<?php
/**
 * The three data-source ingestion routines, shared by:
 *   - cron/fetch_*.php (a traditional scheduled cron job runs these), and
 *   - includes/refresh.php (an on-demand refresh triggered by a page load,
 *     for hosting that doesn't offer cron jobs at all).
 *
 * Each function does its own logging to ingest_log and returns
 * ['rows' => int, 'errors' => string[]] - callers don't need to log
 * anything themselves.
 */

require_once __DIR__ . '/http.php';

/**
 * Shared by every ukgrid_ingest_*() function that makes more than one
 * sequential HTTP request per run (EIRGRID: 5, EIA: 2, ENTSOE: up to 17) -
 * registers a shutdown-time safety net so that if the run gets killed
 * partway through (a PHP time/memory limit, the host cutting off a
 * long-running cron job, etc.), whatever partial progress was made still
 * gets logged to ingest_log instead of leaving zero trace.
 *
 * Without this, the only ukgrid_log_ingest() call for these functions is
 * the one after their loop finishes - so an interrupted run looks
 * identical, from ingest_log/api/status.php's point of view, to "nothing
 * was attempted this cycle" rather than "got partway through and stopped".
 * That gap is exactly what let a real occurrence of it (ukgrid_ingest_entsoe()
 * getting killed by cron's shared time limit partway through its country
 * loop - see cron/fetch_entsoe.php's docblock) go unnoticed until it showed
 * up as "some countries never go live" on the frontend instead, with
 * nothing in the footer's pipeline-status pill pointing to why.
 *
 * register_shutdown_function still runs when a script is killed by
 * set_time_limit, the same mechanism api/_bootstrap.php already relies on
 * for its own fatal-error handler.
 *
 * Usage: call this once near the top of the ingest function, before its
 * request loop, passing references to its own $completedNormally (starts
 * false), $rowsWritten and $errors variables. The calling function must set
 * $completedNormally = true immediately before its own normal-completion
 * ukgrid_log_ingest() call, or this logs the run a second time.
 */
function ukgrid_register_partial_progress_safety_net(string $source, string $context, bool &$completedNormally, int &$rowsWritten, array &$errors): void
{
    register_shutdown_function(function () use ($source, $context, &$completedNormally, &$rowsWritten, &$errors) {
        if ($completedNormally) {
            return; // normal completion already logged this run - nothing more to do
        }
        ukgrid_log_ingest(
            $source,
            $rowsWritten > 0 ? 'OK' : 'ERROR',
            $rowsWritten,
            "Run did not finish normally (likely hit a time or memory limit partway through {$context}) - " .
            $rowsWritten . ' row(s) written before it stopped.' . (empty($errors) ? '' : ' Errors so far: ' . implode('; ', $errors))
        );
    });
}

/**
 * Elexon Insights Solution - no API key required. FUELINST (generation by
 * fuel type incl. interconnectors), MID (price), ATL (demand).
 */
function ukgrid_ingest_elexon(PDO $pdo, array $config, int $timeoutSeconds = 20): array
{
    $base = rtrim($config['sources']['elexon_base'] ?? 'https://data.elexon.co.uk/bmrs/api/v1', '/');
    $to = gmdate('Y-m-d\TH:i\Z');
    $from = gmdate('Y-m-d\TH:i\Z', time() - 3 * 3600); // 3h overlap covers any missed runs

    $rowsWritten = 0;
    $errors = [];

    // ---------- generation by fuel type ----------
    $genData = ukgrid_http_get_json($base . '/datasets/FUELINST?from=' . urlencode($from) . '&to=' . urlencode($to), $timeoutSeconds);
    if ($genData === null) {
        $errors[] = 'FUELINST request failed';
    } else {
        $rows = $genData['data'] ?? $genData;
        if (!is_array($rows)) {
            $errors[] = 'FUELINST: unexpected response shape';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO readings_generation (ts, fuel_type, mw, source)
                 VALUES (:ts, :fuel_type, :mw, "ELEXON")
                 ON DUPLICATE KEY UPDATE mw = VALUES(mw), source = VALUES(source)'
            );
            foreach ($rows as $row) {
                $ts = ukgrid_iso_to_mysql($row['startTime'] ?? '');
                $fuel = $row['fuelType'] ?? null;
                $mw = $row['generation'] ?? null;
                if ($ts === null || $fuel === null || $mw === null) {
                    continue;
                }
                $stmt->execute(['ts' => $ts, 'fuel_type' => $fuel, 'mw' => $mw]);
                $rowsWritten++;
            }
        }
    }

    // ---------- price (MID, APXMIDP provider) ----------
    $priceData = ukgrid_http_get_json($base . '/balancing/pricing/market-index?from=' . urlencode($from) . '&to=' . urlencode($to), $timeoutSeconds);
    if ($priceData === null) {
        $errors[] = 'MID request failed';
    } else {
        $rows = $priceData['data'] ?? $priceData;
        if (!is_array($rows)) {
            $errors[] = 'MID: unexpected response shape';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO readings_price (ts, price, provider, volume)
                 VALUES (:ts, :price, :provider, :volume)
                 ON DUPLICATE KEY UPDATE price = VALUES(price), volume = VALUES(volume)'
            );
            foreach ($rows as $row) {
                if (($row['dataProvider'] ?? null) !== 'APXMIDP') {
                    continue;
                }
                $ts = ukgrid_iso_to_mysql($row['startTime'] ?? '');
                $price = $row['price'] ?? null;
                if ($ts === null || $price === null) {
                    continue;
                }
                $stmt->execute(['ts' => $ts, 'price' => $price, 'provider' => 'APXMIDP', 'volume' => $row['volume'] ?? null]);
                $rowsWritten++;
            }
        }
    }

    // ---------- demand (INDO/ITSDO) ----------
    // This endpoint takes settlementDateFrom/settlementDateTo (whole dates,
    // not the ISO datetimes the other two Elexon calls above use) and
    // returns both INDO (initialDemandOutturn) and ITSDO
    // (initialTransmissionSystemDemandOutturn) per settlement period. This
    // table is documented as Transmission System Demand, so ITSDO is what
    // gets stored. Confirmed against the live API while diagnosing why
    // demand_mw stayed null despite generation/price/emissions all working -
    // the original endpoint (/demand/actual/total, field "quantity") never
    // existed, so every call here silently failed and got masked because
    // this function's overall status is "OK" as long as any of its three
    // sub-fetches wrote rows.
    $demandDateFrom = gmdate('Y-m-d', time() - 3 * 3600);
    $demandDateTo = gmdate('Y-m-d');
    $demandData = ukgrid_http_get_json($base . '/demand/outturn?settlementDateFrom=' . urlencode($demandDateFrom) . '&settlementDateTo=' . urlencode($demandDateTo), $timeoutSeconds);
    if ($demandData === null) {
        $errors[] = 'demand/outturn request failed';
    } else {
        $rows = $demandData['data'] ?? $demandData;
        if (!is_array($rows)) {
            $errors[] = 'demand/outturn: unexpected response shape';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO readings_demand (ts, mw) VALUES (:ts, :mw) ON DUPLICATE KEY UPDATE mw = VALUES(mw)'
            );
            foreach ($rows as $row) {
                $ts = ukgrid_iso_to_mysql($row['startTime'] ?? '');
                $mw = $row['initialTransmissionSystemDemandOutturn'] ?? null;
                if ($ts === null || $mw === null) {
                    continue;
                }
                $stmt->execute(['ts' => $ts, 'mw' => $mw]);
                $rowsWritten++;
            }
        }
    }

    $status = $rowsWritten > 0 ? 'OK' : (empty($errors) ? 'OK' : 'ERROR');
    ukgrid_log_ingest('ELEXON', $status, $rowsWritten, implode('; ', $errors));
    return ['rows' => $rowsWritten, 'errors' => $errors];
}

/**
 * Carbon Intensity API - no API key required. /intensity (actual +
 * forecast gCO2/kWh) and /generation (% mix, used as a fallback/cross-check
 * for embedded solar & wind).
 */
function ukgrid_ingest_carbon_intensity(PDO $pdo, array $config, int $timeoutSeconds = 15): array
{
    $base = rtrim($config['sources']['carbon_intensity_base'] ?? 'https://api.carbonintensity.org.uk', '/');
    $errors = [];
    $rowsWritten = 0;

    $intensity = ukgrid_http_get_json($base . '/intensity', $timeoutSeconds);
    if ($intensity === null) {
        $errors[] = 'intensity request failed';
    } else {
        $entries = $intensity['data'] ?? [];
        if (!is_array($entries)) {
            $errors[] = 'intensity: unexpected response shape';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO readings_emissions (ts, actual_gco2, forecast_gco2, intensity_index)
                 VALUES (:ts, :actual, :forecast, :idx)
                 ON DUPLICATE KEY UPDATE actual_gco2 = VALUES(actual_gco2), forecast_gco2 = VALUES(forecast_gco2), intensity_index = VALUES(intensity_index)'
            );
            foreach ($entries as $entry) {
                $ts = ukgrid_iso_to_mysql($entry['from'] ?? '');
                $block = $entry['intensity'] ?? [];
                if ($ts === null) {
                    continue;
                }
                $stmt->execute([
                    'ts' => $ts,
                    'actual' => $block['actual'] ?? null,
                    'forecast' => $block['forecast'] ?? null,
                    'idx' => $block['index'] ?? null,
                ]);
                $rowsWritten++;
            }
        }
    }

    $generation = ukgrid_http_get_json($base . '/generation', $timeoutSeconds);
    if ($generation === null) {
        $errors[] = 'generation request failed';
    } else {
        $block = $generation['data'] ?? null;
        $from = $block['from'] ?? null;
        $mix = $block['generationmix'] ?? null;
        if ($from === null || !is_array($mix)) {
            $errors[] = 'generation: unexpected response shape';
        } else {
            $ts = ukgrid_iso_to_mysql($from);
            if ($ts !== null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO readings_mix_pct (ts, fuel, pct) VALUES (:ts, :fuel, :pct)
                     ON DUPLICATE KEY UPDATE pct = VALUES(pct)'
                );
                foreach ($mix as $entry) {
                    $fuel = $entry['fuel'] ?? null;
                    $pct = $entry['perc'] ?? null;
                    if ($fuel === null || $pct === null) {
                        continue;
                    }
                    $stmt->execute(['ts' => $ts, 'fuel' => $fuel, 'pct' => $pct]);
                    $rowsWritten++;
                }
            }
        }
    }

    $status = $rowsWritten > 0 ? 'OK' : 'ERROR';
    ukgrid_log_ingest('CARBON_INTENSITY', $status, $rowsWritten, implode('; ', $errors));
    return ['rows' => $rowsWritten, 'errors' => $errors];
}

function ukgrid_find_field(array $fields, array $patterns): ?string
{
    foreach ($fields as $field) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $field)) {
                return $field;
            }
        }
    }
    return null;
}

/**
 * Open-Meteo forecast API - no API key required, no signup. Hourly wind
 * speed + cloud cover for ONE representative GB point (default: roughly
 * central England - see weather_lat/weather_lon in
 * includes/config.php.example), used only as context on the History
 * page's charts, never presented as a national average or as live-second
 * data the way Elexon's readings are - see pages/history.html's caption
 * on the Weather card for the exact honesty framing.
 *
 * Called from both cron/fetch_weather.php and includes/refresh.php's
 * on-demand path (it's in refresh.intervals_minutes in
 * includes/config.php.example) - works with zero cron access, same as
 * every other source in this file except NESO's embedded-generation
 * package.
 *
 * Uses past_days=2 on every run (rather than requesting only the latest
 * hour) so a single call backfills anything a gap in cron coverage
 * missed, the same reasoning as ukgrid_ingest_carbon_intensity() pulling
 * a small window rather than one instant.
 *
 * Field names are resolved via ukgrid_find_field() rather than a hard
 * ['hourly']['wind_speed_10m'] lookup, purely as a defensive layer if
 * Open-Meteo ever renames these (they're currently documented as
 * "wind_speed_10m" and "cloud_cover" - see https://open-meteo.com/en/docs)
 * - only those two names are requested, since asking for unrecognised
 * variable names risks the whole request being rejected rather than the
 * unknown one being silently ignored.
 */
function ukgrid_ingest_weather(PDO $pdo, array $config, int $timeoutSeconds = 15): array
{
    $base = rtrim($config['sources']['open_meteo_base'] ?? 'https://api.open-meteo.com/v1', '/');
    $lat = $config['sources']['weather_lat'] ?? 52.95;
    $lon = $config['sources']['weather_lon'] ?? -1.15;
    $errors = [];
    $rowsWritten = 0;

    $url = $base . '/forecast?latitude=' . urlencode((string) $lat) . '&longitude=' . urlencode((string) $lon)
        . '&hourly=wind_speed_10m,cloud_cover&past_days=2&forecast_days=1&timezone=UTC';
    $data = ukgrid_http_get_json($url, $timeoutSeconds);
    if ($data === null) {
        $msg = 'forecast request failed';
        ukgrid_log_ingest('WEATHER', 'ERROR', 0, $msg);
        return ['rows' => 0, 'errors' => [$msg]];
    }

    $hourly = $data['hourly'] ?? null;
    if (!is_array($hourly) || empty($hourly['time'])) {
        $msg = 'forecast: unexpected response shape';
        ukgrid_log_ingest('WEATHER', 'ERROR', 0, $msg);
        return ['rows' => 0, 'errors' => [$msg]];
    }

    $fields = array_keys($hourly);
    $windField = ukgrid_find_field($fields, ['/^wind_?speed_10m$/i']);
    $cloudField = ukgrid_find_field($fields, ['/^cloud_?cover$/i']);
    if ($windField === null && $cloudField === null) {
        $msg = 'forecast: neither wind speed nor cloud cover field found in response';
        ukgrid_log_ingest('WEATHER', 'ERROR', 0, $msg);
        return ['rows' => 0, 'errors' => [$msg]];
    }

    $times = $hourly['time'];
    $winds = $windField !== null ? $hourly[$windField] : [];
    $clouds = $cloudField !== null ? $hourly[$cloudField] : [];

    $stmt = $pdo->prepare(
        'INSERT INTO readings_weather (ts, wind_speed_ms, cloud_cover_pct, lat, lon)
         VALUES (:ts, :wind, :cloud, :lat, :lon)
         ON DUPLICATE KEY UPDATE wind_speed_ms = VALUES(wind_speed_ms), cloud_cover_pct = VALUES(cloud_cover_pct)'
    );
    foreach ($times as $i => $isoTime) {
        // Open-Meteo returns "hourly" times without a timezone suffix when
        // timezone=UTC is requested (e.g. "2026-08-16T14:00" not
        // "...14:00Z") - append Z explicitly so strtotime() (inside
        // ukgrid_iso_to_mysql()) doesn't fall back to interpreting it as
        // the server's local time.
        $ts = ukgrid_iso_to_mysql(rtrim($isoTime, 'Z') . 'Z');
        if ($ts === null) {
            continue;
        }
        $wind = isset($winds[$i]) ? (float) $winds[$i] : null;
        $cloud = isset($clouds[$i]) ? (float) $clouds[$i] : null;
        if ($wind === null && $cloud === null) {
            continue;
        }
        $stmt->execute(['ts' => $ts, 'wind' => $wind, 'cloud' => $cloud, 'lat' => $lat, 'lon' => $lon]);
        $rowsWritten++;
    }

    $status = $rowsWritten > 0 ? 'OK' : 'ERROR';
    ukgrid_log_ingest('WEATHER', $status, $rowsWritten, implode('; ', $errors));
    return ['rows' => $rowsWritten, 'errors' => $errors];
}

/**
 * Recomputes the "notable moments" this GB install has itself recorded -
 * the highest/lowest value ever seen in this site's own stored history for
 * a handful of metrics - and caches the result in the notable_moments
 * table (see sql/schema.sql for the important caveat: this is NOT the same
 * thing as the hand-typed all-time "Records" panel).
 *
 * Pure re-aggregation over data already in this database - no external API
 * call, so there's nothing to time out on and no $timeoutSeconds use here
 * beyond keeping the same function signature as every other
 * ukgrid_ingest_*()/ukgrid_compute_*() function this file exports, for
 * consistency with how includes/refresh.php calls all of them.
 *
 * Deliberately re-runs a full MAX()/MIN() scan each time rather than
 * incrementally tracking a running best - simpler, and cheap enough at
 * this site's scale (a handful of indexed-by-ts tables) to run once a day
 * via the on-demand refresh dispatch without needing its own cron job.
 */
function ukgrid_compute_notable_moments(PDO $pdo, array $config, int $timeoutSeconds = 15): array
{
    $moments = [];

    $add = function (string $key, ?array $row, string $label, string $unit, string $direction, string $valueCol = 'v') use (&$moments) {
        if ($row === false || $row === null || $row[$valueCol] === null) {
            return;
        }
        $moments[$key] = [
            'label' => $label,
            'value' => (float) $row[$valueCol],
            'unit' => $unit,
            'ts' => $row['ts'],
            'direction' => $direction,
        ];
    };

    // Day-ahead price (Elexon MID) - highest and lowest recorded. Negative
    // prices are a real, interesting grid event (surplus low-carbon
    // generation paying to keep running) rather than a data error, so the
    // lowest figure is shown as-is, sign included.
    $add('price_highest', $pdo->query("SELECT ts, price AS v FROM readings_price ORDER BY price DESC LIMIT 1")->fetch(),
        'Highest day-ahead price recorded', 'GBP/MWh', 'highest');
    $add('price_lowest', $pdo->query("SELECT ts, price AS v FROM readings_price ORDER BY price ASC LIMIT 1")->fetch(),
        'Lowest day-ahead price recorded', 'GBP/MWh', 'lowest');

    // Carbon intensity (Carbon Intensity API).
    $add('emissions_lowest', $pdo->query("SELECT ts, actual_gco2 AS v FROM readings_emissions WHERE actual_gco2 IS NOT NULL ORDER BY actual_gco2 ASC LIMIT 1")->fetch(),
        'Lowest carbon intensity recorded', 'gCO2/kWh', 'lowest');
    $add('emissions_highest', $pdo->query("SELECT ts, actual_gco2 AS v FROM readings_emissions WHERE actual_gco2 IS NOT NULL ORDER BY actual_gco2 DESC LIMIT 1")->fetch(),
        'Highest carbon intensity recorded', 'gCO2/kWh', 'highest');

    // Transmission System Demand Outturn (Elexon ATL).
    $add('demand_highest', $pdo->query("SELECT ts, mw AS v FROM readings_demand ORDER BY mw DESC LIMIT 1")->fetch(),
        'Highest demand recorded', 'MW', 'highest');
    $add('demand_lowest', $pdo->query("SELECT ts, mw AS v FROM readings_demand ORDER BY mw ASC LIMIT 1")->fetch(),
        'Lowest demand recorded (usually an overnight trough)', 'MW', 'lowest');

    // Wind (transmission-connected WIND plus embedded WIND_EMBEDDED, summed
    // per settlement period) - same fuel_type set index.html's own wind
    // stat card uses.
    $add('wind_highest', $pdo->query(
        "SELECT ts, SUM(mw) AS v FROM readings_generation WHERE fuel_type IN ('WIND','WIND_EMBEDDED') GROUP BY ts ORDER BY v DESC LIMIT 1"
    )->fetch(), 'Highest wind generation recorded', 'MW', 'highest');

    // Solar is entirely embedded (distribution-connected, not directly
    // metered by Elexon) - single fuel_type, no SUM needed.
    $add('solar_highest', $pdo->query(
        "SELECT ts, mw AS v FROM readings_generation WHERE fuel_type = 'SOLAR_EMBEDDED' ORDER BY mw DESC LIMIT 1"
    )->fetch(), 'Highest solar generation recorded', 'MW', 'highest');

    $stmt = $pdo->prepare(
        'INSERT INTO notable_moments (metric_key, label, value, unit, ts, direction)
         VALUES (:key, :label, :value, :unit, :ts, :direction)
         ON DUPLICATE KEY UPDATE label = VALUES(label), value = VALUES(value), unit = VALUES(unit), ts = VALUES(ts), direction = VALUES(direction)'
    );
    $rowsWritten = 0;
    foreach ($moments as $key => $m) {
        $stmt->execute([
            'key' => $key,
            'label' => $m['label'],
            'value' => $m['value'],
            'unit' => $m['unit'],
            'ts' => $m['ts'],
            'direction' => $m['direction'],
        ]);
        $rowsWritten++;
    }

    $status = $rowsWritten > 0 ? 'OK' : 'ERROR';
    $errors = $rowsWritten > 0 ? [] : ['no source tables have any data yet'];
    ukgrid_log_ingest('NOTABLE_MOMENTS', $status, $rowsWritten, implode('; ', $errors));
    return ['rows' => $rowsWritten, 'errors' => $errors];
}

/**
 * NESO Data Portal - no API key required (CKAN). Embedded solar/wind
 * generation estimates. Written defensively: resolves the current
 * resource and its column names at request time rather than assuming a
 * fixed resource_id/schema. See the long comment in cron/fetch_neso.php
 * for the full reasoning; this is the same logic, just callable from both
 * cron and the on-demand refresh path.
 */
function ukgrid_ingest_neso(PDO $pdo, array $config, int $timeoutSeconds = 15): array
{
    $apiBase = rtrim($config['sources']['neso_api_base'] ?? 'https://api.neso.energy/api/3/action', '/');
    $packageId = $config['sources']['neso_embedded_package'] ?? 'embedded-wind-and-solar-forecasts';

    $pkg = ukgrid_http_get_json($apiBase . '/package_show?id=' . urlencode($packageId), $timeoutSeconds);
    if ($pkg === null || empty($pkg['success']) || empty($pkg['result']['resources'])) {
        $msg = 'package_show failed or returned no resources for package "' . $packageId . '".';
        ukgrid_log_ingest('NESO', 'ERROR', 0, $msg);
        return ['rows' => 0, 'errors' => [$msg]];
    }

    $resources = $pkg['result']['resources'];
    usort($resources, function ($a, $b) {
        return strtotime($b['last_modified'] ?? $b['created'] ?? '1970-01-01')
             <=> strtotime($a['last_modified'] ?? $a['created'] ?? '1970-01-01');
    });
    $resource = null;
    foreach ($resources as $candidate) {
        if (!empty($candidate['datastore_active'])) {
            $resource = $candidate;
            break;
        }
    }
    if ($resource === null) {
        $msg = 'No datastore-backed resource found. Resources seen: ' . implode(', ', array_column($resources, 'name'));
        ukgrid_log_ingest('NESO', 'ERROR', 0, $msg);
        return ['rows' => 0, 'errors' => [$msg]];
    }
    $resourceId = $resource['id'];

    $probe = ukgrid_http_get_json($apiBase . '/datastore_search?resource_id=' . urlencode($resourceId) . '&limit=1', $timeoutSeconds);
    if ($probe === null || empty($probe['success'])) {
        $msg = "datastore_search probe failed for resource {$resourceId} (\"{$resource['name']}\").";
        ukgrid_log_ingest('NESO', 'ERROR', 0, $msg);
        return ['rows' => 0, 'errors' => [$msg]];
    }
    $fields = array_map(function ($f) { return $f['id']; }, $probe['result']['fields'] ?? []);

    $dateField = ukgrid_find_field($fields, ['/^date/i', '/settlement.*date/i', '/^datetime/i']);
    $periodField = ukgrid_find_field($fields, ['/settlement.*period/i', '/^period$/i', '/^sp$/i']);
    $solarField = ukgrid_find_field($fields, ['/solar/i']);
    $windField = ukgrid_find_field($fields, ['/embedded.*wind/i', '/wind.*embedded/i', '/^wind/i']);

    if ($dateField === null || $solarField === null || $windField === null) {
        $msg = "Resource {$resourceId} (\"{$resource['name']}\") didn't match expected column patterns. Columns seen: " . implode(', ', $fields);
        ukgrid_log_ingest('NESO', 'ERROR', 0, $msg);
        return ['rows' => 0, 'errors' => [$msg]];
    }

    // LIMIT is deliberately generous (not just "however many recent points we
    // need"). This package is named "...-forecasts" for a reason - GB doesn't
    // meter embedded solar/wind directly, so NESO publishes a rolling
    // forward-looking series rather than a purely historical one. Confirmed
    // live (via tools/full-refresh.php) that fetching only the newest 200 rows
    // (DESC) can land entirely within that future forecast horizon - every
    // single one gets skipped below by the "don't write future-dated points"
    // check, silently producing 0 rows written with no per-row error. A wide
    // limit guarantees this reaches back past "now" into the most recent
    // already-elapsed period(s) regardless of how far ahead the horizon
    // extends; the existing per-row filtering below still only ever writes
    // points at or before "now".
    $sql = 'SELECT * FROM "' . $resourceId . '" ORDER BY "' . $dateField . '" DESC LIMIT 5000';
    $result = ukgrid_http_get_json($apiBase . '/datastore_search_sql?sql=' . urlencode($sql), $timeoutSeconds);
    if ($result === null || empty($result['success'])) {
        $msg = "datastore_search_sql failed for resource {$resourceId}.";
        ukgrid_log_ingest('NESO', 'ERROR', 0, $msg);
        return ['rows' => 0, 'errors' => [$msg]];
    }
    $records = $result['result']['records'] ?? [];

    $stmt = $pdo->prepare(
        'INSERT INTO readings_generation (ts, fuel_type, mw, source)
         VALUES (:ts, :fuel_type, :mw, "NESO")
         ON DUPLICATE KEY UPDATE mw = VALUES(mw), source = VALUES(source)'
    );

    $rowsWritten = 0;
    $now = time();
    foreach ($records as $row) {
        $rawDate = $row[$dateField] ?? null;
        if ($rawDate === null) {
            continue;
        }
        $ts = strtotime($rawDate);
        if ($ts === false || $ts > $now) {
            continue;
        }
        if ($periodField !== null && isset($row[$periodField]) && is_numeric($row[$periodField])) {
            $period = (int) $row[$periodField];
            $dayStart = strtotime(gmdate('Y-m-d', $ts) . ' 00:00:00 UTC');
            $ts = $dayStart + ($period - 1) * 1800;
            if ($ts > $now) {
                continue;
            }
        }
        $tsSql = gmdate('Y-m-d H:i:s', $ts);

        if (isset($row[$solarField]) && is_numeric($row[$solarField])) {
            $stmt->execute(['ts' => $tsSql, 'fuel_type' => 'SOLAR_EMBEDDED', 'mw' => $row[$solarField]]);
            $rowsWritten++;
        }
        if (isset($row[$windField]) && is_numeric($row[$windField])) {
            $stmt->execute(['ts' => $tsSql, 'fuel_type' => 'WIND_EMBEDDED', 'mw' => $row[$windField]]);
            $rowsWritten++;
        }
    }

    $status = $rowsWritten > 0 ? 'OK' : 'ERROR';
    $message = "resource={$resourceId} date={$dateField} solar={$solarField} wind={$windField}";
    ukgrid_log_ingest('NESO', $status, $rowsWritten, $message);
    return ['rows' => $rowsWritten, 'errors' => []];
}

/**
 * NESO Data Portal (same keyless CKAN as ukgrid_ingest_neso() above) -
 * "Constraint Breakdown Costs and Volume" dataset. Resolves the current
 * resource the same way ukgrid_ingest_neso() does (this dataset gets a
 * brand new resource each GB fiscal year - a fresh CSV/resource_id every
 * April - so a hardcoded resource_id would silently go stale every
 * spring).
 *
 * IMPORTANT, confirmed directly against this dataset's own published field
 * descriptions (neso.energy/data-portal/constraint-breakdown/...): the
 * columns are "Date", "Reducing largest loss cost/volume", "Increasing
 * system inertia cost/volume", "Voltage constraints cost/volume", and
 * "Thermal constraints cost/volume" - broken down by WHY an action was
 * taken (thermal network limits / voltage / system inertia), not by which
 * generation technology was affected. There is no "wind" column. Field
 * names are still resolved via ukgrid_find_field() rather than hardcoded
 * exactly, purely as a defensive layer (NESO could reformat a title
 * slightly, e.g. "Thermal constraints cost" vs "Thermal Constraint Cost");
 * the substance of what's stored - four constraint types, cost + volume
 * each, no technology breakdown - is confirmed, not guessed.
 *
 * Called from both cron/fetch_constraints.php and includes/refresh.php's
 * on-demand path (it's in refresh.intervals_minutes in
 * includes/config.php.example, at a 1440-minute/daily interval since NESO
 * itself only republishes this weekly) - works with zero cron access.
 */
function ukgrid_ingest_constraints(PDO $pdo, array $config, int $timeoutSeconds = 15): array
{
    $apiBase = rtrim($config['sources']['neso_api_base'] ?? 'https://api.neso.energy/api/3/action', '/');
    $packageId = $config['sources']['neso_constraint_package'] ?? 'constraint-breakdown';

    $pkg = ukgrid_http_get_json($apiBase . '/package_show?id=' . urlencode($packageId), $timeoutSeconds);
    if ($pkg === null || empty($pkg['success']) || empty($pkg['result']['resources'])) {
        $msg = 'package_show failed or returned no resources for package "' . $packageId . '".';
        ukgrid_log_ingest('CONSTRAINTS', 'ERROR', 0, $msg);
        return ['rows' => 0, 'errors' => [$msg]];
    }

    // Same "newest datastore-backed resource wins" logic as
    // ukgrid_ingest_neso() - this dataset publishes one resource per GB
    // fiscal year (e.g. "Constraint Breakdown 2026-2027"), appended to
    // weekly, so the most recently modified resource is always the current
    // year's, with no need to construct/guess that year's label.
    $resources = $pkg['result']['resources'];
    usort($resources, function ($a, $b) {
        return strtotime($b['last_modified'] ?? $b['created'] ?? '1970-01-01')
             <=> strtotime($a['last_modified'] ?? $a['created'] ?? '1970-01-01');
    });
    $resource = null;
    foreach ($resources as $candidate) {
        if (!empty($candidate['datastore_active'])) {
            $resource = $candidate;
            break;
        }
    }
    if ($resource === null) {
        $msg = 'No datastore-backed resource found. Resources seen: ' . implode(', ', array_column($resources, 'name'));
        ukgrid_log_ingest('CONSTRAINTS', 'ERROR', 0, $msg);
        return ['rows' => 0, 'errors' => [$msg]];
    }
    $resourceId = $resource['id'];

    $probe = ukgrid_http_get_json($apiBase . '/datastore_search?resource_id=' . urlencode($resourceId) . '&limit=1', $timeoutSeconds);
    if ($probe === null || empty($probe['success'])) {
        $msg = "datastore_search probe failed for resource {$resourceId} (\"{$resource['name']}\").";
        ukgrid_log_ingest('CONSTRAINTS', 'ERROR', 0, $msg);
        return ['rows' => 0, 'errors' => [$msg]];
    }
    $fields = array_map(function ($f) { return $f['id']; }, $probe['result']['fields'] ?? []);

    $dateField = ukgrid_find_field($fields, ['/^date$/i', '/^date\b/i']);
    $thermalCostField = ukgrid_find_field($fields, ['/thermal.*cost/i']);
    $thermalVolField = ukgrid_find_field($fields, ['/thermal.*volume/i']);
    $voltageCostField = ukgrid_find_field($fields, ['/voltage.*cost/i']);
    $voltageVolField = ukgrid_find_field($fields, ['/voltage.*volume/i']);
    $inertiaCostField = ukgrid_find_field($fields, ['/inertia.*cost/i']);
    $inertiaVolField = ukgrid_find_field($fields, ['/inertia.*volume/i']);
    $lossCostField = ukgrid_find_field($fields, ['/largest.*loss.*cost/i']);
    $lossVolField = ukgrid_find_field($fields, ['/largest.*loss.*volume/i']);

    if ($dateField === null || $thermalCostField === null) {
        $msg = "Resource {$resourceId} (\"{$resource['name']}\") didn't match expected column patterns. Columns seen: " . implode(', ', $fields);
        ukgrid_log_ingest('CONSTRAINTS', 'ERROR', 0, $msg);
        return ['rows' => 0, 'errors' => [$msg]];
    }

    // Plenty of headroom for a full fiscal year (max ~366 daily rows) in
    // one request - this dataset is small compared to the half-hourly ones
    // elsewhere in this file, so there's no need for datastore_search_sql's
    // ORDER BY/LIMIT trick the way ukgrid_ingest_neso() needs it.
    $result = ukgrid_http_get_json($apiBase . '/datastore_search?resource_id=' . urlencode($resourceId) . '&limit=400', $timeoutSeconds);
    if ($result === null || empty($result['success'])) {
        $msg = "datastore_search failed for resource {$resourceId}.";
        ukgrid_log_ingest('CONSTRAINTS', 'ERROR', 0, $msg);
        return ['rows' => 0, 'errors' => [$msg]];
    }
    $records = $result['result']['records'] ?? [];

    $stmt = $pdo->prepare(
        'INSERT INTO readings_constraints
           (dt, thermal_cost, thermal_volume_mwh, voltage_cost, voltage_volume_mwh,
            inertia_cost, inertia_volume_mwh, largest_loss_cost, largest_loss_volume_mwh)
         VALUES (:dt, :thermal_cost, :thermal_vol, :voltage_cost, :voltage_vol,
                 :inertia_cost, :inertia_vol, :loss_cost, :loss_vol)
         ON DUPLICATE KEY UPDATE
           thermal_cost = VALUES(thermal_cost), thermal_volume_mwh = VALUES(thermal_volume_mwh),
           voltage_cost = VALUES(voltage_cost), voltage_volume_mwh = VALUES(voltage_volume_mwh),
           inertia_cost = VALUES(inertia_cost), inertia_volume_mwh = VALUES(inertia_volume_mwh),
           largest_loss_cost = VALUES(largest_loss_cost), largest_loss_volume_mwh = VALUES(largest_loss_volume_mwh)'
    );

    $rowsWritten = 0;
    $today = gmdate('Y-m-d');
    $numOrNull = function ($v) {
        return ($v !== null && $v !== '' && is_numeric($v)) ? (float) $v : null;
    };
    foreach ($records as $row) {
        $rawDate = $row[$dateField] ?? null;
        if ($rawDate === null) {
            continue;
        }
        $ts = strtotime($rawDate);
        if ($ts === false) {
            continue;
        }
        $dt = gmdate('Y-m-d', $ts);
        if ($dt > $today) {
            continue; // this dataset is actuals-only, but skip defensively anyway
        }
        $stmt->execute([
            'dt' => $dt,
            'thermal_cost' => $numOrNull($row[$thermalCostField] ?? null),
            'thermal_vol' => $thermalVolField !== null ? $numOrNull($row[$thermalVolField] ?? null) : null,
            'voltage_cost' => $voltageCostField !== null ? $numOrNull($row[$voltageCostField] ?? null) : null,
            'voltage_vol' => $voltageVolField !== null ? $numOrNull($row[$voltageVolField] ?? null) : null,
            'inertia_cost' => $inertiaCostField !== null ? $numOrNull($row[$inertiaCostField] ?? null) : null,
            'inertia_vol' => $inertiaVolField !== null ? $numOrNull($row[$inertiaVolField] ?? null) : null,
            'loss_cost' => $lossCostField !== null ? $numOrNull($row[$lossCostField] ?? null) : null,
            'loss_vol' => $lossVolField !== null ? $numOrNull($row[$lossVolField] ?? null) : null,
        ]);
        $rowsWritten++;
    }

    $status = $rowsWritten > 0 ? 'OK' : 'ERROR';
    $message = "resource={$resourceId} date={$dateField} thermal_cost={$thermalCostField}";
    ukgrid_log_ingest('CONSTRAINTS', $status, $rowsWritten, $message);
    return ['rows' => $rowsWritten, 'errors' => []];
}

/**
 * Case-insensitive "does this key look like one of these substrings" lookup
 * across an associative array's keys - used to read EirGrid's response
 * fields defensively (field names are well-documented by community tooling
 * as EffectiveTime/FieldName/Region/Value, but this project can't fetch a
 * live response to confirm the exact casing/wrapper from this environment,
 * the same situation as NESO above - see cron/fetch_eirgrid.php).
 */
function ukgrid_find_assoc_field(array $row, array $substrings)
{
    foreach ($row as $key => $value) {
        $lower = strtolower((string) $key);
        foreach ($substrings as $s) {
            if (strpos($lower, $s) !== false) {
                return $value;
            }
        }
    }
    return null;
}

/** Pulls the array of data points out of an EirGrid API response, wherever it's nested. */
function ukgrid_eirgrid_extract_series($data): ?array
{
    if (is_array($data) && isset($data[0]) && is_array($data[0])) {
        return $data;
    }
    foreach (['Series', 'Rows', 'Data', 'rows', 'data', 'series', 'Results', 'results'] as $key) {
        if (isset($data[$key]) && is_array($data[$key]) && isset($data[$key][0])) {
            return $data[$key];
        }
    }
    return null;
}

/** EirGrid timestamps are Ireland local time (Europe/Dublin - same UTC offset/DST as GB). */
function ukgrid_eirgrid_time_to_mysql(string $t): ?string
{
    $dt = DateTime::createFromFormat('d-M-Y H:i:s', $t, new DateTimeZone('Europe/Dublin'));
    if (!$dt) {
        $dt = DateTime::createFromFormat('d-M-Y H:i', $t, new DateTimeZone('Europe/Dublin'));
    }
    if (!$dt) {
        return null;
    }
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

/**
 * EirGrid Smart Grid Dashboard - no API key required. Covers the all-island
 * (Republic of Ireland + Northern Ireland) system: demand, total generation,
 * wind generation, and net interconnection with GB.
 *
 * Written defensively, like ukgrid_ingest_neso() above: multiple independent
 * community tools (and EirGrid's own dashboard) confirm this endpoint and
 * its query parameters are real, keyless and publicly documented under
 * EirGrid's Open Data Licence, but this project's build environment
 * couldn't get a live response from smartgriddashboard.com to verify the
 * exact JSON field names/wrapper - only that requests.get()-style plain
 * HTTP calls work for other developers. If ingestion logs an ERROR here,
 * check the message for which "area" failed and what the raw response
 * looked like, and adjust ukgrid_find_assoc_field()'s patterns above if
 * EirGrid's field names differ from what's assumed.
 *
 * Data licence: EirGrid Group's Open Data Licence requires the attribution
 * "Supported by EirGrid Group Data" wherever this data is shown (already
 * included in every page's footer and pages/data-sources.html).
 */
function ukgrid_ingest_eirgrid(PDO $pdo, array $config, int $timeoutSeconds = 20): array
{
    $base = rtrim($config['sources']['eirgrid_base'] ?? 'https://www.smartgriddashboard.com/DashboardService.svc', '/');

    $now = new DateTime('now', new DateTimeZone('Europe/Dublin'));
    // Round down to the last 15-minute settlement-period boundary. Confirmed
    // live (via tools/full-refresh.php) that this API is picky about this:
    // an off-grid datefrom/dateto like "19:12" doesn't get snapped to the
    // nearest real period - it comes back with EffectiveTime stamps of
    // 15:12, 15:27, 15:42, 15:57... (i.e. every 15 minutes from the exact
    // off-grid minute given) and EVERY one of those has Value: null, since
    // none of them are real settlement periods. Every one of the 5 areas
    // below failed with "no usable data points" for exactly this reason
    // until this rounding was added - a plain new DateTime('now') only
    // lands on :00/:15/:30/:45 by chance.
    $minute = (int) $now->format('i');
    $now->setTime((int) $now->format('H'), $minute - ($minute % 15), 0);
    $from = (clone $now)->modify('-4 hours'); // overlap covers any missed runs
    $dateFrom = $from->format('d-M-Y H:i');
    $dateTo = $now->format('d-M-Y H:i');

    // area => [table, category-or-null (null means the readings_ie_demand table)]
    $areas = [
        'demandactual' => ['readings_ie_demand', null],
        'generationactual' => ['readings_ie_generation', 'TOTAL'],
        'windactual' => ['readings_ie_generation', 'WIND'],
        'interconnection' => ['readings_ie_generation', 'INTERCONNECTION'],
        'co2intensity' => ['readings_ie_co2', null],
    ];

    $demandStmt = $pdo->prepare('INSERT INTO readings_ie_demand (ts, mw) VALUES (:ts, :mw) ON DUPLICATE KEY UPDATE mw = VALUES(mw)');
    $genStmt = $pdo->prepare('INSERT INTO readings_ie_generation (ts, category, mw) VALUES (:ts, :category, :mw) ON DUPLICATE KEY UPDATE mw = VALUES(mw)');
    $co2Stmt = $pdo->prepare('INSERT INTO readings_ie_co2 (ts, gco2_per_kwh) VALUES (:ts, :v) ON DUPLICATE KEY UPDATE gco2_per_kwh = VALUES(gco2_per_kwh)');

    $rowsWritten = 0;
    $errors = [];
    $isFirstArea = true;
    $completedNormally = false;
    ukgrid_register_partial_progress_safety_net('EIRGRID', 'the 5-area loop (demand/generation/wind/interconnection/co2)', $completedNormally, $rowsWritten, $errors);

    foreach ($areas as $area => [$table, $category]) {
        // A gap between each of the 5 sequential requests, on the theory
        // this was server-side rate limiting - bumped from an original
        // 300ms to 600ms, but a live run via tools/full-refresh.php (see
        // includes/http.php's ukgrid_http_get_json() failure messages,
        // surfaced there even in log-capture mode - ukgrid_file_log() in
        // includes/db.php) showed 600ms still wasn't enough, AND that which
        // area(s) fail varies between runs rather than always being the
        // same ones - that's a pattern of genuine intermittent flakiness on
        // EirGrid's own dashboard backend (flat HTTP 503 "Service
        // Unavailable" pages, not a JSON error EirGrid itself generated),
        // not something spacing requests further apart alone reliably
        // fixes. Kept the 600ms gap AND added one short-backoff retry per
        // area below, since a 503 here comes back almost instantly (not a
        // real timeout) and a second attempt a moment later clears most of
        // them in practice.
        if (!$isFirstArea) {
            usleep(600000);
        }
        $isFirstArea = false;

        $url = $base . '/data?area=' . urlencode($area) . '&region=ALL&datefrom=' . urlencode($dateFrom) . '&dateto=' . urlencode($dateTo);
        $data = ukgrid_http_get_json($url, $timeoutSeconds);
        if ($data === null) {
            usleep(900000); // one retry after a short pause - see the comment above
            $data = ukgrid_http_get_json($url, $timeoutSeconds);
        }
        if ($data === null) {
            $errors[] = "{$area}: request failed or returned invalid JSON, even after one retry.";
            continue;
        }
        $series = ukgrid_eirgrid_extract_series($data);
        if ($series === null) {
            $errors[] = "{$area}: couldn't find a data series in the response. Top-level keys seen: " . implode(', ', array_keys((array) $data));
            continue;
        }

        $areaRows = 0;
        $sampleItem = null; // captured for diagnostics if nothing usable is found below
        $nullValueCount = 0; // items where every other field looked right but Value itself was null/blank
        $fieldNamesSeen = []; // distinct FieldName values seen, e.g. "SYSTEM_DEMAND" - see comment below
        foreach ($series as $point) {
            if (!is_array($point)) {
                continue;
            }
            if ($sampleItem === null) {
                $sampleItem = $point;
            }
            $fieldName = ukgrid_find_assoc_field($point, ['fieldname']);
            if ($fieldName !== null && !in_array((string) $fieldName, $fieldNamesSeen, true)) {
                $fieldNamesSeen[] = (string) $fieldName;
            }
            $rawTime = ukgrid_find_assoc_field($point, ['effectivetime', 'time']);
            $rawValue = ukgrid_find_assoc_field($point, ['value']);
            if ($rawTime !== null && ($rawValue === null || $rawValue === '' || !is_numeric($rawValue))) {
                $nullValueCount++;
            }
            if ($rawTime === null || $rawValue === null || $rawValue === '' || !is_numeric($rawValue)) {
                continue;
            }
            $tsSql = ukgrid_eirgrid_time_to_mysql((string) $rawTime);
            if ($tsSql === null) {
                continue;
            }

            if ($table === 'readings_ie_demand') {
                $demandStmt->execute(['ts' => $tsSql, 'mw' => (float) $rawValue]);
            } elseif ($table === 'readings_ie_generation') {
                $genStmt->execute(['ts' => $tsSql, 'category' => $category, 'mw' => (float) $rawValue]);
            } else {
                $co2Stmt->execute(['ts' => $tsSql, 'v' => (float) $rawValue]);
            }
            $areaRows++;
        }

        if ($areaRows === 0) {
            // The actual keys/values of a real item, not just a count - this
            // is what tells us EirGrid's real field names next time this
            // logs, instead of having to guess again. See
            // ukgrid_find_assoc_field()'s doc comment above. Also reports
            // how many items had a real timestamp but a null/blank Value
            // (EirGrid genuinely not having published a figure yet for that
            // period, vs. FieldName(s) actually present - if there's more
            // than one distinct FieldName mixed into one area's response,
            // that's a sign this needs to filter by FieldName rather than
            // reading every item's Value regardless of which field it is).
            $sample = $sampleItem !== null
                ? 'sample item: ' . mb_substr((string) json_encode($sampleItem), 0, 500)
                : 'series was empty';
            $errors[] = "{$area}: response parsed but no usable data points were found (checked " . count($series) . " item(s), {$nullValueCount} had a null/blank value). FieldName(s) seen: " . (empty($fieldNamesSeen) ? 'none' : implode(', ', $fieldNamesSeen)) . ". {$sample}";
        }
        $rowsWritten += $areaRows;
    }

    $status = $rowsWritten > 0 ? 'OK' : 'ERROR';
    $completedNormally = true; // tells the shutdown safety net above this run's already been logged - don't log it twice
    ukgrid_log_ingest('EIRGRID', $status, $rowsWritten, implode('; ', $errors));
    return ['rows' => $rowsWritten, 'errors' => $errors];
}

/**
 * EIA API v2 (api.eia.gov) - the only source in this file that isn't
 * keyless. Covers the lower-48 states aggregate ("respondent" US48) via two
 * routes:
 *   - electricity/rto/region-data: demand (type=D), net generation
 *     (type=NG) and total interchange (type=TI) - one HTTP call for all
 *     three, since EIA lets you request multiple facet values at once and
 *     returns a "type" field per row to tell them apart.
 *   - electricity/rto/fuel-type-data: net generation broken down by fuel
 *     type (fueltype = COL/NG/NUC/OIL/WAT/SUN/WND/OTH/UNK) - a second call,
 *     since it's a different route with a different facet shape.
 *
 * Both routes' facet codes (US48, D/NG/TI, and the fuel type codes) are the
 * standard, widely-documented EIA-930 codes used across EIA's own tooling
 * and most third-party grid dashboards - but this project's build
 * environment couldn't complete a live, authenticated request against
 * api.eia.gov to verify them directly (every unauthenticated probe just
 * returns an auth error with no body to inspect). Written defensively for
 * exactly that reason, the same pattern as ukgrid_ingest_neso() and
 * ukgrid_ingest_eirgrid() above: if a request succeeds but nothing matches
 * the expected facet values, this logs the raw facet/type values actually
 * seen in the response so a real mismatch is easy to diagnose and fix
 * in-place, rather than failing silently.
 *
 * EIA-930 data itself typically lags about a day behind real time - not a
 * bug here, see includes/config.php.example's usa_staleness_minutes and
 * pages/usa.html's own copy, which says so honestly.
 */
function ukgrid_ingest_eia(PDO $pdo, array $config, int $timeoutSeconds = 15): array
{
    $apiKey = trim((string) ($config['sources']['eia_api_key'] ?? ''));
    if ($apiKey === '' || $apiKey === 'CHANGE-ME') {
        // Not an error - most installs won't have registered a key. Logged
        // as OK/0-rows so ingest_log shows "not configured" rather than a
        // recurring false alarm.
        ukgrid_log_ingest('EIA', 'OK', 0, 'eia_api_key not set - skipping (USA page stays on illustrative data until you add one, see includes/config.php.example).');
        return ['rows' => 0, 'errors' => []];
    }

    $base = rtrim($config['sources']['eia_base'] ?? 'https://api.eia.gov/v2', '/');
    $start = gmdate('Y-m-d', time() - 4 * 86400); // several days' overlap - EIA backfills/revises recent hours
    $end = gmdate('Y-m-d');
    $errors = [];
    $rowsWritten = 0;
    $completedNormally = false;
    ukgrid_register_partial_progress_safety_net('EIA', 'its two requests (region-data, fuel-type-data)', $completedNormally, $rowsWritten, $errors);

    // ---------- demand, net generation, total interchange ----------
    $regionUrl = $base . '/electricity/rto/region-data/data/'
        . '?api_key=' . urlencode($apiKey)
        . '&frequency=hourly&data[0]=value'
        . '&facets[respondent][]=US48'
        . '&facets[type][]=D&facets[type][]=NG&facets[type][]=TI'
        . '&start=' . urlencode($start) . '&end=' . urlencode($end)
        . '&sort[0][column]=period&sort[0][direction]=desc&length=5000';
    $regionData = ukgrid_http_get_json($regionUrl, $timeoutSeconds);
    if ($regionData === null) {
        $errors[] = 'region-data request failed (check the API key is valid, or see includes/refresh.log for the raw HTTP error).';
    } else {
        $rows = $regionData['response']['data'] ?? null;
        if (!is_array($rows)) {
            $errors[] = 'region-data: unexpected response shape - top-level keys seen: ' . implode(', ', array_keys((array) $regionData));
        } else {
            $demandStmt = $pdo->prepare('INSERT INTO readings_us_demand (ts, mw) VALUES (:ts, :mw) ON DUPLICATE KEY UPDATE mw = VALUES(mw)');
            $genStmt = $pdo->prepare('INSERT INTO readings_us_generation (ts, category, mw) VALUES (:ts, :category, :mw) ON DUPLICATE KEY UPDATE mw = VALUES(mw)');
            $typesSeen = [];
            $areaRows = 0;
            foreach ($rows as $row) {
                $type = $row['type'] ?? null;
                if ($type !== null && !in_array((string) $type, $typesSeen, true)) {
                    $typesSeen[] = (string) $type;
                }
                $period = $row['period'] ?? null;
                $value = $row['value'] ?? null;
                if ($period === null || $value === null || !is_numeric($value)) {
                    continue;
                }
                $tsSql = ukgrid_eia_period_to_mysql((string) $period);
                if ($tsSql === null) {
                    continue;
                }
                if ($type === 'D') {
                    $demandStmt->execute(['ts' => $tsSql, 'mw' => (float) $value]);
                    $areaRows++;
                } elseif ($type === 'NG') {
                    $genStmt->execute(['ts' => $tsSql, 'category' => 'TOTAL', 'mw' => (float) $value]);
                    $areaRows++;
                } elseif ($type === 'TI') {
                    $genStmt->execute(['ts' => $tsSql, 'category' => 'INTERCONNECTION', 'mw' => (float) $value]);
                    $areaRows++;
                }
            }
            if ($areaRows === 0) {
                $errors[] = 'region-data: response parsed but no D/NG/TI rows were usable (checked ' . count($rows) . ' row(s)). "type" values actually seen: ' . (empty($typesSeen) ? 'none' : implode(', ', $typesSeen)) . '. If that list doesn\'t contain D, NG or TI, EIA has changed these facet codes - update ukgrid_ingest_eia() to match.';
            }
            $rowsWritten += $areaRows;
        }
    }

    // ---------- generation by fuel type ----------
    $fuelUrl = $base . '/electricity/rto/fuel-type-data/data/'
        . '?api_key=' . urlencode($apiKey)
        . '&frequency=hourly&data[0]=value'
        . '&facets[respondent][]=US48'
        . '&start=' . urlencode($start) . '&end=' . urlencode($end)
        . '&sort[0][column]=period&sort[0][direction]=desc&length=5000';
    $fuelData = ukgrid_http_get_json($fuelUrl, $timeoutSeconds);
    if ($fuelData === null) {
        $errors[] = 'fuel-type-data request failed.';
    } else {
        $rows = $fuelData['response']['data'] ?? null;
        if (!is_array($rows)) {
            $errors[] = 'fuel-type-data: unexpected response shape - top-level keys seen: ' . implode(', ', array_keys((array) $fuelData));
        } else {
            $genStmt = $pdo->prepare('INSERT INTO readings_us_generation (ts, category, mw) VALUES (:ts, :category, :mw) ON DUPLICATE KEY UPDATE mw = VALUES(mw)');
            $fuelTypesSeen = [];
            $areaRows = 0;
            foreach ($rows as $row) {
                $fuelType = $row['fueltype'] ?? null;
                if ($fuelType !== null && !in_array((string) $fuelType, $fuelTypesSeen, true)) {
                    $fuelTypesSeen[] = (string) $fuelType;
                }
                $period = $row['period'] ?? null;
                $value = $row['value'] ?? null;
                if ($fuelType === null || $period === null || $value === null || !is_numeric($value)) {
                    continue;
                }
                $tsSql = ukgrid_eia_period_to_mysql((string) $period);
                if ($tsSql === null) {
                    continue;
                }
                $genStmt->execute(['ts' => $tsSql, 'category' => (string) $fuelType, 'mw' => (float) $value]);
                $areaRows++;
            }
            if ($areaRows === 0) {
                $errors[] = 'fuel-type-data: response parsed but no usable rows were found (checked ' . count($rows) . ' row(s)). "fueltype" values actually seen: ' . (empty($fuelTypesSeen) ? 'none' : implode(', ', $fuelTypesSeen)) . '.';
            }
            $rowsWritten += $areaRows;
        }
    }

    $status = $rowsWritten > 0 ? 'OK' : 'ERROR';
    $completedNormally = true; // tells the shutdown safety net above this run's already been logged - don't log it twice
    ukgrid_log_ingest('EIA', $status, $rowsWritten, implode('; ', $errors));
    return ['rows' => $rowsWritten, 'errors' => $errors];
}

/**
 * ENTSO-E generation-type ("psrType") codes, per the Transparency
 * Platform's published code list - stable and unchanged for many years.
 * Used both to give readable labels in the frontend (via
 * assets/data.js's matching ENTSOE_PSR_LABELS) and to group codes into the
 * fossil/renewable/nuclear/other buckets the site's mix donuts use
 * elsewhere.
 */
const UKGRID_ENTSOE_PSR_LABELS = [
    'B01' => 'Biomass', 'B02' => 'Lignite', 'B03' => 'Coal gas', 'B04' => 'Gas',
    'B05' => 'Hard coal', 'B06' => 'Oil', 'B07' => 'Oil shale', 'B08' => 'Peat',
    'B09' => 'Geothermal', 'B10' => 'Pumped storage', 'B11' => 'Hydro (run-of-river)',
    'B12' => 'Hydro (reservoir)', 'B13' => 'Marine', 'B14' => 'Nuclear',
    'B15' => 'Other renewable', 'B16' => 'Solar', 'B17' => 'Waste',
    'B18' => 'Wind (offshore)', 'B19' => 'Wind (onshore)', 'B20' => 'Other',
];

/**
 * Parses one ENTSO-E TimeSeries/Period/Point XML document (either a
 * GL_MarketDocument - actual load A65, or actual generation per type A75 -
 * or a Publication_MarketDocument - day-ahead prices A44) into a flat list
 * of readings. $valueField is "quantity" for load/generation or
 * "price.amount" for prices - the tag name differs by document type, and
 * "price.amount" isn't a valid PHP property access (the dot), so it's
 * looked up via a string key on the child-element array instead of "->".
 *
 * ENTSO-E returns a real, useful XML body on errors too (wrong/expired
 * token, no data published for that period, etc.) as an
 * Acknowledgement_MarketDocument with a human-readable reason - that's
 * detected and surfaced as $result['error'] rather than treated as a
 * silent zero-rows response, the same "log what actually came back"
 * approach as ukgrid_ingest_neso()/ukgrid_ingest_eirgrid() above. This
 * project's build environment couldn't complete a live authenticated
 * request against the Transparency Platform to verify the exact element
 * names below - they're the standard, long-stable IEC 62325 schema names
 * used throughout ENTSO-E's own documentation and widely used third-party
 * tooling, but if ingest_log ever shows a parse error here, that's the
 * first place to check against a real response.
 */
function ukgrid_entsoe_parse_points(string $xmlBody, string $valueField): array
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlBody);
    if ($xml === false) {
        $libErrors = array_map(function ($e) { return trim($e->message); }, libxml_get_errors());
        libxml_clear_errors();
        // ukgrid_http_get_raw() only treats a missing body or a 5xx as a
        // hard failure - a 200 (or 4xx) response with a real but non-XML
        // body (a JSON error object, a plain-text auth message, an HTML
        // error page) reaches this function as-is, and a bare libxml
        // message like "Start tag expected, '<' not found" gives no clue
        // what that body actually was. A short snippet of the raw response
        // is usually the single most useful piece of information for
        // diagnosing this - e.g. it's what would show a bad/expired
        // securityToken's real rejection message instead of a generic
        // parse error.
        $snippet = trim(preg_replace('/\s+/', ' ', mb_substr($xmlBody, 0, 300)));
        $error = 'XML parse failure: ' . implode('; ', array_slice($libErrors, 0, 3))
            . '. Raw response started with: "' . $snippet . '"';
        return ['ok' => false, 'error' => $error, 'points' => []];
    }

    $rootName = $xml->getName();
    if (stripos($rootName, 'Acknowledgement') !== false) {
        $reason = (string) ($xml->Reason->text ?? $xml->Reason->code ?? 'no reason given');
        return ['ok' => false, 'error' => "ENTSO-E returned an Acknowledgement (not data): {$reason}", 'points' => []];
    }

    $points = [];
    $seriesCount = 0;
    foreach ($xml->TimeSeries as $series) {
        $seriesCount++;
        $psrType = isset($series->MktPSRType->psrType) ? (string) $series->MktPSRType->psrType : null;
        foreach ($series->Period as $period) {
            $start = (string) ($period->timeInterval->start ?? '');
            $resolutionRaw = (string) ($period->resolution ?? 'PT60M');
            $resolutionSeconds = ukgrid_entsoe_resolution_seconds($resolutionRaw);
            $startTs = strtotime($start);
            if ($startTs === false || $resolutionSeconds === null) {
                continue;
            }
            foreach ($period->Point as $point) {
                $position = (int) ($point->position ?? 0);
                $pointChildren = (array) $point;
                $value = $pointChildren[$valueField] ?? null;
                if ($position < 1 || $value === null || !is_numeric((string) $value)) {
                    continue;
                }
                $ts = $startTs + ($position - 1) * $resolutionSeconds;
                $points[] = [
                    'psrType' => $psrType,
                    'ts' => gmdate('Y-m-d H:i:s', $ts),
                    'value' => (float) $value,
                ];
            }
        }
    }

    if ($seriesCount === 0) {
        return ['ok' => false, 'error' => 'no <TimeSeries> elements in response - root element was <' . $rootName . '>', 'points' => []];
    }
    if (empty($points)) {
        return ['ok' => false, 'error' => "found {$seriesCount} TimeSeries element(s) but no usable Points (checked for value field \"{$valueField}\")", 'points' => []];
    }
    return ['ok' => true, 'error' => null, 'points' => $points];
}

/** "PT60M" -> 3600, "PT30M" -> 1800, "PT15M" -> 900, "P1D" -> 86400. Returns null for anything unrecognised. */
function ukgrid_entsoe_resolution_seconds(string $resolution): ?int
{
    if (preg_match('/^PT(\d+)M$/', $resolution, $m)) {
        return ((int) $m[1]) * 60;
    }
    if (preg_match('/^PT(\d+)H$/', $resolution, $m)) {
        return ((int) $m[1]) * 3600;
    }
    if ($resolution === 'P1D') {
        return 86400;
    }
    return null;
}

/**
 * ENTSO-E Transparency Platform - generic across every country configured
 * in includes/config.php's entsoe_countries (see config.php.example for
 * the full explanation and the list of countries/EIC domains this project
 * ships with). One function handles all of them by looping the config,
 * rather than a separate function per country - adding a country is a
 * config change, not a code change.
 *
 * Fetches, per configured country and per its 'fetch' list:
 *   - price: day-ahead prices (documentType=A44), EUR/MWh
 *   - generation: actual generation per type (documentType=A75, processType=A16)
 *   - demand: actual total load (documentType=A65, processType=A16)
 *
 * See ukgrid_entsoe_parse_points()'s docblock for why this is written
 * defensively (couldn't verify against a live authenticated response while
 * building this).
 */
/**
 * Epoch timestamp of $countryCode's most recent ENTSO-E reading, across all
 * three per-metric tables - or 0 if it's never had one (COALESCE falls back
 * to "1970-01-01", which sorts first as "most in need of a refresh" either
 * way, so callers don't need to special-case "never fetched" separately
 * from "very stale"). Used by ukgrid_ingest_entsoe() both to skip countries
 * that don't need refreshing yet and to prioritise the most-overdue ones
 * first - see that function's $skipFresherThanMinutes and
 * $maxCountriesPerRun parameters for why.
 */
function ukgrid_entsoe_country_latest_ts(PDO $pdo, string $countryCode): int
{
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare(
            'SELECT GREATEST(
                COALESCE((SELECT MAX(ts) FROM readings_entsoe_price WHERE country_code = :cc1), "1970-01-01"),
                COALESCE((SELECT MAX(ts) FROM readings_entsoe_generation WHERE country_code = :cc2), "1970-01-01"),
                COALESCE((SELECT MAX(ts) FROM readings_entsoe_demand WHERE country_code = :cc3), "1970-01-01")
            ) AS latest'
        );
    }
    $stmt->execute(['cc1' => $countryCode, 'cc2' => $countryCode, 'cc3' => $countryCode]);
    $latest = $stmt->fetchColumn();
    if ($latest === false) {
        return 0;
    }
    $ts = strtotime((string) $latest);
    return $ts === false ? 0 : $ts;
}

/**
 * $skipFresherThanMinutes: if > 0, a country whose own data is already
 * newer than this many minutes is left out of this run entirely, rather
 * than always considering all 6 configured countries.
 *
 * $maxCountriesPerRun: if > 0, caps how many of the (not-skipped) countries
 * actually get fetched THIS call, prioritising whichever have gone longest
 * without a refresh (ties broken by entsoe_countries' own order). The rest
 * are simply left for a later call rather than attempted now.
 *
 * Both exist for the on-demand (no-cron) refresh path in includes/refresh.php:
 * without cron, on-demand page loads are the ONLY thing that ever calls this
 * function, so it has to be cheap enough to run inside a real visitor's page
 * load EVERY time it's invoked - including the very first one, before
 * anything has ever been fetched. $skipFresherThanMinutes alone isn't
 * enough to guarantee that: on a cold start (or after a long outage) every
 * country is equally overdue, so nothing gets skipped and this would still
 * attempt the full 17 requests in one go. That's exactly what caused this
 * page's countries to stay illustrative even after cron/fetch_entsoe.php
 * was fixed - on-demand refresh has no cron script's set_time_limit()
 * override, so PHP's default execution limit (commonly ~30s on shared
 * hosting for a normal web request) could still kill a 17-request cold-start
 * run partway through, silently favouring whichever countries happen to be
 * listed first in entsoe_countries (Ireland, France) over the rest.
 * $maxCountriesPerRun=1 caps every on-demand call - cold start included -
 * to at most 3 requests, and staleness-based ordering (rather than config
 * order) means repeated triggers naturally rotate through every country
 * rather than always favouring the same ones.
 *
 * Cron (cron/fetch_entsoe.php) passes 0 for both (the defaults) - a
 * background job isn't blocking anyone and has its own generous
 * set_time_limit(450), so it always refreshes every configured country in
 * full regardless of how fresh each already is.
 */
function ukgrid_ingest_entsoe(PDO $pdo, array $config, int $timeoutSeconds = 25, int $skipFresherThanMinutes = 0, int $maxCountriesPerRun = 0): array
{
    $token = trim((string) ($config['sources']['entsoe_api_token'] ?? ''));
    if ($token === '' || $token === 'CHANGE-ME') {
        ukgrid_log_ingest('ENTSOE', 'OK', 0, 'entsoe_api_token not set - skipping (Ireland\'s SEM price/mix and the France/Netherlands/Belgium/Norway/Denmark pages stay on illustrative data until you add one, see includes/config.php.example).');
        return ['rows' => 0, 'errors' => []];
    }

    $base = rtrim($config['sources']['entsoe_base'] ?? 'https://web-api.tp.entsoe.eu/api', '/');
    $countries = $config['sources']['entsoe_countries'] ?? [];
    if (empty($countries)) {
        ukgrid_log_ingest('ENTSOE', 'ERROR', 0, 'entsoe_api_token is set but entsoe_countries is empty - nothing to fetch.');
        return ['rows' => 0, 'errors' => ['entsoe_countries is empty']];
    }

    // Which countries actually get fetched this run, and in what order -
    // staleness-first (oldest/never-fetched data first) rather than
    // entsoe_countries' fixed config order, so a capped run
    // ($maxCountriesPerRun) rotates through every country over successive
    // triggers instead of always favouring whichever's listed first.
    $candidates = [];
    foreach ($countries as $countryCode => $countryCfg) {
        if (($countryCfg['domain'] ?? null) === null || empty($countryCfg['fetch'] ?? [])) {
            continue;
        }
        $latestTs = ukgrid_entsoe_country_latest_ts($pdo, $countryCode);
        if ($skipFresherThanMinutes > 0 && $latestTs > (time() - $skipFresherThanMinutes * 60)) {
            continue; // already fresh enough - don't spend this run's time budget re-fetching it
        }
        $candidates[$countryCode] = $latestTs;
    }
    asort($candidates); // oldest/never-fetched (lowest timestamp) first
    $toFetch = array_keys($candidates);
    if ($maxCountriesPerRun > 0) {
        $toFetch = array_slice($toFetch, 0, $maxCountriesPerRun);
    }

    // A few hours' overlap (rather than just "now") covers any missed
    // refresh cycles and TSOs that publish with a delay - see
    // entsoe_staleness_minutes in config.php.example for the matching
    // frontend-facing threshold.
    $periodStart = gmdate('YmdHi', time() - 6 * 3600);
    $periodEnd = gmdate('YmdHi', time() + 3600); // a little into the future - day-ahead prices are known in advance

    $priceStmt = $pdo->prepare('INSERT INTO readings_entsoe_price (ts, country_code, price_eur_mwh) VALUES (:ts, :cc, :v) ON DUPLICATE KEY UPDATE price_eur_mwh = VALUES(price_eur_mwh)');
    $demandStmt = $pdo->prepare('INSERT INTO readings_entsoe_demand (ts, country_code, mw) VALUES (:ts, :cc, :v) ON DUPLICATE KEY UPDATE mw = VALUES(mw)');
    $genStmt = $pdo->prepare('INSERT INTO readings_entsoe_generation (ts, country_code, psr_type, mw) VALUES (:ts, :cc, :psr, :v) ON DUPLICATE KEY UPDATE mw = VALUES(mw)');

    $rowsWritten = 0;
    $errors = [];
    $isFirstRequest = true;
    $completedNormally = false;

    // See ukgrid_register_partial_progress_safety_net()'s doc comment for
    // why this exists - this is the exact function whose real-world
    // interruption (cron's shared time limit killing this mid-loop, see
    // cron/fetch_entsoe.php's docblock) motivated adding it.
    ukgrid_register_partial_progress_safety_net('ENTSOE', 'entsoe_countries', $completedNormally, $rowsWritten, $errors);

    foreach ($toFetch as $countryCode) {
        $countryCfg = $countries[$countryCode];
        $domain = $countryCfg['domain'];
        $fetch = $countryCfg['fetch'];

        foreach ($fetch as $kind) {
            // Same reasoning as ukgrid_ingest_eirgrid()'s inter-request
            // gap: several sequential requests to the same host back-to-
            // back risks looking like abuse even though ENTSO-E's stated
            // limit (400 req/min) is generous - this whole function makes
            // at most 18 requests (6 countries x 3 kinds) per run either way.
            if (!$isFirstRequest) {
                usleep(200000);
            }
            $isFirstRequest = false;

            // $base has no trailing slash (rtrim() above) - the query
            // string is appended straight after it as "...api?..." to
            // match ENTSO-E's own documented endpoint exactly. Confirmed
            // live (via tools/full-refresh.php) that the previous
            // "...api/?..." form (an extra slash before the "?") got a
            // JSON "uuAppErrorMap ... resourceNotFound" response from
            // their API gateway instead of the expected XML - i.e. the
            // gateway treats "/api" and "/api/" as different routes, and
            // only one of them is actually registered.
            if ($kind === 'price') {
                $url = $base . '?securityToken=' . urlencode($token)
                    . '&documentType=A44&in_Domain=' . urlencode($domain) . '&out_Domain=' . urlencode($domain)
                    . '&periodStart=' . $periodStart . '&periodEnd=' . $periodEnd;
                $valueField = 'price.amount';
            } elseif ($kind === 'generation') {
                $url = $base . '?securityToken=' . urlencode($token)
                    . '&documentType=A75&processType=A16&in_Domain=' . urlencode($domain)
                    . '&periodStart=' . $periodStart . '&periodEnd=' . $periodEnd;
                $valueField = 'quantity';
            } elseif ($kind === 'demand') {
                $url = $base . '?securityToken=' . urlencode($token)
                    . '&documentType=A65&processType=A16&outBiddingZone_Domain=' . urlencode($domain)
                    . '&periodStart=' . $periodStart . '&periodEnd=' . $periodEnd;
                $valueField = 'quantity';
            } else {
                continue;
            }

            $body = ukgrid_http_get_raw($url, $timeoutSeconds);
            if ($body === null) {
                $errors[] = "{$countryCode}/{$kind}: request failed (see includes/refresh.log for the raw HTTP error).";
                continue;
            }
            $parsed = ukgrid_entsoe_parse_points($body, $valueField);
            if (!$parsed['ok']) {
                $errors[] = "{$countryCode}/{$kind}: {$parsed['error']}";
                continue;
            }

            $countryRows = 0;
            foreach ($parsed['points'] as $point) {
                if ($kind === 'price') {
                    $priceStmt->execute(['ts' => $point['ts'], 'cc' => $countryCode, 'v' => $point['value']]);
                } elseif ($kind === 'demand') {
                    $demandStmt->execute(['ts' => $point['ts'], 'cc' => $countryCode, 'v' => $point['value']]);
                } else { // generation
                    $psr = $point['psrType'] ?? 'UNK';
                    $genStmt->execute(['ts' => $point['ts'], 'cc' => $countryCode, 'psr' => $psr, 'v' => $point['value']]);
                }
                $countryRows++;
            }
            $rowsWritten += $countryRows;
        }
    }

    $status = $rowsWritten > 0 ? 'OK' : 'ERROR';
    $completedNormally = true; // tells the shutdown safety net above this run's already been logged - don't log it twice
    ukgrid_log_ingest('ENTSOE', $status, $rowsWritten, implode('; ', $errors));
    return ['rows' => $rowsWritten, 'errors' => $errors];
}

/**
 * EIA v2's hourly "period" values look like "2026-08-09T13" (hour-ending,
 * UTC, no minutes/seconds) - not a format PHP's strtotime() reliably
 * parses on its own across PHP versions, so this pads it out explicitly.
 * Falls back to a plain strtotime() for any other shape EIA might return
 * (e.g. a bare date for a coarser frequency), rather than assuming the
 * hourly shape is the only one that will ever come back.
 */
function ukgrid_eia_period_to_mysql(string $period): ?string
{
    if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2})$/', $period, $m)) {
        $ts = strtotime($m[1] . ' ' . $m[2] . ':00:00 UTC');
        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }
    $ts = strtotime($period . ' UTC');
    return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
}

/**
 * Converts a naive "Y-m-d H:i:s"-shaped local Ontario clock time (as
 * published by IESO - see ukgrid_ingest_ieso()) into a UTC "Y-m-d H:i:s"
 * string for storage, correctly handling the America/Toronto DST
 * transitions (EST is UTC-5, EDT is UTC-4). Every other ingest function in
 * this file works with sources that already publish UTC, so this is the
 * one place on the site that needs an explicit local-to-UTC conversion.
 * Returns null if $localDateTime can't be parsed.
 */
function ukgrid_toronto_to_utc(string $localDateTime): ?string
{
    try {
        $dt = new DateTime($localDateTime, new DateTimeZone('America/Toronto'));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * IESO (Ontario's system operator) - no API key required, but also not a
 * REST API: these are flat, stable-URL XML report files re-published in
 * place ("most recent" aliases with no query parameters), documented at
 * https://www.ieso.ca/power-data/data-directory. This project's build
 * environment could reach and parse a real, current response from both
 * report URLs below while this function was written (unlike ENTSO-E, which
 * needed an auth token this environment didn't have) - the element names
 * below are taken directly from those live responses, not guessed from
 * documentation.
 *
 * Only covers Ontario, not the rest of Canada - Ontario is IESO's service
 * area and the only Canadian jurisdiction with a fully public, keyless,
 * machine-readable real-time feed found during research for this feature.
 * See pages/canada.html and pages/data-sources.html for that scope
 * disclosure, and Alberta's AESO (which does publish similar data, but
 * requires a free registered API key - see includes/config.php.example's
 * ieso... note, actually aeso_api_key once added) as a possible future
 * addition.
 *
 * Two reports:
 *  - RealtimeTotals: <Document><DocBody><DeliveryDate>, <DeliveryHour>,
 *    <Energies><IntervalEnergy> (one per 5-minute interval within that
 *    hour) each with <Interval> (1-N) and a list of <MQ><MarketQuantity>/
 *    <EnergyMW> pairs - the "ONTARIO DEMAND" one is what this site's other
 *    country pages call "Demand". Only ever contains the most recent hour
 *    at the "most recent" alias URL used here, so (like GB's own Elexon
 *    feed) History for Canada builds up from repeated polling over time
 *    rather than a one-off backfill.
 *  - GenOutputbyFuelHourly: <Document><DocBody><DailyData><Day> containing
 *    <HourlyData><Hour> containing <FuelTotal><Fuel>/<EnergyValue><Output>
 *    (MW) for NUCLEAR, GAS, HYDRO, WIND, SOLAR, BIOFUEL, OTHER - covers
 *    however many recent days IESO currently has published at the "most
 *    recent" alias (observed to be a rolling window, not the full year
 *    despite the document's <DeliveryYear> tag).
 *
 * Both reports publish Ontario local clock time (no explicit UTC offset in
 * the XML) - see ukgrid_toronto_to_utc() for the DST-aware conversion.
 */
function ukgrid_ingest_ieso(PDO $pdo, array $config, int $timeoutSeconds = 20): array
{
    $errors = [];
    $rowsWritten = 0;
    $completedNormally = false;
    ukgrid_register_partial_progress_safety_net('IESO', 'its two report fetches (RealtimeTotals, GenOutputbyFuelHourly)', $completedNormally, $rowsWritten, $errors);

    $realtimeUrl = 'https://reports-public.ieso.ca/public/RealtimeTotals/PUB_RealtimeTotals.xml';
    $fuelUrl = 'https://reports-public.ieso.ca/public/GenOutputbyFuelHourly/PUB_GenOutputbyFuelHourly.xml';

    // ---------- Ontario demand (5-minute intervals, most recent hour) ----------
    $realtimeBody = ukgrid_http_get_raw($realtimeUrl, $timeoutSeconds);
    if ($realtimeBody === null) {
        $errors[] = 'RealtimeTotals request failed.';
    } else {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($realtimeBody);
        if ($xml === false) {
            $libErrors = array_map(function ($e) { return trim($e->message); }, libxml_get_errors());
            libxml_clear_errors();
            $errors[] = 'RealtimeTotals: XML parse failure: ' . implode('; ', array_slice($libErrors, 0, 3));
        } else {
            $xml->registerXPathNamespace('ieso', 'http://www.ieso.ca/schema');
            $deliveryDate = (string) ($xml->DocBody->DeliveryDate ?? '');
            $deliveryHour = (string) ($xml->DocBody->DeliveryHour ?? '');
            $demandStmt = $pdo->prepare('INSERT INTO readings_ca_demand (ts, mw) VALUES (:ts, :mw) ON DUPLICATE KEY UPDATE mw = VALUES(mw)');
            $areaRows = 0;
            if ($deliveryDate === '' || $deliveryHour === '' || !isset($xml->DocBody->Energies)) {
                $errors[] = 'RealtimeTotals: response parsed but DeliveryDate/DeliveryHour/Energies were missing - IESO may have changed this report\'s shape.';
            } else {
                $hourStartLocal = $deliveryDate . ' ' . str_pad($deliveryHour, 2, '0', STR_PAD_LEFT) . ':00:00';
                foreach ($xml->DocBody->Energies->IntervalEnergy as $interval) {
                    $intervalNum = (int) ($interval->Interval ?? 0);
                    if ($intervalNum < 1) {
                        continue;
                    }
                    $localTs = date('Y-m-d H:i:s', strtotime($hourStartLocal) + ($intervalNum - 1) * 300);
                    $utcTs = ukgrid_toronto_to_utc($localTs);
                    if ($utcTs === null) {
                        continue;
                    }
                    foreach ($interval->MQ as $mq) {
                        if ((string) $mq->MarketQuantity === 'ONTARIO DEMAND') {
                            $mw = (string) $mq->EnergyMW;
                            if (is_numeric($mw)) {
                                $demandStmt->execute(['ts' => $utcTs, 'mw' => (float) $mw]);
                                $areaRows++;
                            }
                        }
                    }
                }
            }
            if ($areaRows === 0 && empty($errors)) {
                $errors[] = 'RealtimeTotals: response parsed but no "ONTARIO DEMAND" MarketQuantity rows were usable.';
            }
            $rowsWritten += $areaRows;
        }
    }

    // ---------- generation by fuel type (hourly) ----------
    $fuelBody = ukgrid_http_get_raw($fuelUrl, $timeoutSeconds);
    if ($fuelBody === null) {
        $errors[] = 'GenOutputbyFuelHourly request failed.';
    } else {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($fuelBody);
        if ($xml === false) {
            $libErrors = array_map(function ($e) { return trim($e->message); }, libxml_get_errors());
            libxml_clear_errors();
            $errors[] = 'GenOutputbyFuelHourly: XML parse failure: ' . implode('; ', array_slice($libErrors, 0, 3));
        } else {
            $genStmt = $pdo->prepare('INSERT INTO readings_ca_generation (ts, category, mw) VALUES (:ts, :category, :mw) ON DUPLICATE KEY UPDATE mw = VALUES(mw)');
            $areaRows = 0;
            $dailyBlocks = $xml->DocBody->DailyData ?? [];
            foreach ($dailyBlocks as $daily) {
                $day = (string) ($daily->Day ?? '');
                if ($day === '') {
                    continue;
                }
                foreach ($daily->HourlyData as $hourly) {
                    $hour = (int) ($hourly->Hour ?? 0);
                    if ($hour < 1 || $hour > 24) {
                        continue;
                    }
                    // IESO's "Hour" is 1-24 (hour-ending), so hour 24 is
                    // midnight at the START of the NEXT day, hours 1-23 map
                    // straight onto local clock hours 1-23 the same day.
                    $localTs = ($hour === 24)
                        ? date('Y-m-d H:i:s', strtotime($day . ' 00:00:00') + 86400)
                        : $day . ' ' . str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . ':00:00';
                    $utcTs = ukgrid_toronto_to_utc($localTs);
                    if ($utcTs === null) {
                        continue;
                    }
                    foreach ($hourly->FuelTotal as $fuelTotal) {
                        $fuel = (string) ($fuelTotal->Fuel ?? '');
                        $output = (string) ($fuelTotal->EnergyValue->Output ?? '');
                        if ($fuel === '' || !is_numeric($output)) {
                            continue;
                        }
                        $genStmt->execute(['ts' => $utcTs, 'category' => $fuel, 'mw' => (float) $output]);
                        $areaRows++;
                    }
                }
            }
            if ($areaRows === 0 && empty($errors)) {
                $errors[] = 'GenOutputbyFuelHourly: response parsed but no usable FuelTotal rows were found.';
            }
            $rowsWritten += $areaRows;
        }
    }

    $status = $rowsWritten > 0 ? 'OK' : 'ERROR';
    $completedNormally = true; // tells the shutdown safety net above this run's already been logged - don't log it twice
    ukgrid_log_ingest('IESO', $status, $rowsWritten, implode('; ', $errors));
    return ['rows' => $rowsWritten, 'errors' => $errors];
}
