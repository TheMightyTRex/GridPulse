/* ==========================================================================
   Grid Pulse - power station map (pages/power-stations.html)
   Every major power producer station in the UK from DESNZ's DUKES table
   5.11, on an OpenStreetMap street map (Leaflet, assets/vendor/leaflet),
   with live planned output from Elexon for the stations matched to their
   BM units. Data: api/stations.php (see includes/stations.php).
   ========================================================================== */
(function () {
  "use strict";
  const host = document.querySelector("[data-stations-page]");
  if (!host) return;
  const GP = window.GridPreview || {};
  const SRC = document.currentScript ? document.currentScript.src : "";
  const API = (function () { const b = SRC.replace(/assets\/stations\.js(?:\?.*)?$/, "api/"); return b && b !== SRC ? b : "api/"; })();
  const ROOT = API.replace(/api\/$/, "");
  const VER = (function () { const m = SRC.match(/[?&]v=([0-9a-z]+)/); return m ? "?v=" + m[1] : ""; })();
  const TZ = "Europe/London";
  const esc = (s) => String(s == null ? "" : s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const mw = (v) => (v == null ? "-" : v >= 100 ? Math.round(v).toLocaleString("en-GB") : (Math.round(v * 10) / 10).toLocaleString("en-GB"));
  function clock(iso) {
    if (!iso) return "-";
    const p = new Intl.DateTimeFormat("en-GB", { timeZone: TZ, hour: "numeric", minute: "2-digit", hour12: true }).formatToParts(new Date(iso));
    const g = (t) => (p.find((x) => x.type === t) || { value: "" }).value;
    return g("hour") + (g("minute") === "00" ? "" : "." + g("minute")) + g("dayPeriod").toLowerCase().replace(/[.\s]/g, "");
  }
  // Technology groups, coloured like the rest of the site.
  const TECH = [
    ["Wind", "Wind", "#2fa876"], ["Solar", "Solar", "#e0a800"], ["Hydro", "Hydro", "#2a8fbd"], ["Pumped Hydro", "Pumped hydro", "#0e8f7a"],
    ["Nuclear", "Nuclear", "#6a5acd"], ["Bioenergy", "Bioenergy", "#7a8f3f"], ["Fossil Fuel", "Fossil fuel", "#8a5a3c"],
  ];
  const techOf = (t) => TECH.find((x) => x[0] === t) || [t, t, "#7a7a7a"];

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

  async function init() {
    let d = null;
    try { const r = await fetch(API + "stations.php"); d = r.ok ? await r.json() : null; } catch (e) { d = null; }
    if (!d || !d.ok || !d.stations.length) {
      host.innerHTML = '<p class="area-empty">The power station list is not available yet. It is fetched from DESNZ once a week by the scheduler.</p>';
      return;
    }
    const S = d.stations.map((r) => ({ code: r[0], site: r[1], company: r[2], tech: r[3], type: r[4], fuel: r[5], cap: r[6], lat: r[7], lon: r[8], year: r[9], country: r[10], conn: r[11], live: d.live[r[0]] || null }));
    const totalGW = S.reduce((s, x) => s + (x.cap || 0), 0) / 1000;
    const liveS = S.filter((x) => x.live);
    const liveGW = liveS.reduce((s, x) => s + x.live.mw, 0) / 1000;
    const counts = {};
    S.forEach((x) => { counts[x.tech] = (counts[x.tech] || 0) + 1; });

    host.innerHTML =
      '<section class="home-hero area-now" aria-label="Power stations"><div class="home-hero__main">' +
      '<p class="home-hero__pill"><span class="home-hero__dot" aria-hidden="true"></span><span class="home-hero__updated">Live output at:</span> <span>' + esc(d.live_ts ? clock(d.live_ts) : "-") + "</span></p>" +
      '<p class="area-now__big"><span class="home-hero__share">' + S.length.toLocaleString("en-GB") + '</span> <span class="home-hero__lead">power stations on the map, with ' + Math.round(totalGW) + " GW of capacity between them</span></p></div>" +
      '<div class="home-hero__card"><p>' + (liveS.length ? liveS.length + " of the biggest have a live figure: they plan to make " + liveGW.toFixed(1) + " GW this half hour." : "Live figures are not available right now.") + "</p></div></section>" +
      '<section class="panel" aria-labelledby="ps-map-h"><h2 id="ps-map-h">Where they are</h2>' +
      '<form class="ps-tools" data-ps-form aria-label="Filter the map">' +
      '<fieldset class="ps-techs"><legend>Show</legend>' + TECH.filter((t) => counts[t[0]]).map((t) => '<label class="ps-chip"><input type="checkbox" name="tech" value="' + esc(t[0]) + '" checked><i style="background:' + t[2] + '" aria-hidden="true"></i>' + esc(t[1]) + " <small>(" + counts[t[0]] + ")</small></label>").join("") + "</fieldset>" +
      '<label class="ps-min">At least <select name="min"><option value="0">any size</option><option value="10">10 MW</option><option value="50" selected>50 MW</option><option value="100">100 MW</option><option value="500">500 MW</option></select></label>' +
      '<label class="ps-chip"><input type="checkbox" name="live"> Only stations with a live figure</label>' +
      '<button type="button" class="pc-btn pc-btn--text" data-ps-reset>Whole of the UK</button></form>' +
      '<div class="pc-mapgrid"><div class="pc-mapbox"><div class="pc-street" data-ps-map role="region" aria-label="Street map of UK power stations. The list beside it gives every station in view."><p class="area-empty">Loading the map…</p></div></div>' +
      '<div class="pc-side"><div class="pc-detail" data-ps-detail aria-live="polite"></div><h3 class="area-sub" data-ps-inview-h>In view</h3><ul class="pc-inview" data-ps-inview></ul></div></div>' +
      '<p class="area-note">Bigger circles are bigger stations. Select one, or a station in the list, to see its details. Use + and − to zoom and drag to move around.</p></section>' +
      '<section class="panel" aria-labelledby="ps-about-h"><h2 id="ps-about-h">About this map</h2>' +
      "<p>The stations are DESNZ's list of power stations run by the major power producers, as published in the Digest of UK Energy Statistics. It leaves out most small generators, such as rooftop solar and many small wind farms, and battery sites. It is updated once a year.</p>" +
      "<p>The live figure is the output a station has told the system operator it plans to run at this half hour (its physical notification), from Elexon. The system operator can ask it to change that, so the real output can differ. Only the biggest stations in Great Britain have been matched to Elexon's units, and only where the match is clear, so many stations show no live figure.</p>" +
      '<p class="area-note">Station list: ' + esc(d.edition) + ', <a href="https://www.gov.uk/government/statistics/electricity-chapter-5-digest-of-united-kingdom-energy-statistics-dukes">DESNZ</a>. Contains public sector information licensed under the Open Government Licence v3.0. Live output: <a href="https://bmrs.elexon.co.uk/">Elexon Insights</a> (physical notifications); contains BMRS data © Elexon Limited copyright and database right 2026. Stations matched to Elexon units on ' + esc(d.matching_checked ? new Date(d.matching_checked + "T12:00:00Z").toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric", timeZone: "UTC" }) : "-") + ".</p></section>";

    const form = host.querySelector("[data-ps-form]");
    const el = host.querySelector("[data-ps-map]");
    const detail = host.querySelector("[data-ps-detail]");
    const inview = host.querySelector("[data-ps-inview]");
    const inviewH = host.querySelector("[data-ps-inview-h]");
    let lmap = null, selected = null, shown = [];
    const markers = new Map();

    function detailHtml(x) {
      if (!x) return '<p class="area-note">Select a station on the map or in the list to see its details.</p>';
      const t = techOf(x.tech);
      const rows = [
        ["Owner", x.company], ["Technology", t[1] + (x.type && x.type !== x.tech ? ", " + x.type : "")], ["Fuel", x.fuel || "Not given"],
        ["Capacity", mw(x.cap) + " MW"], ["Opened", x.year || "Not given"], ["Connected to", x.conn === "HV" ? "High-voltage network" : x.conn === "LV" ? "Lower-voltage network" : (x.conn || "Not given")],
        ["Where", x.country || "-"], ["DESNZ code", x.code],
      ];
      const live = x.live
        ? '<p class="ps-live"><strong>' + mw(x.live.mw) + " MW</strong> planned for the half hour from " + esc(clock(x.live.ts)) + (x.cap ? ", " + Math.round((x.live.mw / x.cap) * 100) + "% of its capacity" : "") + ".</p>" +
          '<p class="area-note">Elexon units: ' + esc(x.live.units.join(", ")) + (x.live.reporting < x.live.units.length ? " (" + x.live.reporting + " reporting)" : "") + ".</p>"
        : '<p class="area-note">No live figure for this station. Only the biggest stations in Great Britain have been matched to Elexon\'s units.</p>';
      return '<h3 class="area-sub">' + esc(x.site) + "</h3>" + live + '<dl class="pc-dl">' + rows.map((r) => "<dt>" + esc(r[0]) + "</dt><dd>" + esc(r[1]) + "</dd>").join("") + "</dl>";
    }
    function filtered() {
      const techs = [...form.querySelectorAll('input[name="tech"]:checked')].map((i) => i.value);
      const min = +form.min.value, liveOnly = form.live.checked;
      return S.filter((x) => techs.includes(x.tech) && (x.cap || 0) >= min && (!liveOnly || x.live));
    }
    function listInView() {
      const b = lmap ? lmap.getBounds() : null;
      const vis = b ? shown.filter((x) => b.contains([x.lat, x.lon])) : shown;
      inviewH.textContent = "In view: " + vis.length + (vis.length === 1 ? " station" : " stations");
      inview.innerHTML = vis.length ? vis.slice(0, 80).map((x) => '<li><button type="button" class="pc-item' + (x === selected ? " is-selected" : "") + '" data-code="' + esc(x.code) + '"><i class="ci-dot" style="background:' + techOf(x.tech)[2] + '" aria-hidden="true"></i><span>' + esc(x.site) + (x.live ? ' <small class="ps-livetag">live</small>' : "") + "</span><strong>" + mw(x.cap) + " MW</strong></button></li>").join("") +
        (vis.length > 80 ? '<li class="area-note">And ' + (vis.length - 80) + " more. Zoom in, or raise the size, to see them.</li>" : "")
        : '<li class="area-note">No stations here with these filters.</li>';
    }
    function select(x, fly) {
      selected = x;
      detail.innerHTML = detailHtml(x);
      markers.forEach((m, code) => m.setStyle({ weight: x && code === x.code ? 3 : 1, color: x && code === x.code ? "#14181f" : "#ffffff" }));
      if (x && lmap && fly) lmap.setView([x.lat, x.lon], Math.max(lmap.getZoom(), 10));
      listInView();
    }
    function draw() {
      shown = filtered();
      if (lmap) {
        markers.forEach((m) => m.remove());
        markers.clear();
        shown.slice().sort((a, b) => (b.cap || 0) - (a.cap || 0)).forEach((x) => {
          const m = window.L.circleMarker([x.lat, x.lon], { radius: 3 + Math.sqrt((x.cap || 1) / 2400) * 16, weight: 1, color: "#ffffff", fillColor: techOf(x.tech)[2], fillOpacity: 0.85 }).addTo(lmap);
          m.bindTooltip(x.site + ", " + mw(x.cap) + " MW" + (x.live ? ", planning " + mw(x.live.mw) + " MW now" : ""));
          m.on("click", () => select(x, false));
          markers.set(x.code, m);
        });
      }
      if (selected && !shown.includes(selected)) selected = null;
      select(selected, false);
    }
    try {
      await loadLeaflet();
      const L = window.L;
      el.innerHTML = "";
      lmap = L.map(el, { scrollWheelZoom: false, zoomSnap: 0.25 });
      L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' }).addTo(lmap);
      const home = () => lmap.fitBounds([[49.8, -8.2], [60.9, 2.0]]);
      home();
      lmap.on("moveend", listInView);
      host.querySelector("[data-ps-reset]").addEventListener("click", home);
    } catch (e) {
      el.innerHTML = '<p class="area-empty">The map could not be loaded. Every station in the list is still listed beside it.</p>';
    }
    form.addEventListener("change", draw);
    inview.addEventListener("click", (ev) => { const b = ev.target.closest("button[data-code]"); if (b) select(S.find((x) => x.code === b.getAttribute("data-code")), true); });
    draw();
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
