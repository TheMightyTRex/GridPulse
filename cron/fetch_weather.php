<?php
/**
 * Cron entry point for Open-Meteo ingestion (wind speed + cloud cover for
 * one representative GB point - see weather_lat/weather_lon in
 * includes/config.php.example). Powers the "Wind speed & cloud cover" card
 * on the History page.
 *
 * OPTIONAL - this source also refreshes with zero cron access via
 * includes/refresh.php's on-demand path (it's in refresh.intervals_minutes
 * in includes/config.php.example, same as Elexon/EirGrid/etc), so a
 * normal page visit will populate it once it's overdue even if this cron
 * job is never set up. Running it as real cron on top of that is still
 * fine and slightly more consistent (see README-DEPLOY.md Step 5) - it
 * just isn't required the way it might look at first glance.
 * Recommended cron frequency if you do use it: hourly (Open-Meteo's own
 * data doesn't update faster than that anyway).
 */

require __DIR__ . '/_bootstrap.php';

$config = ukgrid_load_config();
$pdo = ukgrid_db();
$result = ukgrid_ingest_weather($pdo, $config, 15); // logs to ingest_log itself

ukgrid_out(($result['rows'] > 0 ? 'OK' : 'ERROR') . ' - ' . $result['rows'] . ' rows written/updated.' . (empty($result['errors']) ? '' : ' Errors: ' . implode('; ', $result['errors'])));
