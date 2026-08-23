<?php
/**
 * GET api/canada_current.php
 *
 * Latest available Ontario snapshot: demand and generation broken down by
 * fuel type. Source: IESO's public report repository - see
 * includes/ingest.php's ukgrid_ingest_ieso().
 *
 * This covers Ontario only, not all of Canada - see pages/canada.html and
 * pages/data-sources.html for that scope disclosure. There's no confirmed
 * free, keyless source for a wholesale price, an emissions figure, or
 * interchange/transfers found during research for this page, so those
 * aren't included here - pages/canada.html's price/emissions/transfers
 * stats show "Not available" rather than any kind of placeholder.
 *
 * Response shape:
 *   {
 *     "ok": true,
 *     "live": true,
 *     "stale": false,
 *     "ts": "2026-08-23T13:05:00Z",
 *     "demand_mw": 16205.5,
 *     "generation_mw": 19356.0,
 *     "mix_mw": { "NUCLEAR": 9602.0, "GAS": 3246.0, "HYDRO": 3137.0, "WIND": 3358.0, "SOLAR": 0.0, "BIOFUEL": 13.0, "OTHER": 0.0 }
 *   }
 *
 * If there's no data yet, "ok" stays true but "live" is false and every
 * field is null or empty, matching api/usa_current.php's convention.
 *
 * "stale" uses canada_staleness_minutes (default 120) - IESO's demand
 * report is genuinely real-time (5-minute intervals), but this project's
 * ingestion only polls it on cron/on-demand refresh, so a couple of hours
 * without a run is a more realistic staleness threshold than GB's.
 */

require __DIR__ . '/_bootstrap.php';

$pdo = ukgrid_db();
$config = ukgrid_load_config();
$stalenessMinutes = (int) ($config['canada_staleness_minutes'] ?? 120);

$latestDemandTs = $pdo->query('SELECT MAX(ts) FROM readings_ca_demand')->fetchColumn();
$latestGenTs = $pdo->query('SELECT MAX(ts) FROM readings_ca_generation')->fetchColumn();
$latestTs = $latestDemandTs;
if ($latestGenTs && (!$latestTs || $latestGenTs > $latestTs)) {
    $latestTs = $latestGenTs;
}

if (!$latestTs) {
    echo json_encode([
        'ok' => true,
        'live' => false,
        'stale' => true,
        'ts' => null,
        'demand_mw' => null,
        'generation_mw' => null,
        'mix_mw' => new stdClass(),
    ]);
    exit;
}

$isStale = (strtotime($latestTs) < time() - $stalenessMinutes * 60);

$windowMinutes = max($stalenessMinutes, 60);

$demandStmt = $pdo->prepare(
    'SELECT mw FROM readings_ca_demand
     WHERE ts BETWEEN DATE_SUB(:ts1, INTERVAL :window1 MINUTE) AND DATE_ADD(:ts2, INTERVAL :window2 MINUTE)
     ORDER BY ABS(TIMESTAMPDIFF(SECOND, ts, :ts3)) ASC LIMIT 1'
);
$demandStmt->execute(['ts1' => $latestTs, 'window1' => $windowMinutes, 'ts2' => $latestTs, 'window2' => $windowMinutes, 'ts3' => $latestTs]);
$demandMw = $demandStmt->fetchColumn();

$fuelTypes = ['NUCLEAR', 'GAS', 'HYDRO', 'WIND', 'SOLAR', 'BIOFUEL', 'OTHER'];
$mix = [];
$generationTotal = 0.0;
$haveAnyGen = false;
foreach ($fuelTypes as $fuel) {
    $stmt = $pdo->prepare(
        'SELECT mw FROM readings_ca_generation
         WHERE category = :cat
           AND ts BETWEEN DATE_SUB(:ts1, INTERVAL :window1 MINUTE) AND DATE_ADD(:ts2, INTERVAL :window2 MINUTE)
         ORDER BY ABS(TIMESTAMPDIFF(SECOND, ts, :ts3)) ASC LIMIT 1'
    );
    $stmt->execute(['cat' => $fuel, 'ts1' => $latestTs, 'window1' => $windowMinutes, 'ts2' => $latestTs, 'window2' => $windowMinutes, 'ts3' => $latestTs]);
    $v = $stmt->fetchColumn();
    if ($v !== false && $v !== null) {
        $mix[$fuel] = (float) $v;
        $generationTotal += (float) $v;
        $haveAnyGen = true;
    }
}

echo json_encode([
    'ok' => true,
    'live' => true,
    'stale' => $isStale,
    'ts' => gmdate('Y-m-d\TH:i:s\Z', strtotime($latestTs)),
    'demand_mw' => $demandMw !== false && $demandMw !== null ? (float) $demandMw : null,
    'generation_mw' => $haveAnyGen ? round($generationTotal, 1) : null,
    'mix_mw' => empty($mix) ? new stdClass() : $mix,
]);
