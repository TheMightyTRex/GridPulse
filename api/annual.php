<?php
/**
 * GET api/annual.php?area=FR
 *
 * Generation by fuel group for each year, in GWh, from official yearly
 * statistics (see ukgrid_annual_sources() in includes/flows_annual.php for
 * which source covers which area, and exactly what it covers). Filled in
 * by the ANNUAL scheduler job; empty until that has run for this area.
 */
require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$area = ukgrid_api_area($config);
$pdo = ukgrid_db();
ukgrid_flows_annual_ensure_tables($pdo);
$src = ukgrid_annual_sources()[$area['code']] ?? null;

$st = $pdo->prepare('SELECT year, grp, gwh FROM annual_mix WHERE area = :a ORDER BY year');
$st->execute(['a' => $area['code']]);
$years = [];
$groups = [];
foreach ($st->fetchAll() as $r) {
    $years[(int) $r['year']] = true;
    $groups[$r['grp']][(int) $r['year']] = (float) $r['gwh'];
}
$yearList = array_keys($years);
sort($yearList);
$series = [];
foreach ($groups as $g => $byYear) {
    $series[$g] = array_map(function ($y) use ($byYear) { return $byYear[$y] ?? 0; }, $yearList);
}
$state = ukgrid_fetch_state_get($pdo, 'ANNUAL')[$area['code']] ?? null;

echo json_encode([
    'ok' => true, 'area' => ukgrid_area_public($area),
    'source' => $src ? ['credit' => $src['credit'], 'url' => $src['url'], 'scope' => $src['scope']] : null,
    'years' => $yearList, 'groups' => (object) $series,
    'current_year' => (int) gmdate('Y'),
    'updated' => $state ? ukgrid_iso($state['fetched_at']) : null,
]);
