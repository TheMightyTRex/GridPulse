<?php
/**
 * GET api/series.php?metric=demand&range=week
 *
 * metric: demand | generation | price | emissions | transfers | transfers_country | mix | weather |
 *         fossil | renewable | non_renewable | nuclear_biomass | storage |
 *         wind | solar | hydro | gas | coal | oil | nuclear | biomass
 *   (fossil/renewable/nuclear_biomass/storage power the "Explore by energy
 *   type" topic pages' own History-style output charts - see the long
 *   comment above their switch cases below for why interconnectors.html
 *   isn't in this list too. non_renewable and the eight single-fuel metrics
 *   exist only for pages/comparisons.html's "Energy types" tab, which lets
 *   visitors pick any two of these to overlay - see that page's
 *   ENERGY_METRICS config for the full list and its own colour per source.)
 * range:  3hour | day | week | month | season | year | 5year | 10year | all
 *   (same set and same points/step definitions as RANGE_CONFIG in assets/app.js.
 *   "season" is calendar-anchored to the current UK meteorological season -
 *   see ukgrid_current_uk_season() below - rather than a rolling window.)
 *
 * Returns real history bucketed to each range's step size, averaged within
 * each bucket:
 *   { "ok": true, "metric": "demand", "range": "week", "unit": "GW",
 *     "points": [ {"t": "2026-08-02T00:00:00Z", "v": 24.3}, ... ] }
 *
 * metric=mix returns a different point shape (renewable/non_renewable/
 * storage instead of a single "v") - see the History page's stacked-area
 * chart and period-average donut.
 *
 * metric=weather also returns a different point shape (wind_speed/
 * cloud_cover instead of a single "v") - see readings_weather in
 * sql/schema.sql and ukgrid_ingest_weather() in includes/ingest.php. Like
 * every other metric here, this refreshes with zero cron access via
 * includes/refresh.php's on-demand path; an install where it genuinely
 * hasn't been ingested yet (e.g. on_demand turned off and no cron job set
 * up either) will simply get an empty points array back here, same as any
 * other not-yet-ingested metric.
 *
 * season_offset (optional, only meaningful when range=season): shifts the
 * season back by this many whole years - e.g. season_offset=-1 returns the
 * same season last year instead of the one in progress now. Used by the
 * History page's "compare seasons" checkbox. Clamped to [-10, 0].
 *
 * period_offset (optional, meaningful for any range OTHER than season):
 * shifts that range's whole rolling window back by this many whole
 * window-lengths - e.g. range=week&period_offset=-1 returns the 7-day
 * window immediately before the current one ("last week") rather than the
 * window ending now. Used by pages/comparisons.html's Time periods tab for
 * its "Last week"/"2 weeks ago"/"Last month" compare-against options.
 * Clamped to [-52, 0].
 *
 * Buckets with no data are simply omitted rather than padded with zeroes -
 * the frontend (assets/data.js) draws whatever real points exist and falls
 * back to illustrative mock data only if there are none at all, so a
 * freshly-installed site with a few hours of real history will show a
 * short-but-real line rather than a misleadingly full-looking fake one.
 */

require __DIR__ . '/_bootstrap.php';

$metric = $_GET['metric'] ?? '';
$range = $_GET['range'] ?? '';

$ranges = ukgrid_range_config();
if (!isset($ranges[$range])) {
    ukgrid_json_error('bad_range', 'range must be one of: ' . implode(', ', array_keys($ranges)));
}

$allowedMetrics = [
    'demand', 'generation', 'price', 'emissions', 'transfers', 'transfers_country', 'mix', 'weather',
    'fossil', 'renewable', 'nuclear_biomass', 'storage',
    'non_renewable', 'wind', 'solar', 'hydro', 'gas', 'coal', 'oil', 'nuclear', 'biomass',
];
if (!in_array($metric, $allowedMetrics, true)) {
    ukgrid_json_error('bad_metric', 'metric must be one of: ' . implode(', ', $allowedMetrics));
}

$cfg = $ranges[$range];
$bucket = $cfg['stepSeconds'];
if ($range === 'season') {
    // Calendar-anchored to the UK meteorological season rather than a
    // rolling window - see ukgrid_uk_season_bounds() for why. season_offset
    // lets the caller ask for a prior year's equivalent season instead of
    // the one in progress now (the History page's "compare seasons"
    // checkbox), in which case $to is that season's own natural close
    // rather than "now".
    $seasonOffset = isset($_GET['season_offset']) ? (int) $_GET['season_offset'] : 0;
    $seasonOffset = max(-10, min(0, $seasonOffset));
    $bounds = ukgrid_uk_season_bounds($seasonOffset);
    $from = gmdate('Y-m-d H:i:s', $bounds['start']);
    $to = gmdate('Y-m-d H:i:s', $bounds['end']);
} else {
    // period_offset: shifts this range's whole rolling window back this many
    // whole window-lengths - e.g. range=week&period_offset=-1 returns the
    // 7-day window immediately before the current one ("last week") rather
    // than the one ending now, period_offset=-2 the one before that ("2
    // weeks ago"). Same idea as season_offset above, generalised to any
    // range rather than just the calendar-anchored season - used by
    // pages/comparisons.html's Time periods tab. Clamped to a sane bound
    // (52 windows back) since this walks back by a whole window each step,
    // not a fixed calendar unit - 52 weeks is already a year, far more than
    // that tab exposes in its UI.
    $periodOffset = isset($_GET['period_offset']) ? (int) $_GET['period_offset'] : 0;
    $periodOffset = max(-52, min(0, $periodOffset));
    $windowSeconds = $cfg['points'] * $bucket;
    $to = gmdate('Y-m-d H:i:s', time() + $periodOffset * $windowSeconds);
    $from = gmdate('Y-m-d H:i:s', time() + $periodOffset * $windowSeconds - $windowSeconds);
}

$pdo = ukgrid_db();

function ukgrid_bucketed(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// bucket1/bucket2 (rather than reusing :bucket twice) because PDO's MySQL
// driver uses real prepared statements, which don't allow the same named
// placeholder to appear twice in one query - see the matching comment in
// api/current.php's ukgrid_nearest() for the full story (this exact pattern
// caused a 500 on every call once real data existed to query).
$params = ['from' => $from, 'to' => $to, 'bucket1' => $bucket, 'bucket2' => $bucket];

// Generation readings with NESO's embedded solar/wind spread onto every
// Elexon timestamp. readings_generation holds Elexon FUELINST rows every 5
// minutes but NESO's embedded solar/wind only every 30 (one row per
// settlement-period start), so the "SUM per ts, then AVG per bucket"
// queries below used to count embedded solar/wind at only 1 in every 6
// timestamps - the solar series averaged about a sixth of the real figure,
// and the generation, renewable, wind and mix series dipped the same way
// (and jumped every half hour on the 3-hour sparklines). This derived table
// gives each Elexon timestamp the embedded values of the settlement period
// it falls in - the same pairing api/current.php now uses for the live
// snapshot - so every per-timestamp sum is a complete one.
$GEN_TABLE = '(
    SELECT ts, fuel_type, mw FROM readings_generation
     WHERE source <> "NESO" AND ts BETWEEN :from AND :to
    UNION ALL
    SELECT e.ts, n.fuel_type, n.mw
      FROM (SELECT DISTINCT ts FROM readings_generation WHERE source = "ELEXON" AND ts BETWEEN :from2 AND :to2) e
      JOIN readings_generation n
        ON n.source = "NESO" AND n.ts = FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(e.ts) / 1800) * 1800)
  ) g';
$genParams = $params + ['from2' => $from, 'to2' => $to];

$unit = 'GW';

switch ($metric) {
    case 'demand':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(mw)/1000 AS v
             FROM readings_demand WHERE ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'price':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(price) AS v
             FROM readings_price WHERE provider = "APXMIDP" AND volume > 0 AND ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            // volume > 0: zero-volume half-hours carry a placeholder price of
            // exactly 0 (Elexon: below the liquidity threshold, the Market Index
            // Price "will be set to zero"), which used to drag averages down.
            $params
        );
        $unit = '£';
        break;

    case 'emissions':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(COALESCE(actual_gco2, forecast_gco2)) AS v
             FROM readings_emissions WHERE ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'g/kWh';
        break;

    case 'generation':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.total_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(mw) AS total_mw
               FROM ' . $GEN_TABLE . '
               WHERE fuel_type NOT LIKE "INT%" AND fuel_type <> "PS"
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $genParams
        );
        $unit = 'GW';
        break;

    case 'transfers':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.net_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(mw) AS net_mw
               FROM readings_generation
               WHERE ts BETWEEN :from AND :to AND fuel_type LIKE "INT%"
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    // Generation mix over time, for the History page's stacked-area chart
    // and period-average donut. Bucketed sums per fuel group rather than a
    // single value per point - see ukgrid_fuel_groups() for the fuel-code
    // lists these CASE expressions mirror (keep the two in sync).
    case 'mix':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts,
                    AVG(t.renewable_mw)/1000 AS renewable,
                    AVG(t.non_renewable_mw)/1000 AS non_renewable,
                    AVG(t.storage_mw)/1000 AS storage
             FROM (
               SELECT ts,
                 SUM(CASE WHEN fuel_type IN ("WIND","WIND_EMBEDDED","SOLAR_EMBEDDED","NPSHYD") THEN mw ELSE 0 END) AS renewable_mw,
                 SUM(CASE WHEN fuel_type IN ("CCGT","OCGT","COAL","OIL","NUCLEAR","BIOMASS","OTHER") THEN mw ELSE 0 END) AS non_renewable_mw,
                 SUM(CASE WHEN fuel_type = "PS" THEN mw ELSE 0 END) AS storage_mw
               FROM ' . $GEN_TABLE . '
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $genParams
        );
        $unit = 'GW';
        break;

    // Per-topic output series for the "Explore by energy type" pages
    // (fossil-fuels.html, renewables.html, nuclear-biomass.html,
    // storage.html - interconnectors.html reuses 'transfers' above
    // instead, since interconnector flow is already exactly that). Each
    // mirrors one of 'mix' 's own CASE expressions above (or a finer split
    // of 'non_renewable') - keep all of these, ukgrid_fuel_groups(), and
    // 'mix' above in sync if the underlying fuel-code lists ever change.
    case 'fossil':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.fossil_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(CASE WHEN fuel_type IN ("CCGT","OCGT","COAL","OIL","OTHER") THEN mw ELSE 0 END) AS fossil_mw
               FROM readings_generation
               WHERE ts BETWEEN :from AND :to
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'renewable':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.renewable_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(CASE WHEN fuel_type IN ("WIND","WIND_EMBEDDED","SOLAR_EMBEDDED","NPSHYD") THEN mw ELSE 0 END) AS renewable_mw
               FROM ' . $GEN_TABLE . '
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $genParams
        );
        $unit = 'GW';
        break;

    case 'nuclear_biomass':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.nb_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(CASE WHEN fuel_type IN ("NUCLEAR","BIOMASS") THEN mw ELSE 0 END) AS nb_mw
               FROM readings_generation
               WHERE ts BETWEEN :from AND :to
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'storage':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.storage_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(CASE WHEN fuel_type = "PS" THEN mw ELSE 0 END) AS storage_mw
               FROM readings_generation
               WHERE ts BETWEEN :from AND :to
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    // Non-renewable = the mirror image of 'renewable' above: every fuel
    // type ukgrid_fuel_groups() doesn't call renewable or storage. Exists
    // so pages/comparisons.html's "Energy types" tab can offer a direct
    // renewable-vs-non-renewable pairing, rather than making a visitor
    // reconstruct it themselves from 'fossil' plus 'nuclear_biomass'.
    case 'non_renewable':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.nr_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(CASE WHEN fuel_type IN ("CCGT","OCGT","COAL","OIL","NUCLEAR","BIOMASS","OTHER") THEN mw ELSE 0 END) AS nr_mw
               FROM readings_generation
               WHERE ts BETWEEN :from AND :to
               GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    // Single-fuel breakdowns, each a finer split of one of the group
    // metrics above (wind+solar+hydro make up 'renewable'; gas+coal+oil
    // make up 'fossil'; nuclear+biomass make up 'nuclear_biomass') - added
    // for comparisons.html's "Energy types" tab so two individual sources
    // (e.g. Wind vs Gas) can be overlaid, not just the coarser groups.
    case 'wind':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.v_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(CASE WHEN fuel_type IN ("WIND","WIND_EMBEDDED") THEN mw ELSE 0 END) AS v_mw
               FROM ' . $GEN_TABLE . ' GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $genParams
        );
        $unit = 'GW';
        break;

    case 'solar':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.v_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(CASE WHEN fuel_type = "SOLAR_EMBEDDED" THEN mw ELSE 0 END) AS v_mw
               FROM ' . $GEN_TABLE . ' GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $genParams
        );
        $unit = 'GW';
        break;

    case 'hydro':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.v_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(CASE WHEN fuel_type = "NPSHYD" THEN mw ELSE 0 END) AS v_mw
               FROM readings_generation WHERE ts BETWEEN :from AND :to GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'gas':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.v_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(CASE WHEN fuel_type IN ("CCGT","OCGT") THEN mw ELSE 0 END) AS v_mw
               FROM readings_generation WHERE ts BETWEEN :from AND :to GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'coal':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.v_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(CASE WHEN fuel_type = "COAL" THEN mw ELSE 0 END) AS v_mw
               FROM readings_generation WHERE ts BETWEEN :from AND :to GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'oil':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.v_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(CASE WHEN fuel_type = "OIL" THEN mw ELSE 0 END) AS v_mw
               FROM readings_generation WHERE ts BETWEEN :from AND :to GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'nuclear':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.v_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(CASE WHEN fuel_type = "NUCLEAR" THEN mw ELSE 0 END) AS v_mw
               FROM readings_generation WHERE ts BETWEEN :from AND :to GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    case 'biomass':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(t.ts)/:bucket1)*:bucket2) AS bucket_ts, AVG(t.v_mw)/1000 AS v
             FROM (
               SELECT ts, SUM(CASE WHEN fuel_type = "BIOMASS" THEN mw ELSE 0 END) AS v_mw
               FROM readings_generation WHERE ts BETWEEN :from AND :to GROUP BY ts
             ) t
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    // Interconnector flow broken down by the country each cable connects
    // to, for pages/interconnectors.html's "Imports and exports by
    // country" section. Cables are first summed per country per timestamp
    // (so Ireland = Moyle + EWIC + Greenlink, France = IFA + IFA2 +
    // ElecLink), then averaged per bucket three ways: the net flow (for the
    // small-multiple charts) and its importing and exporting parts
    // separately (for the energy totals - a net average alone would hide
    // a bucket that imported in the morning and exported in the evening).
    // Positive = importing into GB, negative = exporting, as everywhere
    // else on the site. Keep the CASE below in sync with
    // INTERCONNECTOR_LABELS in assets/data.js if a cable is ever added.
    case 'transfers_country':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(c.ts)/:bucket1)*:bucket2) AS bucket_ts, c.country,
                    AVG(c.net_mw) AS net_mw,
                    AVG(GREATEST(c.net_mw, 0)) AS imp_mw,
                    AVG(LEAST(c.net_mw, 0)) AS exp_mw,
                    COUNT(*) AS n, UNIX_TIMESTAMP(MIN(c.ts)) AS first_ts, UNIX_TIMESTAMP(MAX(c.ts)) AS last_ts
             FROM (
               SELECT ts,
                 CASE
                   WHEN fuel_type IN ("INTFR","INTIFA2","INTELEC") THEN "france"
                   WHEN fuel_type = "INTNEM" THEN "belgium"
                   WHEN fuel_type = "INTNED" THEN "netherlands"
                   WHEN fuel_type = "INTVKL" THEN "denmark"
                   WHEN fuel_type = "INTNSL" THEN "norway"
                   WHEN fuel_type IN ("INTIRL","INTEW","INTGRNL") THEN "ireland"
                   ELSE NULL
                 END AS country,
                 SUM(mw) AS net_mw
               FROM readings_generation
               WHERE ts BETWEEN :from AND :to AND fuel_type LIKE "INT%"
               GROUP BY ts, country
             ) c
             WHERE c.country IS NOT NULL
             GROUP BY bucket_ts, c.country
             ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'GW';
        break;

    // Wind speed + cloud cover for the History page's "Wind speed & cloud
    // cover" card. See readings_weather in sql/schema.sql - one
    // representative GB point, not a national average (see that table's
    // own comment for why).
    case 'weather':
        $rows = ukgrid_bucketed($pdo,
            'SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/:bucket1)*:bucket2) AS bucket_ts,
                    AVG(wind_speed_ms) AS wind_speed, AVG(cloud_cover_pct) AS cloud_cover
             FROM readings_weather WHERE ts BETWEEN :from AND :to
             GROUP BY bucket_ts ORDER BY bucket_ts ASC',
            $params
        );
        $unit = 'm/s';
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
            'storage' => round((float) $row['storage'], 3),
        ];
    }, $rows);
} elseif ($metric === 'weather') {
    $points = array_map(function ($row) {
        return [
            't' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['bucket_ts'])),
            'wind_speed' => $row['wind_speed'] !== null ? round((float) $row['wind_speed'], 2) : null,
            'cloud_cover' => $row['cloud_cover'] !== null ? round((float) $row['cloud_cover'], 1) : null,
        ];
    }, $rows);
} elseif ($metric === 'transfers_country') {
    // One point per bucket with each country's net flow in GW, plus
    // period totals in GWh. Each bucket's energy is its average import/
    // export MW times the part of the bucket that falls inside the
    // requested window (the first and last buckets are usually partial),
    // so a short range isn't over-counted. A bucket's hours are also
    // capped at the span its readings actually cover (n readings at their
    // own spacing - 5 minutes for Elexon FUELINST), so a bucket that only
    // has data for part of its length isn't scaled up as if it were full.
    // Buckets with no readings at all contribute nothing.
    $countries = ['france', 'belgium', 'netherlands', 'denmark', 'norway', 'ireland'];
    $fromTs = strtotime($from);
    $toTs = strtotime($to);
    $byBucket = [];
    $totals = [];
    foreach ($countries as $c) {
        $totals[$c] = ['import_gwh' => 0.0, 'export_gwh' => 0.0];
    }
    foreach ($rows as $row) {
        $c = $row['country'];
        if (!isset($totals[$c])) {
            continue;
        }
        $bStart = strtotime($row['bucket_ts']);
        $n = (int) $row['n'];
        $span = (int) $row['last_ts'] - (int) $row['first_ts'];
        $covered = $n > 1 ? $span * $n / ($n - 1) : 300;
        $hours = max(0, min(min($bStart + $bucket, $toTs) - max($bStart, $fromTs), $covered)) / 3600;
        $totals[$c]['import_gwh'] += (float) $row['imp_mw'] * $hours / 1000;
        $totals[$c]['export_gwh'] += -(float) $row['exp_mw'] * $hours / 1000;
        $t = gmdate('Y-m-d\TH:i:s\Z', $bStart);
        if (!isset($byBucket[$t])) {
            $byBucket[$t] = ['t' => $t];
        }
        $byBucket[$t][$c] = round((float) $row['net_mw'] / 1000, 3);
    }
    foreach ($totals as $c => $v) {
        $totals[$c] = ['import_gwh' => round($v['import_gwh'], 1), 'export_gwh' => round($v['export_gwh'], 1)];
    }
    $points = array_values($byBucket);
} else {
    $points = array_map(function ($row) {
        return [
            't' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['bucket_ts'])),
            'v' => round((float) $row['v'], 3),
        ];
    }, $rows);
}

$response = [
    'ok' => true,
    'metric' => $metric,
    'range' => $range,
    'unit' => $unit,
    'points' => $points,
];
if ($metric === 'transfers_country') {
    $response['from'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($from));
    $response['to'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($to));
    $response['totals'] = $totals;
}
echo json_encode($response);
