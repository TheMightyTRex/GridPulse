# UK Grid: Live+ - deployment & configuration guide

This folder is the full site: static frontend (the same pages you previewed) plus a PHP/MySQL backend that polls public data sources and stores real history - three for the Great Britain pages, EirGrid's Smart Grid Dashboard for the Ireland page, and (optionally) the EIA API for part of the USA page. Written for **shared hosting with cPanel or Plesk** - no SSH/shell access assumed. No API key is required for the first four sources; the EIA source is the one exception (see "About the data sources" below) - skip it and the USA page simply stays on illustrative data.

Everything below is one-time setup. Once it's done, the site runs itself.

## What's in this folder

```
index.html, pages/, assets/    the site (unchanged from the preview you saw)
api/current.php                JSON: latest GB snapshot (price, demand, emissions, generation mix)
api/series.php                 JSON: historical GB series for the charts
api/ireland_current.php        JSON: latest Ireland snapshot (demand, generation, wind, GB interconnection, emissions)
api/ireland_series.php         JSON: historical Ireland series for the sparklines
api/usa_current.php            JSON: latest USA snapshot (demand, generation, total interchange) - needs eia_api_key
api/usa_series.php             JSON: historical USA series for the sparklines - needs eia_api_key
cron/fetch_elexon.php          pulls generation mix, price, demand from Elexon (no key needed)
cron/fetch_carbon_intensity.php pulls carbon intensity + a generation-mix cross-check (no key needed)
cron/fetch_neso.php            pulls embedded solar/wind estimates from NESO (no key needed)
cron/fetch_eirgrid.php         pulls Ireland demand/generation/wind/interconnection/CO2 from EirGrid (no key needed)
cron/fetch_eia.php             pulls USA demand/generation/interchange/fuel mix from the EIA (optional - needs eia_api_key)
cron/fetch_weather.php         pulls wind speed/cloud cover from Open-Meteo for the History page's weather card (no key needed - works via no-cron mode too, see Step 5)
cron/fetch_constraints.php     pulls NESO's network constraint costs for the Renewables page's constraints panel (no key needed - works via no-cron mode too, see Step 5)
cron/fetch_notable_moments.php recomputes this install's own recorded highs/lows for the homepage's "Notable moments" section - no external API, just re-scans data already stored here (no key needed - works via no-cron mode too, see Step 5)
includes/config.php.example    copy this to config.php and fill in your database details
includes/db.php                database connection helper (don't need to edit this)
sql/schema.sql                 the database structure - import this once
```

## Step 1 - Create the database

In cPanel, open **MySQL® Databases**.

1. Under "Create New Database", enter a name (e.g. `ukgrid`) and click Create. cPanel will prefix it with your account name, e.g. `myuser_ukgrid`.
2. Under "MySQL Users", create a new user with a strong password.
3. Under "Add User to Database", add that user to the database you just created, and grant **ALL PRIVILEGES**.
4. Write down the three values you now have: database name, username, password (both are usually prefixed `myuser_...`).

(Plesk: Databases > Add Database does the same thing in one screen.)

## Step 2 - Import the schema

1. In cPanel, open **phpMyAdmin** and select the database you just created.
2. Click **Import**, choose `sql/schema.sql` from this folder, and run it.
3. You should see 11 new tables: `readings_generation`, `readings_price`, `readings_demand`, `readings_emissions`, `readings_mix_pct`, `readings_ie_demand`, `readings_ie_generation`, `readings_ie_co2`, `readings_us_demand`, `readings_us_generation`, `ingest_log`.

## Step 3 - Upload the files

Upload the entire contents of this folder to your site's document root - usually `public_html` (or `public_html/subdomain-name` if this is going on a subdomain), using **File Manager** or FTP/SFTP.

**Optional hardening step**, recommended if you're comfortable with it: after uploading, create a folder called `uk-grid-config` *next to* `public_html` (i.e. one level up, not web-accessible), and you'll put the real `config.php` there instead of inside `includes/` - see Step 4. If that sounds fiddly, skip it: `includes/config.php` also works fine and is protected by an `.htaccess` file that blocks direct web access.

## Step 4 - Configure the database connection

1. Rename/copy `includes/config.php.example` to `includes/config.php` (or upload the filled-in version there - or to `uk-grid-config/config.php` outside `public_html` if you did the hardening step above).
2. Open it and fill in the `db` section with the three values from Step 1:

   ```php
   'db' => [
     'host' => 'localhost',
     'name' => 'myuser_ukgrid',
     'user' => 'myuser_ukgrid',
     'pass' => 'the-password-you-set',
     'charset' => 'utf8mb4',
   ],
   ```
3. Leave the `sources` section as-is - Elexon, Carbon Intensity, NESO and EirGrid don't need a key or account. See "About the data sources" below if you want to understand why. If you'd also like the USA page's demand/generation/interchange to go live, fill in `eia_api_key` too (optional, free registration - see "About the data sources" below); leave it as `CHANGE-ME` to skip this and keep the USA page fully illustrative.
4. Leave `cron_http_secret` blank unless you need the URL-triggered cron fallback in Step 5.

### `host`: "localhost" vs. a remote database hostname

Most cPanel plans run MySQL on the same server as the website, and `'host' => 'localhost'` is correct as-is - just fill in `name`/`user`/`pass`.

Some hosts (managed/boutique cPanel resellers, and some larger providers - prositehosting is one) run MySQL on a **separate database server**. You can tell because cPanel's **MySQL® Databases** page, or the database summary it shows after creation, gives a real hostname instead of just "localhost" - something like `mysql-200-136.mysql.yourhost.net`. If that's what you see, use that exact hostname for `host`. Using `localhost` (the example default) when your host actually needs a remote hostname is the single most common cause of a `db_connection_failed` error on this kind of hosting - double check your deployed `includes/config.php` still has the example's `'localhost'` left in by mistake.

If `host` is already set to the correct remote hostname and you still get `db_connection_failed`, the next most likely cause is that the database server isn't yet allowing connections from your web server. Some providers require you to explicitly enable **remote MySQL access** (cPanel's **Remote MySQL** page) for the web server's IP, even though both "belong" to your hosting account. Your host's support can confirm the IP to add if it isn't listed automatically.

Either way, to see the *actual* underlying error rather than the generic public JSON message, check your site's PHP error log after reproducing the failure (cPanel's **Errors** page, or `public_html/error_log`) - `includes/db.php` writes the real database error there (hostname, database name, and MySQL's own message) without ever exposing it publicly.

## Step 5 - Keep the data fresh: no-cron mode, or cron jobs

There are two ways to keep the database updated. You only need one.

### Option A - No cron job needed (default, already on)

`includes/config.php.example` ships with `refresh.on_demand` set to `true`. With this on, every time someone loads the site, the backend checks whether any data source is overdue (Elexon: 15 min, Carbon Intensity: 30 min, EirGrid: 15 min, Open-Meteo weather: 60 min, NESO constraint costs: 1440 min/daily) and, if so, fetches and stores fresh data for **at most one** source before answering that request - lock-guarded so simultaneous visitors can't trigger duplicate fetches. If nothing's overdue, it's a no-op and adds no delay.

This means you can stop after Step 4 - no cron setup required, and every page on the site (including the History page's weather card and the Renewables page's constraint-costs panel) works without it. Trade-offs to know about:

- The very first visitor after data goes stale pays a small extra delay (typically under a second) while that one source refreshes.
- NESO (embedded solar/wind - note: a *different* NESO dataset from the constraint-costs one above, which IS on-demand by default) is deliberately left out of the on-demand schedule by default - it's the slowest source to fetch (several sequential API calls). It'll simply stay on whatever data it last had unless you either add `'NESO' => 60` (or similar) to `intervals_minutes` in `includes/config.php`, or set up the NESO cron job from Option B below.
- EIA (USA) is also left out by default - both because most installs won't have set `eia_api_key`, and because EIA's own data only updates about once a day, so there's nothing to gain from checking on every page load anyway. Once you've filled in `eia_api_key`, add `'EIA' => 240` (or similar - every few hours is plenty) to `intervals_minutes` if you want it refreshed without cron, or set up the EIA cron job from Option B below.
- If your site gets very little traffic, data will only refresh when someone actually visits - fine for a personal dashboard, less ideal if you want it fresh even with zero visitors.

If this is enough for you, skip to **Step 6 - Verify it's working**.

### Option B - Cron jobs (optional; more consistent freshness, zero page-load delay)

If your host gives you cron access and you'd rather have data refresh on a fixed schedule regardless of traffic, set up the cron jobs below. You can use this *alongside* Option A (leave `on_demand` set to `true`) - cron just means on-demand rarely finds anything overdue to do.

In cPanel, open **Cron Jobs**. Add four jobs (or eight, if you also want the optional USA page, weather card, constraints panel and notable-moments section live):

| Schedule | Command |
|---|---|
| Every 15 minutes | `php /home/YOURUSERNAME/public_html/cron/fetch_elexon.php` |
| Every 30 minutes | `php /home/YOURUSERNAME/public_html/cron/fetch_carbon_intensity.php` |
| Every 30 minutes | `php /home/YOURUSERNAME/public_html/cron/fetch_neso.php` |
| Every 15 minutes | `php /home/YOURUSERNAME/public_html/cron/fetch_eirgrid.php` |
| Every 2-4 hours (optional) | `php /home/YOURUSERNAME/public_html/cron/fetch_eia.php` |
| Hourly (optional) | `php /home/YOURUSERNAME/public_html/cron/fetch_weather.php` |
| Daily (optional) | `php /home/YOURUSERNAME/public_html/cron/fetch_constraints.php` |
| Daily (optional) | `php /home/YOURUSERNAME/public_html/cron/fetch_notable_moments.php` |

Notes:

- Replace `/home/YOURUSERNAME/public_html/` with your actual path - cPanel's Cron Jobs page usually shows it, or check File Manager's address bar.
- If `php` alone doesn't work, your host likely needs a versioned path instead, e.g. `/usr/local/bin/php` or `/opt/cpanel/ea-php82/root/usr/bin/php` - check under **Select PHP Version** in cPanel, or ask your host's support for "the CLI PHP path". PHP 7.4 or newer works; PHP 8.1+ preferred.
- cPanel's "Common Settings" dropdown has "Every 15 Minutes" / "Twice an Hour" presets, or use `*/15 * * * *` / `*/30 * * * *` directly.
- **If your host can only trigger cron via a URL** (rare on cPanel, more common on some budget hosts): set `cron_http_secret` in `includes/config.php` to a long random string, then use a URL-fetching cron command instead, e.g. `wget -q -O /dev/null "https://yoursite.example/cron/fetch_elexon.php?secret=YOUR-SECRET"`. Never leave `cron_http_secret` blank if you do this - a blank secret means the script refuses all web requests, which is the safe default.
- `fetch_weather.php` and `fetch_constraints.php` are both genuinely optional - like every other source, they also work with zero cron access via Option A's no-cron mode below (they're included in `intervals_minutes` by default), so skipping these two cron jobs doesn't leave anything broken; it just means those two panels populate on the next page visit that triggers a refresh instead of on a fixed schedule. NESO only updates the constraints dataset weekly, so a daily cron run (or the on-demand default) is already more than enough.

You don't need to register for anything or generate an API key for Elexon, NESO, the Carbon Intensity API, EirGrid's Smart Grid Dashboard, or Open-Meteo - all five are public and keyless (confirmed while building this). EirGrid's Open Data Licence does require the attribution "Supported by EirGrid Group Data" wherever its data is shown - already included in `pages/ireland.html`'s footer, so there's nothing extra to do unless you customise that page. The EIA is the one source that does need a (free) key - see "About the data sources" below - `fetch_eia.php` runs and logs `OK, 0 rows` without erroring if you skip it, so leaving it out doesn't break anything.

## Step 6 - Verify it's working

Give the cron jobs 15–30 minutes to run at least once, then check either of these:

- Visit `https://yoursite.example/api/current.php`, `https://yoursite.example/api/ireland_current.php`, and (if you set `eia_api_key`) `https://yoursite.example/api/usa_current.php` directly in a browser. You should see JSON with `"live": true` and real numbers. `"live": false` means no data has landed yet for that page - check cron ran (see below).
- In phpMyAdmin, browse the `ingest_log` table. You should see rows with `status = OK` from each of the four core sources (`ELEXON`, `CARBON_INTENSITY`, `NESO`, `EIRGRID`), plus `EIA` if you configured it (an `EIA` row saying `OK, 0 rows, eia_api_key not set` is expected and fine if you didn't). A row with `status = ERROR` has a `message` column explaining what went wrong.

Once `api/current.php` shows live data, reload the homepage - the status strip (Time/Price/Emissions/Demand/Generation/Transfers) and the History charts will automatically switch from illustrative preview data to real data. Once `api/ireland_current.php` shows live data, the Ireland page's Emissions/Demand/Generation/GB interconnection stats and sparklines do the same (SEM price and the generation-mix breakdown stay illustrative regardless - see `pages/data-sources.html` for why). Once `api/usa_current.php` shows live data, the USA page's Demand/Generation/Total interchange stats and sparklines do the same (price, emissions and the generation mix stay illustrative regardless). There's no separate "go live" switch; it happens the moment there's real data to show.

### If the footer pill stays on "Data pipeline: no data yet" (api/status.php stays "pending")

This means `ingest_log` has zero rows for every source - nothing has ever run, or every attempt is failing before it gets as far as logging anything to the database. Check `includes/refresh.log` (plain text, view it via FTP/File Manager - it's blocked from direct web access by `includes/.htaccess` like `config.php` is). It logs every on-demand refresh attempt independently of the database, so it still records what happened even when `ingest_log` itself never gets a row:

- **No file at all, even after several page loads:** `refresh.on_demand` isn't being read as `true` from the live `config.php` - double check it's actually set there (not just in `config.php.example`), and that you edited the right copy (if `<one level above public_html>/uk-grid-config/config.php` exists, that one wins over `includes/config.php` - see the comment near the top of `config.php.example`).
- **Lines saying "nothing overdue... no-op" forever:** something's wrong with how "last ran" is being computed - shouldn't happen on a fresh install (an empty `ingest_log` should make every source look infinitely overdue), so this would be worth reporting/investigating further if you see it.
- **Lines saying "another request already holds the refresh lock" every time:** a stuck `GET_LOCK` - unusual, since locks are per-connection and MySQL releases them when a request ends, but if your host uses connection pooling/proxying in front of MySQL this can behave unexpectedly. Restarting PHP (or waiting - locks can't survive a dropped connection) should clear it.
- **A line with "threw" or "crashed" and an exception detail:** the actual error is right there (exception class, message, file, line) - e.g. an HTTP timeout reaching the API (some hosts restrict outbound connections), or a database error on the `INSERT`. This is now also written to the `ingest_log` table itself when possible, with `status = ERROR` and the same detail in `message`.
- **Lines saying a source's ingest function "returned without throwing" but `ingest_log` still shows nothing for it:** the `INSERT INTO ingest_log` itself is failing - check the DB user has `INSERT` privileges on it, and that the table exists (Step 2). `refresh.log` will have a `WARNING: writing the ingest_log row above to the database failed` line right after, with the reason.

### If `fetch_neso.php` logs an ERROR

This is the one script that couldn't be fully verified against a live response while building this (see the long comment at the top of `cron/fetch_neso.php` for why - short version: NESO's Data Portal is a catalogue whose underlying dataset/columns can change, so the script looks them up automatically rather than assuming fixed names, and that auto-detection can occasionally need a nudge). If it errors:

1. Check `ingest_log` for the NESO row's `message` - it lists the actual column names it found.
2. Visit `https://www.neso.energy/data-portal/embedded-wind-and-solar-forecasts` to confirm the dataset's current column names.
3. Edit the `$dateField`/`$periodField`/`$solarField`/`$windField` pattern-matching near the top of `cron/fetch_neso.php` if needed.

This doesn't break the rest of the site: `fetch_carbon_intensity.php` (confirmed working) also pulls a full generation-mix percentage breakdown that includes solar, so the dashboard still has a working embedded-solar figure either way - it's only the embedded-wind split specifically that depends on `fetch_neso.php` succeeding.

## What's live-wired vs. still illustrative

The **homepage's status strip and History charts** (Demand/Generation/Price/Emissions/Transfers, all 8 time ranges) are fully wired to the real backend, with automatic fallback to illustrative data if the backend has nothing yet. The **Ireland page's status strip** (Emissions/Demand/Generation/GB interconnection) and its 3-hour sparklines are wired the same way, via `api/ireland_current.php`/`api/ireland_series.php`. The **USA page's Demand/Generation/Total interchange stats and 3-hour sparklines** are wired the same way too, via `api/usa_current.php`/`api/usa_series.php` - but only if you've filled in `eia_api_key`; skip that and this part of the page falls back to illustrative figures just like the rest of it.

**Still illustrative for now:** the "Generation mix right now" table and donut charts (both the homepage's and the Ireland page's), the battery charge/discharge toggle (on the homepage and the Storage page), the Records panels, the Ireland page's SEM price stat and 24-hour generation-mix stack, and the other detail pages (Fossil fuels, Renewables, Storage, etc.). These were left on illustrative data deliberately rather than rushed - see `pages/data-sources.html` for exactly which sources back each one and why the rest aren't wired yet (mainly: no confirmed public source at that granularity, or a source that isn't keyless).

To extend real-data wiring to more of the site, the pattern already used on the homepage is:

```js
const current = await window.GridData.fetchCurrent();        // latest snapshot, or null
const series  = await window.GridData.fetchSeries(metric, range); // {labels, values, unit}, or null
```

Both resolve to `null` if there's no live data, so every call site should keep its existing mock-data fallback (exactly as `index.html` does now) rather than assuming success. `assets/data.js` also exports `GridData.summarizeGeneration(generation)`, which turns the raw `{fuelCode: MW}` object from `current.php` into renewable/non-renewable/interconnector/storage totals and a by-source breakdown with display labels already applied - that's the piece to reuse for the mix table and donut chart specifically.

## About the data sources

- **Elexon Insights Solution** - public, no key, no rate limit stated beyond reasonable use. Confirmed working live while building this (`data.elexon.co.uk/bmrs/api/v1`).
- **Carbon Intensity API** - public, no key (`api.carbonintensity.org.uk`). Confirmed working live.
- **NESO Data Portal** - public, no key, CKAN-based (`api.neso.energy`). NESO's own guidance asks for at most 1 request/second to the general API and 2 requests/minute to the Datastore API - the recommended every-30-minutes cron schedule is comfortably under that.
- **EirGrid's Smart Grid Dashboard** - public, no key (`smartgriddashboard.com`). Powers the Ireland page's demand/generation/wind/GB interconnection/emissions figures. Its Open Data Licence requires the attribution "Supported by EirGrid Group Data" wherever this data is shown - already in `pages/ireland.html`'s footer.
- **EIA API** (`api.eia.gov`) - optional, powers the USA page's demand/generation/total interchange stats. The only source here that needs a key: register free at <https://www.eia.gov/opendata/register.php> (instant, email-based) and paste the key into `eia_api_key` in `includes/config.php`. Its own Hourly Electric Grid Monitor (EIA-930) data typically lags about a day behind real time - a real limitation of the source, not a bug here; `pages/usa.html` says so honestly rather than presenting it as real-time.

The attribution text required by Elexon's licence is already in the footer of every page and on the Data sources page - nothing further to do there, just don't remove it.

## Security notes

- `includes/.htaccess` blocks direct web access to the `includes/` folder. This is defence-in-depth - PHP files execute rather than serve as source anyway - but costs nothing to keep.
- Consider turning on **AutoSSL** (cPanel > SSL/TLS Status) if the site isn't on HTTPS already; it's free and usually one click.
- `cron_http_secret`, if you use it, should be a long random string (e.g. 32+ characters) - treat it like a password.

## Ongoing maintenance

- Nothing needs regular manual attention if the cron jobs keep running - check `ingest_log` occasionally (e.g. monthly) for a run of `ERROR` rows, which would mean one of the three APIs changed shape upstream.
- Elexon occasionally adds new interconnector fuel-type codes when new cables open (this happened with Greenlink in 2024) - `cron/fetch_elexon.php` stores anything it's given under its raw code either way, so a brand-new code won't be lost, it just won't have a friendly label in `assets/data.js`'s `INTERCONNECTOR_LABELS` until you add one.
