<?php
/**
 * GET api/now_all.php
 *
 * The latest reading for every area: renewable and zero-carbon shares of
 * generation, from each area's own most recent bucket in the last 6 hours.
 * Areas with no reading in that time are left out. Used by the "[country]
 * and its neighbours right now" comparison on the European pages.
 */
require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$pdo = ukgrid_db();
$from = gmdate('Y-m-d H:i:s', time() - 6 * 3600);
$to = gmdate('Y-m-d H:i:s', time() + 3600);
$out = [];
foreach (ukgrid_areas($config) as $code => $area) {
    try {
        $b = ukgrid_area_buckets($pdo, $area, $from, $to);
    } catch (Throwable $e) {
        continue;
    }
    if (!$b) {
        continue;
    }
    $ts = array_key_last($b);
    $s = ukgrid_area_shares($area, $b[$ts]['g']);
    if ($s['total'] <= 0) {
        continue;
    }
    $out[] = [
        'code' => $code, 'name' => $area['name'], 'page' => $area['page'], 'ts' => ukgrid_iso($ts),
        'renewable_share' => round($s['renewable'] / $s['total'] * 100, 1),
        'zero_carbon_share' => round($s['zero_carbon'] / $s['total'] * 100, 1),
        'renewable_biomass' => !empty($area['renewable_biomass']),
    ];
}
echo json_encode(['ok' => true, 'areas' => $out]);
