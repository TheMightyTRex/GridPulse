<?php
/**
 * Cron entry point for ENTSO-E ingestion (Ireland's SEM price + generation
 * mix, plus France/Netherlands/Belgium/Norway/Denmark's demand, generation
 * and price). See includes/refresh.php for the no-cron on-demand
 * alternative.
 *
 * Requires includes/config.php's sources.entsoe_api_token to be set to a
 * real token (see includes/config.php.example for the full registration
 * process - it's a multi-day process involving an email to ENTSO-E, not an
 * instant signup like the other sources here). With the default
 * 'CHANGE-ME' placeholder, this runs and logs "OK, 0 rows" without
 * erroring, and every ENTSO-E-sourced figure stays illustrative.
 *
 * One run here makes up to 18 requests (6 configured countries x up to 3
 * of price/generation/demand each - see entsoe_countries in
 * includes/config.php.example) - well under ENTSO-E's stated 400
 * requests/minute limit, but the reason this cron entry (and the
 * on-demand equivalent) budgets a longer timeout than the other sources.
 *
 * See includes/ingest.php's ukgrid_ingest_entsoe() for why this is written
 * defensively (this project's build environment couldn't complete a live
 * authenticated request against the Transparency Platform to verify the
 * exact XML shape) and what to do if it logs an ERROR.
 *
 * Recommended cron frequency if you do use cron: every hour. ENTSO-E TSOs
 * typically publish actual load/generation within 1-3 hours, and
 * day-ahead prices only change once per day, so anything faster just
 * spends API calls for no benefit.
 *
 * Time budget: cron/_bootstrap.php sets a shared set_time_limit(120) for
 * all three cron entry points, which is plenty for Elexon/EirGrid's much
 * shorter request counts but not for this one - up to 17 sequential
 * requests (6 countries, IE/FR/NL/BE/NO/DK in that order, up to 3 each)
 * with a 60s-per-request timeout meant a single slow response among the
 * first couple of countries could burn through most of the 120s budget,
 * getting the whole script killed by PHP's own time limit before it ever
 * reached the countries later in entsoe_countries - NL, BE, NO and DK would
 * silently never get fetched that cycle while IE/FR (processed first) kept
 * working, which is exactly the "some countries never go live" pattern
 * this shipped with. Fixed two ways: a shorter 20s per-request timeout
 * below (still generous, matches the other cron entry points' defaults)
 * so one slow request can't dominate the budget, and a longer 450s
 * override here - comfortably past the ~344s true worst case (17 x 20s +
 * inter-request gaps) if every single request happened to time out, while
 * still being a bounded, background-only job that never blocks a page
 * load (see includes/refresh.php's much tighter on-demand timeout for
 * that path instead).
 */

require __DIR__ . '/_bootstrap.php';
set_time_limit(450);

$config = ukgrid_load_config();
$pdo = ukgrid_db();
$result = ukgrid_ingest_entsoe($pdo, $config, 20); // logs to ingest_log itself

ukgrid_out(($result['rows'] > 0 ? 'OK' : 'ERROR') . ' - ' . $result['rows'] . ' rows written/updated.' . (empty($result['errors']) ? '' : ' Errors: ' . implode('; ', $result['errors'])));
