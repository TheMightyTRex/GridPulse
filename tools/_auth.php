<?php
/**
 * tools/_auth.php - login gate for everything in tools/.
 *
 * WHY THIS EXISTS: tools/ used to be protected by HTTP Basic Auth in
 * tools/.htaccess, which needs the ABSOLUTE server path to a .htpasswd file.
 * That path can't be known in advance, so the shipped .htaccess held a
 * placeholder ("/REPLACE-WITH-ABSOLUTE-SERVER-PATH-TO/...") that had to be
 * hand-edited on the server. Every time the whole site folder was uploaded
 * again, that edited .htaccess was overwritten by the placeholder (and a
 * clean re-upload also has no .htpasswd, since it's gitignored). Apache
 * then can't open the password file and answers every request to tools/
 * with a generic "500 Internal Server Error ... misconfiguration" page,
 * before PHP even runs.
 *
 * Now the password is checked here, in PHP, against a hash kept in
 * includes/config.php ('tools_password_hash'). config.php lives only on the
 * server and is never part of a deploy upload, so a re-upload can't break
 * or remove the protection, and there's no server path to fill in.
 *
 * Fails closed: until 'tools_password_hash' is set, the tool doesn't run.
 * This page instead offers a form that turns a password you choose into a
 * hash to paste into config.php. Generating a hash grants no access and
 * stores nothing.
 *
 * Include this after includes/db.php and BEFORE any output:
 *   require __DIR__ . '/_auth.php';
 *   ukgrid_tools_require_login(ukgrid_load_config());
 */

function ukgrid_tools_page(string $title, string $bodyHtml): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' - UK Grid: Live+ tools</title>'
        . '<style>body{font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f5f6f8;color:#1a1d23;margin:0;padding:2rem 1rem}'
        . 'main{max-width:34rem;margin:0 auto;background:#fff;border:1px solid #dde1e7;border-radius:10px;padding:1.5rem}'
        . 'h1{font-size:1.25rem;margin-top:0}label{display:block;font-weight:600;margin:.75rem 0 .25rem}'
        . 'input[type=password]{width:100%;box-sizing:border-box;padding:.55rem;border:1px solid #c5cad3;border-radius:6px;font:inherit}'
        . 'button{margin-top:1rem;padding:.55rem 1rem;border:0;border-radius:6px;background:#0a6e5c;color:#fff;font:inherit;font-weight:600;cursor:pointer}'
        . 'code,pre{background:#eceef2;border-radius:4px;padding:.1rem .3rem;font-size:.9rem}pre{padding:.75rem;white-space:pre-wrap;word-break:break-all}'
        . '.err{color:#be382b;font-weight:600}.muted{color:#5b6270;font-size:.9rem}</style></head><body><main>'
        . $bodyHtml . '</main></body></html>';
}

function ukgrid_tools_require_login(array $config): void
{
    // Never cache or index anything under tools/.
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');

    $hash = (string) ($config['tools_password_hash'] ?? '');
    $self = strtok($_SERVER['REQUEST_URI'] ?? 'full-refresh.php', '?');
    $h = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };

    // --- Not set up yet: offer to generate a hash, run nothing. -----------
    if ($hash === '') {
        $generated = '';
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['new_password'])) {
            $pw = (string) $_POST['new_password'];
            if (strlen($pw) < 10) {
                $error = 'Please use at least 10 characters.';
            } else {
                $generated = password_hash($pw, PASSWORD_DEFAULT);
            }
        }
        $body = '<h1>Set a password for the tools page</h1>'
            . '<p>This page is locked until a password is set in <code>includes/config.php</code> on your server. Choose a password below and press the button: you\'ll get a line to paste into that file.</p>';
        if ($generated !== '') {
            $body .= '<p><strong>Add this line</strong> to <code>includes/config.php</code>, inside the main <code>return [ ... ];</code> array (for example just above the <code>\'db\' =&gt; [</code> line), then save the file and reload this page:</p>'
                . '<pre>\'tools_password_hash\' => \'' . $h($generated) . '\',</pre>'
                . '<p class="muted">This is a one-way hash, not your password. Your password itself isn\'t stored anywhere. Since <code>config.php</code> is never part of a site upload, you only need to do this once.</p>';
        } else {
            if ($error !== '') {
                $body .= '<p class="err">' . $h($error) . '</p>';
            }
            $body .= '<form method="post" action="' . $h($self) . '" autocomplete="off">'
                . '<label for="np">New password (10+ characters)</label><input type="password" id="np" name="new_password" required minlength="10">'
                . '<button type="submit">Generate config line</button></form>';
        }
        http_response_code(503);
        ukgrid_tools_page('Set up', $body);
        exit;
    }

    // --- Session-based login. ---------------------------------------------
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $cookiePath = rtrim(dirname($self), '/') . '/';
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(['lifetime' => 0, 'path' => $cookiePath, 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    } else {
        session_set_cookie_params(0, $cookiePath . '; samesite=Strict', '', $secure, true);
    }
    session_name('ukgl_tools');
    session_start();

    if (isset($_GET['logout'])) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . $self);
        exit;
    }

    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['password'])) {
        if (password_verify((string) $_POST['password'], $hash)) {
            session_regenerate_id(true);
            $_SESSION['ukgl_tools_ok'] = true;
            header('Location: ' . $self); // post/redirect/get, so a reload doesn't resubmit
            exit;
        }
        sleep(1); // slows down password guessing
        $error = 'That password isn\'t right.';
    }

    if (!empty($_SESSION['ukgl_tools_ok'])) {
        // Logged in. Release the session lock so this page's own parallel
        // AJAX calls don't queue behind each other.
        session_write_close();
        return;
    }

    // Not logged in. The page's AJAX calls get a JSON 401 rather than an
    // HTML form they can't use.
    if (isset($_GET['ajax'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'not logged in - reload the page and log in again']);
        exit;
    }
    http_response_code(401);
    $body = '<h1>UK Grid: Live+ - internal tools</h1>'
        . ($error !== '' ? '<p class="err">' . $h($error) . '</p>' : '')
        . '<form method="post" action="' . $h($self) . '">'
        . '<label for="pw">Password</label><input type="password" id="pw" name="password" required autofocus autocomplete="current-password">'
        . '<button type="submit">Log in</button></form>'
        . '<p class="muted">Forgotten it? Delete the <code>tools_password_hash</code> line from <code>includes/config.php</code> on your server and reload this page to set a new one.</p>';
    ukgrid_tools_page('Log in', $body);
    exit;
}
