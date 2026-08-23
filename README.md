# UK Grid: Live+

A live dashboard for Great Britain's electricity grid - price, demand, emissions, generation mix and cross-border transfers, with dark mode and history from a day to a decade. It also carries live-wired pages for Ireland and the USA, plus illustrative comparison pages for several other European and American countries.

Live at [ukgridlive.info](https://ukgridlive.info).

## What it does

- **Homepage status strip and History charts** - real-time price, demand, emissions, generation and transfer data for Great Britain, wired to Elexon, the Carbon Intensity API and NESO, with automatic fallback to illustrative data if the backend has nothing yet.
- **Ireland page** - demand, generation, wind and GB interconnection figures from EirGrid's Smart Grid Dashboard, plus SEM price and generation-mix data from ENTSO-E.
- **USA page** - demand, generation and interchange from the EIA (optional, needs a free API key).
- **France, Netherlands, Belgium, Norway, Denmark, Germany, Spain, Italy, Sweden, Portugal** - price/generation/demand from ENTSO-E where configured.
- **Topic pages** - storage, renewables, nuclear & biomass, fossil fuels, interconnectors, CfD auction history, and country-vs-country comparisons.
- **Records/Context panels** - hand-researched, periodically re-verified facts (e.g. "highest wind generation ever") that sit alongside the live data rather than being computed from it.

Every live-data page keeps the site's core convention of never overstating precision or live-ness: pages without a confirmed, granular public source stay clearly framed as illustrative rather than presented as real-time.

## How it's built

A static frontend (plain HTML/CSS/JS, no build step or framework) backed by a small PHP/MySQL layer that polls public grid-data APIs on a schedule and stores real history.

```
index.html, pages/, assets/   the site itself
api/                           JSON endpoints the frontend reads (current snapshot + historical series, per country)
cron/                          scheduled fetchers, one per data source (Elexon, Carbon Intensity, NESO, EirGrid, ENTSO-E, EIA, Open-Meteo, plus a notable-moments recompute)
includes/                      config loading, DB access, the shared ingest/refresh logic
sql/schema.sql                 the database structure
tools/                         a password-protected manual "run all fetchers now" page for the site operator
```

Frontend and backend talk over a couple of small JSON adapters (`assets/data.js`'s `GridData.fetchCurrent()` / `fetchSeries()`), both of which resolve to `null` on any failure so every page keeps working - falling back to illustrative data - even before the backend has ever run.

The backend supports two ways of staying fresh: an on-demand mode where an ordinary page load refreshes whichever source is overdue (no cron access required), or traditional cron jobs for fixed-schedule updates. Both can run together. See `README-DEPLOY.md` for the full setup.

## Data sources

All keyless and public except EIA and ENTSO-E, which need a free registered API key/token:

| Source | Powers |
|---|---|
| Elexon Insights Solution | GB generation mix, price, demand |
| Carbon Intensity API | GB carbon intensity, generation-mix cross-check |
| NESO Data Portal | GB embedded solar/wind, network constraint costs |
| EirGrid Smart Grid Dashboard | Ireland demand, generation, wind, GB interconnection, emissions |
| ENTSO-E Transparency Platform | Ireland SEM price + mix gap-fill; France/Netherlands/Belgium/Norway/Denmark/Germany/Spain/Italy/Sweden/Portugal price, generation, demand |
| EIA (US Energy Information Administration) | USA demand, generation, interchange |
| Open-Meteo | Wind speed / cloud cover context on the History page |

See `pages/data-sources.html` for exactly which figure on the site comes from which source, and why some panels are still illustrative.

## Deployment

Written for shared hosting (cPanel/Plesk, no SSH assumed) - see [`README-DEPLOY.md`](README-DEPLOY.md) for the full step-by-step: create the database, import `sql/schema.sql`, upload the files, fill in `includes/config.php` from `includes/config.php.example`, and choose on-demand or cron-based refresh.

`includes/config.php` (real database credentials and API keys) and `tools/.htpasswd` (the operator-tools login) are deployment-specific and intentionally left out of this repository - see `.gitignore`.

## License

No license file is currently included; all rights reserved by default. Add a `LICENSE` if you want to make reuse terms explicit.
