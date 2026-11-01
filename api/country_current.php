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
 *     "mix_ts": "2026-08-10T10:00:00Z" // the one timestamp every mix_mw value was read at (null if none)
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

// This page's own country first. Without a cron job, the shared on-demand
// refresh fetches one ENTSO-E country at a time in rotation, so a country
// page could show readings many hours old (Ireland's were 16 hours old on
// 29 October 2026). If this country's newest reading is more than 90
// minutes old and it has not been tried in the last 20 minutes, fetch just
// this country now (lock-guarded, short timeouts) before reading.
if (!empty($config['refresh']['on_demand'])) {
    try {
        $ownLatest = ukgrid_entsoe_country_latest_ts($pdo, $countryCode);
        $attempts = ukgrid_entsoe_attempts_read();
        $lastTry = (int) ($attempts[$countryCode] ?? 0);
        if ($ownLatest < time() - 90 * 60 && $lastTry < time() - 20 * 60) {
            $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $lockName = 'ukgrid_refresh_entsoe_' . $countryCode;
            $lock->execute([$lockName]);
            if ((int) $lock->fetchColumn() === 1) {
                try {
                    ukgrid_file_log('ENTSOE: ' . $countryCode . ' page loaded with readings over 90 minutes old - fetching this country now');
                    ukgrid_ingest_entsoe($pdo, $config, 6, 0, 1, $countryCode);
                } finally {
                    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
                }
            }
        }
    } catch (Throwable $e) {
        ukgrid_file_log('ENTSOE: own-country refresh for ' . $countryCode . ' failed - ' . $e->getMessage());
    }
}

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
        'mix_ts' => null,
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
$mixTs = null;
if (in_array('generation', $fetchKinds, true)) {
    // One coherent timestamp for the whole mix. This used to look up each
    // psrType independently as "nearest reading within +/- the staleness
    // window" (4 hours by default), so a single donut/table could combine,
    // say, solar from 11:45 with nuclear from 09:00 and gas from a
    // different hour again - a mix that never existed at any one moment,
    // and one that shifted around between page loads as different fuel
    // types' publications landed. Now: take the latest timestamp that has
    // readings for at least three-quarters of the generation types seen
    // in the window (so a half-published latest hour, with only a couple
    // of types in yet, isn't used - but one consistently-late type can't
    // hold the whole mix hours behind either), and read every type at
    // that one timestamp. A type missing there falls back to its latest
    // reading at or before it - never a later one, never outside the
    // window. The chosen timestamp is returned as mix_ts, and the page
    // shows it in the donut caption.
    $genMaxStmt = $pdo->prepare('SELECT MAX(ts) FROM readings_entsoe_generation WHERE country_code = :cc');
    $genMaxStmt->execute(['cc' => $countryCode]);
    $genLatest = $genMaxStmt->fetchColumn();
    if ($genLatest) {
        $covStmt = $pdo->prepare(
            'SELECT ts, COUNT(*) AS n FROM readings_entsoe_generation
             WHERE country_code = :cc AND ts BETWEEN DATE_SUB(:ts1, INTERVAL :w1 MINUTE) AND :ts2
             GROUP BY ts ORDER BY ts DESC'
        );
        $covStmt->execute(['cc' => $countryCode, 'ts1' => $genLatest, 'w1' => $stalenessMinutes, 'ts2' => $genLatest]);
        $coverage = $covStmt->fetchAll();
        $maxN = 0;
        foreach ($coverage as $c) {
            $maxN = max($maxN, (int) $c['n']);
        }
        $needN = max(1, (int) ceil($maxN * 0.75)); // "complete enough" - see above
        foreach ($coverage as $c) { // newest first
            if ((int) $c['n'] >= $needN) {
                $mixTs = $c['ts'];
                break;
            }
        }
    }
    if ($mixTs !== null) {
        $atStmt = $pdo->prepare('SELECT psr_type, mw FROM readings_entsoe_generation WHERE country_code = :cc AND ts = :ts');
        $atStmt->execute(['cc' => $countryCode, 'ts' => $mixTs]);
        foreach ($atStmt->fetchAll() as $row) {
            $mix[$row['psr_type']] = (float) $row['mw'];
        }
        $psrStmt = $pdo->prepare('SELECT DISTINCT psr_type FROM readings_entsoe_generation WHERE country_code = :cc AND ts BETWEEN DATE_SUB(:ts1, INTERVAL :w1 MINUTE) AND :ts2');
        $psrStmt->execute(['cc' => $countryCode, 'ts1' => $mixTs, 'w1' => $stalenessMinutes, 'ts2' => $mixTs]);
        $prevStmt = $pdo->prepare(
            'SELECT mw FROM readings_entsoe_generation
             WHERE country_code = :cc AND psr_type = :psr AND ts <= :ts1 AND ts >= DATE_SUB(:ts2, INTERVAL :w1 MINUTE)
             ORDER BY ts DESC LIMIT 1'
        );
        foreach ($psrStmt->fetchAll(PDO::FETCH_COLUMN) as $psr) {
            if (array_key_exists($psr, $mix)) {
                continue;
            }
            $prevStmt->execute(['cc' => $countryCode, 'psr' => $psr, 'ts1' => $mixTs, 'ts2' => $mixTs, 'w1' => $stalenessMinutes]);
            $v = $prevStmt->fetchColumn();
            if ($v !== false && $v !== null) {
                $mix[$psr] = (float) $v;
            }
        }
    }
    if (!empty($mix)) {
        // Same readings the mix table/donut count - positive values only
        // (a TSO occasionally publishes a small negative for a type), so the
        // headline generation figure equals the donut's total.
        $generationMw = array_sum(array_filter($mix, function ($v) { return $v >= 0.5; }));
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
    // The single timestamp the mix above was read at (see the comment
    // where $mixTs is chosen) - can be earlier than "ts" when demand or
    // price is fresher than the latest complete set of generation types.
    'mix_ts' => $mixTs !== null ? gmdate('Y-m-d\TH:i:s\Z', strtotime($mixTs)) : null,
]);
