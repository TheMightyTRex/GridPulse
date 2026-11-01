<?php
/**
 * GET api/usa_current.php
 *
 * Latest available lower-48-states aggregate snapshot: demand, total net
 * generation, total interchange (imports/exports across all US48
 * boundaries - dominated by the Canada/Mexico ties, since domestic
 * balancing-authority-to-balancing-authority flows mostly cancel out in the
 * aggregate), and generation broken down by fuel type. Source: the EIA
 * API's rto/region-data and rto/fuel-type-data routes - see
 * includes/ingest.php's ukgrid_ingest_eia().
 *
 * There's no confirmed free source for a national average wholesale price
 * or a direct emissions figure, so those aren't included here -
 * pages/usa.html's price/emissions stats and its history charts stay on
 * placeholder data for now. See pages/data-sources.html for what's live and
 * what isn't.
 *
 * Response shape:
 *   {
 *     "ok": true,
 *     "live": true,
 *     "stale": false,
 *     "ts": "2026-08-09T13:00:00Z",
 *     "demand_mw": 452000.0,
 *     "generation_mw": 458000.0,
 *     "interconnection_mw": -2100.0,
 *     "mix_mw": { "COL": 65000.0, "NG": 197000.0, "NUC": 82400.0, ... }
 *   }
 *
 * If there's no data yet (including a fresh install with no eia_api_key
 * configured), "ok" stays true but "live" is false and every field is null
 * or empty, matching api/ireland_current.php's convention - the frontend
 * falls back to its own illustrative figures in that case.
 *
 * "stale" uses usa_staleness_minutes (default 2880 = 2 days), not the
 * global staleness_minutes - EIA-930 itself normally lags about a day
 * behind, so the shorter global threshold would flag this as stale
 * constantly even when ingestion is working correctly.
 */

require __DIR__ . '/_bootstrap.php';

$pdo = ukgrid_db();
$config = ukgrid_load_config();
$stalenessMinutes = (int) ($config['usa_staleness_minutes'] ?? 2880);

$latestTs = $pdo->query('SELECT MAX(ts) FROM readings_us_demand')->fetchColumn();

if (!$latestTs) {
    echo json_encode([
        'ok' => true,
        'live' => false,
        'stale' => true,
        'ts' => null,
        'demand_mw' => null,
        'generation_mw' => null,
        'interconnection_mw' => null,
        'mix_mw' => new stdClass(),
        'mix_ts' => null,
    ]);
    exit;
}

$isStale = (strtotime($latestTs) < time() - $stalenessMinutes * 60);

$demandStmt = $pdo->prepare('SELECT mw FROM readings_us_demand WHERE ts = :ts');
$demandStmt->execute(['ts' => $latestTs]);
$demandMw = $demandStmt->fetchColumn();

// See the matching comment in api/current.php's ukgrid_nearest() - PDO's
// MySQL driver uses real prepared statements, which don't allow the same
// named placeholder twice in one query. Every repeated value below gets its
// own placeholder name bound to that same value.
function ukgrid_us_nearest(PDO $pdo, string $valueCol, string $ts, int $windowMinutes, string $category)
{
    $stmt = $pdo->prepare(
        'SELECT ' . $valueCol . ' AS v FROM readings_us_generation
         WHERE category = :cat
           AND ts BETWEEN DATE_SUB(:ts1, INTERVAL :window1 MINUTE) AND DATE_ADD(:ts2, INTERVAL :window2 MINUTE)
         ORDER BY ABS(TIMESTAMPDIFF(SECOND, ts, :ts3)) ASC LIMIT 1'
    );
    $stmt->execute(['cat' => $category, 'ts1' => $ts, 'window1' => $windowMinutes, 'ts2' => $ts, 'window2' => $windowMinutes, 'ts3' => $ts]);
    $row = $stmt->fetch();
    return $row ? $row['v'] : null;
}

$genTotal = ukgrid_us_nearest($pdo, 'mw', $latestTs, $stalenessMinutes, 'TOTAL');
$interconnection = ukgrid_us_nearest($pdo, 'mw', $latestTs, $stalenessMinutes, 'INTERCONNECTION');

// Every fuel-type category the EIA has reported (not just the original
// nine: newer EIA-930 codes such as battery storage, pumped storage and
// geothermal used to be ingested but never returned, so they were missing
// from BOTH the mix table and the donut), all read at ONE timestamp - the
// latest hour, within the window, that has at least three-quarters as many
// fuel types as the most complete hour does (so a half-published latest
// hour isn't used). Looking each fuel up independently as "nearest within +/- 2 days"
// could stitch a single donut together from different days' readings.
$mix = [];
$mixTs = null;
$covStmt = $pdo->prepare(
    'SELECT ts, COUNT(*) AS n FROM readings_us_generation
     WHERE category NOT IN ("TOTAL", "INTERCONNECTION")
       AND ts BETWEEN DATE_SUB(:ts1, INTERVAL :window1 MINUTE) AND DATE_ADD(:ts2, INTERVAL :window2 MINUTE)
     GROUP BY ts ORDER BY ts DESC'
);
$covStmt->execute(['ts1' => $latestTs, 'window1' => $stalenessMinutes, 'ts2' => $latestTs, 'window2' => $stalenessMinutes]);
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
if ($mixTs !== null) {
    $atStmt = $pdo->prepare('SELECT category, mw FROM readings_us_generation WHERE ts = :ts AND category NOT IN ("TOTAL", "INTERCONNECTION")');
    $atStmt->execute(['ts' => $mixTs]);
    foreach ($atStmt->fetchAll() as $row) {
        $mix[$row['category']] = (float) $row['mw'];
    }
    // A fuel type missing at that hour falls back to its latest reading at
    // or before it (never a later one), within the same window.
    $catStmt = $pdo->prepare(
        'SELECT DISTINCT category FROM readings_us_generation
         WHERE category NOT IN ("TOTAL", "INTERCONNECTION") AND ts BETWEEN DATE_SUB(:ts1, INTERVAL :window1 MINUTE) AND :ts2'
    );
    $catStmt->execute(['ts1' => $mixTs, 'window1' => $stalenessMinutes, 'ts2' => $mixTs]);
    $prevStmt = $pdo->prepare(
        'SELECT mw FROM readings_us_generation
         WHERE category = :cat AND ts <= :ts1 AND ts >= DATE_SUB(:ts2, INTERVAL :window1 MINUTE)
         ORDER BY ts DESC LIMIT 1'
    );
    foreach ($catStmt->fetchAll(PDO::FETCH_COLUMN) as $cat) {
        if (array_key_exists($cat, $mix)) {
            continue;
        }
        $prevStmt->execute(['cat' => $cat, 'ts1' => $mixTs, 'ts2' => $mixTs, 'window1' => $stalenessMinutes]);
        $v = $prevStmt->fetchColumn();
        if ($v !== false && $v !== null) {
            $mix[$cat] = (float) $v;
        }
    }
}

echo json_encode([
    'ok' => true,
    'live' => true,
    'stale' => $isStale,
    'ts' => gmdate('Y-m-d\TH:i:s\Z', strtotime($latestTs)),
    'demand_mw' => $demandMw !== false ? (float) $demandMw : null,
    'generation_mw' => $genTotal !== null ? (float) $genTotal : null,
    'interconnection_mw' => $interconnection !== null ? (float) $interconnection : null,
    'mix_mw' => empty($mix) ? new stdClass() : $mix,
    'mix_ts' => $mixTs !== null ? gmdate('Y-m-d\TH:i:s\Z', strtotime($mixTs)) : null,
]);
