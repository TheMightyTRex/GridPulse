<?php
/**
 * GET api/ireland_series.php?metric=demand&range=day
 *
 * metric: demand | generation | wind | transfers | emissions
 * range:  same set as api/series.php (3hour | day | week | month | season |
 *         year | 5year | 10year | all) - see ukgrid_range_config() in
 *         api/_bootstrap.php.
 *
 * Bucketed history for the Ireland (all-island) sparklines on
 * pages/ireland.html, sourced from EirGrid's Smart Grid Dashboard. There's
 * no "price" metric here - see api/ireland_current.php's docblock for why
 * SEM price isn't wired up.
 *
 * Response shape matches api/series.php:
 *   { "ok": true, "metric": "demand", "range": "day", "unit": "GW",
 *     "points": [ {"t": "2026-08-09T00:00:00Z", "v": 4.3}, ... ] }
 */

require __DIR__ . '/_bootstrap.php';

$metric = $_GET['metric'] ?? '';
$range = $_GET['range'] ?? '';

$ranges = ukgrid_range_config();
if (!isset($ranges[$range])) {
    ukgrid_json_error('bad_range', 'range must be one of: ' . implode(', ', array_keys($ranges)));
}

$allowedMetrics = ['demand', 'generation', 'wind', 'transfers', 'emissions'];
if (!in_array($metric, $allowedMetrics, true)) {
    ukgrid_json_error('bad_metric', 'metric must be one of: ' . implode(', ', $allowedMetrics));
}

$cfg = $ranges[$range];
$bucket = $cfg['stepSeconds'];
if ($range === 'season') {
    // Same calendar-anchored season (and same season_offset support for
    // year-on-year comparison) as api/series.php - see
    // ukgrid_uk_season_bounds() for why this isn't a rolling window.
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

function ukgrid_ie_bucketed(PDO $pdo, string $sql, array $params): array
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
        $rows = ukgrid_ie_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(mw)/1000 AS v
             FROM readings_ie_demand WHERE ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'generation':
        $rows = ukgrid_ie_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(mw)/1000 AS v
             FROM readings_ie_generation WHERE category = "TOTAL" AND ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'wind':
        $rows = ukgrid_ie_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(mw)/1000 AS v
             FROM readings_ie_generation WHERE category = "WIND" AND ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'transfers':
        $rows = ukgrid_ie_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(mw)/1000 AS v
             FROM readings_ie_generation WHERE category = "INTERCONNECTION" AND ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'emissions':
        $rows = ukgrid_ie_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(gco2_per_kwh) AS v
             FROM readings_ie_co2 WHERE ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'g/kWh';
        break;

    default:
        $rows = [];
}

$points = array_map(function ($row) {
    return [
        't' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['bucket_ts'])),
        'v' => round((float) $row['v'], 3),
    ];
}, $rows);

echo json_encode([
    'ok' => true,
    'metric' => $metric,
    'range' => $range,
    'unit' => $unit,
    'points' => $points,
]);
