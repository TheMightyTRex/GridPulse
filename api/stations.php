<?php
/**
 * GET api/stations.php
 *
 * Major power producer power stations in the UK, from DESNZ's DUKES table
 * 5.11 (Open Government Licence v3.0), with live planned output where the
 * station has been matched to Elexon BM units (see includes/stations.php
 * and includes/station_bmus.json).
 *   fields    the column names for each row of "stations"
 *   stations  [code, site, company, technology, type, primary fuel,
 *              capacity MW, latitude, longitude, year commissioned,
 *              country, connection (HV or LV)]
 *   live      { code: { mw, units[], ts } }: planned output (physical
 *              notifications) for the latest half hour stored, MW
 */
require __DIR__ . '/_bootstrap.php';

$pdo = ukgrid_db();
ukgrid_stations_ensure_tables($pdo);
$rows = $pdo->query('SELECT code, site, company, tech, type, fuel, cap_mw, lat, lon, year, country, conn FROM power_station ORDER BY cap_mw DESC')->fetchAll(PDO::FETCH_NUM);
$stations = array_map(function ($r) {
    return [$r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6] === null ? null : (float) $r[6], (float) $r[7], (float) $r[8], $r[9] === null ? null : (int) $r[9], $r[10], $r[11]];
}, $rows);

$live = [];
$latest = $pdo->query('SELECT MAX(ts) FROM station_pn WHERE ts <= UTC_TIMESTAMP()')->fetchColumn();
if ($latest) {
    $st = $pdo->prepare('SELECT bmu, mw FROM station_pn WHERE ts = ?');
    $st->execute([$latest]);
    $byUnit = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$u, $mw]) {
        $byUnit[$u] = (float) $mw;
    }
    foreach (ukgrid_station_bmus() as $code => $units) {
        $have = array_values(array_filter($units, function ($u) use ($byUnit) { return isset($byUnit[$u]); }));
        if (!$have) {
            continue;
        }
        $sum = 0;
        foreach ($have as $u) {
            $sum += max(0, $byUnit[$u]);
        }
        $live[$code] = ['mw' => round($sum, 1), 'units' => $units, 'reporting' => count($have), 'ts' => ukgrid_iso($latest)];
    }
}
$map = json_decode((string) @file_get_contents(__DIR__ . '/../includes/station_bmus.json'), true);
$updated = $pdo->query("SELECT MAX(ran_at) FROM ingest_log WHERE source = 'STATIONS' AND status = 'OK'")->fetchColumn();
header('Cache-Control: public, max-age=600');
echo json_encode([
    'ok' => true,
    'edition' => (string) (ukgrid_load_config()['stations']['dukes_edition'] ?? UKGRID_DUKES_511_EDITION),
    'list_fetched' => $updated ? ukgrid_iso($updated) : null,
    'matching_checked' => $map['checked'] ?? null,
    'fields' => ['code', 'site', 'company', 'technology', 'type', 'fuel', 'capacity_mw', 'lat', 'lon', 'year', 'country', 'connection'],
    'stations' => $stations,
    'live_ts' => $latest ? ukgrid_iso($latest) : null,
    'live' => (object) $live,
]);
