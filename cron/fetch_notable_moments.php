<?php
/**
 * Cron entry point for recomputing "notable moments" - the highest/lowest
 * value this install has itself recorded for a handful of GB metrics (day-
 * ahead price, carbon intensity, demand, wind, solar). Not an external API
 * call: just a re-scan of this site's own already-stored readings tables -
 * see ukgrid_compute_notable_moments() in includes/ingest.php, and the long
 * comment on the notable_moments table in sql/schema.sql for the important
 * distinction between this and the hand-typed all-time "Records" panel.
 * Powers the "Notable moments" section on index.html.
 *
 * OPTIONAL - this source also refreshes with zero cron access via
 * includes/refresh.php's on-demand path (it's in refresh.intervals_minutes
 * in includes/config.php.example, at a 1440-minute/daily interval), so a
 * normal page visit will populate it once it's overdue even if this cron
 * job is never set up. Running it as real cron on top of that is fine too
 * (see README-DEPLOY.md Step 5) but not required.
 * Recommended cron frequency if you do use it: daily - there's no benefit
 * to running this more often, since it only ever changes when a new
 * high/low actually gets recorded.
 */

require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$pdo = ukgrid_db();
$result = ukgrid_compute_notable_moments($pdo, $config, 20); // logs to ingest_log itself

ukgrid_out(($result['rows'] > 0 ? 'OK' : 'ERROR') . ' - ' . $result['rows'] . ' rows written/updated.' . (empty($result['errors']) ? '' : ' Errors: ' . implode('; ', $result['errors'])));
