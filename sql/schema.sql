-- ==========================================================================
-- UK Grid: Live+ - MySQL schema
-- Import this via phpMyAdmin (or `mysql -u user -p dbname < schema.sql`)
-- into the database you created for this site. Safe to re-run: every
-- CREATE TABLE uses IF NOT EXISTS.
-- ==========================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Half-hourly (or better) generation by fuel type, in megawatts.
-- fuel_type holds the Elexon fuel-type code (CCGT, COAL, NUCLEAR, BIOMASS,
-- WIND, NPSHYD, PS, OIL, OTHER, INTFR, INTIRL, INTNED, INTEW, INTNEM,
-- INTELEC, INTIFA2, INTNSL, INTVKL, INTGRNL) plus the two synthetic codes
-- this site adds for embedded (distribution-connected) generation that
-- Elexon does not directly meter: SOLAR_EMBEDDED and WIND_EMBEDDED.
CREATE TABLE IF NOT EXISTS readings_generation (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL COMMENT 'UTC settlement period start',
  fuel_type VARCHAR(20) NOT NULL,
  mw DECIMAL(9,2) NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'ELEXON' COMMENT 'ELEXON, NESO or CARBON_INTENSITY',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts_fuel (ts, fuel_type),
  KEY idx_ts (ts),
  KEY idx_fuel_ts (fuel_type, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- GB day-ahead wholesale price (Elexon MID dataset), one row per settlement
-- period per reporting exchange (APXMIDP is the primary one in use).
CREATE TABLE IF NOT EXISTS readings_price (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL,
  price DECIMAL(8,2) NOT NULL COMMENT '£/MWh',
  provider VARCHAR(20) NOT NULL DEFAULT 'APXMIDP',
  volume DECIMAL(10,2) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts_provider (ts, provider),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Transmission System Demand Outturn (Elexon ATL dataset), MW.
CREATE TABLE IF NOT EXISTS readings_demand (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL,
  mw DECIMAL(9,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts (ts),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Carbon intensity, gCO2/kWh (Carbon Intensity API).
CREATE TABLE IF NOT EXISTS readings_emissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL,
  actual_gco2 INT NULL,
  forecast_gco2 INT NULL,
  intensity_index VARCHAR(20) NULL COMMENT 'very low / low / moderate / high / very high',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts (ts),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per generation-mix percentage snapshot from the Carbon Intensity
-- API's /generation endpoint. Used as a cross-check / fallback source for
-- splitting embedded solar and wind out of total demand when the NESO
-- Data Portal ingestion can't resolve a usable resource.
CREATE TABLE IF NOT EXISTS readings_mix_pct (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL,
  fuel VARCHAR(20) NOT NULL,
  pct DECIMAL(5,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts_fuel (ts, fuel),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Ireland (all-island, EirGrid + SONI system) readings. Source: EirGrid's
-- Smart Grid Dashboard (smartgriddashboard.com), region=ALL (Republic of
-- Ireland + Northern Ireland combined). Public under EirGrid Group's Open
-- Data Licence, which requires the attribution "Supported by EirGrid Group
-- Data" wherever this data is shown - see pages/data-sources.html and every
-- page's footer.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS readings_ie_demand (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL COMMENT 'UTC',
  mw DECIMAL(9,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts (ts),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per (ts, category). category is TOTAL (generationactual),
-- WIND (windactual), or INTERCONNECTION (net flow with GB - positive means
-- importing). There's no confirmed public breakdown finer than this (e.g.
-- individual gas/solar/hydro figures) via the keyless API, so the mix
-- table/donut on pages/ireland.html isn't live-wired yet even though these
-- headline figures are - see includes/ingest.php's ukgrid_ingest_eirgrid().
CREATE TABLE IF NOT EXISTS readings_ie_generation (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL COMMENT 'UTC',
  category VARCHAR(20) NOT NULL COMMENT 'TOTAL, WIND, or INTERCONNECTION',
  mw DECIMAL(9,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts_cat (ts, category),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS readings_ie_co2 (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL COMMENT 'UTC',
  gco2_per_kwh DECIMAL(7,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts (ts),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- USA (EIA-930, lower-48 aggregate - respondent "US48"). Source: the EIA
-- API v2 (api.eia.gov) - free, but requires an API key, unlike every other
-- source above. See includes/config.php.example's eia_api_key and
-- includes/ingest.php's ukgrid_ingest_eia(). EIA-930 itself typically lags
-- about a day behind real time, so ts values here will normally look
-- "stale" by GB/Ireland standards - that's expected, see
-- usa_staleness_minutes in includes/config.php.example.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS readings_us_demand (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL COMMENT 'UTC',
  mw DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts (ts),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per (ts, category). category is TOTAL or INTERCONNECTION (from
-- the rto/region-data route, type=NG / type=TI, respondent=US48), or one of
-- the rto/fuel-type-data route's fuel type codes (COL, NG, NUC, OIL, WAT,
-- SUN, WND, OTH, UNK - note this "NG" means natural gas here, a different
-- facet on a different route to the region-data "type=NG" meaning net
-- generation above - see ukgrid_ingest_eia()'s comments). The fuel-type
-- breakdown powers pages/usa.html's mix table/donut/bar once wired up on
-- the frontend; TOTAL/INTERCONNECTION power the headline stats.
CREATE TABLE IF NOT EXISTS readings_us_generation (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL COMMENT 'UTC',
  category VARCHAR(20) NOT NULL COMMENT 'TOTAL, INTERCONNECTION, or an EIA fuel type code (COL, NG, NUC, OIL, WAT, SUN, WND, OTH, UNK)',
  mw DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts_cat (ts, category),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- ENTSO-E Transparency Platform - generic, multi-country. Unlike every
-- table above, these three are keyed by country_code rather than having a
-- dedicated table per country: one ukgrid_ingest_entsoe() function (see
-- includes/ingest.php) loops over includes/config.php's entsoe_countries
-- list and writes here for whichever countries are configured, so adding a
-- new country is a config change, not a schema change. Powers the "gap
-- fill" on pages/ireland.html (SEM price + generation mix, which EirGrid
-- doesn't provide) plus the standalone France/Netherlands/Belgium/
-- Norway/Denmark pages. Requires includes/config.php's entsoe_api_token -
-- see includes/config.php.example.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS readings_entsoe_demand (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL COMMENT 'UTC',
  country_code VARCHAR(4) NOT NULL COMMENT 'IE, FR, NL, BE, NO, or DK - see entsoe_countries in includes/config.php.example',
  mw DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts_country (ts, country_code),
  KEY idx_country_ts (country_code, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per (ts, country_code, psr_type). psr_type is an ENTSO-E
-- generation-type code (B01 Biomass, B04 Fossil Gas, B14 Nuclear, B16
-- Solar, B19 Wind Onshore, etc. - see ukgrid_ingest_entsoe()'s
-- ENTSOE_PSR_LABELS for the full list and display labels).
CREATE TABLE IF NOT EXISTS readings_entsoe_generation (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL COMMENT 'UTC',
  country_code VARCHAR(4) NOT NULL,
  psr_type VARCHAR(4) NOT NULL COMMENT 'ENTSO-E generation type code, e.g. B04, B14, B19',
  mw DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts_country_psr (ts, country_code, psr_type),
  KEY idx_country_ts (country_code, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS readings_entsoe_price (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL COMMENT 'UTC',
  country_code VARCHAR(4) NOT NULL,
  price_eur_mwh DECIMAL(9,3) NOT NULL COMMENT 'day-ahead price, EUR/MWh as published - not converted to GBP',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts_country (ts, country_code),
  KEY idx_country_ts (country_code, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Weather (Open-Meteo, keyless). Hourly wind speed + cloud cover for ONE
-- representative GB point (see weather_lat/weather_lon in
-- includes/config.php.example) - context for the History page's charts,
-- not a national average and not tied to any specific wind farm's actual
-- conditions. lat/lon are stored per-row so the History page's caption can
-- show exactly where the figures are from even if the configured point is
-- changed later.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS readings_weather (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL COMMENT 'UTC',
  wind_speed_ms DECIMAL(5,2) NULL COMMENT 'metres/second, 10m height',
  cloud_cover_pct DECIMAL(5,2) NULL,
  lat DECIMAL(6,3) NOT NULL,
  lon DECIMAL(6,3) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts (ts),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Network constraint costs & volumes (NESO Data Portal, "Constraint
-- Breakdown Costs and Volume" dataset - keyless CKAN). One row per day.
-- IMPORTANT: NESO publishes this broken down by CONSTRAINT TYPE (why an
-- action was taken - thermal network limits, voltage, or system inertia),
-- NOT by which generation technology was curtailed. There is no "wind"
-- column in the source data. thermal_cost/thermal_volume_mwh is shown on
-- pages/renewables.html as the closest available proxy for wind
-- curtailment (most GB wind curtailment happens via thermal-constraint
-- actions on the Scotland-England boundary), but it is NOT wind-exclusive
-- - it includes actions on any technology used to manage thermal limits.
-- See api/constraints_summary.php and that page's own caption for the
-- exact wording used to avoid overstating what this figure shows.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS readings_constraints (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dt DATE NOT NULL COMMENT 'the day these costs/volumes cover - NESO publishes this dataset daily, not as a timestamped series',
  thermal_cost DECIMAL(12,2) NULL COMMENT '£ - Trades/BM Actions managing thermal (transmission capacity) constraints',
  thermal_volume_mwh DECIMAL(12,2) NULL,
  voltage_cost DECIMAL(12,2) NULL COMMENT '£ - Trades/BM Actions managing voltage constraints',
  voltage_volume_mwh DECIMAL(12,2) NULL,
  inertia_cost DECIMAL(12,2) NULL COMMENT '£ - BM Actions increasing system inertia (RoCoF management)',
  inertia_volume_mwh DECIMAL(12,2) NULL,
  largest_loss_cost DECIMAL(12,2) NULL COMMENT '£ - Trades/BM Actions reducing the largest credible loss (RoCoF management)',
  largest_loss_volume_mwh DECIMAL(12,2) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dt (dt),
  KEY idx_dt (dt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Auto-computed "notable moments" - the highest/lowest value this site has
-- itself ever RECORDED for a handful of GB metrics, computed by re-running
-- MAX/MIN queries over readings_price/readings_emissions/readings_demand/
-- readings_generation (see ukgrid_compute_notable_moments() in
-- includes/ingest.php) and caching the result here, refreshed about once a
-- day via includes/refresh.php's on-demand dispatch (or cron/
-- fetch_notable_moments.php).
--
-- IMPORTANT: these are NOT the same thing as the hand-typed, independently
-- sourced "Records" panel on index.html (all-time GB records, checked
-- against NESO/press reporting). This table only ever reflects the window
-- of history THIS install has stored since its own first ingestion run -
-- for a fresh install that might be a few days or weeks, nowhere near a
-- real all-time record. api/notable_moments.php computes and returns that
-- window's start date (MIN(ts) across the same source tables) alongside
-- these figures specifically so the frontend can disclose it and avoid
-- implying otherwise - see pages/index.html's "Notable moments" section.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS notable_moments (
  metric_key VARCHAR(40) NOT NULL COMMENT 'e.g. price_highest, wind_highest - see ukgrid_compute_notable_moments()',
  label VARCHAR(120) NOT NULL,
  value DECIMAL(12,2) NOT NULL,
  unit VARCHAR(20) NOT NULL,
  ts DATETIME NOT NULL COMMENT 'when this value was recorded',
  direction VARCHAR(10) NOT NULL COMMENT 'highest or lowest',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (metric_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row every time a cron ingestion script runs, for monitoring/debugging.
-- Check this table first if the site looks like it's stuck on illustrative
-- data - api/current.php falls back to mock data whenever the live tables
-- are empty or stale, so a healthy log here is what "it's working" looks like.
CREATE TABLE IF NOT EXISTS ingest_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source VARCHAR(20) NOT NULL COMMENT 'ELEXON, CARBON_INTENSITY, NESO, EIRGRID, EIA, ENTSOE, IESO, WEATHER, CONSTRAINTS, NOTABLE_MOMENTS, or REFRESH (an on-demand-refresh crash before it reached a specific source - see includes/refresh.log)',
  ran_at DATETIME NOT NULL,
  status VARCHAR(10) NOT NULL COMMENT 'OK or ERROR',
  rows_written INT NOT NULL DEFAULT 0,
  message TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_source_ran (source, ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Canada (Ontario only) - IESO's public report repository, no API key
-- required. Source: reports-public.ieso.ca - see includes/ingest.php's
-- ukgrid_ingest_ieso() for the exact report URLs and shapes, and
-- pages/canada.html / pages/data-sources.html for the "Ontario, not all of
-- Canada" scope disclosure. Deliberately named readings_ca_* (not
-- readings_ieso_* or readings_on_*) to match the readings_us_* / entsoe
-- naming pattern above, in case a second Canadian source (e.g. Alberta's
-- AESO) is added later and this needs to become a country_code-keyed pair
-- of tables the same way the ENTSO-E ones are - not done yet since IESO is
-- currently the only Canadian source this project has wired up.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS readings_ca_demand (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL COMMENT 'UTC',
  mw DECIMAL(10,2) NOT NULL COMMENT 'Ontario Demand, from IESO RealtimeTotals "ONTARIO DEMAND" MarketQuantity',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts (ts),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per (ts, category). category is an IESO fuel-type name exactly as
-- published (NUCLEAR, GAS, HYDRO, WIND, SOLAR, BIOFUEL, OTHER) from the
-- GenOutputbyFuelHourly report - see ukgrid_ingest_ieso().
CREATE TABLE IF NOT EXISTS readings_ca_generation (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL COMMENT 'UTC',
  category VARCHAR(20) NOT NULL COMMENT 'IESO fuel type: NUCLEAR, GAS, HYDRO, WIND, SOLAR, BIOFUEL, or OTHER',
  mw DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts_cat (ts, category),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
