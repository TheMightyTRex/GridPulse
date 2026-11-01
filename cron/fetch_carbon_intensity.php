<?php
/**
 * Cron entry point for Carbon Intensity API ingestion (emissions + mix %).
 * Optional - see includes/refresh.php for the no-cron on-demand alternative.
 * Recommended cron frequency if you do use cron: every 30 minutes.
 */

require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$pdo = ukgrid_db();
$result = ukgrid_ingest_carbon_intensity($pdo, $config, 20); // logs to ingest_log itself

ukgrid_out(($result['rows'] > 0 ? 'OK' : 'ERROR') . ' - ' . $result['rows'] . ' rows written/updated.' . (empty($result['errors']) ? '' : ' Errors: ' . implode('; ', $result['errors'])));
