<?php
/**
 * GET api/flows.php?area=FR[&date=2026-10-31]
 *
 * Electricity across each of an area's borders for one local day (today
 * if no date): energy in and out, the latest flow, and an hourly net
 * series (positive = importing into this area).
 *   - Great Britain: each interconnector from Elexon, grouped by country.
 *   - Ireland: the net flow with Great Britain from EirGrid.
 *   - European countries: ENTSO-E cross-border physical flows (fetched a
 *     border at a time by the scheduler - see includes/flows_annual.php),
 *     with when each border was last updated.
 * Other areas have no border data here and get an empty list.
 */
require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$area = ukgrid_api_area($config);
$pdo = ukgrid_db();
$today = ukgrid_area_today($area);
$date = (string) ($_GET['date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today) {
    ukgrid_json_error('bad_date', 'date must be YYYY-MM-DD, today or earlier');
}
[$from, $to] = ukgrid_area_day_bounds($area, $date);
$code = $area['code'];
$neighbours = []; // key => ['label', 'cables' => [], 'series' => [ts => net MW], 'updated' => ?]

$add = function (string $key, string $label, string $ts, float $mw, ?string $cable = null) use (&$neighbours) {
    if (!isset($neighbours[$key])) {
        $neighbours[$key] = ['code' => $key, 'label' => $label, 'cables' => [], 'series' => [], 'updated' => null];
    }
    $neighbours[$key]['series'][$ts] = ($neighbours[$key]['series'][$ts] ?? 0) + $mw;
    if ($cable !== null && !in_array($cable, $neighbours[$key]['cables'], true)) {
        $neighbours[$key]['cables'][] = $cable;
    }
};
$bucketHours = 1.0;

if ($area['kind'] === 'gb') {
    $bucketHours = 0.5;
    $cables = [
        'INTFR' => ['FR', 'France', 'IFA'], 'INTIFA2' => ['FR', 'France', 'IFA2'], 'INTELEC' => ['FR', 'France', 'ElecLink'],
        'INTNED' => ['NL', 'Netherlands', 'BritNed'], 'INTNEM' => ['BE', 'Belgium', 'Nemo Link'],
        'INTNSL' => ['NO', 'Norway', 'North Sea Link'], 'INTVKL' => ['DK', 'Denmark', 'Viking Link'],
        'INTIRL' => ['IE', 'Ireland', 'Moyle'], 'INTEW' => ['IE', 'Ireland', 'East-West'], 'INTGRNL' => ['IE', 'Ireland', 'Greenlink'],
    ];
    $st = $pdo->prepare("SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/1800)*1800) b, fuel_type, AVG(mw) FROM readings_generation
        WHERE ts >= :f AND ts < :t AND fuel_type LIKE 'INT%' GROUP BY b, fuel_type ORDER BY b");
    $st->execute(['f' => $from, 't' => $to]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$ts, $f, $mw]) {
        [$k, $label, $cable] = $cables[$f] ?? [$f, $f, $f];
        $add($k, $label, $ts, (float) $mw, $cable);
    }
} elseif ($area['kind'] === 'ie') {
    $bucketHours = 0.5;
    $st = $pdo->prepare("SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/1800)*1800) b, AVG(mw) FROM readings_ie_generation
        WHERE ts >= :f AND ts < :t AND category = 'INTERCONNECTION' GROUP BY b ORDER BY b");
    $st->execute(['f' => $from, 't' => $to]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$ts, $mw]) {
        $add('GB', 'Great Britain', $ts, (float) $mw, 'Moyle, East-West and Greenlink');
    }
} elseif ($area['kind'] === 'entsoe') {
    ukgrid_flows_annual_ensure_tables($pdo);
    $zones = ukgrid_flow_zones();
    $st = $pdo->prepare("SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/3600)*3600) b, from_area, to_area, AVG(mw) FROM readings_flow
        WHERE ts >= :f AND ts < :t AND (from_area = :c1 OR to_area = :c2) GROUP BY b, from_area, to_area ORDER BY b");
    $st->execute(['f' => $from, 't' => $to, 'c1' => $code, 'c2' => $code]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$ts, $fa, $ta, $mw]) {
        $other = $fa === $code ? $ta : $fa;
        $add($other, $zones[$other][1] ?? $other, $ts, $ta === $code ? (float) $mw : -(float) $mw);
    }
    $state = ukgrid_fetch_state_get($pdo, 'FLOWS');
    foreach (ukgrid_flow_borders($config) as [$a, $b]) {
        if ($a !== $code && $b !== $code) {
            continue;
        }
        $other = $a === $code ? $b : $a;
        $s = $state[$a . '-' . $b] ?? null;
        if (!isset($neighbours[$other])) {
            $neighbours[$other] = ['code' => $other, 'label' => $zones[$other][1] ?? $other, 'cables' => [], 'series' => [], 'updated' => null];
        }
        $neighbours[$other]['updated'] = $s ? ukgrid_iso($s['fetched_at']) : null;
        $neighbours[$other]['status'] = $s['status'] ?? null;
    }
}

$out = [];
foreach ($neighbours as $n) {
    ksort($n['series']);
    $in = $outE = 0.0;
    foreach ($n['series'] as $mw) {
        if ($mw > 0) $in += $mw * $bucketHours; else $outE -= $mw * $bucketHours;
    }
    $lastTs = $n['series'] ? array_key_last($n['series']) : null;
    $out[] = [
        'code' => $n['code'], 'label' => $n['label'], 'cables' => $n['cables'],
        'in_mwh' => round($in, 1), 'out_mwh' => round($outE, 1),
        'latest_mw' => $lastTs ? round($n['series'][$lastTs], 1) : null, 'latest_ts' => ukgrid_iso($lastTs),
        'updated' => $n['updated'] ?? null, 'status' => $n['status'] ?? null,
        'times' => array_map('ukgrid_iso', array_keys($n['series'])),
        'values' => array_map(function ($v) { return round($v, 1); }, array_values($n['series'])),
    ];
}
usort($out, function ($a, $b) { return ($b['in_mwh'] + $b['out_mwh']) <=> ($a['in_mwh'] + $a['out_mwh']); });

$source = ['gb' => 'Elexon Insights Solution (interconnector flows)', 'ie' => 'EirGrid Smart Grid Dashboard',
           'entsoe' => 'ENTSO-E Transparency Platform (cross-border physical flows)'][$area['kind']] ?? null;
echo json_encode(['ok' => true, 'area' => ukgrid_area_public($area), 'date' => $date, 'source' => $source,
                  'bucket_minutes' => (int) ($bucketHours * 60), 'neighbours' => $out]);
