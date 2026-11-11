<?php
/**
 * GET api/frequency.php
 *
 * Great Britain's grid frequency, from Elexon's FREQ data (one reading every
 * 15 seconds; see includes/outlook.php):
 *   latest   { ts, hz }
 *   hour     the last hour, every reading: { times[], hz[] }
 *   day      the last 24 hours, one point a minute (average): { times[], hz[] }
 *   today    lowest and highest reading in the last 24 hours, with times
 */
require __DIR__ . '/_bootstrap.php';

$pdo = ukgrid_db();
ukgrid_outlook_ensure_tables($pdo);
$last = $pdo->query('SELECT ts, hz FROM grid_frequency ORDER BY ts DESC LIMIT 1')->fetch(PDO::FETCH_NUM);
$out = ['ok' => true, 'latest' => null, 'hour' => ['times' => [], 'hz' => []], 'day' => ['times' => [], 'hz' => []], 'low' => null, 'high' => null];
if ($last) {
    $out['latest'] = ['ts' => ukgrid_iso($last[0]), 'hz' => (float) $last[1]];
    $st = $pdo->prepare('SELECT ts, hz FROM grid_frequency WHERE ts > ? - INTERVAL 1 HOUR ORDER BY ts');
    $st->execute([$last[0]]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$ts, $hz]) {
        $out['hour']['times'][] = ukgrid_iso($ts);
        $out['hour']['hz'][] = (float) $hz;
    }
    $st = $pdo->prepare('SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/60)*60) b, AVG(hz) FROM grid_frequency WHERE ts > ? - INTERVAL 24 HOUR GROUP BY b ORDER BY b');
    $st->execute([$last[0]]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$b, $hz]) {
        $out['day']['times'][] = ukgrid_iso($b);
        $out['day']['hz'][] = round((float) $hz, 3);
    }
    foreach (['low' => 'ASC', 'high' => 'DESC'] as $k => $dir) {
        $st = $pdo->prepare("SELECT ts, hz FROM grid_frequency WHERE ts > ? - INTERVAL 24 HOUR ORDER BY hz {$dir}, ts DESC LIMIT 1");
        $st->execute([$last[0]]);
        $r = $st->fetch(PDO::FETCH_NUM);
        $out[$k] = $r ? ['ts' => ukgrid_iso($r[0]), 'hz' => (float) $r[1]] : null;
    }
}
header('Cache-Control: public, max-age=60');
echo json_encode($out);
