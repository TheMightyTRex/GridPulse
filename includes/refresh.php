<?php
/**
 * On-demand data refresh - lets the site work with NO cron job at all, by
 * checking (on every page load, via api/current.php and api/series.php)
 * whether any data source is overdue, and if so, pulling fresh data right
 * then before answering the request.
 *
 * Safety mechanisms, since this runs on a random visitor's request rather
 * than a controlled schedule:
 *   - Only ONE source is refreshed per request (the most overdue one), so
 *     a single page load never pays for three sequential API calls.
 *   - Short-ish timeouts (see includes/ingest.php calls below) cap the
 *     worst-case added latency.
 *   - A MySQL named lock (GET_LOCK, non-blocking) means that if two
 *     visitors load the site at the same moment while data is stale, only
 *     one of them actually triggers the fetch - the other just serves
 *     whatever's currently in the database, stale or not, rather than
 *     both firing off the same API call at once.
 *   - Locks are per-connection and MySQL releases them automatically if
 *     the request dies mid-fetch, so there's no way to get permanently
 *     "stuck" refreshing.
 *
 * This is entirely optional and safe to run alongside cron jobs too - if
 * cron is keeping data fresh, this will simply find nothing overdue and
 * do nothing on almost every request. Turn it off via includes/config.php
 * > refresh > on_demand if you'd rather rely purely on cron.
 */

require_once __DIR__ . '/ingest.php';

/**
 * Public entry point - called on every API request (see api/_bootstrap.php).
 * This is just a thin wrapper: it hands off to ukgrid_maybe_refresh_inner()
 * and guarantees that absolutely nothing thrown in there can ever break the
 * page that triggered it. Before this wrapper existed, a couple of steps in
 * the inner logic (the "anything overdue?" query, the GET_LOCK call) ran
 * outside any try/catch - an exception there would have taken down whatever
 * API endpoint was loading at the time with a blank 500, AND left zero
 * trace in ingest_log, which looks identical to "on-demand refresh is
 * simply doing nothing". See includes/refresh.log for a full trail either
 * way now (ukgrid_file_log() in includes/db.php).
 */
function ukgrid_maybe_refresh(PDO $pdo, array $config): void
{
    try {
        ukgrid_maybe_refresh_inner($pdo, $config);
    } catch (Throwable $e) {
        $detail = get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
        ukgrid_log_ingest('REFRESH', 'ERROR', 0, 'on-demand refresh crashed before reaching (or while running) a specific source - ' . $detail);
    }
}

function ukgrid_maybe_refresh_inner(PDO $pdo, array $config): void
{
    $refreshCfg = $config['refresh'] ?? [];

    // Logged unconditionally (this is the very first line, cheap either
    // way) so refresh.log can directly answer "is on_demand actually being
    // read as true from the live config.php?" rather than that having to
    // be inferred from silence.
    $onDemand = !empty($refreshCfg['on_demand']);
    ukgrid_file_log('invoked - on_demand=' . ($onDemand ? 'true' : 'false') . ', config source=' . ukgrid_config_source_path());
    if (!$onDemand) {
        return;
    }

    // Only used if includes/config.php itself doesn't set refresh.intervals_minutes
    // at all (a config predating this key, or one that's had 'refresh'
    // deleted) - kept in sync with includes/config.php.example's own
    // shipped default so an incomplete config still behaves sensibly
    // rather than silently missing EIA/ENTSOE. NESO is the one
    // deliberately left out here (and in the example) - unlike every other
    // source it needs a few sequential "which dataset is this right now?"
    // discovery calls before it can even fetch data (see
    // ukgrid_ingest_neso()), so it stays cron-only by default.
    $intervals = $refreshCfg['intervals_minutes'] ?? [
        'ELEXON' => 15,
        'CARBON_INTENSITY' => 30,
        'EIRGRID' => 15,
        'EIA' => 240,
        'IESO' => 30,
        'ENTSOE' => 20,
        'WEATHER' => 60,
        'CONSTRAINTS' => 1440,
        'NOTABLE_MOMENTS' => 1440,
    ];
    $timeouts = $refreshCfg['timeouts_seconds'] ?? [
        'ELEXON' => 8,
        'CARBON_INTENSITY' => 6,
        'NESO' => 10,
        'EIRGRID' => 8,
        'EIA' => 15,
        'IESO' => 7, // per-request, not total - ukgrid_ingest_ieso() makes two sequential calls
        'ENTSOE' => 6, // per-request, not total - see includes/config.php.example
        'WEATHER' => 8,
        'CONSTRAINTS' => 10,
        'NOTABLE_MOMENTS' => 12,
    ];

    // Find how overdue each configured source is; skip anything not due yet.
    $overdueBy = [];
    $stmt = $pdo->prepare('SELECT MAX(ran_at) FROM ingest_log WHERE source = ?');
    foreach ($intervals as $source => $minutes) {
        $stmt->execute([$source]);
        $lastRan = $stmt->fetchColumn();
        $elapsedMinutes = $lastRan ? (time() - strtotime($lastRan)) / 60 : PHP_INT_MAX;
        if ($elapsedMinutes >= $minutes) {
            $overdueBy[$source] = ($elapsedMinutes === PHP_INT_MAX) ? PHP_INT_MAX : ($elapsedMinutes - $minutes);
        }
    }
    if (empty($overdueBy)) {
        ukgrid_file_log('nothing overdue among tracked sources (' . implode(', ', array_keys($intervals)) . ') - no-op');
        return; // everything's fresh - the common case on most requests
    }

    arsort($overdueBy); // most-overdue first
    $source = array_key_first($overdueBy);
    ukgrid_file_log($source . ' is the most overdue source, attempting to acquire its refresh lock');

    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lockName = 'ukgrid_refresh_' . $source;
    $lockStmt->execute([$lockName]);
    if ((int) $lockStmt->fetchColumn() !== 1) {
        ukgrid_file_log($source . ': another request already holds the refresh lock right now, skipping this time');
        return; // another request is already refreshing this source right now
    }

    try {
        $timeoutSeconds = $timeouts[$source] ?? 10;
        ukgrid_file_log($source . ': lock acquired, calling ingest function (timeout ' . $timeoutSeconds . 's)');
        switch ($source) {
            case 'ELEXON':
                ukgrid_ingest_elexon($pdo, $config, $timeoutSeconds);
                break;
            case 'CARBON_INTENSITY':
                ukgrid_ingest_carbon_intensity($pdo, $config, $timeoutSeconds);
                break;
            case 'NESO':
                ukgrid_ingest_neso($pdo, $config, $timeoutSeconds);
                break;
            case 'EIRGRID':
                ukgrid_ingest_eirgrid($pdo, $config, $timeoutSeconds);
                break;
            case 'EIA':
                ukgrid_ingest_eia($pdo, $config, $timeoutSeconds);
                break;
            case 'IESO':
                ukgrid_ingest_ieso($pdo, $config, $timeoutSeconds);
                break;
            case 'ENTSOE':
                // Skip any of the 6 configured countries that already have
                // data newer than this source's own interval, AND cap this
                // call to just the single most-overdue country - keeps
                // every on-demand refresh cheap (at most 3 requests, ~18s
                // worst case), including the very first one ever, when
                // nothing's fresh yet and skipping alone wouldn't help. See
                // ukgrid_ingest_entsoe()'s $skipFresherThanMinutes/
                // $maxCountriesPerRun doc for the full reasoning - this is
                // what stops on-demand refresh from hitting the same
                // "later countries never get fetched" failure mode that
                // cron/fetch_entsoe.php's timeout fix addressed on the cron
                // side.
                ukgrid_ingest_entsoe($pdo, $config, $timeoutSeconds, $intervals['ENTSOE'] ?? 20, 1);
                break;
            case 'WEATHER':
                ukgrid_ingest_weather($pdo, $config, $timeoutSeconds);
                break;
            case 'CONSTRAINTS':
                ukgrid_ingest_constraints($pdo, $config, $timeoutSeconds);
                break;
            case 'NOTABLE_MOMENTS':
                // Pure re-aggregation of data already in this database (see
                // its own docblock in includes/ingest.php) - runs daily,
                // same as CONSTRAINTS, just to keep it cheap rather than
                // because it needs to be fresh to the minute.
                ukgrid_compute_notable_moments($pdo, $config, $timeoutSeconds);
                break;
        }
        // Each ukgrid_ingest_* function above already calls ukgrid_log_ingest
        // itself (success or failure) - nothing further to log here on the
        // happy path.
    } catch (Throwable $e) {
        $detail = get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
        ukgrid_log_ingest($source, 'ERROR', 0, 'on-demand refresh threw - ' . $detail);
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

/** Which config.php actually got loaded - purely for refresh.log's benefit, see above. */
function ukgrid_config_source_path(): string
{
    $hardened = dirname(__DIR__, 2) . '/uk-grid-config/config.php';
    if (is_file($hardened)) {
        return 'uk-grid-config/config.php (hardened path, takes priority over includes/config.php)';
    }
    if (is_file(__DIR__ . '/config.php')) {
        return 'includes/config.php';
    }
    return 'NONE FOUND - this should be unreachable, ukgrid_load_config() exits before here if so';
}
