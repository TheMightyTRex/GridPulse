<?php
/**
 * tools/battery-units.php - lists the Balancing Mechanism units counted as
 * batteries (see includes/battery.php), and why each one matched, so the
 * list can be checked. Password-protected like every page in tools/.
 * Add ?refresh=1 to re-read Elexon's BM unit register now.
 *
 * To correct the list, add to includes/config.php:
 *   'battery_units' => ['include' => ['E_EXAMPLE-1'], 'exclude' => ['T_NOTABATTERY-1']],
 */

require __DIR__ . '/../includes/error_page.php';
ukgrid_html_errors(); // errors/500.html rather than a blank 500 if anything breaks
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/ingest.php';
require __DIR__ . '/_auth.php';

$config = ukgrid_load_config();
ukgrid_tools_require_login($config);

if (!empty($_GET['refresh'])) {
    @unlink(ukgrid_battery_units_path());
}
$err = null;
$units = ukgrid_battery_units($config, 20, $err);
$cache = @json_decode((string) @file_get_contents(ukgrid_battery_units_path()), true);
$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

uasort($units, fn ($a, $b) => ($b['gen_mw'] ?? 0) <=> ($a['gen_mw'] ?? 0));
$gen = array_sum(array_column($units, 'gen_mw'));
$rows = '';
foreach ($units as $id => $u) {
    $rows .= '<tr><td>' . $h($id) . '</td><td>' . $h($u['ng']) . '</td><td>' . $h($u['name']) . '</td><td>' . $h($u['party'])
        . '</td><td style="text-align:right">' . $h(number_format((float) $u['gen_mw'], 1)) . '</td><td style="text-align:right">' . $h(number_format((float) $u['dem_mw'], 1))
        . '</td><td>' . $h($u['why']) . '</td></tr>';
}
$body = '<h1>Battery units</h1>'
    . ($err ? '<p><strong>' . $h($err) . '</strong></p>' : '')
    . '<p>' . count($units) . ' units counted as batteries, ' . $h(number_format($gen / 1000, 2)) . 'GW of export capacity in total'
    . (is_array($cache) ? ', from ' . $h($cache['register_size'] ?? '?') . ' units in Elexon\'s register, read ' . $h(gmdate('j M Y H:i', (int) ($cache['fetched_at'] ?? 0))) . ' UTC' : '')
    . '. <a href="?refresh=1">Re-read the register now</a>.</p>'
    . '<div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:.85rem" border="1" cellpadding="4">'
    . '<tr><th>Elexon ID</th><th>National Grid ID</th><th>Name</th><th>Lead party</th><th>Export MW</th><th>Import MW</th><th>Why</th></tr>'
    . $rows . '</table></div>';
ukgrid_tools_page('Battery units', str_replace('max-width:34rem', 'max-width:none', $body));
