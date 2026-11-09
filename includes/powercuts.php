<?php
/**
 * Live power cuts in Great Britain, from the distribution network operators
 * (DNOs) that publish them as open data.
 *
 *   NGED  National Grid Electricity Distribution (Midlands, South West,
 *         South Wales). CSV, NGED Open Data Licence: "Supported by NGED
 *         Open Data".
 *   UKPN  UK Power Networks (London, South East, East). Opendatasoft API,
 *         CC BY 4.0.
 *   NPG   Northern Powergrid (North East, Yorkshire). Opendatasoft API,
 *         Northern Powergrid Open Data Licence: "Supported by Northern
 *         Powergrid Open Data".
 *   SSEN  Scottish and Southern Electricity Networks (North Scotland,
 *         Southern England). JSON API, CC BY 4.0.
 *   SPEN  SP Energy Networks (Central and South Scotland, Merseyside and
 *         North Wales). Opendatasoft, CC BY 4.0. Needs a free API key in
 *         config.php ('powercuts' => ['spen_key' => ...]). Gives postcode
 *         sectors, not map positions.
 *   ENWL  Electricity North West. Opendatasoft, CC BY 4.0. Needs a free API
 *         key in config.php ('powercuts' => ['enwl_key' => ...]).
 *
 * Job POWERCUTS runs every 5 minutes. On a schedule it fetches every
 * operator each time (the feeds are small); a page load fetches just the one
 * fetched longest ago.
 * Each cut is stored with seen_at = the fetch time; the page shows the cuts
 * from each operator's latest fetch. Planned work that has not started yet
 * is left out.
 */

function ukgrid_powercuts_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    ukgrid_flows_annual_ensure_tables($pdo); // fetch_state
    $pdo->exec("CREATE TABLE IF NOT EXISTS power_cut (
        dno VARCHAR(6) NOT NULL,
        ref VARCHAR(40) NOT NULL,
        planned TINYINT NOT NULL DEFAULT 0,
        customers INT NULL,
        lat DECIMAL(8,5) NULL,
        lon DECIMAL(8,5) NULL,
        place VARCHAR(80) NULL COMMENT 'postcode or area name as given',
        started DATETIME NULL COMMENT 'UTC',
        etr DATETIME NULL COMMENT 'estimated restoration, UTC',
        seen_at DATETIME NOT NULL COMMENT 'UTC fetch time',
        PRIMARY KEY (dno, ref),
        KEY idx_seen (dno, seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS power_cut_count (
        dno VARCHAR(6) NOT NULL,
        ts DATETIME NOT NULL COMMENT 'UTC fetch time',
        cuts INT NOT NULL,
        planned INT NOT NULL,
        customers INT NULL,
        PRIMARY KEY (dno, ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** The operators, in display order, with what each needs. */
function ukgrid_powercuts_dnos(array $config): array
{
    $pc = $config['powercuts'] ?? [];
    return [
        'NGED' => ['name' => 'National Grid Electricity Distribution', 'ready' => true],
        'UKPN' => ['name' => 'UK Power Networks', 'ready' => true],
        'NPG' => ['name' => 'Northern Powergrid', 'ready' => true],
        'SSEN' => ['name' => 'Scottish and Southern Electricity Networks', 'ready' => true],
        'SPEN' => ['name' => 'SP Energy Networks', 'ready' => !empty($pc['spen_key'])],
        'ENWL' => ['name' => 'Electricity North West', 'ready' => !empty($pc['enwl_key'])],
    ];
}

/** A time from a feed, as UTC. Times with no offset are UK local time. */
function ukgrid_pc_time($v): ?string
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    try {
        $hasZone = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $v);
        $d = new DateTime($v, $hasZone ? null : new DateTimeZone('Europe/London'));
        $d->setTimezone(new DateTimeZone('UTC'));
        return $d->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

function ukgrid_pc_num($v): ?int
{
    return is_numeric($v) ? (int) $v : null;
}

/** Parses CSV text into a list of associative rows. */
function ukgrid_pc_csv(string $text): array
{
    $lines = preg_split('/\r\n|\n|\r/', trim($text));
    if (!$lines) {
        return [];
    }
    $head = str_getcsv(array_shift($lines));
    $out = [];
    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }
        $cells = str_getcsv($line);
        if (count($cells) !== count($head)) {
            continue;
        }
        $out[] = array_combine($head, $cells);
    }
    return $out;
}

/** Each parser returns rows: [ref, planned, customers, lat, lon, place, started, etr]. */
function ukgrid_pc_parse_nged(array $rows, int $now): array
{
    $out = [];
    foreach ($rows as $r) {
        $planned = strtolower((string) ($r['planned'] ?? '')) === 'true';
        $start = ukgrid_pc_time($planned && !empty($r['planned_outage_start_date']) ? $r['planned_outage_start_date'] : ($r['date_of_reported_fault'] ?? null));
        if ($planned && $start && strtotime($start . ' UTC') > $now) {
            continue; // planned work not started yet
        }
        if (!empty($r['date_of_restoration'])) {
            continue;
        }
        $cust = (ukgrid_pc_num($r['confirmed_off'] ?? null) ?? 0) + (ukgrid_pc_num($r['predicted_off'] ?? null) ?? 0);
        $pc = trim(explode(',', (string) ($r['postcode'] ?? ''))[0]);
        $out[] = [(string) ($r['fault_id'] ?? ''), $planned, $cust, $r['location_latitude'] ?? null, $r['location_longitude'] ?? null,
                  $pc !== '' ? $pc : ($r['licence_area'] ?? null), $start, ukgrid_pc_time($r['etr'] ?? null)];
    }
    return $out;
}

function ukgrid_pc_parse_ukpn(array $records, int $now): array
{
    $out = [];
    foreach ($records as $r) {
        if (!empty($r['restoreddatetime']) || stripos((string) ($r['powercuttype'] ?? ''), 'restored') !== false) {
            continue;
        }
        $planned = stripos((string) ($r['powercuttype'] ?? ''), 'planned') === 0;
        $start = ukgrid_pc_time($planned ? ($r['planneddate'] ?? $r['creationdatetime'] ?? null) : ($r['receiveddate'] ?? $r['creationdatetime'] ?? null));
        if ($planned && $start && strtotime($start . ' UTC') > $now) {
            continue;
        }
        $cust = $planned ? ukgrid_pc_num($r['noplannedcustomers'] ?? null) : ukgrid_pc_num($r['nocustomeraffected'] ?? null);
        $gp = $r['geopoint'] ?? [];
        $place = trim(explode(';', (string) ($r['postcodesaffected'] ?? ''))[0]);
        $out[] = [(string) ($r['incidentreference'] ?? ''), $planned, $cust, $gp['lat'] ?? null, $gp['lon'] ?? null,
                  $place !== '' ? $place : ($r['operatingzone'] ?? null), $start, ukgrid_pc_time($r['estimatedrestorationdate'] ?? null)];
    }
    return $out;
}

function ukgrid_pc_parse_npg(array $records, int $now): array
{
    $out = [];
    foreach ($records as $r) {
        $planned = stripos((string) ($r['natureofoutage'] ?? ''), 'planned') !== false;
        $start = ukgrid_pc_time($r['loggedtime'] ?? null);
        if ($start && strtotime($start . ' UTC') > $now) {
            continue;
        }
        $cust = (ukgrid_pc_num($r['totalconfirmedpowercut'] ?? null) ?? 0) + (ukgrid_pc_num($r['totalpredictedpowercut'] ?? null) ?? 0);
        $pcs = $r['postcode'] ?? [];
        $place = is_array($pcs) ? trim((string) ($pcs[0] ?? '')) : trim((string) $pcs);
        $out[] = [(string) ($r['reference'] ?? $r['id'] ?? ''), $planned, $cust, $r['lat'] ?? null, $r['lng'] ?? null,
                  $place !== '' ? preg_replace('/\s+/', ' ', $place) : ($r['area'] ?? null), $start, ukgrid_pc_time($r['estimatedtimetillresolution'] ?? null)];
    }
    return $out;
}

function ukgrid_pc_parse_ssen(array $data, int $now): array
{
    $out = [];
    foreach (($data['faults'] ?? []) as $r) {
        // PSI = planned supply interruption (SSEN's own term).
        $planned = strtoupper((string) ($r['type'] ?? '')) === 'PSI';
        $start = ukgrid_pc_time($r['loggedAtUtc'] ?? null);
        if ($planned && $start && strtotime($start . ' UTC') > $now) {
            continue;
        }
        $loc = $r['location'] ?? [];
        $areas = $r['affectedAreas'] ?? [];
        $out[] = [(string) ($r['reference'] ?? ''), $planned, ukgrid_pc_num($r['customerCount'] ?? null), $loc['latitude'] ?? null, $loc['longitude'] ?? null,
                  is_array($areas) && $areas ? (string) $areas[0] : ($r['title'] ?? null), $start, ukgrid_pc_time($r['estimatedRestorationTimeUtc'] ?? null)];
    }
    return $out;
}

function ukgrid_pc_parse_spen(array $records, int $now): array
{
    $out = [];
    foreach ($records as $r) {
        $planned = in_array(strtolower((string) ($r['planned'] ?? '')), ['true', '1', 'yes', 'y'], true);
        $start = ukgrid_pc_time($planned ? ($r['planned_outage_start_date'] ?? null) : ($r['date_of_reported_fault'] ?? null));
        if ($planned && $start && strtotime($start . ' UTC') > $now) {
            continue;
        }
        $out[] = [(string) ($r['fault_id'] ?? ''), $planned, null, null, null, $r['postcode_sector'] ?? ($r['region'] ?? null), $start, ukgrid_pc_time($r['etr'] ?? null)];
    }
    return $out;
}

function ukgrid_pc_parse_enwl(array $records, int $now): array
{
    $out = [];
    foreach ($records as $r) {
        $planned = stripos((string) ($r['incident_type'] ?? ''), 'planned') !== false;
        $start = ukgrid_pc_time($r['outage_time'] ?? null);
        if ($planned && $start && strtotime($start . ' UTC') > $now) {
            continue;
        }
        $gp = $r['geo_point_2d'] ?? [];
        $out[] = [(string) ($r['incident_num'] ?? ''), $planned, ukgrid_pc_num($r['customers_off_supply'] ?? $r['customers_affected'] ?? null), $gp['lat'] ?? null, $gp['lon'] ?? null,
                  null, $start, ukgrid_pc_time($r['estimated_restoration_time'] ?? null)];
    }
    return $out;
}

/** All records from an Opendatasoft dataset, 100 at a time (up to 1,000). */
function ukgrid_pc_ods(string $host, string $dataset, int $timeout, ?string $apiKey = null): ?array
{
    $all = [];
    for ($offset = 0; $offset < 1000; $offset += 100) {
        $url = 'https://' . $host . '/api/explore/v2.1/catalog/datasets/' . $dataset . '/records?limit=100&offset=' . $offset;
        $headers = $apiKey ? ['Authorization: Apikey ' . $apiKey] : [];
        $j = ukgrid_http_get_json($url, $timeout, $headers);
        if ($j === null) {
            return $offset === 0 ? null : $all;
        }
        $batch = $j['results'] ?? [];
        $all = array_merge($all, $batch);
        if (count($batch) < 100 || count($all) >= (int) ($j['total_count'] ?? 0)) {
            break;
        }
    }
    return $all;
}

function ukgrid_pc_fetch(string $dno, array $config, int $timeout, int $now): ?array
{
    $pc = $config['powercuts'] ?? [];
    switch ($dno) {
        case 'NGED':
            $csv = ukgrid_http_get_raw('https://connecteddata.nationalgrid.co.uk/dataset/d6672e1e-c684-4cea-bb78-c7e5248b62a2/resource/a1365982-4e05-463c-8304-8323a2ba0ccd/download/live_detailed_power_cuts.csv', $timeout, ['Accept: text/csv']);
            return $csv === null ? null : ukgrid_pc_parse_nged(ukgrid_pc_csv($csv), $now);
        case 'UKPN':
            $r = ukgrid_pc_ods('ukpowernetworks.opendatasoft.com', 'ukpn-live-faults', $timeout);
            return $r === null ? null : ukgrid_pc_parse_ukpn($r, $now);
        case 'NPG':
            $r = ukgrid_pc_ods('northernpowergrid.opendatasoft.com', 'live-power-cuts-data', $timeout);
            return $r === null ? null : ukgrid_pc_parse_npg($r, $now);
        case 'SSEN':
            $j = ukgrid_http_get_json('https://external.distribution.prd.ssen.co.uk/opendataportal-prd/v4/api/getallfaults', $timeout);
            return $j === null ? null : ukgrid_pc_parse_ssen($j, $now);
        case 'SPEN':
            $r = ukgrid_pc_ods('spenergynetworks.opendatasoft.com', 'distribution-network-live-outages', $timeout, (string) ($pc['spen_key'] ?? ''));
            return $r === null ? null : ukgrid_pc_parse_spen($r, $now);
        case 'ENWL':
            $r = ukgrid_pc_ods('electricitynorthwest.opendatasoft.com', 'live_incidents', $timeout, (string) ($pc['enwl_key'] ?? ''));
            return $r === null ? null : ukgrid_pc_parse_enwl($r, $now);
    }
    return null;
}

function ukgrid_pc_store(PDO $pdo, string $dno, array $rows, string $seenAt): int
{
    $st = $pdo->prepare('INSERT INTO power_cut (dno, ref, planned, customers, lat, lon, place, started, etr, seen_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE planned = VALUES(planned), customers = VALUES(customers), lat = VALUES(lat), lon = VALUES(lon),
        place = VALUES(place), started = VALUES(started), etr = VALUES(etr), seen_at = VALUES(seen_at)');
    $n = 0;
    $cuts = 0;
    $planned = 0;
    $cust = 0;
    $custKnown = false;
    $seen = [];
    foreach ($rows as [$ref, $isPlanned, $customers, $lat, $lon, $place, $start, $etr]) {
        $ref = mb_substr(trim($ref), 0, 40);
        if ($ref === '' || isset($seen[$ref])) {
            continue;
        }
        $seen[$ref] = true;
        $lat = is_numeric($lat) && abs((float) $lat) > 1 ? round((float) $lat, 5) : null;
        $lon = is_numeric($lon) && $lat !== null ? round((float) $lon, 5) : null;
        $st->execute([$dno, $ref, $isPlanned ? 1 : 0, $customers, $lat, $lon, $place !== null ? mb_substr((string) $place, 0, 80) : null, $start, $etr, $seenAt]);
        $n++;
        $cuts++;
        $planned += $isPlanned ? 1 : 0;
        if ($customers !== null) {
            $cust += $customers;
            $custKnown = true;
        }
    }
    $pdo->prepare('INSERT INTO power_cut_count (dno, ts, cuts, planned, customers) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE cuts = VALUES(cuts), planned = VALUES(planned), customers = VALUES(customers)')
        ->execute([$dno, $seenAt, $cuts, $planned, $custKnown ? $cust : null]);
    return $n;
}

/**
 * Fetches up to $maxDnos operators, the ones fetched longest ago first.
 * $only fetches just that operator (tools and tests).
 */
function ukgrid_ingest_powercuts(PDO $pdo, array $config, int $timeoutSeconds = 6, int $maxDnos = 2, ?string $only = null): array
{
    ukgrid_powercuts_ensure_tables($pdo);
    $state = ukgrid_fetch_state_get($pdo, 'POWERCUTS');
    $dnos = array_filter(ukgrid_powercuts_dnos($config), function ($d) { return $d['ready']; });
    $order = array_keys($dnos);
    if ($only !== null) {
        $order = array_values(array_intersect($order, [$only]));
    } else {
        usort($order, function ($a, $b) use ($state) {
            return strcmp($state[$a]['fetched_at'] ?? '0', $state[$b]['fetched_at'] ?? '0');
        });
        $order = array_slice($order, 0, $maxDnos);
    }
    $now = time();
    $total = 0;
    $notes = [];
    $failed = 0;
    foreach ($order as $dno) {
        $rows = ukgrid_pc_fetch($dno, $config, $timeoutSeconds, $now);
        if ($rows === null) {
            ukgrid_fetch_state_set($pdo, 'POWERCUTS', $dno, 'ERROR', 'request failed (see refresh.log)');
            $notes[] = $dno . ' failed';
            $failed++;
            continue;
        }
        $n = ukgrid_pc_store($pdo, $dno, $rows, gmdate('Y-m-d H:i:s', $now));
        ukgrid_fetch_state_set($pdo, 'POWERCUTS', $dno, 'OK', $n . ' cuts');
        $notes[] = $dno . ' ' . $n;
        $total += $n;
    }
    // Keep 3 days of cuts and 35 days of counts.
    $pdo->exec("DELETE FROM power_cut WHERE seen_at < UTC_TIMESTAMP() - INTERVAL 3 DAY");
    $pdo->exec("DELETE FROM power_cut_count WHERE ts < UTC_TIMESTAMP() - INTERVAL 35 DAY");
    ukgrid_log_ingest('POWERCUTS', $failed && $failed === count($order) ? 'ERROR' : 'OK', $total, implode(', ', $notes));
    return ['ok' => $failed < count($order), 'rows' => $total];
}
