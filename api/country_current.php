<?php
/**
 * GET api/country_current.php?country=FR
 *
 * Generic latest-snapshot endpoint for every ENTSO-E-sourced country
 * configured in includes/config.php's entsoe_countries (ships with IE, FR,
 * NL, BE, NO, DK - see includes/config.php.example). One endpoint handles
 * all of them, rather than a dedicated file per country, since the query
 * shape is identical - only which of demand/generation/price actually got
 * fetched (a country's own 'fetch' list) differs.
 *
 * ?country= must be one of the keys in entsoe_countries. Unknown or
 * unconfigured codes get a 400 via ukgrid_json_error().
 *
 * Response shape:
 *   {
 *     "ok": true,
 *     "live": true,
 *     "stale": false,
 *     "ts": "2026-08-10T11:00:00Z",
 *     "demand_mw": 68000.0,        // null if this country's 'fetch' list omits "demand"
 *                                  // (currently just Ireland - EirGrid already covers
 *                                  // its demand more directly, see api/ireland_current.php)
 *     "generation_mw": 71500.0,    // null if 'fetch' omits "generation"
 *     "price_eur_mwh": 84.10,      // null if 'fetch' omits "price"
 *     "mix_mw": { "B04": 24500.0, "B14": 31000.0, ... }   // psrType => MW, empty if no generation data
 *   }
 *
 * If there's no data yet for this country (including a fresh install with
 * no entsoe_api_token configured), "ok" stays true but "live" is false,
 * matching every other *_current.php endpoint's convention.
 *
 * "stale" uses entsoe_staleness_minutes (default 240 = 4 hours) - ENTSO-E
 * TSOs typically publish actual load/generation within 1-3 hours, longer
 * than GB/Ireland's own ~15-30 minute sources but far tighter than the
 * EIA's ~1-day lag, hence its own threshold.
 */

require __DIR__ . '/_bootstrap.php';

$countryCode = strtoupper((string) ($_GET['country'] ?? ''));
$config = ukgrid_load_config();
$countries = $config['sources']['entsoe_countries'] ?? [];

if ($countryCode === '' || !isset($countries[$countryCode])) {
    ukgrid_json_error('bad_country', 'country must be one of: ' . implode(', ', array_keys($countries)));
}

$fetchKinds = $countries[$countryCode]['fetch'] ?? [];
$pdo = ukgrid_db();
$stalenessMinutes = (int) ($config['entsoe_staleness_minutes'] ?? 240);

// Each of demand/generation/price can have a slightly different latest
// timestamp (price updates once a day, demand/generation every 1-3 hours) -
// take the most recent across whichever this country actually fetches, then
// do a nearest-within-window lookup against each table independently, the
// same pattern as api/ireland_current.php's ukgrid_ie_nearest() and
// api/usa_current.php's ukgrid_us_nearest().
$latestCandidates = [];
if (in_array('demand', $fetchKinds, true)) {
    $t = $pdo->prepare('SELECT MAX(ts) FROM readings_entsoe_demand WHERE country_code = :cc');
    $t->execute(['cc' => $countryCode]);
    $v = $t->fetchColumn();
    if ($v) { $latestCandidates[] = $v; }
}
if (in_array('generation', $fetchKinds, true)) {
    $t = $pdo->prepare('SELECT MAX(ts) FROM readings_entsoe_generation WHERE country_code = :cc');
    $t->execute(['cc' => $countryCode]);
    $v = $t->fetchColumn();
    if ($v) { $latestCandidates[] = $v; }
}
if (in_array('price', $fetchKinds, true)) {
    $t = $pdo->prepare('SELECT MAX(ts) FROM readings_entsoe_price WHERE country_code = :cc');
    $t->execute(['cc' => $countryCode]);
    $v = $t->fetchColumn();
    if ($v) { $latestCandidates[] = $v; }
}

if (empty($latestCandidates)) {
    echo json_encode([
        'ok' => true,
        'live' => false,
        'stale' => true,
        'ts' => null,
        'demand_mw' => null,
        'generation_mw' => null,
        'price_eur_mwh' => null,
        'mix_mw' => new stdClass(),
    ]);
    exit;
}
// Prefer the most recent candidate that's already happened over a blind
// max() across all three tables. Day-ahead price is fetched deliberately
// a little ahead of "now" (see ukgrid_ingest_entsoe()'s periodEnd comment
// in includes/ingest.php - ENTSO-E publishes day-ahead prices in advance,
// by design), so readings_entsoe_price's own MAX(ts) can genuinely be
// later than the real current moment. A blind max() then picks that
// future price timestamp as "ts" for the whole snapshot - confirmed live
// as the cause of "Data as of" showing a time that hadn't happened yet.
// Demand/generation are never published ahead of time, so this only ever
// excludes price candidates, and only when they're actually in the future.
$nowUtc = gmdate('Y-m-d H:i:s');
$pastCandidates = array_filter($latestCandidates, function ($ts) use ($nowUtc) {
    return $ts <= $nowUtc;
});
$latestTs = !empty($pastCandidates) ? max($pastCandidates) : max($latestCandidates);
$isStale = (strtotime($latestTs) < time() - $stalenessMinutes * 60);

function ukgrid_entsoe_nearest(PDO $pdo, string $table, string $valueCol, string $countryCode, string $ts, int $windowMinutes, string $extraWhere = '', array $extraParams = [])
{
    $where = 'country_code = :cc AND ts BETWEEN DATE_SUB(:ts1, INTERVAL :window1 MINUTE) AND DATE_ADD(:ts2, INTERVAL :window2 MINUTE)';
    if ($extraWhere !== '') {
        $where .= ' AND ' . $extraWhere;
    }
    $stmt = $pdo->prepare("SELECT {$valueCol} AS v FROM {$table} WHERE {$where} ORDER BY ABS(TIMESTAMPDIFF(SECOND, ts, :ts3)) ASC LIMIT 1");
    $stmt->execute(array_merge(['cc' => $countryCode, 'ts1' => $ts, 'window1' => $windowMinutes, 'ts2' => $ts, 'window2' => $windowMinutes, 'ts3' => $ts], $extraParams));
    $row = $stmt->fetch();
    return $row ? $row['v'] : null;
}

$demandMw = in_array('demand', $fetchKinds, true)
    ? ukgrid_entsoe_nearest($pdo, 'readings_entsoe_demand', 'mw', $countryCode, $latestTs, $stalenessMinutes)
    : null;
$priceEurMwh = in_array('price', $fetchKinds, true)
    ? ukgrid_entsoe_nearest($pdo, 'readings_entsoe_price', 'price_eur_mwh', $countryCode, $latestTs, $stalenessMinutes)
    : null;

$mix = [];
$generationMw = null;
if (in_array('generation', $fetchKinds, true)) {
    $psrStmt = $pdo->prepare('SELECT DISTINCT psr_type FROM readings_entsoe_generation WHERE country_code = :cc AND ts BETWEEN DATE_SUB(:ts1, INTERVAL :w1 MINUTE) AND DATE_ADD(:ts2, INTERVAL :w2 MINUTE)');
    $psrStmt->execute(['cc' => $countryCode, 'ts1' => $latestTs, 'w1' => $stalenessMinutes, 'ts2' => $latestTs, 'w2' => $stalenessMinutes]);
    $psrTypes = $psrStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($psrTypes as $psr) {
        $v = ukgrid_entsoe_nearest($pdo, 'readings_entsoe_generation', 'mw', $countryCode, $latestTs, $stalenessMinutes, 'psr_type = :psr', ['psr' => $psr]);
        if ($v !== null) {
            $mix[$psr] = (float) $v;
        }
    }
    if (!empty($mix)) {
        $generationMw = array_sum($mix);
    }
}

echo json_encode([
    'ok' => true,
    'live' => true,
    'stale' => $isStale,
    'ts' => gmdate('Y-m-d\TH:i:s\Z', strtotime($latestTs)),
    'demand_mw' => $demandMw !== null ? (float) $demandMw : null,
    'generation_mw' => $generationMw,
    'price_eur_mwh' => $priceEurMwh !== null ? (float) $priceEurMwh : null,
    'mix_mw' => empty($mix) ? new stdClass() : $mix,
]);
