/* ==========================================================================
   UK Grid: Live+ - live-data adapter
   Talks to the PHP/MySQL backend (api/current.php, api/series.php). Every
   function resolves to `null` (never throws past its own boundary) if the
   API is unreachable, mis-configured, or simply has no data yet - callers
   are expected to fall back to GridPreview's placeholder generators in that
   case. That fallback is what makes it safe to load this site before
   ingestion has run for the first time.
   ========================================================================== */

(function () {
  "use strict";

  // Resolved absolute URL of this script (same technique as app.js's
  // SELF_SCRIPT_SRC), used to derive an absolute API base that works no
  // matter how deep the current page is. A plain relative "api/" string
  // only works from the site root (index.html) - loaded from anywhere
  // under pages/ (ireland.html, history.html, every detail page), it
  // resolves against the PAGE's URL, not the site root, and silently
  // 404s. Every fetch* function below fell back to "no live data" on any
  // page one level deep, which is exactly what a 404 masquerading as
  // "the API is unreachable" looks like from the outside.
  const SELF_SCRIPT_SRC = document.currentScript ? document.currentScript.src : "";
  const DERIVED_API_BASE = SELF_SCRIPT_SRC.replace(/assets\/data\.js(?:\?.*)?$/, "api/");
  const DEFAULT_API_BASE = (DERIVED_API_BASE && DERIVED_API_BASE !== SELF_SCRIPT_SRC) ? DERIVED_API_BASE : "api/";

  async function fetchJSON(url) {
    const res = await fetch(url, { headers: { Accept: "application/json" } });
    if (!res.ok) throw new Error("HTTP " + res.status);
    const data = await res.json();
    if (!data || data.ok !== true) throw new Error("API returned ok:false");
    return data;
  }

  // Cached per page load - api/status.php has more than one caller (the
  // footer's data-pipeline pill in initIngestStatusPill(), plus the
  // per-page "one of this page's sources is having trouble" banners on
  // index.html/pages/ireland.html), and there's no reason for each to fire
  // its own HTTP request for what's the same read within the same page
  // load. Not invalidated/refreshed - a page reload is what refreshes it,
  // same as every other fetch* function here.
  let statusPromise = null;

  /** Data-pipeline health: per-source (ELEXON/CARBON_INTENSITY/NESO/EIRGRID/
      EIA/ENTSOE) last-ingest-attempt status, straight from api/status.php.
      Returns null if the endpoint itself is unreachable - callers should
      treat that as "can't tell" rather than "something's broken", same as
      every other fetch* function's null-on-failure convention. */
  function fetchStatus(apiBase) {
    if (!statusPromise) {
      statusPromise = fetchJSON((apiBase || DEFAULT_API_BASE) + "status.php").catch(() => null);
    }
    return statusPromise;
  }

  /** Latest snapshot: demand/price/emissions + generation by fuel type. Returns null if unavailable. */
  async function fetchCurrent(apiBase) {
    try {
      const data = await fetchJSON((apiBase || DEFAULT_API_BASE) + "current.php");
      if (!data.live) return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  /** Historical series for one metric+range, shaped like GridPreview.buildSeries's return value.
      seasonOffset (optional): only meaningful when range === "season" - shifts
      the season back this many whole years, e.g. -1 for "the same season last
      year". Used by the History page's "compare seasons" checkbox. */
  async function fetchSeries(metric, range, apiBase, seasonOffset) {
    try {
      let url = (apiBase || DEFAULT_API_BASE) + "series.php?metric=" + encodeURIComponent(metric) + "&range=" + encodeURIComponent(range);
      if (range === "season" && seasonOffset) url += "&season_offset=" + encodeURIComponent(seasonOffset);
      const data = await fetchJSON(url);
      if (!data.points || !data.points.length) return null;
      const cfg = (window.GridPreview && window.GridPreview.RANGE_CONFIG[range]) || { fmt: "date" };
      const fmt = window.GridPreview && window.GridPreview.formatLabel ? window.GridPreview.formatLabel : (t) => new Date(t).toLocaleString();
      return {
        labels: data.points.map((p) => fmt(p.t, cfg.fmt)),
        // Raw ISO timestamps, kept alongside the range-formatted axis labels
        // above so hover tooltips can show the exact date and time a point
        // was recorded regardless of how coarse that range's axis label is
        // (e.g. a "year" range axis label is just a month, but the tooltip
        // should still show the full date).
        times: data.points.map((p) => p.t),
        values: data.points.map((p) => p.v),
        unit: data.unit,
      };
    } catch (e) {
      return null;
    }
  }

  /** Generation mix over time (renewable/non_renewable/storage, bucketed),
      for the History page's stacked-area chart and period-average donut.
      Shape matches renderStackedAreaChart's expected input once wrapped in
      column objects by the caller. Returns null if unavailable. */
  async function fetchMixSeries(range, apiBase) {
    try {
      const url = (apiBase || DEFAULT_API_BASE) + "series.php?metric=mix&range=" + encodeURIComponent(range);
      const data = await fetchJSON(url);
      if (!data.points || !data.points.length) return null;
      const cfg = (window.GridPreview && window.GridPreview.RANGE_CONFIG[range]) || { fmt: "date" };
      const fmt = window.GridPreview && window.GridPreview.formatLabel ? window.GridPreview.formatLabel : (t) => new Date(t).toLocaleString();
      return {
        labels: data.points.map((p) => fmt(p.t, cfg.fmt)),
        times: data.points.map((p) => p.t),
        renewable: data.points.map((p) => p.renewable),
        nonRenewable: data.points.map((p) => p.non_renewable),
        storage: data.points.map((p) => p.storage),
        unit: data.unit,
      };
    } catch (e) {
      return null;
    }
  }

  /** Wind speed (m/s) + cloud cover (%) over time, for the History page's
      "Wind speed & cloud cover" card - one representative GB point, see
      readings_weather in sql/schema.sql. Refreshes with zero cron access
      like every other source (includes/refresh.php). Returns null only if
      it genuinely hasn't been ingested yet on this install (e.g. its very
      first refresh hasn't happened, or on_demand is off and no cron job
      is set up either). */
  async function fetchWeatherSeries(range, apiBase) {
    try {
      const url = (apiBase || DEFAULT_API_BASE) + "series.php?metric=weather&range=" + encodeURIComponent(range);
      const data = await fetchJSON(url);
      if (!data.points || !data.points.length) return null;
      const cfg = (window.GridPreview && window.GridPreview.RANGE_CONFIG[range]) || { fmt: "date" };
      const fmt = window.GridPreview && window.GridPreview.formatLabel ? window.GridPreview.formatLabel : (t) => new Date(t).toLocaleString();
      return {
        labels: data.points.map((p) => fmt(p.t, cfg.fmt)),
        times: data.points.map((p) => p.t),
        windSpeed: data.points.map((p) => p.wind_speed),
        cloudCover: data.points.map((p) => p.cloud_cover),
      };
    } catch (e) {
      return null;
    }
  }

  /** Network constraint costs & volumes, summed over the most recent 7
      days NESO has published (see api/constraints_summary.php for the
      exact shape and the important caveat that these are constraint
      TYPES - thermal/voltage/inertia - not generation technologies; there
      is no wind-specific figure here). Refreshes with zero cron access
      like every other source. Returns null if genuinely unavailable (its
      first refresh hasn't happened yet on this install). */
  async function fetchConstraintsSummary(apiBase) {
    try {
      const data = await fetchJSON((apiBase || DEFAULT_API_BASE) + "constraints_summary.php");
      if (!data.ok) return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  /** This install's own recorded highs/lows for a handful of GB metrics
      (price, carbon intensity, demand, wind, solar) - see
      api/notable_moments.php for the exact shape and the important caveat
      that "recorded_since" is THIS SITE's own storage window, not an
      all-time record start date - a fresh install might only have a few
      days or weeks behind it. NOT the same thing as the separate,
      hand-typed "Records" panel elsewhere on the page. Refreshes with zero
      cron access like every other source. Returns null if genuinely
      unavailable (its first computation hasn't happened yet on this
      install). */
  async function fetchNotableMoments(apiBase) {
    try {
      const data = await fetchJSON((apiBase || DEFAULT_API_BASE) + "notable_moments.php");
      if (!data.ok) return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  /** Latest all-island Ireland snapshot: demand/generation/wind/interconnection/emissions. Returns null if unavailable. */
  async function fetchIrelandCurrent(apiBase) {
    try {
      const data = await fetchJSON((apiBase || DEFAULT_API_BASE) + "ireland_current.php");
      if (!data.live) return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  /** Historical series for one Ireland metric+range. Same shape as fetchSeries's return value. No "price" metric - see api/ireland_current.php. */
  async function fetchIrelandSeries(metric, range, apiBase) {
    try {
      const url = (apiBase || DEFAULT_API_BASE) + "ireland_series.php?metric=" + encodeURIComponent(metric) + "&range=" + encodeURIComponent(range);
      const data = await fetchJSON(url);
      if (!data.points || !data.points.length) return null;
      const cfg = (window.GridPreview && window.GridPreview.RANGE_CONFIG[range]) || { fmt: "date" };
      const fmt = window.GridPreview && window.GridPreview.formatLabel ? window.GridPreview.formatLabel : (t) => new Date(t).toLocaleString();
      return {
        labels: data.points.map((p) => fmt(p.t, cfg.fmt)),
        times: data.points.map((p) => p.t),
        values: data.points.map((p) => p.v),
        unit: data.unit,
      };
    } catch (e) {
      return null;
    }
  }

  /** Latest USA (lower-48 aggregate) snapshot: demand/generation/interconnection + fuel-type mix. Returns null if unavailable. */
  async function fetchUsaCurrent(apiBase) {
    try {
      const data = await fetchJSON((apiBase || DEFAULT_API_BASE) + "usa_current.php");
      if (!data.live) return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  /** Historical series for one USA metric+range. Same shape as fetchSeries's return value. Only demand/generation/transfers/mix are live - see api/usa_current.php's docblock for why price/emissions have no equivalent. */
  async function fetchUsaSeries(metric, range, apiBase) {
    try {
      const url = (apiBase || DEFAULT_API_BASE) + "usa_series.php?metric=" + encodeURIComponent(metric) + "&range=" + encodeURIComponent(range);
      const data = await fetchJSON(url);
      if (!data.points || !data.points.length) return null;
      const cfg = (window.GridPreview && window.GridPreview.RANGE_CONFIG[range]) || { fmt: "date" };
      const fmt = window.GridPreview && window.GridPreview.formatLabel ? window.GridPreview.formatLabel : (t) => new Date(t).toLocaleString();
      return {
        labels: data.points.map((p) => fmt(p.t, cfg.fmt)),
        times: data.points.map((p) => p.t),
        values: data.points.map((p) => p.v),
        unit: data.unit,
      };
    } catch (e) {
      return null;
    }
  }

  /** USA generation mix over time (fossil/renewable/other, bucketed) - same
      shape as fetchMixSeries's GB equivalent, for pages/usa.html's 24-hour
      stacked-area chart. "other" here means nuclear plus EIA's own
      leftover OTH/UNK categories - see api/usa_series.php's metric=mix
      comment for why the US split doesn't line up one-for-one with GB's
      renewable/non_renewable/storage groups. */
  async function fetchUsaMixSeries(range, apiBase) {
    try {
      const url = (apiBase || DEFAULT_API_BASE) + "usa_series.php?metric=mix&range=" + encodeURIComponent(range);
      const data = await fetchJSON(url);
      if (!data.points || !data.points.length) return null;
      const cfg = (window.GridPreview && window.GridPreview.RANGE_CONFIG[range]) || { fmt: "date" };
      const fmt = window.GridPreview && window.GridPreview.formatLabel ? window.GridPreview.formatLabel : (t) => new Date(t).toLocaleString();
      return {
        labels: data.points.map((p) => fmt(p.t, cfg.fmt)),
        times: data.points.map((p) => p.t),
        fossil: data.points.map((p) => p.fossil),
        renewable: data.points.map((p) => p.renewable),
        other: data.points.map((p) => p.other),
        unit: data.unit,
      };
    } catch (e) {
      return null;
    }
  }

  // EIA-930 fuel type codes, mapped to display labels - keep in sync with
  // includes/ingest.php's ukgrid_ingest_eia() and api/usa_current.php's
  // $fuelTypes list.
  const USA_FUEL_LABELS = {
    NG: "Natural gas", COL: "Coal", NUC: "Nuclear", WND: "Wind", SUN: "Solar",
    WAT: "Hydro", OIL: "Oil", OTH: "Other", UNK: "Unknown",
  };

  /**
   * Turns api/usa_current.php's raw { fuelCode: MW } mix_mw object into the
   * shape pages/usa.html's mix table/donut/bar need: sorted labelled pairs
   * plus a total, matching the illustrative arrays they replace.
   */
  function summarizeUsaMix(mixMw) {
    const entries = Object.keys(mixMw || {})
      .map((code) => ({ label: USA_FUEL_LABELS[code] || code, mw: mixMw[code] }))
      .filter((e) => e.mw != null)
      .sort((a, b) => b.mw - a.mw);
    const totalMw = entries.reduce((sum, e) => sum + e.mw, 0);
    return { entries, totalMw };
  }

  /** Latest Canada (Ontario/IESO) snapshot: demand + generation-by-fuel mix. Returns null if unavailable. See api/canada_current.php's docblock for the Ontario-only scope. */
  async function fetchCanadaCurrent(apiBase) {
    try {
      const data = await fetchJSON((apiBase || DEFAULT_API_BASE) + "canada_current.php");
      if (!data.live) return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  /** Historical series for one Canada (Ontario/IESO) metric+range. Same shape as fetchSeries's return value. Only demand/generation/mix and the individual-source metrics are live - no price/emissions/transfers equivalent, see api/canada_current.php's docblock. */
  async function fetchCanadaSeries(metric, range, apiBase) {
    try {
      const url = (apiBase || DEFAULT_API_BASE) + "canada_series.php?metric=" + encodeURIComponent(metric) + "&range=" + encodeURIComponent(range);
      const data = await fetchJSON(url);
      if (!data.points || !data.points.length) return null;
      const cfg = (window.GridPreview && window.GridPreview.RANGE_CONFIG[range]) || { fmt: "date" };
      const fmt = window.GridPreview && window.GridPreview.formatLabel ? window.GridPreview.formatLabel : (t) => new Date(t).toLocaleString();
      return {
        labels: data.points.map((p) => fmt(p.t, cfg.fmt)),
        times: data.points.map((p) => p.t),
        values: data.points.map((p) => p.v),
        unit: data.unit,
      };
    } catch (e) {
      return null;
    }
  }

  /** Ontario generation mix over time (renewable/non_renewable, bucketed) - same shape family as fetchMixSeries/fetchUsaMixSeries, for pages/canada.html's 24-hour stacked-area chart. See api/canada_series.php's metric=mix comment for the group definitions (biofuel counts as non-renewable, matching GB's own convention). */
  async function fetchCanadaMixSeries(range, apiBase) {
    try {
      const url = (apiBase || DEFAULT_API_BASE) + "canada_series.php?metric=mix&range=" + encodeURIComponent(range);
      const data = await fetchJSON(url);
      if (!data.points || !data.points.length) return null;
      const cfg = (window.GridPreview && window.GridPreview.RANGE_CONFIG[range]) || { fmt: "date" };
      const fmt = window.GridPreview && window.GridPreview.formatLabel ? window.GridPreview.formatLabel : (t) => new Date(t).toLocaleString();
      return {
        labels: data.points.map((p) => fmt(p.t, cfg.fmt)),
        times: data.points.map((p) => p.t),
        renewable: data.points.map((p) => p.renewable),
        non_renewable: data.points.map((p) => p.non_renewable),
        unit: data.unit,
      };
    } catch (e) {
      return null;
    }
  }

  // IESO fuel type codes, mapped to display labels - keep in sync with
  // includes/ingest.php's ukgrid_ingest_ieso() and api/canada_current.php's
  // $fuelTypes list.
  const CANADA_FUEL_LABELS = {
    NUCLEAR: "Nuclear", GAS: "Gas", HYDRO: "Hydro", WIND: "Wind",
    SOLAR: "Solar", BIOFUEL: "Biofuel", OTHER: "Other",
  };

  /** Latest ENTSO-E snapshot for one country (demand/generation/price + mix, whichever the country's config fetches). Returns null if unavailable. */
  async function fetchCountryCurrent(country, apiBase) {
    try {
      const data = await fetchJSON((apiBase || DEFAULT_API_BASE) + "country_current.php?country=" + encodeURIComponent(country));
      if (!data.live) return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  /** Historical series for one ENTSO-E country+metric+range. Same shape as fetchSeries's return value. metric must be one that country's config actually fetches - see api/country_series.php. */
  async function fetchCountrySeries(country, metric, range, apiBase) {
    try {
      const url = (apiBase || DEFAULT_API_BASE) + "country_series.php?country=" + encodeURIComponent(country) + "&metric=" + encodeURIComponent(metric) + "&range=" + encodeURIComponent(range);
      const data = await fetchJSON(url);
      if (!data.points || !data.points.length) return null;
      const cfg = (window.GridPreview && window.GridPreview.RANGE_CONFIG[range]) || { fmt: "date" };
      const fmt = window.GridPreview && window.GridPreview.formatLabel ? window.GridPreview.formatLabel : (t) => new Date(t).toLocaleString();
      return {
        labels: data.points.map((p) => fmt(p.t, cfg.fmt)),
        times: data.points.map((p) => p.t),
        values: data.points.map((p) => p.v),
        unit: data.unit,
      };
    } catch (e) {
      return null;
    }
  }

  /** Generation mix over time for one ENTSO-E country (renewable/fossil/
      nuclear/other, bucketed) - same shape family as fetchMixSeries's GB
      equivalent, for each country page's 24-hour stacked-area chart. Only
      valid for countries whose config fetches 'generation' - see
      api/country_series.php's metric=mix comment. */
  async function fetchCountryMixSeries(country, range, apiBase) {
    try {
      const url = (apiBase || DEFAULT_API_BASE) + "country_series.php?country=" + encodeURIComponent(country) + "&metric=mix&range=" + encodeURIComponent(range);
      const data = await fetchJSON(url);
      if (!data.points || !data.points.length) return null;
      const cfg = (window.GridPreview && window.GridPreview.RANGE_CONFIG[range]) || { fmt: "date" };
      const fmt = window.GridPreview && window.GridPreview.formatLabel ? window.GridPreview.formatLabel : (t) => new Date(t).toLocaleString();
      return {
        labels: data.points.map((p) => fmt(p.t, cfg.fmt)),
        times: data.points.map((p) => p.t),
        renewable: data.points.map((p) => p.renewable),
        fossil: data.points.map((p) => p.fossil),
        nuclear: data.points.map((p) => p.nuclear),
        other: data.points.map((p) => p.other),
        unit: data.unit,
      };
    } catch (e) {
      return null;
    }
  }

  // ENTSO-E generation-type ("psrType") codes, mapped to display labels and
  // to the renewable/non-renewable/nuclear/other split the mix donuts use -
  // keep in sync with includes/ingest.php's UKGRID_ENTSOE_PSR_LABELS.
  const ENTSOE_PSR_LABELS = {
    B01: "Biomass", B02: "Lignite", B03: "Coal gas", B04: "Gas", B05: "Hard coal",
    B06: "Oil", B07: "Oil shale", B08: "Peat", B09: "Geothermal", B10: "Pumped storage",
    B11: "Hydro (run-of-river)", B12: "Hydro (reservoir)", B13: "Marine", B14: "Nuclear",
    B15: "Other renewable", B16: "Solar", B17: "Waste", B18: "Wind (offshore)",
    B19: "Wind (onshore)", B20: "Other",
  };
  // One canonical colour per generation type, shared by pages/ireland.html's
  // mix section and every ENTSO-E-sourced country page, so the same fuel
  // always renders the same colour site-wide.
  const ENTSOE_PSR_COLORS = {
    B01: "#7a8f3f", B02: "#4a4a4a", B03: "#6b4f3a", B04: "#8a5a3c", B05: "#333333",
    B06: "#5c4b3c", B07: "#6e5a45", B08: "#7a6a4f", B09: "#b5651d",
    // B10 (pumped storage) used to share B11/Hydro's blue, making the two
    // indistinguishable on any country whose mix includes both - reassigned
    // to the same teal LABEL_COLORS below uses for "Battery"/"Storage",
    // which is exactly what pumped storage is.
    B10: "#0e8f7a",
    B11: "#2a8fbd", B12: "#1f6f94", B13: "#1f9d9d", B14: "#6a5acd", B15: "#59d19e",
    B16: "#e0a800", B17: "#8f7a4f", B18: "#1f8f63", B19: "#2fa876", B20: "#7a7a7a",
  };
  const ENTSOE_RENEWABLE_PSR = ["B01", "B09", "B11", "B12", "B13", "B15", "B16", "B18", "B19"];
  const ENTSOE_NUCLEAR_PSR = ["B14"];
  const ENTSOE_FOSSIL_PSR = ["B02", "B03", "B04", "B05", "B06", "B07", "B08"];

  /**
   * Turns api/country_current.php's raw { psrCode: MW } mix_mw object into
   * the shape the mix table/donut/bar need: sorted labelled entries plus
   * fossil/renewable/nuclear/other subtotals, mirroring
   * summarizeGeneration()'s shape above for the GB donut.
   */
  function summarizeEntsoeMix(mixMw) {
    let fossilMw = 0, renewableMw = 0, nuclearMw = 0, otherMw = 0, totalMw = 0;
    const groupOf = (code) => {
      if (ENTSOE_FOSSIL_PSR.indexOf(code) !== -1) return "fossil";
      if (ENTSOE_RENEWABLE_PSR.indexOf(code) !== -1) return "renewable";
      if (ENTSOE_NUCLEAR_PSR.indexOf(code) !== -1) return "nuclear";
      return "other";
    };
    const entries = Object.keys(mixMw || {}).map((code) => {
      const mw = mixMw[code];
      const group = groupOf(code);
      totalMw += mw;
      if (group === "fossil") fossilMw += mw;
      else if (group === "renewable") renewableMw += mw;
      else if (group === "nuclear") nuclearMw += mw;
      else otherMw += mw;
      return { code, label: ENTSOE_PSR_LABELS[code] || code, mw, group };
    }).sort((a, b) => b.mw - a.mw);
    return { entries, totalMw, fossilMw, renewableMw, nuclearMw, otherMw };
  }

  // Elexon fuel-type codes (plus the two embedded-generation codes this site
  // adds from NESO) mapped to display labels and to the renewable /
  // non-renewable / interconnector / storage split used by the outer donut
  // ring. Keep in sync with api/_bootstrap.php's ukgrid_fuel_groups().
  const FUEL_LABELS = {
    CCGT: "Gas", OCGT: "Gas (peaking)", COAL: "Coal", NUCLEAR: "Nuclear", BIOMASS: "Biomass",
    WIND: "Wind", NPSHYD: "Hydro", PS: "Pumped storage", OIL: "Oil", OTHER: "Other",
    SOLAR_EMBEDDED: "Solar", WIND_EMBEDDED: "Wind (embedded)",
  };
  const INTERCONNECTOR_LABELS = {
    INTFR: "IFA (France)", INTIRL: "Moyle (N. Ireland)", INTNED: "BritNed (Netherlands)",
    INTEW: "EWIC (Ireland)", INTNEM: "Nemo Link (Belgium)", INTELEC: "ElecLink (France)",
    INTIFA2: "IFA2 (France)", INTNSL: "North Sea Link (Norway)", INTVKL: "Viking Link (Denmark)",
    INTGRNL: "Greenlink (Ireland)",
  };
  const RENEWABLE_FUELS = ["WIND", "WIND_EMBEDDED", "SOLAR_EMBEDDED", "NPSHYD"];
  const NON_RENEWABLE_FUELS = ["CCGT", "OCGT", "COAL", "OIL", "NUCLEAR", "BIOMASS", "OTHER"];

  /**
   * Turns api/current.php's raw { fuelCode: MW } generation object into the
   * shape the mix table / nested donut need: totals by category, and a
   * by-source breakdown with display labels already applied.
   */
  function summarizeGeneration(generation) {
    let renewableMw = 0;
    let nonRenewableMw = 0;
    let interconnectorMw = 0;
    let storageMw = 0;
    let totalGenMw = 0;
    const bySource = {}; // display label -> MW
    const byInterconnector = {}; // display label -> MW

    Object.keys(generation || {}).forEach((code) => {
      const mw = generation[code];
      if (code.indexOf("INT") === 0) {
        interconnectorMw += mw;
        byInterconnector[INTERCONNECTOR_LABELS[code] || code] = mw;
        return;
      }
      if (code === "PS") {
        storageMw += mw;
        return;
      }
      totalGenMw += mw;
      if (RENEWABLE_FUELS.indexOf(code) !== -1) renewableMw += mw;
      else if (NON_RENEWABLE_FUELS.indexOf(code) !== -1) nonRenewableMw += mw;
      const label = FUEL_LABELS[code] || code;
      bySource[label] = (bySource[label] || 0) + mw;
    });

    return {
      totalGenMw, renewableMw, nonRenewableMw, interconnectorMw, storageMw,
      bySource, byInterconnector,
    };
  }

  // Canonical colour for every generation-source / category label used
  // anywhere on the site, so the same fuel reads as the same colour on
  // every donut, bar chart and legend rather than each page keeping its
  // own hand-typed colour array. Those per-page arrays are exactly how two
  // real bugs crept in during the 2026 donut redesign: pages/ireland.html
  // and pages/usa.html each happened to reuse Hydro's blue (#2a8fbd) for
  // "Battery" too, making battery storage and hydro indistinguishable
  // slices in those two donuts. Keep in sync with style.css's :root colour
  // custom properties, which define the same palette for ordinary
  // (non-canvas) DOM styling - table swatches, status pills, etc.
  const LABEL_COLORS = {
    Nuclear: "#6a5acd",
    Gas: "#8a5a3c",
    "Natural gas": "#8a5a3c",
    Wind: "#2fa876",
    "Wind (onshore)": "#2fa876",
    "Wind (offshore)": "#1f8f63",
    Solar: "#e0a800",
    Hydro: "#2a8fbd",
    "Hydro (run-of-river)": "#2a8fbd",
    "Hydro (reservoir)": "#1f6f94",
    Coal: "#4a4a4a",
    "Coal & lignite": "#4a4a4a",
    Biomass: "#7a8f3f",
    "Biomass & other": "#7a8f3f",
    "Biomass / waste (CHP)": "#7a8f3f",
    // Matches ENTSOE_PSR_COLORS' own B06 (Oil) value below, rather than
    // picking a new one - #6b4f3a is already spoken for by B03 (Coal gas).
    Oil: "#5c4b3c",
    "Oil peakers": "#5c4b3c",
    "Oil / distillate peakers": "#5c4b3c",
    Battery: "#0e8f7a",
    "Battery storage": "#0e8f7a",
    "Battery & other": "#0e8f7a",
    "Other & storage": "#7a7a7a",
    Other: "#7a7a7a",
    "GB interconnect": "#d98e04",
    "GB interconnection": "#d98e04",
    "Interconnection with GB": "#d98e04",
    Interconnector: "#d98e04",
    Renewable: "#1f9d5a",
    Renewables: "#1f9d5a",
    "Non-renewable": "#8a5a3c",
  };
  // Used only for a label this map genuinely has no entry for (a future
  // source added to one page and forgotten here) - cycles through a few
  // already-established hues rather than defaulting everything to grey, so
  // a missed label is visually obvious (an odd colour out) rather than
  // silently blending into "Other".
  const FALLBACK_COLOR_CYCLE = ["#2fa876", "#8a5a3c", "#e0a800", "#2a8fbd", "#6a5acd", "#7a8f3f", "#d98e04", "#7a7a7a"];

  /** Canonical colour for a generation-source/category label - see LABEL_COLORS above. `fallbackIndex` (usually the label's position in its own array) picks a stand-in colour on the rare miss rather than always defaulting to the same one. */
  function colorFor(label, fallbackIndex) {
    if (Object.prototype.hasOwnProperty.call(LABEL_COLORS, label)) return LABEL_COLORS[label];
    return FALLBACK_COLOR_CYCLE[(fallbackIndex || 0) % FALLBACK_COLOR_CYCLE.length];
  }

  /** Maps a whole labels array to its canonical colours in one call - the common case at each page's call site. */
  function colorsFor(labelsArr) {
    return labelsArr.map((l, i) => colorFor(l, i));
  }

  window.GridData = {
    fetchStatus,
    fetchCurrent,
    fetchSeries,
    fetchMixSeries,
    fetchWeatherSeries,
    fetchConstraintsSummary,
    fetchNotableMoments,
    fetchIrelandCurrent,
    fetchIrelandSeries,
    fetchUsaCurrent,
    fetchUsaSeries,
    fetchUsaMixSeries,
    fetchCanadaCurrent,
    fetchCanadaSeries,
    fetchCanadaMixSeries,
    fetchCountryCurrent,
    fetchCountrySeries,
    fetchCountryMixSeries,
    summarizeGeneration,
    summarizeUsaMix,
    summarizeEntsoeMix,
    FUEL_LABELS,
    INTERCONNECTOR_LABELS,
    USA_FUEL_LABELS,
    CANADA_FUEL_LABELS,
    ENTSOE_PSR_LABELS,
    ENTSOE_PSR_COLORS,
    LABEL_COLORS,
    colorFor,
    colorsFor,
  };
})();
