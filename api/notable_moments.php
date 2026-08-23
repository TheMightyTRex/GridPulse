<?php
/**
 * GET api/notable_moments.php
 *
 * The highest/lowest value THIS INSTALL has itself recorded for a handful
 * of GB metrics (day-ahead price, carbon intensity, demand, wind, solar) -
 * see ukgrid_compute_notable_moments() in includes/ingest.php, which
 * recomputes and caches these into the notable_moments table roughly once
 * a day via includes/refresh.php's on-demand dispatch (or the optional
 * cron/fetch_notable_moments.php).
 *
 * IMPORTANT - this is NOT the same thing as the hand-typed, independently
 * sourced "Records" panel elsewhere on index.html. Those are real all-time
 * GB records, checked against NESO/press reporting. This endpoint only
 * ever reflects the window of history this particular install has stored
 * since ITS OWN first ingestion run - for a fresh install that might be a
 * few days or weeks. "recorded_since" below is exactly that window's start
 * (the earliest timestamp across the same source tables these moments are
 * computed from), returned specifically so the frontend can disclose it
 * rather than let a genuinely small/recent "highest" figure look like a
 * real record - see index.html's "Notable moments" section for the wording
 * this drives.
 *
 * Response shape:
 *   { "ok": true, "recorded_since": "2026-08-01T00:00:00Z",
 *     "moments": [
 *       { "key": "price_highest", "label": "Highest day-ahead price recorded",
 *         "value": 142.50, "unit": "GBP/MWh", "ts": "2026-08-10T18:00:00Z", "direction": "highest" },
 *       ...
 *     ] }
 * or { "ok": false } if nothing has been computed yet (e.g. this is a
 * brand new install and the daily refresh hasn't run for the first time).
 */

require __DIR__ . '/_bootstrap.php';

$pdo = ukgrid_db();

$stmt = $pdo->query('SELECT metric_key, label, value, unit, ts, direction FROM notable_moments ORDER BY metric_key ASC');
$rows = $stmt->fetchAll();

if (empty($rows)) {
    echo json_encode(['ok' => false]);
    exit;
}

// Earliest timestamp across every source table these moments are computed
// from - a cheap indexed MIN() each, four short queries rather than one
// UNION, since these tables have no shared structure to union over.
$sinceCandidates = [];
foreach ([
    ['readings_price', 'ts'],
    ['readings_emissions', 'ts'],
    ['readings_demand', 'ts'],
    ['readings_generation', 'ts'],
] as [$table, $col]) {
    $v = $pdo->query("SELECT MIN({$col}) FROM {$table}")->fetchColumn();
    if ($v) {
        $sinceCandidates[] = $v;
    }
}
$recordedSince = !empty($sinceCandidates) ? min($sinceCandidates) : null;

$moments = array_map(function ($r) {
    return [
        'key' => $r['metric_key'],
        'label' => $r['label'],
        'value' => (float) $r['value'],
        'unit' => $r['unit'],
        'ts' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['ts'])),
        'direction' => $r['direction'],
    ];
}, $rows);

echo json_encode([
    'ok' => true,
    'recorded_since' => $recordedSince ? gmdate('Y-m-d\TH:i:s\Z', strtotime($recordedSince)) : null,
    'moments' => $moments,
]);
