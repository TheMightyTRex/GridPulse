<?php
/**
 * Grid Pulse - cross-border flows and yearly generation history.
 *
 * Both make slow outside requests, so only the scheduler runs them
 * (cron/run-due.php: GitHub Actions or the Docker container), a little
 * at a time, never a visitor's page load.
 *
 * FLOWS - physical flows across each border between the European
 * countries on this site and their neighbours, from the ENTSO-E
 * Transparency Platform ("Cross-border physical flows", document A11).
 * One border (both directions, two requests) per run, oldest first, so
 * each border is updated every few hours. Stored in readings_flow.
 *
 * ANNUAL - generation by fuel for each year, from official statistics:
 *   - European countries and Ireland: Eurostat, "Production of
 *     electricity and derived heat by type of fuel" (nrg_bal_peh, gross
 *     electricity production). Whole countries, so Norway, Sweden and
 *     Denmark are the whole country rather than the one price zone the
 *     live pages show, and Ireland is the Republic of Ireland only.
 *   - Great Britain: NESO Data Portal, "Historic generation mix".
 *   - USA: EIA, "Electric power operational data" (all sectors).
 *   - Ontario: IESO, yearly "Generator output by fuel type" reports.
 *   - Australia (NEM): Open Electricity, yearly energy by fuel
 *     (CC BY-NC 4.0, like the rest of that page).
 * One area per run; each area is refreshed every 30 days. Stored in
 * annual_mix. tools/annual-check.php shows what each source returned.
 */

/** Creates the tables these two jobs use, if missing. */
function ukgrid_flows_annual_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS readings_flow (
        ts DATETIME NOT NULL COMMENT 'UTC',
        from_area VARCHAR(4) NOT NULL,
        to_area VARCHAR(4) NOT NULL,
        mw DECIMAL(10,2) NOT NULL,
        PRIMARY KEY (ts, from_area, to_area),
        KEY idx_to (to_area, ts),
        KEY idx_from (from_area, ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fetch_state (
        job VARCHAR(10) NOT NULL,
        item VARCHAR(20) NOT NULL,
        fetched_at DATETIME NOT NULL,
        status VARCHAR(10) NOT NULL,
        message TEXT NULL,
        PRIMARY KEY (job, item)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS annual_mix (
        area VARCHAR(4) NOT NULL,
        year SMALLINT NOT NULL,
        grp VARCHAR(20) NOT NULL,
        gwh DECIMAL(12,1) NOT NULL,
        source VARCHAR(20) NOT NULL,
        PRIMARY KEY (area, year, grp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function ukgrid_fetch_state_get(PDO $pdo, string $job): array
{
    $st = $pdo->prepare('SELECT item, fetched_at, status, message FROM fetch_state WHERE job = :j');
    $st->execute(['j' => $job]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['item']] = $r;
    }
    return $out;
}

function ukgrid_fetch_state_set(PDO $pdo, string $job, string $item, string $status, string $message): void
{
    $pdo->prepare('INSERT INTO fetch_state (job, item, fetched_at, status, message) VALUES (:j, :i, UTC_TIMESTAMP(), :s, :m)
        ON DUPLICATE KEY UPDATE fetched_at = VALUES(fetched_at), status = VALUES(status), message = VALUES(message)')
        ->execute(['j' => $job, 'i' => $item, 's' => $status, 'm' => mb_substr($message, 0, 2000)]);
}

// ---------------------------------------------------------------------------
// Cross-border flows
// ---------------------------------------------------------------------------

/** ENTSO-E area codes for this site's countries and their neighbours. */
function ukgrid_flow_zones(): array
{
    return [
        'FR' => ['10YFR-RTE------C', 'France'], 'NL' => ['10YNL----------L', 'Netherlands'], 'BE' => ['10YBE----------2', 'Belgium'],
        'NO' => ['10YNO-2--------T', 'Norway (NO2)'], 'DK' => ['10YDK-1--------W', 'Denmark (DK1, west)'],
        'DE' => ['10Y1001A1001A82H', 'Germany'], 'ES' => ['10YES-REE------0', 'Spain'], 'IT' => ['10YIT-GRTN-----B', 'Italy'],
        'SE' => ['10Y1001A1001A46L', 'Sweden (SE3)'], 'PT' => ['10YPT-REN------W', 'Portugal'], 'PL' => ['10YPL-AREA-----S', 'Poland'],
        'CH' => ['10YCH-SWISSGRIDZ', 'Switzerland'], 'AT' => ['10YAT-APG------L', 'Austria'], 'CZ' => ['10YCZ-CEPS-----N', 'Czechia'],
        'SK' => ['10YSK-SEPS-----K', 'Slovakia'], 'LT' => ['10YLT-1001A0008Q', 'Lithuania'], 'SI' => ['10YSI-ELES-----O', 'Slovenia'],
        'GR' => ['10YGR-HTSO-----Y', 'Greece'], 'FI' => ['10YFI-1--------U', 'Finland'], 'NO1' => ['10YNO-1--------2', 'Norway (NO1, Oslo)'],
        'NO5' => ['10Y1001A1001A48H', 'Norway (NO5, Bergen)'], 'DK2' => ['10YDK-2--------M', 'Denmark (DK2, east)'],
        'SE2' => ['10Y1001A1001A45N', 'Sweden (SE2)'], 'SE4' => ['10Y1001A1001A47J', 'Sweden (SE4, south)'],
    ];
}

/** Borders as [a, b] pairs. Each is fetched in both directions. */
function ukgrid_flow_borders(array $config): array
{
    $borders = $config['refresh']['flow_borders'] ?? [
        ['FR', 'BE'], ['FR', 'DE'], ['FR', 'ES'], ['FR', 'IT'], ['FR', 'CH'],
        ['NL', 'BE'], ['NL', 'DE'], ['NL', 'NO'], ['NL', 'DK'],
        ['BE', 'DE'],
        ['NO', 'DE'], ['NO', 'DK'], ['NO', 'NO1'], ['NO', 'NO5'],
        ['DK', 'DE'], ['DK', 'SE'], ['DK', 'DK2'],
        ['DE', 'PL'], ['DE', 'CZ'], ['DE', 'AT'], ['DE', 'CH'], ['DE', 'DK2'], ['DE', 'SE4'],
        ['ES', 'PT'],
        ['IT', 'CH'], ['IT', 'AT'], ['IT', 'SI'], ['IT', 'GR'],
        ['SE', 'NO1'], ['SE', 'FI'], ['SE', 'SE2'], ['SE', 'SE4'],
        ['PL', 'CZ'], ['PL', 'SK'], ['PL', 'LT'], ['PL', 'SE4'],
    ];
    $configured = $config['sources']['entsoe_countries'] ?? [];
    return array_values(array_filter($borders, function ($b) use ($configured) {
        return isset($configured[$b[0]]) || isset($configured[$b[1]]);
    }));
}

/** The FLOWS job: fetches the border(s) updated longest ago. */
function ukgrid_ingest_flows(PDO $pdo, array $config, int $timeoutSeconds = 10, ?string $onlyBorder = null): array
{
    ukgrid_flows_annual_ensure_tables($pdo);
    $token = trim((string) ($config['sources']['entsoe_api_token'] ?? ''));
    if ($token === '' || $token === 'CHANGE-ME') {
        ukgrid_log_ingest('FLOWS', 'OK', 0, 'entsoe_api_token not set - skipping.');
        return ['rows' => 0, 'errors' => []];
    }
    $base = rtrim($config['sources']['entsoe_base'] ?? 'https://web-api.tp.entsoe.eu/api', '/');
    $zones = ukgrid_flow_zones();
    $state = ukgrid_fetch_state_get($pdo, 'FLOWS');
    $minAge = (int) ($config['refresh']['flows_min_age_minutes'] ?? 60);
    $perRun = max(1, (int) ($config['refresh']['flows_borders_per_run'] ?? 1));

    $due = [];
    foreach (ukgrid_flow_borders($config) as [$a, $b]) {
        if (!isset($zones[$a], $zones[$b])) {
            continue;
        }
        $key = $a . '-' . $b;
        $last = isset($state[$key]) ? strtotime($state[$key]['fetched_at'] . ' UTC') : 0;
        if ($onlyBorder !== null ? $key === $onlyBorder : $last < time() - $minAge * 60) {
            $due[$key] = $last;
        }
    }
    asort($due);
    $due = array_slice(array_keys($due), 0, $perRun);
    if (!$due) {
        ukgrid_log_ingest('FLOWS', 'OK', 0, 'every border was updated within the last ' . $minAge . ' minutes.');
        return ['rows' => 0, 'errors' => []];
    }

    // Yesterday 00:00 UTC to the end of this hour covers "today" in every
    // European time zone, plus late corrections to yesterday.
    $start = gmdate('Ymd', time() - 86400) . '0000';
    $end = gmdate('YmdH', time() + 3600) . '00';
    $ins = $pdo->prepare('INSERT INTO readings_flow (ts, from_area, to_area, mw) VALUES (:ts, :f, :t, :mw) ON DUPLICATE KEY UPDATE mw = VALUES(mw)');
    $rows = 0;
    $errors = [];
    foreach ($due as $key) {
        [$a, $b] = explode('-', $key);
        $msgs = [];
        $pairRows = 0;
        foreach ([[$a, $b], [$b, $a]] as [$from, $to]) {
            $url = $base . '?securityToken=' . urlencode($token) . '&documentType=A11'
                . '&out_Domain=' . urlencode($zones[$from][0]) . '&in_Domain=' . urlencode($zones[$to][0])
                . '&periodStart=' . $start . '&periodEnd=' . $end;
            $body = ukgrid_http_get_raw($url, $timeoutSeconds);
            if ($body === null) {
                $msgs[] = "{$from}->{$to}: request failed";
                continue;
            }
            $parsed = ukgrid_entsoe_parse_points($body, 'quantity');
            if (!$parsed['ok']) {
                $msgs[] = "{$from}->{$to}: " . $parsed['error'];
                continue;
            }
            foreach ($parsed['points'] as $pt) {
                $ins->execute(['ts' => $pt['ts'], 'f' => $from, 't' => $to, 'mw' => $pt['value']]);
                $pairRows++;
            }
        }
        $rows += $pairRows;
        ukgrid_fetch_state_set($pdo, 'FLOWS', $key, $pairRows > 0 ? 'OK' : 'ERROR', implode('; ', $msgs));
        if ($pairRows === 0) {
            $errors[] = $key . ': ' . implode('; ', $msgs);
        }
    }
    ukgrid_log_ingest('FLOWS', $rows > 0 ? 'OK' : 'ERROR', $rows, 'borders: ' . implode(', ', $due) . ($errors ? ' - ' . implode(' | ', $errors) : ''));
    return ['rows' => $rows, 'errors' => $errors];
}

// ---------------------------------------------------------------------------
// Yearly generation history
// ---------------------------------------------------------------------------

/** Which yearly source each area uses, and a plain description of what it covers. */
function ukgrid_annual_sources(): array
{
    $eu = function (string $geo, string $scope) {
        return ['kind' => 'eurostat', 'geo' => $geo, 'scope' => $scope,
                'credit' => 'Eurostat, nrg_bal_peh (gross electricity production)', 'url' => 'https://ec.europa.eu/eurostat/databrowser/view/nrg_bal_peh/default/table'];
    };
    return [
        'GB' => ['kind' => 'neso', 'scope' => 'Great Britain', 'credit' => 'NESO Data Portal, Historic generation mix', 'url' => 'https://www.neso.energy/data-portal/historic-generation-mix'],
        'IE' => $eu('IE', 'Republic of Ireland (the live page covers the whole island)'),
        'FR' => $eu('FR', 'France'), 'NL' => $eu('NL', 'Netherlands'), 'BE' => $eu('BE', 'Belgium'),
        'NO' => $eu('NO', 'the whole of Norway (the live page shows the NO2 price zone)'),
        'DK' => $eu('DK', 'the whole of Denmark (the live page shows DK1, western Denmark)'),
        'DE' => $eu('DE', 'Germany'), 'ES' => $eu('ES', 'Spain'), 'IT' => $eu('IT', 'Italy'),
        'SE' => $eu('SE', 'the whole of Sweden (the live page shows the SE3 price zone)'),
        'PT' => $eu('PT', 'Portugal'), 'PL' => $eu('PL', 'Poland'),
        'US' => ['kind' => 'eia', 'scope' => 'the whole USA, all sectors', 'credit' => 'U.S. EIA, Electric power operational data', 'url' => 'https://www.eia.gov/electricity/data/browser/'],
        'CA' => ['kind' => 'ieso', 'scope' => 'Ontario', 'credit' => 'IESO, Generator output by fuel type (yearly reports)', 'url' => 'https://reports-public.ieso.ca/public/GenOutputbyFuelHourly/'],
        'AU' => ['kind' => 'openelectricity', 'scope' => 'the National Electricity Market (not Western Australia)', 'credit' => 'Open Electricity (CC BY-NC 4.0)', 'url' => 'https://openelectricity.org.au/'],
    ];
}

/** The ANNUAL job: refreshes the area whose yearly figures are oldest. */
function ukgrid_ingest_annual(PDO $pdo, array $config, int $timeoutSeconds = 15, ?string $only = null): array
{
    ukgrid_flows_annual_ensure_tables($pdo);
    $areas = ukgrid_areas($config);
    $sources = ukgrid_annual_sources();
    $state = ukgrid_fetch_state_get($pdo, 'ANNUAL');
    $refreshDays = (int) ($config['refresh']['annual_refresh_days'] ?? 30);

    $pick = null;
    $oldest = PHP_INT_MAX;
    foreach ($areas as $code => $area) {
        if (!isset($sources[$code]) || ($only !== null && $code !== $only)) {
            continue;
        }
        $s = $state[$code] ?? null;
        $last = $s ? strtotime($s['fetched_at'] . ' UTC') : 0;
        // Retry a failure after a day; refresh a success every $refreshDays.
        // Ontario fills one year per run until it has every year.
        $wait = $s && $s['status'] === 'OK' ? ($s['message'] === 'more' ? 0 : $refreshDays * 86400) : 86400;
        if (($only !== null || $last + $wait <= time()) && $last < $oldest) {
            $oldest = $last;
            $pick = $code;
        }
    }
    if ($pick === null) {
        ukgrid_log_ingest('ANNUAL', 'OK', 0, 'every area is up to date.');
        return ['rows' => 0, 'errors' => []];
    }

    $src = $sources[$pick];
    try {
        switch ($src['kind']) {
            case 'eurostat': $res = ukgrid_annual_eurostat($src['geo'], $timeoutSeconds); break;
            case 'neso': $res = ukgrid_annual_neso($config, $timeoutSeconds); break;
            case 'eia': $res = ukgrid_annual_eia($config, $timeoutSeconds); break;
            case 'ieso': $res = ukgrid_annual_ieso($pdo, $timeoutSeconds); break;
            case 'openelectricity': $res = ukgrid_annual_openelectricity($config, $timeoutSeconds); break;
            default: $res = ['ok' => false, 'error' => 'unknown source', 'years' => []];
        }
    } catch (Throwable $e) {
        $res = ['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage(), 'years' => []];
    }

    $rows = 0;
    if (!empty($res['years'])) {
        $ins = $pdo->prepare('INSERT INTO annual_mix (area, year, grp, gwh, source) VALUES (:a, :y, :g, :v, :s) ON DUPLICATE KEY UPDATE gwh = VALUES(gwh), source = VALUES(source)');
        foreach ($res['years'] as $year => $groups) {
            if (empty($res['keep_old'])) {
                $pdo->prepare('DELETE FROM annual_mix WHERE area = :a AND year = :y')->execute(['a' => $pick, 'y' => $year]);
            }
            foreach ($groups as $g => $gwh) {
                if ($gwh === null || !is_finite((float) $gwh)) {
                    continue;
                }
                $ins->execute(['a' => $pick, 'y' => (int) $year, 'g' => $g, 'v' => round((float) $gwh, 1), 's' => $src['kind']]);
                $rows++;
            }
        }
    }
    $ok = $rows > 0 || !empty($res['ok']);
    $message = $ok ? (!empty($res['more']) ? 'more' : 'years: ' . implode(', ', array_keys($res['years'] ?? []))) : (string) ($res['error'] ?? 'no data');
    ukgrid_fetch_state_set($pdo, 'ANNUAL', $pick, $ok ? 'OK' : 'ERROR', $message);
    ukgrid_log_ingest('ANNUAL', $ok ? 'OK' : 'ERROR', $rows, $pick . ' (' . $src['kind'] . '): ' . ($ok ? $rows . ' figures' : $message));
    return ['rows' => $rows, 'errors' => $ok ? [] : [$message]];
}

/** Turns mapped totals plus a grand total into groups, with "other" taking the rest. */
function ukgrid_annual_finish(array $groups, ?float $total): array
{
    $groups = array_filter($groups, function ($v) { return $v !== null && $v > 0; });
    if ($total !== null) {
        $rest = $total - array_sum($groups);
        if ($rest > 0.5) {
            $groups['other'] = ($groups['other'] ?? 0) + $rest;
        }
    }
    return $groups;
}

/**
 * Eurostat JSON-stat 2.0. Values are flattened row-major over the
 * dimensions in "id"; dimensions other than fuel (siec) and year are
 * reduced to their total (TOTAL, or the code every other code starts with,
 * or the sum when they split the total).
 */
function ukgrid_annual_eurostat(string $geo, int $timeout): array
{
    $url = 'https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/nrg_bal_peh?format=JSON&lang=en'
        . '&geo=' . urlencode($geo) . '&unit=GWH&nrg_bal=GEP';
    $j = ukgrid_http_get_json($url, $timeout);
    if (!$j || empty($j['id']) || empty($j['dimension']) || !isset($j['value'])) {
        return ['ok' => false, 'error' => 'Eurostat request failed or the reply was not JSON-stat' . ($j && isset($j['error']) ? ': ' . json_encode($j['error']) : ''), 'years' => []];
    }
    $ids = $j['id'];
    $sizes = $j['size'];
    $codes = []; // dim => [pos => code]
    foreach ($ids as $d) {
        $idx = $j['dimension'][$d]['category']['index'] ?? [];
        $isList = array_keys($idx) === range(0, count($idx) - 1) && !is_numeric(reset($idx));
        $codes[$d] = $isList ? $idx : array_flip($idx);
        ksort($codes[$d]);
    }
    // Which codes count for the "other" dimensions.
    $keep = [];
    foreach ($ids as $d) {
        if ($d === 'siec' || $d === 'time') {
            continue;
        }
        $list = array_values($codes[$d]);
        if (in_array('TOTAL', $list, true)) {
            $keep[$d] = ['TOTAL'];
        } elseif (count($list) === 1) {
            $keep[$d] = $list;
        } else {
            $agg = null;
            foreach ($list as $c) {
                $isPrefix = true;
                foreach ($list as $o) {
                    if ($o !== $c && strpos($o, $c) !== 0) { $isPrefix = false; break; }
                }
                if ($isPrefix) { $agg = $c; break; }
            }
            $keep[$d] = $agg !== null ? [$agg] : $list;
        }
    }
    $vals = []; // [siec][year] => GWh
    $n = count($ids);
    foreach ($j['value'] as $flat => $v) {
        if ($v === null || !is_numeric($v)) {
            continue;
        }
        $rem = (int) $flat;
        $pos = array_fill(0, $n, 0);
        for ($k = $n - 1; $k >= 0; $k--) {
            $pos[$k] = $rem % $sizes[$k];
            $rem = intdiv($rem, $sizes[$k]);
        }
        $siec = $year = null;
        $skip = false;
        foreach ($ids as $k => $d) {
            $c = $codes[$d][$pos[$k]] ?? null;
            if ($d === 'siec') { $siec = $c; }
            elseif ($d === 'time') { $year = $c; }
            elseif (!in_array($c, $keep[$d], true)) { $skip = true; break; }
        }
        if ($skip || $siec === null || $year === null || !ctype_digit((string) $year)) {
            continue;
        }
        $vals[$siec][$year] = ($vals[$siec][$year] ?? 0) + (float) $v;
    }
    if (empty($vals['TOTAL'])) {
        return ['ok' => false, 'error' => 'Eurostat reply had no TOTAL fuel row. Fuel codes seen: ' . implode(', ', array_slice(array_keys($vals), 0, 40)), 'years' => []];
    }
    $get = function (array $list, $year) use ($vals) {
        $s = null;
        foreach ($list as $c) {
            if (isset($vals[$c][$year])) {
                $s = ($s ?? 0) + $vals[$c][$year];
            }
        }
        return $s;
    };
    $years = [];
    foreach (array_keys($vals['TOTAL']) as $y) {
        $hydro = $get(['RA110', 'RA120'], $y);
        if ($hydro === null) {
            $all = $get(['RA100'], $y);
            $hydro = $all !== null ? $all - ($get(['RA130'], $y) ?? 0) : null;
        }
        $wind = $get(['RA300'], $y) ?? $get(['RA310', 'RA320'], $y);
        $solar = $get(['RA400'], $y) ?? $get(['RA410', 'RA420'], $y);
        // BIOE (bioenergy) is Eurostat's own total of solid biofuels, biogases,
        // liquid biofuels and renewable waste. Checked against the live API on
        // 1 November 2026 (FR: BIOE present, CF_R and RA110/RA120/RA400 absent).
        $bio = $get(['BIOE'], $y) ?? $get(['R5110-5150_W6000RI', 'R5160', 'R5210P', 'R5210B', 'R5220P', 'R5220B', 'R5230P', 'R5230B', 'R5290', 'R5300', 'W6210'], $y);
        $years[(int) $y] = ukgrid_annual_finish([
            'coal' => $get(['C0000X0350-0370', 'C0350-0370', 'P1000'], $y),
            'oil' => $get(['O4000XBIO', 'S2000'], $y),
            'gas' => $get(['G3000'], $y),
            'nuclear' => $get(['N900H'], $y),
            'hydro' => $hydro,
            'storage' => $get(['RA130'], $y),
            'wind' => $wind,
            'solar' => $solar,
            'biomass' => $bio,
            'other_renewable' => $get(['RA200', 'RA500'], $y),
        ], (float) $vals['TOTAL'][$y]);
    }
    ksort($years);
    return ['ok' => true, 'years' => $years];
}

/** NESO historic generation mix: half-hourly MW summed into GWh per year. */
function ukgrid_annual_neso(array $config, int $timeout): array
{
    $apiBase = rtrim($config['sources']['neso_api_base'] ?? 'https://api.neso.energy/api/3/action', '/');
    $pkgId = $config['sources']['neso_historic_mix_package'] ?? 'historic-generation-mix';
    $pkg = ukgrid_http_get_json($apiBase . '/package_show?id=' . urlencode($pkgId), $timeout);
    if (!$pkg || empty($pkg['result']['resources'])) {
        return ['ok' => false, 'error' => 'NESO package_show failed for "' . $pkgId . '"', 'years' => []];
    }
    $res = null;
    foreach ($pkg['result']['resources'] as $r) {
        if (!empty($r['datastore_active']) && stripos((string) ($r['name'] ?? ''), 'mix') !== false) {
            $res = $r;
            break;
        }
    }
    foreach ($pkg['result']['resources'] as $r) {
        if ($res === null && !empty($r['datastore_active'])) {
            $res = $r;
        }
    }
    if ($res === null) {
        return ['ok' => false, 'error' => 'no datastore resource in "' . $pkgId . '"', 'years' => []];
    }
    $probe = ukgrid_http_get_json($apiBase . '/datastore_search?resource_id=' . urlencode($res['id']) . '&limit=1', $timeout);
    $fields = array_map(function ($f) { return $f['id']; }, $probe['result']['fields'] ?? []);
    $find = function (string $name) use ($fields) {
        foreach ($fields as $f) {
            if (strcasecmp($f, $name) === 0) return $f;
        }
        return null;
    };
    $dt = $find('DATETIME');
    if ($dt === null) {
        return ['ok' => false, 'error' => 'no DATETIME column. Columns: ' . implode(', ', $fields), 'years' => []];
    }
    $map = ['GAS' => 'gas', 'COAL' => 'coal', 'NUCLEAR' => 'nuclear', 'WIND' => 'wind', 'WIND_EMB' => 'wind', 'HYDRO' => 'hydro',
            'BIOMASS' => 'biomass', 'SOLAR' => 'solar', 'STORAGE' => 'storage', 'OTHER' => 'other'];
    $sel = [];
    foreach ($map as $col => $g) {
        $f = $find($col);
        if ($f !== null) {
            $sel[] = 'SUM("' . $f . '") AS "' . $col . '"';
        }
    }
    if (!$sel) {
        return ['ok' => false, 'error' => 'none of the expected fuel columns found. Columns: ' . implode(', ', $fields), 'years' => []];
    }
    // Half-hourly MW averages: MW x 0.5h = MWh, summed per year.
    // The NESO portal refuses EXTRACT() ("Not authorized to call function
    // EXTRACT") but allows date_part() - checked 1 November 2026.
    $sql = 'SELECT date_part(\'year\', "' . $dt . '") AS y, COUNT(*) AS n, ' . implode(', ', $sel)
        . ' FROM "' . $res['id'] . '" GROUP BY y ORDER BY y';
    $out = ukgrid_http_get_json($apiBase . '/datastore_search_sql?sql=' . urlencode($sql), $timeout);
    if (!$out || empty($out['success'])) {
        return ['ok' => false, 'error' => 'NESO datastore_search_sql failed', 'years' => []];
    }
    $years = [];
    foreach ($out['result']['records'] ?? [] as $r) {
        $y = (int) $r['y'];
        if ($y < 2000) {
            continue;
        }
        $groups = [];
        foreach ($map as $col => $g) {
            if (isset($r[$col]) && is_numeric($r[$col])) {
                $groups[$g] = ($groups[$g] ?? 0) + (float) $r[$col] * 0.5 / 1000;
            }
        }
        $years[$y] = ukgrid_annual_finish($groups, null);
    }
    return ['ok' => (bool) $years, 'error' => $years ? null : 'no rows', 'years' => $years];
}

/** EIA electric power operational data: thousand MWh (= GWh) by fuel, all sectors, US total. */
function ukgrid_annual_eia(array $config, int $timeout): array
{
    $key = trim((string) ($config['sources']['eia_api_key'] ?? ''));
    if ($key === '' || $key === 'CHANGE-ME') {
        return ['ok' => false, 'error' => 'eia_api_key not set', 'years' => []];
    }
    $url = 'https://api.eia.gov/v2/electricity/electric-power-operational-data/data/?api_key=' . urlencode($key)
        . '&frequency=annual&data[0]=generation&facets[location][]=US&facets[sectorid][]=99&start=2001&length=5000';
    $j = ukgrid_http_get_json($url, $timeout);
    $rows = $j['response']['data'] ?? null;
    if (!is_array($rows)) {
        return ['ok' => false, 'error' => 'EIA request failed or unexpected reply' . (isset($j['error']) ? ': ' . json_encode($j['error']) : ''), 'years' => []];
    }
    $map = ['COW' => 'coal', 'PET' => 'oil', 'NG' => 'gas', 'NUC' => 'nuclear', 'HYC' => 'hydro', 'WND' => 'wind',
            'SUN' => 'solar', 'GEO' => 'other_renewable', 'WWW' => 'biomass', 'WAS' => 'biomass'];
    $by = [];
    $total = [];
    foreach ($rows as $r) {
        $y = (int) ($r['period'] ?? 0);
        $f = (string) ($r['fueltypeid'] ?? '');
        $v = $r['generation'] ?? null;
        if (!$y || !is_numeric($v)) {
            continue;
        }
        if ($f === 'ALL') {
            $total[$y] = (float) $v;
        } elseif (isset($map[$f])) {
            $by[$y][$map[$f]] = ($by[$y][$map[$f]] ?? 0) + (float) $v;
        }
    }
    $years = [];
    foreach ($total as $y => $t) {
        $years[$y] = ukgrid_annual_finish($by[$y] ?? [], $t);
    }
    ksort($years);
    return ['ok' => (bool) $years, 'error' => $years ? null : 'no ALL (total) rows. fueltypeid values seen: ' . implode(', ', array_slice(array_unique(array_column($rows, 'fueltypeid')), 0, 40)), 'years' => $years];
}

/**
 * IESO yearly report files, one per run (each is a few megabytes): the
 * earliest year not stored yet, from 2015 to last year.
 */
function ukgrid_annual_ieso(PDO $pdo, int $timeout): array
{
    $have = $pdo->query("SELECT DISTINCT year FROM annual_mix WHERE area = 'CA'")->fetchAll(PDO::FETCH_COLUMN);
    $lastYear = (int) gmdate('Y') - 1;
    $year = null;
    for ($y = 2015; $y <= $lastYear; $y++) {
        if (!in_array((string) $y, array_map('strval', $have), true)) {
            $year = $y;
            break;
        }
    }
    if ($year === null) {
        return ['ok' => true, 'years' => [], 'keep_old' => true];
    }
    $url = 'https://reports-public.ieso.ca/public/GenOutputbyFuelHourly/PUB_GenOutputbyFuelHourly_' . $year . '.xml';
    $body = ukgrid_http_get_raw($url, max($timeout, 20));
    if ($body === null) {
        return ['ok' => false, 'error' => 'IESO ' . $year . ' report request failed', 'years' => []];
    }
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    if ($xml === false) {
        return ['ok' => false, 'error' => 'IESO ' . $year . ' report was not XML', 'years' => []];
    }
    $map = ['NUCLEAR' => 'nuclear', 'GAS' => 'gas', 'HYDRO' => 'hydro', 'WIND' => 'wind', 'SOLAR' => 'solar', 'BIOFUEL' => 'biomass', 'OTHER' => 'other'];
    $groups = [];
    foreach ($xml->DocBody->DailyData ?? [] as $daily) {
        foreach ($daily->HourlyData as $hourly) {
            foreach ($hourly->FuelTotal as $ft) {
                $fuel = (string) ($ft->Fuel ?? '');
                $out = (string) ($ft->EnergyValue->Output ?? '');
                if ($fuel !== '' && is_numeric($out)) {
                    $g = $map[$fuel] ?? 'other';
                    $groups[$g] = ($groups[$g] ?? 0) + (float) $out / 1000; // MWh per hour -> GWh
                }
            }
        }
    }
    if (!$groups) {
        return ['ok' => false, 'error' => 'IESO ' . $year . ' report had no FuelTotal rows', 'years' => []];
    }
    return ['ok' => true, 'years' => [$year => $groups], 'keep_old' => false, 'more' => $year < $lastYear];
}

/** Open Electricity: yearly energy by fuel group for the NEM. */
function ukgrid_annual_openelectricity(array $config, int $timeout): array
{
    $key = trim((string) ($config['sources']['openelectricity_api_key'] ?? ''));
    if ($key === '' || $key === 'CHANGE-ME') {
        return ['ok' => false, 'error' => 'openelectricity_api_key not set', 'years' => []];
    }
    $base = rtrim($config['sources']['openelectricity_base'] ?? 'https://api.openelectricity.org.au/v4', '/');
    $url = $base . '/data/network/NEM?metrics=energy&interval=1y&secondary_grouping=fueltech_group'
        . '&date_start=2010-01-01T00:00:00&date_end=' . urlencode(gmdate('Y-m-d\TH:i:s', time() + 10 * 3600));
    $j = ukgrid_http_get_json_auth($url, $key, $timeout);
    $results = $j['data'][0]['results'] ?? null;
    if (!is_array($results)) {
        return ['ok' => false, 'error' => 'Open Electricity request failed or unexpected reply' . (isset($j['error']) ? ': ' . $j['error'] : ''), 'years' => []];
    }
    $map = ['coal' => 'coal', 'gas' => 'gas', 'wind' => 'wind', 'solar' => 'solar', 'hydro' => 'hydro',
            'distillate' => 'oil', 'bioenergy' => 'biomass', 'battery_discharging' => 'storage'];
    $years = [];
    foreach ($results as $r) {
        $g = $r['columns']['fueltech_group'] ?? null;
        if (!isset($map[$g])) {
            continue;
        }
        foreach ($r['data'] ?? [] as $pt) {
            if (!is_array($pt) || count($pt) < 2 || !is_numeric($pt[1])) {
                continue;
            }
            $y = (int) substr((string) $pt[0], 0, 4);
            $years[$y][$map[$g]] = ($years[$y][$map[$g]] ?? 0) + abs((float) $pt[1]) / 1000; // MWh -> GWh
        }
    }
    ksort($years);
    return ['ok' => (bool) $years, 'error' => $years ? null : 'no fuel rows in reply', 'years' => $years];
}
