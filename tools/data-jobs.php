<?php
/**
 * tools/data-jobs.php - the state of the scheduler-only jobs added for the
 * country pages: yearly history (ANNUAL), cross-border flows (FLOWS) and
 * daily summaries (DAILY). Shows what each source last returned (so a
 * changed Eurostat or EIA format shows up here first), and can run one
 * area or border now. Password-protected like every page in tools/.
 */

require __DIR__ . '/../includes/error_page.php';
ukgrid_html_errors();
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/refresh.php';
require __DIR__ . '/_auth.php';

$config = ukgrid_load_config();
ukgrid_tools_require_login($config);
$pdo = ukgrid_db();
ukgrid_flows_annual_ensure_tables($pdo);
ukgrid_area_ensure_tables($pdo);
@set_time_limit(90);
$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };

$note = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!empty($_POST['annual'])) {
        $r = ukgrid_ingest_annual($pdo, $config, 30, strtoupper((string) $_POST['annual']));
        $note = 'Yearly history for ' . $_POST['annual'] . ': ' . $r['rows'] . ' figures saved' . ($r['errors'] ? ' - ' . implode('; ', $r['errors']) : '') . '.';
    } elseif (!empty($_POST['border'])) {
        $r = ukgrid_ingest_flows($pdo, $config, 20, (string) $_POST['border']);
        $note = 'Border ' . $_POST['border'] . ': ' . $r['rows'] . ' readings saved' . ($r['errors'] ? ' - ' . implode('; ', $r['errors']) : '') . '.';
    } elseif (!empty($_POST['daily'])) {
        $r = ukgrid_compute_daily($pdo, $config, 60);
        $note = 'Daily summaries: ' . $r['rows'] . ' days saved' . ($r['errors'] ? ' - ' . implode('; ', $r['errors']) : '') . '.';
    }
}

$areas = ukgrid_areas($config);
$sources = ukgrid_annual_sources();
$annualState = ukgrid_fetch_state_get($pdo, 'ANNUAL');
$flowState = ukgrid_fetch_state_get($pdo, 'FLOWS');
$yearsSt = $pdo->query('SELECT area, MIN(year) a, MAX(year) b, COUNT(DISTINCT year) n FROM annual_mix GROUP BY area');
$yearInfo = [];
foreach ($yearsSt->fetchAll() as $r) {
    $yearInfo[$r['area']] = $r;
}
$daysSt = $pdo->query('SELECT area, MIN(day) a, MAX(day) b, SUM(gen_mwh > 0) n FROM daily_summary GROUP BY area');
$dayInfo = [];
foreach ($daysSt->fetchAll() as $r) {
    $dayInfo[$r['area']] = $r;
}

$form = function (string $name, string $value, string $label) use ($h) {
    return '<form method="post" style="display:inline"><input type="hidden" name="' . $h($name) . '" value="' . $h($value) . '"><button type="submit" style="margin:0;padding:.2rem .6rem;font-size:.85rem">' . $h($label) . '</button></form>';
};

$rows = '';
foreach ($areas as $code => $a) {
    $s = $annualState[$code] ?? null;
    $y = $yearInfo[$code] ?? null;
    $d = $dayInfo[$code] ?? null;
    $rows .= '<tr><td><strong>' . $h($code) . '</strong> ' . $h($a['name']) . '</td>'
        . '<td>' . ($d ? $h($d['n'] . ' days, ' . $d['a'] . ' to ' . $d['b']) : 'none yet') . '</td>'
        . '<td>' . $h($sources[$code]['credit'] ?? '-') . '</td>'
        . '<td>' . ($y ? $h($y['n'] . ' years, ' . $y['a'] . '-' . $y['b']) : 'none yet') . '</td>'
        . '<td>' . ($s ? $h($s['status'] . ' at ' . $s['fetched_at'] . ' UTC: ' . $s['message']) : 'not run yet') . '</td>'
        . '<td>' . (isset($sources[$code]) ? $form('annual', $code, 'Fetch now') : '') . '</td></tr>';
}
$flowRows = '';
foreach (ukgrid_flow_borders($config) as [$a, $b]) {
    $k = $a . '-' . $b;
    $s = $flowState[$k] ?? null;
    $flowRows .= '<tr><td>' . $h($k) . '</td><td>' . ($s ? $h($s['status'] . ' at ' . $s['fetched_at'] . ' UTC' . ($s['message'] ? ': ' . $s['message'] : '')) : 'not run yet') . '</td><td>' . $form('border', $k, 'Fetch now') . '</td></tr>';
}

$table = '<div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:.85rem" border="1" cellpadding="4">';
$body = '<h1>Data jobs</h1>'
    . ($note ? '<p><strong>' . $h($note) . '</strong></p>' : '')
    . '<p>The scheduler (GitHub Actions or Docker, through cron/run-due.php) runs these a little at a time. Use the buttons to run one now.</p>'
    . '<h2>Daily summaries and yearly history</h2><p>' . $form('daily', '1', 'Work out daily summaries now') . '</p>'
    . $table . '<tr><th>Area</th><th>Daily summaries</th><th>Yearly source</th><th>Years stored</th><th>Last yearly fetch</th><th></th></tr>' . $rows . '</table></div>'
    . '<h2>Cross-border flows (ENTSO-E)</h2>'
    . $table . '<tr><th>Border</th><th>Last fetch</th><th></th></tr>' . $flowRows . '</table></div>';
ukgrid_tools_page('Data jobs', str_replace('max-width:34rem', 'max-width:none', $body));
