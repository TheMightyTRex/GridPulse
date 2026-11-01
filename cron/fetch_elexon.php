<?php
/**
 * Cron entry point for Elexon ingestion (generation mix, price, demand).
 * This is entirely OPTIONAL - see includes/refresh.php for the no-cron
 * on-demand alternative, which the site uses automatically if enabled in
 * includes/config.php. Safe to run both at once (see refresh.php's
 * locking, which any cron-run overlap would also respect via ingest_log
 * staleness checks - though cron running its own schedule doesn't use the
 * lock at all, since it's not competing with concurrent web requests).
 *
 * Recommended cron frequency if you do use cron: every 10-15 minutes.
 */

require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$pdo = ukgrid_db();
$result = ukgrid_ingest_elexon($pdo, $config, 25); // logs to ingest_log itself

ukgrid_out(($result['rows'] > 0 ? 'OK' : 'ERROR') . ' - ' . $result['rows'] . ' rows written/updated.' . (empty($result['errors']) ? '' : ' Errors: ' . implode('; ', $result['errors'])));
