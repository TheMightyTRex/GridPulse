<?php
/**
 * GET api/power_cuts.php
 *
 * Live power cuts in Great Britain from each network operator's latest
 * fetch (see includes/powercuts.php):
 *   operators  per operator: covered or not, when last fetched, counts
 *   cuts       each cut: operator, planned, customers, position, place,
 *              start and estimated restoration (UTC)
 *   history    cuts and customers per operator over the last 24 hours
 */
require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$pdo = ukgrid_db();
ukgrid_powercuts_ensure_tables($pdo);
$state = ukgrid_fetch_state_get($pdo, 'POWERCUTS');

$operators = [];
$cuts = [];
$cutSt = $pdo->prepare('SELECT ref, planned, customers, lat, lon, place, started, etr FROM power_cut WHERE dno = ? AND seen_at = ? ORDER BY started DESC');
$lastSt = $pdo->prepare('SELECT MAX(ts) FROM power_cut_count WHERE dno = ?');
$cntSt = $pdo->prepare('SELECT cuts, planned, customers FROM power_cut_count WHERE dno = ? AND ts = ?');
foreach (ukgrid_powercuts_dnos($config) as $code => $d) {
    $op = ['code' => $code, 'name' => $d['name'], 'covered' => $d['ready'], 'fetched_at' => null, 'status' => null,
           'cuts' => null, 'planned' => null, 'customers' => null, 'mapped' => 0];
    if ($d['ready']) {
        $lastSt->execute([$code]);
        $seen = $lastSt->fetchColumn();
        $op['status'] = $state[$code]['status'] ?? null;
        if ($seen) {
            $op['fetched_at'] = ukgrid_iso($seen);
            $cntSt->execute([$code, $seen]);
            $c = $cntSt->fetch(PDO::FETCH_ASSOC) ?: [];
            $op['cuts'] = isset($c['cuts']) ? (int) $c['cuts'] : 0;
            $op['planned'] = isset($c['planned']) ? (int) $c['planned'] : 0;
            $op['customers'] = isset($c['customers']) ? (int) $c['customers'] : null;
            $cutSt->execute([$code, $seen]);
            foreach ($cutSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $hasPos = $r['lat'] !== null && $r['lon'] !== null;
                $op['mapped'] += $hasPos ? 1 : 0;
                $cuts[] = [
                    'dno' => $code, 'ref' => $r['ref'], 'planned' => (bool) $r['planned'],
                    'customers' => $r['customers'] !== null ? (int) $r['customers'] : null,
                    'lat' => $hasPos ? (float) $r['lat'] : null, 'lon' => $hasPos ? (float) $r['lon'] : null,
                    'place' => $r['place'], 'started' => ukgrid_iso($r['started']), 'etr' => ukgrid_iso($r['etr']),
                ];
            }
        }
    }
    $operators[] = $op;
}

$history = [];
$rows = $pdo->query("SELECT dno, ts, cuts, customers FROM power_cut_count WHERE ts > UTC_TIMESTAMP() - INTERVAL 24 HOUR ORDER BY ts")->fetchAll(PDO::FETCH_NUM);
foreach ($rows as [$dno, $ts, $n, $cust]) {
    $history[$dno][] = [ukgrid_iso($ts), (int) $n, $cust !== null ? (int) $cust : null];
}

echo json_encode(['ok' => true, 'operators' => $operators, 'cuts' => $cuts, 'history' => $history]);
