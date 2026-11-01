<?php
/**
 * GET api/day.php?area=FR[&date=2026-10-31]
 *
 * One local day for one area (today if no date): readings in regular
 * buckets (generation by fuel group, demand, price, carbon, net imports),
 * the day's summary (energy by fuel, shares, demand peak and low, price
 * range, imports) and any of the area's records set that day. Used by the
 * "Today so far" card, the replay slider and pages/day.html.
 * See includes/areas.php for the fuel groups and share definitions.
 */
require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$area = ukgrid_api_area($config);
$pdo = ukgrid_db();
$today = ukgrid_area_today($area);
$date = (string) ($_GET['date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today) {
    ukgrid_json_error('bad_date', 'date must be YYYY-MM-DD, today or earlier, in the area\'s own time zone');
}

[$from, $to] = ukgrid_area_day_bounds($area, $date);
$buckets = ukgrid_area_buckets($pdo, $area, $from, $to);
$summary = ukgrid_area_day_summary($area, $buckets, $date);

$times = [];
$groups = [];
$demand = $price = $carbon = $imports = [];
$present = [];
foreach ($buckets as $row) {
    foreach ($row['g'] as $g => $mw) {
        $present[$g] = true;
    }
}
foreach ($buckets as $ts => $row) {
    $times[] = ukgrid_iso($ts);
    foreach (array_keys($present) as $g) {
        $groups[$g][] = isset($row['g'][$g]) ? round($row['g'][$g], 1) : null;
    }
    $demand[] = $row['demand'] !== null ? round($row['demand'], 1) : null;
    $price[] = $row['price'] !== null ? round($row['price'], 2) : null;
    $carbon[] = $row['carbon'] !== null ? round($row['carbon'], 1) : null;
    $imports[] = $row['imports'] !== null ? round($row['imports'], 1) : null;
}

// Records whose all-time best falls on this day.
$recordsSet = [];
try {
    // Every record set on this day, including ones beaten since (each
    // says whether it still stands). The first time a value is recorded
    // isn't a "record broken", so only real breaks are listed.
    $rec = ukgrid_area_records($pdo, $area, true);
    $standing = [];
    foreach ($rec['board'] as $r) {
        $standing[$r['key']] = $r['day'];
    }
    foreach ($rec['breaks'] as $b) {
        if ($b['day'] === $date) {
            $b['still_stands'] = ($standing[$b['key']] ?? null) === $date;
            $recordsSet[] = $b;
        }
    }
    $since = $rec['since'];
} catch (Throwable $e) {
    $since = null;
}

$extremes = [];
if ($summary) {
    foreach ($summary['extremes'] as $k => [$v, $ts]) {
        $extremes[$k] = ['value' => $v, 'ts' => strlen((string) $ts) === 10 ? $ts : ukgrid_iso($ts)];
    }
    $summary['extremes'] = $extremes;
}

echo json_encode([
    'ok' => true,
    'area' => ukgrid_area_public($area),
    'date' => $date,
    'is_today' => $date === $today,
    'today' => $today,
    'records_since' => $since,
    'from' => ukgrid_iso($from),
    'to' => ukgrid_iso($to),
    'series' => [
        'times' => $times,
        'groups' => (object) $groups,
        'demand' => array_filter($demand, 'is_numeric') ? $demand : null,
        'price' => array_filter($price, 'is_numeric') ? $price : null,
        'carbon' => array_filter($carbon, 'is_numeric') ? $carbon : null,
        'imports' => array_filter($imports, 'is_numeric') ? $imports : null,
    ],
    'summary' => $summary,
    'records_set' => $recordsSet,
]);
