<?php
/**
 * Grid Pulse - one view of every grid this site covers ("areas").
 *
 * Each grid has its own tables (Elexon for GB, EirGrid for Ireland,
 * ENTSO-E for the European countries, EIA for the USA, IESO for Ontario,
 * Open Electricity for Australia). The day pages, daily archive, records,
 * "today so far" figures and replay all need the same thing from each:
 * generation by fuel, demand, price and net imports, in regular time
 * buckets. This file turns each source into that one shape, so those
 * features are written once and work for every country.
 *
 * Fuel groups (keep in sync with assets/country.js AREA_GROUPS):
 *   wind, solar, hydro, nuclear, gas, coal, oil, biomass,
 *   other_renewable (geothermal, marine, other renewable), other, storage
 * Storage is pumped hydro or batteries generating; charging is a load and
 * is left out. Net imports are positive when importing.
 *
 * Shares (worked out in ukgrid_area_shares()):
 *   - renewable: wind, solar, hydro, other renewables, plus biomass -
 *     except for Great Britain, where this site has always counted
 *     biomass as non-renewable (matching the GB home page);
 *   - zero-carbon: wind, solar, hydro, other renewables and nuclear,
 *     never biomass (it burns fuel);
 *   - fossil: gas, coal and oil.
 *   All are shares of generation inside the area (storage included,
 *   imports not).
 */

const UKGRID_AREA_GROUPS = ['wind', 'solar', 'hydro', 'nuclear', 'gas', 'coal', 'oil', 'biomass', 'other_renewable', 'other', 'storage'];

/** Every area, keyed by code. Only ENTSO-E countries in the config are included. */
function ukgrid_areas(array $config): array
{
    $areas = [
        'GB' => ['name' => 'Great Britain', 'kind' => 'gb', 'tz' => 'Europe/London', 'currency' => 'GBP', 'bucket' => 1800,
                 'page' => 'index.html', 'carbon' => true, 'renewable_biomass' => false,
                 'source' => 'Elexon Insights Solution, NESO Data Portal and the Carbon Intensity API'],
        'IE' => ['name' => 'Ireland', 'kind' => 'ie', 'tz' => 'Europe/Dublin', 'currency' => 'EUR', 'bucket' => 1800,
                 'page' => 'pages/ireland.html', 'carbon' => true, 'renewable_biomass' => true, 'other_label' => 'Gas and other',
                 'source' => 'EirGrid Smart Grid Dashboard (all-island) and ENTSO-E Transparency Platform (price)'],
        'US' => ['name' => 'USA', 'kind' => 'us', 'tz' => 'America/New_York', 'currency' => 'USD', 'bucket' => 3600,
                 'page' => 'pages/usa.html', 'carbon' => false, 'renewable_biomass' => true,
                 'source' => 'U.S. Energy Information Administration (EIA-930, lower 48 states)'],
        'CA' => ['name' => 'Ontario', 'kind' => 'ca', 'tz' => 'America/Toronto', 'currency' => 'CAD', 'bucket' => 3600,
                 'page' => 'pages/canada.html', 'carbon' => false, 'renewable_biomass' => true,
                 'source' => 'IESO (Independent Electricity System Operator, Ontario)'],
        'AU' => ['name' => 'Australia (NEM)', 'kind' => 'au', 'tz' => 'Australia/Brisbane', 'currency' => 'AUD', 'bucket' => 1800,
                 'page' => 'pages/australia.html', 'carbon' => false, 'renewable_biomass' => true,
                 'source' => 'Open Electricity (CC BY-NC 4.0, non-commercial use only)'],
    ];
    $tz = [
        'FR' => 'Europe/Paris', 'NL' => 'Europe/Amsterdam', 'BE' => 'Europe/Brussels', 'NO' => 'Europe/Oslo',
        'DK' => 'Europe/Copenhagen', 'DE' => 'Europe/Berlin', 'ES' => 'Europe/Madrid', 'IT' => 'Europe/Rome',
        'SE' => 'Europe/Stockholm', 'PT' => 'Europe/Lisbon', 'PL' => 'Europe/Warsaw',
    ];
    $pages = [
        'FR' => 'france', 'NL' => 'nl', 'BE' => 'belgium', 'NO' => 'norway', 'DK' => 'denmark', 'DE' => 'germany',
        'ES' => 'spain', 'IT' => 'italy', 'SE' => 'sweden', 'PT' => 'portugal', 'PL' => 'poland',
    ];
    foreach (($config['sources']['entsoe_countries'] ?? []) as $cc => $c) {
        if ($cc === 'IE' || !isset($tz[$cc]) || !in_array('generation', $c['fetch'] ?? [], true)) {
            continue;
        }
        $areas[$cc] = [
            'name' => (string) ($c['label'] ?? $cc), 'kind' => 'entsoe', 'tz' => $tz[$cc], 'currency' => 'EUR',
            'bucket' => 3600, 'page' => 'pages/' . $pages[$cc] . '.html', 'carbon' => false, 'renewable_biomass' => true,
            'source' => 'ENTSO-E Transparency Platform',
            'has_demand' => in_array('demand', $c['fetch'] ?? [], true),
            'has_price' => in_array('price', $c['fetch'] ?? [], true),
        ];
    }
    foreach ($areas as $code => &$a) {
        $a['code'] = $code;
        $a['has_demand'] = $a['has_demand'] ?? true;
        $a['has_price'] = $a['has_price'] ?? in_array($a['kind'], ['gb', 'ie', 'au'], true);
    }
    unset($a);
    return $areas;
}

/** ENTSO-E psrType -> fuel group. */
function ukgrid_area_psr_group(string $psr): string
{
    static $map = [
        'B01' => 'biomass', 'B02' => 'coal', 'B03' => 'coal', 'B04' => 'gas', 'B05' => 'coal', 'B06' => 'oil',
        'B07' => 'oil', 'B08' => 'coal', 'B09' => 'other_renewable', 'B10' => 'storage', 'B11' => 'hydro',
        'B12' => 'hydro', 'B13' => 'other_renewable', 'B14' => 'nuclear', 'B15' => 'other_renewable',
        'B16' => 'solar', 'B17' => 'other', 'B18' => 'wind', 'B19' => 'wind', 'B20' => 'other',
    ];
    return $map[$psr] ?? 'other';
}

/**
 * Local day -> [utcStart, utcEnd) as 'Y-m-d H:i:s', using the area's time
 * zone (so a 23- or 25-hour clock-change day is handled).
 */
function ukgrid_area_day_bounds(array $area, string $day): array
{
    $tz = new DateTimeZone($area['tz']);
    $start = new DateTimeImmutable($day . ' 00:00:00', $tz);
    $end = $start->modify('+1 day');
    $utc = new DateTimeZone('UTC');
    return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')];
}

/** Today's date in the area's own time zone. */
function ukgrid_area_today(array $area): string
{
    return (new DateTimeImmutable('now', new DateTimeZone($area['tz'])))->format('Y-m-d');
}

/**
 * Bucketed readings for one area between two UTC times (end exclusive).
 * Returns [ 'Y-m-d H:i:s' => ['g' => [group => MW], 'demand' => ?, 'price' => ?, 'carbon' => ?, 'imports' => ?] ]
 * in time order. Buckets with no generation are left out.
 */
function ukgrid_area_buckets(PDO $pdo, array $area, string $fromUtc, string $toUtc): array
{
    $b = (int) $area['bucket'];
    $bucketExpr = "FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/{$b})*{$b})";
    $range = 'ts >= :f AND ts < :t';
    $p = ['f' => $fromUtc, 't' => $toUtc];
    $out = [];
    $add = function (string $ts, string $field, $group, $mw) use (&$out) {
        if ($mw === null || !is_numeric($mw)) {
            return;
        }
        if (!isset($out[$ts])) {
            $out[$ts] = ['g' => [], 'demand' => null, 'price' => null, 'carbon' => null, 'imports' => null];
        }
        if ($field === 'g') {
            $out[$ts]['g'][$group] = ($out[$ts]['g'][$group] ?? 0) + (float) $mw;
        } elseif ($field === 'imports') {
            $out[$ts]['imports'] = ($out[$ts]['imports'] ?? 0) + (float) $mw;
        } else {
            $out[$ts][$field] = (float) $mw;
        }
    };
    $q = function (string $sql, array $extra = []) use ($pdo, $p) {
        $st = $pdo->prepare($sql);
        $st->execute(array_merge($p, $extra));
        return $st->fetchAll(PDO::FETCH_NUM);
    };
    $code = $area['code'];

    switch ($area['kind']) {
        case 'gb':
            $fuel = ['CCGT' => 'gas', 'OCGT' => 'gas', 'COAL' => 'coal', 'NUCLEAR' => 'nuclear', 'BIOMASS' => 'biomass',
                     'WIND' => 'wind', 'WIND_EMBEDDED' => 'wind', 'SOLAR_EMBEDDED' => 'solar', 'NPSHYD' => 'hydro',
                     'PS' => 'storage', 'OIL' => 'oil', 'OTHER' => 'other'];
            foreach ($q("SELECT {$bucketExpr} b, fuel_type, AVG(mw) FROM readings_generation WHERE {$range} GROUP BY b, fuel_type") as [$ts, $f, $mw]) {
                if (strpos($f, 'INT') === 0) {
                    $add($ts, 'imports', null, $mw);
                } else {
                    $add($ts, 'g', $fuel[$f] ?? 'other', $mw);
                }
            }
            foreach ($q("SELECT {$bucketExpr} b, AVG(mw) FROM readings_demand WHERE {$range} GROUP BY b") as [$ts, $v]) $add($ts, 'demand', null, $v);
            foreach ($q("SELECT {$bucketExpr} b, AVG(price) FROM readings_price WHERE {$range} AND provider = 'APXMIDP' GROUP BY b") as [$ts, $v]) $add($ts, 'price', null, $v);
            foreach ($q("SELECT {$bucketExpr} b, AVG(COALESCE(actual_gco2, forecast_gco2)) FROM readings_emissions WHERE {$range} GROUP BY b") as [$ts, $v]) $add($ts, 'carbon', null, $v);
            break;

        case 'entsoe':
            foreach ($q("SELECT {$bucketExpr} b, psr_type, AVG(mw) FROM readings_entsoe_generation WHERE {$range} AND country_code = :cc GROUP BY b, psr_type", ['cc' => $code]) as [$ts, $psr, $mw]) {
                $add($ts, 'g', ukgrid_area_psr_group((string) $psr), $mw);
            }
            if ($area['has_demand']) {
                foreach ($q("SELECT {$bucketExpr} b, AVG(mw) FROM readings_entsoe_demand WHERE {$range} AND country_code = :cc GROUP BY b", ['cc' => $code]) as [$ts, $v]) $add($ts, 'demand', null, $v);
            }
            if ($area['has_price']) {
                foreach ($q("SELECT {$bucketExpr} b, AVG(price_eur_mwh) FROM readings_entsoe_price WHERE {$range} AND country_code = :cc GROUP BY b", ['cc' => $code]) as [$ts, $v]) $add($ts, 'price', null, $v);
            }
            foreach (ukgrid_area_flow_net($pdo, $code, $bucketExpr, $range, $p) as [$ts, $v]) {
                $add($ts, 'imports', null, $v);
            }
            break;

        case 'ie':
            $tot = [];
            foreach ($q("SELECT {$bucketExpr} b, category, AVG(mw) FROM readings_ie_generation WHERE {$range} GROUP BY b, category") as [$ts, $cat, $mw]) {
                if ($cat === 'TOTAL') {
                    $tot[$ts] = (float) $mw;
                } elseif ($cat === 'WIND') {
                    $add($ts, 'g', 'wind', $mw);
                } elseif ($cat === 'SOLAR') {
                    $add($ts, 'g', 'solar', $mw);
                } elseif ($cat === 'INTERCONNECTION') {
                    $add($ts, 'imports', null, $mw);
                }
            }
            foreach ($tot as $ts => $mw) {
                $known = ($out[$ts]['g']['wind'] ?? 0) + ($out[$ts]['g']['solar'] ?? 0);
                $add($ts, 'g', 'other', max(0, $mw - $known));
            }
            foreach ($q("SELECT {$bucketExpr} b, AVG(mw) FROM readings_ie_demand WHERE {$range} GROUP BY b") as [$ts, $v]) $add($ts, 'demand', null, $v);
            foreach ($q("SELECT {$bucketExpr} b, AVG(price_eur_mwh) FROM readings_entsoe_price WHERE {$range} AND country_code = 'IE' GROUP BY b") as [$ts, $v]) $add($ts, 'price', null, $v);
            foreach ($q("SELECT {$bucketExpr} b, AVG(gco2_per_kwh) FROM readings_ie_co2 WHERE {$range} GROUP BY b") as [$ts, $v]) $add($ts, 'carbon', null, $v);
            break;

        case 'us':
            $fuel = ['COL' => 'coal', 'NG' => 'gas', 'NUC' => 'nuclear', 'OIL' => 'oil', 'WAT' => 'hydro', 'SUN' => 'solar',
                     'SNB' => 'solar', 'WND' => 'wind', 'GEO' => 'other_renewable', 'BAT' => 'storage', 'PS' => 'storage',
                     'OES' => 'storage', 'UES' => 'storage', 'OTH' => 'other', 'UNK' => 'other'];
            foreach ($q("SELECT {$bucketExpr} b, category, AVG(mw) FROM readings_us_generation WHERE {$range} GROUP BY b, category") as [$ts, $cat, $mw]) {
                if ($cat === 'TOTAL') {
                    continue;
                }
                if ($cat === 'INTERCONNECTION') {
                    // EIA total interchange is positive when exporting.
                    $add($ts, 'imports', null, -(float) $mw);
                    continue;
                }
                if (isset($fuel[$cat]) && $fuel[$cat] === 'storage' && $mw < 0) {
                    continue; // charging
                }
                $add($ts, 'g', $fuel[$cat] ?? 'other', $mw);
            }
            foreach ($q("SELECT {$bucketExpr} b, AVG(mw) FROM readings_us_demand WHERE {$range} GROUP BY b") as [$ts, $v]) $add($ts, 'demand', null, $v);
            break;

        case 'ca':
            $fuel = ['NUCLEAR' => 'nuclear', 'GAS' => 'gas', 'HYDRO' => 'hydro', 'WIND' => 'wind', 'SOLAR' => 'solar',
                     'BIOFUEL' => 'biomass', 'OTHER' => 'other'];
            foreach ($q("SELECT {$bucketExpr} b, category, AVG(mw) FROM readings_ca_generation WHERE {$range} GROUP BY b, category") as [$ts, $cat, $mw]) {
                $add($ts, 'g', $fuel[$cat] ?? 'other', $mw);
            }
            foreach ($q("SELECT {$bucketExpr} b, AVG(mw) FROM readings_ca_demand WHERE {$range} GROUP BY b") as [$ts, $v]) $add($ts, 'demand', null, $v);
            break;

        case 'au':
            $fuel = ['coal' => 'coal', 'gas' => 'gas', 'wind' => 'wind', 'solar' => 'solar', 'hydro' => 'hydro',
                     'distillate' => 'oil', 'bioenergy' => 'biomass', 'battery_discharging' => 'storage'];
            foreach ($q("SELECT {$bucketExpr} b, category, AVG(mw) FROM readings_au_generation WHERE {$range} GROUP BY b, category") as [$ts, $cat, $mw]) {
                if (!isset($fuel[$cat])) {
                    continue; // pumps and battery_charging are loads
                }
                $add($ts, 'g', $fuel[$cat], abs((float) $mw));
            }
            foreach ($q("SELECT {$bucketExpr} b, AVG(mw) FROM readings_au_demand WHERE {$range} GROUP BY b") as [$ts, $v]) $add($ts, 'demand', null, $v);
            foreach ($q("SELECT {$bucketExpr} b, AVG(price_aud_mwh) FROM readings_au_price WHERE {$range} GROUP BY b") as [$ts, $v]) $add($ts, 'price', null, $v);
            break;
    }

    // Only buckets with generation count as readings; negative storage
    // (charging) is not generation.
    foreach ($out as $ts => $row) {
        foreach ($row['g'] as $g => $mw) {
            if ($mw < 0) {
                unset($out[$ts]['g'][$g]);
            }
        }
        if (empty($out[$ts]['g'])) {
            unset($out[$ts]);
        }
    }
    ksort($out);
    return $out;
}

/** Net imports per bucket from readings_flow (ENTSO-E physical flows). Empty if that table is missing or empty. */
function ukgrid_area_flow_net(PDO $pdo, string $code, string $bucketExpr, string $range, array $p): array
{
    try {
        $st = $pdo->prepare(
            "SELECT b, SUM(v) FROM (
               SELECT {$bucketExpr} b, to_area, from_area, AVG(CASE WHEN to_area = :c1 THEN mw ELSE -mw END) v
               FROM readings_flow WHERE {$range} AND (to_area = :c2 OR from_area = :c3)
               GROUP BY b, to_area, from_area
             ) x GROUP BY b"
        );
        $st->execute(array_merge($p, ['c1' => $code, 'c2' => $code, 'c3' => $code]));
        return $st->fetchAll(PDO::FETCH_NUM);
    } catch (Throwable $e) {
        return [];
    }
}

/** Renewable / zero-carbon / fossil MW and total generation for one bucket's groups. */
function ukgrid_area_shares(array $area, array $g): array
{
    $sum = function (array $keys) use ($g) {
        $s = 0.0;
        foreach ($keys as $k) {
            $s += $g[$k] ?? 0;
        }
        return $s;
    };
    $total = array_sum($g);
    $renKeys = ['wind', 'solar', 'hydro', 'other_renewable'];
    if (!empty($area['renewable_biomass'])) {
        $renKeys[] = 'biomass';
    }
    return [
        'total' => $total,
        'renewable' => $sum($renKeys),
        'zero_carbon' => $sum(['wind', 'solar', 'hydro', 'other_renewable', 'nuclear']),
        'fossil' => $sum(['gas', 'coal', 'oil']),
    ];
}

/**
 * Summary of one local day from bucketed readings: energy by group,
 * shares, demand peak/low, price range, imports, and that day's extremes
 * (used for the records). Null if the day has no readings.
 */
function ukgrid_area_day_summary(array $area, array $buckets, string $day): ?array
{
    if (!$buckets) {
        return null;
    }
    [$dayStart, $dayEnd] = ukgrid_area_day_bounds($area, $day);
    $hours = (strtotime($dayEnd . ' UTC') - strtotime($dayStart . ' UTC')) / 3600;
    $h = $area['bucket'] / 3600; // hours per bucket
    $energy = [];
    $gen = $ren = $zc = $fos = 0.0;
    $imp = $impIn = $impOut = 0.0;
    $impSeen = false;
    $demand = $price = $carbon = [];
    $ext = [];
    $better = function (string $key, float $v, string $ts, bool $high) use (&$ext) {
        if (!isset($ext[$key]) || ($high ? $v > $ext[$key][0] : $v < $ext[$key][0])) {
            $ext[$key] = [round($v, 2), $ts];
        }
    };
    foreach ($buckets as $ts => $row) {
        $s = ukgrid_area_shares($area, $row['g']);
        if ($s['total'] <= 0) {
            continue;
        }
        foreach ($row['g'] as $grp => $mw) {
            $energy[$grp] = ($energy[$grp] ?? 0) + $mw * $h;
        }
        $gen += $s['total'] * $h;
        $ren += $s['renewable'] * $h;
        $zc += $s['zero_carbon'] * $h;
        $fos += $s['fossil'] * $h;
        $better('wind_peak_mw', $row['g']['wind'] ?? 0, $ts, true);
        $better('solar_peak_mw', $row['g']['solar'] ?? 0, $ts, true);
        $better('renewable_share_high', $s['renewable'] / $s['total'] * 100, $ts, true);
        $better('zero_carbon_share_high', $s['zero_carbon'] / $s['total'] * 100, $ts, true);
        $better('fossil_share_low', $s['fossil'] / $s['total'] * 100, $ts, false);
        if ($row['imports'] !== null) {
            $impSeen = true;
            $imp += $row['imports'] * $h;
            if ($row['imports'] > 0) {
                $impIn += $row['imports'] * $h;
            } else {
                $impOut -= $row['imports'] * $h;
            }
        }
        if ($row['demand'] !== null) {
            $demand[] = $row['demand'];
            $better('demand_high_mw', $row['demand'], $ts, true);
            $better('demand_low_mw', $row['demand'], $ts, false);
        }
        if ($row['price'] !== null) {
            $price[] = $row['price'];
            $better('price_high', $row['price'], $ts, true);
            $better('price_low', $row['price'], $ts, false);
        }
        if ($row['carbon'] !== null) {
            $carbon[] = $row['carbon'];
            $better('carbon_low', $row['carbon'], $ts, false);
            $better('carbon_high', $row['carbon'], $ts, true);
        }
    }
    if ($gen <= 0) {
        return null;
    }
    arsort($energy);
    $avg = function (array $a) { return $a ? array_sum($a) / count($a) : null; };
    // Remove extremes that are meaningless when the source has no such
    // generation at all (a 0MW "solar peak" in a country with no solar data).
    foreach (['wind_peak_mw' => 'wind', 'solar_peak_mw' => 'solar'] as $k => $grp) {
        if (empty($energy[$grp])) {
            unset($ext[$k]);
        }
    }
    $ext['wind_day_mwh'] = isset($energy['wind']) ? [round($energy['wind'], 1), $day] : null;
    $ext['solar_day_mwh'] = isset($energy['solar']) ? [round($energy['solar'], 1), $day] : null;
    $ext['renewable_day_share'] = [round($ren / $gen * 100, 2), $day];
    $ext = array_filter($ext);
    return [
        'day' => $day,
        'coverage' => round(min(1, count($buckets) * $h / $hours), 3),
        'gen_mwh' => round($gen, 1),
        'energy_mwh' => array_map(function ($v) { return round($v, 1); }, $energy),
        'largest' => array_key_first($energy),
        'renewable_share' => round($ren / $gen * 100, 2),
        'zero_carbon_share' => round($zc / $gen * 100, 2),
        'fossil_share' => round($fos / $gen * 100, 2),
        'imports_net_mwh' => $impSeen ? round($imp, 1) : null,
        'imports_in_mwh' => $impSeen ? round($impIn, 1) : null,
        'imports_out_mwh' => $impSeen ? round($impOut, 1) : null,
        // Share of the area's own use met by its own generation.
        'home_share' => $impSeen && ($gen + $imp) > 0 ? round(min(100, $gen / ($gen + max(0, $imp)) * 100), 2) : null,
        'demand_avg_mw' => $demand ? round($avg($demand), 1) : null,
        'price_avg' => $price ? round($avg($price), 2) : null,
        'carbon_avg' => $carbon ? round($avg($carbon), 1) : null,
        'extremes' => $ext,
    ];
}

/** Creates the daily_summary table if needed. */
function ukgrid_area_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_summary (
        area VARCHAR(4) NOT NULL,
        day DATE NOT NULL,
        coverage DECIMAL(4,3) NOT NULL,
        gen_mwh DECIMAL(14,1) NOT NULL,
        renewable_share DECIMAL(5,2) NOT NULL,
        zero_carbon_share DECIMAL(5,2) NOT NULL,
        fossil_share DECIMAL(5,2) NOT NULL,
        largest VARCHAR(20) NULL,
        demand_avg_mw DECIMAL(10,1) NULL,
        price_avg DECIMAL(9,2) NULL,
        carbon_avg DECIMAL(7,1) NULL,
        imports_net_mwh DECIMAL(12,1) NULL,
        summary_json TEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (area, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Saves one day's summary. */
function ukgrid_area_save_day(PDO $pdo, string $code, array $s): void
{
    $st = $pdo->prepare('INSERT INTO daily_summary (area, day, coverage, gen_mwh, renewable_share, zero_carbon_share, fossil_share, largest, demand_avg_mw, price_avg, carbon_avg, imports_net_mwh, summary_json)
        VALUES (:a, :d, :cov, :gen, :ren, :zc, :fos, :lg, :dem, :pr, :co, :imp, :js)
        ON DUPLICATE KEY UPDATE coverage = VALUES(coverage), gen_mwh = VALUES(gen_mwh), renewable_share = VALUES(renewable_share),
          zero_carbon_share = VALUES(zero_carbon_share), fossil_share = VALUES(fossil_share), largest = VALUES(largest),
          demand_avg_mw = VALUES(demand_avg_mw), price_avg = VALUES(price_avg), carbon_avg = VALUES(carbon_avg),
          imports_net_mwh = VALUES(imports_net_mwh), summary_json = VALUES(summary_json)');
    $st->execute([
        'a' => $code, 'd' => $s['day'], 'cov' => $s['coverage'], 'gen' => $s['gen_mwh'], 'ren' => $s['renewable_share'],
        'zc' => $s['zero_carbon_share'], 'fos' => $s['fossil_share'], 'lg' => $s['largest'], 'dem' => $s['demand_avg_mw'],
        'pr' => $s['price_avg'], 'co' => $s['carbon_avg'], 'imp' => $s['imports_net_mwh'], 'js' => json_encode($s),
    ]);
}

/** Earliest stored reading for an area, as a UTC 'Y-m-d H:i:s', or null. */
function ukgrid_area_first_ts(PDO $pdo, array $area): ?string
{
    $sql = [
        'gb' => 'SELECT MIN(ts) FROM readings_generation',
        'ie' => 'SELECT MIN(ts) FROM readings_ie_generation',
        'us' => 'SELECT MIN(ts) FROM readings_us_generation',
        'ca' => 'SELECT MIN(ts) FROM readings_ca_generation',
        'au' => 'SELECT MIN(ts) FROM readings_au_generation',
        'entsoe' => 'SELECT MIN(ts) FROM readings_entsoe_generation WHERE country_code = :cc',
    ][$area['kind']];
    try {
        $st = $pdo->prepare($sql);
        $st->execute($area['kind'] === 'entsoe' ? ['cc' => $area['code']] : []);
        $v = $st->fetchColumn();
        return $v ? (string) $v : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * The DAILY job: re-summarises yesterday and today for every area (late
 * data keeps arriving), then works backwards through older days that have
 * readings but no summary yet, a limited number per run. Database only -
 * no outside requests.
 */
function ukgrid_compute_daily(PDO $pdo, array $config, int $timeoutSeconds = 10): array
{
    ukgrid_area_ensure_tables($pdo);
    $areas = ukgrid_areas($config);
    $started = microtime(true);
    $budget = max(3, $timeoutSeconds);
    $rows = 0;
    $errors = [];
    $backfillMax = (int) ($config['refresh']['daily_backfill_days_per_run'] ?? 30);
    $backfilled = 0;

    foreach ($areas as $code => $area) {
        try {
            $today = ukgrid_area_today($area);
            $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
            foreach ([$yesterday, $today] as $day) {
                [$f, $t] = ukgrid_area_day_bounds($area, $day);
                $s = ukgrid_area_day_summary($area, ukgrid_area_buckets($pdo, $area, $f, $t), $day);
                if ($s) {
                    ukgrid_area_save_day($pdo, $code, $s);
                    $rows++;
                }
            }
        } catch (Throwable $e) {
            $errors[] = $code . ': ' . $e->getMessage();
        }
    }

    // Backfill, oldest gap last: walk back from the day before yesterday.
    foreach ($areas as $code => $area) {
        if ($backfilled >= $backfillMax || microtime(true) - $started > $budget) {
            break;
        }
        try {
            $first = ukgrid_area_first_ts($pdo, $area);
            if ($first === null) {
                continue;
            }
            $firstDay = (new DateTimeImmutable($first, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($area['tz']))->format('Y-m-d');
            $have = $pdo->prepare('SELECT day FROM daily_summary WHERE area = :a AND day >= :d');
            $have->execute(['a' => $code, 'd' => $firstDay]);
            $done = array_flip($have->fetchAll(PDO::FETCH_COLUMN));
            $day = (new DateTimeImmutable(ukgrid_area_today($area)))->modify('-2 day');
            $stop = new DateTimeImmutable($firstDay);
            while ($day >= $stop && $backfilled < $backfillMax && microtime(true) - $started <= $budget) {
                $d = $day->format('Y-m-d');
                $day = $day->modify('-1 day');
                if (isset($done[$d])) {
                    continue;
                }
                [$f, $t] = ukgrid_area_day_bounds($area, $d);
                $s = ukgrid_area_day_summary($area, ukgrid_area_buckets($pdo, $area, $f, $t), $d);
                $backfilled++;
                if ($s) {
                    ukgrid_area_save_day($pdo, $code, $s);
                    $rows++;
                } else {
                    // Remember empty days too, so they are not re-checked every run.
                    ukgrid_area_save_day($pdo, $code, ['day' => $d, 'coverage' => 0, 'gen_mwh' => 0, 'renewable_share' => 0,
                        'zero_carbon_share' => 0, 'fossil_share' => 0, 'largest' => null, 'demand_avg_mw' => null,
                        'price_avg' => null, 'carbon_avg' => null, 'imports_net_mwh' => null, 'empty' => true]);
                }
            }
        } catch (Throwable $e) {
            $errors[] = $code . ' backfill: ' . $e->getMessage();
        }
    }

    ukgrid_log_ingest('DAILY', $errors ? 'ERROR' : 'OK', $rows, ($backfilled ? "backfilled {$backfilled} older day(s). " : '') . implode('; ', array_slice($errors, 0, 5)));
    return ['rows' => $rows, 'errors' => $errors];
}

/** Record definitions: key => [label, unit, 'high'|'low', needs]. */
function ukgrid_area_record_defs(array $area): array
{
    $cur = ['GBP' => '£', 'EUR' => '€', 'USD' => '$', 'AUD' => 'A$', 'CAD' => 'C$'][$area['currency']] ?? '';
    $defs = [
        'wind_peak_mw' => ['Highest wind output', 'MW', 'high', 'Generation'],
        'wind_day_mwh' => ['Most wind in a day', 'MWh', 'high', 'Generation'],
        'solar_peak_mw' => ['Highest solar output', 'MW', 'high', 'Generation'],
        'solar_day_mwh' => ['Most solar in a day', 'MWh', 'high', 'Generation'],
        'renewable_share_high' => ['Highest renewable share', '%', 'high', 'Shares'],
        'renewable_day_share' => ['Most renewable day', '%', 'high', 'Shares'],
        'zero_carbon_share_high' => ['Highest zero-carbon share', '%', 'high', 'Shares'],
        'fossil_share_low' => ['Lowest fossil share', '%', 'low', 'Shares'],
        'demand_high_mw' => ['Highest demand', 'MW', 'high', 'Demand'],
        'demand_low_mw' => ['Lowest demand', 'MW', 'low', 'Demand'],
    ];
    if ($area['has_price']) {
        $defs['price_high'] = ['Highest price', $cur . '/MWh', 'high', 'Price'];
        $defs['price_low'] = ['Lowest price', $cur . '/MWh', 'low', 'Price'];
    }
    if (!empty($area['carbon'])) {
        $defs['carbon_low'] = ['Lowest carbon intensity', 'g/kWh', 'low', 'Carbon'];
        $defs['carbon_high'] = ['Highest carbon intensity', 'g/kWh', 'high', 'Carbon'];
    }
    return $defs;
}

/** For the API endpoints: the area named by ?area=, or a JSON 400. */
function ukgrid_api_area(array $config): array
{
    $areas = ukgrid_areas($config);
    $code = strtoupper((string) ($_GET['area'] ?? ''));
    if (!isset($areas[$code])) {
        ukgrid_json_error('bad_area', 'area must be one of: ' . implode(', ', array_keys($areas)));
    }
    return $areas[$code];
}

/** Public fields of an area, for API replies. */
function ukgrid_area_public(array $area): array
{
    return [
        'code' => $area['code'], 'name' => $area['name'], 'tz' => $area['tz'], 'currency' => $area['currency'],
        'page' => $area['page'], 'source' => $area['source'], 'has_demand' => $area['has_demand'],
        'has_price' => $area['has_price'], 'has_carbon' => !empty($area['carbon']),
        'other_label' => $area['other_label'] ?? null, 'renewable_biomass' => !empty($area['renewable_biomass']),
        'bucket_minutes' => (int) ($area['bucket'] / 60),
    ];
}

/** ISO 8601 UTC for a 'Y-m-d H:i:s' UTC string. */
function ukgrid_iso(?string $ts): ?string
{
    return $ts ? gmdate('Y-m-d\TH:i:s\Z', strtotime($ts . ' UTC')) : null;
}

/**
 * Walks every stored day in date order and works out the records: the
 * best value of each kind, the record it beat, each year's best, and
 * every time a record was broken.
 */
function ukgrid_area_records(PDO $pdo, array $area, bool $allBreaks = false): array
{
    ukgrid_area_ensure_tables($pdo);
    $defs = ukgrid_area_record_defs($area);
    $st = $pdo->prepare('SELECT day, coverage, summary_json FROM daily_summary WHERE area = :a AND gen_mwh > 0 ORDER BY day');
    $st->execute(['a' => $area['code']]);
    $best = [];
    $prev = [];
    $years = [];
    $grid = [];
    $breaks = [];
    $first = null;
    $dailyKinds = ['wind_day_mwh', 'solar_day_mwh', 'renewable_day_share'];
    foreach ($st as $row) {
        $first = $first ?? $row['day'];
        $s = json_decode($row['summary_json'], true);
        if (!is_array($s) || empty($s['extremes'])) {
            continue;
        }
        $year = substr($row['day'], 0, 4);
        $years[$year] = true;
        foreach ($defs as $key => [$label, $unit, $dir]) {
            if (!isset($s['extremes'][$key])) {
                continue;
            }
            if (in_array($key, $dailyKinds, true) && (float) $row['coverage'] < 0.9) {
                continue; // a whole-day record needs (nearly) the whole day
            }
            [$v, $ts] = $s['extremes'][$key];
            $v = (float) $v;
            $isBetter = function ($a, $b) use ($dir) { return $dir === 'high' ? $a > $b : $a < $b; };
            if (!isset($grid[$key][$year]) || $isBetter($v, $grid[$key][$year])) {
                $grid[$key][$year] = $v;
            }
            if (!isset($best[$key]) || $isBetter($v, $best[$key]['value'])) {
                if (isset($best[$key])) {
                    $prev[$key] = $best[$key];
                    $breaks[] = ['key' => $key, 'label' => $label, 'unit' => $unit, 'value' => $v, 'ts' => $ts, 'day' => $row['day'],
                                 'previous' => $best[$key]['value'], 'previous_ts' => $best[$key]['ts']];
                }
                $best[$key] = ['value' => $v, 'ts' => $ts, 'day' => $row['day']];
            }
        }
    }
    $iso = function ($ts) {
        return strlen((string) $ts) === 10 ? $ts : ukgrid_iso($ts);
    };
    $board = [];
    foreach ($defs as $key => [$label, $unit, $dir, $group]) {
        if (!isset($best[$key])) {
            continue;
        }
        $board[] = ['key' => $key, 'label' => $label, 'unit' => $unit, 'direction' => $dir, 'group' => $group,
                    'value' => $best[$key]['value'], 'ts' => $iso($best[$key]['ts']), 'day' => $best[$key]['day'],
                    'previous' => $prev[$key]['value'] ?? null, 'previous_ts' => isset($prev[$key]) ? $iso($prev[$key]['ts']) : null];
    }
    // Recent breaks, newest first, ignoring the first 30 days of records
    // (everything is a "record" while there is little history).
    $settle = $first ? date('Y-m-d', strtotime($first . ' +30 days')) : null;
    $recent = array_values(array_filter($breaks, function ($b) use ($settle) { return $settle && $b['day'] >= $settle; }));
    usort($recent, function ($a, $b) { return strcmp($b['day'], $a['day']); });
    $recent = array_map(function ($b) use ($iso) {
        $b['ts'] = $iso($b['ts']);
        $b['previous_ts'] = $iso($b['previous_ts']);
        return $b;
    }, array_slice($recent, 0, 12));
    $yearList = array_keys($years);
    sort($yearList);
    $out = ['since' => $first, 'board' => $board, 'years' => $yearList, 'grid' => $grid, 'recent' => $recent];
    if ($allBreaks) {
        $out['breaks'] = array_map(function ($b) use ($iso) {
            $b['ts'] = $iso($b['ts']);
            $b['previous_ts'] = $iso($b['previous_ts']);
            return $b;
        }, $breaks);
    }
    return $out;
}
