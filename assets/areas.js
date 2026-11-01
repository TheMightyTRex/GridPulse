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
    body.innerHTML = '<dl class="area-facts">' + rows.map((r) => "<div><dt>" + esc(r[0]) + "</dt><dd>" + esc(r[1]) + "</dd></div>").join("") + "</dl>" +
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
    const annual = q("[data-area-annual]");
    const archiveLinkEl = q("[data-area-archive-link]");
    if (archiveLinkEl) archiveLinkEl.setAttribute("href", archiveLink(code));

    async function refreshDay() {
      const d = await getJSON("day.php?area=" + encodeURIComponent(code));
      if (d) day = d;
      const area = day ? day.area : { code, name: code, tz: "UTC", currency: "" };
      if (figures && !figures.dataset.built && day) { figures.dataset.built = "1"; initFigures(figures, area, () => day); }
      if (nowBox) renderNow(nowBox, area, day);
      if (today) renderToday(today, area, day);
      if (replay) renderReplay(replay, area, day);
      return area;
    }
    const area = await refreshDay();
    setInterval(refreshDay, 5 * 60 * 1000);

    if (recsum || records) {
      const rec = await getJSON("records.php?area=" + encodeURIComponent(code));
      if (recsum) renderRecSummary(recsum, area, rec);
      if (records) renderRecords(records, area, rec);
    }
    if (flowsBox) renderFlows(flowsBox, area, await getJSON("flows.php?area=" + encodeURIComponent(code)));
    if (annual) renderAnnual(annual, area, await getJSON("annual.php?area=" + encodeURIComponent(code)));
  }

  function init() {
    const dayHost = document.querySelector("[data-area-day]");
    if (dayHost) { initDayPage(dayHost); return; }
    const arcHost = document.querySelector("[data-area-archive]");
    if (arcHost) { initArchivePage(arcHost); return; }
    const root = document.querySelector("[data-area]");
    if (root) initArea(root);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
