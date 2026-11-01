<?php
/**
 * GET api/canada_series.php?metric=demand&range=day
 *
 * metric: demand | generation | mix |
 *         renewable | non_renewable | fossil |
 *         wind | solar | hydro | gas | coal | oil | nuclear | biomass
 * range:  same set as api/series.php (3hour | day | week | month | season |
 *         year | 5year | 10year | all) - see ukgrid_range_config() in
 *         api/_bootstrap.php.
 *
 * Bucketed history for pages/canada.html's sparklines, History section, 24h
 * mix chart, and pages/comparisons.html's cross-country "Energy sources"
 * tab. Ontario (IESO) only - see includes/ingest.php's ukgrid_ingest_ieso().
 *
 * "coal" and "oil" are accepted as valid metric names (for cross-country
 * consistency with GB/USA/ENTSO-E) but will always return zero points:
 * Ontario's generation mix hasn't included coal since it was phased out in
 * 2014, and IESO doesn't report a separate oil category, so there's
 * genuinely nothing to return - not a bug.
 *
 * Because IESO's "most recent" report aliases only ever contain a rolling
 * recent window (see ukgrid_ingest_ieso()'s docblock), History for this
 * page - unlike GB's own decade-deep archive - only covers however long
 * this install has actually been polling IESO for. Longer ranges (year,
 * 5year, 10year) will legitimately come back with little or no data on a
 * newer install; that's disclosed on pages/canada.html rather than papered
 * over.
 *
 * mix/renewable/non_renewable/fossil group definitions match this site's
 * GB convention: renewable = hydro + wind + solar; non_renewable = nuclear
 * + gas + biofuel + other (biomass/biofuel counted as non-renewable here,
 * same as GB's own non_renewable_mw - see api/series.php); fossil = gas
 * only (no coal/oil currently in Ontario's mix).
 *
 * Response shape matches api/series.php:
 *   { "ok": true, "metric": "demand", "range": "day", "unit": "GW",
 *     "points": [ {"t": "2026-08-23T13:00:00Z", "v": 16.2}, ... ] }
 */

require __DIR__ . '/_bootstrap.php';

$metric = $_GET['metric'] ?? '';
$range = $_GET['range'] ?? '';

$ranges = ukgrid_range_config();
if (!isset($ranges[$range])) {
    ukgrid_json_error('bad_range', 'range must be one of: ' . implode(', ', array_keys($ranges)));
}

$allowedMetrics = [
    'demand', 'generation', 'mix',
    'renewable', 'non_renewable', 'fossil',
    'wind', 'solar', 'hydro', 'gas', 'coal', 'oil', 'nuclear', 'biomass',
];
if (!in_array($metric, $allowedMetrics, true)) {
    ukgrid_json_error('bad_metric', 'metric must be one of: ' . implode(', ', $allowedMetrics));
}

$cfg = $ranges[$range];
$bucket = $cfg['stepSeconds'];
if ($range === 'season') {
    // Same UK-calendar season boundaries as every other page's Season tab -
    // see ukgrid_uk_season_bounds() and the matching comment in
    // api/usa_series.php. Not a claim that Ontario uses the same seasons.
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

function ukgrid_ca_bucketed(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$params = ['from' => $from, 'to' => $to, 'bucket1' => $bucket, 'bucket2' => $bucket];
$unit = 'GW';

switch ($metric) {
    case 'demand':
        $rows = ukgrid_ca_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(mw)/1000 AS v
             FROM readings_ca_demand WHERE ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        break;

    case 'generation':
        $rows = ukgrid_ca_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.v_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(mw) AS v_mw
               FROM readings_ca_generation
               WHERE ts BETWEEN :from AND :to
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        break;

    case 'mix':
        $rows = ukgrid_ca_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts,
                    AVG(t.renewable_mw)/1000 AS renewable,
                    AVG(t.non_renewable_mw)/1000 AS non_renewable
             FROM (
               SELECT ts,
                 SUM(CASE WHEN category IN ("HYDRO","WIND","SOLAR") THEN mw ELSE 0 END) AS renewable_mw,
                 SUM(CASE WHEN category IN ("NUCLEAR","GAS","BIOFUEL","OTHER") THEN mw ELSE 0 END) AS non_renewable_mw
               FROM readings_ca_generation
               WHERE ts BETWEEN :from AND :to
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        break;

    case 'renewable':
    case 'non_renewable':
    case 'fossil':
        $groupCodes = [
            'renewable' => ['HYDRO', 'WIND', 'SOLAR'],
            'non_renewable' => ['NUCLEAR', 'GAS', 'BIOFUEL', 'OTHER'],
            'fossil' => ['GAS'],
        ][$metric];
        $placeholders = implode(',', array_map(fn($c) => $pdo->quote($c), $groupCodes));
        $rows = ukgrid_ca_bucketed($pdo,
            "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.v_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(mw) AS v_mw
               FROM readings_ca_generation
               WHERE category IN ($placeholders) AND ts BETWEEN :from AND :to
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC",
            $params
        );
        break;

    case 'wind':
    case 'solar':
    case 'hydro':
    case 'gas':
    case 'nuclear':
    case 'biomass':
        $code = ['wind' => 'WIND', 'solar' => 'SOLAR', 'hydro' => 'HYDRO', 'gas' => 'GAS', 'nuclear' => 'NUCLEAR', 'biomass' => 'BIOFUEL'][$metric];
        $rows = ukgrid_ca_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(mw)/1000 AS v
             FROM readings_ca_generation WHERE category = :code AND ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params + ['code' => $code]
        );
        break;

    case 'coal':
    case 'oil':
        // See file-level docblock - genuinely no data for these in Ontario.
        $rows = [];
        break;

    default:
        $rows = [];
}

if ($metric === 'mix') {
    $points = array_map(function ($row) {
        return [
            't' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['bucket_ts'])),
            'renewable' => round((float) $row['renewable'], 3),
            'non_renewable' => round((float) $row['non_renewable'], 3),
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
