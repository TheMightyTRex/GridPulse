/* ==========================================================================
   Grid Pulse - what's coming, grid frequency and balancing costs (GB)
   Fills:
     [data-forecast-page]   pages/forecast.html
     [data-besttime-page]   pages/best-time.html
     [data-regional-page]   pages/regional-carbon.html
     [data-frequency-page]  pages/frequency.html
     [data-balancing-page]  pages/balancing-costs.html
     [data-outlook-card]    the "Coming up" card on the GB dashboard
   Data: api/forecast.php, api/frequency.php, api/balancing.php
   (see includes/outlook.php). Never shows a made-up figure: anything
   missing says so instead.
   ========================================================================== */
(function () {
  "use strict";
  const GP = window.GridPreview || {};
  const SRC = document.currentScript ? document.currentScript.src : "";
  const API = (function () {
    const base = SRC.replace(/assets\/outlook\.js(?:\?.*)?$/, "api/");
    return base && base !== SRC ? base : "api/";
  })();
  const ROOT = API.replace(/api\/$/, "");
  const VER = (function () { const m = SRC.match(/[?&]v=([0-9a-z]+)/); return m ? "?v=" + m[1] : ""; })();
  const TZ = "Europe/London";

  const esc = (s) => String(s == null ? "" : s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const whole = (v) => (v == null ? "-" : Math.round(v).toLocaleString("en-GB"));
  const gw = (mw) => (mw == null ? "-" : (mw / 1000).toFixed(1));

  function clock(iso) {
    if (!iso) return "-";
    const parts = new Intl.DateTimeFormat("en-GB", { timeZone: TZ, hour: "numeric", minute: "2-digit", hour12: true }).formatToParts(new Date(iso));
    const h = parts.find((p) => p.type === "hour").value;
    const m = parts.find((p) => p.type === "minute").value;
    const ap = (parts.find((p) => p.type === "dayPeriod") || { value: "" }).value.toLowerCase().replace(/[.\s]/g, "");
    return h + (m === "00" ? "" : "." + m) + ap;
  }
  const dayKey = (d) => d.toLocaleDateString("en-GB", { timeZone: TZ, day: "numeric", month: "short", year: "numeric" });
  /** "1.30pm", "1.30pm tomorrow" or "Thu 1.30pm", UK time. */
  function when(iso) {
    if (!iso) return "-";
    const d = new Date(iso);
    const today = new Date();
    const tomorrow = new Date(today.getTime() + 86400000);
    if (dayKey(d) === dayKey(today)) return clock(iso);
    if (dayKey(d) === dayKey(tomorrow)) return clock(iso) + " tomorrow";
    return d.toLocaleDateString("en-GB", { timeZone: TZ, weekday: "short" }) + " " + clock(iso);
  }
  /** Short axis label: "3pm" or "Thu 3am" at the start of a day. */
  function axisLabel(iso, i, arr) {
    const d = new Date(iso);
    const prev = i > 0 ? new Date(arr[i - 1]) : null;
    return (!prev || dayKey(prev) !== dayKey(d) ? d.toLocaleDateString("en-GB", { timeZone: TZ, weekday: "short" }) + " " : "") + clock(iso);
  }
  async function getJSON(path) {
    try {
      const r = await fetch(API + path, { headers: { Accept: "application/json" } });
      if (!r.ok) return null;
      const j = await r.json();
      return j && j.ok ? j : null;
    } catch (e) { return null; }
  }
  const empty = (msg) => '<p class="area-empty">' + esc(msg) + "</p>";

  /* Carbon intensity bands, as the Carbon Intensity API names them. */
  const BANDS = [["very low", "Very low", "var(--ci-vlow)"], ["low", "Low", "var(--ci-low)"], ["moderate", "Moderate", "var(--ci-mod)"], ["high", "High", "var(--ci-high)"], ["very high", "Very high", "var(--ci-vhigh)"]];
  const bandOf = (idx) => BANDS.find((b) => b[0] === String(idx || "").toLowerCase()) || null;
  const bandName = (idx) => { const b = bandOf(idx); return b ? b[1].toLowerCase() : "not given"; };
  const bandKey = () => '<p class="gpmap-key ci-key">' + BANDS.map((b) => '<span><i style="background:' + b[2] + '"></i>' + b[1] + "</span>").join("") + "</p>";
  const FUEL = { wind: "Wind", solar: "Solar", hydro: "Hydro", nuclear: "Nuclear", biomass: "Biomass", gas: "Gas", coal: "Coal", imports: "Imports", other: "Other" };
  const CI_CREDIT = 'Carbon intensity: <a href="https://carbonintensity.org.uk/">Carbon Intensity API</a> (NESO and the University of Oxford), CC BY 4.0.';

  /* --------------------------------------------- Best window to use power */
  /** Lowest-average window of n half hours that ends by slot `limit`. */
  function bestWindow(g, n, limit) {
    let best = null;
    for (let i = 0; i + n <= Math.min(limit, g.length); i++) {
      const part = g.slice(i, i + n);
      if (part.some((v) => v == null)) continue;
      const avg = part.reduce((s, v) => s + v, 0) / n;
      if (!best || avg < best.avg - 1e-9) best = { start: i, avg };
    }
    return best;
  }
  function nowAvg(g, n) {
    const part = g.slice(0, n);
    return part.length === n && !part.some((v) => v == null) ? part.reduce((s, v) => s + v, 0) / n : null;
  }

  /** A row of half-hour cells coloured by band, with the chosen window outlined. */
  function timeline(c, from, len) {
    const n = c.times.length;
    const cells = c.times.map((t, i) => {
      const b = bandOf(c.index[i]);
      const inWin = from != null && i >= from && i < from + len;
      return '<span class="ci-cell' + (inWin ? " is-win" : "") + '" style="background:' + (b ? b[2] : "var(--border)") + '" title="' + esc(when(t) + ": " + (c.gco2[i] == null ? "no forecast" : c.gco2[i] + " g, " + bandName(c.index[i]))) + '"></span>';
    }).join("");
    // A label every 6 hours, on the hour (UK time): the day's name at midnight.
    const ticks = c.times.map((t, i) => {
      const hm = new Intl.DateTimeFormat("en-GB", { timeZone: TZ, hour: "2-digit", minute: "2-digit", hourCycle: "h23" }).format(new Date(t));
      const h = +hm.slice(0, 2);
      if (hm.slice(3) !== "00" || h % 6 !== 0 || i === 0) return "";
      const lab = h === 0 ? new Date(t).toLocaleDateString("en-GB", { timeZone: TZ, weekday: "short" }) : clock(t);
      return '<span style="left:' + ((i / n) * 100).toFixed(2) + '%"' + (h === 0 ? ' class="is-day"' : "") + ">" + esc(lab) + "</span>";
    }).join("");
    return '<div class="ci-timeline" aria-hidden="true"><div class="ci-cells">' + cells + '</div><div class="ci-ticks">' + ticks + "</div></div>";
  }

  async function initBestTime(host) {
    const first = await getJSON("forecast.php");
    if (!first || !first.carbon.times.length) {
      host.innerHTML = empty("The carbon forecast is not available right now. It updates every 30 minutes.");
      return;
    }
    const areaOpts = [18].concat(first.areas.filter((a) => a.id <= 14).sort((a, b) => a.name.localeCompare(b.name)).map((a) => a.id));
    const nameOf = {};
    first.areas.forEach((a) => { nameOf[a.id] = a.name; });
    nameOf[18] = "Great Britain";
    const lengths = [[1, "30 minutes"], [2, "1 hour"], [3, "1 hour 30 minutes"], [4, "2 hours"], [6, "3 hours"], [8, "4 hours"], [12, "6 hours"], [16, "8 hours"]];
    const ends = [[16, "in the next 8 hours"], [24, "in the next 12 hours"], [48, "in the next 24 hours"], [96, "any time in the next 2 days"]];
    host.innerHTML =
      '<section class="panel" aria-labelledby="bt-form-h"><h2 id="bt-form-h">Find the cleanest time</h2>' +
      '<form class="bt-form" data-bt-form>' +
      '<label>Where you are<select name="region">' + areaOpts.map((id) => '<option value="' + id + '">' + esc(nameOf[id] || id) + "</option>").join("") + "</select></label>" +
      '<label>How long it runs<select name="len">' + lengths.map((l) => '<option value="' + l[0] + '"' + (l[0] === 4 ? " selected" : "") + ">" + l[1] + "</option>").join("") + "</select></label>" +
      '<label>Finished<select name="by">' + ends.map((e) => '<option value="' + e[0] + '"' + (e[0] === 48 ? " selected" : "") + ">" + e[1] + "</option>").join("") + "</select></label>" +
      "</form>" +
      '<div class="bt-result" data-bt-result aria-live="polite"></div></section>' +
      '<section class="panel" aria-labelledby="bt-how-h"><h2 id="bt-how-h">How this works</h2>' +
      "<p>The Carbon Intensity API forecasts how much carbon dioxide is given off for each unit of electricity, every half hour for the next 2 days, for Great Britain and for each of the 14 network areas. Grid Pulse tries every start time and picks the one with the lowest average over the time your task runs.</p>" +
      "<p>It is a forecast, so the real figure can be higher or lower, especially more than a day ahead. Running things at cleaner times does not change your bill unless you are on a tariff that changes with the time of day.</p>" +
      '<p class="area-note">' + CI_CREDIT + "</p></section>";
    const form = host.querySelector("[data-bt-form]");
    const out = host.querySelector("[data-bt-result]");
    const cache = { 18: first };
    async function run() {
      const region = +form.region.value, n = +form.len.value, limit = +form.by.value;
      if (!cache[region]) {
        out.innerHTML = empty("Loading the forecast for " + (nameOf[region] || "this area") + "…");
        cache[region] = await getJSON("forecast.php?region=" + region);
      }
      const d = cache[region];
      if (!d || !d.carbon.times.length) { out.innerHTML = empty("The forecast for this area is not available right now."); return; }
      const c = d.carbon;
      const best = bestWindow(c.gco2, n, limit);
      if (!best) { out.innerHTML = empty("The forecast does not reach far enough ahead for that. Try a shorter task or a later finish."); return; }
      const now = nowAvg(c.gco2, n);
      const endIso = new Date(new Date(c.times[best.start]).getTime() + n * 1800000).toISOString();
      const saving = now != null && now > 0 ? Math.round((1 - best.avg / now) * 100) : null;
      const startsNow = best.start === 0;
      out.innerHTML =
        '<p class="bt-answer"><span class="bt-answer__label">' + (startsNow ? "Best time: start now" : "Best time to start") + '</span> <strong>' + esc(startsNow ? clock(c.times[0]) : when(c.times[best.start])) + "</strong></p>" +
        "<p>Runs until " + esc(when(endIso)) + ", at an average of <strong>" + Math.round(best.avg) + " g of CO₂ per kWh</strong>" +
        (startsNow ? ". Now is already the cleanest time in the window you chose." :
          (now != null ? ". Starting now would average " + Math.round(now) + " g" + (saving > 0 ? ", so waiting cuts the carbon by about " + saving + "%." : ".") : ".")) + "</p>" +
        timeline(c, best.start, n) + bandKey() +
        '<p class="area-note">' + esc(nameOf[region] || c.name) + ", forecast made " + esc(d.updated ? when(d.updated) : "recently") + ". The outlined cells are the best window. Each cell is half an hour.</p>";
    }
    form.addEventListener("change", run);
    run();
  }

  /* --------------------------------------------------------- Forecasts */
  async function initForecast(host) {
    const d = await getJSON("forecast.php");
    if (!d) { host.innerHTML = empty("The forecasts are not available right now."); return; }
    const c = d.carbon;
    let html = "";
    if (c.times.length) {
      const day = Math.min(48, c.gco2.length);
      let lo = null, hi = null;
      for (let i = 0; i < day; i++) {
        if (c.gco2[i] == null) continue;
        if (lo == null || c.gco2[i] < c.gco2[lo]) lo = i;
        if (hi == null || c.gco2[i] > c.gco2[hi]) hi = i;
      }
      html += '<section class="home-hero area-now" aria-label="Carbon intensity coming up"><div class="home-hero__main">' +
        '<p class="home-hero__pill"><span class="home-hero__dot" aria-hidden="true"></span><span class="home-hero__updated">Forecast made at:</span> <span>' + esc(d.updated ? clock(d.updated) : "-") + "</span></p>" +
        '<p class="area-now__big"><span class="home-hero__share">' + esc(lo != null ? when(c.times[lo]) : "-") + '</span> <span class="home-hero__lead">is forecast to be the cleanest half hour in the next 24 hours' + (lo != null ? ", at " + c.gco2[lo] + " g of CO₂ per kWh" : "") + "</span></p></div>" +
        '<div class="home-hero__card"><p>' + (hi != null ? "The dirtiest is forecast at " + esc(when(c.times[hi])) + ", at " + c.gco2[hi] + " g. " : "") + 'Want to plan around it? <a href="best-time.html">Find the best time to use power</a>.</p></div></section>';
      html += '<section class="panel" aria-labelledby="fc-ci-h"><h2 id="fc-ci-h">Carbon intensity, next 2 days</h2>' +
        '<p class="chart-caption">How much carbon dioxide each unit of electricity is forecast to give off in Great Britain, every half hour, in grams per kWh.</p>' +
        timeline(c, null, 0) + bandKey() +
        '<div class="chart-wrap" style="height:240px;"><canvas id="fc-ci" role="img" aria-label="Line chart of forecast carbon intensity for the next two days"></canvas></div>' +
        '<p class="area-note">' + CI_CREDIT + ' <a href="regional-carbon.html">See it for each part of the country</a>.</p></section>';
    } else {
      html += '<section class="panel">' + empty("The carbon forecast is not available right now.") + "</section>";
    }
    html += '<div class="gp-cols">' +
      '<section class="panel" aria-labelledby="fc-dem-h"><h2 id="fc-dem-h">Demand</h2><p class="chart-caption">National demand forecast, every half hour, in GW.</p>' +
      (d.demand.times.length ? '<div class="chart-wrap" style="height:220px;"><canvas id="fc-dem" role="img" aria-label="Line chart of forecast national demand"></canvas></div>' + peakLine(d.demand, "Demand") : empty("The demand forecast is not available right now.")) +
      '<p class="area-note">National demand is what Great Britain uses from power stations connected to the transmission network, not counting most rooftop solar and small wind farms. Source: <a href="https://bmrs.elexon.co.uk/">Elexon Insights</a> (national demand forecast, NDF).</p></section>' +
      '<section class="panel" aria-labelledby="fc-wind-h"><h2 id="fc-wind-h">Wind</h2><p class="chart-caption">Wind generation forecast, every hour, in GW.</p>' +
      (d.wind.times.length ? '<div class="chart-wrap" style="height:220px;"><canvas id="fc-wind" role="img" aria-label="Line chart of forecast wind generation"></canvas></div>' + peakLine(d.wind, "Wind") : empty("The wind forecast is not available right now.")) +
      '<p class="area-note">Covers wind farms metered by the transmission network, so smaller wind farms are not included. Source: <a href="https://bmrs.elexon.co.uk/">Elexon Insights</a> (wind generation forecast, WINDFOR).</p></section></div>';
    host.innerHTML = html;
    if (!GP.renderLineChart) return;
    const draw = (id, times, values, unit, color, title) => {
      const el = document.getElementById(id);
      if (el) GP.renderLineChart(el, { labels: times.map(axisLabel), times, values, unit }, { label: title, color, exportTitle: title + " - Grid Pulse" });
    };
    if (c.times.length) draw("fc-ci", c.times, c.gco2.map((v) => (v == null ? 0 : v)), " g", "#5b6b7f", "Carbon intensity forecast, g/kWh");
    if (d.demand.times.length) draw("fc-dem", d.demand.times, d.demand.mw.map((v) => v / 1000), " GW", "#2f6fd6", "National demand forecast, GW");
    if (d.wind.times.length) draw("fc-wind", d.wind.times, d.wind.mw.map((v) => v / 1000), " GW", "#2fa876", "Wind generation forecast, GW");
  }
  function peakLine(s, what) {
    let hi = 0, lo = 0;
    s.mw.forEach((v, i) => { if (v > s.mw[hi]) hi = i; if (v < s.mw[lo]) lo = i; });
    return '<p class="area-note">' + what + " is forecast to peak at " + gw(s.mw[hi]) + " GW at " + esc(when(s.times[hi])) + " and be lowest at " + gw(s.mw[lo]) + " GW at " + esc(when(s.times[lo])) + ".</p>";
  }

  /* ---------------------------------------------------- Carbon by area */
  /** An SVG map from the lat/lon rings, squashed by cos(55°) so it isn't stretched. */
  function areaSvg(map, fillFor, labelFor) {
    const k = Math.cos((55 * Math.PI) / 180);
    let minX = 1e9, maxX = -1e9, minY = 1e9, maxY = -1e9;
    map.regions.forEach((r) => r.rings.forEach((ring) => ring.forEach(([lat, lon]) => {
      const x = lon * k, y = -lat;
      minX = Math.min(minX, x); maxX = Math.max(maxX, x); minY = Math.min(minY, y); maxY = Math.max(maxY, y);
    })));
    const W = 400, s = W / (maxX - minX), H = Math.round((maxY - minY) * s);
    const paths = map.regions.map((r) => {
      const dstr = r.rings.map((ring) => "M" + ring.map(([lat, lon]) => ((lon * k - minX) * s).toFixed(1) + "," + ((-lat - minY) * s).toFixed(1)).join("L") + "Z").join("");
      return '<path d="' + dstr + '" data-map-id="' + r.id + '" style="fill:' + fillFor(r) + '"><title>' + esc(labelFor(r)) + "</title></path>";
    }).join("");
    return '<svg class="ci-map" viewBox="0 0 ' + W + " " + H + '" role="img" aria-label="Map of Great Britain\'s 14 network areas coloured by carbon intensity right now. The list beside it gives every area.">' + paths + "</svg>";
  }

  function mixBars(mix) {
    if (!mix) return empty("No generation mix given for this area.");
    const keys = Object.keys(mix).filter((k) => mix[k] > 0).sort((a, b) => mix[b] - mix[a]);
    return '<ul class="gp-list ci-mix">' + keys.map((k) => '<li><span>' + esc(FUEL[k] || k) + '</span><span class="ci-mix__bar"><i style="width:' + Math.min(100, mix[k]).toFixed(1) + '%"></i></span><strong>' + mix[k].toFixed(1) + "%</strong></li>").join("") + "</ul>";
  }

  async function initRegional(host) {
    const [d, map] = await Promise.all([getJSON("forecast.php"), fetch(ROOT + "assets/maps/gb-dno-ll.json" + VER).then((r) => (r.ok ? r.json() : null)).catch(() => null)]);
    if (!d || !d.areas.length) { host.innerHTML = empty("Carbon intensity by area is not available right now. It updates every 30 minutes."); return; }
    const areas = d.areas.filter((a) => a.id <= 14);
    const gb = d.areas.find((a) => a.id === 18);
    const byMap = {};
    areas.forEach((a) => { if (a.map_id) byMap[a.map_id] = a; });
    const sorted = areas.slice().sort((a, b) => (a.gco2 == null) - (b.gco2 == null) || a.gco2 - b.gco2);
    const cleanest = sorted[0], dirtiest = sorted[sorted.length - 1];
    let html = '<section class="home-hero area-now" aria-label="Carbon by area right now"><div class="home-hero__main">' +
      '<p class="home-hero__pill"><span class="home-hero__dot" aria-hidden="true"></span><span class="home-hero__updated">Half hour from:</span> <span>' + esc(d.now ? clock(d.now) : "-") + "</span></p>" +
      '<p class="area-now__big"><span class="home-hero__share">' + esc(cleanest.name) + '</span> <span class="home-hero__lead">has the cleanest electricity right now, at ' + cleanest.gco2 + " g of CO₂ per kWh</span></p></div>" +
      '<div class="home-hero__card"><p>' + esc(dirtiest.name) + " is highest, at " + dirtiest.gco2 + " g." + (gb ? " Great Britain as a whole is at " + gb.gco2 + " g (" + bandName(gb.index) + ")." : "") + "</p></div></section>";
    html += '<section class="panel" aria-labelledby="rc-map-h"><h2 id="rc-map-h">By network area, right now</h2><div class="rc-grid">' +
      '<div class="rc-mapbox">' + (map ? areaSvg(map, (r) => { const a = byMap[r.id]; const b = a && bandOf(a.index); return b ? b[2] : "var(--border)"; }, (r) => { const a = byMap[r.id]; return a ? a.name + ": " + a.gco2 + " g, " + bandName(a.index) : r.name + ": no figure"; }) : empty("The map could not be loaded. Every area is listed beside it.")) + bandKey() + "</div>" +
      '<div class="rc-side"><ul class="rc-list" data-rc-list>' + sorted.map((a) => {
        const b = bandOf(a.index);
        return '<li><button type="button" class="pc-item" data-rc="' + a.id + '"><i class="ci-dot" style="background:' + (b ? b[2] : "var(--border)") + '" aria-hidden="true"></i><span>' + esc(a.name) + " <small>(" + esc(bandName(a.index)) + ")</small></span><strong>" + (a.gco2 == null ? "-" : a.gco2 + " g") + "</strong></button></li>";
      }).join("") + '</ul><div class="pc-detail" data-rc-detail aria-live="polite"></div></div></div>' +
      '<p class="area-note">Select an area on the map or in the list to see where its power comes from and its forecast. Carbon intensity is in grams of CO₂ per kWh. The figure for an area counts the power stations in and near it, and the power flowing in from elsewhere, so it can change a lot from one part of the country to the next.</p></section>';
    html += '<section class="panel" aria-labelledby="rc-fc-h"><h2 id="rc-fc-h" data-rc-fc-h>Next 2 days</h2><div data-rc-fc>' + empty("Select an area to see its forecast.") + "</div></section>";
    html += '<section class="panel"><p class="area-note">' + CI_CREDIT + " Area boundaries: Supported by National Energy SO Open Data. These are forecasts for the current half hour; the API's regional figures are all estimates.</p></section>";
    host.innerHTML = html;
    const detail = host.querySelector("[data-rc-detail]");
    const fcBox = host.querySelector("[data-rc-fc]");
    const fcH = host.querySelector("[data-rc-fc-h]");
    async function select(id) {
      const a = areas.find((x) => x.id === id);
      if (!a) return;
      host.querySelectorAll("[data-rc]").forEach((b) => b.classList.toggle("is-selected", +b.getAttribute("data-rc") === id));
      host.querySelectorAll(".ci-map path").forEach((p) => p.classList.toggle("is-selected", p.getAttribute("data-map-id") === a.map_id));
      detail.innerHTML = '<h3 class="area-sub">' + esc(a.name) + "</h3><p>" + (a.gco2 == null ? "No figure" : "<strong>" + a.gco2 + " g of CO₂ per kWh</strong>, " + esc(bandName(a.index))) + ".</p>" + mixBars(a.mix);
      fcH.textContent = "Next 2 days in " + a.name;
      fcBox.innerHTML = empty("Loading…");
      const f = await getJSON("forecast.php?region=" + id);
      if (!f || !f.carbon.times.length) { fcBox.innerHTML = empty("No forecast for this area right now."); return; }
      const best = bestWindow(f.carbon.gco2, 1, 48);
      fcBox.innerHTML = timeline(f.carbon, best ? best.start : null, 1) + bandKey() +
        (best ? '<p class="area-note">Cleanest half hour in the next 24 hours: ' + esc(when(f.carbon.times[best.start])) + ", at " + Math.round(best.avg) + ' g (outlined). <a href="best-time.html">Plan a longer task</a>.</p>' : "");
    }
    host.querySelector("[data-rc-list]").addEventListener("click", (ev) => { const b = ev.target.closest("[data-rc]"); if (b) select(+b.getAttribute("data-rc")); });
    host.querySelectorAll(".ci-map path").forEach((p) => p.addEventListener("click", () => { const a = byMap[p.getAttribute("data-map-id")]; if (a) select(a.id); }));
    select(cleanest.id);
  }

  /* --------------------------------------------------------- Frequency */
  function hz(v) { return v == null ? "-" : v.toFixed(3); }
  function freqWords(v) {
    const off = Math.abs(v - 50);
    if (off < 0.05) return "right on target";
    return (v > 50 ? "a little above" : "a little below") + " 50 Hz, by " + off.toFixed(3) + " Hz" + (v > 50 ? ": there is slightly more power going in than being used" : ": slightly more power is being used than going in");
  }
  async function initFrequency(host) {
    const f = await getJSON("frequency.php");
    if (!f || !f.latest) { host.innerHTML = empty("No frequency readings yet. They arrive every 5 minutes once the scheduler is running."); return; }
    const L = f.latest;
    const old = (Date.now() - new Date(L.ts).getTime()) / 60000 > 20;
    let html = '<section class="home-hero area-now" aria-label="Grid frequency right now"><div class="home-hero__main">' +
      '<p class="home-hero__pill"><span class="home-hero__dot" aria-hidden="true"></span><span class="home-hero__updated">Reading at:</span> <span>' + esc(clock(L.ts)) + "</span></p>" +
      '<p class="area-now__big"><span class="home-hero__share">' + hz(L.hz) + '</span> <span class="home-hero__lead">hertz: the frequency of Great Britain\'s grid</span></p></div>' +
      '<div class="home-hero__card"><p>That is ' + esc(freqWords(L.hz)) + "." + (old ? " This reading is more than 20 minutes old, so it may be behind." : "") + "</p></div></section>";
    html += '<section class="panel" aria-labelledby="fq-h-h"><h2 id="fq-h-h">The last hour</h2><p class="chart-caption">Every reading, one every 15 seconds, in hertz.</p>' +
      '<div class="chart-wrap" style="height:240px;"><canvas id="fq-hour" role="img" aria-label="Line chart of grid frequency over the last hour"></canvas></div></section>';
    html += '<section class="panel" aria-labelledby="fq-d-h"><h2 id="fq-d-h">The last 24 hours</h2><p class="chart-caption">Average each minute, in hertz.</p>' +
      '<div class="chart-wrap" style="height:220px;"><canvas id="fq-day" role="img" aria-label="Line chart of grid frequency over the last 24 hours"></canvas></div>' +
      (f.low && f.high ? '<p class="area-note">Lowest reading: ' + hz(f.low.hz) + " Hz at " + esc(when(f.low.ts)) + ". Highest: " + hz(f.high.hz) + " Hz at " + esc(when(f.high.ts)) + ".</p>" : "") + "</section>";
    host.innerHTML = html;
    if (!GP.renderLineChart) return;
    const ref = (n) => ({ values: new Array(n).fill(50), label: "50 Hz" });
    GP.renderLineChart(document.getElementById("fq-hour"), { labels: f.hour.times.map(clock), times: f.hour.times, values: f.hour.hz, unit: " Hz" },
      { label: "Frequency", color: "#2f6fd6", compareSeries: ref(f.hour.hz.length), exportTitle: "Grid frequency, last hour - Grid Pulse" });
    GP.renderLineChart(document.getElementById("fq-day"), { labels: f.day.times.map((t, i, a) => axisLabel(t, i, a)), times: f.day.times, values: f.day.hz, unit: " Hz" },
      { label: "Frequency", color: "#2f6fd6", compareSeries: ref(f.day.hz.length), exportTitle: "Grid frequency, last 24 hours - Grid Pulse" });
  }

  /* ---------------------------------------------------- Balancing costs */
  const money = (v) => {
    if (v == null) return "-";
    const a = Math.abs(v), s = v < 0 ? "−£" : "£";
    if (a >= 1e9) return s + (a / 1e9).toFixed(2) + "bn";
    if (a >= 1e6) return s + (a / 1e6).toFixed(1) + "m";
    if (a >= 1e3) return s + Math.round(a / 1e3) + "k";
    return s + Math.round(a);
  };
  const ITEM_NOTE = {
    "Energy Imbalance": "Buying or selling power to cover the gap between what was generated, what was used and what was traded ahead. Can be negative.",
    "Frequency Control": "Response and fast reserve: services that keep the frequency near 50 Hz.",
    "Positive Reserve": "Power held ready to cover shortfalls, such as a power station breaking down or demand being higher than forecast.",
    "Negative Reserve": "Being ready to cut generation or raise demand, for example when wind or rooftop solar is higher than expected.",
    "Constraints": "Actions to keep the network within its limits: protecting equipment, staying within the security standard and preventing large parts of the network being lost.",
    "Other": "Other actions and general costs, such as option fees, bank charges and reconciliation.",
  };
  const monthName = (m) => new Date(m + "-15T12:00:00Z").toLocaleDateString("en-GB", { month: "short", year: "numeric", timeZone: "UTC" });
  const dayName = (d) => new Date(d + "T12:00:00Z").toLocaleDateString("en-GB", { day: "numeric", month: "short", timeZone: "UTC" });

  async function initBalancing(host) {
    const b = await getJSON("balancing.php");
    if (!b || !b.latest) { host.innerHTML = empty("Balancing costs are not available yet. They update twice a day from NESO."); return; }
    const items = b.items;
    const D = b.days, M = b.months;
    const dayTotal = D.dates.map((_, i) => items.reduce((s, it) => s + ((D.items[it] || [])[i] || 0), 0));
    const last30 = dayTotal.slice(-30);
    const sum30 = last30.reduce((s, v) => s + v, 0);
    const by30 = items.map((it) => [it, (D.items[it] || []).slice(-30).reduce((s, v) => s + (v || 0), 0)]).sort((a, b2) => b2[1] - a[1]);
    let html = '<section class="home-hero area-now" aria-label="Balancing costs"><div class="home-hero__main">' +
      '<p class="home-hero__pill"><span class="home-hero__dot" aria-hidden="true"></span><span class="home-hero__updated">Latest day:</span> <span>' + esc(dayName(b.latest)) + "</span></p>" +
      '<p class="area-now__big"><span class="home-hero__share">' + money(sum30) + '</span> <span class="home-hero__lead">spent by NESO balancing Great Britain\'s electricity system in the last ' + last30.length + " days</span></p></div>" +
      '<div class="home-hero__card"><p>That is about ' + money(sum30 / Math.max(1, last30.length)) + " a day. The biggest part was " + esc(by30[0][0].toLowerCase()) + ", at " + money(by30[0][1]) + ". " + esc(dayName(b.latest)) + " alone cost " + money(dayTotal[dayTotal.length - 1]) + ".</p></div></section>";
    html += '<section class="panel" aria-labelledby="bc-30-h"><h2 id="bc-30-h">The last 30 days, by type</h2><ul class="gp-list bc-list">' +
      by30.map(([it, v]) => "<li><span>" + esc(it) + "<small>" + esc(ITEM_NOTE[it] || "") + "</small></span><strong>" + money(v) + "</strong></li>").join("") + "</ul>" +
      '<p class="area-note">Descriptions follow NESO\'s own definitions of each type. A negative figure means NESO received more than it paid for that type.</p></section>';
    html += '<section class="panel" aria-labelledby="bc-d-h"><h2 id="bc-d-h">Each day, the last 90 days</h2><p class="chart-caption">Total balancing cost each settlement day, in £ million.</p>' +
      '<div class="chart-wrap" style="height:240px;"><canvas id="bc-days" role="img" aria-label="Line chart of daily balancing costs over the last 90 days"></canvas></div></section>';
    const mTot = M.months.map((_, i) => items.reduce((s, it) => s + ((M.items[it] || [])[i] || 0), 0));
    html += '<section class="panel" aria-labelledby="bc-m-h"><h2 id="bc-m-h">Each month</h2>' +
      '<div class="area-table-scroll" tabindex="0" role="region" aria-labelledby="bc-m-h"><table class="area-daylist"><thead><tr><th scope="col">Month</th>' + items.map((it) => '<th scope="col" class="num">' + esc(it) + "</th>").join("") + '<th scope="col" class="num">Total</th></tr></thead><tbody>' +
      M.months.slice().reverse().map((m, ri) => {
        const i = M.months.length - 1 - ri;
        const part = M.days[i] < new Date(Date.UTC(+m.slice(0, 4), +m.slice(5, 7), 0)).getUTCDate();
        return '<tr><th scope="row">' + esc(monthName(m)) + (part ? " <small>(" + M.days[i] + " days)</small>" : "") + "</th>" + items.map((it) => '<td class="num">' + money((M.items[it] || [])[i]) + "</td>").join("") + '<td class="num"><strong>' + money(mTot[i]) + "</strong></td></tr>";
      }).join("") + "</tbody></table></div>" +
      '<p class="area-note">Months with fewer days so far are marked. NESO publishes each day after its first settlement run, usually a day or two later. Source: <a href="https://www.neso.energy/data-portal/daily-balancing-costs-balancing-services-use-of-system">NESO Data Portal, Daily Balancing Costs (BSUoS)</a>, NESO Open Data Licence. Supported by National Energy SO Open Data.</p></section>';
    host.innerHTML = html;
    if (GP.renderLineChart && D.dates.length) {
      GP.renderLineChart(document.getElementById("bc-days"), { labels: D.dates.map(dayName), times: D.dates.map((d) => d + "T12:00:00Z"), values: dayTotal.map((v) => v / 1e6), unit: "m" },
        { label: "Balancing cost, £m", color: "#8a5a3c", exportTitle: "Daily balancing costs, last 90 days - Grid Pulse" });
    }
  }

  /* ------------------------------------------------ GB "Coming up" card */
  async function initCard(host) {
    const body = host.querySelector("[data-outlook-card-body]") || host;
    const [d, f] = await Promise.all([getJSON("forecast.php"), getJSON("frequency.php")]);
    const rows = [];
    if (d && d.carbon.times.length) {
      const best = bestWindow(d.carbon.gco2, 1, 48);
      if (best) rows.push(["Cleanest half hour, next 24 hours", esc(when(d.carbon.times[best.start])) + " (" + Math.round(best.avg) + " g/kWh)"]);
    }
    if (d && d.wind.times.length) {
      let hi = 0;
      d.wind.mw.forEach((v, i) => { if (v > d.wind.mw[hi]) hi = i; });
      rows.push(["Windiest, next 2 days", gw(d.wind.mw[hi]) + " GW at " + esc(when(d.wind.times[hi]))]);
    }
    if (f && f.latest) rows.push(["Grid frequency", hz(f.latest.hz) + " Hz at " + esc(clock(f.latest.ts))]);
    if (!rows.length) { body.innerHTML = empty("Forecasts are not available right now."); return; }
    body.innerHTML = '<dl class="area-facts">' + rows.map((r) => "<div><dt>" + esc(r[0]) + "</dt><dd>" + r[1] + "</dd></div>").join("") + "</dl>" +
      '<a class="gp-card__link" href="' + ROOT + 'pages/best-time.html">Find the best time to use power →</a>' +
      '<a class="gp-card__link" href="' + ROOT + 'pages/forecast.html">Forecasts for the next 2 days →</a>';
  }

  function init() {
    const map = [["[data-forecast-page]", initForecast], ["[data-besttime-page]", initBestTime], ["[data-regional-page]", initRegional],
      ["[data-frequency-page]", initFrequency], ["[data-balancing-page]", initBalancing], ["[data-outlook-card]", initCard]];
    map.forEach(([sel, fn]) => { const el = document.querySelector(sel); if (el) fn(el); });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
