<?php
/**
 * Cron entry point for EirGrid ingestion (Ireland: demand, generation, wind,
 * interconnection with GB). Optional - see includes/refresh.php for the
 * no-cron on-demand alternative.
 *
 * See includes/ingest.php's ukgrid_ingest_eirgrid() for why this is written
 * defensively (this project's build environment couldn't get a live
 * response from smartgriddashboard.com to verify the exact field names) and
 * what to do if it logs an ERROR.
 *
 * Recommended cron frequency if you do use cron: every 15 minutes.
 */

require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$pdo = ukgrid_db();
$result = ukgrid_ingest_eirgrid($pdo, $config, 25); // logs to ingest_log itself

ukgrid_out(($result['rows'] > 0 ? 'OK' : 'ERROR') . ' - ' . $result['rows'] . ' rows written/updated.' . (empty($result['errors']) ? '' : ' Errors: ' . implode('; ', $result['errors'])));
