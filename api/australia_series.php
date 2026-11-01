<?php
/**
 * GET api/australia_series.php?metric=demand&range=day
 *
 * metric: demand | generation | price | mix |
 *         renewable | non_renewable | fossil |
 *         wind | solar | hydro | gas | coal | oil | bioenergy | nuclear
 * range:  same set as api/series.php (3hour | day | week | month | season |
 *         year | 5year | 10year | all) - see ukgrid_range_config() in
 *         api/_bootstrap.php.
 *
 * Bucketed history for the Australia sparklines, History section, 24-hour
 * mix chart on pages/australia.html, and pages/comparisons.html's
 * cross-country "Energy sources" tab. All of it comes from the Open
 * Electricity API - see includes/ingest.php's
 * ukgrid_ingest_openelectricity() - landing in readings_au_demand/
 * readings_au_generation/readings_au_price.
 *
 * "oil" maps to Open Electricity's "distillate" fueltech group (Australia's
 * name for liquid-fuel peaking plant), kept as "oil" here purely so
 * pages/comparisons.html can request the same metric name regardless of
 * which country is selected. "nuclear" is accepted for the same
 * cross-country-compatibility reason even though the NEM has none - it
 * always returns an empty points array (matching how e.g. Italy/Portugal's
 * own ENTSO-E series behave for a fuel type they don't generate), rather
 * than an error.
 *
 * metric=mix groups the same way as api/australia_current.php's mix_mw:
 * fossil = coal+gas+distillate, renewable = wind+solar+hydro+bioenergy,
 * other = battery_discharging (dispatchable storage, not a primary
 * fuel - biomass counts as renewable here to match this project's existing
 * ENTSO-E convention, see api/country_series.php's psr_type groupings).
 * battery_charging and pumps (pumped-hydro pumping load - pumped hydro's
 * generation is already inside "hydro") are excluded from every metric
 * here, same reasoning as
 * api/australia_current.php's generation_mw (it's a draw, not supply).
 *
 * Response shape matches api/series.php:
 *   { "ok": true, "metric": "demand", "range": "day", "unit": "GW",
 *     "points": [ {"t": "2026-09-19T00:00:00Z", "v": 18.8}, ... ] }
 */

require __DIR__ . '/_bootstrap.php';

$metric = $_GET['metric'] ?? '';
$range = $_GET['range'] ?? '';

$ranges = ukgrid_range_config();
if (!isset($ranges[$range])) {
    ukgrid_json_error('bad_range', 'range must be one of: ' . implode(', ', array_keys($ranges)));
}

$allowedMetrics = [
    'demand', 'generation', 'price', 'mix',
    'renewable', 'non_renewable', 'fossil',
    'wind', 'solar', 'hydro', 'gas', 'coal', 'oil', 'bioenergy', 'biomass', 'nuclear',
];
if (!in_array($metric, $allowedMetrics, true)) {
    ukgrid_json_error('bad_metric', 'metric must be one of: ' . implode(', ', $allowedMetrics));
}

$cfg = $ranges[$range];
$bucket = $cfg['stepSeconds'];
if ($range === 'season') {
    // Same calendar-anchored season as api/series.php/api/usa_series.php -
    // purely so every page's "Season" tab lines up on the same dates, not
    // a claim that Australia uses the UK's own season definitions.
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

function ukgrid_au_bucketed(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// bucket1/bucket2 (rather than reusing :bucket twice) because PDO's MySQL
// driver uses real prepared statements, which don't allow the same named
// placeholder to appear twice in one query - see the matching comment in
// api/current.php's ukgrid_nearest().
$params = ['from' => $from, 'to' => $to, 'bucket1' => $bucket, 'bucket2' => $bucket];
$unit = 'GW';

switch ($metric) {
    case 'demand':
        $rows = ukgrid_au_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(mw)/1000 AS v
             FROM readings_au_demand WHERE ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'price':
        $rows = ukgrid_au_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(price_aud_mwh) AS v
             FROM readings_au_price WHERE ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'AUD/MWh';
        break;

    case 'generation':
        $rows = ukgrid_au_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.v_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(mw) AS v_mw
               FROM readings_au_generation
               WHERE category IN ("coal","gas","wind","solar","hydro","distillate","bioenergy","battery_discharging")
                 AND ts BETWEEN :from AND :to
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'mix':
        $rows = ukgrid_au_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts,
                    AVG(t.fossil_mw)/1000 AS fossil,
                    AVG(t.renewable_mw)/1000 AS renewable,
                    AVG(t.other_mw)/1000 AS other
             FROM (
               SELECT ts,
                 SUM(CASE WHEN category IN ("coal","gas","distillate") THEN mw ELSE 0 END) AS fossil_mw,
                 SUM(CASE WHEN category IN ("wind","solar","hydro","bioenergy") THEN mw ELSE 0 END) AS renewable_mw,
                 SUM(CASE WHEN category IN ("battery_discharging") THEN mw ELSE 0 END) AS other_mw
               FROM readings_au_generation
               WHERE ts BETWEEN :from AND :to AND category NOT IN ("battery_charging", "pumps")
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'renewable':
    case 'non_renewable':
    case 'fossil':
        $groupCodes = [
            'renewable' => ['wind', 'solar', 'hydro', 'bioenergy'],
            'non_renewable' => ['coal', 'gas', 'distillate', 'battery_discharging'],
            'fossil' => ['coal', 'gas', 'distillate'],
        ][$metric];
        $placeholders = implode(',', array_map(fn($c) => $pdo->quote($c), $groupCodes));
        $rows = ukgrid_au_bucketed($pdo,
            "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.v_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(mw) AS v_mw
               FROM readings_au_generation
               WHERE category IN ($placeholders) AND ts BETWEEN :from AND :to
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC",
            $params
        );
        $unit = 'GW';
        break;

    // Single-fuel breakdowns. "oil" and "nuclear" - see this file's
    // docblock for why those two map to "distillate" and to a code that
    // will simply never have rows, respectively. "biomass" is accepted as
    // an alias for "bioenergy" purely so pages/comparisons.html's "Energy
    // sources" tab (which uses the canonical key "biomass" site-wide, per
    // ENERGY_SOURCE_META) can request the same metric name for Australia
    // as for every other country.
    case 'wind':
    case 'solar':
    case 'hydro':
    case 'gas':
    case 'coal':
    case 'oil':
    case 'bioenergy':
    case 'biomass':
    case 'nuclear':
        $code = [
            'wind' => 'wind', 'solar' => 'solar', 'hydro' => 'hydro', 'gas' => 'gas',
            'coal' => 'coal', 'oil' => 'distillate', 'bioenergy' => 'bioenergy', 'biomass' => 'bioenergy', 'nuclear' => 'nuclear',
        ][$metric];
        $rows = ukgrid_au_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(mw)/1000 AS v
             FROM readings_au_generation WHERE category = :code AND ts BETWEEN :from AND :to
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
    $points = array_map(function ($row) use ($metric) {
        return [
            't' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['bucket_ts'])),
            'v' => round((float) $row['v'], $metric === 'price' ? 2 : 3),
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
