<?php
/**
 * Cron entry point for GB battery storage (Elexon Insights Solution:
 * notified output from Physical Notifications, metered output from B1610).
 * Keyless. See includes/battery.php for what is counted and why.
 *
 * Recommended cron frequency: every 30 minutes. Each run fetches the
 * current notified figures and catches up to 24 metered half hours.
 */

require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$pdo = ukgrid_db();
$result = ukgrid_ingest_battery($pdo, $config, 20, 8, 24); // logs to ingest_log itself
echo 'BATTERY: ' . $result['rows'] . ' rows' . ($result['errors'] ? ' - ' . implode('; ', $result['errors']) : '') . PHP_EOL;
