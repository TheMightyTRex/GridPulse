<?php
/**
 * GET api/australia_current.php
 *
 * Latest available NEM-wide (National Electricity Market: NSW, QLD, VIC,
 * SA, TAS - NOT Western Australia's separate WEM market) snapshot: demand,
 * a demand-weighted average spot price across the five regions, and
 * generation broken down by fuel technology group. Source: the Open
 * Electricity API - see includes/ingest.php's
 * ukgrid_ingest_openelectricity().
 *
 * generation_mw is the sum of every fueltech group that supplies power to
 * the grid (coal, gas, wind, solar, hydro, distillate, bioenergy,
 * battery_discharging) - battery_charging and pumps (pumped-hydro pumping
 * load) are deliberately excluded from
 * that total (it's a draw, not supply) and returned separately instead, in
 * battery_charging_mw, the same way api/usa_current.php keeps
 * interconnection_mw separate from generation_mw.
 *
 * Response shape:
 *   {
 *     "ok": true,
 *     "live": true,
 *     "stale": false,
 *     "ts": "2026-09-19T15:15:00Z",
 *     "demand_mw": 18785.01,
 *     "generation_mw": 19797.23,
 *     "price_aud_mwh": 57.19,
 *     "battery_charging_mw": 135.42,
 *     "mix_mw": { "coal": 11854.61, "wind": 6897.63, ... }
 *   }
 *
 * If there's no data yet (including a fresh install with no
 * openelectricity_api_key configured), "ok" stays true but "live" is false
 * and every field is null or empty, matching api/usa_current.php's/
 * api/ireland_current.php's convention - the frontend falls back to its
 * own illustrative figures in that case.
 */

require __DIR__ . '/_bootstrap.php';

$pdo = ukgrid_db();
$config = ukgrid_load_config();
$stalenessMinutes = (int) ($config['australia_staleness_minutes'] ?? 30);

$latestTs = $pdo->query('SELECT MAX(ts) FROM readings_au_demand')->fetchColumn();

if (!$latestTs) {
    echo json_encode([
        'ok' => true,
        'live' => false,
        'stale' => true,
        'ts' => null,
        'demand_mw' => null,
        'generation_mw' => null,
        'price_aud_mwh' => null,
        'battery_charging_mw' => null,
        'mix_mw' => new stdClass(),
        'mix_ts' => null,
    ]);
    exit;
}

$isStale = (strtotime($latestTs) < time() - $stalenessMinutes * 60);

$demandStmt = $pdo->prepare('SELECT mw FROM readings_au_demand WHERE ts = :ts');
$demandStmt->execute(['ts' => $latestTs]);
$demandMw = $demandStmt->fetchColumn();

// See the matching comment in api/current.php's ukgrid_nearest() / api/
// usa_current.php's ukgrid_us_nearest() - PDO's MySQL driver uses real
// prepared statements, which don't allow the same named placeholder twice
// in one query, hence the repeated :ts1/:ts2/:ts3-style placeholders below.
function ukgrid_au_nearest_generation(PDO $pdo, string $ts, int $windowMinutes, string $category)
{
    $stmt = $pdo->prepare(
        'SELECT mw AS v FROM readings_au_generation
         WHERE category = :cat
           AND ts BETWEEN DATE_SUB(:ts1, INTERVAL :window1 MINUTE) AND DATE_ADD(:ts2, INTERVAL :window2 MINUTE)
         ORDER BY ABS(TIMESTAMPDIFF(SECOND, ts, :ts3)) ASC LIMIT 1'
    );
    $stmt->execute(['cat' => $category, 'ts1' => $ts, 'window1' => $windowMinutes, 'ts2' => $ts, 'window2' => $windowMinutes, 'ts3' => $ts]);
    $row = $stmt->fetch();
    return $row ? (float) $row['v'] : null;
}

function ukgrid_au_nearest_price(PDO $pdo, string $ts, int $windowMinutes)
{
    $stmt = $pdo->prepare(
        'SELECT price_aud_mwh AS v FROM readings_au_price
         WHERE ts BETWEEN DATE_SUB(:ts1, INTERVAL :window1 MINUTE) AND DATE_ADD(:ts2, INTERVAL :window2 MINUTE)
         ORDER BY ABS(TIMESTAMPDIFF(SECOND, ts, :ts3)) ASC LIMIT 1'
    );
    $stmt->execute(['ts1' => $ts, 'window1' => $windowMinutes, 'ts2' => $ts, 'window2' => $windowMinutes, 'ts3' => $ts]);
    $row = $stmt->fetch();
    return $row ? (float) $row['v'] : null;
}

// The "supply" fueltech groups - see this file's docblock for why
// battery_charging is handled separately, and includes/ingest.php's
// ukgrid_ingest_openelectricity() for why the raw "battery" net rollup
// isn't stored at all.
// 'pumps' is NOT in this list any more: in Open Electricity's (ex-OpenNEM)
// fueltech taxonomy "pumps" is pumped-hydro PUMPING LOAD - a draw, the same
// kind of thing as battery_charging - while pumped hydro's generation is
// already inside "hydro". Summing it into generation_mw (and showing it as
// a "Pumped hydro" generation slice) overstated generation. It's still
// returned in mix_mw so the page can list it as consumption, clearly
// separated from the generation mix (see pages/australia.html).
$generationGroups = ['coal', 'gas', 'wind', 'solar', 'hydro', 'distillate', 'bioenergy', 'battery_discharging'];

// One coherent snapshot for every fueltech group, including the two loads
// (battery_charging, pumps) - see ukgrid_coherent_mix() in _bootstrap.php.
// Each group used to be looked up independently as "nearest within +/- the
// window", so one donut could combine different 5-minute intervals.
$snapshot = ukgrid_coherent_mix($pdo, 'readings_au_generation', 'category', array_merge($generationGroups, ['battery_charging', 'pumps']), $latestTs, $stalenessMinutes);
$mixTs = $snapshot['ts'];
$mix = [];
$generationMw = 0.0;
$anyGeneration = false;
foreach ($generationGroups as $group) {
    if (array_key_exists($group, $snapshot['mix'])) {
        $mix[$group] = $snapshot['mix'][$group];
        if ($mix[$group] >= 0.5) {
            $generationMw += $mix[$group];
        }
        $anyGeneration = true;
    }
}
$batteryChargingMw = $snapshot['mix']['battery_charging'] ?? null;
$pumpsMw = $snapshot['mix']['pumps'] ?? null;
if ($pumpsMw !== null) {
    $mix['pumps'] = $pumpsMw; // consumption - deliberately NOT added to $generationMw above
}
$priceAudMwh = ukgrid_au_nearest_price($pdo, $latestTs, $stalenessMinutes);

echo json_encode([
    'ok' => true,
    'live' => true,
    'stale' => $isStale,
    'ts' => gmdate('Y-m-d\TH:i:s\Z', strtotime($latestTs)),
    'demand_mw' => $demandMw !== false ? (float) $demandMw : null,
    'generation_mw' => $anyGeneration ? $generationMw : null,
    'price_aud_mwh' => $priceAudMwh,
    'battery_charging_mw' => $batteryChargingMw,
    'mix_mw' => empty($mix) ? new stdClass() : $mix,
    'mix_ts' => $mixTs !== null ? gmdate('Y-m-d\TH:i:s\Z', strtotime($mixTs)) : null,
]);
