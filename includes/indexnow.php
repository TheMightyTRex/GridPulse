<?php
/**
 * IndexNow: tells Bing, Yandex, Seznam, Naver and Yep when pages change, so
 * they recrawl them soon instead of whenever they next pass.
 * https://www.indexnow.org/documentation
 *
 * Job INDEXNOW (every 60 minutes, scheduler and page loads). After each
 * upload it notices which pages in sitemap.xml have really changed and sends
 * just those, in one request. How it decides:
 *   - Each page file is hashed with the cache-busting ?v=... numbers taken
 *     out, so a release that only bumps the version sends nothing.
 *   - The hashes are kept in fetch_state (job INDEXNOW).
 *   - The very first run only records the hashes: every page was sent by
 *     hand on 9 November 2026.
 * The key file sits at the site root (see UKGRID_INDEXNOW_KEY). To stop
 * sending, set 'indexnow' => ['enabled' => false] in config.php (do this on
 * any test copy of the site).
 */

const UKGRID_INDEXNOW_KEY = 'f33267f25a6411679952364424607e41';

/** [url => sha1] for every page in sitemap.xml that exists on disk. */
function ukgrid_indexnow_pages(string $root): array
{
    $sitemap = @file_get_contents($root . '/sitemap.xml');
    if ($sitemap === false || !preg_match_all('#<loc>([^<]+)</loc>#', $sitemap, $m)) {
        return [];
    }
    $out = [];
    foreach ($m[1] as $url) {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $file = $root . ($path === '/' ? '/index.html' : $path);
        if (!is_file($file)) {
            continue;
        }
        $text = preg_replace('/\?v=[0-9a-z]+/', '', (string) file_get_contents($file));
        $out[$url] = sha1($text);
    }
    return $out;
}

function ukgrid_ingest_indexnow(PDO $pdo, array $config, int $timeoutSeconds = 10): array
{
    if (isset($config['indexnow']['enabled']) && !$config['indexnow']['enabled']) {
        ukgrid_log_ingest('INDEXNOW', 'OK', 0, 'turned off in config.php');
        return ['ok' => true, 'rows' => 0];
    }
    ukgrid_flows_annual_ensure_tables($pdo); // fetch_state
    $root = dirname(__DIR__);
    $pages = ukgrid_indexnow_pages($root);
    if (!$pages) {
        ukgrid_log_ingest('INDEXNOW', 'ERROR', 0, 'sitemap.xml missing or empty');
        return ['ok' => false];
    }
    $state = ukgrid_fetch_state_get($pdo, 'INDEXNOW');
    $first = !$state;
    $changed = [];
    foreach ($pages as $url => $hash) {
        $item = substr(sha1($url), 0, 20);
        $old = $state[$item]['message'] ?? null;
        if ($old !== $url . ' ' . $hash) {
            $changed[$url] = $item;
        }
    }
    if (!$changed) {
        ukgrid_log_ingest('INDEXNOW', 'OK', 0, 'no page has changed');
        return ['ok' => true, 'rows' => 0];
    }
    if (!$first) {
        $host = parse_url(array_key_first($pages), PHP_URL_HOST);
        $sent = ukgrid_indexnow_send($host, array_keys($changed), $timeoutSeconds);
        if (!$sent) {
            ukgrid_log_ingest('INDEXNOW', 'ERROR', 0, count($changed) . ' changed pages not sent (see refresh.log); will try again next run');
            return ['ok' => false];
        }
    }
    foreach ($changed as $url => $item) {
        ukgrid_fetch_state_set($pdo, 'INDEXNOW', $item, 'OK', $url . ' ' . $pages[$url]);
    }
    ukgrid_log_ingest('INDEXNOW', 'OK', $first ? 0 : count($changed), $first
        ? 'first run: recorded ' . count($changed) . ' pages without sending'
        : 'sent ' . count($changed) . ' changed pages: ' . implode(', ', array_map(function ($u) { return parse_url($u, PHP_URL_PATH); }, array_keys($changed))));
    return ['ok' => true, 'rows' => $first ? 0 : count($changed)];
}

/** One IndexNow request for up to 10,000 addresses. True if accepted (200 or 202). */
function ukgrid_indexnow_send(string $host, array $urls, int $timeoutSeconds): bool
{
    $timeoutSeconds = ukgrid_http_cap_timeout($timeoutSeconds, 'https://api.indexnow.org/indexnow');
    if ($timeoutSeconds === 0) {
        return false;
    }
    $ch = curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'host' => $host,
            'key' => UKGRID_INDEXNOW_KEY,
            'keyLocation' => 'https://' . $host . '/' . UKGRID_INDEXNOW_KEY . '.txt',
            'urlList' => array_values(array_slice($urls, 0, 10000)),
        ]),
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSeconds),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_USERAGENT => 'GridPulse/1.0 (+https://gridpulse.info)',
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno === 0 && ($status === 200 || $status === 202)) {
        return true;
    }
    ukgrid_file_log('IndexNow request failed: curl errno ' . $errno . ', HTTP ' . $status . ' - ' . mb_substr((string) $body, 0, 200));
    return false;
}
