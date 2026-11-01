<?php
/**
 * Cron entry point for NESO's "Constraint Breakdown Costs and Volume"
 * dataset (network constraint costs, by constraint type - see the long
 * comment on ukgrid_ingest_constraints() in includes/ingest.php for the
 * important caveat that this is NOT broken down by generation technology,
 * so it isn't a "wind curtailment" figure on its own). Powers the
 * "Network constraint costs" panel on pages/renewables.html.
 *
 * OPTIONAL - this source also refreshes with zero cron access via
 * includes/refresh.php's on-demand path (it's in refresh.intervals_minutes
 * in includes/config.php.example, at a 1440-minute/daily interval), so a
 * normal page visit will populate it once it's overdue even if this cron
 * job is never set up. Running it as real cron on top of that is fine too
 * (see README-DEPLOY.md Step 5) but not required.
 * Recommended cron frequency if you do use it: daily. NESO only updates
 * this dataset weekly, so anything more frequent just re-fetches the same
 * numbers.
 */

require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$pdo = ukgrid_db();
$result = ukgrid_ingest_constraints($pdo, $config, 20); // logs to ingest_log itself

ukgrid_out(($result['rows'] > 0 ? 'OK' : 'ERROR') . ' - ' . $result['rows'] . ' rows written/updated.' . (empty($result['errors']) ? '' : ' Errors: ' . implode('; ', $result['errors'])));
