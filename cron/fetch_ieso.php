<?php
/**
 * Cron entry point for IESO ingestion (Canada - Ontario only: demand and
 * generation by fuel type). See includes/refresh.php for the no-cron
 * on-demand alternative.
 *
 * No API key required - see includes/ingest.php's ukgrid_ingest_ieso() for
 * the report URLs and shapes, and pages/canada.html / pages/data-sources.html
 * for why this only covers Ontario, not all of Canada.
 *
 * Recommended cron frequency if you do use cron: every 15-30 minutes for
 * reasonably fresh demand figures - IESO's "most recent" report alias only
 * ever contains the current hour, so History for this page builds up from
 * repeated polling over time, the same way GB's own Elexon feed does.
 */

require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$pdo = ukgrid_db();
$result = ukgrid_ingest_ieso($pdo, $config, 20); // logs to ingest_log itself

ukgrid_out(($result['rows'] > 0 ? 'OK' : 'ERROR') . ' - ' . $result['rows'] . ' rows written/updated.' . (empty($result['errors']) ? '' : ' Errors: ' . implode('; ', $result['errors'])));
