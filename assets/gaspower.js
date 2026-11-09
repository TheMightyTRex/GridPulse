/* ==========================================================================
   Grid Pulse - gas and power cuts (Great Britain)
   Fills:
     [data-gas-page]        pages/gas.html
     [data-gas-card]        the "Gas right now" card on the GB dashboard
     [data-powercuts-page]  pages/power-cuts.html
   Data: api/gas.php (National Gas Transmission) and api/power_cuts.php
   (the network operators' open data). See includes/gas.php and
   includes/powercuts.php.
   ========================================================================== */
(function () {
  "use strict";
  const GP = window.GridPreview || {};
  const SRC = document.currentScript ? document.currentScript.src : "";
  const API = (function () {
    const base = SRC.replace(/assets\/gaspower\.js(?:\?.*)?$/, "api/");
    return base && base !== SRC ? base : "api/";
  })();
  const ROOT = API.replace(/api\/$/, "");
  const VER = (function () { const m = SRC.match(/[?&]v=([0-9a-z]+)/); return m ? "?v=" + m[1] : ""; })();
  const TZ = "Europe/London";

  const esc = (s) => String(s == null ? "" : s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const num = (v, d) => (v == null || isNaN(v) ? "-" : Number(v).toLocaleString("en-GB", { minimumFractionDigits: d == null ? 1 : d, maximumFractionDigits: d == null ? 1 : d }));
  const whole = (v) => (v == null ? "-" : Math.round(v).toLocaleString("en-GB"));
  const pct = (v, t) => (t > 0 && v != null ? Math.round((v / t) * 100) : 0);

  /** "1.30pm" UK time. */
  function clock(iso) {
    if (!iso) return "-";
    const parts = new Intl.DateTimeFormat("en-GB", { timeZone: TZ, hour: "numeric", minute: "2-digit", hour12: true }).formatToParts(new Date(iso));
    const h = parts.find((p) => p.type === "hour").value;
    const m = parts.find((p) => p.type === "minute").value;
    const ap = (parts.find((p) => p.type === "dayPeriod") || { value: "" }).value.toLowerCase().replace(/[.\s]/g, "");
    return h + (m === "00" ? "" : "." + m) + ap;
  }
  /** "Mon 9 Nov, 1.30pm" UK time, or just the time if today. */
  function when(iso) {
    if (!iso) return "-";
    const d = new Date(iso);
    const day = (x) => x.toLocaleDateString("en-GB", { timeZone: TZ, day: "numeric", month: "short" });
    return day(d) === day(new Date()) ? clock(iso) : d.toLocaleDateString("en-GB", { timeZone: TZ, weekday: "short", day: "numeric", month: "short" }) + ", " + clock(iso);
  }
  function shortDate(day) {
    return new Date(day + "T12:00:00Z").toLocaleDateString("en-GB", { day: "numeric", month: "short", timeZone: "UTC" });
  }
  function ago(iso) {
    const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (mins < 2) return "just now";
    return mins < 90 ? mins + " minutes ago" : Math.round(mins / 60) + " hours ago";
  }
  async function getJSON(path) {
    try {
      const r = await fetch(API + path, { headers: { Accept: "application/json" } });
      if (!r.ok) return null;
      const j = await r.json();
      return j && j.ok ? j : null;
    } catch (e) { return null; }
  }

  /* ---------------------------------------------------------------- Gas */
  const DEMAND = [
    ["d_ldz", "Homes and businesses", "var(--gas-ldz)", "#2f6fd6"],
    ["d_power", "Power stations", "var(--gas-power)", "#8a5a3c"],
    ["d_industrial", "Large industry", "var(--gas-ind)", "#7a5fd0"],
    ["d_export", "Exports", "var(--gas-export)", "#c47f00"],
    ["d_storage", "Into storage", "var(--gas-store)", "#13897f"],
  ];
  const SUPPLY = [
    ["s_ncs", "North Sea (UK and Norway)", "var(--gas-ncs)", "#24618a"],
    ["s_lng", "LNG ships", "var(--gas-lng)", "#c2410c"],
    ["s_storage", "From storage", "var(--gas-store)", "#13897f"],
    ["s_import", "Pipelines from Europe", "var(--gas-ind)", "#7a5fd0"],
  ];
  const UNIT_NOTE = "Gas is measured in million cubic metres (mcm). Live figures are rates: how much would flow in a whole day at that rate.";

  /** A solid stacked bar with a list beside it. */
  function splitBar(defs, vals, label) {
    const total = defs.reduce((s, d) => s + Math.max(0, vals[d[0]] || 0), 0);
    const segs = defs.filter((d) => (vals[d[0]] || 0) > 0).map((d) =>
      '<span style="flex:' + ((vals[d[0]] / total) * 100).toFixed(2) + ";background:" + d[2] + '"></span>').join("");
    const rows = defs.map((d) => '<li><span><i class="gas-sw" style="background:' + d[2] + '"></i>' + esc(d[1]) + "</span><strong>" + num(vals[d[0]]) + " <small>mcm/d, " + pct(vals[d[0]], total) + "%</small></strong></li>").join("");
    return '<div class="gas-split" role="img" aria-label="' + esc(label + ": " + defs.map((d) => d[1] + " " + pct(vals[d[0]], total) + "%").join(", ")) + '">' + segs + "</div>" +
      '<ul class="gp-list gas-list">' + rows + "</ul>";
  }

  /** SVG stacked columns, one per gas day, with a <title> on each part. */
  function dailyColumns(daily, defs, width) {
    const n = daily.days.length;
    if (!n) return '<p class="area-empty">No daily totals yet. They arrive the day after each gas day.</p>';
    const W = Math.max(300, Math.round(width || 640)), H = 220, padL = 40, padB = 26, padT = 8;
    const totals = daily.days.map((_, i) => defs.reduce((s, d) => s + Math.max(0, (daily.items[d[0]] || [])[i] || 0), 0));
    const max = Math.max(...totals, 1) * 1.08;
    const step = (W - padL - 4) / n, bw = Math.max(3, step * 0.72);
    const y = (v) => padT + (H - padT - padB) * (1 - v / max);
    let grid = "";
    for (let k = 0; k <= 4; k++) {
      const v = (max / 4) * k;
      grid += '<line x1="' + padL + '" x2="' + W + '" y1="' + y(v).toFixed(1) + '" y2="' + y(v).toFixed(1) + '" class="gas-grid"/><text x="' + (padL - 6) + '" y="' + (y(v) + 4).toFixed(1) + '" text-anchor="end" class="gas-axis">' + Math.round(v) + "</text>";
    }
    let cols = "";
    daily.days.forEach((day, i) => {
      let base = 0;
      const x = padL + i * step + (step - bw) / 2;
      defs.forEach((d) => {
        const v = Math.max(0, (daily.items[d[0]] || [])[i] || 0);
        if (!v) return;
        const y0 = y(base), y1 = y(base + v);
        cols += '<rect x="' + x.toFixed(1) + '" y="' + y1.toFixed(1) + '" width="' + bw.toFixed(1) + '" height="' + Math.max(0.5, y0 - y1).toFixed(1) + '" style="fill:' + d[2] + '"><title>' + esc(shortDate(day) + ", " + d[1] + ": " + num(v) + " mcm") + "</title></rect>";
        base += v;
      });
      if (i % Math.ceil(n / 7) === 0 || i === n - 1) cols += '<text x="' + (x + bw / 2).toFixed(1) + '" y="' + (H - 8) + '" text-anchor="middle" class="gas-axis">' + esc(shortDate(day)) + "</text>";
    });
    const last = daily.days.length - 1;
    const aria = "Gas used each day for the last " + n + " gas days, split by use. Latest, " + shortDate(daily.days[last]) + ": " + defs.map((d) => d[1] + " " + num((daily.items[d[0]] || [])[last]) + " mcm").join(", ") + ".";
    return '<svg class="gas-cols" viewBox="0 0 ' + W + " " + H + '" role="img" aria-label="' + esc(aria) + '">' + grid + cols + "</svg>";
  }

  function dailyTable(daily, defs) {
    const head = "<tr><th scope=\"col\">Gas day</th>" + defs.map((d) => '<th scope="col" class="num">' + esc(d[1]) + "</th>").join("") + '<th scope="col" class="num">Total</th></tr>';
    const rows = daily.days.slice().reverse().map((day, ri) => {
      const i = daily.days.length - 1 - ri;
      let t = 0;
      const cells = defs.map((d) => { const v = (daily.items[d[0]] || [])[i]; t += v || 0; return '<td class="num">' + num(v) + "</td>"; }).join("");
      return "<tr><th scope=\"row\">" + esc(shortDate(day)) + "</th>" + cells + '<td class="num">' + num(t) + "</td></tr>";
    }).join("");
    return '<details class="gp-fold gas-table"><summary>Show the daily figures as a table</summary><div class="area-table-scroll" tabindex="0" role="region" aria-label="Gas used each day, in mcm"><table class="area-daylist"><caption class="visually-hidden">Gas used each day by use, million cubic metres</caption><thead>' + head + "</thead><tbody>" + rows + "</tbody></table></div></details>";
  }

  async function initGasPage(host) {
    const g = await getJSON("gas.php");
    if (!g || !g.latest_ts) {
      host.innerHTML = '<p class="area-empty">No gas readings yet. They arrive every 12 minutes once the scheduler is running.</p>';
      return;
    }
    const L = g.latest;
    const total = L.d_total;
    const stale = (Date.now() - new Date(g.latest_ts).getTime()) / 60000 > 45;
    const powerShare = pct(L.d_power, total);
    const ldzShare = pct(L.d_ldz, total);
    let html = '<section class="home-hero area-now gas-now" aria-label="Gas right now"><div class="home-hero__main">' +
      '<p class="home-hero__pill"><span class="home-hero__dot" aria-hidden="true"></span><span class="home-hero__updated">Live, updated at:</span> <span>' + esc(clock(g.latest_ts)) + "</span></p>" +
      '<p class="area-now__big"><span class="home-hero__share">' + num(total, 0) + '</span> <span class="home-hero__lead">million cubic metres of gas a day: the rate Great Britain is using gas right now</span></p></div>' +
      '<div class="home-hero__card"><p>Homes and businesses are taking ' + ldzShare + "% of it and power stations " + powerShare + "%." +
      (stale ? " These readings are more than 45 minutes old, so they may be behind." : "") + "</p></div></section>";

    html += '<div class="gp-cols gas-cols-2">' +
      '<section class="panel" aria-labelledby="gas-use-h"><h2 id="gas-use-h">Where the gas is going</h2>' + splitBar(DEMAND, L, "Gas use right now") +
      '<p class="area-note">Total ' + num(total) + " mcm/d. Homes and businesses means gas taken off the national network by the local gas networks: homes, offices, shops and smaller factories.</p></section>" +
      '<section class="panel" aria-labelledby="gas-src-h"><h2 id="gas-src-h">Where it is coming from</h2>' + splitBar(SUPPLY, L, "Gas supply right now") +
      '<p class="area-note">Total ' + num(L.s_total) + " mcm/d. Supply and use rarely match minute by minute. The difference comes from, or goes into, the gas held in the pipes (linepack: " + num(L.linepack, 0) + " mcm now).</p></section></div>";

    html += '<section class="panel" aria-labelledby="gas-24-h"><h2 id="gas-24-h">The last 24 hours</h2>' +
      '<p class="chart-caption">Gas use by type, every 12 minutes, in mcm per day.</p>' +
      '<div class="chart-wrap" style="height:280px;"><canvas id="gas-stack" role="img" aria-label="Stacked chart of gas use by type over the last 24 hours"></canvas></div><ul class="chart-legend" id="gas-stack-legend" style="justify-content:center;"></ul>' +
      '<p class="chart-caption">Gas supply by source over the same 24 hours.</p>' +
      '<div class="chart-wrap" style="height:240px;"><canvas id="gas-supply" role="img" aria-label="Stacked chart of gas supply by source over the last 24 hours"></canvas></div><ul class="chart-legend" id="gas-supply-legend" style="justify-content:center;"></ul></section>';

    html += '<section class="panel" aria-labelledby="gas-30-h"><h2 id="gas-30-h">Each day, the last month</h2>' +
      '<p class="chart-caption">Gas used each gas day (5am to 5am), split by use, in mcm. National Gas publishes each day\'s total the day after.</p>' +
      '<div data-gas-daily></div>' + '<p class="gpmap-key gas-key">' + DEMAND.map((d) => '<span><i style="background:' + d[2] + '"></i>' + esc(d[1]) + "</span>").join("") + "</p>" +
      (g.daily.days.length ? dailyTable(g.daily, DEMAND) : "") + "</section>";

    html += '<section class="panel" aria-labelledby="gas-about-h"><h2 id="gas-about-h">About these figures</h2>' +
      "<p>" + UNIT_NOTE + "</p>" +
      "<p>Live readings are taken every 2 minutes and published every 12 minutes. They cover the national transmission network, so gas used by homes and businesses is measured where it leaves for the local gas networks.</p>" +
      '<p class="area-note">Source: <a href="https://data.nationalgas.com/">National Gas Transmission Data Portal</a> (Instantaneous Flow and Published Data APIs). Data shown with the acknowledgement National Gas asks for. Latest reading ' + esc(when(g.latest_ts)) + ".</p></section>";

    host.innerHTML = html;
    const dailyBox = host.querySelector("[data-gas-daily]");
    const drawDaily = () => { dailyBox.innerHTML = dailyColumns(g.daily, DEMAND, dailyBox.clientWidth); };
    drawDaily();
    let rt;
    window.addEventListener("resize", () => { clearTimeout(rt); rt = setTimeout(drawDaily, 150); });
    const s = g.series;
    if (s && s.times.length && GP.renderStackedAreaChart) {
      const labels = s.times.map(clock);
      GP.renderStackedAreaChart(document.getElementById("gas-stack"), s.times, labels,
        DEMAND.map((d) => ({ name: d[1], color: d[3], values: (s.items[d[0]] || []).map((v) => v || 0) })),
        { unit: " mcm/d", legendTarget: "gas-stack-legend", exportTitle: "Great Britain gas use, last 24 hours - Grid Pulse" });
      GP.renderStackedAreaChart(document.getElementById("gas-supply"), s.times, labels,
        SUPPLY.map((d) => ({ name: d[1], color: d[3], values: (s.items[d[0]] || []).map((v) => v || 0) })),
        { unit: " mcm/d", legendTarget: "gas-supply-legend", exportTitle: "Great Britain gas supply, last 24 hours - Grid Pulse" });
    }
  }

  async function initGasCard(host) {
    const body = host.querySelector("[data-gas-card-body]") || host;
    const g = await getJSON("gas.php");
    if (!g || !g.latest_ts) {
      body.innerHTML = '<p class="area-empty">No gas readings yet.</p>';
      return;
    }
    const L = g.latest;
    body.innerHTML = '<p class="gp-card__big">' + num(L.d_total, 0) + ' <small class="gas-unit">mcm a day</small></p>' +
      splitBar(DEMAND, L, "Gas use right now") +
      '<p class="area-note">Power stations are using ' + pct(L.d_power, L.d_total) + "% of Great Britain's gas right now. Latest reading " + esc(clock(g.latest_ts)) + ". Source: National Gas Transmission.</p>" +
      '<a class="gp-card__link" href="' + ROOT + 'pages/gas.html">Gas use and supply, today and this month →</a>';
  }

  /* -------------------------------------------------------- Power cuts */
  const LINKS = {
    NGED: "https://powercuts.nationalgrid.co.uk/",
    UKPN: "https://www.ukpowernetworks.co.uk/power-cut/map",
    NPG: "https://www.northernpowergrid.com/power-cuts-map",
    SSEN: "https://powertrack.ssen.co.uk/powertrack",
    SPEN: "https://powercuts.spenergynetworks.co.uk/map",
    ENWL: "https://www.enwl.co.uk/power-cuts/",
  };
  const CREDIT = {
    NGED: "Supported by NGED Open Data",
    UKPN: "UK Power Networks, CC BY 4.0",
    NPG: "Supported by Northern Powergrid Open Data",
    SSEN: "SSEN, CC BY 4.0",
    SPEN: "SP Energy Networks, CC BY 4.0",
    ENWL: "Electricity North West, CC BY 4.0",
  };

  async function getMap() {
    try {
      const r = await fetch(ROOT + "assets/maps/gb-dno-ll.json" + VER);
      return r.ok ? await r.json() : null;
    } catch (e) { return null; }
  }

  /* The power cuts map: an OpenStreetMap street map (Leaflet, served from
     assets/vendor/leaflet) with the 14 network areas drawn over it.
     Pointing at an area highlights it; selecting it zooms to the whole
     area. Select a dot, or a cut in the "In view" list, to see its
     details. The list always shows the cuts in the part of the map in view. */
  function cutsMapHtml(map) {
    const opts = map.regions.slice().sort((a, b) => a.name.localeCompare(b.name)).map((r) => '<option value="' + r.id + '">' + esc(r.name) + "</option>").join("");
    return '<div class="pc-tools" role="group" aria-label="Map controls">' +
        '<button type="button" class="pc-btn pc-btn--text" data-z="reset">Whole of Great Britain</button>' +
        '<label class="pc-jump">Zoom to <select data-pc-jump><option value="">an area…</option>' + opts + "</select></label>" +
      "</div>" +
      '<div class="pc-mapgrid"><div class="pc-mapbox"><div class="pc-street" data-pc-leaflet role="region" aria-label="Street map of Great Britain with the electricity network areas and power cuts. The list beside it gives every cut in view."><p class="area-empty">Loading the map…</p></div></div>' +
      '<div class="pc-side"><div class="pc-detail" data-pc-detail aria-live="polite"></div>' +
        '<h3 class="area-sub" data-pc-inview-h>In view</h3><ul class="pc-inview" data-pc-inview></ul></div></div>';
  }

  function cutDetail(c, opName) {
    if (!c) return '<p class="area-note">Select an area to zoom in, then select a dot, or a cut in the list, to see its details.</p>';
    const rows = [
      ["Type", c.planned ? "Planned work" : "Power cut"],
      ["Area", c.place || "Not given"],
      ["Customers affected", c.customers != null ? whole(c.customers) : "Not given"],
      ["Started", c.started ? when(c.started) : "Not given"],
      ["Expected back", c.etr ? when(c.etr) : "Not given"],
      ["Network operator", opName],
      ["Reference", c.ref],
    ];
    return '<h3 class="area-sub">' + esc((c.planned ? "Planned work" : "Power cut") + (c.place ? " in " + c.place : "")) + "</h3>" +
      '<dl class="pc-dl">' + rows.map((r) => "<dt>" + esc(r[0]) + "</dt><dd>" + esc(r[1]) + "</dd>").join("") + "</dl>" +
      '<p><a href="' + LINKS[c.dno] + '">Check this on ' + esc(opName) + "'s own page</a> for the latest.</p>";
  }

  function loadLeaflet() {
    if (window.L) return Promise.resolve();
    return new Promise((res, rej) => {
      const css = document.createElement("link");
      css.rel = "stylesheet"; css.href = ROOT + "assets/vendor/leaflet/leaflet.css" + VER;
      document.head.appendChild(css);
      const s = document.createElement("script");
      s.src = ROOT + "assets/vendor/leaflet/leaflet.js" + VER;
      s.onload = res; s.onerror = rej;
      document.head.appendChild(s);
    });
  }

  async function initCutsMap(box, map, ops, cuts) {
    const el = box.querySelector("[data-pc-leaflet]");
    const detail = box.querySelector("[data-pc-detail]");
    const inview = box.querySelector("[data-pc-inview]");
    const inviewH = box.querySelector("[data-pc-inview-h]");
    const opName = {}, covered = {};
    ops.forEach((o) => { opName[o.code] = o.name; covered[o.code] = o.covered && o.fetched_at; });
    const placed = cuts.filter((c) => c.lat != null).sort((a, b) => (b.customers || 0) - (a.customers || 0));
    const maxC = Math.max(1, ...placed.map((c) => c.customers || 0));
    detail.innerHTML = cutDetail(null);
    try { await loadLeaflet(); } catch (e) {
      el.innerHTML = '<p class="area-empty">The map could not be loaded. Every cut is still listed beside it.</p>';
    }
    const L = window.L;
    const css = getComputedStyle(document.documentElement);
    const tok = (n, fb) => css.getPropertyValue(n).trim() || fb;
    let lmap = null, selected = null;
    const markers = [];
    const regionLayers = {};

    function listInView() {
      const b = lmap ? lmap.getBounds() : null;
      const vis = b ? placed.filter((c) => b.contains([c.lat, c.lon])) : placed;
      inviewH.textContent = vis.length ? "In view: " + vis.length + (vis.length === 1 ? " cut" : " cuts") : "In view";
      inview.innerHTML = vis.length ? vis.slice(0, 80).map((c) => '<li><button type="button" class="pc-item' + (c === selected ? " is-selected" : "") + '" data-i="' + placed.indexOf(c) + '">' +
        '<i class="' + (c.planned ? "pc-key-planned" : "pc-key-cut") + '" aria-hidden="true"></i><span>' + esc(c.place || c.ref) + " <small>(" + esc(c.dno) + ")</small></span><strong>" + (c.customers != null ? whole(c.customers) : "-") + "</strong></button></li>").join("") +
        (vis.length > 80 ? '<li class="area-note">And ' + (vis.length - 80) + " more. Zoom in to see them.</li>" : "")
        : '<li class="area-note">No power cuts in this part of the map.</li>';
    }
    function select(c, fly) {
      selected = c;
      detail.innerHTML = cutDetail(c, c ? opName[c.dno] : "");
      markers.forEach((m) => m.setStyle({ weight: m._cut === c ? 3 : 1.5, color: m._cut === c ? tok("--text", "#14181f") : "#ffffff" }));
      if (c && lmap && fly) lmap.setView([c.lat, c.lon], Math.max(lmap.getZoom(), 12));
      listInView();
    }
    function zoomToRegion(id) {
      if (regionLayers[id] && lmap) lmap.fitBounds(regionLayers[id].getBounds(), { padding: [12, 12] });
    }

    if (L) {
      el.innerHTML = "";
      lmap = L.map(el, { scrollWheelZoom: false, zoomSnap: 0.25 });
      L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      }).addTo(lmap);
      const all = [];
      map.regions.forEach((r) => {
        const live = covered[r.dno];
        const base = { color: tok("--accent", "#0a6e5c"), weight: 1.5, opacity: 0.9, fillColor: live ? tok("--accent", "#0a6e5c") : "#7a7a7a", fillOpacity: live ? 0.06 : 0.22, dashArray: live ? null : "4 4" };
        const layer = L.polygon(r.rings, base).addTo(lmap);
        layer.bindTooltip(r.name + (live ? "" : ": no live data here"), { sticky: true });
        layer.on("mouseover", () => layer.setStyle({ weight: 3.5, fillOpacity: live ? 0.22 : 0.32 }));
        layer.on("mouseout", () => layer.setStyle(base));
        layer.on("click", () => zoomToRegion(r.id));
        regionLayers[r.id] = layer;
        all.push(layer);
      });
      const gbBounds = L.featureGroup(all).getBounds();
      lmap.fitBounds(gbBounds);
      placed.forEach((c) => {
        const m = L.circleMarker([c.lat, c.lon], {
          radius: 4 + Math.sqrt((c.customers || 0) / maxC) * 11, weight: 1.5, color: "#ffffff", fillOpacity: 0.9,
          fillColor: c.planned ? tok("--cut-planned", "#c47f00") : tok("--cut", "#c0392b"),
        }).addTo(lmap);
        m._cut = c;
        m.bindTooltip((c.planned ? "Planned work" : "Power cut") + (c.place ? ", " + c.place : "") + (c.customers != null ? ", " + whole(c.customers) + " customers" : ""));
        m.on("click", (ev) => { L.DomEvent.stopPropagation(ev); select(c, false); });
        markers.push(m);
      });
      lmap.on("moveend", listInView);
      box.querySelector('[data-z="reset"]').addEventListener("click", () => { lmap.fitBounds(gbBounds); box.querySelector("[data-pc-jump]").value = ""; });
    }
    box.querySelector("[data-pc-jump]").addEventListener("change", (ev) => { if (ev.target.value) zoomToRegion(ev.target.value); });
    inview.addEventListener("click", (ev) => {
      const b = ev.target.closest("button[data-i]");
      if (b) select(placed[+b.getAttribute("data-i")], true);
    });
    listInView();
  }

  /** Total customers across covered operators, every 30 minutes, from each operator's latest count in that half hour. */
  function historySeries(history) {
    const buckets = {};
    Object.keys(history).forEach((dno) => {
      history[dno].forEach(([ts, , cust]) => {
        if (cust == null) return;
        const b = Math.floor(new Date(ts).getTime() / 1800000) * 1800000;
        buckets[b] = buckets[b] || {};
        buckets[b][dno] = cust;
      });
    });
    const keys = Object.keys(buckets).map(Number).sort((a, b) => a - b);
    const last = {};
    const times = [], values = [];
    keys.forEach((k) => {
      Object.assign(last, buckets[k]);
      times.push(new Date(k).toISOString());
      values.push(Object.values(last).reduce((s, v) => s + v, 0));
    });
    return { times, values };
  }

  async function initPowerCuts(host) {
    const [d, map] = await Promise.all([getJSON("power_cuts.php"), getMap()]);
    if (!d) {
      host.innerHTML = '<p class="area-empty">Power cut data is not available right now.</p>';
      return;
    }
    const ops = d.operators;
    const live = ops.filter((o) => o.covered && o.fetched_at);
    const missing = ops.filter((o) => !(o.covered && o.fetched_at));
    const cuts = d.cuts;
    const unplanned = cuts.filter((c) => !c.planned);
    const custOff = unplanned.reduce((s, c) => s + (c.customers || 0), 0);
    const newest = live.map((o) => o.fetched_at).sort().pop();

    let html = '<section class="home-hero area-now pc-now" aria-label="Power cuts right now"><div class="home-hero__main">' +
      '<p class="home-hero__pill"><span class="home-hero__dot" aria-hidden="true"></span><span class="home-hero__updated">Live, updated at:</span> <span>' + esc(newest ? clock(newest) : "-") + "</span></p>" +
      '<p class="area-now__big"><span class="home-hero__share">' + unplanned.length + '</span> <span class="home-hero__lead">power cuts right now, affecting about ' + whole(custOff) + " customers</span></p></div>" +
      '<div class="home-hero__card"><p>Plus ' + (cuts.length - unplanned.length) + " sites with planned work under way. Covers " + live.length + " of the 6 network operators" +
      (missing.length ? ": " + esc(missing.map((o) => o.name).join(" and ")) + " not included yet." : ".") + "</p></div></section>";

    html += '<div class="gp-cols pc-cols">';
    const mapHtml = '<section class="panel pc-mappanel" aria-labelledby="pc-map-h" data-pc-mapbox><h2 id="pc-map-h">Where they are</h2>' + (map ? cutsMapHtml(map) : '<p class="area-empty">The map could not be loaded.</p>') +
      '<p class="gpmap-key"><span><i class="pc-key-cut"></i>Power cut</span><span><i class="pc-key-planned"></i>Planned work</span><span>Bigger = more customers</span><span><i class="pc-key-none"></i>Dashed area: no live data</span></p>' +
      '<p class="area-note">Point at an area to highlight it and select it to zoom in. Use + and − to zoom, and drag to move around. Each dot is placed where the operator says the cut is.</p></section>';
    html = html.replace('<div class="gp-cols pc-cols">', mapHtml + '<div class="gp-cols pc-cols">');

    const opRows = ops.map((o) => {
      const ok = o.covered && o.fetched_at;
      return "<tr><th scope=\"row\"><a href=\"" + LINKS[o.code] + "\">" + esc(o.name) + "</a></th>" +
        (ok ? '<td class="num">' + (o.cuts - o.planned) + '</td><td class="num">' + o.planned + '</td><td class="num">' + (o.customers == null ? "Not given" : whole(o.customers)) + "</td><td>" + esc(ago(o.fetched_at)) + "</td>"
            : '<td colspan="4">' + (o.covered ? "Waiting for the first update" : "No live data here yet") + "</td>") + "</tr>";
    }).join("");
    html += '<section class="panel" aria-labelledby="pc-ops-h"><h2 id="pc-ops-h">By network operator</h2>' +
      '<div class="area-table-scroll" tabindex="0" role="region" aria-labelledby="pc-ops-h"><table class="area-daylist"><thead><tr><th scope="col">Operator (links to its power cut map)</th><th scope="col" class="num">Power cuts</th><th scope="col" class="num">Planned</th><th scope="col" class="num">Customers</th><th scope="col">Updated</th></tr></thead><tbody>' + opRows + "</tbody></table></div>" +
      '<p class="area-note">Customers are counted as each operator reports them, for cuts and planned work together. Some operators give 0 for planned work.</p>';
    const top = unplanned.filter((c) => c.customers != null).sort((a, b) => b.customers - a.customers).slice(0, 8);
    html += top.length ? '<h3 class="area-sub">Biggest power cuts right now</h3><ul class="gp-list">' + top.map((c) =>
      "<li><span>" + esc(c.place || c.ref) + " <small>(" + esc(c.dno) + ")</small></span><strong>" + whole(c.customers) + " <small>customers" + (c.etr ? ", back by " + esc(when(c.etr)) : "") + "</small></strong></li>").join("") + "</ul>" : "";
    html += "</section></div>";

    html += '<section class="panel" aria-labelledby="pc-24-h"><h2 id="pc-24-h">The last 24 hours</h2><p class="chart-caption">Customers affected across the operators covered, every half hour.</p>' +
      '<div class="chart-wrap" style="height:220px;"><canvas id="pc-history" role="img" aria-label="Line chart of customers affected by power cuts over the last 24 hours"></canvas></div></section>';

    host.innerHTML = html;
    const mapBox = host.querySelector("[data-pc-mapbox]");
    if (map && mapBox) initCutsMap(mapBox, map, ops, cuts);
    const hs = historySeries(d.history || {});
    if (hs.values.length > 1 && GP.renderLineChart) {
      GP.renderLineChart(document.getElementById("pc-history"), { labels: hs.times.map(clock), times: hs.times, values: hs.values, unit: "" },
        { label: "Customers affected", color: "#c0392b", exportTitle: "Customers affected by power cuts, last 24 hours - Grid Pulse" });
    } else {
      document.getElementById("pc-history").closest(".chart-wrap").outerHTML = '<p class="area-empty">Not enough readings yet. This fills in over the first few hours.</p>';
    }
    const credit = document.querySelector("[data-pc-credits]");
    if (credit) credit.textContent = live.map((o) => CREDIT[o.code]).join(". ") + (live.length ? ". " : "") + (map ? "Area boundaries: Supported by National Energy SO Open Data." : "");
  }

  function init() {
    const gp = document.querySelector("[data-gas-page]");
    if (gp) initGasPage(gp);
    const gc = document.querySelector("[data-gas-card]");
    if (gc) initGasCard(gc);
    const pc = document.querySelector("[data-powercuts-page]");
    if (pc) initPowerCuts(pc);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
