<?php
/**
 * GET api/status.php
 *
 * Reports whether the data pipeline (pulling from Elexon / Carbon Intensity /
 * NESO / EirGrid and writing to MySQL) is actually working, using the most
 * recent row per source in ingest_log. Powers the status pill in the site
 * footer (assets/app.js's initIngestStatusPill()).
 *
 * Response shape:
 *   {
 *     "ok": true,
 *     "overall": "success" | "fail" | "pending",
 *     "checked_at": "2026-08-09T13:00:00Z",
 *     "last_run_at": "2026-08-09T12:45:00Z",   // most recent ingest_log row across all sources, or null
 *     "sources": {
 *       "ELEXON":            { "status": "OK", "ran_at": "...", "rows_written": 24, "message": "" } | null,
 *       "CARBON_INTENSITY":  { ... } | null,
 *       "NESO":              { ... } | null,
 *       "EIRGRID":           { ... } | null,
 *       "EIA":               { ... } | null,
 *       "ENTSOE":            { ... } | null
 *     }
 *   }
 *
 * "overall" is:
 *   - "pending" if ingest_log is empty (fresh install, nothing has run yet -
 *     including no on-demand refresh triggered by a page load so far)
 *   - "fail" if the most recent attempt for any source ended in ERROR
 *   - "success" otherwise (every source that has run at least once last
 *     succeeded)
 *
 * A source being null just means it's never logged a run yet (e.g. NESO if
 * you're on no-cron mode without adding it to the on-demand schedule, or EIA
 * if no eia_api_key is configured) - that alone doesn't flip "overall" to
 * "fail". EIA logging "OK, 0 rows, eia_api_key not set" (rather than being
 * null) also doesn't flip it to "fail" - see includes/ingest.php's
 * ukgrid_ingest_eia().
 */

require __DIR__ . '/_bootstrap.php';

$pdo = ukgrid_db();

$sources = ['ELEXON', 'CARBON_INTENSITY', 'NESO', 'EIRGRID', 'EIA', 'ENTSOE'];
$stmt = $pdo->prepare(
    'SELECT status, ran_at, rows_written, message FROM ingest_log WHERE source = :source ORDER BY ran_at DESC LIMIT 1'
);

$latest = [];
$hasAny = false;
$hasError = false;
$mostRecentAt = null;

foreach ($sources as $source) {
    $stmt->execute(['source' => $source]);
    $row = $stmt->fetch();

    if ($row === false) {
        $latest[$source] = null;
        continue;
    }

    $hasAny = true;
    if ($row['status'] !== 'OK') {
        $hasError = true;
    }
    if ($mostRecentAt === null || strtotime($row['ran_at']) > strtotime($mostRecentAt)) {
        $mostRecentAt = $row['ran_at'];
    }

    $latest[$source] = [
        'status' => $row['status'],
        'ran_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['ran_at'])),
        'rows_written' => (int) $row['rows_written'],
        'message' => (string) $row['message'],
    ];
}

$overall = !$hasAny ? 'pending' : ($hasError ? 'fail' : 'success');

echo json_encode([
    'ok' => true,
    'overall' => $overall,
    'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'last_run_at' => $mostRecentAt ? gmdate('Y-m-d\TH:i:s\Z', strtotime($mostRecentAt)) : null,
    'sources' => $latest,
]);
