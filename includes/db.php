<?php
/**
 * UK Grid: Live+ - config loader + PDO connection helper.
 * Included by every API endpoint and every cron ingestion script.
 */

function ukgrid_load_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    // Hardening option: a copy of config.php placed one level above the
    // public webroot, in a sibling folder called "uk-grid-config". If it's
    // there, prefer it over the one inside includes/.
    $hardened = dirname(__DIR__, 2) . '/uk-grid-config/config.php';
    $local = __DIR__ . '/config.php';

    if (is_file($hardened)) {
        $config = require $hardened;
    } elseif (is_file($local)) {
        $config = require $local;
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'missing_config',
            'message' => 'includes/config.php not found. Copy includes/config.php.example to includes/config.php and fill in your database details - see README-DEPLOY.md.',
        ]);
        exit;
    }

    if (!is_array($config)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_config', 'message' => 'config.php must return an array.']);
        exit;
    }

    return $config;
}

function ukgrid_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = ukgrid_load_config();
    $db = $config['db'] ?? [];
    $host = $db['host'] ?? 'localhost';
    $name = $db['name'] ?? '';
    $user = $db['user'] ?? '';
    $pass = $db['pass'] ?? '';
    $charset = $db['charset'] ?? 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (PDOException $e) {
        // The public response stays generic (don't hand DB details to random
        // visitors), but the real reason is always written to the PHP error
        // log - check that first. On cPanel: Metrics > Errors, or look for
        // an error_log file in this folder / your account's logs folder.
        error_log('ukgrid db connection failed: dsn=' . $dsn . ' user=' . $user . ' - ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'db_connection_failed',
            'message' => 'Could not connect to the database. Check includes/config.php against the credentials shown under cPanel > MySQL Databases. The exact error has been written to the PHP error log.',
        ]);
        exit;
    }

    return $pdo;
}

/**
 * Appends one line to includes/refresh.log - a plain-text trail of what the
 * on-demand refresh (includes/refresh.php) and the cron scripts have tried
 * to do, independent of the database. Read this first if the site keeps
 * showing "Data pipeline: no data yet" / api/status.php stays "pending"
 * despite refresh.on_demand being on - it records every attempt, including
 * ones that never get as far as writing a row to ingest_log at all (e.g.
 * an exception during the "is anything overdue" check itself, or the
 * ingest_log INSERT failing).
 *
 * Protected from direct web access by includes/.htaccess, same as
 * config.php - safe to leave in place. View it via FTP/File Manager.
 *
 * Best-effort and silent about its own failures: if the file can't be
 * written (read-only filesystem, permissions), this falls back to PHP's
 * own error_log so there's still at least one trace, and it never throws
 * or measurably slows down the request that triggered it.
 */
function ukgrid_file_log(string $line): void
{
    // Log-capture mode (see ukgrid_start_log_capture()'s doc comment below)
    // must keep EVERYTHING out of the real on-disk log while it's active -
    // not just the ukgrid_log_ingest() calls this was originally built for.
    // This function is also called directly, unconditionally, from
    // includes/http.php on every failed HTTP request (with a genuinely
    // useful status-code-and-body-preview message) - without this check,
    // those calls would have bypassed capture mode entirely and still
    // written real lines to includes/refresh.log during a
    // tools/full-refresh.php run, which breaks the "nothing from this page
    // gets saved anywhere" promise that tool was built around. Captured
    // here as a 'source' => 'HTTP' pseudo-entry instead of being discarded,
    // since these HTTP-layer details (the actual status code, the first
    // couple hundred characters of a non-JSON/non-XML response body) are
    // often the only real clue to what a third-party API is doing - see
    // tools/full-refresh.php's rendering, which treats status 'INFO'
    // entries as informational rather than pass/fail.
    if (is_array($GLOBALS['__ukgrid_log_capture'] ?? null)) {
        $GLOBALS['__ukgrid_log_capture'][] = [
            'source' => 'HTTP',
            'status' => 'INFO',
            'rows' => 0,
            'message' => $line,
            'at' => gmdate('Y-m-d H:i:s') . ' UTC',
        ];
        return;
    }

    $path = __DIR__ . '/refresh.log';
    $entry = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $line . "\n";

    try {
        // Simple rotation - keeps this from growing forever on a
        // long-running site. One backup (refresh.log.1) is enough for
        // debugging purposes; anything older just gets overwritten.
        if (is_file($path) && filesize($path) > 1024 * 1024) {
            @rename($path, $path . '.1');
        }
        $ok = @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
        if ($ok === false) {
            error_log('ukgrid: refresh.log is not writable, falling back to PHP error log - ' . $line);
        }
    } catch (Throwable $e) {
        error_log('ukgrid: writing to refresh.log threw ' . get_class($e) . ': ' . $e->getMessage() . ' - original line: ' . $line);
    }
}

/**
 * Log-capture mode: when active, ukgrid_log_ingest() below collects entries
 * into this array instead of writing to includes/refresh.log or the
 * ingest_log table at all. Built for tools/full-refresh.php - a manual,
 * one-off "pull everything now" page - so a deliberate test run doesn't
 * leave a permanent trace in the site's real pipeline history (which
 * api/status.php and the footer's status pill read from) or reset any
 * source's "last ran at" staleness timer. null = normal mode (the default,
 * used by every cron script and every on-demand refresh); an array = capture
 * mode. Not thread-safe/concurrency-safe by design - this is fine because
 * it's only ever toggled by a single manual admin page run at a time, never
 * by the regular request paths.
 */
$GLOBALS['__ukgrid_log_capture'] = null;

/** Starts log-capture mode - see the doc comment above. */
function ukgrid_start_log_capture(): void
{
    $GLOBALS['__ukgrid_log_capture'] = [];
}

/** Stops log-capture mode and returns everything collected since the matching ukgrid_start_log_capture() call. */
function ukgrid_stop_log_capture(): array
{
    $captured = $GLOBALS['__ukgrid_log_capture'] ?? [];
    $GLOBALS['__ukgrid_log_capture'] = null;
    return $captured;
}

/**
 * Logs one row into ingest_log, AND always writes the same information to
 * includes/refresh.log first (see ukgrid_file_log above) - UNLESS
 * log-capture mode is active (see above), in which case this collects the
 * same information into memory instead and returns without touching either
 * the file or the database at all. The DB write on the normal path is
 * still best-effort/never-throws so a logging failure can't crash a cron
 * run or an on-demand refresh - but if it does fail (missing table, no
 * INSERT privilege, connection dropped mid-request), that failure itself
 * now gets recorded to the file log instead of vanishing silently.
 */
function ukgrid_log_ingest(string $source, string $status, int $rowsWritten, string $message = ''): void
{
    if (is_array($GLOBALS['__ukgrid_log_capture'] ?? null)) {
        $GLOBALS['__ukgrid_log_capture'][] = [
            'source' => $source,
            'status' => $status,
            'rows' => $rowsWritten,
            'message' => $message,
            'at' => gmdate('Y-m-d H:i:s') . ' UTC',
        ];
        return;
    }

    ukgrid_file_log(sprintf('%s status=%s rows=%d%s', $source, $status, $rowsWritten, $message !== '' ? ' - ' . $message : ''));

    try {
        $pdo = ukgrid_db();
        $stmt = $pdo->prepare(
            'INSERT INTO ingest_log (source, ran_at, status, rows_written, message) VALUES (?, UTC_TIMESTAMP(), ?, ?, ?)'
        );
        $stmt->execute([$source, $status, $rowsWritten, mb_substr($message, 0, 2000)]);
    } catch (Throwable $e) {
        ukgrid_file_log('WARNING: writing the ingest_log row above to the database failed - ' . get_class($e) . ': ' . $e->getMessage());
    }
}
