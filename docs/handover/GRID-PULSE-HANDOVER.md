# Grid Pulse: handover for a new chat

**Status:** UK Grid: Live+ (ukgridlive.info, GitHub repo TheMightyTRex/UKGridLive) is deprecated. It is moving to **Grid Pulse** at **gridpulse.info**, in a new public GitHub repo. No more changes go into the old site or repo apart from the redirect and a "moved" note.

This document has three parts:

- **Part 1:** steps for Dave to do himself, before starting the new chat.
- **Part 2:** a briefing to paste into the new chat as the first message.
- **Part 3:** the task list for the new chat, in order.

The folder this file came in also holds:

- `logo/`: the new Grid Pulse logo ("Grid and pulse": a grid of dots with a yellow pulse running through it and one green "live" node), in the site's existing colours, as SVG and PNG.
- `logo-ideas/`: three other new directions (pulse bars, pulse tile, plug and pulse), in case Dave prefers one.
- `old-site-redirect/`: the files that forward ukgridlive.info to gridpulse.info.
- `country-features-wip.patch`: unfinished work for the country pages, to finish after the move.

---

## Part 1: Dave's steps

Never paste passwords, API keys, the cron secret or the contents of `includes/config.php` into a chat. Copy them yourself.

### 1.1 Create the public GitHub repo

1. On GitHub, go to New repository. Name it `GridPulse`, owner TheMightyTRex, set it to **Public**, and leave "Add a README", .gitignore and licence **unticked**. An empty repo accepts the existing history.
2. Push the current code to it, with its full history, from the existing local copy (`GitHub\old\UKGridLive`, which matches UKGridLive57):

   ```powershell
   cd "C:\Users\david\OneDrive\Documents\Design\Industrial excitement\GitHub\old\UKGridLive"
   git remote add gridpulse https://github.com/TheMightyTRex/GridPulse.git
   git push gridpulse main
   ```

3. Add this handover folder to the new repo, so the new chat can read it straight from GitHub:

   ```powershell
   git clone https://github.com/TheMightyTRex/GridPulse.git "C:\Users\david\OneDrive\Documents\Design\Industrial excitement\GitHub\GridPulse"
   cd "C:\Users\david\OneDrive\Documents\Design\Industrial excitement\GitHub\GridPulse"
   mkdir docs\handover
   robocopy "..\GridPulse-move" docs\handover /E
   git add docs/handover
   git commit -m "Add Grid Pulse handover notes, logo and unfinished country-page work"
   git push
   ```

   The repo is public, so these files will be public too. They don't contain any secrets: I checked the whole history of the old repo and no real keys or passwords were ever committed.

4. Use the new `GitHub\GridPulse` folder from now on.

### 1.2 Set up gridpulse.info on Fasthosts

1. Add gridpulse.info to your Fasthosts hosting. The simplest option is the same hosting package as ukgridlive.info, so it can use the same database.
2. Turn on an SSL certificate for gridpulse.info and www.gridpulse.info, and wait until https://gridpulse.info loads. Do this **before** any redirect.
3. Upload the site files (from the new repo, or the latest zip) to gridpulse.info's web folder, including the root `.htaccess` and the `errors/` folder.
4. Copy `includes/config.php` from the old site's web folder to the new one using File Manager or FTP. Don't change it if you're keeping the same database (see 1.3).

### 1.3 Move the data

**Option A (recommended): same Fasthosts package, same database.**
Nothing to export. The new site's copied `config.php` already points at the existing database, so all history carries straight over. The old and new sites briefly share one database, which is safe: every write is an "insert or update" on the reading's own time.

**Option B: different hosting or a new database.**

1. **Export from the old database.** In the Fasthosts control panel, open the old site's MySQL database and launch phpMyAdmin. Select the database, then go to **Export** and choose **Custom**. Tick all tables. Choose **SQL** format and **gzipped** compression, then click **Export**. This saves a `.sql.gz` file to your PC.
   - `ingest_log` and `daily_summary` can be left out if the file is too big. The site rebuilds both.
   - Do this close to the switch-over time. Readings from the hours between the export and the switch are fetched again automatically, because each source looks back up to 24 hours.
2. **Create the new database** for gridpulse.info in the Fasthosts control panel. Note its name, user and password yourself.
3. **Import into the new database.** Open the new database in phpMyAdmin, go to **Import**, choose the `.sql.gz` file and click **Import**.
   - If phpMyAdmin refuses the file because it's too large, ask the new chat to build a step-by-step importer (task 3.2 covers this).
4. **Update the new site's settings.** Edit the new site's `includes/config.php` so the `'db'` section has the new database's host, name, user and password.

### 1.4 Point the scheduler at the new site

1. **GitHub Actions secrets.** In the new GridPulse repo, go to Settings, then Secrets and variables, then Actions. Add `UKGRID_SITE_URL` = `https://gridpulse.info` and `UKGRID_CRON_SECRET` = the same value as `cron_http_secret` in config.php.
2. **Turn off the old workflow.** In the **old** UKGridLive repo, go to Actions, choose "Refresh data" and click **Disable workflow**. Otherwise it will keep calling the old address.
3. **Docker (if used).** In `docker/.env`, set `SITE_URL=https://gridpulse.info`.

### 1.5 Forward the old site, and mark it as moved

Do this only once https://gridpulse.info works.

- **If ukgridlive.info has its own web folder:** upload `old-site-redirect/.htaccess` and `old-site-redirect/index.html` to that folder's root, replacing what's there.
  - Every old address then forwards permanently (301) to the same page on gridpulse.info.
  - `index.html` is a "UK Grid: Live+ is now Grid Pulse" page, shown only if the host ever stops the forwarding from working.
- **If both domains share one web folder:** leave the files alone, and let the new chat add the two forwarding lines to the site's own `.htaccess` (task 3.1).

Keep paying for ukgridlive.info for at least a year, so old links and search results keep forwarding.

### 1.6 Analytics (Matomo)

The tracking code points at `www.ukgridlive.info/stat/`.

1. In Matomo, add gridpulse.info as a URL for the same site, or create a new site.
2. Tell the new chat whether Matomo will stay on the old domain or move to gridpulse.info, so it can update the tracking address.

### 1.7 Start the new chat

1. Start a new chat and connect GitHub, with **TheMightyTRex/GridPulse** added to the session's repositories, so Claude can push directly.
2. Connect the `GitHub\GridPulse` folder too, if you'd like Claude to see local files.
3. Paste Part 2 below as the first message.

---

## Part 2: briefing for the new chat (paste this)

> You're continuing work on **Grid Pulse** (gridpulse.info), formerly **UK Grid: Live+** (ukgridlive.info, now deprecated). The code is in the public GitHub repo **TheMightyTRex/GridPulse**, which you can push to directly. Read `docs/handover/GRID-PULSE-HANDOVER.md` first, then work through its Part 3 task list in order. Ask me before anything risky.
>
> **About the site**
>
> - It's PHP 7.4+ and MySQL on Fasthosts shared hosting. There's no SSH and no cron, and web requests are cut off at about 60 seconds.
> - Data refreshes in two ways:
>   - on page loads (`includes/refresh.php`), one overdue source at a time, under MySQL locks;
>   - from a scheduler (GitHub Actions every 5 minutes, or the Docker container in `docker/`), which calls `cron/run-due.php`. That page has a 45-second budget, a hard stop on outgoing requests (`ukgrid_http_set_deadline()`), and runs ENTSO-E first.
> - Every write is INSERT ... ON DUPLICATE KEY UPDATE.
> - Sources:
>   - Elexon, NESO and the Carbon Intensity API (Great Britain);
>   - EirGrid and SEMO (Ireland);
>   - the ENTSO-E Transparency Platform (12 European countries);
>   - EIA (USA);
>   - IESO (Ontario);
>   - Open Electricity (Australia, CC BY-NC 4.0).
> - `includes/config.php` holds the secrets. It's gitignored and never goes in a zip or a commit.
> - The page header, menus and Display panel are written into every page by `tools/apply_shell.py` (`python3 tools/apply_shell.py`). Edit the menu there, and bump `VERSION` there for cache busting.
> - Error pages are in `errors/`. HTML tools pages call `ukgrid_html_errors()` (`includes/error_page.php`). `tools/full-refresh.php` and the other tools pages are password-protected (`tools/_auth.php`).
> - Every chart already has Copy as text, Download PNG and Download CSV buttons (`attachChartExport` in `assets/app.js`).
>
> **Rules (from Dave)**
>
> - Never ask for, take or type passwords, API keys or secrets. Dave enters them himself.
> - Use government or industry sources (system operators, regulators, statistics agencies). Supplier and company websites are fine. Third-party sites (Wikipedia, news aggregators, blogs) are not. If unsure, ask.
> - No made-up, illustrative or placeholder data. When a figure isn't available, say so clearly. Hand-checked facts carry a "Last checked" date.
> - Use plain hyphens, never em-dashes.
> - Write in plain English with short sentences.
> - Delivery: commit with a CHANGELOG.md entry and push to GitHub. Also give Dave a zip for uploading (built with `includes/config.php`, `includes/battery_units.json` and `docker/.env` excluded) and say which folders to upload. He uploads the whole site, because partial uploads have caused errors before.
> - Test with a local PHP server and MariaDB with test data before shipping. Take Playwright screenshots on desktop and mobile, in light and dark mode.

---

## Part 3: task list for the new chat

### 3.1 Move and rebrand (do first)

1. **Rebrand UK Grid: Live+ to Grid Pulse everywhere.** This covers:
   - page titles, meta descriptions, the header brand (in `tools/apply_shell.py`) and the footer;
   - the About page: add "Grid Pulse was formerly UK Grid: Live+", and keep the credit to Kate Morley's National Grid: Live;
   - README, docs, CSV and PNG file names (currently `ukgridlive-...`), snapshot card titles, the GitHub workflow name and the Docker User-Agent.

   Keep the `ukgrid_` PHP function prefix and the `UKGRID_` secret names, so nothing breaks.
2. **Logo.** Use `docs/handover/logo/` (or the `logo-ideas/` direction Dave picks). It's a completely new logo; only the colours carry over.
   - Replace the inline header mark in `apply_shell.py`.
   - Replace `assets/logo.svg`, the favicon (`gridpulse-favicon.svg`) and the apple-touch icon (`gridpulse-apple-touch-icon-180.png`).
   - Add a social share image, with an `og:image` tag on each page.
   - Give the header mark a light animation in the spirit of the old one: the pulse line draws in, then the green node pings. Respect "Reduce motion".
   - On light backgrounds, "PULSE" sits in a yellow box with dark text (`gridpulse-logo-on-light.svg`), because yellow text on white is too faint to read.
3. **Domain.** Change every `ukgridlive.info` address to `gridpulse.info`. That includes the Matomo URL (once Dave confirms) and any email addresses. The GitHub links change from UKGridLive to GridPulse.
4. **Redirect support:**
   - On the new site, show a one-off, dismissible banner when the address has `?from=ukgridlive`: "UK Grid: Live+ is now Grid Pulse. Update your bookmarks." Remove `from` from the address with `history.replaceState`.
   - If both domains share one web folder, add to the root `.htaccess`:

     ```
     RewriteEngine On
     RewriteCond %{HTTP_HOST} ^(www\.)?ukgridlive\.info$ [NC]
     RewriteRule ^(.*)$ https://gridpulse.info/$1?from=ukgridlive [R=301,L,QSA]
     ```

   - Add `<link rel="canonical">` with gridpulse.info addresses to every page.
5. **Deprecation notices.**
   - Ask Dave to give this session access to the old UKGridLive repo, then add a notice at the top of its README: "Deprecated: moved to Grid Pulse, https://gridpulse.info and https://github.com/TheMightyTRex/GridPulse".
   - Make sure the old site forwards (part 1.5).
6. **Check the move.** Confirm with Dave that:
   - every page loads at gridpulse.info;
   - the GitHub Actions run passes against the new address;
   - old addresses forward (for example `ukgridlive.info/pages/france.html` goes to `gridpulse.info/pages/france.html`).

### 3.2 Data tools (only if needed)

- If Dave's database export is too big for phpMyAdmin (part 1.3, Option B), build `tools/import-data.php`. It should be password-protected and import a `.sql.gz` that Dave uploads by FTP, a chunk per page load. It should remember its position, so it can resume after the 60-second limit, and show progress.
- Also useful: `tools/export-data.php`, which streams a gzipped SQL dump of the site's tables for backups.

### 3.3 Finish the country-page work (started, not shipped)

Dave asked for these on 1 November 2026, after comparing with electricitylive.com (a GB-only site, used for ideas only, never as a data source). He wants everything below **except the wind curtailment map**.

**The standard:** the **Great Britain home page** (`index.html`) is the template every country page follows, as closely as each country's data allows. That means:

1. a hero with "X% of [country]'s power is renewable right now", a live "updated" pill and a one-line summary card;
2. the status grid (price, carbon where available, demand, generation, net imports), each with a 3-hour sparkline;
3. "Copy as text", "Download CSV" and "Download snapshot (PNG)" under the figures;
4. "Generation mix right now", with the two donuts (by source and by category) and the mix table;
5. the home cards ("Today so far" and records);
6. the 24-hour stacked mix chart;
7. history;
8. explore by type, records and notable moments, where they apply.

Each country page keeps its own intro text, About section, "Records & context" and data sources.

**Already written, not deployed or tested.** It's in `docs/handover/country-features-wip.patch`, based on UKGridLive57 (commit 0f64ebc). Apply it with `git apply docs/handover/country-features-wip.patch` and review it. It contains:

- **`includes/areas.php`:** one view of every grid.
  - It turns each source (Elexon, ENTSO-E, EirGrid, EIA, IESO, Open Electricity) into the same buckets: generation by fuel group, demand, price, carbon and net imports.
  - Local-time day bounds handle clock changes.
  - It works out day summaries (energy by fuel, renewable, zero-carbon and fossil shares, demand peak and low, price range, imports and the share made at home) and each day's extremes.
  - `ukgrid_compute_daily()` (the DAILY job, every 30 minutes, database only) updates today and yesterday, then backfills older days, 30 per run.
  - `ukgrid_area_records()` works out records from the daily summaries: the best of each, the record it beat, each year's best and recent breaks.
  - Definitions:
    - renewable includes biomass, except for GB, where it has always been counted as non-renewable;
    - zero-carbon never includes biomass;
    - whole-day records need 90% of the day covered;
    - recent record breaks are only counted from 30 days after recording started.
- **`includes/flows_annual.php`:**
  - **FLOWS** (scheduler only): ENTSO-E cross-border physical flows (document A11), for 36 borders between the European countries and their neighbours. It fetches one border per run, oldest first, and stores to `readings_flow`.
  - **ANNUAL** (scheduler only): yearly generation by fuel, from official sources:
    - Eurostat nrg_bal_peh for the European countries and Ireland (whole countries, so Norway, Sweden and Denmark aren't just their price zones, and Ireland is the Republic only);
    - the NESO historic generation mix for GB;
    - EIA operational data for the USA;
    - IESO yearly reports for Ontario;
    - Open Electricity for Australia.

    It fetches one area per run, refreshes every 30 days and stores to `annual_mix`.
  - The `fetch_state` table tracks both jobs.
- **New API endpoints:** `api/day.php`, `api/archive.php`, `api/records.php`, `api/flows.php`, `api/annual.php` and `api/areas.php`.
- **`tools/data-jobs.php`:** shows each job's state and what each source returned, with "Fetch now" buttons.
- **Changes to existing files:** `includes/refresh.php` (requires the new files and adds DAILY, FLOWS and ANNUAL) and `cron/run-due.php` (adds the FLOWS and ANNUAL intervals and their worst-case costs).

**Caution.** None of the outside formats (Eurostat JSON-stat codes, EIA fuel type IDs, the IESO yearly file names, Open Electricity's `interval=1y`, NESO historic mix column names) could be checked from the old chat, because those sites were blocked there. After deploying:

- check `tools/data-jobs.php` and fix any parser that doesn't match;
- check the yearly totals against each source's own published figures before showing them;
- check that ENTSO-E accepts the Italy control area (`10YIT-GRTN-----B`) for physical flows.

**Still to do:**

1. Apply the patch. Test the backend against seeded local data: day summaries, archive, records, flows and the clock-change days (25 October and 29 March).
2. **Today so far** card on every country page and GB: peak, low and average demand with times, renewable and zero-carbon share, net imports, share made at home, average price and largest source.
3. **Records** on every country:
   - a board of records grouped by type, each with its value, time and the record it beat;
   - a "best of each year" grid, shaded by closeness to the record, with the record ringed;
   - a "broken most recently" list.

   Label them all "since this site started recording [area] on [date]".
4. **Day archive** (`pages/archive.html?area=XX`): a calendar per year with each day shaded by renewable share, plus a list by month (largest source, renewable share, demand and price). Each day links to `pages/day.html?area=XX&date=YYYY-MM-DD`.
5. **Day page:**
   - stacked generation for that day, demand, price, carbon where available and net imports;
   - summary figures and any records set that day;
   - previous and next day links, and a CSV of the day.
6. **Replay the day:** a slider under the mix donuts that redraws the donut and bars at any half hour (or hour) of today, from `api/day.php`.
7. **Cross-border flow cards:**
   - for GB, one per country, with the cables named;
   - for Ireland, the flow with GB;
   - for each European country, one per neighbour.

   Each card shows the live flow and direction, today's energy in and out, and a small in/out chart on a shared scale. Say when each border was last updated (ENTSO-E borders update every few hours).
8. **Long-term history:** a yearly stacked chart by fuel (share and GWh), from `api/annual.php`. Show what each source covers, and label the current year as "so far". Credit each source.
9. **Copy link** on every chart toolbar. The link reopens the same view (range tab and chart anchor, for example `?range=week#history-heading`), so pages must read the range from the address on load.
10. **CSV attribution:** each chart CSV should start with source and licence lines. Australian data must carry Open Electricity's CC BY-NC 4.0 note.
11. **Rebuild the 12 ENTSO-E country pages from one template** (a script like `apply_shell.py`), keeping each page's own text. Then bring Ireland, the USA, Canada and Australia into the same order, and add the new sections to the GB home page.
12. **Update the documentation:**
    - `pages/data-sources.html`: Eurostat, EIA yearly, IESO yearly, NESO historic mix and ENTSO-E flows, each with licence and attribution;
    - `sql/schema.sql`: `daily_summary`, `readings_flow`, `annual_mix` and `fetch_state`;
    - `README-DEPLOY.md` and `CHANGELOG.md`.
13. **Test and ship.** Seed local data, then:
    - take Playwright screenshots on desktop (1280) and mobile (390), in light and dark mode;
    - run the axe accessibility check;
    - check that no page shows made-up figures when data is missing;
    - check the 60-second scheduler budget still holds.

    Then commit and push, and give Dave the zip and upload notes.

### 3.4 Older items still open

- PVGIS for the plug-in solar calculator. Dave runs `pvgis/get-pvgis.ps1` (in `GitHub\old\pvgis`) on his PC, then the calculator switches to the PVGIS figures.
- Replace the remaining third-party citations site-wide (Wikipedia and Carbon Brief on some pages, and edie and Solar Power Portal) with official sources.
- Check the battery unit list on `tools/battery-units.php`.
- The Docker scheduler, if Dave wants a second caller alongside GitHub Actions.
