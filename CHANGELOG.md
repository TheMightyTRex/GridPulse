# Changelog

All notable changes to this project are documented here. Dates are when the change was made, not necessarily when it was deployed. Where useful, entries reference the deployment zip version they came from (e.g. v57, v59).

## GridPulse11 - 5 November 2026

- Added the Impact tracking script (Dave's code) to the head of every page, straight after Matomo. It loads `utt.impactcdn.com` and runs `transformLinks` and `trackImpression`. tools/apply_shell.py writes it, so it stays on every page when the shell is rebuilt.
- Cache-busting version bumped to 20261105a.

## GridPulse10 - 1 November 2026

- **Donuts and bar charts are solid colour.** The donuts (Replay today, and the donut in the downloaded snapshot) and every bar chart (the replay bars, the merit order, sun hours by month, the country comparisons) now fill each slice or bar with one flat colour instead of fading. Line charts, the last 24 hours stacked chart and the sparklines keep their fade.
- The "Live" pill now reads "Live, updated at: <time>" on Great Britain and every country page.
- Cache-busting version bumped to 20261101r.

## GridPulse9 - 1 November 2026

- **Border maps show every link roughly where it really runs.** On the Borders card of every country and on each Borders page, each interconnector is now drawn at its real place: subsea cables between their two landing points (for example IFA from Folkestone to Calais, Viking Link from Lincolnshire to Jutland, NordLink from Norway to Schleswig-Holstein), and land borders at their main crossing. Before, one line ran from the middle of the country to the middle of each neighbour.
- Great Britain's map shows each cable separately (IFA, IFA2 and ElecLink to France, Moyle, East-West and Greenlink to Ireland), with its own direction and thickness from Elexon's per-cable readings. The flows API now returns `cable_latest_mw` for this.
- Other countries publish one total per border, so where a border has two links (France-Spain, Spain-Portugal), both lines show the border total. The Borders page says so under the map.
- Selecting or focusing a line names the link and its current flow (for example "Viking Link, Denmark: in 1.2 GW").
- Fix: panels that a page builds after the data arrives (the map and table on the Borders pages, and similar) could stay invisible until a reload, because the fade-in only watched panels that were there at the start. It now watches for new panels too.
- Fix: at 175% text on a phone, the long "Interconnectors (net import)" label in the mix legend pushed the page sideways. It now wraps.
- Positions are approximate, to within a few kilometres, from the operators' and project pages for each link. They are not exact cable routes.
- Cache-busting version bumped to 20261101o.

## GridPulse8 - 1 November 2026

- "Loading live data…" inside the black "Live, updated" pill was grey on black in light mode (and grey on white in dark mode). Loading text inside dark pills and cards now uses the pill's own text colour.
- The new sections say "Loading…" with a proper ellipsis, the same as the rest of the site.
- **Ireland's replay no longer drops to wind only in the evening.** EirGrid publishes wind before total generation, so the latest half hours had wind and nothing else, which also made "right now" look like 100% renewable. Those half hours are now left out until EirGrid's total arrives. More generally, for every country, half-published readings at the end of the day (fewer than half the usual fuel types, or a sudden drop because some types haven't arrived yet) are left out of "right now", the replay, Today so far and the day's shares until the rest arrive.
- Cache-busting version bumped to 20261101l.

## GridPulse7 - 1 November 2026 (speed, accessibility and Matomo)

**Speed**
- **The API answers first, then refreshes.** When a data source was overdue, the visitor who happened to load the page used to wait while the site fetched it (up to about 5 seconds on the France page). Now the reply goes out straight away from what is already stored, and the refresh runs after it has been sent. The scheduler keeps the stored figures fresh anyway. To go back to the old way, add `'after_response' => false` to the `'refresh'` section of config.php.
- **Browsers keep the site's files.** The root `.htaccess` now tells browsers to keep CSS and JS for a year (their addresses change with every release, so updates still arrive), and images and the border maps for a week. Repeat visits no longer ask the server for every file. Pages themselves are never kept, so new versions always show.
- The border maps are loaded with the release version, so they can be kept but refresh after an update.
- Checked: the host already compresses files (gzip), so no change was needed there.

**Accessibility** (checked with axe in light, dark, high contrast light and dark, and 175% text with wider spacing, the low-vision font and underlined links on a phone; every focusable element shows a focus ring)
- The "How the records have climbed" chart has a "Show each step as a list" option, and no longer says "hover" (the content design system says "select", and hovering doesn't work on a keyboard or phone).
- The month-by-month chart reads out each month's share to screen readers.
- Wide tables (best of each year, the day archive lists) now scroll inside their own box, which can take keyboard focus, and no longer push the page sideways at large text sizes.
- Record values, interconnector rows and pop-up messages wrap instead of running off a narrow screen.

**Matomo**
- New tracking code on every page (all 49, including the error pages), pointing at `www.gridpulse.info/stat/`. The script is in `<head>`; the `<noscript>` image is straight after `<body>`, because an image isn't allowed in `<head>`. `tools/apply_shell.py` now writes both, so new pages get them too.
- Cache-busting version bumped to 20261101k.

## GridPulse6 - 1 November 2026

- **Both card columns now end level.** On wider screens the map in the Interconnectors / Borders card shrinks or grows (down to 240 px tall) so its card lines up with the bottom of This year so far. Where a column is shorter, its last card stretches to match.
- **Today so far chart:** the Peak, Low and Now labels are kept inside the chart, so none is cut off at the top or the sides.
- **Light mode by default.** The site now opens in light mode unless the Display panel says otherwise. "Auto" still follows the device's setting, and anyone who already chose Auto or Dark keeps their choice. A tiny script in each page's head sets the theme before the page draws, so there's no flash of dark.
- Cache-busting version bumped to 20261101h.

## GridPulse5 - 1 November 2026

- **The four cards flow without gaps.** They now sit in two columns that stack on their own: Today so far and the map on the left, Records and This year so far on the right (Today and This year when a country has no map). A short card no longer leaves white space beside a tall one. Below 860 px wide the columns merge and the cards stack in reading order: Today, Records, Borders, This year.
- "This year so far": the dashed line's label ("[last year] same dates") moved to the left, so it no longer sits on top of this month's column.
- Cache-busting version bumped to 20261101f.

## GridPulse4 - 1 November 2026

- The border and interconnector maps now scale with their card or page: full width, with the height set by the map's own shape. The big maps (Interconnectors page, Borders page) are capped at 80% of the window height so the whole map fits on screen.
- Checked load times on the live site: after the first visit, pages and API calls answer in well under a second. The first visit after a quiet spell can take about 10 seconds, which is the host waking up, not the database.
- Cache-busting version bumped to 20261101e.
- Removed `pages/argentina.html`, `pages/brazil.html`, `pages/chile.html` and `pages/colombia.html`. Nothing linked to them: they were merged into `pages/south-america.html` long ago and the old repo had already deleted them. When uploading, delete these four files from the server too.

## GridPulse3 - 1 November 2026 (dashboard layout for GB and every country)

The GB home page and every country page are now shorter dashboards, with the detail on shared pages. Designed with Dave on a mockup first. Nothing shows a made-up figure.

- **GB home page** went from 15 sections to 6: the hero and figures, the generation mix (with **Replay today** now inside it), four cards, the last 24 hours, and **Explore Great Britain** tiles. The tiles replace the big energy-type cards, the History link and the plug-in solar card, and still show live GW for each type.
- **Four cards** on every page (GB and countries):
  - **Today so far**: a demand curve with the low, peak and now marked, plus the day's figures.
  - **Records**: four records with icons, and a bar showing how close today came to each.
  - **Interconnectors / Borders**: a real map (home country white, neighbours grey, sea transparent) with animated amber and blue flow lines, thicker for more power, and the biggest flows listed. ENTSO-E pages say when the oldest border was last updated. Not shown for the USA, Ontario and Australia, which have no per-border flows.
  - **This year so far**: renewable share with the change on last year (same dates), this year's mix against last year's, a month-by-month renewable column chart, total generated, best month, net imports, and (where the yearly source has been checked) a mini yearly mix.
- **New page: Records** (`pages/records.html?area=XX`). "How the records have climbed" step chart (from 30 days after recording began), the records board with today's closeness bars, best of each year, records broken each month and the most recent breaks. For GB it also has the hand-checked all-time records, notable moments and the alerts, moved from the home page.
- **New page: Borders** (`pages/borders.html?area=XX`): a larger map and one card per neighbour.
- **Country pages** follow the same order: heading and intro, "X% renewable right now", figures, mix with Replay, the four cards, last 24 hours, **[country] and its neighbours right now** (European pages: renewable and zero-carbon shares side by side), history, long-term chart, Explore tiles, then the page's own About (open), Records and context, and data sources (folded).
- **GB History page** now has the long-term yearly chart (NESO historic generation mix).
- **Alerts** moved to `assets/alerts.js`. The settings are on the GB Records page; the home page still checks them while it is open.
- New APIs: `api/year.php` (this year so far and the same dates last year) and `api/now_all.php` (every area's latest shares). `api/records.php?all=1` adds every record break.
- Maps: `assets/maps/XX.json`, built from Natural Earth (public domain) by `tools/maps/build_maps.mjs`. Credited on the Sources page.
- `tools/full-refresh.php` no longer warns that BATTERY is missing from config.php: it now uses the same built-in defaults as page loads. `config.php.example` lists BATTERY and DAILY.
- Cache-busting version bumped to 20261101d.

## GridPulse2 - 1 November 2026 (country pages follow the Great Britain home page)

Every country page now follows the Great Britain home page's order, as far as each country's data allows. Nothing shows a made-up figure: anything missing says so.

- **"X% of [country]'s power is renewable right now"** at the top of each country page, with a live "updated" pill and a one-line summary (largest source, demand, price, net imports).
- **Copy as text, Download CSV and Download snapshot (PNG)** under each country's figures.
- **Replay today**: a slider under the mix that redraws the donut and bars at any half hour (or hour) so far today.
- **Today so far** card on every country and on the GB home page: peak, low and average demand with times, renewable and zero-carbon share, net imports or exports, share made at home, average price and largest source.
- **Records** on every country: a board grouped by type (each with its value, time and the record it beat), a "best of each year" grid shaded by closeness to the record with the record ringed, and a "broken most recently" list. All labelled "since this site started recording [country] on [date]". Whole-day records need 90% of the day. Breaks only count from 30 days after recording started.
- **Day archive** (`pages/archive.html?area=FR`): a calendar for each year, each day shaded by renewable share, plus a month-by-month list. **Day page** (`pages/day.html?area=FR&date=2026-10-25`): stacked generation, demand, price, carbon where available, net imports, summary, records set that day, previous and next day, and a CSV of the day. Clock-change days (23 and 25 hours) are handled.
- **Cross-border flow cards**: for GB one per country with the cables named, for Ireland the flow with GB, for each European country one per neighbour. Each shows the live flow and direction, today's energy in and out, a small in/out chart on a shared scale, and when the border was last updated.
- **Long-term history**: a yearly stacked chart by fuel (share or GWh), with the source credited and what it covers. The current year is labelled "so far". USA and Australia figures are stored but not shown until they have been checked against the source's own totals.
- **Copy link** on every chart toolbar. Range tabs now keep the range in the address (`?range=week`), so a copied link or a reload opens the same view.
- **CSV attribution**: every CSV now starts with source and licence lines. Australian data carries Open Electricity's CC BY-NC 4.0 note.
- Chart y-axis labels no longer get cut off on the left (for example "€110/MWh").
- New backend (written in the last chat, tested here against local test data): `includes/areas.php`, `includes/flows_annual.php`, `api/day.php`, `api/archive.php`, `api/records.php`, `api/flows.php`, `api/annual.php`, `api/areas.php` and `tools/data-jobs.php`. New jobs DAILY, FLOWS and ANNUAL (see README-DEPLOY.md). New tables `daily_summary`, `readings_flow`, `annual_mix` and `fetch_state` (created on first use, also in `sql/schema.sql`).
- **Outside formats checked on 1 November 2026**, through a browser because the build sandbox can't reach them:
  - Eurostat nrg_bal_peh: no `CF_R`, `RA110`/`RA120` or `RA400` codes, so bioenergy now uses `BIOE`. France's totals match Eurostat's own TOTAL row.
  - NESO historic generation mix: the portal refuses `EXTRACT()`, so the yearly query now uses `date_part()`. Columns confirmed. GENERATION includes imports, which are left out.
  - IESO yearly reports: file names confirmed (`PUB_GenOutputbyFuelHourly_<year>.xml`, 2015 onwards, plus the current year so far, which is now fetched too).
  - Not checked (they need the site's keys): EIA fuel type IDs, Open Electricity `interval=1y`, and whether ENTSO-E accepts the Italy control area for flows. Check these on `tools/data-jobs.php` after upload.
- `tools/apply_areas.py` (new) writes the shared sections into all 16 country pages and the GB home page between `<!-- area:... -->` markers, keeping each page's own intro, About, "Records & context" and sources. Run it after `tools/apply_shell.py`.
- `pages/data-sources.html`: Eurostat, NESO historic mix, EIA yearly, IESO yearly, Open Electricity yearly and ENTSO-E flows, each with licence and attribution.
- Cache-busting version bumped to 20261101c.

## GridPulse1 - 1 November 2026 (UK Grid: Live+ is now Grid Pulse)

- **New name and address.** The site is now Grid Pulse, at https://gridpulse.info. Page titles, descriptions, the header, the footer, the README, the docs, snapshot card titles and download file names (`gridpulse-...` instead of `ukgridlive-...`) all use the new name. The PHP `ukgrid_` function prefix and the `UKGRID_` secret names stay the same, so nothing breaks.
- **New logo ("pulse bars").** Six rounded bars with a tall yellow one and a green "live" node. Files: `assets/logo.svg`, `assets/gridpulse-favicon.svg`, `assets/gridpulse-favicon-48.png`, `assets/gridpulse-favicon-192.png`, `assets/gridpulse-apple-touch-icon-180.png`, `assets/gridpulse-icon-512.png`, and full logos in `assets/brand/`. On light backgrounds "PULSE" sits in a yellow box with dark text (`gridpulse-logo-on-light.svg`).
- **Header animation.** The bars rise left to right, then the green node pings. "Reduce motion" (and the system setting) turns it off.
- **Share cards.** `tools/apply_shell.py` now also writes a block into each page's `<head>`: the icons, a `<link rel="canonical">` with the gridpulse.info address, and Open Graph tags with a new 1200 x 630 share image (`assets/gridpulse-social.png`). Error pages get `noindex` instead.
- **"We've moved" notice.** ukgridlive.info forwards every address here with `?from=ukgridlive`. The page then shows a one-off notice ("UK Grid: Live+ is now Grid Pulse. Update your bookmarks."), and takes `from` out of the address.
- **About page** says Grid Pulse was formerly UK Grid: Live+, and keeps the credit to Kate Morley's National Grid: Live.
- **Matomo** now tracks at `gridpulse.info/stat/`. Move or copy the `stat` folder from the old web space before uploading.
- Outgoing requests now identify as `GridPulse/1.0 (+https://gridpulse.info)`. The scheduler's User-Agent is `gridpulse-scheduler`.
- Cache-busting version bumped to 20261101b.

## UKGridLive57 - 1 November 2026

- The first run after uploading only `cron/` stopped with "Call to undefined function ukgrid_http_set_deadline()", because the server still had the older `includes/http.php`, yet GitHub showed it as passed (HTTP 200). `cron/run-due.php` now checks that `includes/refresh.php`, `includes/battery.php` and `includes/http.php` are the matching version before it prints anything, and answers 500 naming any that are older.
- The GitHub workflow and the Docker script now only count a run as passed if the reply ends with "done in ...". A PHP error part-way through can't change the 200 status once output has started, so the workflow checks the reply instead.

## UKGridLive56 - 1 November 2026

- **Scheduled runs no longer overrun the host's 60-second limit.** The 14:31 GitHub run fetched NESO, IESO, Elexon, Open Electricity, Carbon Intensity and batteries (all saved), then started ENTSO-E 43 seconds in. Two slow ENTSO-E countries took it past about 60 seconds, and the host ended the request with its own "500 Internal Server Error" page. Now:
  - `cron/run-due.php` only starts a source if its rough worst case (requests x timeout) still fits in the budget (now 45 seconds, `refresh.run_due_budget_seconds`). Sources that don't fit are listed as "Left for next time" and run on the next call or a page load.
  - ENTSO-E runs first, while the whole budget is left, with a 12-second timeout (`refresh.run_due_entsoe_timeout_seconds`) instead of a page load's 6 seconds, since many of its requests were timing out at 6. Schedulers now fetch one country per call, like page loads.
  - `includes/http.php` has a hard stop for scheduled runs (`ukgrid_http_set_deadline()`): each request's timeout is cut to the time left, and no new request starts once under 2 seconds remain. Page loads are unaffected.

## UKGridLive55 - 1 November 2026

- **Error pages for 400 (bad request) and 401 (login needed)** in `errors/`, in the same style as the others, each with a "Go back" button (hidden when there is no page to go back to). `.htaccess` now lists them with `ErrorDocument` too.

## UKGridLive54 - 1 November 2026

- **Custom error pages** in a new `errors/` folder: 404 (page not found, with the address asked for), 403, 500 and 503. They use the normal site header, menus and Display panel, plus a short "Where to next" list. Links and assets start at the site root (`/assets/...`, `/pages/...`), so they work whatever address they are shown at. `tools/apply_shell.py` now writes the header into these pages too.
- **`.htaccess` in the site root** (new) points Apache at those pages with `ErrorDocument`. If the server already has a root `.htaccess`, copy its four lines in rather than replacing it.
- **`includes/error_page.php`** (new): PHP fatal errors usually reach the browser as an empty 500 ("HTTP ERROR 500"), which Apache does not replace. `tools/full-refresh.php` and `tools/battery-units.php` now call `ukgrid_html_errors()` first, so a fatal error shows `errors/500.html` instead, and the details go to `includes/refresh.log`. A config.php typo or database problem on those pages shows the same page with the plain explanation from `includes/db.php` (which now goes through one `ukgrid_config_fail()` helper: JSON for the API, HTML for tools pages). The full refresh page's `?ajax=1` calls stay JSON.
- `assets/app.js`: pages with `data-no-status` on `<body>` (the error pages) skip the data-pipeline check and its pop-up.
- Cache-busting version bumped to 20261101a.

## UKGridLive53 - 1 November 2026

- **`includes/db.php`**: a typo in `includes/config.php` no longer gives a bare 500 on every page. The pages, the API and `cron/run-due.php` now say which line of config.php is wrong and how a setting should look. Only the line number is shown; PHP's full message goes to the PHP error log, since it can quote part of the file.
- **`cron/_bootstrap.php`**: once the secret is accepted, a PHP fatal error during a scheduled run is printed in the reply (file and line) and written to `includes/refresh.log`, so the GitHub Actions log shows the cause instead of an empty 500. Output is flushed line by line.
- **`cron/run-due.php`**: prints "run-due: starting" first, and says plainly when `includes/refresh.php` or `includes/battery.php` on the server is an older version than `cron/`.

## 2026-11-01 (Scheduler: clearer failures, secret also sent as a form field)

- The first GitHub Actions run failed with curl exit code 22, which means the site answered with an error status. The log did not show which one. The workflow and the Docker script now print the HTTP status and the site's reply, plus a plain explanation: 403 means the secret is missing or does not match, 404 means run-due.php has not been uploaded or the address is wrong, and 5xx means a server error.
- The secret is now also sent as a POSTed `secret` field, which `cron/_bootstrap.php` accepts, in case the host does not pass the `X-Cron-Secret` header through to PHP. The 403 message now says whether `cron_http_secret` is unset or just does not match.

## 2026-11-01 (Scheduled updates without cron: GitHub Actions and Docker)

- **`cron/run-due.php`**: runs every source that is due, one after another, within a 45-second budget (`refresh.run_due_budget_seconds`). It uses the same "is it due?" record (`ingest_log`) and the same per-source MySQL locks as the page-load refresh. It also covers NESO, which page loads leave out, and uses bigger ENTSO-E and battery batches. It is protected by `cron_http_secret`, sent in an `X-Cron-Secret` header (`?secret=` still works).
- **`.github/workflows/refresh-data.yml`**: GitHub calls run-due every 30 minutes. That keeps a private repo inside the free Actions minutes; a public repo can use every 5 minutes. It needs repository secrets `UKGRID_SITE_URL` and `UKGRID_CRON_SECRET`.
- **`docker/`**: a tiny Alpine container that calls run-due every 5 minutes (`INTERVAL_SECONDS`), with a docker-compose file, `.env.example` and a README. `docker/.env` is git-ignored, and `docker/.htaccess` blocks web access if the folder is uploaded.
- **How they stay in sync:** whoever runs a source first records it, so the others find it not due. The locks stop two runs of one source at once, and every write is keyed on the reading's timestamp. Page-load refresh stays on as the fallback.
- `includes/refresh.php` refactored into `ukgrid_refresh_schedule()`, `ukgrid_refresh_overdue()` and `ukgrid_refresh_run_source()`, shared by page loads and run-due. Tested end to end against a local MariaDB with mocked APIs: the first call ran 13 sources, a second call straight after returned "Nothing due", and page-load refresh still runs an overdue source.

## 2026-10-29 (Batteries: real figures from Elexon)

- **New Batteries section on the Storage page** (and a one-line summary under the homepage mix). It adds up the battery units registered in the Balancing Mechanism:
  - **Notified:** Elexon Physical Notifications (PN). What each battery told NESO it plans to do, per half hour, about an hour ahead. Charging is negative. Shown as right now, charging and discharging separately, and the last 24 hours.
  - **Metered:** Elexon B1610 actual output per unit. Published about a week later (22 October was the newest on 29 October). Shows the latest 7 days published.
  - Both are labelled for what they are: plans versus measurements, Balancing Mechanism batteries only, and not added to the mix.
- **Why it is built this way** (checked 29 October 2026): Elexon's fuel-type data has no battery category, its fuel-type list has none, and B1610 labels every unit "Generation". NESO's historic mix puts "Batteries (NET discharge)" in OTHER and cuts negatives at zero.
- **Which units count:** Elexon does not flag batteries, so `ukgrid_battery_is_unit()` picks them from the BM unit register. A unit counts if its name or lead party says battery, BESS or energy storage, or if it is a T_/E_ unit whose ID ends "B-n" and that can import at least half what it exports. Interconnectors, supplier units, pumped storage and conventional fuel types are excluded. The list is cached daily in `includes/battery_units.json`. `tools/battery-units.php` (password-protected) shows every unit counted and why. Correct it in config.php with `'battery_units' => ['include' => [...], 'exclude' => [...]]`.
- New: `includes/battery.php`, `api/battery.php`, `cron/fetch_battery.php` (every 30 minutes recommended), table `readings_battery` (created automatically, also in `sql/schema.sql`), source `BATTERY` in the on-demand refresh (every 30 minutes, small batches; added even if config.php has its own intervals list) and in tools/full-refresh.php.
- Tested against mocked Elexon responses only: this build environment cannot reach Elexon. Live check: open tools/battery-units.php, then the Storage page.

## 2026-10-29 (Country pages refresh their own ENTSO-E data)

- Live, Ireland's mix showed only wind, gas and other, and GB interconnect. Solar was 0 at night, so it was left out. Hydro and peat were left out because Ireland's ENTSO-E readings were 16 hours old (05:30 UTC), beyond the 6-hour limit. Without cron, the shared on-demand refresh fetches one ENTSO-E country per run in rotation, so a country could go most of a day between fetches.
- `api/country_current.php` now fetches its own country first when that country's newest reading is more than 90 minutes old and it has not been tried in the last 20 minutes. It is lock-guarded, uses 6-second timeouts and is logged to refresh.log. This applies to Ireland and every ENTSO-E country page. Setting up `cron/fetch_entsoe.php` is still the better fix.

## 2026-10-29 (Plug-in solar check; electricity prices page moved to official sources)

- **Plug-in solar (8 pages): checked against the official sources on 29 October 2026. No rule changes since 3 October.** The DESNZ Interim Product Specification is still version 2.0 (July 2026). The GOV.UK page was last updated on 3 September 2026, adding Welsh translations only. SI 2026/848 and SI 2026/896 have no amendments. Nothing new was found from Ofgem on export payments, from NIE Networks or the NI Department for the Economy on Northern Ireland, or from the Welsh Government on planning. "Rules last checked" moved to 29 October 2026. Not re-checked this time: the ENA Type Test Register, Electricity North West's notification page, and gov.scot (it could not be searched).
- **Electricity prices page, every chart now from an official source:**
  - Merit order: was unsourced "typical" figures. Now DESNZ Electricity Generation Costs 2023, Table 10 (fuel, carbon and variable running costs, 2021 prices): solar £0, offshore wind £1, onshore wind £6, gas £105/MWh. Nuclear and biomass are left out because DESNZ does not publish them in that table.
  - Bill breakdown: was an "approximate" July to September donut. Now Ofgem's own Annex 1 breakdown for 1 October to 31 December 2026, as a bar chart because Ofgem's rounded shares add to slightly over 100%. The wholesale share in the text is updated from about 45% to 47%.
  - "Factcheck" section (Carbon Brief): replaced with "What has changed on bills since 2021", comparing Ofgem's October 2021 and October 2026 cap breakdowns. The "gas sets the price 98% of the time" chart is removed: no official source gives that figure.
  - Institute for Government and Wikipedia removed from the sources list.
- Chart values with a £ unit now read "£105/MWh" and "£690", not "105£/MWh".
- Added `pvgis/get-pvgis.ps1` outside the site (in the user's GitHub folder) to download PVGIS irradiance for the calculator, because PVGIS cannot be reached from the build environment. sunhours.app stays on the plug-in solar pages until that data is in.

## 2026-10-29 (Chart check: no made-up data anywhere; menu tab joins its panel)

- **Checked every chart on all 39 pages** three ways: with live data for every API, with every API returning an error, and with the network down. With data, every live chart draws. With no data, every live chart shows an empty state or a "not available" note. Nothing stays stuck on "Loading", and no page throws a JavaScript error. Only fixed, sourced charts (records, capacity and plan figures) draw without the API.
- **Removed every piece of made-up data:**
  - The battery charge/discharge "preview" on the homepage, History and Storage pages drew a generated wave and showed a fixed -0.18GW. History's version could even copy that as text. It is gone: Elexon's feed has no battery reading, and the Storage page's note already says so.
  - The homepage "Download snapshot" button used built-in example figures before live data loaded. It now says "No live figures to save yet" until real figures arrive.
  - Ireland's page drew a hard-coded example bar chart, donut and table (2.11GW wind and so on), and the bar chart could reappear under "no data". These are gone; the table starts on "Loading live data…".
  - The unused example sparkline functions and their made-up base values are removed from 13 pages, and `GridPreview.buildSeries()` (the generator behind all of it) is removed from `assets/app.js`.
- **Homepage hero:** with no live data, the summary card no longer stays on "Loading live data…".
- **Menus (desktop):** an open menu button now turns into a tab in the panel's colour and runs down over the black bar and the yellow line into the panel, so there is no gap between the button and its menu.

## 2026-10-29 (Ireland: mix combines EirGrid and ENTSO-E)

- Ireland's generation mix right now uses EirGrid's total generation, wind, solar and GB interconnection (every 15 minutes), plus ENTSO-E's other types for Ireland: hydro, peat, pumped storage and any others it publishes. Wind and solar come only from EirGrid. What is left of EirGrid's total is "Gas and other (calculated)" (or "Other (calculated)" if ENTSO-E ever publishes gas). ENTSO-E figures more than 6 hours older than EirGrid's are left out, and the caption says so. The caption gives both sources' times and notes that ENTSO-E still lists Edenderry under peat.
- The 24-hour stack keeps EirGrid's figures only, now labelled "Gas and other (calculated)".

## 2026-10-29 (Phone header stays on one line)

- On phones (560px and narrower) the logo, Display and Menu now always sit on one line, with a 10px gap above the yellow line. Before, with the phone's own large-text setting on, the buttons wrapped onto a second row, sat right on the yellow line and pushed the logo out of view in the sticky header. The header sizes here are in px so text size cannot break the layout. Buttons are smaller: 38px tall (36px under 380px wide), and Display is a square icon.
- Fixed two small sideways overflows at large text sizes: the mix legend values (150% text) and the homepage solar and records cards (175% text on a 320px screen).

## 2026-10-29 (Ireland: generation mix from EirGrid, plus EirGrid's 24-hour fuel mix)

- **Ireland's mix was wrong.** It came from ENTSO-E, which only publishes Ireland's wind, hydro and peat in near real time, with no gas. So it added up to about 2.7GW when EirGrid showed about 5.8GW, and wind came out at 93%. The mix right now and the 24-hour mix chart now come from EirGrid's Smart Grid Dashboard: wind, solar, net GB interconnection, and "gas and other sources", calculated as EirGrid's total generation minus wind and solar. Captions say it is calculated and that hydro and biomass sit in it.
- **New: Fuel mix over the last 24 hours**, under the mix chart. It shows EirGrid's own fuel mix (renewables, gas, net imports, other fossil fuels, coal) as a share of the 24-hour total.
- **EirGrid ingest:** adds `solaractual` (category `SOLAR`) and `fuelmix` (new table `readings_ie_fuelmix`, which the ingest creates itself if it is missing; it is also in `sql/schema.sql`). It makes 3 combined requests instead of 5 single ones, and asks again one at a time for any area a combined answer leaves out. The live log had shown 4 of 5 single requests failing in one run.
- **API:** `api/ireland_current.php` adds `solar_mw`, `mix` (all read at one timestamp) and `fuel_24h`. `api/ireland_series.php` adds `metric=solar`.
- **Ireland banner:** "having trouble" for ENTSO-E now only shows when ENTSO-E's last run included Ireland. Before, a failed Italy fetch made the Ireland page say its own figures might be out of date. ENTSO-E is now only used for the SEM day-ahead price on this page.
- Bar chart labels can now be up to 160px wide, so "Gas and other sources" is no longer cut off.

## 2026-10-29 (Country pages: "Data as of" pill on the Switch country line)

- On the 16 country pages (Ireland, the ENTSO-E countries, Europe overview, USA, Canada and Australia), "Data as of" is no longer a figure card. It is a pill like the homepage's, on the left of the Switch country line and centred on it. On phones it sits just above the switcher. "On this page" now takes its own line above.
- The figure grids lose that card, so they use 3 columns (`status-grid--3`) or 5 columns (`status-grid--5`) on wide screens instead of leaving an empty slot. Ireland keeps 6.

## 2026-10-29 (Ireland feed moved to EirGrid's new dashboard API; mix chart tweaks)

- **Ireland live data fixed.** EirGrid has replaced its Smart Grid Dashboard, and the old `DashboardService.svc/data` feed now returns HTTP 503 to every request, so the Ireland ingest wrote 0 rows. `ukgrid_ingest_eirgrid()` now reads the new dashboard's chart API (`https://www.smartgriddashboard.com/api/chart/?region=ALL&chartType=default&dateRange=day&dateFrom=...&dateTo=...&areas=...`), checked live on 29 October 2026. It sends whole-day dates and keeps only the right field for each area (`SYSTEM_DEMAND`, `GEN_EXP`, `WIND_ACTUAL`, `INTER_NET`, `CO2_INTENSITY`). Before, the interconnection area's per-cable rows could overwrite the net figure. An old `DashboardService.svc` value for `eirgrid_base` in `includes/config.php` is ignored, so no config change is needed. `ukgrid_http_get_json()` takes optional extra headers.
- **Mix donut:** the centre total is smaller and scales with the ring, so it no longer touches the inner ring. The inner ring sits closer to the outer ring (r 84 to 90) and now uses the same colours as the bottom bar: each group's tint, edged in its colour.
- **Donut legend:** the coloured line above Renewable, Non-renewable, Storage and Net imports shows again. A more specific rule had been removing it.
- **Download chart snapshot (PNG)** on every generation mix section. It saves one card with the donut, its legend and both bars, in the current theme.
- **Announcement banner** folds to a thin yellow line under the menu once the page scrolls, and opens again at the top of the page or when its link has focus. It does not animate under Reduce motion.

## 2026-10-29 (Design C fixes: header fit, figure cards, Reduce motion)

- **Header no longer breaks onto two lines.** With the real Archivo font, the full menu plus Display did not fit at some widths (around 1300px) and Display dropped to a second row. The header now measures itself: if the menu does not fit on one line, Display shows as an icon; if it still does not fit (a narrower window, or 125% text and above), it switches to the Menu button and the one-section-at-a-time menu, shown as a panel at the right on wide screens. Rechecked on resize, text-size changes and when the font loads.
- **Figure values fit their cards.** Values like £223.75/MWh overflowed. Each value now scales with its card's width, and the unit drops to its own line if it has to.
- **Reduce motion shows everything, still.** It used to switch animations off entirely, which left anything that normally fades in (every section panel, including the mix donut) invisible. Animations now jump straight to their finished state instead, so the page looks the same, with no movement.

## 2026-10-29 (Redesign: design C across the whole site)

The site has a new look and layout on every page, built from the redesign proposal (design C, "poster"). All live data, charts and tools work as before.

- **Header and menus (all 39 pages):** a dark header with a new animated logo. The ring draws in, the bolt pops and the live dot pulses three times; all of it stops with Reduce motion or the device's own reduced-motion setting. On a computer, each menu opens as a panel grouped by topic, with a short line under each Great Britain page. On a phone, a labelled Menu button opens one section at a time, so the menu fits on the screen. The header is written by `tools/apply_shell.py` between `<!-- shell:start -->` and `<!-- shell:end -->`: change the menu there and run the script, rather than editing 39 pages.
- **Display and accessibility panel (Display button, every page):** theme (Auto, Light, Dark), text size 100% to 175%, low-vision font (Atkinson Hyperlegible Next), high contrast, reduce motion, underline links (on by default) and wider text spacing. Saved in the browser only. Replaces the old theme toggle; an existing Light or Dark choice carries over.
- **Homepage:** a live pill with the update time, the renewable share as the headline, a one-sentence summary card, five tinted figure cards with their 3-hour trend lines lined up, and Copy as text, Download CSV and Download snapshot (PNG) for the figures. Then the mix chart, a plug-in solar card and a records card.
- **Generation mix chart (every page with one):** donut first, with a Bar switch (the choice is remembered). The outer ring or top bar is each source; the inner ring or bottom bar is its group (renewable, non-renewable, storage or net imports), lined up with the sources in it. Pointing at, tabbing to or selecting any part highlights it with its group and reads out the figures. Built in `renderMixBreakdown()`, so the chart, table, copy and CSV always agree. The method note moves into "How we measure this", and the table sits behind a Show as table button. The GB homepage's second donut and ranked bar chart are replaced by it.
- **Page template:** every page gets a breadcrumb and an "On this page" list of its sections. Country pages get a Switch country menu (with a Go button) and a Compare with Great Britain link.
- **Words, to the content design system:** contractions written out in full, times as 12.35pm, "select" instead of "hover", no positional words in error messages, and new empty and error states ("Figures appear here when the next reading arrives.", "The live figures did not load. Try again in a few minutes.", "We do not have this much history yet. Choose a shorter range."). The banner reads "New: Read the plug-in solar guide". `tools/words_pass.py` holds the pattern rules.
- **Fonts:** Archivo replaces Open Sans. New stylesheet `assets/design-c.css`, loaded after `style.css`.
- **Checks:** axe-core WCAG 2.2 AA scan of all 39 pages in light and dark, with no violations. No sideways scrolling at 175% text on a 390px phone. No script errors on any page on desktop and phone.

## 2026-10-28 (Battery storage figures updated from DESNZ, NESO and RenewableUK)

- **Storage page, Records:** added the official DESNZ figures (7.5GW of UK grid-scale batteries at the end of 2025; a record 2.3GW connected in 2025; GB battery output of 2.3TWh in 2025, up from 0.8TWh in 2023; average efficiency 85% for batteries and 81% for pumped storage). Also added RenewableUK's latest industry count (8.7GW / 14.1GWh operational by 12 October 2026; about 9.1GW / 19GWh under construction) and NESO's long-duration storage figure (2.8GW / 23.9GWh, June 2026, via the DESNZ Clean flexibility roadmap). Caption renamed "The storage facts"; battery figures last checked 28 October 2026.
- **Storage page, About and Methodology:** short paragraph on the battery fleet and the 2030 target, plus a note on why DESNZ, Modo/NESO and RenewableUK figures differ (DESNZ scales up BM-registered batteries by about 1.3x; RenewableUK counts projects and models some sizes). New sources listed.
- **Renewables plans page:** the "Storage" target card is now "Battery storage". The old chart compared a battery-only "today" figure with a combined battery + long-duration target; it now shows the plan's 4.5GW baseline, 7.5GW at the end of 2025 (DESNZ) and the 23-27GW 2030 target (Clean Power 2030 Action Plan, Table 1). Long-duration storage (2.9GW to 4-6GW) is mentioned separately in the caption.
- Sources: primary publications only (DESNZ, NESO via DESNZ, RenewableUK EnergyPulse). The press article that prompted this (electricalnews.co.uk) is not cited.

## 2026-10-03 (IESO: fetch only the end of the year-to-date file)

- **IESO step on the full refresh failed with "Unexpected token '<'".** With the longer 25s timeout, the IESO step (two downloads) ran past the web host's own request time limit, and the host returned an HTML error page instead of the tool's JSON.
- **Fix:** IESO's `GenOutputbyFuelHourly` is a year-to-date file (~6MB by October, ~22KB a day), but only the last few days are ever needed. `ukgrid_ingest_ieso()` now asks for just the last ~250KB (an HTTP Range request, about 10 days) and rebuilds a small valid document from the complete days in it. If the server ignores Range it gets the whole file and parses it as before. The timeout is back to a minimum of 12s.
- `ukgrid_http_get_raw()` takes optional extra headers (used for the Range header; compression is switched off for range requests).
- `tools/full-refresh.php`: when the server returns its own error page instead of a result, the card now says so plainly (HTTP status, page title, "probably ran past the host's time limit - re-running usually works") rather than a raw JSON parse error.

## 2026-10-03 (IESO timeout on the big generation-by-fuel file)

- Full refresh results: every ENTSO-E country now succeeds, including Italy's price.
- **Canada (IESO):** `GenOutputbyFuelHourly` timed out at 8s with 4.4MB of 6.2MB received. It's IESO's year-to-date file and keeps growing through the year. Fixes:
  - that one request now gets at least 25 seconds;
  - every HTTP request (`includes/http.php`) now asks for gzip/deflate compression and curl unpacks it, so large XML and JSON downloads are much smaller wherever the server supports it.
- No change needed for EirGrid's "HTTP 503 Service Unavailable" (their Smart Grid Dashboard was down at the time; the retry still wrote 118 rows) or Ireland's SEM price "No matching data found" from ENTSO-E (a gap in ENTSO-E's SEM price data for that window - the Ireland page shows "-" for the day-ahead price when it's missing).

## 2026-10-03 (Italy day-ahead price)

- **Italy's price fetch always failed:** ENTSO-E answered "No matching data found for ENERGY_PRICES" because the configured area, `10YIT-GRTN-----B`, is Terna's whole-country control area. ENTSO-E has generation and load for that area, but day-ahead prices only per price zone (Italy has several).
- `includes/ingest.php` now supports an optional `price_domain` per country in `entsoe_countries`. Italy defaults to the North zone (IT-North, `10Y1001A1001A73I`, the largest by demand) even without it, so the live config.php doesn't need editing. `config.php.example` shows the setting.
- The Italy page now labels the price "Day-ahead price (North zone)" and explains why in its caption and data sources note.

## 2026-10-03 (Full refresh covers every ENTSO-E country by name; clearer chart loading state)

### tools/full-refresh.php

- **Every European country now gets its own step and card, in order:** Ireland (SEM), France, Netherlands, Belgium, Norway, Denmark, Germany, Spain, Italy, Sweden, Portugal, Poland - following `entsoe_countries` in config.php. Before, it made one ENTSO-E call per country but let each call pick "the most out-of-date" one. With the future-price bug, that could fetch the same country twice and miss others, and the results were lumped into a single ENTSOE card that didn't say which country was which.
- `ukgrid_ingest_entsoe()` has a new `$onlyCountry` argument for this, and every ENTSO-E log line now names the country (or countries) it covered.
- **Weather, grid constraints and notable moments added** to the full refresh. Before, only on-demand refresh or cron updated them. Notable moments runs last, so it's built from the freshly fetched data.
- **API keys are no longer written into error logs.** A failed request used to log its full URL, including ENTSO-E's `securityToken` and similar key parameters, to `includes/refresh.log` and the full-refresh page. These are now masked (`ukgrid_redact_url()` in includes/http.php).

### Loading state on every chart

- The loading skeleton was nearly invisible inside chart cards: it was the same colour as the card. Now every chart that's waiting for data shows a visible grey block with a moving sheen, plus a spinner ring and "Loading…" in the middle (sheen only in the small headline sparklines). Reported on the Storage page, which looked broken while it loaded.
- Added the loading state where it was missing: the sparklines in every headline stat card (17 pages), and the EU page's mix donut and bar chart.
- **Storage:** the two headline stats said "No data available" straight away, before the live fetch had even finished. They now pulse "Loading live data…" until it settles, and the pumped hydro row shows "…" rather than "-" while loading.
- EU page: the mix donut and bar now show "No data available" if no country has live data, rather than staying on the loading state.
- Asset version bumped to `?v=20261003c` (style.css changed).

## 2026-10-03 (Spain/Portugal history gaps, signed charts with a zero line, accessibility sweep)

### ENTSO-E countries: History showing "Not enough recorded history yet" (Spain especially)

Live API check: Spain's newest generation mix was 2 days old and its newest demand reading over 20 hours old (Portugal the same), so every Spain History range under a day was empty. France, Germany and Italy had readings only for a few hours of today.

- **Cause:** the on-demand refresh fetches one ENTSO-E country per run, choosing whichever has the oldest data. It judged "how fresh" using day-ahead prices too, which are published for the whole of tomorrow. So any country whose price fetch worked looked fresh until the end of the next day, was skipped and sent to the back of the queue, and its demand and generation weren't fetched for a day or more.
- **Fix (`includes/ingest.php`):**
  - Freshness now uses actual generation/demand readings up to now, not future prices.
  - Countries are taken in true round-robin order: least recently *attempted* first, stored in `includes/entsoe_attempts.json`, which is git-ignored and created automatically. A country whose requests keep failing can't hog every run.
  - When a country's latest reading is older than the usual 6-hour window, the fetch reaches back to just before it (up to 24 hours), so gaps get filled rather than left as holes.
- On-demand refresh still only runs when someone visits. For every country to stay complete, run `cron/fetch_entsoe.php` hourly if your host allows cron.

### Charts with negative values: zero line and above/below colours

- `renderLineChart` (assets/app.js) has a new `signed` option. It uses the same style as the interconnectors charts:
  - amber area above zero, blue below;
  - a zero line that's always drawn;
  - round y-axis ticks that include 0;
  - a key above the chart ("Above the line: …" / "Below the line: …");
  - tooltips that name the direction.
  
  The PNG snapshot legend includes the key too. Any other line chart whose data crosses zero now gets a zero line as well.
- Applied to:
  - storage output and share (Generating / Pumping or charging);
  - the battery previews on the homepage, Storage and History pages (Discharging / Charging);
  - History "Transfers" and Ireland "GB interconnect" (Importing / Exporting);
  - USA total interchange (neutral "Positive / Negative interchange" labels, as the site hasn't confirmed EIA's sign convention);
  - every price chart: History, Price history, Comparisons, Ireland (both SEM prices), Australia, the EU page and the 11 European country pages. Price charts keep their normal scale and only switch to the above/below style when the price actually goes negative.

### Accessibility sweep (axe-core, all 39 pages, light and dark)

- **Interconnectors "Net flow over time":** the mini charts' axis text was black in dark mode (1.3:1). Now uses the theme text colours.
- **Contrast fixes:**
  - footer data-pipeline pill (2.3-3.1:1, now 4.5:1+ via the `--band-*` colours on an elevated background);
  - battery-toggle notes (`.toggle-row em`, faded with opacity);
  - failed sparkline messages (faded);
  - the Plug-in Solar calculator's selected tab and the Considerations efficiency badges (solar yellow on white, ~2:1; new `--solar-text` token);
  - the Mounting page's Northern Ireland card heading in dark mode.
- **Valid definition lists:** the stat cards' band and sparkline were extra elements inside the `<dl>`'s groups (16 pages). They now sit in a second `<dd class="stat__extra">` with `display: contents`, so the layout is unchanged.
- **Landmarks:** the top banner is now an `<aside aria-label="Site announcement">`, and the skip link comes first on every page.
- Asset version bumped to `?v=20261003b`.

## 2026-10-03 (Country pages: a real loading state instead of illustrative figures)

- **Belgium, Denmark, France, Germany, Italy, Netherlands, Norway, Poland, Portugal, Spain, Sweden:** a first-time visitor no longer sees made-up "illustrative" numbers (headline figures, mix table, donut and bar chart) and an "Illustrative figures - not yet connected to a live source" banner while the page loads. Instead:
  - a grey "Loading live data…" banner with a pulsing dot;
  - pulsing dashes in the headline figures;
  - "Loading live data…" in the mix table and captions;
  - shimmers on the donut and bar chart.

  These change to the live, cached ("refreshing…") or "No data available" state as soon as the fetch settles. The illustrative first-paint donut/bar code has been removed.
- **Australia, Canada, USA, EU:** same grey loading banner (they said "Connecting to live data…" in the amber "illustrative" style) and pulsing dashes in the headline figures.
- `assets/app.js`: headline figures that a page has no value for now settle to "-" as soon as the page's "Data as of" tile or status banner stops loading, rather than pulsing until the 30-second safety net. New `.data-status-banner.is-loading` style in `assets/style.css`.

## 2026-10-03 (Loading states checked across the whole site)

Every page (39) was loaded with live data, with the API failing outright, and with the API delayed by 5 seconds, clicking through every range tab, to find anything stuck loading, any loading state with no animation, and anything that showed made-up numbers while it loaded.

- **No stuck loading states** in any scenario: every chart shimmer, "Loading live data…" tile and caption clears once data arrives or the request fails.
- **Homepage and Ireland headline figures no longer show placeholder numbers while loading.** The stat cards used to show hardcoded values (e.g. £135.47/MWh, 28.9GW on the homepage; €92.30 on Ireland) until the live fetch finished. They now show a pulsing dash until the real value - or an error dash - arrives.
- **Ireland:** the SEM day-ahead price kept its placeholder €92.30 for good if ENTSO-E returned no price; it now shows "-". The SEM imbalance price now also shows "-" when EirGrid can't be reached.
- **One consistent loading animation.** Any plain "Loading…" text anywhere on the site (chart captions, table rows, the interconnectors "Updated" lines) now pulses the same way as the "Data as of" tile, and stops as soon as its text is replaced - handled once in `assets/app.js` (`initLoadingTextObserver`), so captions a page resets to "Loading…" on a tab switch are covered too.
- **Homepage:** the generation-by-source bar chart and the 24-hour mix chart now show the loading shimmer while they load (they were blank).
- Safety net: a headline value that's still loading after 30 seconds stops pulsing and shows "-".
- Asset version bumped to `?v=20261003` on every page, since `assets/style.css` and `assets/app.js` changed.

## 2026-10-03 (Interconnectors: labelled axes, zero line, capacity column)

- **History charts** redrawn in the same import/export style as the by-country charts: amber area above the line = importing, blue below = exporting, with a zero line that's always shown, value labels on the y axis (GW or %) and UK-time labels on the x axis (hours, days, dates, months or years depending on the range). Hover shows the exact value. They keep Copy as text / Download CSV / Download snapshot (PNG); the PNG redraws the chart at full size so the labels stay readable.
- **Net flow over time (by country)** small charts now have the same labelled axes and zero line, on a shared ±GW scale.
- **Right now, by country:** column headings (Country, Exporting/Importing, Flow now, Capacity), a new Capacity column with each country's combined cable capacity, and an "All countries" total row (net flow and 10.30GW total capacity). The tooltip now shows each cable's capacity too.
- **Energy imported and exported:** column headings (Country, Exported/Imported, Net) and an "All countries" total row.

## 2026-10-03 (Interconnectors: export buttons on the new visuals)

- "Copy as text", "Download CSV" and "Download snapshot (PNG)" added under all four new interconnector visuals (right now by country, live flow map, net flow by country, energy by country). The by-country ones export whichever range tab is showing.
- Text and CSV include the data timestamp (right-now views) or the period covered (by-country views), plus the source. CSVs: per country (net MW, direction, capacity, % used, % of demand, per-cable split), per cable, a per-bucket time series, and per-country GWh totals.
- PNG snapshots use the same card layout as the site's other chart snapshots: title, "ukgridlive.info - data as of ..." line, legend, the chart in the current light/dark theme, caption and source. The by-country one lays the six mini charts out in a 3x2 grid.

## 2026-10-03 (Interconnectors: import/export by country - live bars, live flow map, small multiples, period totals)

### pages/interconnectors.html

- **Status strip:** new "Data as of" tile (local + UK time), same as the homepage. "Current output" renamed "Net flow right now".
- **Right now, by country (was "Breakdown"):** diverging bars per country - imports right (amber), exports left (blue) - over a grey track showing that country's combined cable capacity. Hover/tap shows each cable's own figure and how hard the link is running. Shows "Updated HH:MM (HH:MM UK time). Refreshes every 5 minutes", or a delayed-data warning if the reading is stale. The exact-figures table stays underneath.
- **Live flow map:** each cable is now coloured by direction, drawn thicker the more it carries, with moving dashes showing which way power flows (static under "reduce motion"). Labels show each cable's live MW; hover/tap for capacity use. Its own "Live flows updated" timestamp. Falls back to the capacity labels if live data is unavailable.
- **New "Imports and exports by country" section** (Day / Week / Month / Season / Year):
  - Net flow over time: one small chart per country on a shared scale, area above the line = importing, below = exporting, with a hover crosshair.
  - Energy imported and exported: GWh per country over the period, exports left, imports right, net per country and for all countries.
- Both live pages now refresh every 5 minutes via `GridPreview.autoRefresh`; everything redraws on resize.
- **IFA2 added** (France, 1,000MW, 2021, Fareham - Tourbe) to the cable table and map - it was in the live feed but missing from both. Intro corrected to ten cables (nine subsea, one through the Channel Tunnel), ~10.3GW. History text mentions IFA2.
- Import/export colours (amber #c47f00 / blue #2b6cb0 light; #c4831f / #4f8fd6 dark) validated for colour-blind separation and 3:1 contrast in both themes.

### api/series.php

- New `metric=transfers_country`: per-bucket net flow (GW) for France, Belgium, Netherlands, Denmark, Norway and Ireland (cables summed per country per timestamp), plus `totals` (import_gwh / export_gwh per country) and the window's `from`/`to`. Energy is time-weighted per bucket, trimmed to the requested window and to the span each bucket's readings actually cover. Tested against MariaDB with synthetic readings: totals match a direct per-reading sum.

## 2026-10-03 (Site banner now promotes Plug-in Solar)

- The banner at the top of every page (all 39) changed from "BETA PREVIEW: UK Grid: Live+ is in testing. See data sources" to "NEW: Find out about UK Plug-in solar. Read the guide", linking to the Plug-in Solar section (`pages/plugin-solar.html`).

## 2026-10-03 (Plug-in solar pages re-checked against the current rules)

### Interim Product Specification status

- The DESNZ Interim Product Specification has **not** been updated or replaced. Version 2 (16 July 2026) is current and is referenced by name in SI 2026/848 (in force 27 August 2026). The earlier consultation draft is marked withdrawn on GOV.UK. DESNZ says longer-term standards will follow, with no timeline.
- Every plug-in solar page now has "Rules last checked: 3 October 2026", plus direct links to the IPS v2 PDF, SI 2026/848 and Ofgem's G98 decision.

### Corrections

- **One per household:** the Registration page said the limit was "expected to relax to one per circuit once G98 is amended". G98 was amended by Ofgem on 11 August 2026 and kept one per household. Fixed there and on What is it, Safety and Considerations, which also explain why kit plugs say "one device per household circuit".
- **Batteries:** the Battery page rewritten. Plug-in batteries remain prohibited (Ofgem decision; SI 2026/848 definition), and the IPS requires a warning not to use kits with battery storage. Removed the AC-coupled "needs its own marking" framing, the "simpler to retrofit" DC/AC text, the unsourced "DESNZ-linked battery study" and "internal scouting notes" source. Added PAS 63100:2024 and BS EN IEC 62133.
- **Northern Ireland:** the plug-safety change (PSSR) applies UK-wide; the ESQCR change and G98 amendment are GB only; IPS application in NI "is subject to further consideration".
- **Anti-islanding:** separated G98 loss-of-mains protection from the IPS touch-safety limits (100ms disconnect, pins below 34V within 100ms, capacitors to 34V within 1s). "Can never backfeed" softened to "designed not to".
- **Registration:** notification is a legal requirement (ESQCR reg 22, before or at first use); details asked are name, postcode, make and model (per Electricity North West), not "which socket"; excluded cladding/remediation is a ban, not a permission matter.
- **Certification:** route is type testing against the IPS and G98 with ENA register confirmation before sale, not "self-declaration"; "British or European standard" corrected; CE marking wording now matches GOV.UK.
- **Considerations:** consumer unit advice now reflects the IPS RCBO check/test requirement; "doesn't need a modern board" removed.
- **Mounting:** "bare timber" replaced with the actual IPS exclusions; listed buildings softened to "check with your council".
- **Calculator:** callout no longer says the comparison site lists "UK-compliant" kits; points to the ENA register. Fixed a phone-width horizontal scroll caused by the Mounting type dropdown.

### Added

- What is it: "The current rules at a glance" panel (law, product rules, network rules, England planning, batteries, where it applies).
- Safety: plug markings, IP55/IP44, panel-side limits (4 panels, 2 in series, 120V DC, no Y-connectors), consumer unit label, RCBO check, 960W professional-assessment advice.
- Certification: "What a compliant kit must come with" checklist.
- Registration: consumer unit label step, notify on removal, ENA Connect Direct digital notification in development.
- Mounting: England GPDO (SI 2026/896) detail - balcony enclosure limits, wood/timber exclusions, conservation areas fronting a highway; IPS boundary-wall and escape-route rules.

### Follow-up (same day)

- **Registration:** myplugin.solar linked again, described as the form Electricity North West directs its customers to (sourced to ENWL's own page).
- **Behind-glass figures (Considerations, Calculator):** replaced the unsourced "industry commentary" with glass manufacturers' EN 410 data - Saint-Gobain PLANICLEAR 4mm single glazing passes 89% of solar energy (~11% loss); PLANITHERM NEO low-E double glazing passes 73% of visible light but 48% of solar energy (so ~27-52% loss for a solar cell). Guardian Glass definitions and Approved Document L (1.4 W/m²K replacement windows) cited. The unsourced "most UK double glazing since the early 2010s is Low-E" claim was replaced. The calculator's 0.55/0.65 factors are unchanged (they assume ~30-35% glass loss, inside that range).

## 2026-10-02 (Records re-checked on every page; dated records added for every country; notable moments fixed)

### Records panels - re-verified against official/reputable sources (all "Last checked" now 2 October 2026)

- **GB (`index.html`, `renewables.html`, topic pages):**
  - Wind (23,880MW, 25 March 2026) and solar (15,427MW, 12 July 2026) records confirmed. A note now says Carbon Brief's dataset puts the solar peak at ~15.2GW (23 April 2026).
  - Wind curtailment is now credited to Montel EnAppSys, which produced the estimate.
  - **Carbon intensity corrected.** The "record" 39g/kWh (2021) has been beaten: NESO reported 20g/kWh on 7 April 2026 and 26g on 25 March 2026. The "near-record 89g/kWh on 23 May 2026" was wrong (May 2026's low was 32g). The unsourced "~500g in 2012" average was replaced with Carbon Brief's 419g (2014) to 126g (2025). The chart was updated to match.
  - Winter 2026/27 early-view margin added (5.5GW, 8.8%).
  - The 2025 renewables share is now labelled 47% UK / 44% GB.
  - Peak coal share corrected to ~76% (1980).
  - Viking Link is "longest land-and-subsea", not "longest subsea"; North Sea Link was added as the longest subsea interconnector at opening.
  - Dinorwig: "0 to 1,320MW in about 12 seconds", per its owner ENGIE, replacing "under 16 seconds"; opening date is 9 May 1984.
  - CfD AR6/AR7 figures and AR8's results window were made precise, and a dubious Energy UK link was replaced with DESNZ sources.
  - "This year" Elexon figures are relabelled "past 12 months", since one dated from December 2025.
- **Corrections elsewhere:**
  - Ireland: last peat-fired generation was the end of 2023 (Edenderry), not 2020. Also fixed in two prose captions.
  - Belgium: nuclear extension runs to 2035, not 2036; there are 9 offshore farms, not 8; the Princess Elisabeth tender status is updated.
  - Germany: "wind + solar first became the largest source in 2025" corrected to "solar first overtook lignite in 2025"; the renewables share is ~59% in both 2024 and 2025.
  - Spain: renewables first passed 50% in 2023, not 2024; solar overtook wind during 2024, not January 2025. The chart now uses REE's 2023/2024 figures only.
  - Italy: the Larderello site still generates, not the 1913 unit itself.
  - Poland: 31.4% renewables in 2025, not 29.4%. The chart's 2024/2025 values were corrected.
  - Ontario: nuclear was 48.2% in 2025, and the unverifiable hydro range was removed.
  - Australia: the Waratah Super Battery is now the largest, and Eraring now closes in 2029.
  - Brazil: hydro ~52% (2025).
  - Argentina: three reactors on two sites, with Atucha I offline; the unsourced Yacyretá "60%" was removed.
  - Chile: coal is down to ~2.8GW.
  - Colombia: the Venezuela link status is updated.
  - Unverifiable claims were removed: the Dutch and Belgian winter-demand "records", Norway reservoir months and the Ontario hydro range.
- **Dated, sourced records added for every country page**, renamed from "Context" to "Records & context". Each panel's caption now lists its actual sources:
  - France: demand 102.1GW (2012), wind 20.1GW, solar 23.8GW and record net exports.
  - Netherlands, Belgium, Norway, Denmark: annual records, plus Norway's 25,309MWh/h consumption record (January 2026).
  - Germany: solar 50.4GW, onshore wind 46.4GW and negative-price hours.
  - Spain: peninsular peak 45,450MW (2007).
  - Italy: July 2026 record month.
  - Sweden: 99% fossil-free.
  - Portugal: first time above 10GW demand (January 2026).
  - Poland: solar 14,565MW.
  - USA: ERCOT 91.1GW and PJM ~168.2GW (both July 2026).
  - Ontario: highest demand since 2007.
  - Australia: record 78.6% renewable share and record minimum demand.
  - Ireland: solar 1,222MW and battery 396MW.

### Notable moments (GB, computed from this site's own stored data) - fixed

- **One empty source table broke the whole panel.** The helper's `?array` type hint made `PDOStatement::fetch()`'s `false` (no rows) a fatal TypeError, so an empty table stopped every moment from being computed.
- **The lowest price could read £0.00.** Elexon sets the Market Index Price to exactly 0 for half-hours below its liquidity threshold. Zero-volume rows are now excluded here, from the live price in `api/current.php`, and from the price series in `api/series.php`, where they had been dragging averages down.
- **The price was mislabelled as "day-ahead".** It is Elexon's market index (MID/APX). Fixed on the price-history caption too.
- **Highest wind** now pairs each 5-minute Elexon reading with its half-hour's embedded wind; before, only :00 and :30 readings included embedded wind.
- **Solar** is labelled as a NESO estimate.
- Verified against MariaDB fixtures. Old: fatal on an empty emissions table. New: correct values, including -£5.20 rather than £0 for the lowest price, and 14,000MW wind at a 5-minute timestamp.

## 2026-09-30 (Fix every live page sticking on "Loading live data…" after the v24/v25 upload)

### Fixed

- **Every page with live data sat at "Data as of: Loading live data…" after deploying v24/v25.** The new pages call `GridPreview.autoRefresh()`, which only exists in the new `assets/app.js`. The asset URLs had no version string, so browsers that had visited recently kept using their cached old `app.js`. The call threw `TypeError: GridPreview.autoRefresh is not a function` before any data loaded. Reproduced exactly by serving the new HTML with the previous commit's `app.js`/`data.js`. The v24 audit had tested new HTML with new JS only, never with a cached old script as a real returning visitor gets it.
  - Every `assets/*.js` / `assets/*.css` reference in `index.html` and `pages/*.html` now carries `?v=20260930`, so a new upload makes browsers fetch matching scripts. Bump this string whenever `assets/` changes. `data.js` already works out the API base from its own URL with any query string.
  - Each page's `autoRefresh` call now falls back to a plain one-off call if an older `app.js` without it is loaded, so a mismatched cache can't freeze a page again. Re-tested: the France page with the old cached `app.js` now loads normally, and the full three-pass mix audit still passes (51/51).

## 2026-09-30 (tools/full-refresh.php: fix "500 Internal Server Error" after a site upload)

### Fixed

- **`tools/full-refresh.php` returned Apache's generic "500 Internal Server Error ... misconfiguration" page.** `tools/` was protected by HTTP Basic Auth in `tools/.htaccess`, which needs the absolute server path to a `.htpasswd` file. The shipped `.htaccess` only holds a placeholder (`/REPLACE-WITH-ABSOLUTE-SERVER-PATH-TO/tools/.htpasswd`) that had to be edited by hand on the server. `.htpasswd` itself is gitignored, so it isn't in the deploy zips either. Uploading the whole site folder again puts the placeholder back, and Apache then can't open the password file and fails every request to `tools/` with a 500, before PHP runs. (The server's error log should show "Could not open password file" to confirm this.)
- **The login is now in PHP** (`tools/_auth.php`): a password form checked with `password_verify()` against a new `tools_password_hash` setting in `includes/config.php`.
  - `config.php` never goes up with a site upload, so re-deploying can't break or remove the protection, and there's no server path to fill in.
  - It fails closed. Until the hash is set, the page runs nothing and instead shows a form that turns a chosen password into the line to paste into `config.php`.
  - Sessions use an HttpOnly, SameSite=Strict cookie scoped to `tools/`, the session id is regenerated at login, and a failed attempt waits 1 second.
  - The page's AJAX calls get a JSON 401 when not logged in, and the page now has a "Log out" link.
  - `tools/.htaccess` now has no directives at all, so it can't cause a 500 whatever the host allows.
- Tested with PHP's built-in server against the local test database:
  - The setup form refuses short passwords and generates a line that works when pasted into `config.php`.
  - Before logging in, the page and its AJAX endpoint are both refused (401).
  - A wrong password is refused; the right one redirects (302) to the page (200).
  - AJAX calls work once logged in, and logging out locks the page again.

## 2026-09-30 (Generation mix: donuts and source tables now always match, and keep updating)

Reported: on the location/country pages the "Generation mix right now" donut and the Source/GW/% table beside it didn't agree and didn't stay up to date. Audited every page with a mix donut (GB, Ireland, France, Netherlands, Belgium, Norway, Denmark, Germany, Spain, Italy, Sweden, Portugal, Poland, the EU aggregate, USA, Canada, Australia). There were real differences on every one of them, and several data bugs in the backend underneath.

### Fixed - frontend (table vs donut)

- **One renderer for every mix section.** New `GridPreview.renderMixBreakdown()` (`assets/app.js`) builds the table, donut, donut legend and bar chart from one list of rows. `renderPsrMixSection()` (all ENTSO-E pages and the EU aggregate) now wraps it, and `index.html`, `pages/usa.html`, `pages/canada.html` and `pages/australia.html` were rewritten to use it. Rules, applied identically everywhere:
  - The donut's slices are exactly the table's positive rows, in the same order, with the same labels and colours. Each table row now carries its slice's colour swatch.
  - Every % (table rows, group subtotals, donut tooltip, legend) is a share of one total, which is the donut's centre figure.
  - Negative readings (pumping load, battery charging, net exports) go in a clearly labelled "Consuming or exporting right now" group with "-" for %, never as a slice. They used to reduce the table's total while being silently dropped from the donut's.
  - Zero readings are left out of both and named in the caption.
  - Fuel codes with no group now appear under "Other" in the table. They used to appear in the donut only.
- **GB (`index.html`).**
  - The table's % was a share of demand while the donut's was a share of generation.
  - Interconnectors and pumped storage were table rows with no donut slice.
  - The category donut's centre showed demand, not its own total.
  - Now both donuts and the table show the same rows and the same total supply (generation + pumped storage + net imports).
  - The illustrative battery preview figure is no longer added onto the live pumped-storage row. It's a separate row, shown only while its toggle is on and excluded from exports while hidden.
- **Ireland.**
  - The GB-interconnect line was `Math.abs()`'d into an "import" slice even while Ireland was exporting. It's now signed: an import is a slice, an export is listed separately.
  - A failed ENTSO-E or EirGrid fetch no longer wipes a cached mix to "No data".
  - The caption now says why the headline Generation figure (from EirGrid) can differ from the ENTSO-E breakdown.
- **Rounding.** The legend and tooltip rounded to 1 decimal place and whole percentages while the table showed 2 decimals. They now use the table's precision: GW to the same decimals, % to 1 decimal. The bar chart labels match too. Two illustrative first-paint tables (France, Netherlands) had % values off by 0.1.
- **USA:** now shows every EIA fuel category it has, including the newer battery, pumped-storage and geothermal codes (see backend below), with storage in its own group.
- **Canada:** switched to 2 decimals, so small sources (e.g. 13MW of biofuel) show as small rather than "0.0".
- **Australia:** Open Electricity's `pumps` is pumped-hydro pumping *load*; pumped hydro's output is already inside `hydro`. It was being shown as a "Pumped hydro" generation slice. It and battery charging are now listed as consumption. `api/australia_current.php`'s `generation_mw` and `api/australia_series.php` no longer count `pumps` as generation.
- **Charts that once showed "No data" stayed invisible.** `showChartError()` hid the canvas and nothing un-hid it, so a later successful render drew an invisible donut beside a populated table. `registerChart()` now clears that state. It also keeps one registry entry per canvas instead of replaying every stale earlier render on resize or theme change.
- **Staying up to date.** Every page's stats, mix table and donuts used to load exactly once. They now re-fetch every 5 minutes while the tab is visible (`GridPreview.autoRefresh`). A failed refresh keeps the last good figures on screen instead of blanking them.
- Upstream-supplied labels are now HTML-escaped in the mix table and legends.

### Fixed - backend (the data under the donut)

- **`api/current.php` (GB): solar was usually missing from the donut and table.** `readings_generation` mixes Elexon's 5-minute FUELINST rows with NESO's 30-minute embedded solar/wind rows. The snapshot took `MAX(ts)` and then read rows "at exactly that ts". So at 5 of every 6 timestamps, Solar and embedded wind vanished. Just after each half hour it could also return a "mix" of only those two NESO rows (reproduced against a real MariaDB with fixture data: the old endpoint returned `{SOLAR_EMBEDDED, WIND_EMBEDDED}` and nothing else). It now uses the latest Elexon timestamp plus the embedded figures for the settlement period it falls in, found by index scan rather than a full-table `MAX()`.
- **`api/series.php` (GB history, sparklines, 24h stack): same root cause.** The "sum per timestamp, then average per bucket" queries counted embedded solar/wind at only 1 in every 6 timestamps. The solar series averaged about a sixth to a third of the real figure, and the generation, renewable, wind and mix series dipped and jumped every half hour. They now pair every Elexon timestamp with its settlement period's embedded values. The generation series also no longer includes pumped storage, matching the headline Generation figure.
- **`api/country_current.php`, `api/usa_current.php`, `api/canada_current.php`, `api/australia_current.php`: one timestamp per mix.** Each fuel was looked up on its own as "nearest reading within +/- the window" (4 hours for ENTSO-E, 2 days for the EIA), so one donut could combine readings from different hours. Now every value is read at a single timestamp: the latest one with at least three-quarters of the fuel types published. A type missing there falls back to its latest reading at or before it, never a later one. The endpoints return this as `mix_ts`, and the donut caption shows "mix as of ...". Shared helper: `ukgrid_coherent_mix()` in `api/_bootstrap.php`. The headline generation figure now sums the same positive values as the donut.
- **`api/usa_current.php`** now returns every stored fuel category, not just the original nine, and `api/usa_series.php` groups the newer codes.
- **`includes/ingest.php` (ENTSO-E): pumping could overwrite generation.** An A75 response carries a second, *consumption* TimeSeries for types such as pumped storage (it has `outBiddingZone_Domain.mRID`). It shares the psrType of the generation series, so the upsert let whichever came last win, which could put pumping load into the mix as generation. Consumption series are now skipped. Rows already stored this way outside the normal re-fetch window stay as they are until re-ingested.

### Verification

- **Automated donut-vs-table audit** (Playwright, fixture API data), all 17 pages, run three times:
  1. Normal data.
  2. Edge cases: negative pumping and battery values, zero solar, Ireland exporting, GB net-exporting, unknown fuel codes.
  3. Load, switch the API to different numbers, fire the 5-minute refresh, and confirm table and donut both moved to the new data and still match.

  Each check compares the table's rows, order, GW and % against the donut's slices, legend, tooltip values and centre total, and checks group subtotals and that % adds to 100. On the old code, every page failed at least one check. On the new code, all 51 page-checks pass.
- **Backend** endpoints run against a local MariaDB loaded with `sql/schema.sql` and fixture rows that reproduce each bug (5-minute vs 30-minute GB rows, a half-published ENTSO-E hour, a lagging psrType, new EIA codes, partial IESO and Open Electricity intervals). Old and new outputs were compared side by side.
- **Independent code review** of the full diff by a separate reviewer. Its findings were addressed: the index-friendly latest-timestamp query, Canada and Australia coherence, positive-only generation sums, Ireland refresh edge cases, label escaping, and export of hidden rows.
- PHP lint on every changed endpoint, `node --check` on `assets/*.js` and every inline script, HTML tag balance, CSS brace balance and a secrets grep all pass.

## 2026-09-28 (Plug-in Solar: remove unreliable "clone-coded" sources, correct the registration mechanism)

### Fixed - significant

- **myplugin.solar was never confirmed as an official registration portal, and the site presented it as one.** It was cited across eight pages as "the" way to register a plug-in solar kit, including a "Government & official sources" list with a UK flag, and once as "the Energy Networks Association's national registration portal." Direct verification against DESNZ's July 2026 government response and Interim Product Specification (v2.0, final) found neither document names any consumer-facing portal at all - the actual requirement is that a compliant kit's own documentation must include a QR code and clear instructions to notify your electricity network operator (DNO) under the standard G98 process; the specific website that QR code leads to depends on the kit and network operator, not one fixed address. Rewrote every reference to describe the real mechanism (DNO notification via G98, per your kit's own documentation) instead of naming myplugin.solar, and removed it from both "Government & official sources" lists. Touched: `plugin-solar.html`, `plugin-solar-registration.html` (substantial rewrite - hero text, registration steps, one-per-household wording, Northern Ireland section, sources), `plugin-solar-certification.html`, `plugin-solar-battery.html`, `plugin-solar-safety.html`, `plugin-solar-mounting.html`, `plugin-solar-considerations.html`.
- **www.pluginsolarregister.co.uk replaced with the real ENA Type Test Register** (`plugin-solar-certification.html`) - the site explicitly self-describes as an independent, unofficial tracker of the real register; now links directly to the ENA's own register instead.

### Changed

- **Removed commercial/unreliable sources cited as data references, per the site owner's direction not to use "clone-coded" SEO sites for factual data:**
  - Sunsave (`plugin-solar.html`, `plugin-solar-calculator.html`) - removed; wasn't underpinning any specific figure, just listed as a cross-reference.
  - Power NI's appliance-wattage guide (`plugin-solar-calculator.html`) - replaced the fridge-freezer figure with a transparent conversion from the Energy Saving Trust's own published running-cost figure (~£80/year for an F-rated 424L fridge-freezer, converted at this calculator's own Ofgem unit rate to ~35W average continuous draw), shown with its working rather than stated as a bare fact. No UK government or professional-body source could be found for a WiFi-router wattage figure specifically - now honestly labelled as a commonly-quoted planning estimate, not a sourced fact, rather than forcing an attribution that doesn't exist.
  - homeenergymodel.co.uk (`plugin-solar-calculator.html`) - confirmed to be a private commercial consultancy trading on the government's "Home Energy Model" name, not the government itself. Replaced with the real GOV.UK Home Energy Model methodology page for the "base load" terminology; the by-property-size ranges and the overnight-meter-reading technique are now honestly labelled as this site's own practical guidance, since no single external authority for those specific figures could be confirmed.
- **sunhours.app flagged as under review, not yet replaced.** This is the dataset behind the calculator's core generation numbers (13 regions x 12 months). Attempted to source a replacement from MCS's own published Irradiance Datasets or the EU JRC's PVGIS tool, but this session's network access could not reach either host to retrieve the actual figures. The existing sunhours.app-sourced numbers are unchanged for now - both pages that cite it now say so plainly rather than silently keeping the old citation. Needs the site owner to supply the MCS spreadsheet or equivalent data before this can be completed.

### Verification

- HTML tag-balance and inline JS-syntax checks on all eight touched pages; secrets grep - clean.
- Two DESNZ PDFs (government response and Interim Product Specification v2.0 final) fetched and quoted directly before rewriting any registration-mechanism content, rather than relying on secondary summaries.

## 2026-09-28 (Plug-in Solar: source audit + kit-comparison callout on calculator)

### Added

- **Callout on the Energy calculator linking to PluginSolarCalculator.com/products**, placed after the payback stats so it appears right where a reader has just seen a payback estimate using a placeholder kit cost. Disclosed plainly as an independent third-party comparison site, not run by us or by government, per this site's usual practice of not overstating another site's status.

### Investigated (no page changes yet - see chat writeup for full detail and open questions)

- Audited every external source cited across the Plug-in Solar pages against "is this a UK government body or a recognised professional/industry body." Most pass (GOV.UK/devolved-nation-government pages, Ofgem, HSE, MCS, Energy Networks Association, Planning Portal, legislation.gov.uk). Flagged several that don't: sunhours.app, Sunsave, Power NI, homeenergymodel.co.uk, Octopus Energy, and pluginsolarregister.co.uk are all commercial/independent, not government or professional-body sources. Citizens Advice and Electrical Safety First are registered charities, not government bodies, though both are widely treated as authoritative. Most significantly, myplugin.solar - used throughout the Registration/Safety/What-is-it pages as "the" registration portal for the DESNZ scheme - could not be confirmed as an officially designated government portal; research suggests it may be an independent commercial site. This needs the site owner's input before editing, since it's presented as a compliance step, not just a citation.

## 2026-09-21 (Plug-in Solar: bring Considerations' placement icons in line with the calculator's redesign)

### Changed

- **Updated the six "Best panel placement" example icons on `plugin-solar-considerations.html`** to match the refined side-profile, line-panel style now used for the mounting-type icons on the Energy calculator, instead of the older filled-box panel icons. South-facing tilted reuses the calculator's ground-frame icon; south-facing vertical, east/west-facing and north-facing all reuse the calculator's close-to-wall bracket composition (keeping each card's existing colour-coding: accent for south, the wind colour for east/west, muted for north); the two indoor cards reuse the calculator's indoor-flat and indoor-tilted window icons directly. No wording or percentage figures changed - icons only.

### Verification

- HTML tag-balance check on the edited page; secrets grep - clean.
- Playwright screenshot of the updated placement grid reviewed and approved before rolling in.

## 2026-09-21 (Plug-in Solar: clearer worked example for unused generation being exported unpaid)

### Changed

- **Made it explicit that generation below the kit's 800W cap that isn't used is exported for nothing, with a concrete number.** Following on from the previous fix of the "no export mechanism" claim, added a direct worked example to `plugin-solar.html` ("Why the power gets used by your home, not the grid"): if a kit is putting out its full 800W maximum but the home is only drawing 300W at that moment, the other 500W flows out to the grid the same as any small grid-tied inverter's would, but earns nothing since there's no metering or export tariff for a kit like this. Also added a clarifying sentence to the Energy calculator's base-load field caption (`plugin-solar-calculator.html`) stating the same point in the context of the base-load/occupancy inputs users are actually editing.

### Verification

- Re-checked the 800VA/3.5A cap figure and export mechanics against DESNZ's July 2026 plug-in solar government response and interim product specification (v2.0, final): confirmed no dynamic/zero-export limiting exists in these devices, so a full-output surplus above home draw does flow to the grid uncontrolled, consistent with independent worked examples found elsewhere (e.g. "generating 500W, using 300W, 200W exported").
- HTML tag-balance and inline JS-syntax checks on both edited pages; secrets grep - clean.

## 2026-09-21 (Plug-in Solar: correct export/anti-islanding claim, reader feedback)

### Fixed

- **Incorrect "these kits have no mechanism to export" claim.** A reader pointed out that plug-in solar kits *can* and do export surplus generation back to the grid like any small grid-tied inverter - there's just no metering or tariff (e.g. the Smart Export Guarantee) set up to pay for it from a self-installed, unregistered system like this. The site previously stated the opposite (that there's no export mechanism at all) in three places, and additionally misattributed this to anti-islanding protection. Checked against DESNZ's July 2026 plug-in solar consultation/government response and general grid-tied inverter behaviour: confirmed the reader is correct on both points.
  - `plugin-solar.html` ("What is it?" and "Why the power gets used by your home, not the grid" sections): reworded to say surplus generation flows out to the grid unpaid and unmetered, rather than "has no export mechanism"/"is simply lost".
  - `plugin-solar-safety.html` ("What's already built in" list): replaced the "No hidden export" bullet (which claimed "no mechanism to export to the grid") with "No export payment, not 'no export'", explaining the surplus can flow out to the grid but isn't remunerated, and that this is unrelated to anti-islanding.
- **Anti-islanding's purpose clarified alongside the above.** Anti-islanding protection disconnects the inverter when it can't detect a live grid connection (e.g. during a power cut), for the safety of network engineers working on what should be a dead line - it is not designed to prevent export during normal, grid-connected operation. The existing "What happens in a power cut" section on `plugin-solar-considerations.html` already described this correctly and needed no change; the fix was to stop other pages implying anti-islanding also blocks export.

### Verification

- HTML tag-balance checks on both edited pages; secrets grep - clean.
- Web research against DESNZ's July 2026 plug-in solar government response, GOV.UK consultation documents, MCS's Smart Export Guarantee guidance, and independent plug-in/balcony-solar commentary, confirming: (1) these microinverters have no active zero-export/demand-following limiter, so surplus does physically flow onto the grid; (2) anti-islanding responds to loss of grid connection, not to export; (3) the barrier to being paid for that export is a metering/scheme gap (no SEG-eligible metering for a self-installed, unregistered small system), not a technical impossibility.

## 2026-09-20 (Plug-in Solar: myplugin.solar link fix, indoor mounting options, base-load section, mounting-icon redesign)

### Fixed

- **`myplugin.solar` links pointed to the wrong subdomain.** All four hrefs across `plugin-solar.html` and `plugin-solar-registration.html` used `https://www.myplugin.solar/`; the correct address has no `www`. Fixed to `https://myplugin.solar/`.
- **Mounting-type example icons redrawn as side profiles.** The original six icons on the calculator's "Mounting type examples" grid mixed front-on and see-through-window views and didn't read clearly. Redrawn as side-view diagrams, refined over several passes: the panel itself is shown as a single accent-coloured line (its edge-on profile) rather than a filled box, with each mount/bracket/leg meeting the panel exactly at that line rather than overlapping or floating clear of it. Ground frame: a low angled frame with short front/tall back legs sitting close to the ground. Balcony: a hook sits snug over the top of the railing with the panel's top close in against it, hanging down and outward at an angle, with a lower mount/leg further down the post. Wall/hang: short brackets keep the panel close to the wall face. Wall mount: fixed near-vertical against the wall. Indoor flat/tilted: shown against a window frame, flat or angled back from it. Matches the side-view convention already used for the orientation cards on `plugin-solar-considerations.html`.

### Added

- **Two new indoor mounting options on the Energy calculator**: "Indoors (flat against window)" and "Indoors (tilted behind window)", added back to the Mounting type dropdown (they'd been dropped from the calculator when placement was reworked into Facing/Orientation/Mounting). Unlike the four outdoor mounting types, these two carry their own fixed, illustrative factor (0.55 / 0.65 - the same figures already used on Considerations) and, when selected, override the facing/orientation multiplication entirely, since those figures already assume a south-facing window and bake in an estimated glass-transmission loss. Selecting either one now: greys out and disables the Facing/Orientation dropdowns (they don't apply), shows a new callout warning that the figure is a rough estimate depending heavily on the specific window's glass type (10-15% loss for older single glazing vs 40-50%+ for modern Low-E double glazing), and updates the "How your placement factor is worked out" card to show the fixed estimate instead of a facing x orientation formula. `assets/plugin-solar-data.js`'s `MOUNTINGS` array gained an optional `indoorFactor` field used by the calculator's script to detect and branch on the indoor case.
- **New "Working out your base load" section on the Energy calculator**, explaining what base load means for the calculator, the accurate method (reading the overnight floor value off a smart meter's in-home display or app), a rough by-property-size guide (small flat ~100-200W, mid-terrace ~200-350W, larger detached ~300-500W) for anyone without a smart meter, and a build-it-yourself breakdown of typical always-on device wattages (a fridge-freezer's ~400W nameplate rating cycles down to roughly 25-60W averaged over a day; a WiFi router draws 2-20W, averaging ~6W; other standby electronics add up in small amounts). Sourced to Home Energy Model's base-load explainer, Power NI's appliance-electricity guide, and Citizens Advice's smart-meter-display guidance - disclosed as general industry guides, not a measurement of the reader's own home.

### Verification

- HTML tag-balance and JS-syntax checks (external + inline) on the calculator page and `plugin-solar-data.js`; CSS brace-balance check; secrets grep - all clean.
- Playwright: confirmed selecting an indoor mounting option shows the callout, disables both Facing and Orientation selects, and updates the worked-out card to the fixed estimate (0.65 for "tilted behind window"); confirmed switching back to an outdoor option re-enables everything and hides the callout; confirmed the annual generation figure itself changes correctly with the indoor factor (536 kWh/yr at the default south/vertical setup vs 378 kWh/yr for indoor-flat, matching the 0.78→0.55 factor ratio exactly); screenshots of the redrawn mounting-icon grid and the new base-load section.

## 2026-09-20 (Plug-in Solar: warning-box CSS follow-up fix)

### Fixed

- **The "Don't plug in more than one kit" warning box was still reported as breaking mid-sentence after the previous fix.** Root cause: the previous fix scoped the block-level rule to `.ps-warning p:first-child strong:first-child`, but every `.ps-warning` box's `<p>` is actually the *second* child (the icon `<svg>` comes first) - so that selector never matched anything, and the reported "still broken" screenshot was almost certainly the live site, not-yet-updated with the earlier fix. Rather than rely on a `:first-child` selector that silently never matches, replaced it with a plain `.ps-warning strong { font-weight: 700; }` rule - bold text inside a warning box is always inline emphasis now, never a forced block, regardless of where it falls in the sentence. Verified via Playwright screenshot that the box renders as one continuous flowing paragraph.

## 2026-09-20 (Plug-in Solar: warning/table formatting fixes, battery-page rewrite, ring-circuit worked example, extension-lead warnings, placement picker rework)

### Fixed

- **Warning-box text breaking mid-sentence.** `.ps-warning strong { display: block; }` was forcing *every* bold phrase inside a warning box onto its own line, not just the lead-in title - visible as broken, ragged text on `plugin-solar.html`'s "Don't plug in more than one kit" box, which has a second, mid-sentence bold phrase. Scoped the block-level rule to only the first `<strong>` inside the first `<p>` (`.ps-warning p:first-child strong:first-child`), so lead-in titles still stand out but other bold text stays inline. Verified via Playwright screenshot.
- **Fact lists rendering as broken, unaligned text instead of a table.** The sitewide `.records-list` component (`display:flex; justify-content:space-between` per row) is built for short label/value pairs; several new Plug-in Solar lists pair a short label with a full sentence, which squeezed the label column into an unreadable ragged wrap. Added a new `.ps-fact-list` component (CSS grid, fixed label column) and switched the seven affected lists to it: `builtin-list` and `checks-list` (Safety), `battery-types-list` and `battery-safety-list` (Battery power), `cu-list` and `other-list` (Considerations), `pp-list` (Mounting) - `pd-list` on Mounting was left as `.records-list` since its values are genuinely short. Confirmed via `assets/app.js` that the copy/CSV helpers key off element structure, not the class name, so this was safe to introduce. Verified via Playwright screenshot.

### Changed

- **Reworded the battery-page "Regulatory status" section** (`plugin-solar-battery.html`) to be more accurate about what's actually confirmed: DESNZ's own position is that battery storage is "outside the scope" of the current consultation but it "will continue to consider the evidence base as part of future work" - no confirmed timeline, so the page no longer implies a settled roadmap. Added that installing a battery is normally notifiable electrical work under Part P of the Building Regulations, so it typically needs a registered/qualified electrician rather than being a self-install job like the panel-plus-inverter kit itself. Added a cautiously-worded warning that installing a battery - especially without proper electrician certification/notification - can be a material change your insurer expects to be told about, and that not disclosing it risks a claim being reduced or a policy being invalidated; this isn't attributed to any specific insurer body, since no plug-in-solar-specific insurance guidance was found to cite.

### Added

- **"A plug-in solar kit MUST connect directly into a wall socket" warning added to all 8 Plug-in Solar pages**, each as its own `.ps-warning` box placed consistently right after the page's subnav, reinforcing the existing (but previously easy-to-miss) extension-lead rule from the Interim Product Specification.
- **New "Worked example: plug-in solar and a portable air conditioner" section on `plugin-solar-considerations.html`**, with an inline SVG diagram illustrating how a ring final circuit loops from the consumer unit past several sockets and back to the same MCB/RCBO, a two-item fact list contrasting the solar kit's own direct-connection requirement against a portable air conditioner sharing the same ring, a 5-step `.ps-steps` guide to checking which sockets share a ring (consumer-unit labelling, switching off breakers one at a time, a plug-in socket tester, not assuming by room, and asking an electrician), and its own warning box stating plainly that this is a general illustration, not electrical advice for a specific home. New `.ps-ring-diagram` CSS for the diagram's inset container.
- **New "Why the power gets used by your home, not the grid" section on `plugin-solar.html`**, explaining in plain terms (with a matching SVG diagram in the same visual style as the ring-circuit one) that the plug-in inverter and the grid both feed the same ring circuit, so demand is met from whichever source is available at that instant and only the shortfall is drawn - and metered - from the grid; includes a worked numeric example and reiterates that surplus generation isn't exported or credited, since these kits have no export mechanism.
- **Panel placement on the Energy calculator reworked from a single 6-option dropdown into three linked pickers**: Panel facing (South/East/West/North), Panel orientation (Tilted ~30-40°/Vertical), and an illustrative Mounting type (Outside Ground Frame, Outside Balcony, Outside Wall/Hang on outside of Balcony, Outside Wall mount) - the calculator's older single dropdown only had static considerations-page's original text as a note, now the facing and orientation factors multiply live (mounting type is illustrative only and doesn't change the estimate, since any of the four mounting types can be tilted or vertical). Added a small illustrated example card for each of the four mounting types (with the current selection highlighted), and a new "How your placement factor is worked out" card showing the facing factor × orientation factor = combined multiplier live as the dropdowns change, so the maths behind the estimate is no longer hidden in a single opaque dropdown label. `assets/plugin-solar-data.js` gained `FACINGS`, `ORIENTATIONS`, `MOUNTINGS`, `getFacing()`, `getOrientation()`, `getMounting()` and `computePlacementFactor()`; `computeMonthly()`/`computeDay()` now take a combined numeric placement factor instead of a placement-option id (only the calculator page uses these functions, so this was a safe internal change). The calculator's indoor/behind-glass placement options from the original dropdown are no longer offered here - Considerations still covers that case separately for anyone comparing it.

### Verification

- HTML tag-balance checks (`div`, `section`, `ul`/`ol`/`li`, `svg`, `p`, `script`, `select`, `label`, table tags, etc.) on all touched pages; JS-syntax check (`node --check`) on `assets/plugin-solar-data.js` and the calculator's inline script; CSS brace-balance check on `assets/style.css`; secrets grep across all touched files (clean).
- Playwright screenshots confirming: the warning-box fix renders as a normal flowing paragraph with only the lead-in bolded; the `.ps-fact-list` conversion produces a cleanly aligned two-column list; the reworded battery-page section reads correctly; the new ring-circuit worked-example section (diagram, fact list, steps, warning) renders correctly; the new "why the power gets used" diagram renders correctly (including a wording fix after the first pass left a diagram label mid-sentence); the calculator's new three-dropdown placement picker updates its mounting-type highlight and "worked out" card live, and changing facing/orientation changes the computed annual generation figure as expected (checked with West facing + Tilted = 0.62 factor, correctly applied).

## 2026-09-20 (Plug-in Solar: subnav icon fix, Annual/Month/Day calculator tabs)

### Fixed

- **Subnav icons missing on 7 of the 8 Plug-in Solar pages.** The cross-page tile row (`.ps-subnav`) only had its icons wired up on `plugin-solar.html` itself - Safety, Battery power, Considerations, Mounting, Registration, Energy calculator and Certification were shipped with plain text tiles. Fixed via a scripted pass inserting the same icon SVG (matching the one used for that page in the main nav dropdown) into all eight pages' subnav consistently.

### Added

- **Annual / Month / Day tabs on the Energy calculator's "Estimated results" section.** Previously the section only showed annual totals plus the monthly chart/table. Now:
  - **Annual** - unchanged: annual generation/usable/savings/payback, the monthly bar chart, and the CSV-exportable monthly table.
  - **Month** - a month dropdown (defaulting to the current month) showing that single month's generation, usable generation and both savings figures, read directly from the same per-month array the Annual tab's chart/table use, so the two never disagree.
  - **Day** - two dropdowns, Season (Spring/Summer/Autumn/Winter) and Weather (Sunny, Cloudy, Overcast, Wet, Stormy), estimating a single representative day's generation and saving. The season maps to the average irradiance across its three calendar months; the weather condition applies an illustrative multiplier (1.60 sunny down to 0.15 stormy) on top of that average. There's no citable UK dataset breaking solar irradiance down by named weather condition, so this is explicitly disclosed on-page as an illustration of day-to-day variability, not a measured forecast - consistent with the site's practice of never presenting a modelling assumption as measured fact.
  - New shared logic in `assets/plugin-solar-data.js`: `SEASONS`, `WEATHER_CONDITIONS`, `getProfile()`, `getSeason()`, `getWeather()` and `computeDay()`, alongside the existing `computeMonthly()` - the page script no longer duplicates the 13-city irradiance-profile object inline, it now calls `DATA.getProfile()` for the sun-hours strip too.
  - New `.ps-tabs`/`.ps-tab-btn`/`.ps-tab-panel` styling in `assets/style.css`, built from existing tokens/keyframes so it matches the site's light/dark theme.

### Verification

- JS-syntax check on both inline scripts and `assets/plugin-solar-data.js`; HTML tag-balance check on the calculator page.
- Playwright: confirmed all three tabs switch correctly, the Day tab produces sensible extremes (a stormy winter day and a sunny summer day for London came out at 0.08 kWh and 3.88 kWh respectively for an 800W south-vertical setup), and the Month tab's December figures match the Annual tab's own table row for December.
- Playwright screenshot of the Safety page confirming its subnav tiles now show icons matching the main nav.

## 2026-09-20 (Plug-in Solar becomes its own 8-page top-level section)

### Added

- **New top-level "Plug-in Solar" nav group, positioned before "Great Britain," on all 41 pages.** The single `pages/plugin-solar.html` page from earlier today has been split into eight pages, scripted sitewide via a Python nav-rewrite pass (removing the old single "Plug-in solar" entry from the Great Britain dropdown, inserting the new group with the correct active/aria-current state per page) rather than hand-edited file by file:
  - **`plugin-solar.html` ("What is plug-in solar")** - reworked to a shorter overview plus an animated seasonal sun-hours strip and links out to the other seven pages, since the original page's "rules," "calculator" and "cost" content has moved to its own dedicated pages below.
  - **`plugin-solar-safety.html` ("Safety")** - what a compliant kit already builds in (anti-islanding, fixed BS 1362-fused plug, output ceiling, weatherproofing) versus what's still worth checking yourself, plus the multiple-kits warning.
  - **`plugin-solar-battery.html` ("Battery power")** - clarifies that battery storage is NOT currently part of the compliant plug-in solar scheme itself, explains AC-coupled vs DC-coupled in plain terms, and covers battery-specific safety standards (BS EN IEC 62619, UN 38.3, ventilation, fire safety).
  - **`plugin-solar-considerations.html` ("Considerations")** - the largest new page: consumer unit types (old rewireable fuse board vs single whole-board RCD vs modern RCBO), why extension leads are prohibited for the kit's own connection, running air conditioning/other high loads on the same circuit, ring final circuits and the specific risk of two kits (or a kit plus heavy load) sharing one, what genuinely happens in a power cut (anti-islanding means no backup power, a common misconception), a worked explanation with a comparison table of why oversizing panels beyond 800W still helps (the inverter reaches its 800W ceiling earlier/later in the day and on duller days, rather than raising the ceiling itself), and a best-panel-placement section including two indoor/behind-glass options with sourced (if wide-ranging) glass-transmission-loss estimates.
  - **`plugin-solar-mounting.html` ("Mounting")** - where you're allowed to physically fix a panel (balcony/wall/flat roof/pitched roof/ground/fence), England's permitted development projection/height limits, and - added after the user caught its absence - a dedicated England/Scotland/Wales/Northern Ireland comparison making clear the DESNZ reform is Great Britain-only and NI has its own unamended rules and planning system, plus when planning permission or other consent (listed buildings, conservation areas, leasehold flats) is still needed regardless.
  - **`plugin-solar-registration.html` ("Registration", new)** - a step-by-step guide to registering via myplugin.solar, the one-per-household rule, what not registering means, and the Northern Ireland registration gap.
  - **`plugin-solar-calculator.html` ("Energy calculator")** - substantially rebuilt (see below).
  - **`plugin-solar-certification.html` ("Certification")** - the ENA Type Test Register, how the plug-in scheme's lighter compliance route differs from full MCS certification, CE/UKCA marking, and what "Interim" in the spec's name means.
  - A prominent "don't install more than one kit" warning box (`.ps-warning`) appears on the What-is-it, Safety, Considerations and Registration pages, tying the one-per-household G98 limit to the concrete ring-circuit-overload scenario it exists to prevent.
  - Every new page carries its own "Government & official sources" box (`.ps-gov-links`) linking the relevant GOV.UK/devolved-nation/ENA pages, not just the calculator.
- **New `assets/plugin-solar-data.js`** - a shared, documented dataset module: monthly solar irradiance (kWh/m²/day) for a 20-town/city UK location picker (sourced from a 20-year, 2001-2020 satellite irradiance climatology at sunhours.app, with several nearby towns sharing one profile exactly as the source itself does, and Belfast included as an explicitly-labelled latitude-matched estimate since Northern Ireland isn't covered by the underlying dataset), the industry-standard 0.80 performance-ratio conversion formula, six panel-placement factors (four outdoor orientations plus two indoor/behind-glass options), and the monthly generation/self-consumption model shared by the calculator page.
- **`plugin-solar-calculator.html` rebuilt** with: a location dropdown (~20 towns/cities), a panel-capacity slider (unchanged 300-2,000W range), a placement dropdown (replacing the old four-option orientation-only dropdown with six, including the two window-mounted options), an editable household base-load field (pre-filled at a typical 200W always-on estimate, per the user's request, feeding a below/above-baseload self-consumption split rather than a single flat occupancy factor), the existing daylight-occupancy dropdown, and the equipment-cost field. Results now show a full month-by-month generation/usable/saving table (with copy-as-CSV) and bar chart, annual totals, and payback compared against **both** Ofgem's price cap and Octopus Energy's Flexible tariff side by side, plus an animated sun-hours strip for the selected location and on-page explainers for the oversizing and placement points (cross-linked to the fuller write-ups on Considerations).
- **New `.ps-*` component classes in `assets/style.css`**: an animated sun/panel hero graphic (rotating rays, pulsing sun, glinting panel cells) used at the top of all eight pages; a cross-page sub-navigation tile row; the warning and government-links boxes; a four-nations comparison grid; a placement-diagram grid; a numbered step list (Registration); and an animated seasonal sun-hours bar strip - all built with existing CSS variables/keyframes so they respect the site's light/dark theme and the `prefers-reduced-motion` override already in place.

### Changed

- Pricing note: the user asked for weekly-refreshed real supplier prices "from suppliers." After flagging the direct conflict with this feature's standing "Ofgem price cap, not scraping supplier sites" rule, the user confirmed Octopus Energy's genuine public REST API (`api.octopus.energy`, confirmed keyless for product/rate GETs) as the supplier source, shown alongside the Ofgem cap rather than replacing it. What's shipped in this pass is a **manually checked, dated snapshot** of Octopus's Flexible tariff unit rate (same "state the source and the last-checked date" convention as the Ofgem constant), not a live server-side ingestion pipeline - `api.octopus.energy` is not reachable from this build environment's network (same restriction hit and disclosed for PVGIS earlier today), so a genuine weekly cron-based ingest (matching the SEMO/EIA pattern elsewhere on this site) couldn't be built and tested end-to-end in this pass. This is flagged here rather than silently shipped as if it were live; a real ingest endpoint is a reasonable next step and does not require any of this pass's frontend work to change.
- Solar irradiance data: PVGIS's live API (the original plan) is also unreachable from this build environment. Monthly solar irradiance is climatological, not truly live data (an August average doesn't change day to day the way a price or generation figure does), so it's treated the same way as this site's existing Records reference panels - a periodically-refreshable static dataset with a clear source and last-checked date - rather than a live per-visit fetch.

### Verification

- JS-syntax (`new Function`) checks on every inline `<script>` block across all eight pages plus the new shared `assets/plugin-solar-data.js`, and a tag-balance check (`<main>`/`<div>`/`<script>`) on all eight pages and sitewide.
- `php -l` across every `.php` file in the repo (unaffected by this pass, checked for regressions) and a secrets grep across `.php`/`.html`/`.js`.
- A scripted sitewide nav check confirming all 41 pages (33 existing + 8 new, `plugin-solar.html` itself now counted among the "new" 8 since its nav entry moved) carry the new "Plug-in Solar" group with the correct active/aria-current state, and that the "Great Britain" group's own active state is preserved for every page outside the new section.
- Playwright light/dark screenshots of the What-is-it, Considerations, Mounting and Energy calculator pages, confirming the calculator's dropdowns populate, the monthly bar chart and table render with the shared `GridPreview.renderBarChart` helper, and the nation-comparison and placement-diagram grids render correctly in both themes.

## 2026-09-20 (New: Plug-in solar page and savings calculator)

### Added

- **New `pages/plugin-solar.html`**, added to the "Great Britain" nav group on every page. Plug-in ("balcony") solar panel kits became legal to sell and self-install in Great Britain on 27 August 2026 - this is genuinely new, previously-uncovered subject matter for the site, not an extension of the existing live-grid-data pages. The page has four parts:
  - **What is it** - a plain-language explanation of plug-in solar and how it differs from a full rooftop PV installation (no export payment, no battery by default, self-consumption-dependent savings).
  - **The rules** - a sourced `records-list` of the confirmed technical limits (800VA/3.5A max inverter output, 2,000W max panel capacity across up to 4 panels, direct BS 1363 plug connection only, `myplugin.solar` registration, one device per household under the current G98 limit), taken from DESNZ's July 2026 government response and Interim Product Specification v2.0 (the primary regulatory source) and cross-checked against several independent installer/guide sites, which is how a factual conflict was caught and resolved: one secondary source claimed these kits must be hardwired to the consumer unit by a registered electrician, which the primary government document contradicts (compliant kits use a standard plug into an existing socket) - the page follows the primary source.
  - **Savings calculator** - a client-side JS calculator (panel capacity, orientation, and daylight-hours home-occupancy as inputs) estimating annual generation and saving. Deliberately uses Ofgem's own published price cap unit rate (26.32p/kWh, October-December 2026 cap period) as a manually-maintained constant, per the standing "no scraping supplier sites for pricing" instruction for this feature, rather than any retailer's own marketing figures - the same "state the source, state the last-checked date" convention as the site's Records sections. The calculation method (inverter-clipping derate above 800W, a 900 kWh/kWp/year UK-average yield, an orientation factor, a self-consumption factor) is disclosed in full underneath the calculator rather than presented as a precise quote.
  - **What kits actually cost** - indicative self-install vs professionally-installed price ranges, sourced and captioned as indicative rather than this site's own quotes.
- New `.compare-picker input[type="number"]`/`input[type="range"]` theming in `assets/style.css`, extending the existing `.compare-picker` select styling (from `pages/comparisons.html`) so the calculator's slider and number field match the site's dark/light theme instead of falling back to unstyled browser defaults.

### Notes

- Per instruction, no live affiliate links were added anywhere on the site for this feature - a separate research-only document on UK plug-in solar retailers' affiliate programmes (EcoFlow, Anker SOLIX, BLUETTI, UKSOL) was written and delivered directly, not published.

### Verification

- JS-syntax and HTML tag-balance checks on the new page; confirmed all 32 pages (31 existing + the new one) now carry the same nav entry via a scripted, anchor-based sitewide update, with a spot-check that the "Great Britain" nav dropdown renders it correctly.
- Manually re-derived the calculator's own arithmetic for its default inputs to confirm the displayed £/kWh/payback figures match the formula disclosed on the page.
- Cross-checked the regulatory figures against DESNZ's primary-source PDFs (the July 2026 government response and the Interim Product Specification) rather than relying solely on secondary installer-guide sites, after finding a genuine conflict between two secondary sources on the connection method.

## 2026-09-20 (Interconnector maps redrawn with real geography)

### Changed

- **All three interconnector maps site-wide are now real-geography maps, not freehand schematic diagrams.** Previously, `pages/interconnectors.html`'s GB map used plain ellipses for every country with arbitrary made-up relative positions, and `pages/americas-interconnects.html`'s two Americas diagrams were abstract labelled boxes with no geography at all. All three are rebuilt from real coastline/border data (Natural Earth 1:50m, via the `world-atlas`, `us-atlas` npm packages and the `click_that_hood` Canada-provinces dataset), Mercator/Albers-projected and cropped to the relevant region with `d3-geo`, then hand-annotated with cable/tie labels:
  - **GB interconnectors map**: real coastlines for Great Britain, Ireland, France, Belgium, the Netherlands, Denmark and Norway, with each of the nine cables drawn as a curve between its two real landing points (e.g. Sellindge↔Bonningues-lès-Calais for IFA, Blyth↔Kvilldal for North Sea Link) rather than an arbitrary line between two ellipses.
  - **North America interconnections map**: real US state, Canadian province and Mexico outlines, shaded by interconnection (Western/Eastern/ERCOT/Québec/Mexico, with a legend), with HVDC/back-to-back tie markers placed at their real locations (Rapid City SD, Oklaunion TX, the Québec-New England border, etc.) in place of the old abstract box-and-line diagram.
  - **South America interconnections map**: real outlines for Colombia, Venezuela, Ecuador, Brazil, Paraguay, Argentina, Uruguay and Chile at their true relative positions and sizes, with Peru/Bolivia/Guyana/Suriname shown greyed-out for visual continuity only (this page still doesn't cover their interconnections), and each dam/tie marked at its real border location (Itaipu, Yacyretá, Garabí, Salto Grande, Andes-Cobos, and the Colombia-Ecuador/Colombia-Venezuela ties).
- State/province-level interconnection colouring on the North America map is an approximation - the real NERC interconnection boundary cuts across a handful of border states rather than following state lines exactly - and is captioned as such, consistent with this project's standing rule of never overstating precision. Provinces/states this site's sources don't classify (Saskatchewan, Newfoundland and Labrador, the northern territories, Alaska, Hawaii) are shown as explicitly "not classified" rather than guessed into a category.
- Removed the now-unused `.tie-node`/`.tie-line`/`.tie-label-bg` inline styles from `pages/americas-interconnects.html` (replaced by shared `.americas-*`/`.map-*` classes in `assets/style.css`, also used by the GB map).
- Updated the captions on all three maps to describe what's now real (coastlines/positions) versus still simplified (cable curves are for legibility, not the true undersea route; tie markers show each corridor's real location, not every individual station).

### Verification

- `node -e` JS-syntax check and an HTML tag-balance check on both edited pages; a plain brace-balance check on `assets/style.css`.
- Rendered every map with Playwright, in both light and dark theme, against the actual site stylesheet - checked label legibility, that no text overflows the canvas, and that the light/dark `color-mix()` region colours (already an established pattern elsewhere in `assets/style.css`) stay readable in both themes.

## 2026-09-20 (SEMO imbalance price goes live; homepage type-card fix)

### Added

- **SEMO's Imbalance Price Report (`BM-025`) is now integrated as its own independent live source for Ireland**, alongside the existing EirGrid Smart Grid Dashboard and ENTSO-E day-ahead feeds. SEMO (Single Electricity Market Operator, sem-o.com) publishes a genuinely public, keyless JSON+XML "Reports API" that updates roughly every 5 minutes (ex-post, with a 15-30 minute publication lag). This is the SEM's actual settlement/imbalance price, distinct from the day-ahead price already shown via ENTSO-E - the two can and do diverge. New table `readings_ie_semo_imbalance`, new `ukgrid_ingest_semo()` in `includes/ingest.php`, new `cron/fetch_semo.php` cron entry point, wired into `tools/full-refresh.php`'s on-demand refresh, and exposed via two new fields: `api/ireland_current.php` (`semo_imbalance_price_eur_mwh`, `semo_ts`) and `api/ireland_series.php` (`semo_price` metric, EUR/MWh). Follows the site's established "independent source, independent staleness" pattern - SEMO's own `semo_staleness_minutes` config gates its freshness separately from EirGrid's, so one source's outage never masks or is masked by the other's.
- On `pages/ireland.html`: a new "SEM imbalance price" stat card with its own sparkline, and a new "SEM imbalance price per MWh" chart in the History range panel, sitting alongside the renamed "SEM day-ahead price" (formerly just "SEM price", to disambiguate the two). The `#ie-sources` explainer and the API-issue banner were both updated to describe SEMO as a live source and to explain the day-ahead-vs-imbalance distinction.
- Because the SEMO Reports API's own `sort_by=PublishTime&order_by=DESC` ordering proved unreliable under testing (two otherwise-identical fetches returned different "most recent" items), `ukgrid_ingest_semo()` re-sorts every batch itself before processing and upserts a full hour of overlapping recent periods each run, so the ingest is correct regardless of API ordering quirks.

### Fixed

- **The homepage's "Explore by energy type" cards (Fossil fuels / Renewables / Nuclear & biomass / Interconnectors / Storage) had zero live-data wiring** - all five values were static hardcoded HTML text with no JavaScript behind them, despite sitting directly below the "Generation mix right now" section that an earlier pass had already fixed to be genuinely live. This is the same "looks live, isn't" class of bug as that earlier fix, just a previously-missed instance of it. Added `id`s to the five value spans and a new `updateTypeCards()` in `index.html`, fed from the same `summarizeGeneration()` output already used elsewhere on the page (`renewableMw`/`interconnectorMw`/`storageMw` reused directly; fossil and nuclear-&-biomass computed fresh from `summary.bySource` using the same label groupings as the page's own mix table). Confirmed via a mocked `summarizeGeneration()` call in a live browser context that the new live figures exactly match the old hardcoded placeholder values, which is strong evidence the old numbers were a frozen one-time snapshot rather than ever being genuinely live.

### Changed

- Added a visible "Read more →" cue to each of the five "Explore by energy type" cards, since the cards are fully clickable but that wasn't obvious. New `.type-card__cta` styling in `assets/style.css`.

### Verification

- `php -l` on every touched PHP file; JS syntax check (`new Function()` on every inline `<script>` block) across all 31 HTML pages; HTML tag-balance check on `index.html` and `pages/ireland.html`.
- Verified live via WebFetch that SEMO's Reports API and an individual XML resource file are genuinely public and unauthenticated, with real current data.
- Verified with Playwright: the homepage's no-data state correctly shows "-" for all five type-cards; the "Read more →" cue renders correctly under each card's description.

## 2026-09-20 (copy/PNG/CSV overhaul, per-capita comparisons)

### Added

- **Every chart and donut on the site now gets a consistent "Copy as text / Download PNG / Download CSV" toolbar, formatted to paste cleanly into a comment or spreadsheet, for free - without editing the ~30+ pages that use them.** Rather than touching every individual chart call site, the toolbar is built into the four shared chart-rendering functions in `assets/app.js` (`renderLineChart`, `renderStackedAreaChart`, `renderBarChart`, `renderDonutChart`) via a new `attachChartExport(canvas, spec)`, so any page calling these functions picks up the toolbar automatically. Chart titles are derived automatically from the page's own existing heading structure (`deriveChartTitle()`); a canvas can opt out with `noExport: true` (used on `history.html`, which already had richer bespoke copy/CSV/JSON, and on any illustrative-only chart that isn't real data).
- **"Download PNG" captures exactly what's already drawn on the chart** (`downloadChartSnapshotPng()`, via `ctx.drawImage()` on the live canvas) rather than re-implementing every chart type's drawing logic a second time - so the export always matches what the visitor actually sees, including the current light/dark theme.
- **Legend text on exported PNGs is measured, not guessed** (`layoutLegendRows()`, using real `ctx.measureText()` results) so it always wraps to fit the card, whatever the category count - verified against Australia's 9-category worst case and GB's 8-category case, both cleanly wrapped with nothing clipped.
- **New "Download CSV" button added to every chart, comparison table, and records/context list sitewide**, via new generic `categoriesToCsv()`, `tableToCsv()` and `listToCsv()` helpers (plus matching `categoriesToText()`/`listToText()` for "Copy as text" on lists) wired through new `[data-csv-table]`, `[data-copy-list]`, `[data-csv-list]` attributes.
- **Records and Notable Moments**: all four of the homepage's tabbed Records lists (Wind/Solar/Emissions/Demand), the homepage's Notable Moments list, and every one of the 22 country/topic pages' "Context"/"Records" lists (23 lists in total, including South America's four per-country lists and Renewables' live-refreshed Network constraint costs list) now have "Copy as text" and "Download CSV" buttons.
- **New "Compare against" options on `pages/comparisons.html`'s Time Periods tab: "Last week", "2 weeks ago" and "Last month"**, alongside the existing season-based options (now grouped into "Recent" and "Same season" optgroups). Backed by a genuinely new backend capability - a `period_offset` parameter on `api/series.php` that shifts the whole rolling window back by whole window-lengths, generalizing the previously season-only offset mechanism - plus matching plumbing in `assets/data.js`'s `fetchSeries()`.
- **New population- and area-adjusted comparison metrics on `pages/comparisons.html`'s Countries tab**: demand per person, generation per person, and demand per km², for all 16 tracked grid zones. Each zone is scoped to the specific area its own data source actually covers, not naively "the whole country" - e.g. Canada uses Ontario only (IESO), the USA uses the lower-48 + DC only (EIA), Australia uses the NEM only (NSW/ACT/QLD/VIC/SA/TAS, excluding WA/NT), and Ireland uses the whole island (the SEM spans ROI + NI). Norway (NO2), Denmark (DK1) and Sweden (SE3) are single-bidding-zone figures rather than official whole-country statistics, and are explicitly flagged as estimates in the UI.

### Fixed

- **Discovered and fixed a real pre-existing bug**: Poland ("PL") was selectable in `pages/comparisons.html`'s two country dropdowns but missing from both label lookup objects, which would have displayed the literal string "undefined" as a table column header if selected.
- **A CSS specificity gotcha meant `hidden` could be silently overridden by an element's own class.** The browser's built-in `[hidden] { display: none }` rule and an author class that sets its own `display` (e.g. `.records-list { display: grid }`, `.download-row { display: flex }`) have equal specificity, so whichever is declared later in the stylesheet wins - which for several elements on this site was the author class, not `[hidden]`. This had been silently harmless everywhere it already existed (`#moments-list`, tab panels, etc.) because those elements happen to be empty/inert while hidden, but it became visibly broken by this change's new `#moments-download-row` (a `.download-row`, always containing two real buttons): the Copy/CSV buttons stayed visible even when Notable Moments had no data to show. Fixed with one defensive sitewide rule, `[hidden] { display: none !important; }`, added near the top of `assets/style.css` - verified with Playwright that this didn't affect any existing tab/panel toggling anywhere on the site.
- Removed the stale phrase "has been granted and is now wired up" from the ENTSO-E callout on `pages/data-sources.html` (described a past one-off grant event; now written in the present tense to match its actual ongoing state).

### Verification

- Verified via a standalone Playwright test harness (synthetic 9-category and 8-category donuts) that exported PNG legends wrap correctly with nothing clipped.
- Verified every one of the site's 31 HTML pages' inline `<script>` blocks still parse cleanly (`new Function()`) after all of the above edits, plus `node --check` on `assets/app.js` and `php -l` on `api/series.php`.
- Verified HTML tag balance (`div`/`ul`/`section`/`p`/`button`) across all 22 directly-edited country/topic pages.
- Verified rendered layout with Playwright screenshots on both directly-edited pages (Australia, France, the homepage's Notable Moments section) and the `[hidden]` CSS fix specifically (before/after, plus confirming the homepage's Records tabs and `history.html`'s range tabs still toggle correctly).
- **Fact-checked every hardcoded claim in every Records/Context list sitewide (~90 claims across 22 pages) against current sources**, using dedicated web research per region, per the request to verify these are still accurate rather than just add buttons to them. Corrected: GB's 2025 renewables generation share (was 44%, actually ~47% per Carbon Brief - the site had understated a genuine record year); AR8's CfD auction timeline (was "results expected Q3-Q4 2026", actually now expected November 2026-February 2027 per the official AR8 timeline, with a 15 October 2026 DESNZ decision point that could push it to January 2027); Spain's wind-vs-solar capacity ranking (solar overtook wind as Spain's largest installed generation technology in January 2025 - the page still said wind was largest); Belgium's Doel 4/Tihange 3 extension (tightened from vague "mid-2030s" to the actual finalised 2036 date) and offshore wind capacity (was overstated as "several GW installed" - it's just over 2GW, with more under tender); Germany's fossil gas generation share (widened from a single "~16%" to "~13-16%", reflecting genuine gross/net methodology variance in the sources); the USA's all-time demand record (still shows the correct ~759GW/summer 2025 figure, now with a note that regional records, PJM and ERCOT, were broken again in summer 2026 with no new national figure yet published); and Ireland's SNSP prose, which claimed the operating limit had already been raised to 80% when EirGrid's own DS3 programme page confirms 75% is still the current, achieved limit (rising toward a 95% target) - the 80% figure was never an implemented step. Every other claim checked (France, Italy, Netherlands, Norway, Denmark, Poland, Portugal, Sweden, Canada, all four South American countries, and GB's own wind/solar/carbon/demand/fossil/nuclear/storage/interconnector records) was confirmed accurate and its "Last checked" date bumped to 20 September 2026; a handful of GB's own granular BMRS-level daily figures (exact gas/nuclear/biomass/pumped-storage/import-export peaks) couldn't be independently corroborated against public press coverage during this pass (that data isn't well-indexed by search engines), but no evidence surfaced that any of them are wrong.

## 2026-09-20 (Australia on-demand refresh fix)

### Fixed

- **`OPENELECTRICITY` was missing from `includes/config.php.example`'s `refresh.intervals_minutes` and `refresh.timeouts_seconds` arrays**, caught immediately by `tools/full-refresh.php`'s own config-check when the previous day's Australia integration was deployed to a real config. `includes/refresh.php`'s fallback defaults (used only when a config predates the `refresh` key entirely) already had `OPENELECTRICITY` correctly added, but the shipped example - which is what an existing install's real `config.php` actually carries forward - was not updated to match, so a config built from it would only ever refresh Australia via cron or `tools/full-refresh.php`, never from an ordinary visitor's page load. Added `'OPENELECTRICITY' => 15` and `'OPENELECTRICITY' => 8` to the example's two arrays (matching `includes/refresh.php`'s existing fallback values), with a short explanatory comment on the timeout entry mirroring the site's other multi-request sources.

## 2026-09-19 (Australia goes live)

### Added

- **Australia (NEM) is now a fully live page, backed by a real Open Electricity API key.** Sourced from the Open Electricity API (formerly OpenNEM), covering AEMO's National Electricity Market: New South Wales, Queensland, Victoria, South Australia and Tasmania (NEM does not include Western Australia or the Northern Territory, which run separate markets). New `pages/australia.html` follows `pages/usa.html`'s "no illustrative fallback" convention - stats show "-"/"Not available" until real data loads or a fetch fails, never a fabricated placeholder - combined with the price/band/sparkline handling used on the ENTSO-E-style pages.
- New dedicated tables (`readings_au_demand`, `readings_au_generation`, `readings_au_price`), following the site's established convention of a dedicated table set per distinct data-source family rather than folding into the generic ENTSO-E `country_code` schema. `sql/schema.sql` uses `IF NOT EXISTS` throughout, so it's safe to re-run in full against an existing database.
- New `ukgrid_ingest_openelectricity()` in `includes/ingest.php`, plus a new `ukgrid_http_get_json_auth()` helper in `includes/http.php` for the API's `Authorization: Bearer` auth (unlike EIA's query-param key). Pulls generation via `/v4/data/network/NEM` (grouped by `fueltech_group`) and price/demand via `/v4/market/network/NEM` (grouped by region), converting the API's timezone-naive NEM-local (fixed UTC+10, no DST) timestamps to UTC for storage. Since no single "NEM price" exists - each of the five regions sets its own spot price - the headline price is a demand-weighted average across regions. The API's live responses include an extra, undocumented `battery` fueltech_group that isn't in Open Electricity's own published list; confirmed by exact arithmetic against real data that it's simply `battery_discharging - battery_charging` (a redundant net-convenience rollup), so it's deliberately skipped on ingest to avoid double-counting - `battery_charging` and `battery_discharging` are stored and reported separately instead, with `battery_charging` excluded from generation totals (it's a load, not generation) but still exposed in the API response for transparency.
- New `cron/fetch_openelectricity.php` entry point, wired into `includes/refresh.php` (on-demand refresh) and `tools/full-refresh.php` (manual full-refresh tool), matching the pattern already used for EIA/IESO.
- New `api/australia_current.php` and `api/australia_series.php` endpoints, mirroring `api/usa_current.php`/`api/usa_series.php`, plus matching client helpers in `assets/data.js` (`fetchAustraliaCurrent`, `fetchAustraliaSeries`, `fetchAustraliaMixSeries`, `summarizeAustraliaMix`).
- Australia added site-wide: nav link on all 30 other pages, a country option in `pages/comparisons.html`'s country selector and its Energy Sources tab (including a `biomass` metric alias so it lines up with that page's canonical fuel key), and updated copy on `pages/about.html`, `pages/data-sources.html` (new source-card disclosing Open Electricity's CC BY-NC 4.0 non-commercial licence) and `README-DEPLOY.md` moving Australia from "planned" to live, alongside a new `australia_staleness_minutes` config setting.
- Ingestion logic verified end-to-end against real, live NEM data fetched with a real API key (generation, demand and demand-weighted price all checked by hand against the raw API response) before this was shipped.

### Note for deployment

This feature needs two manual steps on the live server that this repo's automated tooling can't do: put the real key into the live `includes/config.php`'s `openelectricity_api_key` (never committed - `includes/config.php.example` keeps the placeholder), and run the three new `CREATE TABLE` statements (or safely re-import the whole of `sql/schema.sql`) against the live database.

## 2026-09-19 (demand/generation/chart consistency pass)

### Fixed

- **Five pages' sparkline configs still described a different, older set of demand/generation figures than the same page's own headline stats.** `assets/app.js`'s live sparkline rendering only ever reads `SPARK_METRICS[key].color` on success - the `base`/`amp`/`min`/`max` fields only feed the unreachable `drawMockSparkline()` fallback - so this had no visible effect, but it was still a genuine leftover from an earlier fix (the 2026-09-08 "EU generation mix" entry) that corrected each page's `stat-demand`/`stat-generation` and mix table but missed the matching `SPARK_METRICS` object: `pages/italy.html`, `pages/portugal.html`, `pages/spain.html` and `pages/sweden.html` all still carried France/Germany-scale sparkline bases (54.2/58.6) years after their own stats were fixed to their real, much smaller scale. `pages/ireland.html`'s price sparkline base (88) had also drifted from its own `stat-price` (92.30). `index.html`'s own sparkline bases (price/emissions/demand/generation/transfers) had similarly drifted from its current stat values. All six pages' `SPARK_METRICS` now match their own displayed figures.
- **Italy, Portugal, Spain and Sweden's "Low/Below average/Average/High/Very high" demand and generation band indicators used France and Germany's typical range (35-85GW) instead of their own.** Unlike the sparkline issue above, `GridPreview.bandFromRange()` runs on every successful live page load, not just as an illustrative fallback - so this was a real, user-facing bug: Portugal's actual ~8.5-9.0GW grid and Sweden's ~15.5-16.0GW grid would almost always have read "Low" regardless of true conditions, and Italy's/Spain's own generation figures (34.0GW) fell at or below the stated 35GW floor of their own "typical" range. Replaced with each country's own approximate typical range: Italy demand 22-58GW/generation 20-52GW, Portugal 4.5-10GW (both), Spain demand 18-42GW/generation 16-44GW, Sweden 9-27GW (both) - each page's current stat value now falls comfortably (36-82%) within its own band, matching the pattern already used correctly on Belgium, Denmark, France, Germany, NL, Norway, Poland and Ireland.
- Both issues were found and fixed with a small verification script (checking `stat-demand`/`stat-generation` against each page's mix-table sum, `SOURCE_VALUES` sum, donut `centerLabel`, `SPARK_METRICS` bases, and `bandFromRange()` low/high arguments across all 13 ENTSO-E-style pages plus the homepage), re-run clean after the fixes above.

## 2026-09-19 (Australia groundwork)

### Added

- Reserved `openelectricity_api_key`/`openelectricity_base` config slots in `includes/config.php.example` for the planned Australia (NEM) page, sourced from the Open Electricity API (formerly OpenNEM). Clearly marked as not yet consumed by any ingestion code - filling the key in doesn't activate anything yet, it just gives the setting its eventual home.
- Documented Open Electricity's CC BY-NC 4.0 (non-commercial) data licence in `pages/about.html` (both the "Source code" section and the footer) - the one source under consideration for this site with a use restriction beyond simple attribution, worth flagging ahead of building on it.

## 2026-09-19 (charts stuck on loading)

### Fixed

- **Live History-section charts across most of the site rendered real data underneath a permanent loading shimmer.** `assets/style.css`'s `.chart-wrap.is-loading::before` skeleton animation is only meant to show before a chart has anything to draw, but nothing ever removed the `is-loading` class on a *successful* render - only `GridPreview.showChartError()` cleared it, on the failure path. Checked directly on the live site: Belgium's Day-tab charts (demand/generation/price), France's price chart, all five of Ireland's History charts, and both of `comparisons.html`'s charts were confirmed stuck this way; `pages/eu.html` too. The actual data was there and correctly drawn (confirmed via a direct `renderLineChart()` test) - it was just permanently hidden under the shimmer overlay, which looks indistinguishable from "still loading" to a visitor. Root-caused to one shared function: every chart type (`renderLineChart`, `renderBarChart`, `renderStackedAreaChart`, `renderSparkline`, `renderDonutChart`) funnels through `assets/app.js`'s internal `registerChart()`, so the fix removes `is-loading` there once, right after a chart's first successful draw - covering every chart on every page in one place rather than requiring each page's own success-path code to remember it individually. GB's own `history.html` and the five GB category pages happened not to hit this (their code already cleared the class by hand), which is why it wasn't caught in the previous audit's per-page checks - this time it was found by loading the real, currently-deployed site rather than only exercising the local no-backend failure path.

## 2026-09-19 (no-illustrative-data pass, mobile menu, Poland)

### Fixed

- **The homepage and five GB category pages were showing 100% fake "live" data with no disclaimer.** `index.html`'s entire "Generation mix right now" donut/bar/table section and its "last 24 hours" stack chart were static numbers or `GridPreview.buildSeries(...)` synthetic random-walk data, despite the page's own copy calling them live. The same pattern existed on `pages/fossil-fuels.html`, `pages/renewables.html`, `pages/nuclear-biomass.html`, `pages/storage.html` and `pages/interconnectors.html` - each page's top "Current output"/"Share of generation (or demand)" stat and its Breakdown table were hardcoded, with zero live-data wiring and zero illustrative-data disclaimer, even though each page's own meta description and body copy claim to be live. All six now fetch real data (`window.GridData.fetchCurrent()` + `summarizeGeneration()`, reusing the GB homepage's existing live pipeline) and show an explicit "No data available" state - not a placeholder number - if the fetch fails.
- **Every ENTSO-E country page (Belgium, Denmark, France, Germany, Italy, Netherlands, Norway, Portugal, Spain, Sweden, Ireland, and the EU aggregate) fell back to illustrative numbers on any fetch failure instead of saying so.** `assets/app.js`'s shared `renderPsrMixSection()` silently returned instead of clearing the mix table/donut/bar on missing data, and each page's own failure branch redrew a fake sparkline (`drawMockSparkline`) rather than clearing the chart. Added a new shared `GridPreview.showNoMixData()` and wired every one of these pages' failure paths to it and to `GridPreview.showChartError(canvas, null)`, so a genuine outage now reads "No data available" everywhere instead of quietly showing stale-looking placeholder figures.
- **`pages/nl.html`'s 24-hour mix chart was synthetic data**, unlike every sibling ENTSO-E page, which already fetched real history - an isolated one-off bug, now wired to `window.GridData.fetchCountryMixSeries("NL", "day")` like the rest.
- **Five country pages had a copy-pasted "About" heading.** `pages/germany.html`, `pages/italy.html`, `pages/portugal.html`, `pages/spain.html` and `pages/sweden.html` all read "About the French grid" - fixed to name each page's own country.
- **`pages/eu.html`'s "See data sources" link pointed at an absolute production URL** (`https://ukgridlive.info/index.html#data-sources`, an anchor that doesn't exist on that page) instead of the relative `data-sources.html` link every other page uses, and its "Europe" nav item wasn't marked as the active section like every sibling country page's. Both fixed.
- **Mobile navigation menu was awkward to use.** The hamburger button rendered floating mid-way down the open menu instead of at the top, and the menu list didn't reliably go full-width. Root cause: the nav list's desktop `flex: 1` rule was winning over its mobile `width: 100%` rule (flex-basis takes priority for main-axis sizing), combined with an inherited `align-items: center`. Fixed in `assets/style.css` with an explicit `flex: 1 1 100%` and `align-items: flex-start` inside the mobile media query - verified before/after with Playwright bounding-box measurements and screenshots.

### Added

- **Poland**, as a new ENTSO-E country page (`pages/poland.html`) - Europe's most coal-dependent large grid (~52% coal in 2025) alongside a fast-growing wind/solar sector and its first offshore wind farm (Baltic Power, first power July 2026). Added to `includes/config.php.example`'s `entsoe_countries` (EIC domain `10YPL-AREA-----S`), the site nav on all 29 other pages, the `comparisons.html` country selectors, and the `eu.html` EU aggregate (Poland is an EU member state, unlike Norway). Sourced from Fraunhofer ISE/Energy-Charts (via Notes from Poland) and PSE's own reporting - see the page's own Data sources section.
- A "Coal & lignite" / "Hydro & biomass" colour-map fallback was already close but not exact for Poland's grouped categories; added a canonical `"Hydro & biomass"` entry to `assets/data.js`'s `LABEL_COLORS` so its chart gets a stable colour rather than the fallback cycle.

### Checked

- Updated ENTSO-E-related capacity math and documentation now that 12 countries are configured (was implicitly written for 6, already stale before Poland): `cron/fetch_entsoe.php`'s worst-case request count (35, was documented as 17-18) and its `set_time_limit()` (raised from 450s to 900s - the old budget was already too tight for the 11-country config this shipped with, before Poland made it more so), plus matching comments in `includes/ingest.php`, `includes/refresh.php`, `includes/config.php.example`, `tools/full-refresh.php`, `pages/data-sources.html` and `sql/schema.sql`'s stale `country_code` column comment.
- Ran three independent verification passes across every page on the site (not just the ones touched above): a Node `--check` syntax pass on every inline `<script>` block, a Python HTML-tag-balance pass, and a Playwright headless-browser smoke test of all 30 pages under simulated total backend failure (checking for JS exceptions and for "NaN"/"undefined" leaking into any visible stat, table cell or caption). All clean. Also checked every internal `.html` link on the site for a matching file - none broken - and every page's `<title>`/`<h1>`/footer attribution badge for further copy-paste mismatches beyond the "About" heading bug above - none found.

## 2026-09-08 (site-wide table/chart audit)

### Fixed

- **Homepage interconnector figure contradicted itself.** `index.html`'s "Generation mix right now" table and its "Interconnectors" type-card both showed -0.59GW (a net export), while the same page's category donut chart (`CATEGORY_VALUES`) and its live "Transfers" stat both worked from +0.20GW (a net import) - and only the +0.20GW figure actually reconciles with the page's own demand (28.9GW) and generation (28.7GW) numbers (28.7 + 0.20 ≈ 28.9). Corrected the table row and type-card to 0.20GW / 0.7%, and updated `pages/interconnectors.html` to match: its "Current output"/"Share of demand" stats now read 0.20GW/0.7% instead of -0.59GW/-2.0%, and its per-cable breakdown table (Belgium, Denmark, France, Ireland, Netherlands, Norway) has the Norway (North Sea Link) row adjusted from 0.52GW to 1.31GW so the six cables still sum exactly to the corrected total, well within that cable's 1.4GW capacity.
- **Spain, Italy, Sweden and Portugal's donut charts showed the wrong total in the middle.** Following last entry's fix to their generation-mix tables, their donut charts' `centerLabel` still read France's old "58.6GW" figure. Now show each country's correct total (Spain 34.0GW, Italy 34.0GW, Sweden 16.0GW, Portugal 9.0GW), matching the table and legend below them.
- **`pages/price-history.html` silently showed fake data.** The page's caption claims its charts are "sourced from the Elexon Insights Solution," but the page never loaded `assets/data.js` and its chart-drawing code called `GridPreview.buildSeries(...)` - the same seeded random-walk placeholder generator used before any live source exists - unconditionally, with no attempt to fetch real history and no error state if none exists. Every chart (and its Average/Lowest/Highest stats) was random fake data that changed on every reload. Now fetches `window.GridData.fetchSeries("price", range)` and falls back to "Live data unavailable for this range" when there's no live history yet, matching `pages/history.html`'s own price chart.
- **`pages/eu.html` said "5 tracked countries" in four places while actually tracking ten.** A leftover from before Germany, Spain, Italy, Sweden and Portugal were added to the page's ENTSO-E country list. Text now says "10 tracked countries" throughout, matching `EU_COUNTRIES`'s actual ten entries.
- **`pages/interconnectors.html`'s top stat was mislabelled "Share of generation."** Interconnector flow is an import/export figure, not generation - the page's own History section two hundred lines down correctly calls the same kind of figure "Share of demand." Relabelled for consistency.
- **Leftover copy-paste artifacts, no functional effect but cleaned up while auditing:** `pages/nl.html`, `pages/norway.html`, `pages/denmark.html` and `pages/belgium.html` each used the id `fr-status-banner` (left over from being cloned from `pages/france.html`) for their own illustrative-data banner; renamed to `nl-`/`no-`/`dk-`/`be-status-banner` respectively. `pages/denmark.html`'s data-sources section used `de-sources` (Germany's code); renamed to `dk-sources`. `pages/nl.html` had a malformed hidden table caption reading "Illustrative the Netherlands generation by source"; fixed to "Illustrative Netherlands generation by source". `pages/south-america.html` and `pages/americas-interconnects.html` both still carried the homepage's "Great Britain's electricity grid, live and over time" tagline; each now has its own.

### Checked

- Audited every remaining table and chart on the site (all EU/Nordic pages, `index.html`'s own generation-mix section, the Americas pages, `comparisons.html`, `pages/history.html`, `pages/electricity-prices.html`, and the GB category pages' non-Records tables/charts) for the same class of copy-paste, mismatched-array or broken-canvas-id issues found in the previous entry. `pages/electricity-prices.html`'s bill-breakdown bar chart (£162/£63/£18/£65, summing to £308 against a stated £324 total) was flagged for a mismatch but checked against Carbon Brief's own May 2025 source analysis - the £16 gap exists in Carbon Brief's own figures too, and the page's caption already says so; no change needed. A small (~2 percentage point) rounding overshoot when comparing "share of generation" headline percentages *across* the five separate GB category pages (renewables/fossil/nuclear-biomass/storage) was also noted - each page's own breakdown table is internally consistent with its own header figure, so this looks like accumulated rounding in hand-typed illustrative snapshot numbers rather than a copy-paste error, and was left as-is.

## 2026-09-08 (EU generation mix)

### Fixed

- **Six EU country pages had a broken, copy-pasted "Generation mix right now" table.** `pages/germany.html` was showing large nuclear generation despite Germany completing its nuclear phase-out in April 2023 - closer inspection found the same illustrative mix table (originally built for France, whose 56.3% nuclear share it still carried) had been copy-pasted onto `pages/italy.html`, `pages/spain.html`, `pages/sweden.html`, `pages/portugal.html` and `pages/belgium.html` as well, each keeping France's nuclear percentage or a rough guess unrelated to that country's real mix, and four of them (Italy, Spain, Sweden, Portugal) also still carried France's `stat-demand`/`stat-generation` figures (54.2GW/58.6GW) even though none of those grids run anywhere near that scale. Rebuilt each table, its `SOURCE_LABELS`/`SOURCE_VALUES` chart arrays, and (for Italy, Spain, Sweden, Portugal) the demand/generation stats from current per-country generation-mix data: Germany now shows no nuclear at all (phased out); Italy and Portugal show no nuclear (Italy since a 1987 referendum, Portugal never had commercial nuclear); Spain, Sweden and Belgium keep their real, smaller nuclear shares (17.9%, 25.6% and 20.0% respectively) instead of France's. `pages/france.html` itself was already correct and is unchanged in substance - only re-verified. Every corrected page keeps the site's convention that the mix table's total equals its own `stat-generation` figure (checked with a script, not by hand).

## 2026-09-04 (repository)

### Checked

- **GB Records panels re-verified.** Checked `index.html`'s four Records tabs (wind, solar, emissions, demand) and the Records panels on `pages/storage.html`, `pages/renewables.html`, `pages/nuclear-biomass.html`, `pages/fossil-fuels.html` and `pages/interconnectors.html` against NESO, Elexon BMRS, the Carbon Intensity API, and press coverage (RenewableUK, Edie, Solar Power Portal, pv magazine). No figures changed - every headline record (23,880MW wind, 25 Mar 2026; 15,427MW solar, 12 Jul 2026; the 39g/kWh carbon-intensity floor; the now-settled winter 2025/26 demand peak) is still current. All "Last checked" dates bumped to 4 September 2026. Note: the narrower "this year" BMRS superlatives (highest Dinorwig discharge, highest/lowest nuclear/biomass/gas, highest interconnector flows) had no specific press coverage to confirm against either way and were left unchanged rather than guessed - see the commit message for the full reasoning.

### Added

- **NESO TEC Register cited as a source.** `pages/renewables-plans.html`'s grid connection queue section now links directly to NESO's [Transmission Entry Capacity Register](https://www.neso.energy/data-portal/transmission-entry-capacity-tec-register/tec_register) - the public, project-by-project dataset behind the queue figures discussed there (2,201 entries as of this check). Also added as its own source-card on `pages/data-sources.html`'s Great Britain grid, marked clearly as a reference link rather than a live-polled source - NESO's own register warns that summing the raw file double-counts staged/multi-technology projects, so the existing 770GW headline figure still cites NESO/Ofgem's own reported number rather than a total derived here.
- **GitHub repo linked from the About page.** `pages/about.html` has a new "Source code" section pointing at this repository. Confirmed no separate open-source licence is needed - the only redistribution terms that apply are the data APIs' own (Elexon's BMRS attribution, already in every footer); `README.md`'s License section now says so directly instead of suggesting a `LICENSE` file might be added.

## 2026-08-23 (v59)

### Changed

- **Brazil, Argentina, Chile and Colombia consolidated into a single `pages/south-america.html`.** None of the four had live data wired up (all four were illustrative/context-only, per `pages/data-sources.html`), so rather than maintain four near-identical standalone pages they're now one page with an in-page section per country (`#brazil`, `#argentina`, `#chile`, `#colombia`). Every page's Americas nav dropdown was updated to match, and `data-sources.html`'s per-country source links now point at the matching section anchor instead of a standalone page.

### Fixed

- **Broken Americas nav links on the homepage.** `index.html`'s Americas dropdown (USA, Canada, South America, Interconnections) had lost its `pages/` path prefix in the same update that introduced the South America consolidation above - every other nav entry on `index.html` correctly keeps the prefix since `index.html` sits at the site root, so as shipped this would have 404'd all four of those links from the homepage. Caught and fixed before this reached the repository.

## 2026-08-23 (v57)

### Fixed

- **IESO (Canada) never refreshed without cron.** `cron/fetch_ieso.php` and `ukgrid_ingest_ieso()` already existed, but `IESO` was missing from `refresh.intervals_minutes` and `refresh.timeouts_seconds` in both `includes/config.php.example` and `includes/refresh.php`. On-demand refresh (the default, no-cron mode described in `README-DEPLOY.md`) silently never considered IESO overdue, so an install without cron access would have permanently stale Canada data with no error anywhere to point at it. Both files now include `'IESO' => 30` (minutes) and `'IESO' => 7` (seconds), and `includes/refresh.php`'s on-demand switch gained the missing `case 'IESO':` to actually call `ukgrid_ingest_ieso()`.
- **Inconsistent ENTSO-E country count in comments/log messages.** `includes/config.php.example`'s prose and the log line in `includes/ingest.php` (`ukgrid_ingest_entsoe()`) still described only five ENTSO-E-sourced country pages (France, Netherlands, Belgium, Norway, Denmark) even though `entsoe_countries` has included five more comparison-only countries (Germany, Spain, Italy, Sweden, Portugal) for a while. Both now correctly describe all ten.

### Added

- **Config-check panel on `tools/full-refresh.php`.** Before running anything, the manual refresh tool now checks the live `includes/config.php` for the two gaps above (and their general form) and displays what it finds:
  - `entsoe_api_token` left unset (`CHANGE-ME` or blank)
  - `eia_api_key` left unset
  - any of the ten expected ENTSO-E countries missing from `entsoe_countries`
  - any on-demand-refreshable source missing from `intervals_minutes` and/or `timeouts_seconds`
  - `refresh.on_demand` left off with no indication cron is configured instead

  A green "config check passed" line shows when nothing is wrong. This is meant to catch the next version of the IESO-style gap at a glance, on a page the operator already visits, rather than as silent missing data with no error to find.
- **Tighter, documented IESO timeout in the manual tool.** `tools/full-refresh.php`'s per-source timeout for IESO dropped from 15s to 8s, with a comment explaining why: `ukgrid_ingest_ieso()` makes two sequential HTTP calls (worst case ~16s already), IESO's servers are further from a UK host than this project's other sources, and a hang risks tripping a host-level proxy timeout that PHP can't catch or report on - so the tighter figure leaves more headroom under that unknown ceiling.

### Repository

- First public push of the project to GitHub, with `includes/config.php` and `tools/.htpasswd` excluded (see `.gitignore`) since both carry deployment-specific secrets.
