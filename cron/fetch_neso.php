<?php
/**
 * Cron entry point for NESO ingestion (embedded solar/wind estimates).
 * Optional - see includes/refresh.php for the no-cron on-demand alternative
 * (NESO is excluded from the on-demand default schedule since, unlike every
 * other source, it needs a few sequential "which dataset is this right now?"
 * discovery calls before it can even fetch data - see
 * ukgrid_ingest_neso(); cron is the better fit for it if you have cron
 * access at all).
 *
 * See includes/ingest.php's ukgrid_ingest_neso() for why this is written
 * defensively (auto-detects the current NESO dataset/columns rather than
 * assuming a fixed resource_id) and what to do if it logs an ERROR.
 *
 * Recommended cron frequency if you do use cron: every 30 minutes.
 */

require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$pdo = ukgrid_db();
$result = ukgrid_ingest_neso($pdo, $config, 25); // logs to ingest_log itself

ukgrid_out(($result['rows'] > 0 ? 'OK' : 'ERROR') . ' - ' . $result['rows'] . ' rows written/updated.' . (empty($result['errors']) ? '' : ' Errors: ' . implode('; ', $result['errors'])));
