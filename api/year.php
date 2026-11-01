<?php
/**
 * GET api/year.php?area=FR
 *
 * This year so far for one area, from the daily summaries (this site's own
 * readings): energy by fuel group, renewable and zero-carbon shares, net
 * imports, each month's renewable share, the best month, and the same
 * figures for last year so far (1 January to the same day) where this site
 * has them. Days with less than 90% of their readings are still counted,
 * and "coverage" says how many days were complete.
 */
require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$area = ukgrid_api_area($config);
$pdo = ukgrid_db();
ukgrid_area_ensure_tables($pdo);

$today = ukgrid_area_today($area);
$year = (int) substr($today, 0, 4);
$md = substr($today, 5); // same day last year

function ukgrid_year_sum(PDO $pdo, array $area, string $from, string $to): ?array
{
    $st = $pdo->prepare('SELECT day, coverage, summary_json FROM daily_summary WHERE area = :a AND gen_mwh > 0 AND day BETWEEN :f AND :t ORDER BY day');
    $st->execute(['a' => $area['code'], 'f' => $from, 't' => $to]);
    $energy = [];
    $gen = $ren = $zc = 0.0;
    $imp = 0.0;
    $impSeen = false;
    $days = $full = 0;
    $months = [];
    foreach ($st as $row) {
        $s = json_decode($row['summary_json'], true);
        if (!is_array($s) || empty($s['energy_mwh'])) {
            continue;
        }
        $days++;
        if ((float) $row['coverage'] >= 0.9) {
            $full++;
        }
        $g = (float) $s['gen_mwh'];
        $gen += $g;
        $ren += $g * (float) $s['renewable_share'] / 100;
        $zc += $g * (float) $s['zero_carbon_share'] / 100;
        foreach ($s['energy_mwh'] as $k => $v) {
            $energy[$k] = ($energy[$k] ?? 0) + (float) $v;
        }
        if (isset($s['imports_net_mwh']) && $s['imports_net_mwh'] !== null) {
            $impSeen = true;
            $imp += (float) $s['imports_net_mwh'];
        }
        $m = (int) substr($row['day'], 5, 2);
        $months[$m]['gen'] = ($months[$m]['gen'] ?? 0) + $g;
        $months[$m]['ren'] = ($months[$m]['ren'] ?? 0) + $g * (float) $s['renewable_share'] / 100;
        $months[$m]['days'] = ($months[$m]['days'] ?? 0) + 1;
    }
    if ($gen <= 0) {
        return null;
    }
    arsort($energy);
    $monthList = [];
    $best = null;
    foreach ($months as $m => $v) {
        $share = round($v['ren'] / $v['gen'] * 100, 1);
        $monthList[] = ['month' => $m, 'renewable_share' => $share, 'days' => $v['days']];
        if ($best === null || $share > $best['renewable_share']) {
            $best = ['month' => $m, 'renewable_share' => $share];
        }
    }
    return [
        'from' => $from, 'to' => $to, 'days' => $days, 'complete_days' => $full,
        'gen_mwh' => round($gen, 1),
        'energy_mwh' => array_map(function ($v) { return round($v, 1); }, $energy),
        'renewable_share' => round($ren / $gen * 100, 1),
        'zero_carbon_share' => round($zc / $gen * 100, 1),
        'imports_net_mwh' => $impSeen ? round($imp, 1) : null,
        'months' => $monthList,
        'best_month' => $best,
    ];
}

$this_year = ukgrid_year_sum($pdo, $area, $year . '-01-01', $today);
$last_year = ukgrid_year_sum($pdo, $area, ($year - 1) . '-01-01', ($year - 1) . '-' . $md);

echo json_encode([
    'ok' => true,
    'area' => ukgrid_area_public($area),
    'year' => $year,
    'today' => $today,
    'this_year' => $this_year,
    'last_year' => $last_year,
]);
