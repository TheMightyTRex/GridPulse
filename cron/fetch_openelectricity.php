<?php
/**
 * Cron entry point for Open Electricity ingestion (Australia: AEMO NEM
 * demand, generation by fuel technology group, and a demand-weighted
 * average spot price). See includes/refresh.php for the no-cron
 * on-demand alternative.
 *
 * Requires includes/config.php's sources.openelectricity_api_key to be set
 * to a real key (register free, instant, at
 * https://platform.openelectricity.org.au/sign-up) - with the default
 * 'CHANGE-ME' placeholder, this runs and logs "OK, 0 rows" without
 * erroring, and pages/australia.html stays on illustrative data.
 *
 * Unlike cron/fetch_eia.php's equivalent docblock, this integration WAS
 * exercised against the live API with a real key while it was built - see
 * includes/ingest.php's ukgrid_ingest_openelectricity() for the confirmed
 * endpoint/response details.
 *
 * Recommended cron frequency if you do use cron: every 10-15 minutes.
 * AEMO's NEM dispatch data updates every 5 minutes, so anything much more
 * frequent than that just spends API calls for no benefit; anything much
 * less frequent and the page will look more "stale" than it needs to.
 */

require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$pdo = ukgrid_db();
$result = ukgrid_ingest_openelectricity($pdo, $config, 25); // logs to ingest_log itself

ukgrid_out(($result['rows'] > 0 ? 'OK' : 'ERROR') . ' - ' . $result['rows'] . ' rows written/updated.' . (empty($result['errors']) ? '' : ' Errors: ' . implode('; ', $result['errors'])));
