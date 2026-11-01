<?php
/**
 * GET api/country_series.php?country=FR&metric=demand&range=day
 *
 * Generic bucketed-history endpoint matching api/country_current.php - one
 * file for every ENTSO-E-sourced country rather than one per country.
 *
 * country: any key in includes/config.php's entsoe_countries (IE, FR, NL,
 *          BE, NO, DK as shipped)
 * metric:  demand | generation | price | mix |
 *          renewable | non_renewable | fossil |
 *          wind | solar | hydro | gas | coal | oil | nuclear | biomass
 *          demand/generation/price only valid if that country's own
 *          'fetch' list includes them (e.g. metric=demand&country=IE is
 *          rejected, since Ireland's demand comes from EirGrid instead -
 *          see api/ireland_series.php). Every other metric only needs
 *          'generation' in that country's fetch list (same underlying
 *          readings_entsoe_generation rows the 'generation' case sums,
 *          just grouped or filtered by psr_type instead).
 * range:   same set as api/series.php (3hour | day | week | month | season
 *          | year | 5year | 10year | all)
 *
 * Response shape matches every other *_series.php endpoint:
 *   { "ok": true, "metric": "demand", "range": "day", "unit": "GW",
 *     "points": [ {"t": "2026-08-10T00:00:00Z", "v": 68.3}, ... ] }
 * (metric=price uses unit "EUR/MWh" and isn't divided by 1000.)
 *
 * metric=mix returns a different point shape (renewable/fossil/nuclear/
 * other instead of a single "v"), grouped by ENTSO-E psr_type the same way
 * assets/data.js's summarizeEntsoeMix() groups the live snapshot - see
 * ENTSOE_RENEWABLE_PSR/ENTSOE_FOSSIL_PSR/ENTSOE_NUCLEAR_PSR there, kept in
 * sync with the code lists below. Used by each country page's 24-hour
 * generation-mix stacked-area chart.
 *
 * renewable/non_renewable/fossil and the eight single-fuel metrics mirror
 * api/series.php's own GB metric names 1:1 (grouped/filtered by ENTSO-E
 * psr_type instead of GB's Elexon fuel_type - see ENTSOE_PSR_LABELS in
 * assets/data.js for the full code list), so
 * pages/comparisons.html's "Energy sources" tab can pass the exact same
 * metric string regardless of which country is selected.
 */

require __DIR__ . '/_bootstrap.php';

$countryCode = strtoupper((string) ($_GET['country'] ?? ''));
$metric = $_GET['metric'] ?? '';
$range = $_GET['range'] ?? '';

$config = ukgrid_load_config();
$countries = $config['sources']['entsoe_countries'] ?? [];
if ($countryCode === '' || !isset($countries[$countryCode])) {
    ukgrid_json_error('bad_country', 'country must be one of: ' . implode(', ', array_keys($countries)));
}

$MIX_DERIVED_METRICS = ['mix', 'renewable', 'non_renewable', 'fossil', 'wind', 'solar', 'hydro', 'gas', 'coal', 'oil', 'nuclear', 'biomass'];
$allowedMetrics = array_merge(['demand', 'generation', 'price'], $MIX_DERIVED_METRICS);
if (!in_array($metric, $allowedMetrics, true)) {
    ukgrid_json_error('bad_metric', 'metric must be one of: ' . implode(', ', $allowedMetrics));
}
$fetchKinds = $countries[$countryCode]['fetch'] ?? [];
// mix and everything derived from it ride on the same
// readings_entsoe_generation rows 'generation' itself uses, so they're
// gated on 'generation' being fetched, not on a separate entry that
// doesn't exist in entsoe_countries' own 'fetch' list.
$requiredFetch = in_array($metric, $MIX_DERIVED_METRICS, true) ? 'generation' : $metric;
if (!in_array($requiredFetch, $fetchKinds, true)) {
    ukgrid_json_error('metric_not_fetched', "{$countryCode} doesn't fetch \"{$requiredFetch}\" from ENTSO-E - see entsoe_countries in includes/config.php.example.");
}

$ranges = ukgrid_range_config();
if (!isset($ranges[$range])) {
    ukgrid_json_error('bad_range', 'range must be one of: ' . implode(', ', array_keys($ranges)));
}

$cfg = $ranges[$range];
$bucket = $cfg['stepSeconds'];
if ($range === 'season') {
    $seasonOffset = isset($_GET['season_offset']) ? (int) $_GET['season_offset'] : 0;
    $seasonOffset = max(-10, min(0, $seasonOffset));
    $bounds = ukgrid_uk_season_bounds($seasonOffset);
    $from = gmdate('Y-m-d H:i:s', $bounds['start']);
    $to = gmdate('Y-m-d H:i:s', $bounds['end']);
} else {
    $from = gmdate('Y-m-d H:i:s', time() - $cfg['points'] * $bucket);
    $to = gmdate('Y-m-d H:i:s');
}

$pdo = ukgrid_db();
$params = ['cc' => $countryCode, 'from' => $from, 'to' => $to, 'bucket1' => $bucket, 'bucket2' => $bucket];

switch ($metric) {
    case 'demand':
        $sql = 'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(mw)/1000 AS v
                FROM readings_entsoe_demand WHERE country_code = :cc AND ts BETWEEN :from AND :to
                GROUP BY bucket_ts ORDER BY bucket_ts ASC';
        $unit = 'GW';
        break;
    case 'generation':
        // Two-step aggregation, same pattern as api/series.php's own
        // 'generation' case: readings_entsoe_generation has multiple rows
        // per raw timestamp (one per psr_type), which need SUMMING into a
        // per-timestamp total first (inner query) - only THEN does it make
        // sense to AVERAGE those per-timestamp totals within a bucket
        // (outer query). Doing a single SUM(mw) grouped only by bucket_ts
        // (an earlier version of this) silently double-(or more-)counts
        // the moment a bucket spans more than one raw timestamp - harmless
        // today since every range this endpoint is actually called with
        // (3hour, 15-minute buckets) never spans more than one ENTSO-E
        // reading, but it would quietly inflate totals the moment a wider
        // range (day/week/etc) is wired up for these countries' History
        // section.
        $sql = 'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.total_mw)/1000 AS v
                FROM (
                  SELECT ts, SUM(mw) AS total_mw
                  FROM readings_entsoe_generation
                  WHERE country_code = :cc AND ts BETWEEN :from AND :to
                  GROUP BY ts
                ) t
                GROUP BY bucket_ts ORDER BY bucket_ts ASC';
        $unit = 'GW';
        break;
    case 'price':
        $sql = 'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(price_eur_mwh) AS v
                FROM readings_entsoe_price WHERE country_code = :cc AND ts BETWEEN :from AND :to
                GROUP BY bucket_ts ORDER BY bucket_ts ASC';
        $unit = 'EUR/MWh';
        break;
    case 'mix':
        // Same psr_type groupings as assets/data.js's summarizeEntsoeMix() -
        // keep the two in sync if that list ever changes. Two-step
        // aggregation for the same double-counting reason as 'generation'
        // above: sum each group per raw timestamp first, then average those
        // per-timestamp sums within a bucket.
        $sql = 'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts,
                       AVG(t.renewable_mw)/1000 AS renewable,
                       AVG(t.fossil_mw)/1000 AS fossil,
                       AVG(t.nuclear_mw)/1000 AS nuclear,
                       AVG(t.other_mw)/1000 AS other
                FROM (
                  SELECT ts,
                    SUM(CASE WHEN psr_type IN ("B01","B09","B11","B12","B13","B15","B16","B18","B19") THEN mw ELSE 0 END) AS renewable_mw,
                    SUM(CASE WHEN psr_type IN ("B02","B03","B04","B05","B06","B07","B08") THEN mw ELSE 0 END) AS fossil_mw,
                    SUM(CASE WHEN psr_type = "B14" THEN mw ELSE 0 END) AS nuclear_mw,
                    SUM(CASE WHEN psr_type NOT IN ("B01","B09","B11","B12","B13","B15","B16","B18","B19","B02","B03","B04","B05","B06","B07","B08","B14") THEN mw ELSE 0 END) AS other_mw
                  FROM readings_entsoe_generation
                  WHERE country_code = :cc AND ts BETWEEN :from AND :to
                  GROUP BY ts
                ) t
                GROUP BY bucket_ts ORDER BY bucket_ts ASC';
        $unit = 'GW';
        break;

    // renewable/non_renewable/fossil as single values instead of split
    // several ways - same psr_type sets as the 'mix' case above.
    // non_renewable is the complement of renewable (fossil + nuclear +
    // "other", i.e. total minus renewable) rather than a separately
    // curated list - "other" here includes pumped storage/waste/anything
    // ungrouped, same simplification api/usa_series.php's own
    // non_renewable makes.
    case 'renewable':
    case 'non_renewable':
    case 'fossil':
        $caseExpr = [
            'renewable' => 'psr_type IN ("B01","B09","B11","B12","B13","B15","B16","B18","B19")',
            'non_renewable' => 'psr_type NOT IN ("B01","B09","B11","B12","B13","B15","B16","B18","B19")',
            'fossil' => 'psr_type IN ("B02","B03","B04","B05","B06","B07","B08")',
        ][$metric];
        $sql = "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.v_mw)/1000 AS v
                FROM (
                  SELECT ts, SUM(CASE WHEN $caseExpr THEN mw ELSE 0 END) AS v_mw
                  FROM readings_entsoe_generation
                  WHERE country_code = :cc AND ts BETWEEN :from AND :to
                  GROUP BY ts
                ) t
                GROUP BY bucket_ts ORDER BY bucket_ts ASC";
        $unit = 'GW';
        break;

    // Single-fuel breakdowns, each a finer split of one of the groups
    // above. wind combines onshore (B19) and offshore (B18); hydro
    // combines all three ENTSO-E hydro variants (run-of-river B11,
    // reservoir B12, marine B13); coal combines lignite (B02) and hard
    // coal (B05); oil combines oil (B06) and oil shale (B07) - see
    // ENTSOE_PSR_LABELS in assets/data.js for what each code means.
    case 'wind':
    case 'solar':
    case 'hydro':
    case 'gas':
    case 'coal':
    case 'oil':
    case 'nuclear':
    case 'biomass':
        $psrCodes = [
            'wind' => ['B18', 'B19'],
            'solar' => ['B16'],
            'hydro' => ['B11', 'B12', 'B13'],
            'gas' => ['B04'],
            'coal' => ['B02', 'B05'],
            'oil' => ['B06', 'B07'],
            'nuclear' => ['B14'],
            'biomass' => ['B01'],
        ][$metric];
        $placeholders = implode(',', array_map(fn($c) => $pdo->quote($c), $psrCodes));
        $sql = "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.v_mw)/1000 AS v
                FROM (
                  SELECT ts, SUM(mw) AS v_mw
                  FROM readings_entsoe_generation
                  WHERE psr_type IN ($placeholders) AND country_code = :cc AND ts BETWEEN :from AND :to
                  GROUP BY ts
                ) t
                GROUP BY bucket_ts ORDER BY bucket_ts ASC";
        $unit = 'GW';
        break;

    default:
        $sql = null;
        $unit = '';
}

$rows = [];
if ($sql !== null) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

if ($metric === 'mix') {
    $points = array_map(function ($row) {
        return [
            't' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['bucket_ts'])),
            'renewable' => round((float) $row['renewable'], 3),
            'fossil' => round((float) $row['fossil'], 3),
            'nuclear' => round((float) $row['nuclear'], 3),
            'other' => round((float) $row['other'], 3),
        ];
    }, $rows);
} else {
    $points = array_map(function ($row) {
        return [
            't' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['bucket_ts'])),
            'v' => round((float) $row['v'], 3),
        ];
    }, $rows);
}

echo json_encode([
    'ok' => true,
    'metric' => $metric,
    'range' => $range,
    'unit' => $unit,
    'points' => $points,
]);
