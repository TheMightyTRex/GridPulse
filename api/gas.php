<?php
/**
 * GET api/gas.php
 *
 * Great Britain's gas right now and over the last day and month, from
 * National Gas Transmission (see includes/gas.php):
 *   latest  the newest 2-minute reading of every item (mcm per day rates)
 *   series  the last 24 hours, one point every 12 minutes
 *   daily   each gas day's totals for the last 35 days
 * Items: d_ldz (local gas networks: homes, businesses and smaller
 * industry), d_power, d_industrial, d_export, d_storage, d_total; s_ncs
 * (North Sea, UK and Norway), s_lng, s_import, s_storage, s_total; linepack.
 */
require __DIR__ . '/_bootstrap.php';

$pdo = ukgrid_db();
ukgrid_gas_ensure_tables($pdo);

$last = $pdo->query('SELECT MAX(ts) FROM gas_flow')->fetchColumn();
$latest = [];
if ($last) {
    $st = $pdo->prepare('SELECT item, mcm FROM gas_flow WHERE ts = ?');
    $st->execute([$last]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$item, $v]) {
        $latest[$item] = round((float) $v, 2);
    }
}

// Last 24 hours, averaged into 12-minute buckets.
$series = ['times' => [], 'items' => []];
if ($last) {
    $st = $pdo->prepare("SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/720)*720) b, item, AVG(mcm)
        FROM gas_flow WHERE ts > ? - INTERVAL 24 HOUR GROUP BY b, item ORDER BY b");
    $st->execute([$last]);
    $by = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$b, $item, $v]) {
        $by[$b][$item] = round((float) $v, 2);
    }
    $items = ['d_ldz', 'd_power', 'd_industrial', 'd_export', 'd_storage', 'd_total', 's_ncs', 's_lng', 's_import', 's_storage', 's_total', 'linepack'];
    foreach ($items as $i) {
        $series['items'][$i] = [];
    }
    foreach ($by as $b => $vals) {
        $series['times'][] = ukgrid_iso($b);
        foreach ($items as $i) {
            $series['items'][$i][] = $vals[$i] ?? null;
        }
    }
}

$daily = ['days' => [], 'items' => []];
$rows = $pdo->query("SELECT gas_day, item, mcm FROM gas_daily WHERE gas_day >= CURDATE() - INTERVAL 35 DAY ORDER BY gas_day")->fetchAll(PDO::FETCH_NUM);
$byDay = [];
foreach ($rows as [$d, $item, $v]) {
    $byDay[$d][$item] = round((float) $v, 2);
}
foreach ($byDay as $d => $vals) {
    $daily['days'][] = $d;
    foreach (['d_ldz', 'd_power', 'd_industrial', 'd_export', 'd_storage', 'd_total', 's_ncs', 's_lng', 's_import', 's_storage'] as $i) {
        $daily['items'][$i][] = $vals[$i] ?? null;
    }
}

$fetched = $pdo->query("SELECT MAX(ran_at) FROM ingest_log WHERE source = 'GAS' AND status = 'OK'")->fetchColumn();
echo json_encode([
    'ok' => true,
    'source' => 'National Gas Transmission Data Portal',
    'unit' => 'mcm/d',
    'latest_ts' => ukgrid_iso($last ?: null),
    'fetched_at' => ukgrid_iso($fetched ?: null),
    'latest' => $latest,
    'series' => $series,
    'daily' => $daily,
]);
