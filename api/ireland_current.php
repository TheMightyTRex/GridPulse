<?php
/**
 * GET api/ireland_current.php
 *
 * Latest available all-island (Republic of Ireland + Northern Ireland)
 * snapshot: demand, total generation, wind generation, net interconnection
 * with GB, and the SEM imbalance price. Sources: EirGrid's Smart Grid
 * Dashboard (demand/generation/wind/interconnection/emissions - see
 * includes/ingest.php's ukgrid_ingest_eirgrid()) and SEMO's public Reports
 * API (semo_imbalance_price_eur_mwh - see ukgrid_ingest_semo()).
 *
 * The SEM imbalance price is deliberately kept on its OWN staleness check
 * (semo_staleness_minutes, default tighter than the main
 * $stalenessMinutes) rather than being gated by $latestTs/EirGrid's own
 * freshness: it's an independent source that can be live even if EirGrid's
 * feed has a gap, or vice versa, and pages/ireland.html's own SEM price
 * card already handles a null value as "not available" separately from
 * the rest of this response.
 *
 * "mix" is the Ireland page's generation mix right now, all from EirGrid
 * and all read at one timestamp (mix.ts): total generation, wind, solar
 * and net interconnection. EirGrid does not publish gas or other fuels
 * separately in real time; the page shows total minus wind and solar as
 * one calculated "gas and other sources" figure. (ENTSO-E's breakdown for
 * Ireland only carries wind, hydro and peat in near real time.)
 *
 * "fuel_24h" is EirGrid's own fuel mix for the 24 hours to fuel_24h.ts, in
 * MWh: coal, gas, net_import, other_fossil, renew.
 *
 * Response shape:
 *   {
 *     "ok": true,
 *     "live": true,
 *     "stale": false,
 *     "ts": "2026-08-09T13:00:00Z",
 *     "demand_mw": 4830.0,
 *     "generation_mw": 4610.0,
 *     "wind_mw": 2110.0,
 *     "interconnection_mw": 220.0,
 *     "emissions_gco2": 178,
 *     "semo_imbalance_price_eur_mwh": 24.26,
 *     "semo_ts": "2026-09-20T11:50:00Z",
 *     "solar_mw": 301.0,
 *     "mix": { "ts": "...", "total_mw": 5801, "wind_mw": 3698, "solar_mw": 301, "interconnection_mw": -270 },
 *     "fuel_24h": { "ts": "...", "coal": 0, "gas": 44417.37, "net_import": 4422.43, "other_fossil": 3125.76, "renew": 64739.54 }
 *   }
 *
 * If there's no data yet, "ok" stays true but "live" is false and every
 * numeric field is null, matching api/current.php's convention - the
 * frontend shows placeholder data in that case rather than a blank page.
 * semo_imbalance_price_eur_mwh/semo_ts are independently null (with "live"
 * left true) if just the SEMO half has no data yet or has gone stale,
 * since EirGrid's figures can still be perfectly good at the same time.
 */

require __DIR__ . '/_bootstrap.php';

$pdo = ukgrid_db();
$config = ukgrid_load_config();
$stalenessMinutes = (int) ($config['staleness_minutes'] ?? 90);

$latestTs = $pdo->query('SELECT MAX(ts) FROM readings_ie_demand')->fetchColumn();

// Independent of $latestTs/EirGrid above - see this file's docblock for why
// the SEM imbalance price gets its own staleness check rather than being
// gated by EirGrid's own freshness.
$semoStalenessMinutes = (int) ($config['semo_staleness_minutes'] ?? 30);
$semoLatestTs = $pdo->query('SELECT MAX(ts) FROM readings_ie_semo_imbalance')->fetchColumn();
$semoPrice = null;
$semoTsOut = null;
if ($semoLatestTs && strtotime($semoLatestTs) >= time() - $semoStalenessMinutes * 60) {
    $semoStmt = $pdo->prepare('SELECT imbalance_price_eur_mwh FROM readings_ie_semo_imbalance WHERE ts = :ts');
    $semoStmt->execute(['ts' => $semoLatestTs]);
    $semoPriceRaw = $semoStmt->fetchColumn();
    if ($semoPriceRaw !== false) {
        $semoPrice = (float) $semoPriceRaw;
        $semoTsOut = gmdate('Y-m-d\TH:i:s\Z', strtotime($semoLatestTs));
    }
}

if (!$latestTs) {
    echo json_encode([
        'ok' => true,
        'live' => false,
        'stale' => true,
        'ts' => null,
        'demand_mw' => null,
        'generation_mw' => null,
        'wind_mw' => null,
        'interconnection_mw' => null,
        'emissions_gco2' => null,
        'semo_imbalance_price_eur_mwh' => $semoPrice,
        'semo_ts' => $semoTsOut,
        'solar_mw' => null,
        'mix' => null,
        'fuel_24h' => null,
    ]);
    exit;
}

$isStale = (strtotime($latestTs) < time() - $stalenessMinutes * 60);

$demandStmt = $pdo->prepare('SELECT mw FROM readings_ie_demand WHERE ts = :ts');
$demandStmt->execute(['ts' => $latestTs]);
$demandMw = $demandStmt->fetchColumn();

// See the matching comment in api/current.php's ukgrid_nearest() - PDO's
// MySQL driver uses real prepared statements, which don't allow the same
// named placeholder twice in one query. Every repeated value below gets its
// own placeholder name bound to that same value.
function ukgrid_ie_nearest(PDO $pdo, string $table, string $valueCol, string $ts, int $windowMinutes, string $extraWhere = '', array $extraParams = [])
{
    $where = 'ts BETWEEN DATE_SUB(:ts1, INTERVAL :window1 MINUTE) AND DATE_ADD(:ts2, INTERVAL :window2 MINUTE)';
    if ($extraWhere !== '') {
        $where .= ' AND ' . $extraWhere;
    }
    $stmt = $pdo->prepare("SELECT {$valueCol} AS v FROM {$table} WHERE {$where} ORDER BY ABS(TIMESTAMPDIFF(SECOND, ts, :ts3)) ASC LIMIT 1");
    $stmt->execute(array_merge(['ts1' => $ts, 'window1' => $windowMinutes, 'ts2' => $ts, 'window2' => $windowMinutes, 'ts3' => $ts], $extraParams));
    $row = $stmt->fetch();
    return $row ? $row['v'] : null;
}

$genTotal = ukgrid_ie_nearest($pdo, 'readings_ie_generation', 'mw', $latestTs, $stalenessMinutes, 'category = :cat', ['cat' => 'TOTAL']);
$wind = ukgrid_ie_nearest($pdo, 'readings_ie_generation', 'mw', $latestTs, $stalenessMinutes, 'category = :cat', ['cat' => 'WIND']);
$interconnection = ukgrid_ie_nearest($pdo, 'readings_ie_generation', 'mw', $latestTs, $stalenessMinutes, 'category = :cat', ['cat' => 'INTERCONNECTION']);
$co2 = ukgrid_ie_nearest($pdo, 'readings_ie_co2', 'gco2_per_kwh', $latestTs, $stalenessMinutes);

$solar = ukgrid_ie_nearest($pdo, 'readings_ie_generation', 'mw', $latestTs, $stalenessMinutes, 'category = :cat', ['cat' => 'SOLAR']);

// The mix: the latest timestamp with both total generation and wind, and
// every reading at that one timestamp, so the figures belong together.
$mix = null;
$mixTsStmt = $pdo->prepare(
    'SELECT t.ts FROM readings_ie_generation t
     JOIN readings_ie_generation w ON w.ts = t.ts AND w.category = "WIND"
     WHERE t.category = "TOTAL" AND t.ts >= DATE_SUB(:ts1, INTERVAL :w1 MINUTE)
     ORDER BY t.ts DESC LIMIT 1'
);
$mixTsStmt->execute(['ts1' => $latestTs, 'w1' => $stalenessMinutes]);
$mixTs = $mixTsStmt->fetchColumn();
if ($mixTs) {
    $atStmt = $pdo->prepare('SELECT category, mw FROM readings_ie_generation WHERE ts = :ts');
    $atStmt->execute(['ts' => $mixTs]);
    $at = [];
    foreach ($atStmt->fetchAll() as $row) {
        $at[$row['category']] = (float) $row['mw'];
    }
    $mix = [
        'ts' => gmdate('Y-m-d\TH:i:s\Z', strtotime($mixTs)),
        'total_mw' => $at['TOTAL'] ?? null,
        'wind_mw' => $at['WIND'] ?? null,
        // EirGrid reports a small negative for solar at night
        'solar_mw' => isset($at['SOLAR']) ? max(0.0, $at['SOLAR']) : null,
        'interconnection_mw' => $at['INTERCONNECTION'] ?? null,
    ];
}

// EirGrid's 24-hour fuel mix - the newest set, if the table exists yet
// (ukgrid_ingest_eirgrid() creates it).
$fuel24 = null;
try {
    $fts = $pdo->query('SELECT MAX(ts) FROM readings_ie_fuelmix')->fetchColumn();
    if ($fts && strtotime($fts) >= time() - 6 * 3600) {
        $fStmt = $pdo->prepare('SELECT fuel, mwh FROM readings_ie_fuelmix WHERE ts = :ts');
        $fStmt->execute(['ts' => $fts]);
        $fuel24 = ['ts' => gmdate('Y-m-d\TH:i:s\Z', strtotime($fts))];
        foreach ($fStmt->fetchAll() as $row) {
            $fuel24[strtolower($row['fuel'])] = (float) $row['mwh'];
        }
    }
} catch (Throwable $e) {
    $fuel24 = null;
}

echo json_encode([
    'ok' => true,
    'live' => true,
    'stale' => $isStale,
    'ts' => gmdate('Y-m-d\TH:i:s\Z', strtotime($latestTs)),
    'demand_mw' => $demandMw !== false ? (float) $demandMw : null,
    'generation_mw' => $genTotal !== null ? (float) $genTotal : null,
    'wind_mw' => $wind !== null ? (float) $wind : null,
    'interconnection_mw' => $interconnection !== null ? (float) $interconnection : null,
    'emissions_gco2' => $co2 !== null ? (float) $co2 : null,
    'semo_imbalance_price_eur_mwh' => $semoPrice,
    'semo_ts' => $semoTsOut,
    'solar_mw' => $solar !== null ? max(0.0, (float) $solar) : null,
    'mix' => $mix,
    'fuel_24h' => $fuel24,
]);
