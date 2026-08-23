<?php
/**
 * GET api/usa_series.php?metric=demand&range=day
 *
 * metric: demand | generation | transfers | mix |
 *         renewable | non_renewable | fossil |
 *         wind | solar | hydro | gas | coal | oil | nuclear
 * range:  same set as api/series.php (3hour | day | week | month | season |
 *         year | 5year | 10year | all) - see ukgrid_range_config() in
 *         api/_bootstrap.php.
 *
 * Bucketed history for the USA sparklines, History section, 24-hour mix
 * chart on pages/usa.html, and pages/comparisons.html's cross-country
 * "Energy sources" tab. demand/generation/transfers come from the EIA
 * API's rto/region-data route; every other metric comes from its
 * rto/fuel-type-data route (see includes/ingest.php's ukgrid_ingest_eia())
 * - both land in the same readings_us_demand/readings_us_generation
 * tables, just different "category" values. There's no "price" or
 * "emissions" metric here: EIA doesn't publish either for the lower-48
 * aggregate, so there's no real data to bucket - see
 * api/usa_current.php's docblock and pages/usa.html's History section,
 * which shows those two panels as "not available" rather than any kind of
 * placeholder line.
 *
 * metric=mix returns the same shape as api/series.php's own metric=mix
 * (fossil/renewable/other instead of a single "v"), grouped the same way
 * as api/usa_current.php's mix_mw ($fuelTypes) and pages/usa.html's mix
 * table: fossil = NG+COL+OIL, renewable = WND+SUN+WAT, other = NUC+OTH+UNK
 * (named "other" rather than "nuclear_biomass" here since nuclear is
 * lumped with the leftover EIA categories, not biomass - the US doesn't
 * report biomass as its own EIA-930 fuel type the way GB's Elexon feed
 * does, hence no "biomass" or "nuclear_biomass" metric below either).
 *
 * renewable/non_renewable/fossil and the seven single-fuel metrics mirror
 * api/series.php's own GB metric names 1:1 where the underlying EIA fuel
 * exists (wind/solar/hydro/gas/coal/oil/nuclear), so
 * pages/comparisons.html's "Energy sources" tab can pass the exact same
 * metric string regardless of which country is selected.
 *
 * Response shape matches api/series.php:
 *   { "ok": true, "metric": "demand", "range": "day", "unit": "GW",
 *     "points": [ {"t": "2026-08-09T00:00:00Z", "v": 452.3}, ... ] }
 *
 * Because EIA-930 data normally lags about a day, a "day" or "3hour" range
 * request here may well come back with few or no points yet for the most
 * recent portion of that window - that's expected, not a bug.
 */

require __DIR__ . '/_bootstrap.php';

$metric = $_GET['metric'] ?? '';
$range = $_GET['range'] ?? '';

$ranges = ukgrid_range_config();
if (!isset($ranges[$range])) {
    ukgrid_json_error('bad_range', 'range must be one of: ' . implode(', ', array_keys($ranges)));
}

$allowedMetrics = [
    'demand', 'generation', 'transfers', 'mix',
    'renewable', 'non_renewable', 'fossil',
    'wind', 'solar', 'hydro', 'gas', 'coal', 'oil', 'nuclear',
];
if (!in_array($metric, $allowedMetrics, true)) {
    ukgrid_json_error('bad_metric', 'metric must be one of: ' . implode(', ', $allowedMetrics));
}

$cfg = $ranges[$range];
$bucket = $cfg['stepSeconds'];
if ($range === 'season') {
    // Same calendar-anchored season (and same season_offset support for
    // year-on-year comparison) as api/series.php - see
    // ukgrid_uk_season_bounds() for why this isn't a rolling window. (The
    // seasons themselves are still the UK's own calendar boundaries, purely
    // so every page's "Season" tab lines up on the same dates - not a claim
    // that the US uses the same season definitions.)
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

function ukgrid_us_bucketed(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// bucket1/bucket2 (rather than reusing :bucket twice) because PDO's MySQL
// driver uses real prepared statements, which don't allow the same named
// placeholder to appear twice in one query - see the matching comment in
// api/current.php's ukgrid_nearest() for the full story.
$params = ['from' => $from, 'to' => $to, 'bucket1' => $bucket, 'bucket2' => $bucket];
$unit = 'GW';

switch ($metric) {
    case 'demand':
        $rows = ukgrid_us_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(mw)/1000 AS v
             FROM readings_us_demand WHERE ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'generation':
        $rows = ukgrid_us_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(mw)/1000 AS v
             FROM readings_us_generation WHERE category = "TOTAL" AND ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'transfers':
        $rows = ukgrid_us_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(mw)/1000 AS v
             FROM readings_us_generation WHERE category = "INTERCONNECTION" AND ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'mix':
        $rows = ukgrid_us_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts,
                    AVG(t.fossil_mw)/1000 AS fossil,
                    AVG(t.renewable_mw)/1000 AS renewable,
                    AVG(t.other_mw)/1000 AS other
             FROM (
               SELECT ts,
                 SUM(CASE WHEN category IN ("NG","COL","OIL") THEN mw ELSE 0 END) AS fossil_mw,
                 SUM(CASE WHEN category IN ("WND","SUN","WAT") THEN mw ELSE 0 END) AS renewable_mw,
                 SUM(CASE WHEN category IN ("NUC","OTH","UNK") THEN mw ELSE 0 END) AS other_mw
               FROM readings_us_generation
               WHERE ts BETWEEN :from AND :to AND category NOT IN ("TOTAL", "INTERCONNECTION")
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    // Same three groups as metric=mix above, each returned as a single
    // value instead of split three ways - lets pages/comparisons.html's
    // "Energy sources" tab request "USA renewables" the same way it
    // requests "GB renewables" from api/series.php.
    case 'renewable':
    case 'non_renewable':
    case 'fossil':
        $groupCodes = [
            'renewable' => ['WND', 'SUN', 'WAT'],
            'non_renewable' => ['NUC', 'OTH', 'UNK', 'NG', 'COL', 'OIL'],
            'fossil' => ['NG', 'COL', 'OIL'],
        ][$metric];
        $placeholders = implode(',', array_map(fn($c) => $pdo->quote($c), $groupCodes));
        $rows = ukgrid_us_bucketed($pdo,
            "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.v_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(mw) AS v_mw
               FROM readings_us_generation
               WHERE category IN ($placeholders) AND ts BETWEEN :from AND :to
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC",
            $params
        );
        $unit = 'GW';
        break;

    // Single-fuel breakdowns - each a direct EIA fuel-type code, no
    // grouping needed. See the file-level docblock for why there's no
    // "biomass" metric here (EIA doesn't report it separately).
    case 'wind':
    case 'solar':
    case 'hydro':
    case 'gas':
    case 'coal':
    case 'oil':
    case 'nuclear':
        $code = ['wind' => 'WND', 'solar' => 'SUN', 'hydro' => 'WAT', 'gas' => 'NG', 'coal' => 'COL', 'oil' => 'OIL', 'nuclear' => 'NUC'][$metric];
        $rows = ukgrid_us_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(mw)/1000 AS v
             FROM readings_us_generation WHERE category = :code AND ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params + ['code' => $code]
        );
        $unit = 'GW';
        break;

    default:
        $rows = [];
}

if ($metric === 'mix') {
    $points = array_map(function ($row) {
        return [
            't' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['bucket_ts'])),
            'fossil' => round((float) $row['fossil'], 3),
            'renewable' => round((float) $row['renewable'], 3),
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
