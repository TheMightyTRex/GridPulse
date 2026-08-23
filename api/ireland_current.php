<?php
/**
 * GET api/ireland_current.php
 *
 * Latest available all-island (Republic of Ireland + Northern Ireland)
 * snapshot: demand, total generation, wind generation, and net
 * interconnection with GB. Source: EirGrid's Smart Grid Dashboard - see
 * includes/ingest.php's ukgrid_ingest_eirgrid().
 *
 * There's no confirmed public source for a finer fuel-type breakdown (gas,
 * solar, hydro, battery individually) or for SEM wholesale price via a
 * keyless API, so those aren't included here - pages/ireland.html's mix
 * donut/table and SEM price stat stay on placeholder data for now. See
 * pages/data-sources.html for what's live and what isn't.
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
 *     "emissions_gco2": 178
 *   }
 *
 * If there's no data yet, "ok" stays true but "live" is false and every
 * numeric field is null, matching api/current.php's convention - the
 * frontend shows placeholder data in that case rather than a blank page.
 */

require __DIR__ . '/_bootstrap.php';

$pdo = ukgrid_db();
$config = ukgrid_load_config();
$stalenessMinutes = (int) ($config['staleness_minutes'] ?? 90);

$latestTs = $pdo->query('SELECT MAX(ts) FROM readings_ie_demand')->fetchColumn();

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
]);
