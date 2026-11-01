<?php
/**
 * Shared HTTP-GET-JSON helper, used by both the cron scripts and the
 * on-demand (page-load-triggered) refresh path.
 */

/**
 * A URL with any API key/token query parameters masked, for log lines -
 * ENTSO-E's securityToken, the EIA's api_key and similar would otherwise
 * be written in full to includes/refresh.log and shown on
 * tools/full-refresh.php whenever a request fails.
 */
function ukgrid_redact_url(string $url): string
{
    return preg_replace('/([?&](?:securityToken|api_key|apikey|key|token|access_token)=)[^&]*/i', '$1[redacted]', $url);
}

/**
 * GET a URL and decode it as JSON. Returns null on any failure - callers
 * should treat null as "skip this run, try again later" rather than a
 * fatal error, since these are third-party APIs that occasionally have
 * blips or (for the on-demand path) a deliberately short timeout.
 */
/**
 * A hard stop for every outgoing request in this PHP run, set by
 * cron/run-due.php (ukgrid_http_set_deadline()). The host ends a web
 * request at about 60 seconds with a bare "500 Internal Server Error", and
 * some sources make many requests in a row, so a per-request timeout alone
 * can't keep a whole run inside that. With a deadline set, each request's
 * timeout is cut to the time left, and once under 2 seconds remain no new
 * request is started (it returns null, which every caller already treats
 * as "try again next time"). Page loads never set one.
 */
function ukgrid_http_set_deadline(?float $unixTime): void
{
    $GLOBALS['ukgrid_http_deadline'] = $unixTime;
}

/** Returns the timeout to use, or 0 if the deadline leaves no time to start. */
function ukgrid_http_cap_timeout(int $timeoutSeconds, string $safeUrl): int
{
    $deadline = $GLOBALS['ukgrid_http_deadline'] ?? null;
    if ($deadline === null) {
        return $timeoutSeconds;
    }
    $left = (int) floor($deadline - microtime(true));
    if ($left < 2) {
        if (function_exists('ukgrid_file_log')) {
            ukgrid_file_log('Skipped (run time limit reached, will run next time): ' . $safeUrl);
        }
        return 0;
    }
    return min($timeoutSeconds, $left);
}

function ukgrid_http_get_json(string $url, int $timeoutSeconds = 20, array $extraHeaders = []): ?array
{
    $safeUrl = ukgrid_redact_url($url);
    $timeoutSeconds = ukgrid_http_cap_timeout($timeoutSeconds, $safeUrl);
    if ($timeoutSeconds === 0) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSeconds),
        CURLOPT_FOLLOWLOCATION => true,
        // Ask for gzip/deflate and let curl unpack it - big XML reports
        // (IESO's year-to-date GenOutputbyFuelHourly file is 6MB+ by the
        // autumn) download far faster compressed.
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $extraHeaders),
        CURLOPT_USERAGENT => 'GridPulse/1.0 (+https://gridpulse.info; contact via site data-sources page)',
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
        $msg = "HTTP error fetching {$safeUrl}: curl errno {$errno} - {$error}";
        error_log('ukgrid: ' . $msg);
        if (function_exists('ukgrid_file_log')) {
            ukgrid_file_log($msg);
        }
        return null;
    }
    if ($status < 200 || $status >= 300) {
        $bodyPreview = mb_substr((string) $body, 0, 300);
        $msg = "HTTP {$status} fetching {$safeUrl}" . ($bodyPreview !== '' ? " - body starts: {$bodyPreview}" : ' - empty body');
        error_log('ukgrid: ' . $msg);
        if (function_exists('ukgrid_file_log')) {
            ukgrid_file_log($msg);
        }
        return null;
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        $bodyPreview = mb_substr((string) $body, 0, 300);
        $msg = "non-JSON or empty response from {$safeUrl}" . ($bodyPreview !== '' ? " - body starts: {$bodyPreview}" : ' - empty body');
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
 * Same as ukgrid_http_get_json() but adds a "Authorization: Bearer
 * <token>" header - needed for the Open Electricity API (see
 * ukgrid_ingest_openelectricity() in includes/ingest.php), which rejects
 * requests with no bearer token rather than accepting one as a query
 * parameter the way the EIA API does. Kept as its own function rather than
 * adding an optional header param to ukgrid_http_get_json() so every
 * existing call site (which all pass a bare URL) stays untouched.
 */
function ukgrid_http_get_json_auth(string $url, string $bearerToken, int $timeoutSeconds = 20): ?array
{
    $safeUrl = ukgrid_redact_url($url);
    $timeoutSeconds = ukgrid_http_cap_timeout($timeoutSeconds, $safeUrl);
    if ($timeoutSeconds === 0) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSeconds),
        CURLOPT_FOLLOWLOCATION => true,
        // Ask for gzip/deflate and let curl unpack it - big XML reports
        // (IESO's year-to-date GenOutputbyFuelHourly file is 6MB+ by the
        // autumn) download far faster compressed.
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $bearerToken],
        CURLOPT_USERAGENT => 'GridPulse/1.0 (+https://gridpulse.info; contact via site data-sources page)',
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        $msg = "HTTP error fetching {$safeUrl}: curl errno {$errno} - {$error}";
        error_log('ukgrid: ' . $msg);
        if (function_exists('ukgrid_file_log')) {
            ukgrid_file_log($msg);
        }
        return null;
    }
    if ($status < 200 || $status >= 300) {
        // Open Electricity returns a JSON body even on 4xx (e.g.
        // {"success":false,"error":"Unsupported metrics: demand"}) that's
        // genuinely useful for diagnosis - same reasoning as
        // ukgrid_http_get_raw()'s ENTSO-E 4xx handling - but this still
        // treats it as a failed fetch rather than parsing it as success.
        $bodyPreview = mb_substr((string) $body, 0, 300);
        $msg = "HTTP {$status} fetching {$safeUrl}" . ($bodyPreview !== '' ? " - body starts: {$bodyPreview}" : ' - empty body');
        error_log('ukgrid: ' . $msg);
        if (function_exists('ukgrid_file_log')) {
            ukgrid_file_log($msg);
        }
        return null;
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        $bodyPreview = mb_substr((string) $body, 0, 300);
        $msg = "non-JSON or empty response from {$safeUrl}" . ($bodyPreview !== '' ? " - body starts: {$bodyPreview}" : ' - empty body');
        error_log('ukgrid: ' . $msg);
        if (function_exists('ukgrid_file_log')) {
            ukgrid_file_log($msg);
        }
        return null;
    }
    return $data;
}

/**
 * Same as ukgrid_http_get_json() but for endpoints that return XML rather
 * than JSON (currently only the ENTSO-E Transparency Platform - see
 * ukgrid_ingest_entsoe() in includes/ingest.php) - returns the raw response
 * body as a string rather than decoding it, since XML parsing is specific
 * to each document's schema and belongs in the caller. Returns null on any
 * HTTP/network failure, same convention as ukgrid_http_get_json().
 */
function ukgrid_http_get_raw(string $url, int $timeoutSeconds = 20, array $extraHeaders = []): ?string
{
    $safeUrl = ukgrid_redact_url($url);
    $timeoutSeconds = ukgrid_http_cap_timeout($timeoutSeconds, $safeUrl);
    if ($timeoutSeconds === 0) {
        return null;
    }
    // A "Range: bytes=-N" request (see ukgrid_ingest_ieso()) asks for just
    // the last N bytes of a file - compression is left off for those, since
    // byte ranges of a compressed response can't be unpacked on their own.
    $isRange = (bool) array_filter($extraHeaders, function ($h) { return stripos($h, 'Range:') === 0; });
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSeconds),
        CURLOPT_FOLLOWLOCATION => true,
        // Ask for gzip/deflate and let curl unpack it - big XML reports
        // download far faster compressed.
        CURLOPT_ENCODING => $isRange ? 'identity' : '',
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/xml'], $extraHeaders),
        CURLOPT_USERAGENT => 'GridPulse/1.0 (+https://gridpulse.info; contact via site data-sources page)',
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        $msg = "HTTP error fetching {$safeUrl}: curl errno {$errno} - {$error}";
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
        $msg = "HTTP {$status} fetching {$safeUrl}" . ($bodyPreview !== '' ? " - body starts: {$bodyPreview}" : ' - empty body');
        error_log('ukgrid: ' . $msg);
        if (function_exists('ukgrid_file_log')) {
            ukgrid_file_log($msg);
        }
        return null;
    }
    return (string) $body;
}
