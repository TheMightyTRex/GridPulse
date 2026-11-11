<?php
/**
 * GET api/balancing.php
 *
 * What NESO spends balancing Great Britain's electricity system, from its
 * Daily Balancing Costs (BSUoS) data (see includes/outlook.php). Pounds.
 *   items    the categories, in NESO's own names
 *   days     the last 90 days: { dates[], items: { name: [cost per day] } }
 *   months   the last 13 months: { months[] ("2026-10"), items: {...}, days[] (days counted) }
 *   latest   the newest whole day in the data
 */
require __DIR__ . '/_bootstrap.php';

$pdo = ukgrid_db();
ukgrid_outlook_ensure_tables($pdo);
$items = $pdo->query('SELECT DISTINCT item FROM balancing_daily ORDER BY item')->fetchAll(PDO::FETCH_COLUMN);
$latest = $pdo->query('SELECT MAX(dt) FROM balancing_daily')->fetchColumn();
$out = ['ok' => true, 'items' => $items, 'latest' => $latest ?: null, 'days' => ['dates' => [], 'items' => []], 'months' => ['months' => [], 'items' => [], 'days' => []]];
if ($latest) {
    $st = $pdo->prepare('SELECT dt, item, cost FROM balancing_daily WHERE dt > ? - INTERVAL 90 DAY ORDER BY dt');
    $st->execute([$latest]);
    $by = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$d, $i, $c]) {
        $by[$d][$i] = round((float) $c);
    }
    foreach ($by as $d => $vals) {
        $out['days']['dates'][] = $d;
        foreach ($items as $i) {
            $out['days']['items'][$i][] = $vals[$i] ?? null;
        }
    }
    $st = $pdo->prepare("SELECT DATE_FORMAT(dt, '%Y-%m') m, item, SUM(cost), COUNT(DISTINCT dt) FROM balancing_daily
        WHERE dt >= DATE_FORMAT(? - INTERVAL 12 MONTH, '%Y-%m-01') GROUP BY m, item ORDER BY m");
    $st->execute([$latest]);
    $bm = [];
    $nd = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$m, $i, $c, $n]) {
        $bm[$m][$i] = round((float) $c);
        $nd[$m] = (int) $n;
    }
    foreach ($bm as $m => $vals) {
        $out['months']['months'][] = $m;
        $out['months']['days'][] = $nd[$m];
        foreach ($items as $i) {
            $out['months']['items'][$i][] = $vals[$i] ?? null;
        }
    }
}
header('Cache-Control: public, max-age=1800');
echo json_encode($out);
