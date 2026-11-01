<?php
/**
 * GB battery storage, from Elexon's Insights Solution (official, keyless).
 *
 * Why this is built from individual units: Elexon's generation-by-fuel-type
 * data (FUELINST) has no battery category, and NESO's historic generation
 * mix says "Batteries (NET discharge) are currently included in the OTHER
 * category ... All Net-Negative values are curtailed at zero" (checked 29
 * October 2026). So there is no published GB battery total. This adds up
 * the battery units registered in the Balancing Mechanism instead:
 *
 *  - NOTIFIED (dataset PN, Physical Notifications): what each unit told
 *    NESO it plans to do, per settlement period, published ahead of time.
 *    Not metered, and NESO's instructions can change it. Levels are MW,
 *    negative when charging. Confirmed live 29 October 2026: E_ARNKB-1
 *    notified -28MW at 00:01-00:30 UTC and +40MW at 08:31-09:01 UTC.
 *  - METERED (dataset B1610, actual generation output per unit): what each
 *    unit actually did, in MWh per half hour, published days later (the
 *    newest on 29 October 2026 was 22 October). Negative when charging.
 *
 * Which units are batteries: Elexon's BM unit register has no battery
 * fuel type (its fuel types list has none, and B1610 labels every unit
 * "Generation"). Units are identified from the register (see
 * ukgrid_battery_is_unit()), cached in includes/battery_units.json, and
 * can be corrected with config 'battery_units' => ['include' => [...],
 * 'exclude' => [...]] (Elexon BM unit IDs). tools/battery-units.php lists
 * what was matched and why.
 *
 * Coverage: only batteries registered in the Balancing Mechanism. Smaller
 * batteries outside it (most home batteries and many small sites) are not
 * included.
 */

require_once __DIR__ . '/http.php';

function ukgrid_battery_units_path(): string
{
    return __DIR__ . '/battery_units.json';
}

function ukgrid_battery_ensure_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS readings_battery (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ts DATETIME NOT NULL COMMENT \'UTC - settlement period start\',
        kind VARCHAR(10) NOT NULL COMMENT \'NOTIFIED (PN) or METERED (B1610)\',
        net_mw DECIMAL(10,2) NOT NULL,
        charge_mw DECIMAL(10,2) NOT NULL,
        discharge_mw DECIMAL(10,2) NOT NULL,
        units SMALLINT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_ts_kind (ts, kind),
        KEY idx_kind_ts (kind, ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** UTC start of a GB settlement period (settlementDate is UK local date; SP1 starts at local midnight). */
function ukgrid_sp_start_utc(string $date, int $sp): int
{
    $midnight = new DateTime($date . ' 00:00:00', new DateTimeZone('Europe/London'));
    return $midnight->getTimestamp() + ($sp - 1) * 1800;
}

/** [settlementDate, settlementPeriod] for a UTC timestamp. */
function ukgrid_sp_for_utc(int $ts): array
{
    $local = new DateTime('@' . $ts);
    $local->setTimezone(new DateTimeZone('Europe/London'));
    $date = $local->format('Y-m-d');
    $sp = (int) floor(($ts - ukgrid_sp_start_utc($date, 1)) / 1800) + 1;
    return [$date, $sp];
}

/**
 * Is this BM unit register record a battery? Rules, in order:
 *  - never: interconnectors, supplier base units (2__), units with a
 *    conventional fuel type, pumped storage and hydro by name;
 *  - yes: "battery", "BESS" or "energy storage" in the unit or lead party name;
 *  - yes: a transmission (T_) or embedded (E_) unit whose National Grid ID
 *    ends "B-<n>" (the usual naming for battery units) AND that can import
 *    at least half as much as it can export - batteries both charge and
 *    discharge, which rules out generators such as West Burton B (WBURB).
 * Returns the reason string, or null if not a battery.
 */
function ukgrid_battery_is_unit(array $u): ?string
{
    $elexon = (string) ($u['elexonBmUnit'] ?? '');
    $ng = (string) ($u['nationalGridBmUnit'] ?? '');
    $name = (string) ($u['bmUnitName'] ?? '');
    $party = (string) ($u['leadPartyName'] ?? '');
    $fuel = strtoupper((string) ($u['fuelType'] ?? ''));
    if (!empty($u['interconnectorId']) || strpos($elexon, 'I_') === 0 || strpos($elexon, '2__') === 0) {
        return null;
    }
    if (in_array($fuel, ['CCGT', 'OCGT', 'COAL', 'NUCLEAR', 'OIL', 'BIOMASS', 'WIND', 'NPSHYD', 'PS'], true)) {
        return null;
    }
    if (preg_match('/pumped|hydro|dinorwig|ffestiniog|cruachan|foyers/i', $name)) {
        return null;
    }
    if (preg_match('/batter|\bbess\b|energy storage/i', $name . ' ' . $party)) {
        return 'name';
    }
    $gen = (float) ($u['generationCapacity'] ?? 0);
    $dem = abs((float) ($u['demandCapacity'] ?? 0));
    if (preg_match('/^(T|E)_/', $elexon) && preg_match('/B-\d+$/', $ng) && $dem >= 1 && $dem >= 0.5 * $gen) {
        return 'id and two-way capacity';
    }
    return null;
}

/**
 * The battery units, as [elexonBmUnit => ['ng' => ..., 'name' => ..., 'why' => ...]].
 * Refreshed from Elexon's register at most once a day.
 */
function ukgrid_battery_units(array $config, int $timeoutSeconds, ?string &$error = null): array
{
    $path = ukgrid_battery_units_path();
    $cached = is_readable($path) ? json_decode((string) @file_get_contents($path), true) : null;
    if (is_array($cached) && !empty($cached['units']) && ($cached['fetched_at'] ?? 0) > time() - 86400) {
        return ukgrid_battery_apply_overrides($cached['units'], $config);
    }
    $base = rtrim($config['sources']['elexon_base'] ?? 'https://data.elexon.co.uk/bmrs/api/v1', '/');
    $data = ukgrid_http_get_json($base . '/reference/bmunits/all', $timeoutSeconds);
    $list = is_array($data) && isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
    if (!is_array($list) || !$list) {
        $error = 'BM unit register request failed';
        return is_array($cached) && !empty($cached['units']) ? ukgrid_battery_apply_overrides($cached['units'], $config) : [];
    }
    $units = [];
    foreach ($list as $u) {
        if (!is_array($u)) continue;
        $why = ukgrid_battery_is_unit($u);
        $id = (string) ($u['elexonBmUnit'] ?? '');
        if ($why === null || $id === '') continue;
        $units[$id] = [
            'ng' => (string) ($u['nationalGridBmUnit'] ?? ''),
            'name' => (string) ($u['bmUnitName'] ?? ''),
            'party' => (string) ($u['leadPartyName'] ?? ''),
            'gen_mw' => (float) ($u['generationCapacity'] ?? 0),
            'dem_mw' => abs((float) ($u['demandCapacity'] ?? 0)),
            'why' => $why,
        ];
    }
    @file_put_contents($path, json_encode(['fetched_at' => time(), 'register_size' => count($list), 'units' => $units]), LOCK_EX);
    return ukgrid_battery_apply_overrides($units, $config);
}

function ukgrid_battery_apply_overrides(array $units, array $config): array
{
    $o = $config['battery_units'] ?? [];
    foreach ((array) ($o['exclude'] ?? []) as $id) {
        unset($units[$id]);
    }
    foreach ((array) ($o['include'] ?? []) as $id) {
        if (!isset($units[$id])) {
            $units[$id] = ['ng' => '', 'name' => '', 'party' => '', 'gen_mw' => 0, 'dem_mw' => 0, 'why' => 'added in config'];
        }
    }
    return $units;
}

/** Elexon dataset responses come as {"data":[...]} or a bare array. */
function ukgrid_battery_rows($data): ?array
{
    if (!is_array($data)) return null;
    if (isset($data['data']) && is_array($data['data'])) return $data['data'];
    return array_keys($data) === range(0, count($data) - 1) || $data === [] ? $data : null;
}

/** Sum battery units for one settlement period of PN segments: [net, charge, discharge, units]. */
function ukgrid_battery_sum_pn(array $rows, array $units, int $spStart): array
{
    $perUnit = []; // unit => MW-seconds
    foreach ($rows as $r) {
        $id = (string) ($r['bmUnit'] ?? '');
        if ($id === '' || !isset($units[$id])) continue;
        $from = strtotime((string) ($r['timeFrom'] ?? ''));
        $to = strtotime((string) ($r['timeTo'] ?? ''));
        if (!$from || !$to || $to <= $from) continue;
        // Clip each segment to this settlement period
        $a = max($from, $spStart);
        $b = min($to, $spStart + 1800);
        if ($b <= $a) continue;
        $lf = (float) ($r['levelFrom'] ?? 0);
        $lt = (float) ($r['levelTo'] ?? 0);
        $slope = ($lt - $lf) / ($to - $from);
        $va = $lf + $slope * ($a - $from);
        $vb = $lf + $slope * ($b - $from);
        $perUnit[$id] = ($perUnit[$id] ?? 0) + ($va + $vb) / 2 * ($b - $a);
    }
    $net = $charge = $discharge = 0.0;
    foreach ($perUnit as $mws) {
        $mw = $mws / 1800;
        $net += $mw;
        if ($mw < 0) $charge += $mw; else $discharge += $mw;
    }
    return [$net, $charge, $discharge, count($perUnit)];
}

/** Sum battery units for one settlement period of B1610 (MWh per half hour, so x2 for MW). */
function ukgrid_battery_sum_b1610(array $rows, array $units): array
{
    $perUnit = [];
    foreach ($rows as $r) {
        $id = (string) ($r['bmUnit'] ?? '');
        if ($id === '' || !isset($units[$id]) || !is_numeric($r['quantity'] ?? null)) continue;
        $perUnit[$id] = (float) $r['quantity'] * 2;
    }
    $net = $charge = $discharge = 0.0;
    foreach ($perUnit as $mw) {
        $net += $mw;
        if ($mw < 0) $charge += $mw; else $discharge += $mw;
    }
    return [$net, $charge, $discharge, count($perUnit)];
}

/**
 * Each run: the BM unit list (daily), notified output for the current and
 * two previous settlement periods plus up to $extraPn missing ones from the
 * last 24 hours, and up to $meteredBudget metered requests. The on-demand
 * refresh keeps these small; cron/fetch_battery.php catches up faster.
 */
function ukgrid_ingest_battery(PDO $pdo, array $config, int $timeoutSeconds = 10, int $extraPn = 4, int $meteredBudget = 6): array
{
    $base = rtrim($config['sources']['elexon_base'] ?? 'https://data.elexon.co.uk/bmrs/api/v1', '/');
    $errors = [];
    $rowsWritten = 0;
    try {
        ukgrid_battery_ensure_table($pdo);
    } catch (Throwable $e) {
        ukgrid_log_ingest('BATTERY', 'ERROR', 0, 'could not create the readings_battery table - run sql/schema.sql. ' . $e->getMessage());
        return ['rows' => 0, 'errors' => ['table']];
    }
    $unitError = null;
    $units = ukgrid_battery_units($config, $timeoutSeconds, $unitError);
    if ($unitError) $errors[] = $unitError;
    if (!$units) {
        ukgrid_log_ingest('BATTERY', 'ERROR', 0, 'no battery units identified - ' . implode('; ', $errors));
        return ['rows' => 0, 'errors' => $errors];
    }

    $ins = $pdo->prepare('INSERT INTO readings_battery (ts, kind, net_mw, charge_mw, discharge_mw, units) VALUES (:ts, :kind, :net, :c, :d, :u)
        ON DUPLICATE KEY UPDATE net_mw = VALUES(net_mw), charge_mw = VALUES(charge_mw), discharge_mw = VALUES(discharge_mw), units = VALUES(units)');
    $haveStmt = $pdo->prepare('SELECT ts FROM readings_battery WHERE kind = :kind AND ts >= :from');

    $have = function (string $kind, int $fromTs) use ($haveStmt): array {
        $haveStmt->execute(['kind' => $kind, 'from' => gmdate('Y-m-d H:i:s', $fromTs)]);
        return array_flip(array_map(fn ($t) => strtotime($t . ' UTC'), $haveStmt->fetchAll(PDO::FETCH_COLUMN)));
    };

    // ---------- Notified (PN) ----------
    $nowSp = ukgrid_sp_start_utc(...ukgrid_sp_for_utc(time()));
    $targets = [$nowSp, $nowSp - 1800, $nowSp - 3600];
    $got = $have('NOTIFIED', $nowSp - 86400);
    $extra = 0;
    for ($t = $nowSp - 5400; $t > $nowSp - 86400 && $extra < $extraPn; $t -= 1800) {
        if (!isset($got[$t])) { $targets[] = $t; $extra++; }
    }
    foreach ($targets as $t) {
        [$d, $sp] = ukgrid_sp_for_utc($t);
        $data = ukgrid_http_get_json($base . '/datasets/PN?settlementDate=' . $d . '&settlementPeriod=' . $sp . '&format=json', $timeoutSeconds);
        $rows = ukgrid_battery_rows($data);
        if ($rows === null) { $errors[] = "PN {$d} SP{$sp}: request failed"; continue; }
        [$net, $c, $dis, $n] = ukgrid_battery_sum_pn($rows, $units, $t);
        if ($n === 0) { $errors[] = "PN {$d} SP{$sp}: no battery units in the response"; continue; }
        $ins->execute(['ts' => gmdate('Y-m-d H:i:s', $t), 'kind' => 'NOTIFIED', 'net' => round($net, 2), 'c' => round($c, 2), 'd' => round($dis, 2), 'u' => $n]);
        $rowsWritten++;
    }

    // ---------- Metered (B1610) ----------
    // Elexon publishes B1610 days later (about 7 on 29 October 2026), so:
    // carry on forward from the newest metered period we have until Elexon
    // has nothing newer, then fill older gaps. With nothing stored yet,
    // step back a day at a time to find the newest published day.
    $budget = $meteredBudget;
    $fetchMetered = function (int $t) use ($base, $timeoutSeconds, $units, $ins, &$rowsWritten, &$errors, &$budget): ?bool {
        [$d, $sp] = ukgrid_sp_for_utc($t);
        $budget--;
        $rows = ukgrid_battery_rows(ukgrid_http_get_json($base . '/datasets/B1610?settlementDate=' . $d . '&settlementPeriod=' . $sp . '&format=json', $timeoutSeconds));
        if ($rows === null) { $errors[] = "B1610 {$d} SP{$sp}: request failed"; return null; }
        if (!$rows) return false; // not published yet
        [$net, $c, $dis, $n] = ukgrid_battery_sum_b1610($rows, $units);
        if ($n > 0) {
            $ins->execute(['ts' => gmdate('Y-m-d H:i:s', $t), 'kind' => 'METERED', 'net' => round($net, 2), 'c' => round($c, 2), 'd' => round($dis, 2), 'u' => $n]);
            $rowsWritten++;
        }
        return true;
    };
    $oldest = $nowSp - 14 * 86400;
    $gotM = $have('METERED', $oldest);
    if (!$gotM) {
        for ($k = 4; $k <= 12 && $budget > 0; $k++) {
            $r = $fetchMetered($nowSp - $k * 86400);
            if ($r === null || $r === true) break;
        }
    } else {
        $newest = max(array_keys($gotM));
        for ($t = $newest + 1800; $t < $nowSp - 86400 && $budget > 2; $t += 1800) {
            $r = $fetchMetered($t);
            if ($r !== true) break;
        }
        for ($t = $newest - 1800; $t > $oldest && $budget > 0; $t -= 1800) {
            if (isset($gotM[$t])) continue;
            if ($fetchMetered($t) === null) break;
        }
    }

    ukgrid_log_ingest('BATTERY', $rowsWritten > 0 ? 'OK' : 'ERROR', $rowsWritten, (count($units) . ' battery units. ') . implode('; ', $errors));
    return ['rows' => $rowsWritten, 'errors' => $errors];
}
