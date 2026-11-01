<?php
/**
 * UK Grid: Live+ - friendly error pages for PHP pages that return HTML
 * (everything in tools/). The static pages in errors/ are shown by the web
 * server for missing pages (404), blocked folders (403) and server errors
 * (500) - see the .htaccess in the site root. But a PHP fatal error usually
 * comes back as an empty 500 that the web server passes straight through
 * (the browser then shows its own "HTTP ERROR 500"), so PHP pages that
 * build HTML call ukgrid_html_errors() first, and any fatal error is shown
 * using errors/500.html instead.
 *
 * The visitor only ever sees a plain explanation. The real error (message,
 * file and line) goes to includes/refresh.log and the PHP error log.
 *
 * JSON endpoints (api/, cron/) don't use this - they answer in JSON or
 * plain text, which is what their callers read.
 */

if (!function_exists('ukgrid_send_error_page')) {

    /**
     * Prints errors/<code>.html (falling back to 500.html, then to a bare
     * page) with an optional extra paragraph, which must already be HTML-
     * escaped. Safe to call before or after other output.
     */
    function ukgrid_send_error_page(int $code, string $detailHtml = ''): void
    {
        $dir = dirname(__DIR__) . '/errors/';
        $file = is_readable($dir . $code . '.html') ? $dir . $code . '.html' : $dir . '500.html';
        $html = is_readable($file) ? (string) file_get_contents($file) : '';
        if ($html === '') {
            $html = '<!DOCTYPE html><html lang="en-GB"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>Something went wrong - UK Grid: Live+</title></head><body style="font:16px/1.5 system-ui,sans-serif;max-width:36rem;margin:2rem auto;padding:0 1rem">'
                . '<h1>Something went wrong on our side</h1><p>This page couldn\'t load because of a problem on the server. Please try again in a minute or two.</p>'
                . '<!-- error:detail --><p><a href="/index.html">Go to the live overview</a></p></body></html>';
        }
        if ($detailHtml !== '') {
            $html = str_replace('<!-- error:detail -->', '<div class="error-page__detail">' . $detailHtml . '</div>', $html);
        }
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex');
        }
        echo $html;
    }

    /**
     * Call at the very top of a PHP page that outputs HTML. Turns fatal
     * errors into errors/500.html, and tells includes/db.php to answer a
     * config or database problem with an HTML page rather than JSON.
     */
    function ukgrid_html_errors(): void
    {
        if (!defined('UKGRID_HTML_ERRORS')) {
            define('UKGRID_HTML_ERRORS', true);
        }
        ini_set('display_errors', '0');
        ob_start();
        register_shutdown_function(function () {
            $err = error_get_last();
            $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if ($err === null || !in_array($err['type'], $fatal, true)) {
                if (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                return;
            }
            $where = basename((string) $err['file']) . ':' . $err['line'];
            $line = 'PHP fatal error on ' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? '?')) . ': ' . $err['message'] . ' at ' . $where;
            if (function_exists('ukgrid_file_log')) {
                ukgrid_file_log($line);
            } else {
                error_log('UK Grid: Live+ ' . $line);
            }
            // Throw away anything half-built, so the error page isn't
            // glued onto the end of a broken one.
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            ukgrid_send_error_page(500, '<p>The details have been written to <code>includes/refresh.log</code> (problem in <code>'
                . htmlspecialchars($where, ENT_QUOTES, 'UTF-8') . '</code>).</p>');
        });
    }
}
