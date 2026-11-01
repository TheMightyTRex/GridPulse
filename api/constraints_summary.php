<?php
/**
 * GET api/constraints_summary.php
 *
 * Network constraint costs & volumes, summed over the most recent 7 days
 * NESO has actually published (see readings_constraints in sql/schema.sql
 * and ukgrid_ingest_constraints() in includes/ingest.php) - NOT the last 7
 * calendar days, since this dataset updates weekly and can lag by several
 * days. "days_covered" tells the frontend exactly how many days' worth of
 * data the totals below represent, so pages/renewables.html can say
 * "sum over the last N days to DD Mon" accurately rather than assuming 7.
 *
 * Returns:
 *   { "ok": true, "from": "2026-08-05", "to": "2026-08-11", "days_covered": 7,
 *     "thermal":      { "cost": 12345.67, "volume_mwh": 4321.0 },
 *     "voltage":      { "cost": 1234.56,  "volume_mwh": 987.0 },
 *     "inertia":      { "cost": 234.56,   "volume_mwh": 87.0 },
 *     "largest_loss": { "cost": 3456.78,  "volume_mwh": -654.0 },
 *     "total_cost": 17271.57 }
 * or { "ok": false } if no data has been ingested yet (e.g.
 * cron/fetch_constraints.php isn't set up on this install).
 *
 * Each category is a constraint TYPE (why an action was taken), not a
 * generation technology - see the long comment on
 * ukgrid_ingest_constraints() for why there's no "wind" figure here.
 * pages/renewables.html's own copy is responsible for explaining that
 * "thermal" is a proxy for wind curtailment, not a wind-specific number -
 * this endpoint just returns NESO's own categories as published.
 */

require __DIR__ . '/_bootstrap.php';

$pdo = ukgrid_db();

$stmt = $pdo->prepare(
    'SELECT dt, thermal_cost, thermal_volume_mwh, voltage_cost, voltage_volume_mwh,
            inertia_cost, inertia_volume_mwh, largest_loss_cost, largest_loss_volume_mwh
     FROM readings_constraints
     ORDER BY dt DESC
     LIMIT 7'
);
$stmt->execute();
$rows = $stmt->fetchAll();

if (empty($rows)) {
    echo json_encode(['ok' => false]);
    exit;
}

function ukgrid_sum_col(array $rows, string $col): float
{
    $sum = 0.0;
    foreach ($rows as $r) {
        if ($r[$col] !== null) {
            $sum += (float) $r[$col];
        }
    }
    return round($sum, 2);
}

$dates = array_column($rows, 'dt');
sort($dates);

$thermalCost = ukgrid_sum_col($rows, 'thermal_cost');
$voltageCost = ukgrid_sum_col($rows, 'voltage_cost');
$inertiaCost = ukgrid_sum_col($rows, 'inertia_cost');
$lossCost = ukgrid_sum_col($rows, 'largest_loss_cost');

echo json_encode([
    'ok' => true,
    'from' => $dates[0],
    'to' => $dates[count($dates) - 1],
    'days_covered' => count($rows),
    'thermal' => ['cost' => $thermalCost, 'volume_mwh' => ukgrid_sum_col($rows, 'thermal_volume_mwh')],
    'voltage' => ['cost' => $voltageCost, 'volume_mwh' => ukgrid_sum_col($rows, 'voltage_volume_mwh')],
    'inertia' => ['cost' => $inertiaCost, 'volume_mwh' => ukgrid_sum_col($rows, 'inertia_volume_mwh')],
    'largest_loss' => ['cost' => $lossCost, 'volume_mwh' => ukgrid_sum_col($rows, 'largest_loss_volume_mwh')],
    'total_cost' => round($thermalCost + $voltageCost + $inertiaCost + $lossCost, 2),
]);
