<?php
/**
 * Shared bootstrap for the three cron entry points in this folder.
 *
 * Cron is now OPTIONAL - see includes/refresh.php for a no-cron
 * alternative that triggers ingestion from ordinary page loads instead
 * (turn it on via includes/config.php > refresh > on_demand). If you do
 * have cron access, a cPanel Cron Job running
 *   php /home/USERNAME/path/to/cron/fetch_elexon.php
 * directly (no web access to this folder needed) is still the option with
 * the most consistently fresh data and zero added page-load latency.
 *
 * Fallback: if your host can only trigger cron via a URL, set
 * `cron_http_secret` in includes/config.php to a long random string, and
 * call e.g. https://yoursite.example/cron/fetch_elexon.php?secret=... - any
 * request without the correct secret is rejected.
 */

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/ingest.php';

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak errors into cron/HTTP output
ini_set('log_errors', '1');
set_time_limit(120);
date_default_timezone_set('UTC');

$config = ukgrid_load_config();

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $secret = $config['cron_http_secret'] ?? '';
    $supplied = $_GET['secret'] ?? '';
    if ($secret === '' || !hash_equals($secret, (string) $supplied)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo "Forbidden. Run this script via cron CLI, or set cron_http_secret in includes/config.php and pass ?secret=...\n";
        exit;
    }
    header('Content-Type: text/plain');
}

function ukgrid_out(string $line): void
{
    echo $line . "\n";
}
