<?php
/**
 * Great Britain: what's coming, grid frequency and the cost of balancing.
 *
 * Three jobs (added November 2026):
 *   FORECAST   every 30 minutes, 3 requests.
 *              - Carbon Intensity API (NESO and the University of Oxford,
 *                CC BY 4.0): the 48-hour carbon intensity forecast for
 *                Great Britain and each of the 14 network areas, plus each
 *                area's current generation mix. One request
 *                (/regional/intensity/{from}/fw48h, about 650 KB).
 *              - Elexon Insights: the national demand forecast (NDF, half
 *                hourly, about 2 days ahead) and the wind generation
 *                forecast (WINDFOR, hourly, about 2 days ahead, for wind
 *                farms metered by the transmission network).
 *   FREQ       every 5 minutes, 1 request. Elexon's system frequency
 *              readings (FREQ), one every 15 seconds. Kept for 7 days.
 *   BALANCING  every 12 hours, up to 4 requests. NESO's Daily Balancing
 *              Costs (BSUoS) data: what NESO spent balancing the system,
 *              by category, per settlement period. Stored as daily
 *              totals. NESO publishes each day a day or two later.
 */

function ukgrid_outlook_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS ci_forecast (
        ts DATETIME NOT NULL COMMENT 'UTC, start of the half hour',
        region TINYINT UNSIGNED NOT NULL COMMENT 'Carbon Intensity API regionid: 1-14 network areas, 15 England, 16 Scotland, 17 Wales, 18 GB',
        gco2 SMALLINT NULL,
        idx VARCHAR(12) NULL,
        PRIMARY KEY (ts, region)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ci_region_mix (
        region TINYINT UNSIGNED NOT NULL,
        ts DATETIME NOT NULL,
        mix_json VARCHAR(600) NOT NULL,
        PRIMARY KEY (region)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS grid_forecast (
        ts DATETIME NOT NULL COMMENT 'UTC',
        item VARCHAR(8) NOT NULL COMMENT 'nd = national demand, tsd = transmission system demand, wind = wind generation',
        mw INT NOT NULL,
        published DATETIME NULL,
        PRIMARY KEY (ts, item)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS grid_frequency (
        ts DATETIME NOT NULL COMMENT 'UTC',
        hz DECIMAL(6,3) NOT NULL,
        PRIMARY KEY (ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS balancing_daily (
        dt DATE NOT NULL,
        item VARCHAR(40) NOT NULL,
        cost DECIMAL(14,2) NOT NULL COMMENT 'pounds',
        PRIMARY KEY (dt, item)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** The 14 network areas, by Carbon Intensity API regionid, with the matching id in assets/maps/gb-dno-ll.json. */
function ukgrid_ci_regions(): array
{
    return [
        1 => ['North Scotland', 'P'], 2 => ['South Scotland', 'N'], 3 => ['North West England', 'G'],
        4 => ['North East England', 'F'], 5 => ['Yorkshire', 'M'], 6 => ['North Wales and Merseyside', 'D'],
        7 => ['South Wales', 'K'], 8 => ['West Midlands', 'E'], 9 => ['East Midlands', 'B'],
        10 => ['East England', 'A'], 11 => ['South West England', 'L'], 12 => ['South England', 'H'],
        13 => ['London', 'C'], 14 => ['South East England', 'J'],
        15 => ['England', null], 16 => ['Scotland', null], 17 => ['Wales', null], 18 => ['Great Britain', null],
    ];
}

function ukgrid_ingest_forecast(PDO $pdo, array $config, int $timeoutSeconds = 15): array
{
    ukgrid_outlook_ensure_tables($pdo);
    $ci = rtrim($config['sources']['carbon_intensity_base'] ?? 'https://api.carbonintensity.org.uk', '/');
    $elexon = rtrim($config['sources']['elexon_base'] ?? 'https://data.elexon.co.uk/bmrs/api/v1', '/');
    $rows = 0;
    $notes = [];
    $errors = [];

    // 1. Carbon intensity, every area, next 48 hours.
    $from = gmdate('Y-m-d\TH:', time()) . (gmdate('i') < 30 ? '00' : '30') . 'Z';
    $data = ukgrid_http_get_json($ci . '/regional/intensity/' . $from . '/fw48h', $timeoutSeconds);
    if ($data === null || !is_array($data['data'] ?? null)) {
        $errors[] = 'carbon intensity forecast request failed';
    } else {
        $st = $pdo->prepare('INSERT INTO ci_forecast (ts, region, gco2, idx) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE gco2 = VALUES(gco2), idx = VALUES(idx)');
        $mixSt = $pdo->prepare('INSERT INTO ci_region_mix (region, ts, mix_json) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE ts = VALUES(ts), mix_json = VALUES(mix_json)');
        $n = 0;
        foreach ($data['data'] as $i => $slot) {
            $ts = ukgrid_iso_to_mysql((string) ($slot['from'] ?? ''));
            if ($ts === null) {
                continue;
            }
            foreach (($slot['regions'] ?? []) as $r) {
                $id = (int) ($r['regionid'] ?? 0);
                if ($id < 1 || $id > 18) {
                    continue;
                }
                $g = $r['intensity']['forecast'] ?? null;
                $st->execute([$ts, $id, is_numeric($g) ? (int) $g : null, isset($r['intensity']['index']) ? substr((string) $r['intensity']['index'], 0, 12) : null]);
                $n++;
                if ($i === 0 && is_array($r['generationmix'] ?? null)) {
                    $mix = [];
                    foreach ($r['generationmix'] as $m) {
                        if (isset($m['fuel'], $m['perc']) && is_numeric($m['perc'])) {
                            $mix[substr((string) $m['fuel'], 0, 12)] = round((float) $m['perc'], 1);
                        }
                    }
                    $mixSt->execute([$id, $ts, json_encode($mix)]);
                }
            }
        }
        $rows += $n;
        $notes[] = 'carbon: ' . count($data['data']) . ' half hours';
    }

    // 2. National demand forecast and 3. wind forecast (Elexon).
    $f = gmdate('Y-m-d\TH:i:s\Z', time() - 1800);
    $t = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 3600);
    $fst = $pdo->prepare('INSERT INTO grid_forecast (ts, item, mw, published) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE mw = VALUES(mw), published = VALUES(published)');
    $ndf = ukgrid_http_get_json($elexon . '/forecast/demand/day-ahead/latest?from=' . $f . '&to=' . $t . '&format=json', $timeoutSeconds);
    if ($ndf === null || !is_array($ndf['data'] ?? null)) {
        $errors[] = 'demand forecast request failed';
    } else {
        $n = 0;
        foreach ($ndf['data'] as $p) {
            $ts = ukgrid_iso_to_mysql((string) ($p['startTime'] ?? ''));
            if ($ts === null) {
                continue;
            }
            $pub = isset($p['publishTime']) ? ukgrid_iso_to_mysql((string) $p['publishTime']) : null;
            if (is_numeric($p['nationalDemand'] ?? null)) {
                $fst->execute([$ts, 'nd', (int) $p['nationalDemand'], $pub]);
                $n++;
            }
            if (is_numeric($p['transmissionSystemDemand'] ?? null)) {
                $fst->execute([$ts, 'tsd', (int) $p['transmissionSystemDemand'], $pub]);
                $n++;
            }
        }
        $rows += $n;
        $notes[] = 'demand: ' . count($ndf['data']) . ' points';
    }
    $wind = ukgrid_http_get_json($elexon . '/forecast/generation/wind/latest?from=' . $f . '&to=' . $t . '&format=json', $timeoutSeconds);
    if ($wind === null || !is_array($wind['data'] ?? null)) {
        $errors[] = 'wind forecast request failed';
    } else {
        $n = 0;
        foreach ($wind['data'] as $p) {
            $ts = ukgrid_iso_to_mysql((string) ($p['startTime'] ?? ''));
            if ($ts === null || !is_numeric($p['generation'] ?? null)) {
                continue;
            }
            $fst->execute([$ts, 'wind', (int) $p['generation'], isset($p['publishTime']) ? ukgrid_iso_to_mysql((string) $p['publishTime']) : null]);
            $n++;
        }
        $rows += $n;
        $notes[] = 'wind: ' . $n . ' points';
    }

    $pdo->exec("DELETE FROM ci_forecast WHERE ts < UTC_TIMESTAMP() - INTERVAL 3 DAY");
    $pdo->exec("DELETE FROM grid_forecast WHERE ts < UTC_TIMESTAMP() - INTERVAL 3 DAY");
    $ok = count($errors) < 3;
    ukgrid_log_ingest('FORECAST', $ok ? 'OK' : 'ERROR', $rows, implode('; ', array_merge($notes, $errors)) . ($errors ? ' (see refresh.log)' : ''));
    return ['ok' => $ok, 'rows' => $rows];
}

function ukgrid_ingest_frequency(PDO $pdo, array $config, int $timeoutSeconds = 8): array
{
    ukgrid_outlook_ensure_tables($pdo);
    $elexon = rtrim($config['sources']['elexon_base'] ?? 'https://data.elexon.co.uk/bmrs/api/v1', '/');
    $last = $pdo->query('SELECT MAX(ts) FROM grid_frequency')->fetchColumn();
    // From the last reading (at most 2 hours back) to now.
    $start = $last ? max(strtotime($last . ' UTC'), time() - 7200) : time() - 3600;
    $url = $elexon . '/system/frequency?from=' . gmdate('Y-m-d\TH:i:s\Z', $start) . '&to=' . gmdate('Y-m-d\TH:i:s\Z') . '&format=json';
    $data = ukgrid_http_get_json($url, $timeoutSeconds);
    if ($data === null || !is_array($data['data'] ?? $data)) {
        ukgrid_log_ingest('FREQ', 'ERROR', 0, 'Elexon frequency request failed (see refresh.log)');
        return ['ok' => false];
    }
    $points = $data['data'] ?? $data;
    $st = $pdo->prepare('INSERT IGNORE INTO grid_frequency (ts, hz) VALUES (?, ?)');
    $n = 0;
    foreach ($points as $p) {
        $ts = ukgrid_iso_to_mysql((string) ($p['measurementTime'] ?? ''));
        $hz = $p['frequency'] ?? null;
        // A real reading is always close to 50 Hz; anything else is a fault in the feed.
        if ($ts === null || !is_numeric($hz) || $hz < 48 || $hz > 52) {
            continue;
        }
        $st->execute([$ts, round((float) $hz, 3)]);
        $n += $st->rowCount();
    }
    $pdo->exec("DELETE FROM grid_frequency WHERE ts < UTC_TIMESTAMP() - INTERVAL 7 DAY");
    ukgrid_log_ingest('FREQ', 'OK', $n, count($points) . ' readings received');
    return ['ok' => true, 'rows' => $n];
}

function ukgrid_ingest_balancing(PDO $pdo, array $config, int $timeoutSeconds = 15): array
{
    ukgrid_outlook_ensure_tables($pdo);
    $api = rtrim($config['sources']['neso_api_base'] ?? 'https://api.neso.energy/api/3/action', '/');
    $pkg = ukgrid_http_get_json($api . '/package_show?id=daily-balancing-costs-balancing-services-use-of-system', $timeoutSeconds);
    $resources = $pkg['result']['resources'] ?? null;
    if (!is_array($resources)) {
        ukgrid_log_ingest('BALANCING', 'ERROR', 0, 'NESO package_show failed (see refresh.log)');
        return ['ok' => false];
    }
    // One resource per financial year ("Daily Balancing Costs 2026-2027").
    // The newest two cover the last 12 months; the older one is only
    // fetched until it is stored.
    $years = [];
    foreach ($resources as $r) {
        if (!empty($r['datastore_active']) && preg_match('/(\d{4})-(\d{4})/', (string) ($r['name'] ?? ''), $m)) {
            $years[(int) $m[1]] = $r['id'];
        }
    }
    krsort($years);
    $years = array_slice($years, 0, 2, true);
    if (!$years) {
        ukgrid_log_ingest('BALANCING', 'ERROR', 0, 'No yearly resources found in the NESO package');
        return ['ok' => false];
    }
    $st = $pdo->prepare('INSERT INTO balancing_daily (dt, item, cost) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE cost = VALUES(cost)');
    $rows = 0;
    $notes = [];
    $first = true;
    foreach ($years as $year => $rid) {
        if (!$first) {
            $have = $pdo->prepare('SELECT COUNT(DISTINCT dt) FROM balancing_daily WHERE dt BETWEEN ? AND ?');
            $have->execute([$year . '-04-01', ($year + 1) . '-03-31']);
            if ((int) $have->fetchColumn() >= 360) {
                continue;
            }
        }
        $first = false;
        $probe = ukgrid_http_get_json($api . '/datastore_search?resource_id=' . urlencode($rid) . '&limit=1', $timeoutSeconds);
        $fields = array_map(function ($f) { return (string) $f['id']; }, $probe['result']['fields'] ?? []);
        $dateField = null;
        $costFields = [];
        foreach ($fields as $f) {
            if (preg_match('/^sett(lement)?_?date$/i', $f)) {
                $dateField = $f;
            } elseif (!preg_match('/^(_id|_full_text|sett(lement)?_?period)$/i', $f) && preg_match('/^[A-Za-z][A-Za-z0-9 _-]{1,38}$/', $f)) {
                $costFields[] = $f;
            }
        }
        if ($dateField === null || !$costFields) {
            $notes[] = $year . ': columns not recognised (' . implode(', ', $fields) . ')';
            continue;
        }
        // The columns are numeric already (NESO's SQL endpoint doesn't allow CAST).
        $sums = implode(', ', array_map(function ($f) { return 'SUM("' . $f . '") AS "' . $f . '"'; }, $costFields));
        $sql = 'SELECT "' . $dateField . '" AS d, COUNT(*) AS n_periods, ' . $sums . ' FROM "' . $rid . '" GROUP BY "' . $dateField . '" ORDER BY "' . $dateField . '"';
        $res = ukgrid_http_get_json($api . '/datastore_search_sql?sql=' . rawurlencode($sql), $timeoutSeconds);
        $recs = $res['result']['records'] ?? null;
        if (!is_array($recs)) {
            $notes[] = $year . ': request failed';
            continue;
        }
        foreach ($recs as $rec) {
            $t = strtotime((string) ($rec['d'] ?? ''));
            // Only whole days: 46 periods on the day the clocks go forward,
            // 48 normally, 50 when they go back. A day still being filled
            // in is left until it is complete.
            if (!$t || (int) ($rec['n_periods'] ?? 0) < 46) {
                continue;
            }
            foreach ($costFields as $f) {
                if (is_numeric($rec[$f] ?? null)) {
                    $st->execute([gmdate('Y-m-d', $t), substr($f, 0, 40), round((float) $rec[$f], 2)]);
                    $rows++;
                }
            }
        }
        $notes[] = $year . '-' . ($year + 1) . ': ' . count($recs) . ' days';
    }
    ukgrid_log_ingest('BALANCING', $rows ? 'OK' : 'ERROR', $rows, implode('; ', $notes));
    return ['ok' => $rows > 0, 'rows' => $rows];
}
