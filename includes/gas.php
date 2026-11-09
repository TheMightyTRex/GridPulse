<?php
/**
 * Great Britain's gas: where it goes and where it comes from.
 *
 * Source: National Gas Transmission's Gas Operational Data REST API
 * (https://data.nationalgas.com/apis/rest-apis). No key is needed. National
 * Gas asks to be acknowledged as the source.
 *
 * Two jobs:
 *   GAS       every 12 minutes. The Instantaneous Flow API: live flows,
 *             captured every 2 minutes and published in sets every 12
 *             minutes. Gives demand by category (homes and businesses via
 *             the local gas networks, power stations, industry, exports,
 *             storage injection), supply by entry point, and linepack.
 *   GASDAILY  every 6 hours. The Published Data API: each gas day's actual
 *             totals (D+1), for the last 35 days.
 *
 * All values are million cubic metres (mcm). Live values are rates in mcm
 * per day (what a full day would be at that rate); daily values are the
 * day's total. A gas day runs from 5am to 5am UK time.
 */

const UKGRID_GAS_BASE = 'https://api.nationalgas.com/operationaldata/v1';

function ukgrid_gas_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS gas_flow (
        ts DATETIME NOT NULL COMMENT 'UTC',
        item VARCHAR(16) NOT NULL,
        mcm DECIMAL(9,3) NOT NULL COMMENT 'rate, mcm per day',
        PRIMARY KEY (ts, item)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS gas_daily (
        gas_day DATE NOT NULL,
        item VARCHAR(16) NOT NULL,
        mcm DECIMAL(9,3) NOT NULL COMMENT 'total for the gas day',
        PRIMARY KEY (gas_day, item)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * Which supply group each entry point belongs to. Anything not listed here
 * (a new terminal) counts as North Sea and is logged, so the list can be
 * updated.
 */
function ukgrid_gas_entry_groups(): array
{
    return [
        // Gas storage sites
        'ALDBROUGH' => 's_storage', 'EASINGTON ROUGH ST' => 's_storage', 'HILLTOP' => 's_storage',
        'HOLE HOUSE FARM' => 's_storage', 'HOLFORD' => 's_storage', 'HORNSEA' => 's_storage',
        'STUBLACH' => 's_storage', 'HUMBLY GROVE' => 's_storage', 'HATFIELD MOOR' => 's_storage',
        // LNG import terminals
        'GRAIN NTS 1' => 's_lng', 'GRAIN NTS 2' => 's_lng',
        'MILFORD HAVEN - DRAGON' => 's_lng', 'MILFORD HAVEN - SOUTH HOOK' => 's_lng',
        // Pipelines from Belgium and the Netherlands
        'BACTON BBL' => 's_import', 'BACTON IC' => 's_import',
        // North Sea gas fields, UK and Norway (National Gas groups these
        // together, and St Fergus takes gas from both)
        'BACTON PERENCO' => 's_ncs', 'BACTON SEAL' => 's_ncs', 'BACTON SHELL' => 's_ncs',
        'EASINGTON DIMLINGTON' => 's_ncs', 'EASINGTON LANGELED' => 's_ncs',
        'ST FERGUS MOBIL' => 's_ncs', 'ST FERGUS NSMP' => 's_ncs', 'ST FERGUS SHELL' => 's_ncs',
        'TEESSIDE CATS' => 's_ncs', 'TEESSIDE PX' => 's_ncs', 'THEDDLETHORPE' => 's_ncs',
        'BARROW' => 's_ncs',
    ];
}

/**
 * Turns an Instantaneous Flow reply into rows [ts => [item => mcm]].
 * $unknown collects entry points not in ukgrid_gas_entry_groups().
 */
function ukgrid_gas_parse_instant(array $data, array &$unknown = []): array
{
    $demand = [
        'LDZ OFFTAKE FLOW' => 'd_ldz', 'POWER STATION DEMAND FLOW' => 'd_power',
        'INDUSTRIAL DEMAND FLOW' => 'd_industrial', 'INTERCONNECTOR EXPORT DEMAND FLOW' => 'd_export',
        'STORAGE DEMAND FLOW' => 'd_storage', 'NTS DEMAND FLOW' => 'd_total',
        'TOTAL SUPPLY' => 's_total', 'NTS ACTUAL LINEPACK' => 'linepack',
    ];
    $entryGroups = ukgrid_gas_entry_groups();
    $rows = [];
    foreach (($data['instantaneousFlow'] ?? []) as $group) {
        $desc = strtolower((string) ($group['description'] ?? ''));
        $isEntryPoints = strpos($desc, 'entry point') !== false;
        foreach (($group['sites'] ?? []) as $site) {
            $name = strtoupper(trim((string) ($site['siteName'] ?? '')));
            if ($isEntryPoints) {
                $item = $entryGroups[$name] ?? null;
                if ($item === null) {
                    $unknown[$name] = true;
                    $item = 's_ncs';
                }
                $add = true;
            } elseif (isset($demand[$name])) {
                $item = $demand[$name];
                $add = false;
            } else {
                continue; // terminal totals and single export points: not needed
            }
            foreach (($site['siteGasDetail'] ?? []) as $p) {
                if (!isset($p['applicableAt'], $p['flowRate']) || !is_numeric($p['flowRate'])) {
                    continue;
                }
                $t = strtotime((string) $p['applicableAt']);
                if (!$t) {
                    continue;
                }
                $ts = gmdate('Y-m-d H:i:00', $t);
                $v = (float) $p['flowRate'];
                if ($add) {
                    $rows[$ts][$item] = ($rows[$ts][$item] ?? 0) + $v;
                } else {
                    $rows[$ts][$item] = $v;
                }
            }
        }
    }
    ksort($rows);
    return $rows;
}

function ukgrid_gas_store_flow(PDO $pdo, array $rows): int
{
    $st = $pdo->prepare('INSERT INTO gas_flow (ts, item, mcm) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE mcm = VALUES(mcm)');
    $n = 0;
    foreach ($rows as $ts => $items) {
        foreach ($items as $item => $v) {
            $st->execute([$ts, $item, round($v, 3)]);
            $n++;
        }
    }
    return $n;
}

function ukgrid_ingest_gas(PDO $pdo, array $config, int $timeoutSeconds = 10): array
{
    ukgrid_gas_ensure_tables($pdo);
    $data = ukgrid_http_get_json(UKGRID_GAS_BASE . '/instantaneousflow/sites', $timeoutSeconds);
    if ($data === null) {
        ukgrid_log_ingest('GAS', 'ERROR', 0, 'National Gas instantaneous flow request failed (see refresh.log)');
        return ['ok' => false];
    }
    $unknown = [];
    $rows = ukgrid_gas_parse_instant($data, $unknown);
    if (!$rows) {
        ukgrid_log_ingest('GAS', 'ERROR', 0, 'National Gas reply had no flow points');
        return ['ok' => false];
    }
    $n = ukgrid_gas_store_flow($pdo, $rows);
    // Two-minute points add up (about 6,000 rows a day). Keep 35 days.
    $pdo->exec("DELETE FROM gas_flow WHERE ts < UTC_TIMESTAMP() - INTERVAL 35 DAY");
    $msg = count($rows) . ' points to ' . array_key_last($rows) . ' UTC';
    if ($unknown) {
        $msg .= '. New entry points counted as North Sea: ' . implode(', ', array_keys($unknown));
    }
    ukgrid_log_ingest('GAS', 'OK', $n, $msg);
    return ['ok' => true, 'rows' => $n];
}

/** Published Data API items for each gas day's totals. */
function ukgrid_gas_daily_items(): array
{
    return [
        'PUBOBJ1015' => 'd_ldz',        // NTS Volume Offtaken, LDZ Offtake Total
        'PUBOBJ1017' => 'd_power',      // NTS Volume Offtaken, Powerstations Total
        'PUBOBJ1018' => 'd_industrial', // NTS Volume Offtaken, Industrial Offtake Total
        'PUBOBJ1020' => 'd_export',     // NTS Volume Offtaken, Interconnector Exports Total
        'PUBOBJ1016' => 'd_storage',    // NTS Volume Offtaken, Storage Injection Total
        'PUBOB637' => 'd_total',        // Demand Actual, NTS, D+1
        'PUBOBJ1620' => 's_ncs',        // Beach Including Norway - Daily Flow
        'PUBOBJ1621' => 's_lng',        // Aggregate LNG Importations - Daily Flow
        'PUBOB262' => 's_import',       // Interconnector - Daily Flow
        'PUBOB264' => 's_storage',      // Storage - Daily Flow
    ];
}

function ukgrid_gas_parse_daily(array $data): array
{
    $map = ukgrid_gas_daily_items();
    $rows = [];
    foreach ($data as $pub) {
        $item = $map[$pub['publicationId'] ?? ''] ?? null;
        if ($item === null) {
            continue;
        }
        foreach (($pub['publications'] ?? []) as $p) {
            $day = (string) ($p['applicableFor'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !is_numeric($p['value'] ?? null)) {
                continue;
            }
            $rows[$day][$item] = (float) $p['value'];
        }
    }
    ksort($rows);
    return $rows;
}

function ukgrid_ingest_gas_daily(PDO $pdo, array $config, int $timeoutSeconds = 15): array
{
    ukgrid_gas_ensure_tables($pdo);
    $data = ukgrid_http_post_json(UKGRID_GAS_BASE . '/publications/gasday', [
        'fromDate' => gmdate('Y-m-d', time() - 35 * 86400),
        'toDate' => gmdate('Y-m-d'),
        'publicationIds' => array_keys(ukgrid_gas_daily_items()),
        'latestValue' => 'Y',
    ], $timeoutSeconds);
    if ($data === null) {
        ukgrid_log_ingest('GASDAILY', 'ERROR', 0, 'National Gas published data request failed (see refresh.log)');
        return ['ok' => false];
    }
    $rows = ukgrid_gas_parse_daily($data);
    $st = $pdo->prepare('INSERT INTO gas_daily (gas_day, item, mcm) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE mcm = VALUES(mcm)');
    $n = 0;
    foreach ($rows as $day => $items) {
        foreach ($items as $item => $v) {
            $st->execute([$day, $item, round($v, 3)]);
            $n++;
        }
    }
    ukgrid_log_ingest('GASDAILY', $n ? 'OK' : 'EMPTY', $n, count($rows) . ' gas days' . ($rows ? ', latest ' . array_key_last($rows) : ''));
    return ['ok' => true, 'rows' => $n];
}
