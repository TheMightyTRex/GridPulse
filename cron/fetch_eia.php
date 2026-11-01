<?php
/**
 * Cron entry point for EIA ingestion (USA: demand, net generation, total
 * interchange, and generation by fuel type - all lower-48 aggregate). See
 * includes/refresh.php for the no-cron on-demand alternative.
 *
 * Requires includes/config.php's sources.eia_api_key to be set to a real
 * key (register free at https://www.eia.gov/opendata/register.php) - with
 * the default 'CHANGE-ME' placeholder, this runs and logs "OK, 0 rows"
 * without erroring, and pages/usa.html stays on illustrative data.
 *
 * See includes/ingest.php's ukgrid_ingest_eia() for why this is written
 * defensively (this project's build environment couldn't complete a live
 * authenticated request against api.eia.gov to verify the exact facet
 * codes) and what to do if it logs an ERROR.
 *
 * Recommended cron frequency if you do use cron: every 2-4 hours. EIA-930
 * data itself only updates about once a day, so anything more frequent
 * just spends API calls for no benefit.
 */

require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$pdo = ukgrid_db();
$result = ukgrid_ingest_eia($pdo, $config, 25); // logs to ingest_log itself

ukgrid_out(($result['rows'] > 0 ? 'OK' : 'ERROR') . ' - ' . $result['rows'] . ' rows written/updated.' . (empty($result['errors']) ? '' : ' Errors: ' . implode('; ', $result['errors'])));
