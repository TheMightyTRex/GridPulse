<?php
/**
 * Shared bootstrap for JSON API endpoints (api/current.php, api/series.php).
 */

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/refresh.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('UTC');

// Forces json_encode to print the shortest string that round-trips back to
// the same float (PHP's own default since 7.1), rather than a long decimal
// expansion like 141.56999999999999317878973670303821563720703125 for
// 141.57. Some hosts override serialize_precision away from its default
// (-1) in php.ini; setting it explicitly here guarantees clean numeric
// output regardless of that, without changing any query logic.
ini_set('serialize_precision', -1);

/**
 * Fatal-error safety net for every API endpoint (this file is required by
 * all of them). Without this, an uncaught exception or a true PHP fatal
 * error (out-of-memory, hitting max_execution_time, calling something that
 * doesn't exist) produces a blank page or the host's generic "HTTP ERROR
 * 500" - no JSON, and nothing written anywhere that says why. These two
 * handlers turn that into a proper JSON 500 response and a detailed line in
 * includes/refresh.log (same file the on-demand refresh path uses - see
 * ukgrid_file_log() in includes/db.php), so "the site 500s" always leaves a
 * trace of exactly what broke, where, and on which endpoint.
 *
 * set_exception_handler catches anything thrown and not caught elsewhere.
 * register_shutdown_function additionally catches true fatals that PHP
 * doesn't represent as a catchable Throwable at all - notably a script
 * hitting max_execution_time, which matters here since the on-demand
 * refresh path makes outbound HTTP calls with their own timeouts nested
 * inside PHP's overall one.
 */
function ukgrid_report_fatal(string $endpoint, string $detail): void
{
    if (function_exists('ukgrid_file_log')) {
        ukgrid_file_log('FATAL on ' . $endpoint . ': ' . $detail);
    }
    error_log('ukgrid fatal error on ' . $endpoint . ': ' . $detail);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'internal_error',
            'message' => 'Something went wrong handling this request. The exact error has been written to includes/refresh.log and the PHP error log.',
        ]);
    }
}

set_exception_handler(function (Throwable $e) {
    $endpoint = basename($_SERVER['SCRIPT_NAME'] ?? 'unknown');
    $detail = get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    ukgrid_report_fatal($endpoint, $detail);
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) {
        return;
    }
    $endpoint = basename($_SERVER['SCRIPT_NAME'] ?? 'unknown');
    $detail = $err['message'] . ' at ' . basename($err['file']) . ':' . $err['line'];
    ukgrid_report_fatal($endpoint, $detail);
});

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60'); // these values change at most every few minutes

// No-cron mode: if enabled in includes/config.php, this checks whether any
// data source is overdue and - at most once per request, lock-guarded -
// pulls fresh data before the endpoint below reads from the database. A
// no-op on almost every request once data exists, since it only does
// anything when a source is actually stale. See includes/refresh.php.
//
// Runs on every endpoint deliberately, even though current.php/
// ireland_current.php usually load first on any given page and would
// already have caught a stale source by the time series.php is called.
// An earlier version of this file restricted the check to just those two
// endpoints to cut down on repeated "is anything overdue?" queries - but
// pages/history.html calls api/series.php directly without ever loading
// current.php first, so that restriction meant the History page could
// never trigger a refresh on its own, only ever showing data that
// happened to already be fresh from an unrelated visit elsewhere on the
// site. Correctness (every page can independently keep its own data
// fresh with no cron job needed) matters more than the few extra
// already-cheap, indexed lookups this costs on a normal page load.
ukgrid_maybe_refresh(ukgrid_db(), ukgrid_load_config());

function ukgrid_json_error(string $code, string $message, int $httpStatus = 400): void
{
    http_response_code($httpStatus);
    echo json_encode(['ok' => false, 'error' => $code, 'message' => $message]);
    exit;
}

// Same range definitions as assets/app.js's RANGE_CONFIG - keep these two in
// sync if you ever change one (e.g. adding a range or changing a step size).
function ukgrid_range_config(): array
{
    return [
        '3hour'  => ['points' => 13, 'stepSeconds' => 15 * 60],
        'day'    => ['points' => 48, 'stepSeconds' => 30 * 60],
        'week'   => ['points' => 56, 'stepSeconds' => 3 * 3600],
        'month'  => ['points' => 30, 'stepSeconds' => 24 * 3600],
        'season' => ['points' => 13, 'stepSeconds' => 7 * 24 * 3600],
        'year'   => ['points' => 52, 'stepSeconds' => 7 * 24 * 3600],
        '5year'  => ['points' => 60, 'stepSeconds' => 30 * 24 * 3600],
        '10year' => ['points' => 40, 'stepSeconds' => 91 * 24 * 3600],
        'all'    => ['points' => 15, 'stepSeconds' => 365 * 24 * 3600],
    ];
}

/**
 * The UK meteorological season containing $now, per the Met Office's
 * calendar-based definition: Winter = Dec-Feb, Spring = Mar-May,
 * Summer = Jun-Aug, Autumn = Sep-Nov. Winter's label spans the two
 * calendar years it covers (e.g. "Winter 2025/26"), matching the
 * convention already used for the winter peak-demand record on the
 * homepage.
 *
 * Used by api/series.php so the History page's "Season" tab shows the
 * actual current season to date (e.g. 1 Jun onward on 10 Aug) rather than
 * a rolling 91-day window, which would drift across two calendar seasons
 * depending on what day you load the page. Keep in sync with
 * getCurrentUkSeason() in assets/app.js.
 */
function ukgrid_current_uk_season(?int $now = null): array
{
    $b = ukgrid_uk_season_bounds(0, $now);
    return ['name' => $b['name'], 'label' => $b['label'], 'start' => $b['start']];
}

/**
 * Generalised version of ukgrid_current_uk_season(): the UK meteorological
 * season containing $now, shifted back by $yearOffset whole years (0 = the
 * current season in progress, -1 = the same season last year, etc). Used
 * by the History page's "compare seasons" checkbox (api/series.php's
 * season_offset query param) to bound a prior year's equivalent season.
 *
 * For $yearOffset === 0 the season is still in progress, so 'end' is just
 * $now; for any other offset the season has already fully elapsed, so
 * 'end' is that season's own natural close (one second before the next
 * season began). Keep in sync with getUkSeasonBounds() in assets/app.js.
 */
function ukgrid_uk_season_bounds(int $yearOffset = 0, ?int $now = null): array
{
    $now = $now ?? time();
    $m = (int) gmdate('n', $now);
    $y = (int) gmdate('Y', $now);

    if ($m === 12 || $m <= 2) {
        $name = 'Winter';
        $startYear = ($m === 12) ? $y : $y - 1;
        $startMonth = 12;
    } elseif ($m <= 5) {
        $name = 'Spring';
        $startYear = $y;
        $startMonth = 3;
    } elseif ($m <= 8) {
        $name = 'Summer';
        $startYear = $y;
        $startMonth = 6;
    } else {
        $name = 'Autumn';
        $startYear = $y;
        $startMonth = 9;
    }

    $startYear += $yearOffset;
    $label = $name === 'Winter'
        ? 'Winter ' . $startYear . '/' . substr((string) ($startYear + 1), -2)
        : $name . ' ' . $startYear;

    $start = gmmktime(0, 0, 0, $startMonth, 1, $startYear);
    $nextStartMonth = $startMonth + 3;
    $nextStartYear = $startYear;
    if ($nextStartMonth > 12) {
        $nextStartMonth -= 12;
        $nextStartYear += 1;
    }
    $nextStart = gmmktime(0, 0, 0, $nextStartMonth, 1, $nextStartYear);
    $end = ($yearOffset === 0) ? $now : min($nextStart - 1, $now);

    return ['name' => $name, 'label' => $label, 'start' => $start, 'end' => $end];
}

/** Fuel-type groupings shared by both endpoints - keep in sync with assets/data.js. */
function ukgrid_fuel_groups(): array
{
    return [
        // Renewable = wind (transmission + embedded), embedded solar, non-pumped hydro.
        'renewable' => ['WIND', 'WIND_EMBEDDED', 'SOLAR_EMBEDDED', 'NPSHYD'],
        // Non-renewable = everything else that isn't an interconnector or pumped storage.
        'non_renewable' => ['CCGT', 'OCGT', 'COAL', 'OIL', 'NUCLEAR', 'BIOMASS', 'OTHER'],
        // Pumped storage is tracked separately (it's not primary generation).
        'storage' => ['PS'],
    ];
}

function ukgrid_is_interconnector(string $fuelType): bool
{
    return strpos($fuelType, 'INT') === 0;
}
