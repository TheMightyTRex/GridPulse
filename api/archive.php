<?php
/**
 * GET api/archive.php?area=FR[&year=2026]
 *
 * Every stored day for one area and year, from daily_summary (worked out
 * by the DAILY job - see includes/areas.php): renewable and zero-carbon
 * share, largest source, average demand and price. Also lists which years
 * have any days. Used by pages/archive.html.
 */
require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$area = ukgrid_api_area($config);
$pdo = ukgrid_db();
ukgrid_area_ensure_tables($pdo);

$yearsSt = $pdo->prepare('SELECT YEAR(day) y, COUNT(*) n, AVG(renewable_share) r FROM daily_summary WHERE area = :a AND gen_mwh > 0 GROUP BY y ORDER BY y');
$yearsSt->execute(['a' => $area['code']]);
$years = array_map(function ($r) {
    return ['year' => (int) $r['y'], 'days' => (int) $r['n'], 'renewable_share_avg' => round((float) $r['r'], 1)];
}, $yearsSt->fetchAll());

$year = (int) ($_GET['year'] ?? ($years ? end($years)['year'] : (int) date('Y')));
$st = $pdo->prepare('SELECT day, coverage, gen_mwh, renewable_share, zero_carbon_share, fossil_share, largest, demand_avg_mw, price_avg, carbon_avg, imports_net_mwh
    FROM daily_summary WHERE area = :a AND gen_mwh > 0 AND day BETWEEN :f AND :t ORDER BY day');
$st->execute(['a' => $area['code'], 'f' => $year . '-01-01', 't' => $year . '-12-31']);
$days = array_map(function ($r) {
    return [
        'day' => $r['day'], 'coverage' => (float) $r['coverage'], 'gen_mwh' => (float) $r['gen_mwh'],
        'renewable_share' => (float) $r['renewable_share'], 'zero_carbon_share' => (float) $r['zero_carbon_share'],
        'fossil_share' => (float) $r['fossil_share'], 'largest' => $r['largest'],
        'demand_avg_mw' => $r['demand_avg_mw'] !== null ? (float) $r['demand_avg_mw'] : null,
        'price_avg' => $r['price_avg'] !== null ? (float) $r['price_avg'] : null,
        'carbon_avg' => $r['carbon_avg'] !== null ? (float) $r['carbon_avg'] : null,
        'imports_net_mwh' => $r['imports_net_mwh'] !== null ? (float) $r['imports_net_mwh'] : null,
    ];
}, $st->fetchAll());

echo json_encode(['ok' => true, 'area' => ukgrid_area_public($area), 'year' => $year, 'years' => $years, 'days' => $days]);
