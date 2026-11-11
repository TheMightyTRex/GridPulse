<?php
/**
 * Power stations in the UK, with live planned output where it can be matched.
 *
 * Two jobs (added November 2026):
 *   STATIONS     every 7 days, 1 request (about 1.2 MB). Downloads DESNZ's
 *                "Power stations in the United Kingdom" spreadsheet (DUKES
 *                table 5.11, Open Government Licence v3.0) and stores the
 *                "5.11 Full list" sheet: every major power producer station
 *                with its fuel, capacity, owner and location. DESNZ updates
 *                it once a year, at the end of July; put the new address in
 *                config 'stations' => ['dukes_url' => ...] when it does.
 *   STATIONSLIVE every 30 minutes, 1 request (about 600 KB). Elexon's
 *                physical notifications (PN) for the current half hour: the
 *                output each unit has told the system operator it plans to
 *                run at. Kept only for the units listed in
 *                includes/station_bmus.json, for 2 days.
 */

const UKGRID_DUKES_511_URL = 'https://assets.publishing.service.gov.uk/media/6a6a365b0ddb7e4831c629f3/DUKES_5.11.xlsx';
const UKGRID_DUKES_511_EDITION = 'DUKES 2026, table 5.11 (stations operating at the end of May 2026)';

function ukgrid_stations_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS power_station (
        code VARCHAR(16) NOT NULL COMMENT 'DESNZ site code',
        company VARCHAR(120) NOT NULL,
        site VARCHAR(120) NOT NULL,
        tech VARCHAR(24) NOT NULL,
        type VARCHAR(32) NULL,
        fuel VARCHAR(40) NULL,
        cap_mw DECIMAL(8,2) NULL,
        conn VARCHAR(8) NULL,
        country VARCHAR(20) NULL,
        region VARCHAR(40) NULL,
        lat DECIMAL(8,5) NOT NULL,
        lon DECIMAL(8,5) NOT NULL,
        year SMALLINT NULL,
        PRIMARY KEY (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS station_pn (
        bmu VARCHAR(16) NOT NULL,
        ts DATETIME NOT NULL COMMENT 'UTC, start of the half hour',
        mw DECIMAL(8,1) NOT NULL COMMENT 'average planned output over the half hour',
        PRIMARY KEY (bmu, ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * British National Grid easting/northing (OSGB36) to WGS84 latitude and
 * longitude. Transverse Mercator inverse on the Airy 1830 ellipsoid, then
 * a Helmert transform (accurate to a few metres, plenty for a map).
 */
function ukgrid_osgb_to_wgs84(float $E, float $N): array
{
    $a = 6377563.396; $b = 6356256.909; $F0 = 0.9996012717;
    $lat0 = deg2rad(49); $lon0 = deg2rad(-2); $N0 = -100000; $E0 = 400000;
    $e2 = 1 - ($b * $b) / ($a * $a); $n = ($a - $b) / ($a + $b);
    $lat = $lat0; $M = 0;
    do {
        $lat = ($N - $N0 - $M) / ($a * $F0) + $lat;
        $Ma = (1 + $n + (5 / 4) * $n ** 2 + (5 / 4) * $n ** 3) * ($lat - $lat0);
        $Mb = (3 * $n + 3 * $n ** 2 + (21 / 8) * $n ** 3) * sin($lat - $lat0) * cos($lat + $lat0);
        $Mc = ((15 / 8) * $n ** 2 + (15 / 8) * $n ** 3) * sin(2 * ($lat - $lat0)) * cos(2 * ($lat + $lat0));
        $Md = (35 / 24) * $n ** 3 * sin(3 * ($lat - $lat0)) * cos(3 * ($lat + $lat0));
        $M = $b * $F0 * ($Ma - $Mb + $Mc - $Md);
    } while ($N - $N0 - $M >= 0.00001);
    $sl = sin($lat); $cl = cos($lat); $tl = tan($lat);
    $nu = $a * $F0 / sqrt(1 - $e2 * $sl * $sl);
    $rho = $a * $F0 * (1 - $e2) / pow(1 - $e2 * $sl * $sl, 1.5);
    $eta2 = $nu / $rho - 1;
    $VII = $tl / (2 * $rho * $nu);
    $VIII = $tl / (24 * $rho * $nu ** 3) * (5 + 3 * $tl ** 2 + $eta2 - 9 * $tl ** 2 * $eta2);
    $IX = $tl / (720 * $rho * $nu ** 5) * (61 + 90 * $tl ** 2 + 45 * $tl ** 4);
    $X = 1 / ($cl * $nu);
    $XI = 1 / ($cl * 6 * $nu ** 3) * ($nu / $rho + 2 * $tl ** 2);
    $XII = 1 / ($cl * 120 * $nu ** 5) * (5 + 28 * $tl ** 2 + 24 * $tl ** 4);
    $XIIA = 1 / ($cl * 5040 * $nu ** 7) * (61 + 662 * $tl ** 2 + 1320 * $tl ** 4 + 720 * $tl ** 6);
    $dE = $E - $E0;
    $phi = $lat - $VII * $dE ** 2 + $VIII * $dE ** 4 - $IX * $dE ** 6;
    $lam = $lon0 + $X * $dE - $XI * $dE ** 3 + $XII * $dE ** 5 - $XIIA * $dE ** 7;
    // OSGB36 lat/lon -> cartesian (Airy) -> Helmert -> WGS84 (GRS80).
    $sp = sin($phi); $cp = cos($phi);
    $nuA = $a / sqrt(1 - $e2 * $sp * $sp);
    $x1 = $nuA * $cp * cos($lam); $y1 = $nuA * $cp * sin($lam); $z1 = (1 - $e2) * $nuA * $sp;
    $s = 20.4894e-6; $tx = 446.448; $ty = -125.157; $tz = 542.060;
    $rx = deg2rad(0.1502 / 3600); $ry = deg2rad(0.2470 / 3600); $rz = deg2rad(0.8421 / 3600);
    $x2 = $tx + (1 + $s) * $x1 - $rz * $y1 + $ry * $z1;
    $y2 = $ty + $rz * $x1 + (1 + $s) * $y1 - $rx * $z1;
    $z2 = $tz - $ry * $x1 + $rx * $y1 + (1 + $s) * $z1;
    $a2 = 6378137.0; $b2 = 6356752.3141; $e22 = 1 - ($b2 * $b2) / ($a2 * $a2);
    $p = sqrt($x2 * $x2 + $y2 * $y2);
    $phi2 = atan2($z2, $p * (1 - $e22));
    for ($i = 0; $i < 10; $i++) {
        $nu2 = $a2 / sqrt(1 - $e22 * sin($phi2) ** 2);
        $phi2 = atan2($z2 + $e22 * $nu2 * sin($phi2), $p);
    }
    return [rad2deg($phi2), rad2deg(atan2($y2, $x2))];
}

/** Rows of one sheet of an .xlsx file, by sheet name. Null if it can't be read. */
function ukgrid_xlsx_sheet(string $path, string $sheetName): ?array
{
    if (!class_exists('ZipArchive')) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return null;
    }
    $wb = @simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
    $rels = @simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
    if (!$wb || !$rels) {
        return null;
    }
    $rid = null;
    foreach ($wb->sheets->sheet as $sh) {
        if ((string) $sh['name'] === $sheetName) {
            $rid = (string) $sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        }
    }
    $target = null;
    foreach ($rels->Relationship as $r) {
        if ((string) $r['Id'] === $rid) {
            $target = ltrim(preg_replace('#^/?xl/#', '', (string) $r['Target']), '/');
        }
    }
    if ($target === null) {
        return null;
    }
    $strings = [];
    $ss = @simplexml_load_string((string) $zip->getFromName('xl/sharedStrings.xml'));
    if ($ss) {
        foreach ($ss->si as $si) {
            $t = '';
            if (isset($si->t)) {
                $t = (string) $si->t;
            }
            foreach ($si->r as $run) {
                $t .= (string) $run->t;
            }
            $strings[] = $t;
        }
    }
    $sheet = @simplexml_load_string((string) $zip->getFromName('xl/' . $target));
    $zip->close();
    if (!$sheet) {
        return null;
    }
    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $out = [];
        foreach ($row->c as $c) {
            $ref = preg_replace('/\d+/', '', (string) $c['r']);
            $col = 0;
            foreach (str_split($ref) as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            $v = isset($c->v) ? (string) $c->v : (isset($c->is->t) ? (string) $c->is->t : '');
            if ((string) $c['t'] === 's') {
                $v = $strings[(int) $v] ?? '';
            }
            $out[$col - 1] = $v;
        }
        $rows[] = $out;
    }
    return $rows;
}

function ukgrid_ingest_stations(PDO $pdo, array $config, int $timeoutSeconds = 30): array
{
    ukgrid_stations_ensure_tables($pdo);
    $url = (string) ($config['stations']['dukes_url'] ?? UKGRID_DUKES_511_URL);
    $body = ukgrid_http_get_raw($url, $timeoutSeconds, ['Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    if ($body === null || substr($body, 0, 2) !== 'PK') {
        ukgrid_log_ingest('STATIONS', 'ERROR', 0, 'DUKES 5.11 download failed or was not a spreadsheet (see refresh.log)');
        return ['ok' => false];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'dukes');
    file_put_contents($tmp, $body);
    $rows = ukgrid_xlsx_sheet($tmp, '5.11 Full list');
    @unlink($tmp);
    if ($rows === null) {
        ukgrid_log_ingest('STATIONS', 'ERROR', 0, class_exists('ZipArchive') ? 'No "5.11 Full list" sheet in the spreadsheet' : 'PHP zip extension (ZipArchive) is not available on this server');
        return ['ok' => false];
    }
    // Find the header row and the columns by name, so a reordered sheet still works.
    $head = null;
    $cols = [];
    foreach ($rows as $i => $r) {
        $joined = strtolower(implode('|', $r));
        if (strpos($joined, 'site name') !== false && strpos($joined, 'x-coordinate') !== false) {
            $head = $i;
            foreach ($r as $ci => $h) {
                $h = strtolower(preg_replace('/\s*\[.*?\]\s*/', '', $h));
                $map = ['company name' => 'company', 'site name' => 'site', 'technology' => 'tech', 'type' => 'type', 'primary fuel' => 'fuel',
                    'installedcapacity (mw)' => 'cap', 'installed capacity (mw)' => 'cap', 'grid connection type' => 'conn', 'country' => 'country',
                    'region' => 'region', 'x-coordinate' => 'x', 'y-coordinate' => 'y', 'year commissioned' => 'year', 'desnz site code' => 'code'];
                if (isset($map[trim($h)])) {
                    $cols[$map[trim($h)]] = $ci;
                }
            }
            break;
        }
    }
    foreach (['company', 'site', 'tech', 'cap', 'x', 'y', 'code'] as $need) {
        if (!isset($cols[$need])) {
            ukgrid_log_ingest('STATIONS', 'ERROR', 0, 'Column not found in DUKES 5.11: ' . $need);
            return ['ok' => false];
        }
    }
    $get = function (array $r, string $k) use ($cols) {
        return isset($cols[$k]) ? trim((string) ($r[$cols[$k]] ?? '')) : '';
    };
    $st = $pdo->prepare('INSERT INTO power_station (code, company, site, tech, type, fuel, cap_mw, conn, country, region, lat, lon, year)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM power_station');
    $n = 0;
    foreach (array_slice($rows, $head + 1) as $r) {
        $code = $get($r, 'code');
        $x = $get($r, 'x');
        $y = $get($r, 'y');
        if ($code === '' || !is_numeric($x) || !is_numeric($y)) {
            continue;
        }
        [$lat, $lon] = ukgrid_osgb_to_wgs84((float) $x, (float) $y);
        $cap = $get($r, 'cap');
        $year = $get($r, 'year');
        $st->execute([substr($code, 0, 16), mb_substr($get($r, 'company'), 0, 120), mb_substr($get($r, 'site'), 0, 120), mb_substr($get($r, 'tech'), 0, 24),
            mb_substr($get($r, 'type'), 0, 32) ?: null, mb_substr($get($r, 'fuel'), 0, 40) ?: null, is_numeric($cap) ? round((float) $cap, 2) : null,
            mb_substr($get($r, 'conn'), 0, 8) ?: null, mb_substr($get($r, 'country'), 0, 20) ?: null, mb_substr($get($r, 'region'), 0, 40) ?: null,
            round($lat, 5), round($lon, 5), is_numeric($year) ? (int) $year : null]);
        $n++;
    }
    if ($n < 100) {
        $pdo->rollBack();
        ukgrid_log_ingest('STATIONS', 'ERROR', 0, "Only {$n} stations read, kept the previous list");
        return ['ok' => false];
    }
    $pdo->commit();
    ukgrid_log_ingest('STATIONS', 'OK', $n, $n . ' stations from DUKES 5.11');
    return ['ok' => true, 'rows' => $n];
}

/** [DESNZ site code => [BM unit, ...]] from includes/station_bmus.json. */
function ukgrid_station_bmus(): array
{
    static $m = null;
    if ($m === null) {
        $j = json_decode((string) @file_get_contents(__DIR__ . '/station_bmus.json'), true);
        $m = is_array($j['stations'] ?? null) ? $j['stations'] : [];
    }
    return $m;
}

function ukgrid_ingest_stations_live(PDO $pdo, array $config, int $timeoutSeconds = 15): array
{
    ukgrid_stations_ensure_tables($pdo);
    $want = [];
    foreach (ukgrid_station_bmus() as $units) {
        foreach ($units as $u) {
            $want[$u] = true;
        }
    }
    $elexon = rtrim($config['sources']['elexon_base'] ?? 'https://data.elexon.co.uk/bmrs/api/v1', '/');
    // The current settlement period, UK time: period 1 starts at midnight.
    $uk = new DateTime('now', new DateTimeZone('Europe/London'));
    $midnight = (clone $uk)->setTime(0, 0);
    $sp = intdiv($uk->getTimestamp() - $midnight->getTimestamp(), 1800) + 1;
    $url = $elexon . '/balancing/physical/all?dataset=PN&settlementDate=' . $uk->format('Y-m-d') . '&settlementPeriod=' . $sp . '&format=json';
    $data = ukgrid_http_get_json($url, $timeoutSeconds);
    $points = $data['data'] ?? null;
    if (!is_array($points)) {
        ukgrid_log_ingest('STATIONSLIVE', 'ERROR', 0, 'Elexon physical notifications request failed (see refresh.log)');
        return ['ok' => false];
    }
    // A unit can send several steps within the half hour: average them, weighted by time.
    $acc = [];
    foreach ($points as $p) {
        $u = (string) ($p['bmUnit'] ?? '');
        if (!isset($want[$u])) {
            continue;
        }
        $f = strtotime((string) ($p['timeFrom'] ?? ''));
        $t = strtotime((string) ($p['timeTo'] ?? ''));
        if (!$f || !$t || $t <= $f || !is_numeric($p['levelFrom'] ?? null) || !is_numeric($p['levelTo'] ?? null)) {
            continue;
        }
        $start = intdiv($f, 1800) * 1800;
        $acc[$u][$start][0] = ($acc[$u][$start][0] ?? 0) + (($p['levelFrom'] + $p['levelTo']) / 2) * ($t - $f);
        $acc[$u][$start][1] = ($acc[$u][$start][1] ?? 0) + ($t - $f);
    }
    $st = $pdo->prepare('INSERT INTO station_pn (bmu, ts, mw) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE mw = VALUES(mw)');
    $n = 0;
    foreach ($acc as $u => $byTs) {
        foreach ($byTs as $start => [$sum, $secs]) {
            $st->execute([$u, gmdate('Y-m-d H:i:s', $start), round($sum / $secs, 1)]);
            $n++;
        }
    }
    $pdo->exec("DELETE FROM station_pn WHERE ts < UTC_TIMESTAMP() - INTERVAL 2 DAY");
    ukgrid_log_ingest('STATIONSLIVE', 'OK', $n, $n . ' of ' . count($want) . ' units, period ' . $sp);
    return ['ok' => true, 'rows' => $n];
}
