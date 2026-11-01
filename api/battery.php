<?php
/**
 * GET api/battery.php
 *
 * GB battery storage from Elexon (see includes/battery.php): the latest
 * NOTIFIED figure (Physical Notifications - planned, not metered), the
 * last 24 hours of notified figures, and the newest 7 days of METERED
 * figures (B1610 - actual, published days later). MW, negative = charging.
 *
 * Response shape:
 *   { "ok": true, "units": 142,
 *     "now": { "ts": "...", "net_mw": -420.5, "charge_mw": -910.2, "discharge_mw": 489.7, "units": 131 } | null,
 *     "notified": [ { "t": "...", "net": -420.5, "charge": -910.2, "discharge": 489.7 }, ... ],
 *     "metered":  [ ... same ... ],
 *     "metered_latest": "..." | null }
 */

require __DIR__ . '/_bootstrap.php';

$pdo = ukgrid_db();
$out = ['ok' => true, 'units' => null, 'now' => null, 'notified' => [], 'metered' => [], 'metered_latest' => null];

$cache = @json_decode((string) @file_get_contents(__DIR__ . '/../includes/battery_units.json'), true);
if (is_array($cache) && isset($cache['units'])) {
    $out['units'] = count(ukgrid_battery_apply_overrides($cache['units'], ukgrid_load_config()));
}

$iso = fn ($t) => gmdate('Y-m-d\TH:i:s\Z', strtotime($t . ' UTC'));
$row = fn ($r) => ['t' => $iso($r['ts']), 'net' => (float) $r['net_mw'], 'charge' => (float) $r['charge_mw'], 'discharge' => (float) $r['discharge_mw']];

try {
    // Latest notified period that has started (PN is published ahead of time)
    $now = $pdo->prepare('SELECT * FROM readings_battery WHERE kind = "NOTIFIED" AND ts <= UTC_TIMESTAMP() AND ts >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR) ORDER BY ts DESC LIMIT 1');
    $now->execute();
    if ($r = $now->fetch()) {
        $out['now'] = ['ts' => $iso($r['ts']), 'net_mw' => (float) $r['net_mw'], 'charge_mw' => (float) $r['charge_mw'], 'discharge_mw' => (float) $r['discharge_mw'], 'units' => (int) $r['units']];
    }
    $n = $pdo->prepare('SELECT * FROM readings_battery WHERE kind = "NOTIFIED" AND ts >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) AND ts <= UTC_TIMESTAMP() ORDER BY ts');
    $n->execute();
    $out['notified'] = array_map($row, $n->fetchAll());

    $latest = $pdo->query('SELECT MAX(ts) FROM readings_battery WHERE kind = "METERED"')->fetchColumn();
    if ($latest) {
        $out['metered_latest'] = $iso($latest);
        $m = $pdo->prepare('SELECT * FROM readings_battery WHERE kind = "METERED" AND ts > DATE_SUB(:latest, INTERVAL 7 DAY) ORDER BY ts');
        $m->execute(['latest' => $latest]);
        $out['metered'] = array_map($row, $m->fetchAll());
    }
} catch (Throwable $e) {
    // Table not created yet (no battery ingest has run) - an empty, valid answer
}

echo json_encode($out);
