<?php
/**
 * Shared HTTP-GET-JSON helper, used by both the cron scripts and the
 * on-demand (page-load-triggered) refresh path.
 */

/**
 * GET a URL and decode it as JSON. Returns null on any failure - callers
 * should treat null as "skip this run, try again later" rather than a
 * fatal error, since these are third-party APIs that occasionally have
 * blips or (for the on-demand path) a deliberately short timeout.
 */
function ukgrid_http_get_json(string $url, int $timeoutSeconds = 20): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSeconds),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'UK-Grid-Live-Plus/1.0 (+https://github.com/KateMorley/grid; contact via site data-sources page)',
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Also written to includes/refresh.log (not just PHP's error_log) so a
    // failed fetch shows up in the same trail as everything else - see
    // ukgrid_file_log() in includes/db.php. Callers' own error messages
    // (e.g. "request failed or returned invalid JSON") only say THAT it
    // failed; this says WHY.
    if ($errno !== 0) {
        $msg = "HTTP error fetching {$url}: curl errno {$errno} - {$error}";
        error_log('ukgrid: ' . $msg);
        if (function_exists('ukgrid_file_log')) {
            ukgrid_file_log($msg);
        }
        return null;
    }
    if ($status < 200 || $status >= 300) {
        $bodyPreview = mb_substr((string) $body, 0, 300);
        $msg = "HTTP {$status} fetching {$url}" . ($bodyPreview !== '' ? " - body starts: {$bodyPreview}" : ' - empty body');
        error_log('ukgrid: ' . $msg);
        if (function_exists('ukgrid_file_log')) {
            ukgrid_file_log($msg);
        }
        return null;
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        $bodyPreview = mb_substr((string) $body, 0, 300);
        $msg = "non-JSON or empty response from {$url}" . ($bodyPreview !== '' ? " - body starts: {$bodyPreview}" : ' - empty body');
        error_log('ukgrid: ' . $msg);
        if (function_exists('ukgrid_file_log')) {
            ukgrid_file_log($msg);
        }
        return null;
    }
    return $data;
}

function ukgrid_iso_to_mysql(string $iso): ?string
{
    $ts = strtotime($iso);
    return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
}

/**
 * Same as ukgrid_http_get_json() but for endpoints that return XML rather
 * than JSON (currently only the ENTSO-E Transparency Platform - see
 * ukgrid_ingest_entsoe() in includes/ingest.php) - returns the raw response
 * body as a string rather than decoding it, since XML parsing is specific
 * to each document's schema and belongs in the caller. Returns null on any
 * HTTP/network failure, same convention as ukgrid_http_get_json().
 */
function ukgrid_http_get_raw(string $url, int $timeoutSeconds = 20): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSeconds),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Accept: application/xml'],
        CURLOPT_USERAGENT => 'UK-Grid-Live-Plus/1.0 (+https://github.com/KateMorley/grid; contact via site data-sources page)',
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        $msg = "HTTP error fetching {$url}: curl errno {$errno} - {$error}";
        error_log('ukgrid: ' . $msg);
        if (function_exists('ukgrid_file_log')) {
            ukgrid_file_log($msg);
        }
        return null;
    }
    // ENTSO-E returns 4xx with a real XML Acknowledgement_MarketDocument
    // body explaining what went wrong (bad token, no data for that period,
    // etc.) - that's genuinely useful for diagnosis, so it's returned as
    // the body rather than treated as a hard failure here. Only a missing
    // body or a 5xx is treated as "nothing to parse".
    if ($body === '' || $body === false || $status >= 500) {
        $bodyPreview = mb_substr((string) $body, 0, 300);
        $msg = "HTTP {$status} fetching {$url}" . ($bodyPreview !== '' ? " - body starts: {$bodyPreview}" : ' - empty body');
        error_log('ukgrid: ' . $msg);
        if (function_exists('ukgrid_file_log')) {
            ukgrid_file_log($msg);
        }
        return null;
    }
    return (string) $body;
}
