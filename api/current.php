<?php
/**
 * GET api/current.php
 *
 * Returns the latest available snapshot: demand, price, emissions, and
 * generation by fuel type (including interconnectors). The frontend
 * (assets/data.js) turns this into the stat strip, the mix table and the
 * nested donut chart.
 *
 * Response shape:
 *   {
 *     "ok": true,
 *     "live": true,           // false if there's no data at all yet (fresh install)
 *     "stale": false,         // true if the newest row is older than staleness_minutes
 *     "ts": "2026-08-09T13:00:00Z",
 *     "demand_mw": 21198.0,
 *     "price_gbp_mwh": 112.82,
 *     "emissions_gco2": 145,
 *     "generation": { "CCGT": 5843, "NUCLEAR": 3985, "INTFR": -420, ... }
 *   }
 *
 * If there's no data yet (rows_written across every ingest run is zero, or
 * the tables are empty - e.g. right after a fresh install before cron has
 * run), "ok" is still true but "live" is false and every numeric field is
 * null; the frontend shows illustrative preview data in that case rather
 * than a blank dashboard.
 */

require __DIR__ . '/_bootstrap.php';

$pdo = ukgrid_db();
$config = ukgrid_load_config();
$stalenessMinutes = (int) ($config['staleness_minutes'] ?? 90);

$latestTs = $pdo->query('SELECT MAX(ts) FROM readings_generation')->fetchColumn();

if (!$latestTs) {
    echo json_encode([
        'ok' => true,
        'live' => false,
        'stale' => true,
        'ts' => null,
        'demand_mw' => null,
        'price_gbp_mwh' => null,
        'emissions_gco2' => null,
        'generation' => new stdClass(),
    ]);
    exit;
}

$isStale = (strtotime($latestTs) < time() - $stalenessMinutes * 60);

// Generation by fuel type at the latest available timestamp.
$stmt = $pdo->prepare('SELECT fuel_type, mw FROM readings_generation WHERE ts = :ts');
$stmt->execute(['ts' => $latestTs]);
$generation = [];
foreach ($stmt->fetchAll() as $row) {
    $generation[$row['fuel_type']] = (float) $row['mw'];
}

// Nearest demand/price/emissions readings within the staleness window (each
// source updates on its own schedule, so they won't share an exact timestamp).
// Named placeholders each need a distinct name here - PDO's MySQL driver
// uses real (non-emulated) prepared statements (see includes/db.php), which
// don't allow the same named placeholder to appear twice in one query.
// Reusing :ts/:window across DATE_SUB and DATE_ADD looked fine in testing
// (this path never ran without live data to feed it) but throws
// "SQLSTATE[HY093]: Invalid parameter number" the moment it actually runs -
// see includes/refresh.log if this ever regresses.
function ukgrid_nearest(PDO $pdo, string $table, string $valueCol, string $ts, int $windowMinutes)
{
    $stmt = $pdo->prepare(
        "SELECT {$valueCol} AS v, ts FROM {$table}
         WHERE ts BETWEEN DATE_SUB(:ts1, INTERVAL :window1 MINUTE) AND DATE_ADD(:ts2, INTERVAL :window2 MINUTE)
         ORDER BY ABS(TIMESTAMPDIFF(SECOND, ts, :ts3)) ASC LIMIT 1"
    );
    $stmt->execute(['ts1' => $ts, 'window1' => $windowMinutes, 'ts2' => $ts, 'window2' => $windowMinutes, 'ts3' => $ts]);
    $row = $stmt->fetch();
    return $row ? $row['v'] : null;
}

$demand = ukgrid_nearest($pdo, 'readings_demand', 'mw', $latestTs, $stalenessMinutes);
$price = ukgrid_nearest($pdo, 'readings_price', 'price', $latestTs, $stalenessMinutes);
$emissions = ukgrid_nearest($pdo, 'readings_emissions', 'COALESCE(actual_gco2, forecast_gco2)', $latestTs, $stalenessMinutes);

echo json_encode([
    'ok' => true,
    'live' => true,
    'stale' => $isStale,
    'ts' => gmdate('Y-m-d\TH:i:s\Z', strtotime($latestTs)),
    'demand_mw' => $demand !== null ? (float) $demand : null,
    'price_gbp_mwh' => $price !== null ? round((float) $price, 2) : null,
    'emissions_gco2' => $emissions !== null ? (int) round((float) $emissions) : null,
    'generation' => $generation,
]);
