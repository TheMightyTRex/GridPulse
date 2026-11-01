<?php
/**
 * Cron entry point for SEMO ingestion (Single Electricity Market Operator -
 * the all-island Republic of Ireland + Northern Ireland Imbalance Price,
 * i.e. real 5-minute settlement pricing). See includes/refresh.php for the
 * no-cron on-demand alternative.
 *
 * Genuinely keyless - no config.php setting needs to be filled in for this
 * one to work, unlike cron/fetch_eia.php or cron/fetch_openelectricity.php.
 *
 * This WAS exercised against the live API while it was built - a real,
 * unauthenticated request against reports.sem-o.com returned real, current
 * data. See includes/ingest.php's ukgrid_ingest_semo() for the confirmed
 * endpoint/response details, and its docblock for the one thing that
 * couldn't be independently confirmed (whether report timestamps are UTC
 * or Irish local time).
 *
 * Recommended cron frequency if you do use cron: every 15 minutes. SEMO
 * publishes a new imbalance price roughly every 5 minutes, but
 * ukgrid_ingest_semo() always re-fetches a full hour's worth each run
 * (heavy overlap, ON DUPLICATE KEY UPDATE makes that safe) so anything
 * much more frequent than 15 minutes just repeats the same small set of
 * requests for no benefit.
 */

require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$pdo = ukgrid_db();
$result = ukgrid_ingest_semo($pdo, $config, 20); // logs to ingest_log itself

ukgrid_out(($result['rows'] > 0 ? 'OK' : 'ERROR') . ' - ' . $result['rows'] . ' rows written/updated.' . (empty($result['errors']) ? '' : ' Errors: ' . implode('; ', $result['errors'])));
