<?php
/**
 * GET api/forecast.php[?region=18]
 *
 * Great Britain's next two days (see includes/outlook.php):
 *   carbon   the carbon intensity forecast for one area, every half hour:
 *            { region, name, times[], gco2[], index[] }. region is the
 *            Carbon Intensity API's regionid: 1-14 the network areas,
 *            15 England, 16 Scotland, 17 Wales, 18 Great Britain (default).
 *   areas    every area right now: { id, name, map_id, gco2, index, mix }
 *            (mix: share of each fuel in that area, %, from the same API)
 *   demand   national demand forecast (Elexon NDF), MW: { times[], mw[] }
 *   wind     wind generation forecast (Elexon WINDFOR), MW: { times[], mw[] }
 * Sources: Carbon Intensity API (NESO and University of Oxford, CC BY 4.0),
 * Elexon Insights (BMRS).
 */
require __DIR__ . '/_bootstrap.php';

$pdo = ukgrid_db();
ukgrid_outlook_ensure_tables($pdo);
$regions = ukgrid_ci_regions();
$region = (int) ($_GET['region'] ?? 18);
if (!isset($regions[$region])) {
    $region = 18;
}

$st = $pdo->prepare('SELECT ts, gco2, idx FROM ci_forecast WHERE region = ? AND ts >= UTC_TIMESTAMP() - INTERVAL 30 MINUTE ORDER BY ts');
$st->execute([$region]);
$carbon = ['region' => $region, 'name' => $regions[$region][0], 'times' => [], 'gco2' => [], 'index' => []];
foreach ($st->fetchAll(PDO::FETCH_NUM) as [$ts, $g, $i]) {
    $carbon['times'][] = ukgrid_iso($ts);
    $carbon['gco2'][] = $g === null ? null : (int) $g;
    $carbon['index'][] = $i;
}

// Every area now: the forecast for the current half hour.
$now = $pdo->query("SELECT MAX(ts) FROM ci_forecast WHERE ts <= UTC_TIMESTAMP()")->fetchColumn();
$areas = [];
if ($now) {
    $st = $pdo->prepare('SELECT f.region, f.gco2, f.idx, m.mix_json FROM ci_forecast f LEFT JOIN ci_region_mix m ON m.region = f.region WHERE f.ts = ? ORDER BY f.region');
    $st->execute([$now]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$id, $g, $i, $mix]) {
        $id = (int) $id;
        $areas[] = ['id' => $id, 'name' => $regions[$id][0] ?? (string) $id, 'map_id' => $regions[$id][1] ?? null,
            'gco2' => $g === null ? null : (int) $g, 'index' => $i, 'mix' => $mix ? json_decode($mix, true) : null];
    }
}

$series = function (string $item) use ($pdo) {
    $st = $pdo->prepare('SELECT ts, mw FROM grid_forecast WHERE item = ? AND ts >= UTC_TIMESTAMP() - INTERVAL 30 MINUTE ORDER BY ts');
    $st->execute([$item]);
    $out = ['times' => [], 'mw' => []];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$ts, $mw]) {
        $out['times'][] = ukgrid_iso($ts);
        $out['mw'][] = (int) $mw;
    }
    return $out;
};
$updated = $pdo->query("SELECT MAX(ran_at) FROM ingest_log WHERE source = 'FORECAST' AND status = 'OK'")->fetchColumn();

header('Cache-Control: public, max-age=300');
echo json_encode([
    'ok' => true,
    'updated' => $updated ? ukgrid_iso($updated) : null,
    'now' => $now ? ukgrid_iso($now) : null,
    'carbon' => $carbon,
    'areas' => $areas,
    'demand' => $series('nd'),
    'wind' => $series('wind'),
]);
