/* ==========================================================================
   Grid Pulse - shared country features (every country page and GB).

   Reads the area APIs (api/day.php, api/records.php, api/flows.php,
   api/annual.php, api/archive.php) and fills the blocks that
   tools/apply_areas.py writes into each page:

     [data-area-now]      "X% of [area]'s power is renewable right now"
     [data-area-figures]  Copy as text / Download CSV / Download snapshot
     [data-area-replay]   replay today: donut and bars at any half hour
     [data-area-today]    "Today so far" card
     [data-area-recsum]   records card (the newest records)
     [data-area-flows]    cross-border flow cards
     [data-area-annual]   long-term history (yearly mix)
     [data-area-records]  records board, best of each year, recent breaks
     [data-area-archive]  pages/archive.html
     [data-area-day]      pages/day.html

   Never shows a made-up figure: anything missing says so instead.
   Fuel groups match includes/areas.php.
   ========================================================================== */
(function () {
  "use strict";
  const GP = window.GridPreview || {};
  const API = (function () {
    const s = document.currentScript ? document.currentScript.src : "";
    const base = s.replace(/assets\/areas\.js(?:\?.*)?$/, "api/");
    return base && base !== s ? base : "api/";
  })();
  const ROOT = API.replace(/api\/$/, "");

  const GROUPS = {
    wind: ["Wind", "#2fa876"], solar: ["Solar", "#e0a800"], hydro: ["Hydro", "#2a8fbd"],
    nuclear: ["Nuclear", "#6a5acd"], gas: ["Gas", "#8a5a3c"], coal: ["Coal", "#4a4a4a"],
    oil: ["Oil", "#5c4b3c"], biomass: ["Biomass", "#7a8f3f"], other_renewable: ["Other renewables", "#3f8fa0"],
    storage: ["Storage", "#0e8f7a"], other: ["Other", "#7a7a7a"],
  };
  const ORDER = ["wind", "solar", "hydro", "other_renewable", "biomass", "nuclear", "gas", "coal", "oil", "storage", "other"];
  const CUR = { GBP: "£", EUR: "€", USD: "$", AUD: "A$", CAD: "C$" };

  const esc = (s) => String(s == null ? "" : s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const groupLabel = (g, area) => (g === "other" && area && area.other_label) ? area.other_label : (GROUPS[g] ? GROUPS[g][0] : g);
  const groupColor = (g) => (GROUPS[g] ? GROUPS[g][1] : "#7a7a7a");
  const sortGroups = (keys) => keys.slice().sort((a, b) => ORDER.indexOf(a) - ORDER.indexOf(b));
  const gw = (mw, d) => (mw == null ? "-" : (mw / 1000).toFixed(d == null ? 1 : d));
  const pct = (v, d) => (v == null ? "-" : Number(v).toFixed(d == null ? 0 : d) + "%");
  function energy(mwh) {
    if (mwh == null) return "-";
    const a = Math.abs(mwh);
    if (a >= 1e6) return (mwh / 1e6).toFixed(2) + " TWh";
    if (a >= 1e3) return (mwh / 1e3).toFixed(1) + " GWh";
    return Math.round(mwh) + " MWh";
  }
  const money = (v, area) => (v == null ? "-" : (CUR[area.currency] || "") + Number(v).toFixed(2) + "/MWh");

  /** "1.30pm" in the area's own time zone. */
  function clock(iso, tz) {
    if (!iso) return "-";
    const d = new Date(iso);
    const parts = new Intl.DateTimeFormat("en-GB", { timeZone: tz, hour: "numeric", minute: "2-digit", hour12: true }).formatToParts(d);
    const h = parts.find((p) => p.type === "hour").value;
    const m = parts.find((p) => p.type === "minute").value;
    const ap = (parts.find((p) => p.type === "dayPeriod") || { value: "" }).value.toLowerCase().replace(/\./g, "").replace(/\s/g, "");
    return h + (m === "00" ? "" : "." + m) + ap;
  }
  function longDate(day) {
    if (!day) return "-";
    const d = new Date(day.slice(0, 10) + "T12:00:00Z");
    return d.toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric", timeZone: "UTC" });
  }
  function shortDate(day) {
    const d = new Date(day.slice(0, 10) + "T12:00:00Z");
    return d.toLocaleDateString("en-GB", { day: "numeric", month: "short", timeZone: "UTC" });
  }
  function areaPossessive(area) {
    const n = area.name.replace(/\s*\(.*\)$/, "");
    return n === "USA" ? "the USA's" : (n.endsWith("s") ? n + "'" : n + "'s");
  }
  async function getJSON(path) {
    try {
      const r = await fetch(API + path, { headers: { Accept: "application/json" } });
      if (!r.ok) return null;
      const j = await r.json();
      return j && j.ok ? j : null;
    } catch (e) { return null; }
  }
  function dayLink(code, day) { return ROOT + "pages/day.html?area=" + encodeURIComponent(code) + "&date=" + encodeURIComponent(day); }
  function archiveLink(code) { return ROOT + "pages/archive.html?area=" + encodeURIComponent(code); }

  /** Shares for one bucket's groups, matching ukgrid_area_shares(). */
  function shares(area, g) {
    let total = 0;
    Object.keys(g).forEach((k) => { if (g[k] > 0) total += g[k]; });
    const sum = (keys) => keys.reduce((s, k) => s + (g[k] > 0 ? g[k] : 0), 0);
    const ren = ["wind", "solar", "hydro", "other_renewable"].concat(area.renewable_biomass ? ["biomass"] : []);
    return { total, renewable: sum(ren), zero: sum(["wind", "solar", "hydro", "other_renewable", "nuclear"]), fossil: sum(["gas", "coal", "oil"]) };
  }
  function bucketAt(day, i) {
    const s = day.series;
    const g = {};
    Object.keys(s.groups || {}).forEach((k) => { const v = s.groups[k][i]; if (v != null && v > 0) g[k] = v; });
    return {
      ts: s.times[i], g,
      demand: s.demand ? s.demand[i] : null, price: s.price ? s.price[i] : null,
      carbon: s.carbon ? s.carbon[i] : null, imports: s.imports ? s.imports[i] : null,
    };
  }

  /* ---------- Simple canvas charts (stacked bars, in/out bars) ---------- */
  function drawStackedBars(canvas, labels, series, opts) {
    opts = opts || {};
    const draw = () => {
      const { ctx, w, h } = GP.prepareCanvas(canvas);
      const colors = GP.themeAwareColors();
      const pad = { l: 46, r: 8, t: 10, b: 24 };
      const n = labels.length;
      const totals = labels.map((_, i) => series.reduce((s, se) => s + (se.values[i] || 0), 0));
      const max = opts.max || Math.max(1, ...totals) * 1.05;
      const yAt = (v) => h - pad.b - (v / max) * (h - pad.t - pad.b);
      ctx.strokeStyle = colors.grid; ctx.fillStyle = colors.text; ctx.lineWidth = 1;
      ctx.textAlign = "right"; ctx.textBaseline = "middle";
      for (let k = 0; k <= 4; k++) {
        const v = (max * k) / 4, y = Math.round(yAt(v)) + 0.5;
        ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(w - pad.r, y); ctx.stroke();
        ctx.fillText(opts.unit === "%" ? Math.round(v) + "%" : (v >= 1000 ? (v / 1000).toFixed(v >= 10000 ? 0 : 1) + "k" : Math.round(v)), pad.l - 6, y);
      }
      const slot = (w - pad.l - pad.r) / Math.max(1, n);
      const bw = Math.max(2, Math.min(48, slot * 0.7));
      ctx.textAlign = "center"; ctx.textBaseline = "top";
      const every = Math.ceil(n / Math.max(1, Math.floor((w - pad.l) / 44)));
      for (let i = 0; i < n; i++) {
        const x = pad.l + slot * i + (slot - bw) / 2;
        let base = 0;
        series.forEach((se) => {
          const v = se.values[i] || 0;
          if (v <= 0) return;
          ctx.fillStyle = se.color;
          ctx.fillRect(x, yAt(base + v), bw, yAt(base) - yAt(base + v));
          base += v;
        });
        if (opts.hatch && opts.hatch[i]) {
          ctx.strokeStyle = colors.text; ctx.setLineDash([3, 3]);
          ctx.strokeRect(x + 0.5, yAt(totals[i]) + 0.5, bw - 1, yAt(0) - yAt(totals[i]) - 1);
          ctx.setLineDash([]);
        }
        if (i % every === 0) { ctx.fillStyle = colors.text; ctx.fillText(labels[i], x + bw / 2, h - pad.b + 6); }
      }
    };
    GP.registerChart(canvas, draw);
  }

  function drawInOut(canvas, values, max) {
    const draw = () => {
      const { ctx, w, h } = GP.prepareCanvas(canvas);
      const colors = GP.themeAwareColors();
      const mid = h / 2;
      ctx.strokeStyle = colors.guide; ctx.lineWidth = 1;
      ctx.beginPath(); ctx.moveTo(0, Math.round(mid) + 0.5); ctx.lineTo(w, Math.round(mid) + 0.5); ctx.stroke();
      const n = values.length || 1;
      const slot = w / n, bw = Math.max(1, slot * 0.8);
      values.forEach((v, i) => {
        if (v == null || !max) return;
        const hh = (Math.abs(v) / max) * (mid - 2);
        ctx.fillStyle = v >= 0 ? "#1d6fe0" : "#d98e04";
        ctx.fillRect(i * slot + (slot - bw) / 2, v >= 0 ? mid - hh : mid, bw, hh);
      });
    };
    GP.registerChart(canvas, draw);
  }

  /* ---------- Figures: copy, CSV and snapshot under the status grid ---------- */
  function figureRows() {
    const dl = document.querySelector(".status-grid");
    if (!dl) return [];
    return Array.from(dl.querySelectorAll(".stat")).map((s) => {
      const dt = s.querySelector("dt");
      const dd = s.querySelector("dd");
      return [dt ? dt.textContent.trim() : "", dd ? dd.textContent.replace(/\s+/g, " ").trim() : ""];
    }).filter((r) => r[0]);
  }
  function initFigures(host, area, getDay) {
    host.innerHTML =
      '<button type="button" class="copy-btn" data-act="copy"><span data-copy-label aria-live="polite">Copy as text</span></button>' +
      '<button type="button" class="copy-btn" data-act="csv"><span data-copy-label aria-live="polite">Download CSV</span></button>' +
      '<button type="button" class="copy-btn" data-act="png"><span data-copy-label aria-live="polite">Download snapshot (PNG)</span></button>';
    const title = area.name + " right now - Grid Pulse";
    host.querySelector('[data-act="copy"]').addEventListener("click", (e) => {
      const rows = figureRows();
      const btn = e.currentTarget;
      if (!rows.length || rows.every((r) => r[1] === "-" || r[1] === "–")) { GP.announceCopyResult(btn, false); return; }
      GP.copyText(title + "\n" + rows.map((r) => r[0] + ": " + r[1]).join("\n") + "\nSource: " + area.source)
        .then(() => GP.announceCopyResult(btn, true)).catch(() => GP.announceCopyResult(btn, false));
    });
    host.querySelector('[data-act="csv"]').addEventListener("click", (e) => {
      const rows = figureRows();
      const csv = ["figure,value"].concat(rows.map((r) => r.map((c) => /[",\n]/.test(c) ? '"' + c.replace(/"/g, '""') + '"' : c).join(","))).join("\r\n");
      GP.downloadTextFile("gridpulse-" + area.code.toLowerCase() + "-figures-" + new Date().toISOString().slice(0, 10) + ".csv", csv, "text/csv");
      GP.announceCopyResult(e.currentTarget, true);
    });
    host.querySelector('[data-act="png"]').addEventListener("click", (e) => {
      const day = getDay();
      const btn = e.currentTarget;
      if (!day || !day.series.times.length || !GP.downloadSnapshotCard) { GP.announceCopyResult(btn, false); return; }
      const b = bucketAt(day, day.series.times.length - 1);
      const keys = sortGroups(Object.keys(b.g));
      const s = shares(area, b.g);
      const stats = [];
      if (b.price != null) stats.push({ label: "Price", value: Number(b.price).toFixed(2), unit: (CUR[area.currency] || "") + "/MWh" });
      if (b.carbon != null) stats.push({ label: "Carbon", value: String(Math.round(b.carbon)), unit: "g/kWh" });
      if (b.demand != null) stats.push({ label: "Demand", value: gw(b.demand), unit: "GW" });
      stats.push({ label: "Generation", value: gw(s.total), unit: "GW" });
      if (b.imports != null) stats.push({ label: "Net imports", value: gw(b.imports), unit: "GW" });
      GP.downloadSnapshotCard({
        title: area.name + " - Grid Pulse",
        asOf: "as of " + clock(b.ts, area.tz) + " " + area.name.replace(/\s*\(.*\)$/, "") + " time",
        sourceLabels: keys.map((k) => groupLabel(k, area)), sourceValues: keys.map((k) => b.g[k] / 1000),
        sourceColors: keys.map(groupColor), renewablePct: s.total ? (s.renewable / s.total) * 100 : 0,
        stats, filename: "gridpulse-" + area.code.toLowerCase() + "-snapshot-" + new Date().toISOString().slice(0, 10) + ".png",
      });
      GP.announceCopyResult(btn, true);
    });
  }

  /* ---------- "X% renewable right now" ---------- */
  function renderNow(host, area, day) {
    const shareEl = host.querySelector("[data-area-now-share]");
    const sumEl = host.querySelector("[data-area-now-summary]");
    const timeEl = host.querySelector("[data-area-now-time]");
    const n = day ? day.series.times.length : 0;
    if (!n) {
      if (shareEl) shareEl.textContent = "-";
      if (sumEl) sumEl.textContent = "No readings for today yet. Figures appear here when the next reading arrives.";
      if (timeEl) timeEl.textContent = "-";
      return;
    }
    const b = bucketAt(day, n - 1);
    const s = shares(area, b.g);
    const top = sortGroups(Object.keys(b.g)).sort((x, y) => b.g[y] - b.g[x])[0];
    if (shareEl) shareEl.textContent = s.total ? Math.round((s.renewable / s.total) * 100) : "-";
    if (timeEl) timeEl.textContent = clock(b.ts, area.tz) + " " + area.name.replace(/\s*\(.*\)$/, "") + " time";
    const bits = [groupLabel(top, area) + " is the largest source (" + gw(b.g[top]) + " GW of " + gw(s.total) + " GW)"];
    if (b.demand != null) bits.push("demand is " + gw(b.demand) + " GW");
    if (b.price != null) bits.push("the price is " + money(b.price, area));
    if (b.imports != null) bits.push(b.imports >= 0 ? "net imports are " + gw(b.imports) + " GW" : "net exports are " + gw(-b.imports) + " GW");
    if (sumEl) sumEl.textContent = bits.join(", ").replace(/^./, (c) => c.toUpperCase()) + ".";
  }

  /* ---------- Today so far ---------- */
  function renderToday(host, area, day) {
    const body = host.querySelector("[data-area-today-body]") || host;
    const s = day && day.summary;
    if (!s) {
      body.innerHTML = '<p class="area-empty">No readings for today yet. This card fills in as the day goes on.</p>';
      return;
    }
    const ex = s.extremes || {};
    const rows = [];
    if (ex.demand_high_mw) rows.push(["Peak demand", gw(ex.demand_high_mw.value) + " GW at " + clock(ex.demand_high_mw.ts, area.tz)]);
    if (ex.demand_low_mw) rows.push(["Lowest demand", gw(ex.demand_low_mw.value) + " GW at " + clock(ex.demand_low_mw.ts, area.tz)]);
    if (s.demand_avg_mw != null) rows.push(["Average demand", gw(s.demand_avg_mw) + " GW"]);
    rows.push(["Renewable share", pct(s.renewable_share, 1)]);
    rows.push(["Zero-carbon share", pct(s.zero_carbon_share, 1)]);
    if (s.imports_net_mwh != null) rows.push([s.imports_net_mwh >= 0 ? "Net imports" : "Net exports", energy(Math.abs(s.imports_net_mwh))]);
    if (s.home_share != null) rows.push(["Made at home", pct(s.home_share, 1)]);
    if (s.price_avg != null) rows.push(["Average price", money(s.price_avg, area)]);
    if (s.largest) rows.push(["Largest source", groupLabel(s.largest, area) + " (" + energy(s.energy_mwh[s.largest]) + ")"]);
    body.innerHTML = demandCurve(area, day) + '<dl class="area-facts">' + rows.map((r) => "<div><dt>" + esc(r[0]) + "</dt><dd>" + esc(r[1]) + "</dd></div>").join("") + "</dl>" +
      '<p class="area-note">' + (s.coverage < 0.98 ? "So far today (" + Math.round(s.coverage * 100) + "% of the day). " : "") +
      'Times are ' + esc(area.name.replace(/\s*\(.*\)$/, "")) + ' time. <a href="' + dayLink(area.code, s.day) + '">See the whole day</a> · <a href="' + archiveLink(area.code) + '">Day archive</a></p>';
  }

  /* ---------- Replay the day ---------- */
  function renderReplay(host, area, day) {
    const n = day ? day.series.times.length : 0;
    if (!n) {
      host.innerHTML = '<p class="area-empty">No readings for today yet, so there is nothing to replay.</p>';
      return;
    }
    if (!host.dataset.built) {
      host.dataset.built = "1";
      host.innerHTML =
        '<label class="area-replay__label" for="area-replay-' + area.code + '">Time: <strong data-replay-time></strong></label>' +
        '<input type="range" class="area-replay__slider" id="area-replay-' + area.code + '" min="0" step="1">' +
        '<p class="area-replay__share" data-replay-share aria-live="polite"></p>' +
        '<div class="panel-grid panel-grid--2">' +
        '<div><div class="chart-wrap" style="height:240px;"><canvas data-replay-donut role="img" aria-label="Donut chart of generation by source at the chosen time"></canvas></div><ul class="chart-legend" data-replay-legend></ul></div>' +
        '<div><div class="chart-wrap" style="height:240px;"><canvas data-replay-bars role="img" aria-label="Bar chart of generation by source at the chosen time"></canvas></div></div>' +
        "</div>";
      host.querySelector("input").addEventListener("input", (e) => drawAt(Number(e.target.value)));
    }
    const input = host.querySelector("input");
    const atEnd = input.value === "" || Number(input.value) >= Number(input.max || 0);
    input.max = String(n - 1);
    if (atEnd) input.value = String(n - 1);
    function drawAt(i) {
      const b = bucketAt(day, i);
      const keys = sortGroups(Object.keys(b.g)).sort((x, y) => b.g[y] - b.g[x]);
      const s = shares(area, b.g);
      host.querySelector("[data-replay-time]").textContent = clock(b.ts, area.tz) + (i === n - 1 ? " (latest)" : "");
      input.setAttribute("aria-valuetext", clock(b.ts, area.tz));
      host.querySelector("[data-replay-share]").textContent = "Renewable " + pct(s.total ? (s.renewable / s.total) * 100 : null, 0) + ", total generation " + gw(s.total) + " GW.";
      const labels = keys.map((k) => groupLabel(k, area));
      const vals = keys.map((k) => +(b.g[k] / 1000).toFixed(2));
      const cols = keys.map(groupColor);
      GP.renderDonutChart(host.querySelector("[data-replay-donut]"), labels, vals, cols, { unit: "GW", decimals: 1, exportTitle: area.name + " generation at " + clock(b.ts, area.tz) + " - Grid Pulse" });
      GP.renderBarChart(host.querySelector("[data-replay-bars]"), labels, vals, cols, { unit: "GW", exportTitle: area.name + " generation at " + clock(b.ts, area.tz) + " - Grid Pulse" });
      const leg = host.querySelector("[data-replay-legend]");
      leg.innerHTML = keys.map((k, j) => '<li><span class="swatch" style="background:' + cols[j] + '"></span>' + esc(labels[j]) + " " + gw(b.g[k]) + " GW</li>").join("");
    }
    drawAt(Number(input.value));
  }

  /* ---------- Records ---------- */
  function fmtRecord(r, area) {
    if (r.unit === "MW") return gw(r.value, 2) + " GW";
    if (r.unit === "MWh") return energy(r.value);
    if (r.unit === "%") return Number(r.value).toFixed(1) + "%";
    if (r.unit === "g/kWh") return Math.round(r.value) + " g/kWh";
    return (r.unit.indexOf("/MWh") > 0 ? r.unit.replace("/MWh", "") : "") + Number(r.value).toFixed(2) + "/MWh";
  }
  const fmtPrev = (r, v) => fmtRecord(Object.assign({}, r, { value: v }));
  function when(ts, area) {
    if (!ts) return "-";
    if (ts.length === 10) return longDate(ts);
    const local = new Intl.DateTimeFormat("en-CA", { timeZone: area.tz, year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date(ts));
    return longDate(local) + ", " + clock(ts, area.tz);
  }
  function sinceLine(rec, area) {
    return rec && rec.since ? "Since this site started recording " + esc(area.name) + " on " + esc(longDate(rec.since)) + "." : "";
  }
  function renderRecSummary(host, area, rec) {
    const body = host.querySelector("[data-area-recsum-body]") || host;
    if (!rec || !rec.board.length) {
      body.innerHTML = '<p class="area-empty">No records yet. They appear once a few days have been recorded.</p>';
      return;
    }
    const pick = rec.board.filter((r) => ["wind_peak_mw", "solar_peak_mw", "renewable_share_high", "demand_high_mw"].indexOf(r.key) !== -1);
    body.innerHTML = '<ul class="area-reclist">' + pick.map((r) => "<li><span>" + esc(r.label) + "</span><strong>" + esc(fmtRecord(r, area)) + "</strong><small>" + esc(when(r.ts, area)) + "</small></li>").join("") + "</ul>" +
      '<p class="area-note">' + sinceLine(rec, area) + ' <a href="#area-records-heading">All records</a></p>';
  }
  function renderRecords(host, area, rec) {
    const body = host.querySelector("[data-area-records-body]") || host;
    const since = host.querySelector("[data-area-records-since]");
    if (since) since.innerHTML = rec && rec.since ? sinceLine(rec, area) + " These are worked out from this site's own readings, so they only go back that far." : "";
    if (!rec || !rec.board.length) {
      body.innerHTML = '<p class="area-empty">No records yet. They appear once a few days have been recorded.</p>';
      return;
    }
    const groups = {};
    rec.board.forEach((r) => { (groups[r.group] = groups[r.group] || []).push(r); });
    let html = '<div class="area-board">';
    Object.keys(groups).forEach((g) => {
      html += '<div class="area-board__group"><h3>' + esc(g) + '</h3><ul class="area-reclist">';
      groups[g].forEach((r) => {
        html += "<li><span>" + esc(r.label) + "</span><strong>" + esc(fmtRecord(r, area)) + "</strong><small>" +
          '<a href="' + dayLink(area.code, r.day) + '">' + esc(when(r.ts, area)) + "</a>" +
          (r.previous != null ? ". Beat " + esc(fmtPrev(r, r.previous)) + " (" + esc(when(r.previous_ts, area)) + ")" : ". The first value recorded") + "</small></li>";
      });
      html += "</ul></div>";
    });
    html += "</div>";

    // Best of each year: shaded by how close each year came to the record.
    if (rec.years.length) {
      html += '<h3 class="area-sub">Best of each year</h3><div class="area-table-scroll"><table class="area-years"><caption class="visually-hidden">Best value of each record type, by year</caption><thead><tr><th scope="col">Record</th>' +
        rec.years.map((y) => '<th scope="col" class="num">' + esc(y) + "</th>").join("") + "</tr></thead><tbody>";
      rec.board.forEach((r) => {
        html += '<tr><th scope="row">' + esc(r.label) + "</th>";
        rec.years.forEach((y) => {
          const v = rec.grid[r.key] ? rec.grid[r.key][y] : undefined;
          if (v == null) { html += '<td class="num area-years__none">-</td>'; return; }
          const isRec = String(r.day).slice(0, 4) === String(y);
          let close = r.direction === "high" ? (r.value ? v / r.value : 0) : (v ? r.value / v : 0);
          if (r.direction === "low" && (v <= 0 || r.value <= 0)) close = v === r.value ? 1 : 0.5;
          close = Math.max(0, Math.min(1, close));
          html += '<td class="num' + (isRec ? " area-years__rec" : "") + '" style="--close:' + close.toFixed(2) + '">' + esc(fmtPrev(r, v)) + (isRec ? '<span class="visually-hidden"> (record)</span>' : "") + "</td>";
        });
        html += "</tr>";
      });
      html += '</tbody></table></div><p class="area-note">Darker cells came closer to the record. The ringed cell is the record. The current year is so far.</p>';
    }

    html += '<h3 class="area-sub">Broken most recently</h3>';
    if (rec.recent.length) {
      html += '<ul class="area-reclist">' + rec.recent.map((b) =>
        "<li><span>" + esc(b.label) + "</span><strong>" + esc(fmtRecord(b, area)) + "</strong><small>" +
        '<a href="' + dayLink(area.code, b.day) + '">' + esc(when(b.ts, area)) + "</a>. Beat " + esc(fmtPrev(b, b.previous)) + "</small></li>").join("") + "</ul>";
    } else {
      html += '<p class="area-empty">None yet. A record only counts as broken once at least 30 days have been recorded.</p>';
    }
    body.innerHTML = html;
  }

  /* ---------- Cross-border flows ---------- */
  function renderFlows(host, area, flows) {
    const body = host.querySelector("[data-area-flows-body]") || host;
    if (!flows || !flows.neighbours.length) {
      body.innerHTML = '<p class="area-empty">No cross-border flow readings for today yet.</p>';
      return;
    }
    let max = 0;
    flows.neighbours.forEach((n) => n.values.forEach((v) => { if (v != null && Math.abs(v) > max) max = Math.abs(v); }));
    const now = Date.now();
    body.innerHTML = '<div class="area-flows">' + flows.neighbours.map((n, i) => {
      let live = "No live reading";
      if (n.latest_mw != null) {
        live = Math.abs(n.latest_mw) < 1 ? "No flow" : (n.latest_mw > 0 ? "Importing " + gw(n.latest_mw, 2) + " GW from " + n.label : "Exporting " + gw(-n.latest_mw, 2) + " GW to " + n.label);
      }
      let upd = "";
      if (n.updated) {
        const mins = Math.round((now - new Date(n.updated).getTime()) / 60000);
        upd = "Border last updated " + (mins < 90 ? mins + " minutes ago" : Math.round(mins / 60) + " hours ago") + (n.status === "ERROR" ? " (the last try failed)" : "") + ".";
      } else if (flows.area && flows.area.code && n.status === null && area.code !== "GB" && area.code !== "IE") {
        upd = "Not fetched yet.";
      }
      const last = n.latest_ts ? "Latest reading " + clock(n.latest_ts, area.tz) + "." : "";
      return '<article class="area-flow"><h3>' + esc(n.label) + "</h3>" +
        (n.cables && n.cables.length ? '<p class="area-flow__cables">' + esc(n.cables.join(", ")) + "</p>" : "") +
        '<p class="area-flow__live">' + esc(live) + "</p>" +
        (n.values.length
          ? '<p class="area-flow__today">Today: in ' + esc(energy(n.in_mwh)) + ", out " + esc(energy(n.out_mwh)) + "</p>" +
            '<div class="chart-wrap area-flow__chart"><canvas data-flow="' + i + '" role="img" aria-label="' + esc("Net flow with " + n.label + " today. Bars above the line are imports, below are exports.") + '"></canvas></div>'
          : '<p class="area-empty">No readings for today yet.</p>') +
        '<p class="area-note">' + esc(last + " " + upd) + "</p></article>";
    }).join("") + "</div>" +
      '<p class="area-note"><span class="swatch" style="background:#1d6fe0"></span> Into ' + esc(area.name) + ' <span class="swatch" style="background:#d98e04"></span> Out of ' + esc(area.name) +
      ". " + (max > 0 ? "All cards share one scale (up to " + gw(max, 1) + " GW). " : "") + "In and out are worked out from the net flow in each " + (flows.bucket_minutes === 30 ? "half hour" : "hour") + ". Source: " + esc(flows.source) + ".</p>";
    flows.neighbours.forEach((n, i) => { const c = body.querySelector('[data-flow="' + i + '"]'); if (c) drawInOut(c, n.values, max); });
  }

  /* ---------- Long-term history ---------- */
  function renderAnnual(host, area, ann) {
    const body = host.querySelector("[data-area-annual-body]") || host;
    const src = ann && ann.source;
    const credit = src ? 'Source: <a href="' + esc(src.url) + '">' + esc(src.credit) + "</a>. Covers " + esc(src.scope) + "." : "";
    if (!ann || !ann.years.length) {
      body.innerHTML = '<p class="area-empty">Yearly figures have not been fetched yet. They are added a year at a time by the site\'s scheduler.</p><p class="area-note">' + credit + "</p>";
      return;
    }
    if (src && !src.checked) {
      body.innerHTML = '<p class="area-empty">Yearly figures from this source are stored but not shown yet, because they still need checking against the source\'s own published totals.</p><p class="area-note">' + credit + "</p>";
      return;
    }
    const keys = sortGroups(Object.keys(ann.groups));
    const labels = ann.years.map((y) => (y === ann.current_year ? y + " so far" : String(y)));
    const totals = ann.years.map((_, i) => keys.reduce((s, k) => s + (ann.groups[k][i] || 0), 0));
    if (!body.dataset.built) {
      body.dataset.built = "1";
      body.innerHTML = '<div class="mixchart__switch" role="group" aria-label="Show as"><button type="button" aria-pressed="true" data-mode="share">Share (%)</button><button type="button" aria-pressed="false" data-mode="gwh">Energy (GWh)</button></div>' +
        '<div class="chart-wrap" style="height:320px;"><canvas data-annual role="img" aria-label="' + esc("Stacked bar chart of " + area.name + "'s yearly electricity generation by source") + '"></canvas></div>' +
        '<ul class="chart-legend" style="justify-content:center;">' + keys.map((k) => '<li><span class="swatch" style="background:' + groupColor(k) + '"></span>' + esc(groupLabel(k, area)) + "</li>").join("") + "</ul>" +
        '<p class="area-note">' + credit + (src && src.checked ? " Checked against the source on " + esc(src.checked) + "." : "") + (ann.years.indexOf(ann.current_year) !== -1 ? " The current year is so far, so it is not a full year." : "") + "</p>";
      body.querySelectorAll("[data-mode]").forEach((b) => b.addEventListener("click", () => {
        body.querySelectorAll("[data-mode]").forEach((x) => x.setAttribute("aria-pressed", String(x === b)));
        draw(b.dataset.mode);
      }));
    }
    const canvas = body.querySelector("[data-annual]");
    function draw(mode) {
      const series = keys.map((k) => ({
        name: groupLabel(k, area), color: groupColor(k),
        values: ann.groups[k].map((v, i) => (mode === "share" ? (totals[i] ? (v / totals[i]) * 100 : 0) : v)),
      }));
      drawStackedBars(canvas, labels, series, { unit: mode === "share" ? "%" : "GWh", max: mode === "share" ? 100 : null, hatch: ann.years.map((y) => y === ann.current_year) });
      GP.attachChartExport(canvas, {
        kind: "series", title: area.name + " yearly generation by source (" + (mode === "share" ? "%" : "GWh") + ") - Grid Pulse",
        data: { labels, columns: series.map((s) => ({ name: s.name, unit: mode === "share" ? "%" : "GWh", values: s.values.map((v) => +v.toFixed(mode === "share" ? 2 : 1)) })) },
      });
    }
    draw((body.querySelector('[data-mode][aria-pressed="true"]') || {}).dataset ? body.querySelector('[data-mode][aria-pressed="true"]').dataset.mode : "share");
  }

  /* ---------- Day page ---------- */
  async function initDayPage(host) {
    const q = new URLSearchParams(window.location.search);
    const code = (q.get("area") || "GB").toUpperCase();
    const date = q.get("date") || "";
    const day = await getJSON("day.php?area=" + encodeURIComponent(code) + (date ? "&date=" + encodeURIComponent(date) : ""));
    const h1 = document.querySelector("h1");
    if (!day) {
      if (h1) h1.textContent = "Day not found";
      host.innerHTML = '<p class="area-empty">There is no stored day for that area and date. Check the address, or pick a day from the <a href="' + archiveLink(code) + '">day archive</a>.</p>';
      return;
    }
    const area = day.area;
    document.title = area.name + " on " + longDate(day.date) + " - Grid Pulse";
    if (h1) h1.textContent = area.name + " on " + longDate(day.date);
    const crumb = document.querySelector("[data-day-crumb]");
    if (crumb) crumb.innerHTML = '<a href="' + ROOT + area.page + '">' + esc(area.name) + '</a> › <a href="' + archiveLink(code) + '">Day archive</a> › ' + esc(longDate(day.date));
    const prev = new Date(day.date + "T12:00:00Z"); prev.setUTCDate(prev.getUTCDate() - 1);
    const next = new Date(day.date + "T12:00:00Z"); next.setUTCDate(next.getUTCDate() + 1);
    const p = prev.toISOString().slice(0, 10), nx = next.toISOString().slice(0, 10);
    const nav = '<nav class="area-daynav" aria-label="Other days"><a href="' + dayLink(code, p) + '">← ' + esc(longDate(p)) + "</a>" +
      (day.is_today ? "<span></span>" : '<a href="' + dayLink(code, nx) + '">' + esc(longDate(nx)) + " →</a>") + "</nav>";
    const s = day.summary;
    let html = nav;
    if (!s) {
      html += '<p class="area-empty">No readings were stored for ' + esc(area.name) + " on this day.</p>";
      host.innerHTML = html;
      return;
    }
    html += '<section class="panel" aria-labelledby="day-sum-h"><h2 id="day-sum-h">Summary</h2><div data-day-today></div></section>';
    html += '<section class="panel" aria-labelledby="day-mix-h"><h2 id="day-mix-h">Generation by source</h2><div class="chart-wrap" style="height:320px;"><canvas id="day-stack" role="img" aria-label="Stacked chart of generation by source through the day"></canvas></div><ul class="chart-legend" id="day-stack-legend" style="justify-content:center;"></ul></section>';
    const lines = [["demand", "Demand", "GW", "#1d6fe0"], ["price", "Price", (CUR[area.currency] || "") + "/MWh", "#d98e04"], ["carbon", "Carbon intensity", "g/kWh", "#8a5a3c"], ["imports", "Net imports", "GW", "#6a5acd"]]
      .filter((l) => day.series[l[0]]);
    if (lines.length) {
      html += '<section class="panel" aria-labelledby="day-lines-h"><h2 id="day-lines-h">Demand, price and more</h2><div class="chart-grid">' +
        lines.map((l) => '<div class="chart-card"><h3>' + esc(l[1]) + '</h3><div class="chart-wrap"><canvas data-line="' + l[0] + '"></canvas></div></div>').join("") + "</div></section>";
    }
    html += '<section class="panel" aria-labelledby="day-rec-h"><h2 id="day-rec-h">Records set this day</h2><div data-day-records></div></section>';
    html += '<p class="download-row"><button type="button" class="copy-btn" data-day-csv><span data-copy-label aria-live="polite">Download CSV of the day</span></button></p>';
    html += '<p class="area-note">Times are ' + esc(area.name.replace(/\s*\(.*\)$/, "")) + " time. Source: " + esc(area.source) + ".</p>" + nav;
    host.innerHTML = html;
    document.body.dataset.csvSource = area.source;

    const todayBox = host.querySelector("[data-day-today]");
    renderToday(todayBox, area, day);
    const note = todayBox.querySelector(".area-note");
    if (note) note.innerHTML = (s.coverage < 0.98 ? "Readings cover " + Math.round(s.coverage * 100) + "% of this day." : "");

    const labels = day.series.times.map((t) => clock(t, area.tz));
    const keys = sortGroups(Object.keys(day.series.groups));
    GP.renderStackedAreaChart(document.getElementById("day-stack"), day.series.times, labels,
      keys.map((k) => ({ name: groupLabel(k, area), color: groupColor(k), values: day.series.groups[k].map((v) => (v == null ? 0 : v / 1000)) })),
      { unit: "GW", legendTarget: "day-stack-legend", exportTitle: area.name + " generation by source, " + longDate(day.date) + " - Grid Pulse" });
    lines.forEach((l) => {
      const div = l[2] === "GW" ? 1000 : 1;
      GP.renderLineChart(host.querySelector('[data-line="' + l[0] + '"]'), { labels, times: day.series.times, values: day.series[l[0]].map((v) => (v == null ? null : v / div)), unit: l[2] },
        { label: l[1], color: l[3], unit: l[2], signed: l[0] === "imports" ? "flow" : (l[0] === "price" ? "price" : undefined), exportTitle: area.name + " " + l[1].toLowerCase() + ", " + longDate(day.date) + " - Grid Pulse" });
    });
    const recBox = host.querySelector("[data-day-records]");
    recBox.innerHTML = day.records_set && day.records_set.length
      ? '<ul class="area-reclist">' + day.records_set.map((r) => "<li><span>" + esc(r.label) + "</span><strong>" + esc(fmtRecord(r, area)) + "</strong><small>" + esc(when(r.ts, area)) + ". Beat " + esc(fmtPrev(r, r.previous)) + (r.still_stands ? ". Still the record." : ". Since beaten.") + "</small></li>").join("") + '</ul><p class="area-note">' + sinceLine({ since: day.records_since }, area) + "</p>"
      : '<p class="area-empty">No records were broken on this day.</p>';
    host.querySelector("[data-day-csv]").addEventListener("click", (e) => {
      const cols = keys.map((k) => groupLabel(k, area) + " (MW)").concat(lines.map((l) => l[1] + " (" + (l[2] === "GW" ? "MW" : l[2]) + ")"));
      const rows = ["time (UTC)," + cols.join(",")];
      day.series.times.forEach((t, i) => {
        rows.push([t].concat(keys.map((k) => day.series.groups[k][i] == null ? "" : day.series.groups[k][i])).concat(lines.map((l) => day.series[l[0]][i] == null ? "" : day.series[l[0]][i])).join(","));
      });
      GP.downloadTextFile("gridpulse-" + code.toLowerCase() + "-" + day.date + ".csv", rows.join("\r\n"), "text/csv");
      GP.announceCopyResult(e.currentTarget, true);
    });
  }

  /* ---------- Archive page ---------- */
  async function initArchivePage(host) {
    const q = new URLSearchParams(window.location.search);
    const code = (q.get("area") || "GB").toUpperCase();
    const year = q.get("year");
    const arc = await getJSON("archive.php?area=" + encodeURIComponent(code) + (year ? "&year=" + encodeURIComponent(year) : ""));
    const h1 = document.querySelector("h1");
    if (!arc) {
      if (h1) h1.textContent = "Day archive";
      host.innerHTML = '<p class="area-empty">That area is not covered. Pick a country from the menu.</p>';
      return;
    }
    const area = arc.area;
    document.title = area.name + " day archive - Grid Pulse";
    if (h1) h1.textContent = area.name + ": day archive";
    const crumb = document.querySelector("[data-day-crumb]");
    if (crumb) crumb.innerHTML = '<a href="' + ROOT + area.page + '">' + esc(area.name) + "</a> › Day archive";
    if (!arc.days.length) {
      host.innerHTML = '<p class="area-empty">No days have been summarised for ' + esc(area.name) + " yet. Days are added every half hour by the site's daily job.</p>";
      return;
    }
    const byDay = {};
    arc.days.forEach((d) => { byDay[d.day] = d; });
    let html = '<nav class="area-years-nav" aria-label="Years">' + arc.years.map((y) => y.year === arc.year
      ? '<span aria-current="page">' + y.year + "</span>"
      : '<a href="' + archiveLink(code) + "&year=" + y.year + '">' + y.year + "</a>").join("") + "</nav>";
    html += '<p class="area-note">Each day is shaded by its renewable share: the darker the green, the higher the share. Pale days had only part of the day recorded. ' +
      (area.renewable_biomass ? "Renewable includes biomass." : "Renewable does not include biomass, as on the rest of this site's Great Britain pages.") + "</p>";
    html += '<div class="area-cal">';
    for (let m = 0; m < 12; m++) {
      const first = new Date(Date.UTC(arc.year, m, 1));
      const days = new Date(Date.UTC(arc.year, m + 1, 0)).getUTCDate();
      const monthName = first.toLocaleDateString("en-GB", { month: "long", timeZone: "UTC" });
      const offset = (first.getUTCDay() + 6) % 7;
      let cells = "";
      for (let k = 0; k < offset; k++) cells += '<span class="area-cal__pad"></span>';
      let any = false;
      for (let d = 1; d <= days; d++) {
        const key = arc.year + "-" + String(m + 1).padStart(2, "0") + "-" + String(d).padStart(2, "0");
        const r = byDay[key];
        if (r) {
          any = true;
          const label = longDate(key) + ": " + r.renewable_share.toFixed(1) + "% renewable";
          cells += '<a class="area-cal__day' + (r.coverage < 0.9 ? " is-partial" : "") + '" style="--ren:' + (r.renewable_share / 100).toFixed(3) + '" href="' + dayLink(code, key) + '" title="' + esc(label) + '" aria-label="' + esc(label) + '">' + d + "</a>";
        } else {
          cells += '<span class="area-cal__day is-empty" aria-hidden="true">' + d + "</span>";
        }
      }
      if (any) html += '<div class="area-cal__month"><h3>' + esc(monthName) + '</h3><div class="area-cal__grid">' + cells + "</div></div>";
    }
    html += "</div>";

    // List by month
    html += '<h2 class="area-sub">Every day, by month</h2>';
    const months = {};
    arc.days.forEach((d) => { (months[d.day.slice(0, 7)] = months[d.day.slice(0, 7)] || []).push(d); });
    Object.keys(months).sort().reverse().forEach((mk) => {
      const mName = new Date(mk + "-15T12:00:00Z").toLocaleDateString("en-GB", { month: "long", year: "numeric", timeZone: "UTC" });
      html += '<details class="area-month"' + (mk === Object.keys(months).sort().pop() ? " open" : "") + "><summary>" + esc(mName) + " (" + months[mk].length + (months[mk].length === 1 ? " day" : " days") + ")</summary>" +
        '<div class="area-table-scroll"><table class="area-daylist"><thead><tr><th scope="col">Day</th><th scope="col">Largest source</th><th scope="col" class="num">Renewable</th>' +
        (area.has_demand ? '<th scope="col" class="num">Average demand</th>' : "") + (area.has_price ? '<th scope="col" class="num">Average price</th>' : "") + "</tr></thead><tbody>" +
        months[mk].slice().reverse().map((d) => '<tr><th scope="row"><a href="' + dayLink(code, d.day) + '">' + esc(shortDate(d.day)) + "</a>" + (d.coverage < 0.9 ? ' <small>(part day)</small>' : "") + "</th><td>" + esc(d.largest ? groupLabel(d.largest, area) : "-") + '</td><td class="num">' + pct(d.renewable_share, 1) + "</td>" +
          (area.has_demand ? '<td class="num">' + (d.demand_avg_mw != null ? gw(d.demand_avg_mw) + " GW" : "-") + "</td>" : "") +
          (area.has_price ? '<td class="num">' + money(d.price_avg, area) + "</td>" : "") + "</tr>").join("") +
        "</tbody></table></div></details>";
    });
    html += '<p class="area-note">Source: ' + esc(area.source) + ". Days are in " + esc(area.name.replace(/\s*\(.*\)$/, "")) + " time.</p>";
    host.innerHTML = html;
  }

  /* ==========================================================================
     Dashboard cards (Records, Borders, This year so far, neighbours) and the
     shared Records and Borders pages. Added for the dashboard layout,
     November 2026. Every card says plainly when a figure isn't available.
     ========================================================================== */
  const ICONS = {
    wind: "M3 8h11a3 3 0 1 0-3-3M3 12h15a3 3 0 1 1-3 3M3 16h8",
    solar: "M12 4v2M12 18v2M4 12h2M18 12h2M6.3 6.3l1.4 1.4M16.3 16.3l1.4 1.4M6.3 17.7l1.4-1.4M16.3 7.7l1.4-1.4M12 8a4 4 0 1 0 0 8a4 4 0 1 0 0-8",
    leaf: "M5 19c8 0 14-6 14-14C11 5 5 11 5 19ZM5 19l7-7",
    bolt: "M13 3 5 14h6l-1 7 8-11h-6l1-7Z",
    coin: "M12 4a8 8 0 1 0 0 16a8 8 0 1 0 0-16M9 10h5M9 14h5M11 8v8",
    atom: "M12 10a2 2 0 1 0 0 4a2 2 0 1 0 0-4M4 12c0-2 3.6-4 8-4s8 2 8 4-3.6 4-8 4-8-2-8-4Z",
  };
  const REC_STYLE = {
    wind_peak_mw: ["wind", "#2fa876"], wind_day_mwh: ["wind", "#2fa876"], solar_peak_mw: ["solar", "#c49000"],
    solar_day_mwh: ["solar", "#c49000"], renewable_share_high: ["leaf", "#1f9d5a"], renewable_day_share: ["leaf", "#1f9d5a"],
    zero_carbon_share_high: ["atom", "#6a5acd"], fossil_share_low: ["leaf", "#4f8a3a"], demand_high_mw: ["bolt", "#1d6fe0"],
    demand_low_mw: ["bolt", "#1d6fe0"], price_high: ["coin", "#6a5acd"], price_low: ["coin", "#6a5acd"],
    carbon_low: ["leaf", "#4f8a3a"], carbon_high: ["leaf", "#8a5a3c"],
  };
  const icon = (name) => '<span class="gp-ico gp-ico--' + name + '"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' + ICONS[name] + '"/></svg></span>';
  const recordsLink = (code) => ROOT + "pages/records.html?area=" + encodeURIComponent(code);
  const bordersLink = (code) => code === "GB" ? ROOT + "pages/interconnectors.html" : ROOT + "pages/borders.html?area=" + encodeURIComponent(code);
  const shortName = (area) => area.name.replace(/\s*\(.*\)$/, "");

  /** How close today's best came to the record, 0-1 (null when today has no such figure). */
  function closeness(r, todayVal) {
    if (todayVal == null || r.value == null) return null;
    if (r.direction === "high") return r.value > 0 ? Math.max(0, Math.min(1, todayVal / r.value)) : null;
    if (todayVal <= 0 || r.value <= 0) return todayVal === r.value ? 1 : null;
    return Math.max(0, Math.min(1, r.value / todayVal));
  }

  /* ---------- Demand curve for Today so far ---------- */
  function demandCurve(area, day) {
    const d = day && day.series && day.series.demand;
    if (!d) return "";
    const pts = d.map((v, i) => [i, v]).filter((p) => p[1] != null);
    if (pts.length < 3) return "";
    const W = 300, H = 90, n = day.series.times.length;
    const hours = (new Date(day.to) - new Date(day.from)) / 3600000;
    const slots = Math.round(hours * 60 / (day.area.bucket_minutes || 60));
    const vals = pts.map((p) => p[1]);
    const lo = Math.min(...vals), hi = Math.max(...vals);
    const x = (i) => 4 + (i / Math.max(1, slots - 1)) * (W - 8);
    const y = (v) => hi === lo ? H / 2 : 12 + (1 - (v - lo) / (hi - lo)) * (H - 30);
    const line = pts.map((p, k) => (k ? "L" : "M") + x(p[0]).toFixed(1) + "," + y(p[1]).toFixed(1)).join(" ");
    const iHi = pts[vals.indexOf(hi)], iLo = pts[vals.indexOf(lo)], last = pts[pts.length - 1];
    const dot = (p, cls, fill, label, dy) => '<circle cx="' + x(p[0]).toFixed(1) + '" cy="' + y(p[1]).toFixed(1) + '" r="4" fill="' + fill + '"' + (cls ? ' class="' + cls + '"' : "") + '/><text x="' + x(p[0]).toFixed(1) + '" y="' + (y(p[1]) + dy).toFixed(1) + '" font-size="11" text-anchor="middle" class="gp-curve__label">' + label + "</text>";
    return '<svg class="gp-curve" viewBox="0 0 ' + W + " " + H + '" role="img" aria-label="' + esc("Demand so far today, from " + gw(lo) + " GW at its lowest to " + gw(hi) + " GW at its peak") + '">' +
      '<path d="' + line + '" fill="none" class="gp-curve__line" stroke-width="2.5" stroke-linejoin="round"/>' +
      '<circle cx="' + x(iHi[0]).toFixed(1) + '" cy="' + y(iHi[1]).toFixed(1) + '" r="3" fill="none" stroke="#4fd18b" stroke-width="2" class="gp-ping"/>' +
      dot(iHi, "", "#4fd18b", "Peak", -8) + dot(iLo, "", "#ffd54a", "Low", 15) +
      (last !== iHi && last !== iLo ? dot(last, "", "currentColor", "Now", 15) : "") + "</svg>";
  }

  /* ---------- Records card ---------- */
  function renderRecCard(host, area, rec, day) {
    const body = host.querySelector("[data-area-recsum-body]") || host;
    if (!rec || !rec.board.length) {
      body.innerHTML = '<p class="area-empty">No records yet. They appear once a few days have been recorded.</p><a class="gp-card__link" href="' + recordsLink(area.code) + '">Records page →</a>';
      return;
    }
    const want = ["wind_peak_mw", "solar_peak_mw", area.has_carbon ? "carbon_low" : "renewable_share_high", "demand_high_mw"];
    const pick = want.map((k) => rec.board.find((r) => r.key === k)).filter(Boolean);
    const ex = day && day.summary ? day.summary.extremes || {} : {};
    let newest = rec.recent && rec.recent[0];
    body.innerHTML = pick.map((r) => {
      const st = REC_STYLE[r.key] || ["bolt", "#1d6fe0"];
      const t = ex[r.key] ? ex[r.key].value : null;
      const c = closeness(r, t);
      return '<div class="gp-rec"><div class="gp-rec__top">' + icon(st[0]) + '<span class="gp-rec__label">' + esc(r.label) +
        '<small>Record ' + esc(when(r.ts, area)) + '</small></span><strong>' + esc(fmtRecord(r, area)) + "</strong></div>" +
        (c != null
          ? '<div class="gp-bar" aria-hidden="true"><span class="gp-bar__fill" style="width:' + (c * 100).toFixed(1) + "%;background:" + st[1] + '"></span></div>' +
            '<small class="gp-rec__today">Today\'s best: ' + esc(fmtPrev(r, t)) + ", " + Math.round(c * 100) + "% of the record</small>"
          : '<small class="gp-rec__today">No reading for this today yet.</small>') + "</div>";
    }).join("") +
      (newest ? '<p class="area-note">Newest: ' + esc(newest.label.toLowerCase()) + ", " + esc(longDate(newest.day)) + ".</p>" : "") +
      '<p class="area-note">' + sinceLine(rec, area) + "</p>" +
      '<a class="gp-card__link" href="' + recordsLink(area.code) + '">All records' + (area.code === "GB" ? " and notable moments" : "") + " →</a>";
  }

  /* ---------- Border map ---------- */
  const mapCache = {};
  async function getMap(code) {
    if (!(code in mapCache)) {
      mapCache[code] = fetch(ROOT + "assets/maps/" + code + ".json").then((r) => (r.ok ? r.json() : null)).catch(() => null);
    }
    return mapCache[code];
  }
  /** SVG map: home country white, neighbours grey, sea transparent, one animated line per neighbour. */
  function mapSvg(map, area, flowsBy, opts) {
    opts = opts || {};
    const h = map.pts[map.home];
    let lines = "", dashes = "", tags = "";
    let max = 0;
    map.neighbours.forEach((c) => { const f = flowsBy[c]; if (f && f.latest_mw != null) max = Math.max(max, Math.abs(f.latest_mw)); });
    map.neighbours.forEach((c) => {
      const p = map.pts[c];
      if (!p) return;
      const f = flowsBy[c];
      const mx = (h[0] + p[0]) / 2, my = (h[1] + p[1]) / 2;
      const dx = p[0] - h[0], dy = p[1] - h[1];
      const d = "M" + h[0] + "," + h[1] + " Q" + (mx - dy * 0.15).toFixed(1) + "," + (my + dx * 0.15).toFixed(1) + " " + p[0] + "," + p[1];
      const v = f && f.latest_mw != null ? f.latest_mw : null;
      if (v == null || Math.abs(v) < 1) {
        lines += '<path d="' + d + '" class="gpmap-idle"/>';
      } else {
        const w = 2 + (max ? (Math.abs(v) / max) * 5 : 0);
        lines += '<path d="' + d + '" class="' + (v > 0 ? "gpmap-in" : "gpmap-out") + '" stroke-width="' + w.toFixed(1) + '"/>';
        dashes += '<path d="' + d + '" class="gpmap-dash' + (v > 0 ? " is-in" : "") + '"/>';
      }
      const lab = c;
      const tw = 10 + lab.length * 8;
      tags += '<g class="gpmap-tag"><rect x="' + (p[0] - tw / 2) + '" y="' + (p[1] - 10) + '" width="' + tw + '" height="20" rx="10"/><text x="' + p[0] + '" y="' + (p[1] + 4) + '" text-anchor="middle">' + esc(lab) + "</text></g>";
    });
    const land = map.land.map((l) => '<path d="' + l[0] + '" class="' + (l[1] ? "gpmap-home" : "gpmap-land") + '"/>').join("");
    const label = '<text x="' + h[0] + '" y="' + (h[1] + 5) + '" text-anchor="middle" class="gpmap-homelabel">' + esc(shortName(area)) + "</text>";
    return '<svg class="gpmap' + (opts.big ? " gpmap--big" : "") + '" viewBox="0 0 ' + map.w + " " + map.h + '" role="img" aria-label="' + esc("Map of " + shortName(area) + " and its neighbours. Each line shows the flow across that border: amber into " + shortName(area) + ", blue out. Moving dashes show the direction. Grey dashed lines have no reading.") + '">' +
      land + lines + dashes + tags + label + "</svg>";
  }

  function renderBordersCard(host, area, flows, map) {
    const body = host.querySelector("[data-area-borders-body]") || host;
    if (!map) {
      body.innerHTML = '<p class="area-empty">No map for this country.</p>';
      return;
    }
    const by = {};
    (flows && flows.neighbours || []).forEach((n) => { by[n.code] = n; });
    let net = null, latest = null;
    Object.values(by).forEach((n) => {
      if (n.latest_mw != null) { net = (net || 0) + n.latest_mw; if (!latest || n.latest_ts > latest) latest = n.latest_ts; }
    });
    let oldest = null;
    Object.values(by).forEach((n) => { if (n.updated && (!oldest || n.updated < oldest.updated)) oldest = n; });
    const head = net == null ? "No live border readings" : (Math.abs(net) < 1 ? "Balanced" : (net > 0 ? "Importing " : "Exporting ") + gw(Math.abs(net)) + " GW");
    const rows = map.neighbours.filter((c) => by[c]).sort((a, b) => Math.abs(by[b].latest_mw || 0) - Math.abs(by[a].latest_mw || 0)).slice(0, 4).map((c) => {
      const n = by[c];
      const v = n.latest_mw;
      const dir = v == null ? "No reading" : (Math.abs(v) < 1 ? "No flow" : (v > 0 ? "In " : "Out ") + gw(Math.abs(v)) + " GW");
      return '<li><span>' + esc(n.label) + (n.cables && n.cables.length ? " <small>(" + esc(n.cables.join(", ")) + ")</small>" : "") + '</span><strong class="' + (v > 0 ? "is-in" : v < 0 ? "is-out" : "") + '">' + esc(dir) + "</strong></li>";
    }).join("");
    body.innerHTML = '<p class="gp-card__big">' + esc(head) + "</p>" + mapSvg(map, area, by) +
      '<p class="gpmap-key"><span><i class="gpmap-key__in"></i>Into ' + esc(shortName(area)) + '</span><span><i class="gpmap-key__out"></i>Out of ' + esc(shortName(area)) + "</span><span>Thicker = more power</span></p>" +
      (rows ? '<ul class="gp-list">' + rows + "</ul>" : "") +
      '<p class="area-note">' + (latest ? "Latest reading " + clock(latest, area.tz) + ". " : "") +
      (oldest && area.code !== "GB" && area.code !== "IE" ? "ENTSO-E borders update every few hours. Oldest: " + esc(oldest.label) + ", " + esc(ago(oldest.updated)) + "." : "") + "</p>" +
      '<a class="gp-card__link" href="' + bordersLink(area.code) + '">' + (area.code === "GB" ? "Every cable, today in and out" : "Every border, today in and out") + " →</a>";
  }
  function ago(iso) {
    const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    return mins < 90 ? mins + " minutes ago" : Math.round(mins / 60) + " hours ago";
  }

  /* ---------- This year so far ---------- */
  function shareBar(energy, keys, area, faded) {
    const tot = keys.reduce((s, k) => s + (energy[k] || 0), 0) || 1;
    return '<div class="gp-sbar' + (faded ? " is-faded" : "") + '" aria-hidden="true">' + keys.map((k) => energy[k] > 0 ? '<span style="flex:' + (energy[k] / tot * 100).toFixed(2) + ";background:" + groupColor(k) + '"></span>' : "").join("") + "</div>";
  }
  function renderYearCard(host, area, yr, ann) {
    const body = host.querySelector("[data-area-year-body]") || host;
    const ty = yr && yr.this_year, ly = yr && yr.last_year;
    if (!ty) {
      body.innerHTML = '<p class="area-empty">No days recorded this year yet.</p>';
      return;
    }
    const keys = sortGroups(Object.keys(Object.assign({}, ty.energy_mwh, ly ? ly.energy_mwh : {})));
    const tot = Object.values(ty.energy_mwh).reduce((a, b) => a + b, 0) || 1;
    let badge = "";
    if (ly) {
      const diff = ty.renewable_share - ly.renewable_share;
      badge = '<span class="gp-badge ' + (diff >= 0 ? "is-up" : "is-down") + '"><svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="' + (diff >= 0 ? "M6 2 10 8H2Z" : "M6 10 10 4H2Z") + '" fill="currentColor"/></svg>' +
        Math.abs(diff).toFixed(1) + " points " + (diff >= 0 ? "up" : "down") + " on " + (yr.year - 1) + "</span>";
    }
    // Months
    const months = ty.months;
    const W = 240, H = 70, base = 58;
    const maxShare = Math.max(1, ...months.map((m) => m.renewable_share), ly ? ly.renewable_share : 0);
    const sy = (v) => base - (v / maxShare) * 48;
    const nowMonth = Number(yr.today.slice(5, 7));
    let cols = "";
    for (let m = 1; m <= 12; m++) {
      const rec = months.find((x) => x.month === m);
      const x = 4 + (m - 1) * 19.6;
      if (rec) {
        const partial = m === nowMonth;
        cols += '<rect x="' + x.toFixed(1) + '" y="' + sy(rec.renewable_share).toFixed(1) + '" width="13" height="' + (base - sy(rec.renewable_share)).toFixed(1) + '" rx="2" class="' + (partial ? "gp-col is-partial" : "gp-col") + '"><title>' + esc(new Date(Date.UTC(2000, m - 1, 15)).toLocaleDateString("en-GB", { month: "long", timeZone: "UTC" }) + ": " + rec.renewable_share + "% renewable" + (partial ? " so far" : "")) + "</title></rect>";
      }
      cols += '<text x="' + (x + 6.5).toFixed(1) + '" y="69" font-size="8" text-anchor="middle" class="gp-axis">' + "JFMAMJJASOND"[m - 1] + "</text>";
    }
    const avg = ly ? '<line x1="0" x2="' + W + '" y1="' + sy(ly.renewable_share).toFixed(1) + '" y2="' + sy(ly.renewable_share).toFixed(1) + '" class="gp-avg"/><text x="' + (W - 2) + '" y="' + (sy(ly.renewable_share) - 3).toFixed(1) + '" font-size="8" text-anchor="end" class="gp-axis">' + (yr.year - 1) + " so far</text>" : "";
    const monthsSvg = '<svg class="gp-months" viewBox="0 0 ' + W + " " + H + '" role="img" aria-label="Renewable share in each month this year. The current month is so far.">' +
      '<line x1="0" x2="' + W + '" y1="' + base + '" y2="' + base + '" class="gp-base"/>' + avg + cols + "</svg>";
    const best = ty.best_month ? new Date(Date.UTC(2000, ty.best_month.month - 1, 15)).toLocaleDateString("en-GB", { month: "long", timeZone: "UTC" }) + " " + ty.best_month.renewable_share + "%" : "-";
    // Long-term mini chart (only when the yearly source has been checked)
    let yearly = "";
    if (ann && ann.years.length && ann.source && ann.source.checked) {
      const ys = ann.years.slice(-6);
      const gk = sortGroups(Object.keys(ann.groups));
      const yW = 240, yH = 80, cw = Math.min(30, (yW - 10) / ys.length - 8);
      let svg = "";
      ys.forEach((y, j) => {
        const i = ann.years.indexOf(y);
        const t = gk.reduce((s, k) => s + (ann.groups[k][i] || 0), 0) || 1;
        let top = 6;
        const x = 6 + j * ((yW - 10) / ys.length);
        gk.forEach((k) => {
          const h = (ann.groups[k][i] || 0) / t * 62;
          if (h > 0) svg += '<rect x="' + x.toFixed(1) + '" y="' + top.toFixed(1) + '" width="' + cw.toFixed(1) + '" height="' + h.toFixed(1) + '" fill="' + groupColor(k) + '"/>';
          top += h;
        });
        svg += '<text x="' + (x + cw / 2).toFixed(1) + '" y="78" font-size="8" text-anchor="middle" class="gp-axis">' + (y === ann.current_year ? y + "*" : y) + "</text>";
      });
      yearly = '<div class="gp-sub"><span>Yearly mix, from ' + esc(ann.source.credit.split(",")[0]) + '</span><svg class="gp-months" viewBox="0 0 ' + yW + " " + yH + '" role="img" aria-label="' + esc("Share of each source in " + shortName(area) + "'s yearly generation, " + ys[0] + " to " + ys[ys.length - 1]) + '">' + svg + "</svg></div>";
    }
    body.innerHTML =
      '<div class="gp-yr-head"><p class="gp-card__big">' + pct(ty.renewable_share, 1) + " renewable</p>" + badge + "</div>" +
      (area.code !== "GB" ? '<p class="gp-pill">' + pct(ty.zero_carbon_share, 1) + " zero-carbon</p>" : "") +
      '<div class="gp-sbars"><span>' + yr.year + "</span>" + shareBar(ty.energy_mwh, keys, area, false) +
      (ly ? "<span>" + (yr.year - 1) + "</span>" + shareBar(ly.energy_mwh, keys, area, true) : "") + "</div>" +
      '<ul class="gp-keys">' + keys.filter((k) => ty.energy_mwh[k] > 0).map((k) => '<li><i style="background:' + groupColor(k) + '"></i>' + esc(groupLabel(k, area)) + " " + pct(ty.energy_mwh[k] / tot * 100, 0) + "</li>").join("") + "</ul>" +
      '<div class="gp-sub"><span>Renewable share by month</span>' + monthsSvg + "</div>" +
      '<dl class="gp-mini"><div><dt>Generated</dt><dd>' + energy(ty.gen_mwh) + "</dd></div><div><dt>Best month</dt><dd>" + esc(best) + "</dd></div>" +
      (ty.imports_net_mwh != null ? "<div><dt>" + (ty.imports_net_mwh >= 0 ? "Net imports" : "Net exports") + "</dt><dd>" + energy(Math.abs(ty.imports_net_mwh)) + "</dd></div>" : "") + "</dl>" +
      yearly +
      '<p class="area-note">This year from this site\'s readings, 1 January to today (' + ty.days + " days)." + (ly ? " " + (yr.year - 1) + " is the same dates." : " No figures for the same dates last year.") + (yearly ? " * = so far." : "") + "</p>" +
      '<a class="gp-card__link" href="' + (area.code === "GB" ? ROOT + "pages/history.html#annual-heading" : "#annual-heading") + '">' + (area.code === "GB" ? "History and long-term chart" : "Long-term chart") + " →</a>";
  }

  /* ---------- [country] and its neighbours right now ---------- */
  function renderCompare(host, area, all, map) {
    const body = host.querySelector("[data-area-compare-body]") || host;
    if (!all || !all.areas.length) {
      body.innerHTML = '<p class="area-empty">No readings to compare right now.</p>';
      return;
    }
    const wanted = [area.code].concat(map ? map.neighbours : []);
    const rows = all.areas.filter((a) => wanted.indexOf(a.code) !== -1);
    if (rows.length < 2) {
      body.innerHTML = '<p class="area-empty">Not enough neighbours have readings right now.</p>';
      return;
    }
    const block = (title, key, color) => {
      const list = rows.slice().sort((a, b) => b[key] - a[key]);
      return '<div class="gp-cmp"><h3>' + esc(title) + '</h3><ul class="gp-cmp__list">' + list.map((a) =>
        '<li class="' + (a.code === area.code ? "is-me" : "") + '"><a href="' + ROOT + esc(a.page) + '">' + esc(a.name.replace(/\s*\(.*\)$/, "")) + '</a><span class="gp-bar" aria-hidden="true"><span class="gp-bar__fill" style="width:' + a[key] + "%;background:" + color + '"></span></span><strong>' + pct(a[key], 0) + "</strong></li>").join("") + "</ul></div>";
    };
    const gbNote = rows.some((a) => a.code === "GB") ? " Great Britain does not count biomass as renewable, the others do." : "";
    body.innerHTML = '<div class="gp-cmp-wrap">' + block("Renewable share", "renewable_share", "#2fa876") + block("Zero-carbon share (with nuclear)", "zero_carbon_share", "#9d8fe8") + "</div>" +
      '<p class="area-note">Each country\'s latest reading. Times can differ by up to an hour.' + gbNote + " Price zones (Norway, Denmark, Sweden) are the zone on each country's page.</p>";
  }

  /* ---------- Records page ---------- */
  function stepChart(area, rec, key) {
    const r = rec.board.find((b) => b.key === key);
    if (!r) return '<p class="area-empty">No record of this kind yet.</p>';
    // Start 30 days after recording began, as the "broken most recently"
    // list does: in the first few weeks nearly every day sets a "record".
    const first = rec.firsts && rec.firsts[key];
    const all = [];
    if (first) all.push({ day: first.day, value: first.value, ts: first.ts });
    (rec.breaks || []).filter((b) => b.key === key).forEach((b) => all.push({ day: b.day, value: b.value, ts: b.ts }));
    const settleD = new Date(rec.since + "T00:00:00Z"); settleD.setUTCDate(settleD.getUTCDate() + 30);
    const settle = settleD.toISOString().slice(0, 10);
    const before = all.filter((s) => s.day < settle);
    const steps = [];
    if (before.length) steps.push(Object.assign({}, before[before.length - 1], { day: settle, start: true }));
    all.filter((s) => s.day >= settle).forEach((s) => steps.push(s));
    if (steps.length < 2) return '<p class="area-empty">This record has not been broken since ' + esc(longDate(settle)) + ".</p>";
    const W = 1000, H = 260, pl = 90, pr = 20, pt = 30, pb = 34;
    const t0 = new Date(steps[0].day).getTime(), t1 = Date.now();
    const vals = steps.map((s) => s.value);
    let lo = Math.min(...vals), hi = Math.max(...vals);
    if (hi === lo) { hi += 1; lo -= 1; }
    const pad = (hi - lo) * 0.1; lo -= pad; hi += pad;
    const x = (d) => pl + ((new Date(d).getTime() - t0) / Math.max(1, t1 - t0)) * (W - pl - pr);
    const y = (v) => pt + (1 - (v - lo) / (hi - lo)) * (H - pt - pb);
    let d = "M" + x(steps[0].day).toFixed(1) + "," + y(steps[0].value).toFixed(1);
    for (let i = 1; i < steps.length; i++) d += " H" + x(steps[i].day).toFixed(1) + " V" + y(steps[i].value).toFixed(1);
    d += " H" + (W - pr);
    const st = REC_STYLE[key] || ["bolt", "#1d6fe0"];
    let grid = "";
    for (let k = 0; k <= 3; k++) {
      const v = lo + ((hi - lo) * k) / 3;
      grid += '<line x1="' + pl + '" x2="' + (W - pr) + '" y1="' + y(v).toFixed(1) + '" y2="' + y(v).toFixed(1) + '" class="gp-grid"/><text x="' + (pl - 8) + '" y="' + (y(v) + 4).toFixed(1) + '" font-size="13" text-anchor="end" class="gp-axis">' + esc(fmtPrev(r, v)) + "</text>";
    }
    const dots = steps.map((s, i) => '<circle cx="' + x(s.day).toFixed(1) + '" cy="' + y(s.value).toFixed(1) + '" r="5" fill="' + st[1] + '"><title>' + esc(when(s.ts, area) + ": " + fmtPrev(r, s.value) + (s.start ? " (the record on this date)" : "")) + "</title></circle>").join("");
    const lastS = steps[steps.length - 1];
    const years = [];
    for (let yv = new Date(t0).getUTCFullYear() + 1; yv <= new Date(t1).getUTCFullYear(); yv++) years.push(yv);
    const yl = years.map((yv) => '<text x="' + x(yv + "-01-01").toFixed(1) + '" y="' + (H - 10) + '" font-size="13" text-anchor="middle" class="gp-axis">' + yv + "</text>").join("");
    return '<svg class="gp-step" viewBox="0 0 ' + W + " " + H + '" role="img" aria-label="' + esc("Step chart of the " + r.label.toLowerCase() + " record since recording started. It was broken " + (steps.length - 1) + " times.") + '">' + grid + yl +
      '<path d="' + d + '" fill="none" stroke="' + st[1] + '" stroke-width="3" class="gp-draw"/>' + dots +
      '<circle cx="' + x(lastS.day).toFixed(1) + '" cy="' + y(lastS.value).toFixed(1) + '" r="4" fill="none" stroke="' + st[1] + '" stroke-width="2" class="gp-ping"/></svg>' +
      '<p class="area-note">From ' + esc(longDate(settle)) + ", 30 days after this site started recording. Each step is a new record (" + (steps.length - 1) + " since then). Hover a dot for its date. Current record: " + esc(fmtRecord(r, area)) + ", " + esc(when(r.ts, area)) + ".</p>";
  }
  function breaksByMonth(rec) {
    const counts = {};
    (rec.breaks || []).forEach((b) => { const m = b.day.slice(0, 7); counts[m] = (counts[m] || 0) + 1; });
    const months = [];
    const now = new Date();
    for (let i = 11; i >= 0; i--) {
      const d = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() - i, 1));
      const k = d.toISOString().slice(0, 7);
      months.push({ k, n: counts[k] || 0, l: d.toLocaleDateString("en-GB", { month: "short", timeZone: "UTC" }).slice(0, 1) });
    }
    const max = Math.max(1, ...months.map((m) => m.n));
    return '<svg class="gp-months" viewBox="0 0 240 70" role="img" aria-label="' + esc("Records broken each month over the last 12 months: " + months.map((m) => m.n).join(", ")) + '"><line x1="0" x2="240" y1="58" y2="58" class="gp-base"/>' +
      months.map((m, i) => '<rect x="' + (4 + i * 19.6).toFixed(1) + '" y="' + (58 - (m.n / max) * 48).toFixed(1) + '" width="13" height="' + ((m.n / max) * 48).toFixed(1) + '" rx="2" fill="#ffd54a"><title>' + m.k + ": " + m.n + "</title></rect>" +
        '<text x="' + (10.5 + i * 19.6).toFixed(1) + '" y="69" font-size="8" text-anchor="middle" class="gp-axis">' + m.l + "</text>").join("") + "</svg>";
  }
  async function initRecordsPage(host) {
    const q = new URLSearchParams(window.location.search);
    const code = (q.get("area") || "GB").toUpperCase();
    const [rec, day] = await Promise.all([getJSON("records.php?area=" + encodeURIComponent(code) + "&all=1"), getJSON("day.php?area=" + encodeURIComponent(code))]);
    const h1 = document.querySelector("h1");
    if (!rec) {
      if (h1) h1.textContent = "Records";
      host.innerHTML = '<p class="area-empty">That country is not covered. Pick one from the menu.</p>';
      return;
    }
    const area = rec.area;
    document.title = shortName(area) + " records - Grid Pulse";
    if (h1) h1.textContent = shortName(area) + " records";
    const crumb = document.querySelector("[data-day-crumb]");
    if (crumb) crumb.innerHTML = '<a href="' + ROOT + area.page + '">' + esc(area.name) + "</a> › Records";
    document.querySelectorAll("[data-only-area]").forEach((el) => { el.hidden = el.getAttribute("data-only-area") !== code; });
    const intro = document.querySelector("[data-records-intro]");
    if (intro) intro.textContent = code === "GB"
      ? "The highest and lowest figures for Great Britain's grid. Official all-time records come first, with sources. Below them are records worked out from this site's own readings."
      : "The highest and lowest figures for " + area.name + ", worked out from this site's own readings. Official records from the grid operator are on the " + shortName(area) + " page, under Records and context.";
    const keys = rec.board.map((b) => b.key);
    const chartKeys = ["wind_peak_mw", "solar_peak_mw", "renewable_share_high", "zero_carbon_share_high", "demand_high_mw", "carbon_low", "price_low"].filter((k) => keys.indexOf(k) !== -1);
    let html = "";
    if (rec.board.length) {
      html += '<section class="panel" aria-labelledby="climb-h"><div class="gp-head"><h2 id="climb-h">How the records have climbed</h2>' +
        '<div class="gp-pills" role="group" aria-label="Record to show">' + chartKeys.map((k, i) => '<button type="button" aria-pressed="' + (i === 0) + '" data-key="' + k + '">' + esc(rec.board.find((b) => b.key === k).label.replace(/^Highest |^Lowest /, (m) => m)) + "</button>").join("") + "</div></div>" +
        '<div data-step></div></section>';
      html += '<div class="gp-cols"><section class="panel" aria-labelledby="board-h"><h2 id="board-h">Since we started recording</h2><p class="chart-caption" data-area-records-since></p><div data-area-records-body></div></section>' +
        '<section class="panel gp-dark" aria-labelledby="month-h"><h2 id="month-h">Records broken each month</h2>' + breaksByMonth(rec) + '<p class="area-note">Last 12 months. Only real breaks count, not the first values recorded.</p></section></div>';
    } else {
      html += '<p class="area-empty">No records yet. They appear once a few days have been recorded.</p>';
    }
    host.innerHTML = html;
    if (!rec.board.length) return;
    renderRecords(host.querySelector("[data-area-records-body]").parentNode, area, rec);
    // Closeness bars on the board, from today's figures
    const ex = day && day.summary ? day.summary.extremes || {} : {};
    const stepBox = host.querySelector("[data-step]");
    const show = (k) => { stepBox.innerHTML = stepChart(area, rec, k); };
    host.querySelectorAll("[data-key]").forEach((b) => b.addEventListener("click", () => {
      host.querySelectorAll("[data-key]").forEach((x) => x.setAttribute("aria-pressed", String(x === b)));
      show(b.dataset.key);
    }));
    if (chartKeys.length) show(chartKeys[0]);
    host.querySelectorAll(".area-board .area-reclist li").forEach((li, i) => {
      const r = rec.board[i];
      if (!r) return;
      const c = closeness(r, ex[r.key] ? ex[r.key].value : null);
      if (c == null) return;
      const st = REC_STYLE[r.key] || ["bolt", "#1d6fe0"];
      const bar = document.createElement("span");
      bar.className = "gp-bar gp-bar--row";
      bar.setAttribute("aria-hidden", "true");
      bar.innerHTML = '<span class="gp-bar__fill" style="width:' + (c * 100).toFixed(1) + "%;background:" + st[1] + '"></span>';
      li.insertBefore(bar, li.querySelector("small"));
      const sm = li.querySelector("small");
      if (sm) sm.insertAdjacentText("beforeend", ". Today: " + Math.round(c * 100) + "% of the record");
    });
  }

  /* ---------- Borders page ---------- */
  async function initBordersPage(host) {
    const q = new URLSearchParams(window.location.search);
    const code = (q.get("area") || "FR").toUpperCase();
    if (code === "GB") { window.location.replace(ROOT + "pages/interconnectors.html"); return; }
    const [flows, map] = await Promise.all([getJSON("flows.php?area=" + encodeURIComponent(code)), getMap(code)]);
    const h1 = document.querySelector("h1");
    if (!flows) {
      if (h1) h1.textContent = "Borders";
      host.innerHTML = '<p class="area-empty">That country is not covered. Pick one from the menu.</p>';
      return;
    }
    const area = flows.area;
    document.title = shortName(area) + " borders - Grid Pulse";
    if (h1) h1.textContent = shortName(area) + ": electricity across its borders";
    const crumb = document.querySelector("[data-day-crumb]");
    if (crumb) crumb.innerHTML = '<a href="' + ROOT + area.page + '">' + esc(area.name) + "</a> › Borders";
    const by = {};
    flows.neighbours.forEach((n) => { by[n.code] = n; });
    host.innerHTML = (map ? '<section class="panel" aria-labelledby="bmap-h"><h2 id="bmap-h">Right now</h2>' + mapSvg(map, area, by, { big: true }) +
      '<p class="gpmap-key"><span><i class="gpmap-key__in"></i>Into ' + esc(shortName(area)) + '</span><span><i class="gpmap-key__out"></i>Out of ' + esc(shortName(area)) + "</span><span>Thicker = more power</span><span>Grey dashes = no reading</span></p></section>" : "") +
      '<section class="panel" aria-labelledby="flows-heading"><h2 id="flows-heading">Today, border by border</h2><div data-area-flows-body></div></section>';
    renderFlows(host.querySelector("[data-area-flows-body]").parentNode, area, flows);
  }

  /* ---------- Country page wiring ---------- */
  async function initArea(root) {
    const code = root.getAttribute("data-area");
    let day = null;
    const q = (sel) => document.querySelector(sel);
    const figures = q("[data-area-figures]");
    const nowBox = q("[data-area-now]");
    const replay = q("[data-area-replay]");
    const today = q("[data-area-today]");
    const recsum = q("[data-area-recsum]");
    const records = q("[data-area-records]");
    const flowsBox = q("[data-area-flows]");
    const borders = q("[data-area-borders]");
    const yearBox = q("[data-area-year]");
    const compare = q("[data-area-compare]");
    const annual = q("[data-area-annual]");
    document.querySelectorAll("[data-area-archive-link]").forEach((a) => a.setAttribute("href", archiveLink(code)));
    document.querySelectorAll("[data-area-records-link]").forEach((a) => a.setAttribute("href", recordsLink(code)));
    document.querySelectorAll("[data-area-borders-link]").forEach((a) => a.setAttribute("href", bordersLink(code)));

    let rec = null;
    async function refreshDay() {
      const d = await getJSON("day.php?area=" + encodeURIComponent(code));
      if (d) day = d;
      const area = day ? day.area : { code, name: code, tz: "UTC", currency: "" };
      if (figures && !figures.dataset.built && day) { figures.dataset.built = "1"; initFigures(figures, area, () => day); }
      if (nowBox) renderNow(nowBox, area, day);
      if (today) renderToday(today, area, day);
      if (replay) renderReplay(replay, area, day);
      if (recsum && rec) renderRecCard(recsum, area, rec, day);
      return area;
    }
    const area = await refreshDay();
    setInterval(refreshDay, 5 * 60 * 1000);

    const jobs = [];
    if (recsum || records) {
      jobs.push(getJSON("records.php?area=" + encodeURIComponent(code)).then((r) => {
        rec = r;
        if (recsum) renderRecCard(recsum, area, rec, day);
        if (records) renderRecords(records, area, rec);
      }));
    }
    if (borders || compare) {
      jobs.push(Promise.all([borders ? getJSON("flows.php?area=" + encodeURIComponent(code)) : null, getMap(code), compare ? getJSON("now_all.php") : null]).then(([fl, map, all]) => {
        if (borders) renderBordersCard(borders, area, fl, map);
        if (compare) renderCompare(compare, area, all, map);
      }));
    }
    if (flowsBox) jobs.push(getJSON("flows.php?area=" + encodeURIComponent(code)).then((fl) => renderFlows(flowsBox, area, fl)));
    if (yearBox || annual) {
      jobs.push(Promise.all([yearBox ? getJSON("year.php?area=" + encodeURIComponent(code)) : null, getJSON("annual.php?area=" + encodeURIComponent(code))]).then(([yr, ann]) => {
        if (yearBox) renderYearCard(yearBox, area, yr, ann);
        if (annual) renderAnnual(annual, area, ann);
      }));
    }
    await Promise.all(jobs);
  }

  function init() {
    const dayHost = document.querySelector("[data-area-day]");
    if (dayHost) { initDayPage(dayHost); return; }
    const arcHost = document.querySelector("[data-area-archive]");
    if (arcHost) { initArchivePage(arcHost); return; }
    const recHost = document.querySelector("[data-area-records-page]");
    if (recHost) { initRecordsPage(recHost); return; }
    const bHost = document.querySelector("[data-area-borders-page]");
    if (bHost) { initBordersPage(bHost); return; }
    const root = document.querySelector("[data-area]");
    if (root) initArea(root);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
