<?php
/**
 * cron/run-due.php - runs every data source that is due, one after another,
 * within a time budget. Built for outside schedulers, since this hosting
 * package has no cron:
 *   - the GitHub Actions workflow in .github/workflows/refresh-data.yml
 *   - the Docker container in docker/
 * Both call this same page, with the secret from includes/config.php
 * ('cron_http_secret') in an X-Cron-Secret header.
 *
 * Why several callers stay in sync:
 *   - "Due" is worked out from ingest_log, the same record the page-load
 *     refresh uses. Whoever runs a source first updates it; every other
 *     caller (another scheduler, or a visitor's page load) then finds it
 *     not due and does nothing.
 *   - Each source runs under the same MySQL lock as the page-load refresh
 *     (ukgrid_refresh_<SOURCE>), so two callers can never fetch the same
 *     source at the same moment.
 *   - Every write is INSERT ... ON DUPLICATE KEY UPDATE on the reading's
 *     own timestamp, so fetching the same half hour twice changes nothing.
 *
 * Output is plain text, one line per source, ending "done" - the GitHub
 * workflow and the container print it to their logs.
 */

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/refresh.php';
// cron/ is often uploaded on its own. Check the includes/ files it needs are
// from the same version before printing anything, so the reply can still be
// a 500 (once a line has gone out, the status can't change).
$missing = [];
foreach (['ukgrid_refresh_schedule' => 'includes/refresh.php', 'ukgrid_ingest_battery' => 'includes/battery.php', 'ukgrid_http_set_deadline' => 'includes/http.php', 'ukgrid_ingest_gas' => 'includes/gas.php', 'ukgrid_ingest_powercuts' => 'includes/powercuts.php', 'ukgrid_ingest_indexnow' => 'includes/indexnow.php', 'ukgrid_http_post_json' => 'includes/http.php'] as $fn => $file) {
    if (!function_exists($fn)) {
        $missing[$file] = true;
    }
}
if ($missing) {
    http_response_code(500);
    ukgrid_out('These files on the server are older than cron/run-due.php: ' . implode(', ', array_keys($missing)) . '. Upload the whole includes/ folder from the same zip.');
    exit;
}
ukgrid_out('run-due: starting');

@set_time_limit(120);
$config = ukgrid_load_config();
$pdo = ukgrid_db();
$started = microtime(true);
// Every source must be FINISHED within this many seconds. The host stops a
// web request at about 60 seconds, so a source is only started when its
// rough worst case (requests x timeout, see $worst below) still fits, and
// includes/http.php refuses to start any request after the hard stop. Anything
// left over runs on the next call five minutes later, or on a page load.
$budget = (int) ($config['refresh']['run_due_budget_seconds'] ?? 45);
// And a hard stop for every outgoing request, a little after the budget,
// in case a source makes more requests than its estimate below.
ukgrid_http_set_deadline($started + $budget + 5);
$caller = preg_replace('/[^a-z0-9_-]/i', '', (string) ($_GET['caller'] ?? ($isCli ? 'cli' : 'web')));

[$intervals, $timeouts] = ukgrid_refresh_schedule($config);
// NESO needs several discovery calls, so page loads leave it out; a
// scheduler can afford it.
if (!isset($intervals['NESO'])) $intervals['NESO'] = 30;
// Cross-border flows (one border per call) and yearly history (one area
// per call) - see includes/flows_annual.php.
if (!isset($intervals['FLOWS'])) $intervals['FLOWS'] = 4;
if (!isset($intervals['ANNUAL'])) $intervals['ANNUAL'] = 120;

$due = ukgrid_refresh_overdue($pdo, $intervals);
ukgrid_file_log('run-due (' . $caller . '): ' . (count($due) ? implode(', ', array_keys($due)) . ' due' : 'nothing due'));
ukgrid_out('Grid Pulse run-due, ' . gmdate('Y-m-d H:i:s') . ' UTC, caller ' . $caller);
if (!$due) {
    ukgrid_out('Nothing due.');
}

// ENTSO-E is the slowest and least reliable source, so it goes first, while
// the whole budget is still there, with a longer timeout than a page load
// can afford. One country per call (the most out of date one).
$timeouts['ENTSOE'] = max((int) ($timeouts['ENTSOE'] ?? 6), (int) ($config['refresh']['run_due_entsoe_timeout_seconds'] ?? 12));
$order = array_keys($due);
usort($order, function ($a, $b) {
    return ($b === 'ENTSOE') <=> ($a === 'ENTSOE');
});
// Worst case = timeout x the most requests the source makes in one run.
$worst = ['ENTSOE' => 3, 'EIRGRID' => 4, 'NESO' => 3, 'ELEXON' => 4, 'BATTERY' => 3, 'EIA' => 2, 'OPENELECTRICITY' => 2, 'CARBON_INTENSITY' => 2, 'FLOWS' => 2, 'ANNUAL' => 2, 'DAILY' => 1, 'GAS' => 1, 'GASDAILY' => 1, 'POWERCUTS' => 6, 'INDEXNOW' => 1];

$lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
$release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
$left = [];
foreach ($order as $source) {
    $timeout = (int) ($timeouts[$source] ?? 10);
    $cost = $timeout * ($worst[$source] ?? 2);
    if (microtime(true) - $started + $cost > $budget) {
        $left[] = $source;
        continue;
    }
    $lockName = 'ukgrid_refresh_' . $source;
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        ukgrid_out($source . ': already running elsewhere, skipped.');
        continue;
    }
    $t = microtime(true);
    try {
        ukgrid_refresh_run_source($pdo, $config, $source, $timeout, true);
        $st = $pdo->prepare('SELECT status, rows_written FROM ingest_log WHERE source = ? ORDER BY ran_at DESC LIMIT 1');
        $st->execute([$source]);
        $last = $st->fetch() ?: [];
        ukgrid_out(sprintf('%s: %s, %s rows, %.1fs', $source, $last['status'] ?? '?', $last['rows_written'] ?? '?', microtime(true) - $t));
    } catch (Throwable $e) {
        ukgrid_log_ingest($source, 'ERROR', 0, 'run-due threw - ' . get_class($e) . ': ' . $e->getMessage());
        ukgrid_out($source . ': ERROR - ' . $e->getMessage());
    } finally {
        $release->execute([$lockName]);
    }
}
if ($left) {
    ukgrid_out('Left for next time (would not fit in the ' . $budget . 's budget): ' . implode(', ', $left));
}
ukgrid_out(sprintf('done in %.1fs', microtime(true) - $started));
