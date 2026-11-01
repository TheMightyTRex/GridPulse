/* ==========================================================================
   Grid Pulse - shared behaviour
   ========================================================================== */

(function () {
  "use strict";

  /* ---------- Theme: follows the system by default, with a Light/Dark override ----------
     Three states cycled by the toggle button: "system" (default - follows the OS/browser
     colour-scheme preference and updates live if it changes) → "light" → "dark" → back to
     "system". Only "light"/"dark" are ever persisted; "system" means no stored preference. */
  const THEME_KEY = "grid-live-theme";
  const THEME_ORDER = ["system", "light", "dark"];
  const THEME_LABEL = { system: "System", light: "Light mode", dark: "Dark mode" };
  const root = document.documentElement;
  let currentTheme = "system";

  function systemPrefersDark() {
    return window.matchMedia("(prefers-color-scheme: dark)").matches;
  }

  function applyTheme(theme) {
    if (!THEME_ORDER.includes(theme)) theme = "system";
    currentTheme = theme;
    if (theme === "dark" || theme === "light") {
      root.setAttribute("data-theme", theme);
    } else {
      root.removeAttribute("data-theme");
    }
    const btn = document.querySelector("[data-theme-toggle]");
    if (btn) {
      const isDark = theme === "dark" || (theme === "system" && systemPrefersDark());
      btn.removeAttribute("aria-pressed"); // not a true binary toggle - 3 states, conveyed via the visible/announced label
      btn.dataset.themeActive = isDark ? "dark" : "light";
      btn.dataset.themeState = theme;
      const label = btn.querySelector("[data-theme-label]");
      if (label) {
        label.textContent = THEME_LABEL[theme];
        label.setAttribute("aria-live", "polite");
      }
    }
  }

  function initTheme() {
    let stored = localStorage.getItem(THEME_KEY);
    if (stored !== "light" && stored !== "dark") stored = "system";
    applyTheme(stored);

    const btn = document.querySelector("[data-theme-toggle]");
    if (btn) {
      btn.addEventListener("click", () => {
        const idx = THEME_ORDER.indexOf(currentTheme);
        const next = THEME_ORDER[(idx + 1) % THEME_ORDER.length];
        if (next === "system") localStorage.removeItem(THEME_KEY);
        else localStorage.setItem(THEME_KEY, next);
        applyTheme(next);
        if (window.GridPreview && window.GridPreview.redrawAll) {
          setTimeout(window.GridPreview.redrawAll, 50);
        }
      });
    }

    // Live-update when following the system and the OS preference changes mid-session
    const mql = window.matchMedia("(prefers-color-scheme: dark)");
    const onSystemChange = () => {
      if (currentTheme === "system") {
        applyTheme("system");
        if (window.GridPreview && window.GridPreview.redrawAll) {
          setTimeout(window.GridPreview.redrawAll, 50);
        }
      }
    };
    if (mql.addEventListener) mql.addEventListener("change", onSystemChange);
    else if (mql.addListener) mql.addListener(onSystemChange); // older Safari
  }

  /* ---------- Site navigation: hamburger + grouped dropdown/accordion ---------- */
  function initNav() {
    const navToggle = document.querySelector("[data-nav-toggle]");
    const navList = document.querySelector("[data-nav-list]");
    const groupToggles = Array.from(document.querySelectorAll("[data-nav-group]"));
    const navEl = document.querySelector(".site-head") || document.querySelector(".site-nav");

    function closeAllGroups(exceptBtn) {
      groupToggles.forEach((btn) => {
        if (btn === exceptBtn) return;
        const menu = document.getElementById(btn.getAttribute("aria-controls"));
        btn.setAttribute("aria-expanded", "false");
        if (menu) menu.classList.remove("is-open");
      });
    }

    function closeMobileNav() {
      closeAllGroups();
      if (!navToggle || !navList) return;
      navToggle.setAttribute("aria-expanded", "false");
      navList.classList.remove("is-open");
    }

    if (navToggle && navList) {
      navToggle.addEventListener("click", () => {
        const isOpen = !navList.classList.contains("is-open");
        if (isOpen && window.GridPreview.closeDisplayPanel) window.GridPreview.closeDisplayPanel(false);
        navList.classList.toggle("is-open", isOpen);
        navToggle.setAttribute("aria-expanded", String(isOpen));
        if (!isOpen) closeAllGroups();
      });
    }

    groupToggles.forEach((btn) => {
      const menu = document.getElementById(btn.getAttribute("aria-controls"));
      if (!menu) return;
      btn.addEventListener("click", () => {
        if (window.GridPreview.closeDisplayPanel) window.GridPreview.closeDisplayPanel(false);
        const willOpen = !menu.classList.contains("is-open");
        closeAllGroups(willOpen ? btn : undefined);
        menu.classList.toggle("is-open", willOpen);
        btn.setAttribute("aria-expanded", String(willOpen));
      });
    });

    document.addEventListener("click", (e) => {
      if (navEl && !navEl.contains(e.target)) {
        closeMobileNav();
      }
    });

    document.addEventListener("keydown", (e) => {
      if (e.key !== "Escape") return;
      const openGroupBtn = groupToggles.find((b) => b.getAttribute("aria-expanded") === "true");
      if (openGroupBtn) {
        closeAllGroups();
        openGroupBtn.focus();
      } else if (navToggle && navToggle.getAttribute("aria-expanded") === "true") {
        closeMobileNav();
        navToggle.focus();
      }
    });

    // Fit the header: full menu if it fits on one line, else Display as an
    // icon, else the Menu button (the phone layout). Rechecked on resize,
    // text-size changes and once the web font has loaded.
    const bar = document.querySelector(".site-header__bar");
    function fitNav() {
      if (!bar) return;
      const wasCompact = root.classList.contains("nav-compact");
      root.classList.remove("nav-tight", "nav-compact");
      if (window.innerWidth < 1180) return;
      const overflows = () => bar.scrollWidth > bar.clientWidth + 1;
      if (overflows()) root.classList.add("nav-tight");
      if (overflows()) root.classList.add("nav-compact");
      if (wasCompact !== root.classList.contains("nav-compact")) closeMobileNav();
    }
    window.GridPreview.fitNav = fitNav;
    fitNav();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitNav);
    document.addEventListener("grid-prefs", () => setTimeout(fitNav, 0));

    let navResizeTimer;
    let wasWide = window.innerWidth >= 1180;
    window.addEventListener("resize", () => {
      clearTimeout(navResizeTimer);
      navResizeTimer = setTimeout(() => {
        fitNav();
        const wide = window.innerWidth >= 1180;
        if (wide !== wasWide) closeMobileNav();
        wasWide = wide;
      }, 150);
    });
  }

  /* ---------- Accessible tabs (time ranges) ---------- */
  function initTabs() {
    document.querySelectorAll("[data-tablist]").forEach((tablist) => {
      const tabs = Array.from(tablist.querySelectorAll('[role="tab"]'));
      const panels = tabs.map((tab) =>
        document.getElementById(tab.getAttribute("aria-controls"))
      );

      function select(index) {
        tabs.forEach((tab, i) => {
          const selected = i === index;
          tab.setAttribute("aria-selected", String(selected));
          tab.tabIndex = selected ? 0 : -1;
          if (panels[i]) panels[i].hidden = !selected;
        });
        tabs[index].focus();
        const range = tabs[index].getAttribute("data-range");
        tablist.dispatchEvent(
          new CustomEvent("rangechange", { detail: { range }, bubbles: true })
        );
      }

      tabs.forEach((tab, i) => {
        tab.addEventListener("click", () => {
          tabs.forEach((t, j) => {
            t.setAttribute("aria-selected", String(j === i));
            t.tabIndex = j === i ? 0 : -1;
            if (panels[j]) panels[j].hidden = j !== i;
          });
          const range = tab.getAttribute("data-range");
          tablist.dispatchEvent(
            new CustomEvent("rangechange", { detail: { range }, bubbles: true })
          );
        });
        tab.addEventListener("keydown", (e) => {
          let newIndex = null;
          if (e.key === "ArrowRight") newIndex = (i + 1) % tabs.length;
          if (e.key === "ArrowLeft") newIndex = (i - 1 + tabs.length) % tabs.length;
          if (e.key === "Home") newIndex = 0;
          if (e.key === "End") newIndex = tabs.length - 1;
          if (newIndex !== null) {
            e.preventDefault();
            select(newIndex);
          }
        });
      });

      // Keep the chosen range in the address (?range=week), so a copied
      // link or a reload reopens the same view. Only the first range
      // tablist on a page uses the address.
      if (!tablist.hasAttribute("data-url-range-off") && !document.querySelector("[data-tablist][data-url-range]")) {
        tablist.setAttribute("data-url-range", "");
        tablist.addEventListener("rangechange", (e) => {
          try {
            const u = new URL(window.location.href);
            if (e.detail && e.detail.range && e.detail.range !== tabs[0].getAttribute("data-range")) u.searchParams.set("range", e.detail.range);
            else u.searchParams.delete("range");
            history.replaceState(history.state, "", u.pathname + u.search + u.hash);
          } catch (err) { /* ignore */ }
        });
        let wanted = null;
        try { wanted = new URLSearchParams(window.location.search).get("range"); } catch (err) { /* ignore */ }
        const idx = wanted ? tabs.findIndex((tab) => tab.getAttribute("data-range") === wanted) : -1;
        if (idx > 0) {
          // After the page's own scripts have wired their rangechange
          // listeners (they run after app.js's DOMContentLoaded handler).
          setTimeout(() => {
            tabs[idx].click();
            if (window.location.hash) {
              const target = document.getElementById(window.location.hash.slice(1));
              if (target) target.scrollIntoView();
            }
          }, 0);
        }
      }
    });
  }

  /* ---------- Time ranges ----------
     The site never draws generated or placeholder data: a chart with no
     real figures shows an empty state instead. */
  const RANGE_CONFIG = {
    "3hour": { points: 13, label: "Last 3 hours", stepMs: 15 * 60 * 1000, fmt: "time" },
    day: { points: 48, label: "Past day", stepMs: 30 * 60 * 1000, fmt: "time" },
    week: { points: 56, label: "Past week", stepMs: 3 * 60 * 60 * 1000, fmt: "day" },
    month: { points: 30, label: "Past month", stepMs: 24 * 60 * 60 * 1000, fmt: "date" },
    season: { points: 13, label: "Past season", stepMs: 7 * 24 * 60 * 60 * 1000, fmt: "date" },
    year: { points: 52, label: "Past year", stepMs: 7 * 24 * 60 * 60 * 1000, fmt: "month" },
    "5year": { points: 60, label: "Past 5 years", stepMs: 30 * 24 * 60 * 60 * 1000, fmt: "yearmonth" },
    "10year": { points: 40, label: "Past 10 years", stepMs: 91 * 24 * 60 * 60 * 1000, fmt: "year" },
    all: { points: 15, label: "All time", stepMs: 365 * 24 * 60 * 60 * 1000, fmt: "year" },
  };

  /**
   * The UK meteorological season containing `now` (Met Office definition:
   * Winter = Dec-Feb, Spring = Mar-May, Summer = Jun-Aug, Autumn = Sep-Nov).
   * Winter's label spans both calendar years it covers ("Winter 2025/26"),
   * matching the convention already used for the winter peak-demand record
   * on the homepage. Keep in sync with ukgrid_current_uk_season() in
   * api/_bootstrap.php - the "Season" range tab uses this on the frontend
   * for labels and mock/illustrative data, while the backend uses its own
   * copy to bound the real SQL query, so the two must agree.
   */
  function getCurrentUkSeason(now) {
    return getUkSeasonBounds(0, now);
  }

  /**
   * Generalised version of getCurrentUkSeason(): the UK meteorological
   * season containing `now`, shifted back by `yearOffset` whole years
   * (0 = the current season in progress, -1 = the same season last year,
   * etc). Used by the History page's "compare seasons" checkbox to fetch
   * and label a prior year's equivalent season. For yearOffset === 0 the
   * season is still in progress, so `end` is just `now`; for any other
   * offset the season has already fully elapsed, so `end` is that
   * season's own natural close (the moment before the next season
   * begins). Keep in sync with ukgrid_uk_season_bounds() in
   * api/_bootstrap.php.
   */
  function getUkSeasonBounds(yearOffset, now) {
    yearOffset = yearOffset || 0;
    const d = now ? new Date(now) : new Date();
    const m = d.getMonth() + 1; // 1-12
    const y = d.getFullYear();
    let name, startYear, startMonth;
    if (m === 12 || m <= 2) {
      name = "Winter";
      startYear = m === 12 ? y : y - 1;
      startMonth = 12;
    } else if (m <= 5) {
      name = "Spring"; startYear = y; startMonth = 3;
    } else if (m <= 8) {
      name = "Summer"; startYear = y; startMonth = 6;
    } else {
      name = "Autumn"; startYear = y; startMonth = 9;
    }
    startYear += yearOffset;
    const start = new Date(startYear, startMonth - 1, 1, 0, 0, 0);
    let nextStartMonth = startMonth + 3;
    let nextStartYear = startYear;
    if (nextStartMonth > 12) { nextStartMonth -= 12; nextStartYear += 1; }
    const nextStart = new Date(nextStartYear, nextStartMonth - 1, 1, 0, 0, 0);
    const end = yearOffset === 0 ? d : new Date(Math.min(nextStart.getTime() - 1000, d.getTime()));
    const label = name === "Winter"
      ? `Winter ${startYear}/${String((startYear + 1) % 100).padStart(2, "0")}`
      : `${name} ${startYear}`;
    return { name, label, start: start.getTime(), end: end.getTime() };
  }

  function formatLabel(date, fmt) {
    const d = new Date(date);
    switch (fmt) {
      case "time":
        return d.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
      case "day":
        return d.toLocaleDateString([], { weekday: "short" });
      case "date":
        return d.toLocaleDateString([], { day: "numeric", month: "short" });
      case "month":
        return d.toLocaleDateString([], { month: "short" });
      case "yearmonth":
        return d.toLocaleDateString([], { month: "short", year: "2-digit" });
      case "year":
        return d.getFullYear().toString();
      default:
        return d.toLocaleDateString();
    }
  }

  /* Human-readable calendar period covered by a range, e.g. "3 – 9 Aug 2026".
     Used anywhere the site needs to show *which* dates a tab's chart covers,
     not just the abstract range name. */
  function getRangePeriodLabel(range) {
    const cfg = RANGE_CONFIG[range];
    if (!cfg) return "";
    const end = new Date();
    const dOpts = { day: "numeric", month: "short", year: "numeric" };

    if (range === "season") {
      const season = getCurrentUkSeason(end.getTime());
      const start = new Date(season.start);
      const sameYear = start.getFullYear() === end.getFullYear();
      const startStr = start.toLocaleDateString([], sameYear ? { day: "numeric", month: "short" } : dOpts);
      const endStr = end.toLocaleDateString([], dOpts);
      return `${season.label}: ${startStr} – ${endStr}`;
    }

    const start = new Date(end.getTime() - (cfg.points - 1) * cfg.stepMs);

    if (range === "day") {
      const tOpts = { hour: "numeric", minute: "2-digit" };
      const shortDate = { day: "numeric", month: "short" };
      if (start.toDateString() === end.toDateString()) {
        return `${start.toLocaleDateString([], dOpts)}, ${start.toLocaleTimeString([], tOpts)} – ${end.toLocaleTimeString([], tOpts)}`;
      }
      return `${start.toLocaleDateString([], shortDate)}, ${start.toLocaleTimeString([], tOpts)} – ${end.toLocaleDateString([], dOpts)}, ${end.toLocaleTimeString([], tOpts)}`;
    }
    if (start.toDateString() === end.toDateString()) {
      return start.toLocaleDateString([], dOpts);
    }
    const sameYear = start.getFullYear() === end.getFullYear();
    const startStr = start.toLocaleDateString([], sameYear ? { day: "numeric", month: "short" } : dOpts);
    const endStr = end.toLocaleDateString([], dOpts);
    return `${startStr} – ${endStr}`;
  }

  window.GridPreview = {
    RANGE_CONFIG,
    getRangePeriodLabel,
    formatLabel,
    getCurrentUkSeason,
    getUkSeasonBounds,
  };

  function themeAwareColors() {
    const dark =
      root.getAttribute("data-theme") === "dark" ||
      (!root.getAttribute("data-theme") &&
        window.matchMedia("(prefers-color-scheme: dark)").matches);
    return {
      dark: dark,
      grid: dark ? "rgba(255,255,255,0.08)" : "rgba(20,24,33,0.08)",
      guide: dark ? "rgba(255,255,255,0.4)" : "rgba(20,24,33,0.4)",
      text: dark ? "#a2a8b6" : "#5b6270",
      // Actual --bg-elevated / --border custom-property values, read live so
      // the canvas-drawn hover tooltip always matches the current theme
      // exactly rather than approximating it with a second hardcoded palette.
      panelBg: getComputedStyle(root).getPropertyValue("--bg-elevated").trim() || (dark ? "#1c1f27" : "#ffffff"),
      panelBorder: getComputedStyle(root).getPropertyValue("--border").trim() || (dark ? "#333846" : "#dde1e7"),
    };
  }
  window.GridPreview.themeAwareColors = themeAwareColors;

  /* ==========================================================================
     Chart rendering - plain Canvas 2D, no external library.
     Self-contained on purpose: charts must show example data even with no
     network access (blocked CDN, offline preview, strict corporate proxy).
     ========================================================================== */

  const chartRegistry = [];

  function registerChart(canvas, draw) {
    draw();
    // Every render*Chart function funnels through here, so this is the one
    // place that reliably clears the "is-loading" shimmer skeleton
    // (assets/style.css's .chart-wrap.is-loading::before) once a chart has
    // actually drawn something. Individual pages' success paths only ever
    // called the equivalent of this by hand in some places (e.g. the
    // 24-hour mix-stack charts) and not others (every History-section
    // line chart drawn via drawRange()) - the omission meant those charts
    // rendered real data underneath a shimmer overlay that never went
    // away, i.e. they looked permanently stuck loading even though the
    // data was right there. showChartError() already did this on the
    // failure path; this mirrors it for every success path in one spot
    // instead of requiring every call site to remember it individually.
    const wrap = canvas.closest(".chart-wrap");
    if (wrap) {
      wrap.classList.remove("is-loading");
      // Also clear a previous "Figures appear here when the next reading arrives." state. showChartError()
      // hides the canvas (.has-error canvas { visibility: hidden }) and
      // nothing ever un-hid it, so a chart that had once shown "no data"
      // stayed invisible even after real data arrived and was drawn -
      // leaving e.g. a populated mix table beside a blank donut.
      if (wrap.classList.contains("has-error")) {
        wrap.classList.remove("has-error");
        const msg = wrap.querySelector(".chart-error-msg");
        if (msg) msg.remove();
      }
    }
    // One registry entry per canvas: re-rendering the same chart (a live
    // update, a periodic refresh) replaces its draw() rather than stacking
    // another one, which used to replay every stale earlier render on each
    // resize/theme change before the current one.
    const existing = chartRegistry.findIndex((entry) => entry.canvas === canvas);
    if (existing !== -1) chartRegistry[existing].draw = draw;
    else chartRegistry.push({ canvas, draw });
  }

  function redrawAll() {
    chartRegistry.forEach((entry) => {
      if (entry.canvas.isConnected && entry.canvas.offsetParent !== null) {
        entry.draw();
      }
    });
  }
  window.GridPreview = window.GridPreview || {};
  window.GridPreview.redrawAll = redrawAll;
  window.GridPreview.registerChart = registerChart;
  window.GridPreview.prepareCanvas = prepareCanvas;

  let resizeTimer;
  window.addEventListener("resize", () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(redrawAll, 120);
  });

  function prepareCanvas(canvas) {
    const dpr = window.devicePixelRatio || 1;
    const cssW = canvas.clientWidth || canvas.parentElement.clientWidth || 300;
    const cssH = canvas.clientHeight || canvas.parentElement.clientHeight || 200;
    canvas.width = Math.max(1, Math.round(cssW * dpr));
    canvas.height = Math.max(1, Math.round(cssH * dpr));
    const ctx = canvas.getContext("2d");
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cssW, cssH);
    ctx.font = '12px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
    return { ctx, w: cssW, h: cssH };
  }

  function hexToRgba(hex, alpha) {
    if (!hex || hex[0] !== "#") return hex;
    let h = hex.slice(1);
    if (h.length === 3) h = h.split("").map((c) => c + c).join("");
    const r = parseInt(h.slice(0, 2), 16);
    const g = parseInt(h.slice(2, 4), 16);
    const b = parseInt(h.slice(4, 6), 16);
    return `rgba(${r},${g},${b},${alpha})`;
  }

  function formatAxisValue(v, unit) {
    const rounded = Math.abs(v) >= 100 ? Math.round(v) : Math.round(v * 10) / 10;
    return withUnit(rounded, unit);
  }
  // "£/MWh" and "£" read as "£105/MWh" and "£690", not "105£/MWh"
  function withUnit(num, unit) {
    unit = unit || "";
    if (unit.charAt(0) === "£") return (num < 0 ? "-£" + String(num).slice(1) : "£" + num) + unit.slice(1);
    return `${num}${unit}`;
  }

  // Full precision date + time for hover tooltips - deliberately independent
  // of a chart's axis-label format (RANGE_CONFIG's "fmt"), which is often
  // coarser than a single data point (e.g. a "year" range axis label is just
  // a month). The tooltip should always be able to answer "exactly when was
  // this point recorded?" regardless of range.
  function formatTooltipDateTime(t) {
    const d = new Date(t);
    if (isNaN(d.getTime())) return "";
    const datePart = d.toLocaleDateString([], { weekday: "short", day: "numeric", month: "short", year: "numeric" });
    const timePart = d.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
    return `${datePart}, ${timePart}`;
  }

  function roundRectPath(ctx, x, y, w, h, r) {
    const rad = Math.max(0, Math.min(r, Math.abs(w) / 2, Math.abs(h) / 2));
    const x0 = w < 0 ? x + w : x;
    const w0 = Math.abs(w);
    ctx.beginPath();
    ctx.moveTo(x0 + rad, y);
    ctx.arcTo(x0 + w0, y, x0 + w0, y + h, rad);
    ctx.arcTo(x0 + w0, y + h, x0, y + h, rad);
    ctx.arcTo(x0, y + h, x0, y, rad);
    ctx.arcTo(x0, y, x0 + w0, y, rad);
    ctx.closePath();
  }

  function pickTickIndices(n, count) {
    const c = Math.max(2, Math.min(count, n));
    const out = [];
    for (let i = 0; i < c; i++) out.push(Math.round((i * (n - 1)) / (c - 1)));
    return Array.from(new Set(out));
  }

  /** Escapes text for safe use inside innerHTML - labels can come straight
      from an upstream feed's own codes when this site has no display label
      for them (a new ENTSO-E psrType, EIA fuel code, etc). */
  function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  }
  window.GridPreview.escapeHtml = escapeHtml;

  // fmt (optional): { decimals, pctDecimals } - when given, legend values
  // use exactly that many decimal places and show each entry's share of
  // the (positive) total too, so a donut's legend reads the same numbers
  // as the mix table beside it (see renderMixBreakdown below). Without it,
  // the original axis-style rounding is kept for every other caller.
  function buildLegend(target, labels, colors, values, unit, fmt) {
    const el = typeof target === "string" ? document.getElementById(target) : target;
    if (!el) return;
    const total = values ? values.reduce((a, b) => a + Math.max(Number(b) || 0, 0), 0) : 0;
    el.innerHTML = labels
      .map((l, i) => {
        let v = "";
        if (values) {
          if (fmt && fmt.decimals != null) {
            const share = total > 0 ? (Math.max(values[i], 0) / total * 100).toFixed(fmt.pctDecimals != null ? fmt.pctDecimals : 1) : "0.0";
            v = ` <strong>${Number(values[i]).toFixed(fmt.decimals)}${unit || ""}</strong> <span class="chart-legend__pct">(${share}%)</span>`;
          } else {
            v = ` <strong>${formatAxisValue(values[i], unit)}</strong>`;
          }
        }
        return `<li><span class="swatch" style="background:${colors[i]}"></span>${escapeHtml(l)}${v}</li>`;
      })
      .join("");
  }
  window.GridPreview.buildLegend = buildLegend;

  /* ---------- Line / area chart ---------- */
  /* Signed series (flows that reverse direction, prices that go
     negative): pass opts.signed to renderLineChart, either one of these
     preset names or an object of the same shape. The area above the zero
     line is filled amber and below it blue (the same pair the
     interconnectors page uses - checked for colour-blind separation and
     3:1 contrast in both themes), the zero line is always drawn, and the
     y-axis ticks are round numbers that include 0. includeZero: false
     (prices) keeps the normal tight scale unless the data actually dips
     below zero. Any other line chart whose data crosses zero still gets a
     zero line. */
  const SIGNED_PRESETS = {
    flow: { pos: "Importing", neg: "Exporting" },
    storage: { pos: "Generating", neg: "Pumping / charging" },
    battery: { pos: "Discharging", neg: "Charging" },
    price: { pos: "Positive price", neg: "Negative price", includeZero: false },
  };
  function signedColors() {
    return themeAwareColors().dark ? { pos: "#c4831f", neg: "#4f8fd6" } : { pos: "#c47f00", neg: "#2b6cb0" };
  }
  function resolveSigned(s) {
    if (!s) return null;
    return typeof s === "string" ? SIGNED_PRESETS[s] || null : s;
  }
  window.GridPreview.SIGNED_PRESETS = SIGNED_PRESETS;
  function niceStepFor(raw) {
    const p = Math.pow(10, Math.floor(Math.log10(raw)));
    const f = raw / p;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p;
  }
  /** Small "above the line / below the line" key, inserted once just
      above a signed chart's .chart-wrap so the two colours are never the
      only cue. */
  function ensureSignedLegend(canvas, signed) {
    const wrap = canvas.closest(".chart-wrap") || canvas.parentElement;
    if (!wrap || !wrap.parentElement) return;
    let el = canvas._signedLegend;
    if (!el) {
      el = document.createElement("div");
      el.className = "signed-legend";
      wrap.insertAdjacentElement("beforebegin", el);
      canvas._signedLegend = el;
    }
    el.innerHTML = `<span><i class="signed-legend__pos"></i>Above the line: ${signed.pos}</span><span><i class="signed-legend__neg"></i>Below the line: ${signed.neg}</span>`;
  }

  function renderLineChart(canvas, series, opts) {
    opts = opts || {};
    const color = opts.color || "#0a6e5c";
    const signed = resolveSigned(opts.signed);
    let layout = null; // latest draw()'s geometry, read by the hover handler below
    if (signed && (signed.includeZero !== false || Math.min(...series.values) < 0)) ensureSignedLegend(canvas, signed);

    function draw() {
      const { ctx, w, h } = prepareCanvas(canvas);
      const colors = themeAwareColors();
      const values = series.values;
      const n = values.length;
      const pad = { l: 42, r: 12, t: 12, b: 22 };

      // Optional second series (e.g. the History page's "compare seasons"
      // checkbox), drawn as a dashed overlay line aligned by index rather
      // than by calendar date - point i of the compare series lines up
      // with point i of the primary series ("the same week of the season",
      // not "the same calendar day"), which is what makes a year-on-year
      // comparison actually readable. Truncated to the primary series'
      // length so an in-progress current season compares against the same
      // elapsed portion of a prior, already-complete one rather than
      // stretching the whole prior season across the same width.
      const compare = opts.compareSeries;
      const compareValues = compare && compare.values && compare.values.length
        ? compare.values.slice(0, n)
        : null;

      let min = Math.min(...values, ...(compareValues || []));
      let max = Math.max(...values, ...(compareValues || []));
      const rawMin = min;
      const signedOn = !!signed && (signed.includeZero !== false || rawMin < 0);
      let tickValues = null;
      if (signedOn) {
        min = Math.min(0, min); max = Math.max(0, max);
        if (min === max) { max += 1; }
        const span0 = max - min;
        if (max > 0) max += span0 * 0.08;
        if (min < 0) min -= span0 * 0.08;
        const step = niceStepFor((max - min) / 4);
        tickValues = [];
        for (let v = Math.ceil(min / step - 1e-9) * step; v <= max + 1e-9; v += step) tickValues.push(Math.abs(v) < 1e-9 ? 0 : v);
      } else {
        if (min === max) { min -= 1; max += 1; }
        const span = max - min;
        min -= span * 0.1;
        max += span * 0.1;
      }
      const sc = signedColors();
      // Widen the left margin to fit the longest y-axis label (long labels
      // such as "€120/MWh" were cut off at the canvas edge).
      {
        const yt = tickValues || Array.from({ length: 5 }, (_, k) => min + ((max - min) * k) / 4);
        pad.l = Math.max(pad.l, 14 + Math.max(...yt.map((v) => ctx.measureText(formatAxisValue(v, opts.unit)).width)));
      }
      const plotW = w - pad.l - pad.r;
      const plotH = h - pad.t - pad.b;
      const xAt = (i) => pad.l + (n > 1 ? plotW * (i / (n - 1)) : plotW / 2);
      const yAt = (v) => pad.t + plotH - plotH * ((v - min) / (max - min));

      // gridlines + y labels
      ctx.strokeStyle = colors.grid;
      ctx.fillStyle = colors.text;
      ctx.lineWidth = 1;
      const yTicks = 4;
      const ticks = tickValues || Array.from({ length: yTicks + 1 }, (_, i) => min + ((max - min) * i) / yTicks);
      for (let i = 0; i < ticks.length; i++) {
        const v = ticks[i];
        const y = yAt(v);
        ctx.beginPath();
        ctx.moveTo(pad.l, Math.round(y) + 0.5);
        ctx.lineTo(w - pad.r, Math.round(y) + 0.5);
        ctx.stroke();
        ctx.textAlign = "right";
        ctx.textBaseline = "middle";
        ctx.fillText(formatAxisValue(v, opts.unit), pad.l - 8, y);
      }

      // x labels
      ctx.textAlign = "center";
      ctx.textBaseline = "top";
      pickTickIndices(n, 5).forEach((idx) => {
        ctx.fillText(series.labels[idx], xAt(idx), h - pad.b + 5);
      });

      const tracePath = () => {
        ctx.beginPath();
        values.forEach((v, i) => {
          const x = xAt(i), y = yAt(v);
          if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        });
      };
      const y0 = yAt(0);
      if (signedOn) {
        // Two-colour fill and line, split at the zero line.
        [[pad.t, y0 - pad.t, sc.pos], [y0, pad.t + plotH - y0, sc.neg]].forEach(([cy, ch, c]) => {
          if (ch <= 0) return;
          ctx.save();
          ctx.beginPath();
          ctx.rect(pad.l - 2, cy, plotW + 4, ch);
          ctx.clip();
          tracePath();
          ctx.lineTo(xAt(n - 1), y0);
          ctx.lineTo(xAt(0), y0);
          ctx.closePath();
          ctx.fillStyle = hexToRgba(c, 0.3);
          ctx.fill();
          tracePath();
          ctx.strokeStyle = c;
          ctx.lineWidth = 2;
          ctx.lineJoin = "round";
          ctx.lineCap = "round";
          ctx.stroke();
          ctx.restore();
        });
      } else {
        // area fill
        const grad = ctx.createLinearGradient(0, pad.t, 0, h - pad.b);
        grad.addColorStop(0, hexToRgba(color, 0.28));
        grad.addColorStop(1, hexToRgba(color, 0.02));
        tracePath();
        ctx.lineTo(xAt(n - 1), pad.t + plotH);
        ctx.lineTo(xAt(0), pad.t + plotH);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();

        // line
        tracePath();
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        ctx.lineJoin = "round";
        ctx.lineCap = "round";
        ctx.stroke();
      }

      // Zero line - always on a signed chart, and on any other chart
      // whose data crosses zero.
      if (signedOn || (min < 0 && max > 0)) {
        ctx.save();
        ctx.strokeStyle = colors.dark ? "#edeef2" : "#1a1d23";
        ctx.globalAlpha = 0.75;
        ctx.lineWidth = 1.4;
        ctx.beginPath();
        ctx.moveTo(pad.l, Math.round(y0) + 0.5);
        ctx.lineTo(w - pad.r, Math.round(y0) + 0.5);
        ctx.stroke();
        ctx.restore();
      }

      const pointColor = (i) => (signedOn ? (values[i] >= 0 ? sc.pos : sc.neg) : color);
      // last-point dot
      ctx.beginPath();
      ctx.arc(xAt(n - 1), yAt(values[n - 1]), 3.2, 0, Math.PI * 2);
      ctx.fillStyle = pointColor(n - 1);
      ctx.fill();

      // Compare-season overlay line: dashed, muted, no fill, drawn on top
      // of the primary line/area so both are readable at once.
      if (compareValues && compareValues.length) {
        ctx.save();
        ctx.setLineDash([5, 4]);
        ctx.beginPath();
        compareValues.forEach((v, i) => {
          const x = xAt(i), y = yAt(v);
          if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        });
        ctx.strokeStyle = hexToRgba(color, 0.55);
        ctx.lineWidth = 2;
        ctx.lineJoin = "round";
        ctx.lineCap = "round";
        ctx.stroke();
        ctx.restore();
      }

      // Geometry snapshot for the hover handler - re-captured on every
      // draw() (including the redraws triggered by resize/theme-change via
      // redrawAll()), so hover hit-testing is always in sync with whatever
      // is actually on screen right now.
      layout = { ctx, w, h, pad, plotW, plotH, n, values, xAt, yAt, colors, compareValues, pointColor, signed: signedOn ? signed : null };
    }

    registerChart(canvas, draw);
    bindLineChartHover(canvas, () => layout, series, opts, color, draw);

    if (!opts.noExport) {
      const columns = [{ name: opts.label || "Value", unit: series.unit || opts.unit, color: opts.color, values: series.values }];
      if (opts.compareSeries && opts.compareSeries.values && opts.compareSeries.values.length) {
        columns.push({
          name: opts.compareLabel || "Comparison",
          unit: series.unit || opts.unit,
          color: color, // same hue as the primary line (drawn as a lighter dashed overlay on-canvas) - reused here just to distinguish the two legend entries, not to imply a second true colour
          values: opts.compareSeries.values.slice(0, series.values.length),
        });
      }
      attachChartExport(canvas, {
        title: opts.exportTitle,
        kind: "series",
        data: { times: series.times, labels: series.labels, columns },
        signed: signed && (signed.includeZero !== false || Math.min(...series.values) < 0) ? signed : null,
      });
    }
  }
  window.GridPreview.renderLineChart = renderLineChart;

  /* Hover interaction for renderLineChart: tracks the pointer, finds the
     nearest data point, and redraws the base chart plus a dashed guide line
     from that point straight down to the x-axis, a marker dot, and a small
     tooltip with the exact date/time and value.

     The pointer listeners are attached once per canvas (guarded via a
     dataset flag), but the state they read - getLayout/series/opts/color/
     draw - is refreshed on EVERY call via canvas._lineHoverState instead of
     being captured once in the closure and then ignored on later calls.
     That distinction matters once a canvas can be re-rendered with new
     data after its first render (e.g. the History page's "compare
     seasons" checkbox calling renderLineChart a second time on the same
     canvas with a compareSeries added) - without it, hover would keep
     showing whichever series/opts happened to be bound first, regardless
     of what is actually on screen. */
  function bindLineChartHover(canvas, getLayout, series, opts, color, draw) {
    canvas._lineHoverState = { getLayout, series, opts, color, draw };
    if (canvas.dataset.hoverBound) return;
    canvas.dataset.hoverBound = "1";
    canvas.style.cursor = "crosshair";

    let hoverIndex = null;
    let rafId = null;

    function nearestIndex(mouseX) {
      const layout = canvas._lineHoverState.getLayout();
      if (!layout || layout.n === 0) return null;
      if (layout.n === 1) return 0;
      const ratio = (mouseX - layout.pad.l) / layout.plotW;
      const idx = Math.round(ratio * (layout.n - 1));
      return Math.max(0, Math.min(layout.n - 1, idx));
    }

    function drawOverlay(idx) {
      const { series, opts, color } = canvas._lineHoverState;
      const layout = canvas._lineHoverState.getLayout();
      if (!layout || idx == null) return;
      const { ctx, w, h, pad, xAt, yAt, values, colors, compareValues } = layout;
      const markColor = layout.pointColor ? layout.pointColor(idx) : color;
      const x = xAt(idx);
      const y = yAt(values[idx]);

      // Guide line from the point down to the bottom axis.
      ctx.save();
      ctx.setLineDash([4, 3]);
      ctx.strokeStyle = colors.guide;
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(Math.round(x) + 0.5, y);
      ctx.lineTo(Math.round(x) + 0.5, h - pad.b);
      ctx.stroke();
      ctx.restore();

      // Point marker.
      ctx.beginPath();
      ctx.arc(x, y, 4, 0, Math.PI * 2);
      ctx.fillStyle = markColor;
      ctx.fill();
      ctx.lineWidth = 1.5;
      ctx.strokeStyle = colors.panelBg;
      ctx.stroke();

      // Compare-series marker, same x position, its own value's y.
      const hasCompare = compareValues && compareValues[idx] != null;
      if (hasCompare) {
        const cy = yAt(compareValues[idx]);
        ctx.beginPath();
        ctx.arc(x, cy, 3.5, 0, Math.PI * 2);
        ctx.fillStyle = hexToRgba(color, 0.55);
        ctx.fill();
        ctx.lineWidth = 1.5;
        ctx.strokeStyle = colors.panelBg;
        ctx.stroke();
      }

      // Tooltip: metric value on top, compare value (if active) below
      // that, exact recorded date/time last.
      const valueLine = layout.signed
        ? `${values[idx] >= 0 ? layout.signed.pos : layout.signed.neg}: ${formatAxisValue(values[idx], series.unit || opts.unit)}`
        : opts.label
          ? `${opts.label}: ${formatAxisValue(values[idx], series.unit || opts.unit)}`
          : formatAxisValue(values[idx], series.unit || opts.unit);
      const compareLine = hasCompare
        ? `${opts.compareLabel || "Comparison"}: ${formatAxisValue(compareValues[idx], series.unit || opts.unit)}`
        : null;
      const timeLine = (series.times && series.times[idx])
        ? formatTooltipDateTime(series.times[idx])
        : (series.labels[idx] || "");

      ctx.font = '700 12px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
      let maxW = ctx.measureText(valueLine).width;
      ctx.font = '11px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
      maxW = Math.max(maxW, ctx.measureText(timeLine).width);
      if (compareLine) maxW = Math.max(maxW, ctx.measureText(compareLine).width);
      const boxW = maxW + 20;
      const lineH = 16;
      const boxH = compareLine ? 40 + lineH : 40;

      let boxX = x + 10;
      if (boxX + boxW > w - pad.r) boxX = x - boxW - 10;
      boxX = Math.max(pad.l, Math.min(boxX, w - pad.r - boxW));
      let boxY = y - boxH - 10;
      if (boxY < pad.t) boxY = Math.min(y + 10, h - pad.b - boxH);

      ctx.save();
      roundRectPath(ctx, boxX, boxY, boxW, boxH, 6);
      ctx.fillStyle = colors.panelBg;
      ctx.fill();
      ctx.strokeStyle = colors.panelBorder;
      ctx.lineWidth = 1;
      ctx.stroke();
      ctx.restore();

      ctx.textAlign = "left";
      ctx.textBaseline = "top";
      ctx.fillStyle = color;
      ctx.font = '700 12px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
      ctx.fillText(valueLine, boxX + 10, boxY + 7);

      let nextY = boxY + 23;
      if (compareLine) {
        ctx.fillStyle = colors.text;
        ctx.font = '700 11px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
        ctx.fillText(compareLine, boxX + 10, nextY);
        nextY += lineH;
      }
      ctx.fillStyle = colors.text;
      ctx.font = '11px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
      ctx.fillText(timeLine, boxX + 10, nextY);
    }

    function scheduleOverlay() {
      if (rafId) return;
      rafId = requestAnimationFrame(() => {
        rafId = null;
        canvas._lineHoverState.draw();
        drawOverlay(hoverIndex);
      });
    }

    function handleMove(clientX, clientY) {
      const rect = canvas.getBoundingClientRect();
      // Ignore pointer positions outside the canvas's own box (can happen
      // with pointer capture during a touch drag).
      if (clientX < rect.left || clientX > rect.right || clientY < rect.top || clientY > rect.bottom) {
        handleLeave();
        return;
      }
      const idx = nearestIndex(clientX - rect.left);
      if (idx === null || idx === hoverIndex) return;
      hoverIndex = idx;
      scheduleOverlay();
    }

    function handleLeave() {
      if (hoverIndex === null) return;
      hoverIndex = null;
      if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
      canvas._lineHoverState.draw();
    }

    canvas.addEventListener("pointermove", (e) => handleMove(e.clientX, e.clientY));
    canvas.addEventListener("pointerleave", handleLeave);
    canvas.addEventListener("pointercancel", handleLeave);
  }

  /* ---------- Stacked area chart (generation mix over time) ----------
     series: [{ name, color, values }], all sharing the same times/labels.
     Renders each series as a band stacked on top of the previous one
     (bottom-up) rather than overlapping lines, so the filled area at any
     x-position always sums to the period's total. Hover shows every
     series' individual value plus the total, not just one number. */
  function renderStackedAreaChart(canvas, times, labels, series, opts) {
    opts = opts || {};
    let layout = null;

    function draw() {
      const { ctx, w, h } = prepareCanvas(canvas);
      const colors = themeAwareColors();
      const n = labels.length;
      const pad = { l: 42, r: 12, t: 12, b: 22 };

      // Running cumulative totals per series, per point - cum[s][i] is the
      // top edge of series s's band at point i (series 0's band sits on
      // the zero baseline; each later series stacks on top of the last).
      const cum = [];
      let runningMax = 0;
      for (let s = 0; s < series.length; s++) {
        cum.push(new Array(n));
        for (let i = 0; i < n; i++) {
          const prev = s === 0 ? 0 : cum[s - 1][i];
          const v = series[s].values[i] || 0;
          cum[s][i] = prev + v;
          if (cum[s][i] > runningMax) runningMax = cum[s][i];
        }
      }
      let max = runningMax > 0 ? runningMax * 1.1 : 1;
      const min = 0;
      {
        const yt = Array.from({ length: 5 }, (_, k) => min + ((max - min) * k) / 4);
        pad.l = Math.max(pad.l, 14 + Math.max(...yt.map((v) => ctx.measureText(formatAxisValue(v, opts.unit)).width)));
      }
      const plotW = w - pad.l - pad.r;
      const plotH = h - pad.t - pad.b;
      const xAt = (i) => pad.l + (n > 1 ? plotW * (i / (n - 1)) : plotW / 2);
      const yAt = (v) => pad.t + plotH - plotH * ((v - min) / (max - min));

      // gridlines + y labels
      ctx.strokeStyle = colors.grid;
      ctx.fillStyle = colors.text;
      ctx.lineWidth = 1;
      const yTicks = 4;
      for (let i = 0; i <= yTicks; i++) {
        const v = min + ((max - min) * i) / yTicks;
        const y = yAt(v);
        ctx.beginPath();
        ctx.moveTo(pad.l, Math.round(y) + 0.5);
        ctx.lineTo(w - pad.r, Math.round(y) + 0.5);
        ctx.stroke();
        ctx.textAlign = "right";
        ctx.textBaseline = "middle";
        ctx.fillText(formatAxisValue(v, opts.unit), pad.l - 8, y);
      }

      // x labels
      ctx.textAlign = "center";
      ctx.textBaseline = "top";
      pickTickIndices(n, 5).forEach((idx) => {
        ctx.fillText(labels[idx], xAt(idx), h - pad.b + 5);
      });

      // stacked bands, bottom-up - each band fades top-to-bottom the same
      // way renderLineChart's single-series area does (hexToRgba(color,
      // 0.28) → hexToRgba(color, 0.02)), but anchored to that band's OWN
      // vertical extent rather than the whole chart height. Anchoring every
      // band to the full plot height would leave the bottom band (which
      // never reaches near the top of the chart) looking permanently faint
      // regardless of its actual values - each type gets its own equivalent
      // fade, from its own top edge down to its own bottom edge.
      for (let s = 0; s < series.length; s++) {
        const baseline = s === 0 ? null : cum[s - 1];
        const topYs = cum[s].map((v) => yAt(v));
        const bottomYs = baseline ? baseline.map((v) => yAt(v)) : cum[s].map(() => yAt(0));
        const bandTopY = Math.min(...topYs);
        const bandBottomY = Math.max(...bottomYs);

        ctx.beginPath();
        for (let i = 0; i < n; i++) {
          const x = xAt(i), y = topYs[i];
          if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        }
        for (let i = n - 1; i >= 0; i--) {
          ctx.lineTo(xAt(i), bottomYs[i]);
        }
        ctx.closePath();
        const grad = bandBottomY > bandTopY
          ? ctx.createLinearGradient(0, bandTopY, 0, bandBottomY)
          : ctx.createLinearGradient(0, bandTopY - 1, 0, bandTopY + 1);
        grad.addColorStop(0, hexToRgba(series[s].color, 0.6));
        grad.addColorStop(1, hexToRgba(series[s].color, 0.18));
        ctx.fillStyle = grad;
        ctx.fill();

        ctx.beginPath();
        for (let i = 0; i < n; i++) {
          const x = xAt(i), y = yAt(cum[s][i]);
          if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        }
        ctx.strokeStyle = series[s].color;
        ctx.lineWidth = 1.25;
        ctx.lineJoin = "round";
        ctx.stroke();
      }

      layout = { ctx, w, h, pad, plotW, plotH, n, cum, xAt, yAt, colors };
    }

    registerChart(canvas, draw);
    bindStackedAreaHover(canvas, () => layout, times, labels, series, opts, draw);
    if (opts.legendTarget) buildLegend(opts.legendTarget, series.map((s) => s.name), series.map((s) => s.color));

    if (!opts.noExport) {
      attachChartExport(canvas, {
        title: opts.exportTitle,
        kind: "series",
        data: {
          times,
          labels,
          columns: series.map((s) => ({ name: s.name, unit: opts.unit, color: s.color, values: s.values })),
        },
      });
    }
  }
  window.GridPreview.renderStackedAreaChart = renderStackedAreaChart;

  /* Hover interaction for renderStackedAreaChart - same pointer-tracking
     approach as bindLineChartHover, but the tooltip lists every series'
     individual value at that point plus the running total, since a stacked
     chart's visual height at any x is a sum, not a single number. */
  function bindStackedAreaHover(canvas, getLayout, times, labels, series, opts, draw) {
    if (canvas.dataset.hoverBound) return;
    canvas.dataset.hoverBound = "1";
    canvas.style.cursor = "crosshair";

    let hoverIndex = null;
    let rafId = null;

    function nearestIndex(mouseX) {
      const layout = getLayout();
      if (!layout || layout.n === 0) return null;
      if (layout.n === 1) return 0;
      const ratio = (mouseX - layout.pad.l) / layout.plotW;
      const idx = Math.round(ratio * (layout.n - 1));
      return Math.max(0, Math.min(layout.n - 1, idx));
    }

    function drawOverlay(idx) {
      const layout = getLayout();
      if (!layout || idx == null) return;
      const { ctx, w, h, pad, xAt, yAt, cum, colors } = layout;
      const x = xAt(idx);
      const topY = yAt(cum[cum.length - 1][idx]);

      ctx.save();
      ctx.setLineDash([4, 3]);
      ctx.strokeStyle = colors.guide;
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(Math.round(x) + 0.5, topY);
      ctx.lineTo(Math.round(x) + 0.5, h - pad.b);
      ctx.stroke();
      ctx.restore();

      for (let s = 0; s < series.length; s++) {
        const y = yAt(cum[s][idx]);
        ctx.beginPath();
        ctx.arc(x, y, 3, 0, Math.PI * 2);
        ctx.fillStyle = series[s].color;
        ctx.fill();
      }

      const timeLine = (times && times[idx]) ? formatTooltipDateTime(times[idx]) : (labels[idx] || "");
      const rows = series.map((s) => ({ text: `${s.name}: ${formatAxisValue(s.values[idx], opts.unit)}`, color: s.color }));
      rows.push({ text: `Total: ${formatAxisValue(cum[cum.length - 1][idx], opts.unit)}`, color: colors.text });

      ctx.font = '700 11px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
      let maxTextW = ctx.measureText(timeLine).width;
      ctx.font = '11px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
      rows.forEach((r) => { maxTextW = Math.max(maxTextW, ctx.measureText(r.text).width + 14); });
      const boxW = maxTextW + 20;
      const lineH = 16;
      const boxH = 14 + rows.length * lineH + 6;

      let boxX = x + 10;
      if (boxX + boxW > w - pad.r) boxX = x - boxW - 10;
      boxX = Math.max(pad.l, Math.min(boxX, w - pad.r - boxW));
      const boxY = Math.max(pad.t, Math.min(pad.t, h - pad.b - boxH));

      ctx.save();
      roundRectPath(ctx, boxX, boxY, boxW, boxH, 6);
      ctx.fillStyle = colors.panelBg;
      ctx.fill();
      ctx.strokeStyle = colors.panelBorder;
      ctx.lineWidth = 1;
      ctx.stroke();
      ctx.restore();

      ctx.textAlign = "left";
      ctx.textBaseline = "top";
      ctx.fillStyle = colors.text;
      ctx.font = '700 11px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
      ctx.fillText(timeLine, boxX + 10, boxY + 7);

      ctx.font = '11px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
      rows.forEach((r, i) => {
        const rowY = boxY + 7 + 16 + i * lineH;
        ctx.fillStyle = r.color;
        ctx.fillText("●", boxX + 10, rowY);
        ctx.fillStyle = colors.text;
        ctx.fillText(r.text, boxX + 22, rowY);
      });
    }

    function scheduleOverlay() {
      if (rafId) return;
      rafId = requestAnimationFrame(() => {
        rafId = null;
        draw();
        drawOverlay(hoverIndex);
      });
    }

    function handleMove(clientX, clientY) {
      const rect = canvas.getBoundingClientRect();
      if (clientX < rect.left || clientX > rect.right || clientY < rect.top || clientY > rect.bottom) {
        handleLeave();
        return;
      }
      const idx = nearestIndex(clientX - rect.left);
      if (idx === null || idx === hoverIndex) return;
      hoverIndex = idx;
      scheduleOverlay();
    }

    function handleLeave() {
      if (hoverIndex === null) return;
      hoverIndex = null;
      if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
      draw();
    }

    canvas.addEventListener("pointermove", (e) => handleMove(e.clientX, e.clientY));
    canvas.addEventListener("pointerleave", handleLeave);
    canvas.addEventListener("pointercancel", handleLeave);
  }

  /* ---------- Sparkline (mini trend, no axes) ---------- */
  function renderSparkline(canvas, values, color) {
    function draw() {
      const { ctx, w, h } = prepareCanvas(canvas);
      const n = values.length;
      let min = Math.min(...values);
      let max = Math.max(...values);
      if (min === max) { min -= 1; max += 1; }
      const xAt = (i) => (n > 1 ? w * (i / (n - 1)) : w / 2);
      const yAt = (v) => h - h * ((v - min) / (max - min));

      const grad = ctx.createLinearGradient(0, 0, 0, h);
      grad.addColorStop(0, hexToRgba(color, 0.35));
      grad.addColorStop(1, hexToRgba(color, 0.02));
      ctx.beginPath();
      values.forEach((v, i) => {
        const x = xAt(i), y = yAt(v);
        if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      });
      ctx.lineTo(xAt(n - 1), h);
      ctx.lineTo(xAt(0), h);
      ctx.closePath();
      ctx.fillStyle = grad;
      ctx.fill();

      ctx.beginPath();
      values.forEach((v, i) => {
        const x = xAt(i), y = yAt(v);
        if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      });
      ctx.strokeStyle = color;
      ctx.lineWidth = 1.75;
      ctx.lineJoin = "round";
      ctx.lineCap = "round";
      ctx.stroke();
    }
    registerChart(canvas, draw);
  }
  window.GridPreview.renderSparkline = renderSparkline;

  /* ---------- Live-data error states ----------
     Used wherever a page has a real backend to talk to (the GB status strip
     and History charts on index.html, the Ireland status strip on
     pages/ireland.html) so that when the backend genuinely cannot be reached,
     the page says so plainly instead of quietly drawing illustrative data
     in its place. This is deliberately NOT used on sections that were never
     wired to a live source in the first place (the generation-mix donuts,
     SEM price, and every page with no backend at all) - those stay on
     illustrative data by design, not because something's broken; see
     pages/data-sources.html for which is which. */

  /** Shows (or updates) a page-level banner just below the hero section. */
  function showLiveDataBanner(message) {
    let banner = document.getElementById("live-data-banner");
    if (!banner) {
      banner = document.createElement("div");
      banner.id = "live-data-banner";
      banner.className = "live-data-banner";
      banner.setAttribute("role", "alert");
      const hero = document.querySelector(".hero");
      if (hero && hero.parentNode) {
        hero.parentNode.insertBefore(banner, hero.nextSibling);
      } else {
        const wrap = document.querySelector("main .wrap");
        if (wrap) wrap.insertBefore(banner, wrap.firstChild);
      }
    }
    banner.textContent = message;
    banner.hidden = false;
  }
  window.GridPreview.showLiveDataBanner = showLiveDataBanner;

  function hideLiveDataBanner() {
    const banner = document.getElementById("live-data-banner");
    if (banner) banner.hidden = true;
  }
  window.GridPreview.hideLiveDataBanner = hideLiveDataBanner;

  /**
   * For pages that combine more than one independent upstream API (the GB
   * status strip on index.html: Elexon/Carbon Intensity/NESO; the Ireland
   * status strip: EirGrid/ENTSO-E) - checks api/status.php and returns a
   * ready-to-show banner message naming exactly which source(s) currently
   * have a failing ingest run, or null if none do.
   *
   * This is deliberately separate from showLiveDataBanner's existing
   * all-or-nothing use (called when a page's own fetch* call came back
   * completely empty): a source can still be serving stale-but-present data
   * from an earlier successful run while its MOST RECENT ingest attempt is
   * erroring - that is a real, current API problem worth surfacing, but
   * "the figures below could not be loaded" would be wrong to say since
   * they did load, just not freshly. Callers pass a map of the exact
   * ingest_log source keys that feed their page to a short label describing
   * what each one powers there, e.g. { ELEXON: "demand, price and
   * transmission generation" }.
   *
   * A source with no entry at all in status.php's response (never logged a
   * run - e.g. EIA/ENTSOE before an API key is configured) is NOT treated
   * as an issue here, same as status.php's own "overall" semantics - that is
   * "illustrative by design", not "broken".
   */
  async function buildApiIssueBanner(sourceLabels) {
    const status = window.GridData ? await window.GridData.fetchStatus() : null;
    if (!status || !status.sources) return null; // status.php itself unreachable - cannot tell either way, so say nothing
    // A label can be { label, country }: ENTSO-E's status covers whichever
    // countries its last run fetched ("Countries: IT. ..."), so a failure
    // for another country is not this page's problem.
    const broken = Object.keys(sourceLabels).filter((key) => {
      const s = status.sources[key];
      if (!s || s.status === "OK") return false;
      const want = typeof sourceLabels[key] === "object" ? sourceLabels[key].country : null;
      const m = want && /Countries:\s*([A-Z]{2}(?:\s*,\s*[A-Z]{2})*)/.exec(s.message || "");
      if (m && m[1].split(/\s*,\s*/).indexOf(want) === -1) return false;
      return true;
    });
    if (!broken.length) return null;
    const labels = broken.map((key) => (typeof sourceLabels[key] === "object" ? sourceLabels[key].label : sourceLabels[key]));
    return (
      (broken.length === 1 ? "One of this page's live data sources is" : "Some of this page's live data sources are") +
      " having trouble right now: " + labels.join("; ") + ". Those figures may be out of date until it recovers."
    );
  }
  window.GridPreview.buildApiIssueBanner = buildApiIssueBanner;

  /**
   * Marks one chart/sparkline as unavailable instead of drawing anything
   * into it. Pass message === null to just hide the canvas with no overlay
   * text - for small sparklines there is not room for a message, and the
   * page-level banner (showLiveDataBanner) already explains why.
   */
  function showChartError(canvas, message) {
    if (!canvas) return;
    const wrap = canvas.closest(".chart-wrap");
    if (!wrap) return;
    wrap.classList.remove("is-loading");
    wrap.classList.add("has-error");
    canvas._donutMeta = null; // no longer showing any slices
    const ctx = canvas.getContext && canvas.getContext("2d");
    if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (message === null) return;
    let msg = wrap.querySelector(".chart-error-msg");
    if (!msg) {
      msg = document.createElement("p");
      msg.className = "chart-error-msg";
      wrap.appendChild(msg);
    }
    msg.textContent = message || "Live data unavailable.";
  }
  window.GridPreview.showChartError = showChartError;

  /** Marks one status-strip stat's value as unavailable rather than a mock number. */
  function showStatError(id) {
    const el = document.getElementById(id);
    if (el) el.textContent = "-";
  }
  window.GridPreview.showStatError = showStatError;

  /**
   * Builds the two-line HTML for the "Data as of" stat used at the top of
   * every live-wired page (index.html, ireland.html, and each ENTSO-E
   * country page): the visitor's own local time with its timezone name on
   * the first line, and UK time on a smaller line below.
   *
   * This matters because the first line's timezone is whatever the
   * visitor's own browser/OS is set to, NOT necessarily UK time - a site
   * called "Grid Pulse" showing "2.32pm" with no timezone attached is
   * ambiguous for anyone not physically in the UK right now (a reader
   * abroad, or checking a non-GB country page where it'd be easy to assume
   * the time shown is that country's local time instead). Labelling the
   * first line's timezone and always anchoring a second line to actual UK
   * time removes that ambiguity without needing to know or guess where the
   * visitor is.
   *
   * extraOptions lets usa.html add month/day (its EIA data can be the best
   * part of a day old, so the date matters there in a way it does not for
   * the near-real-time GB/Ireland/ENTSO-E pages).
   */
  function formatAsOfHtml(tsIso, extraOptions) {
    const d = new Date(tsIso);
    const base = Object.assign({ hour: "numeric", minute: "2-digit" }, extraOptions || {});
    const localStr = fmtTime12(d, Object.assign({ timeZoneName: "short" }, extraOptions || {}));
    const ukStr = fmtTime12(d, Object.assign({ timeZone: "Europe/London" }, extraOptions || {}));
    return localStr + '<br><small class="stat-time-uk">' + ukStr + " UK time</small>";
  }
  window.GridPreview.formatAsOfHtml = formatAsOfHtml;

  /** Same "Data as of" slot, but for a page's first paint from cached data
      rather than a confirmed-fresh fetch - "Showing: 14:32" rather than the
      normal two-line local+UK time. Deliberately plainer than
      formatAsOfHtml (no UK-time subline) since this is a transient state:
      it is replaced by formatAsOfHtml the moment the real fetch resolves. */
  function formatShowingHtml(tsIso) {
    const d = new Date(tsIso);
    const str = fmtTime12(d, { timeZoneName: "short" });
    return "Showing " + str + ' <small class="stat-time-uk">Refreshing…</small>';
  }
  window.GridPreview.formatShowingHtml = formatShowingHtml;

  /** Carbon-intensity traffic-light band, in gCO2/kWh. Thresholds match
      the National Energy System Operator's own published Carbon Intensity
      API bands (carbonintensity.org.uk), not a figure invented for this
      site, so the label carries real meaning rather than an arbitrary cut-off. */
  function carbonBand(gco2) {
    if (gco2 == null || isNaN(gco2)) return null;
    if (gco2 < 50) return { label: "Very low", cls: "band-verygood" };
    if (gco2 < 150) return { label: "Low", cls: "band-good" };
    if (gco2 < 250) return { label: "Moderate", cls: "band-average" };
    if (gco2 < 350) return { label: "High", cls: "band-bad" };
    return { label: "Very high", cls: "band-verybad" };
  }
  window.GridPreview.carbonBand = carbonBand;

  /** Wholesale price traffic-light band, in £/MWh. Unlike carbon intensity,
      there is no official public banding for GB wholesale price, so these
      are rough, disclosed thresholds based on the typical range seen in
      this site's own recorded price history (roughly -£20 to £250/MWh) -
      not a claim of any official "cheap/expensive" standard. */
  function priceBand(gbpMwh) {
    if (gbpMwh == null || isNaN(gbpMwh)) return null;
    if (gbpMwh < 40) return { label: "Low", cls: "band-good" };
    if (gbpMwh < 100) return { label: "Average", cls: "band-average" };
    return { label: "High", cls: "band-bad" };
  }
  window.GridPreview.priceBand = priceBand;

  /** Generic 5-tier traffic-light band from a value's position within a
      rough [low, high] reference range - shared by every region page's
      demand/generation stat cards. Unlike carbonBand's NESO-official
      thresholds, there is no official "low/high demand" standard for any
      grid, so - like priceBand - each page passes its own rough,
      disclosed-as-not-official round-number reference points (typically an
      approximate summer-overnight low and a recent winter-evening peak for
      that specific grid - see each page's own call site for its source).
      Deliberately a magnitude indicator ("how close to this grid's own
      typical peak is it right now?"), not a "good/bad" moral judgment the
      way the price/carbon bands are - framed as Low/Average/High rather
      than good/bad wording for that reason. */
  function bandFromRange(value, low, high) {
    if (value == null || isNaN(value) || !(high > low)) return null;
    const frac = (value - low) / (high - low);
    if (frac < 0.2) return { label: "Low", cls: "band-verygood" };
    if (frac < 0.45) return { label: "Below average", cls: "band-good" };
    if (frac < 0.7) return { label: "Average", cls: "band-average" };
    if (frac < 0.9) return { label: "High", cls: "band-bad" };
    return { label: "Very high", cls: "band-verybad" };
  }
  window.GridPreview.bandFromRange = bandFromRange;

  /** Net interconnector flow traffic-light band, in MW - positive means
      this grid is importing, negative means it is exporting (see
      pages/interconnectors.html's own "positive = importing, negative =
      exporting" convention note, which this matches). Framed as a neutral
      trade status rather than a moral "good/bad": exporting reads as the
      calmest state (green) since it means this grid has spare capacity,
      but importing is not inherently bad - it is ordinary cross-border
      trading - so it only escalates toward amber/red as the volume grows,
      reflecting heavier reliance on neighbouring grids rather than
      wrongdoing. Thresholds are round, disclosed-as-rough reference points
      scaled to a single interconnector's typical capacity (roughly
      1-2GW), not official. */
  function transfersBand(mw) {
    if (mw == null || isNaN(mw)) return null;
    if (mw <= 0) return { label: "Exporting", cls: "band-verygood" };
    if (mw < 1000) return { label: "Light imports", cls: "band-good" };
    if (mw < 3000) return { label: "Importing", cls: "band-average" };
    if (mw < 5000) return { label: "Heavy imports", cls: "band-bad" };
    return { label: "Very heavy imports", cls: "band-verybad" };
  }
  window.GridPreview.transfersBand = transfersBand;

  /** Applies a computed band {label, cls} (or null) to a small indicator
      element next to a stat value - sets the CSS class that drives its
      colour/dot and a text label plus a title tooltip explaining what it
      means, so the signal is not colour-only (accessibility). Clears the
      indicator entirely (no dot, no text) when band is null, e.g. while a
      value is unavailable. */
  function setStatBand(id, band, tooltipSuffix) {
    const el = document.getElementById(id);
    if (!el) return;
    el.className = "stat-band" + (band ? " " + band.cls : "");
    el.textContent = band ? band.label : "";
    el.title = band ? band.label + (tooltipSuffix || "") : "";
    el.hidden = !band;
  }
  window.GridPreview.setStatBand = setStatBand;

  /**
   * Shows a page's last successfully-fetched data immediately from
   * localStorage (if any) while the real network fetch is still in
   * flight, then replaces it once that fetch resolves - so a repeat
   * visitor sees real numbers straight away instead of a blank "Loading
   * live data…" placeholder every time, even on a slow connection.
   *
   * cacheKey: namespaces the localStorage entry, e.g. "gb-current",
   *   "ireland-current", "country-current-FR" - one per distinct fetch
   *   this page makes that this pattern applies to.
   * fetchFn(): the page's own fetchXCurrent()-shaped call - must resolve
   *   to null on any failure (every fetch* function in assets/data.js
   *   already does this), never reject.
   * renderFn(data, isCached): called synchronously with the cached data
   *   (if any) before this function returns, and again with the fresh
   *   data once fetchFn() resolves (only if it did not resolve to null -
   *   a failed fetch leaves whatever renderFn(cached, true) already drew
   *   as the final on-screen state, rather than blanking it out).
   *
   * Returns { usedCache, promise } - usedCache is known synchronously
   * (was there anything to show immediately?), promise resolves to
   * fetchFn()'s own result so callers can still chain sparklines, banners,
   * etc. after it the same way they did before this wrapper existed.
   * localStorage is wrapped in try/catch throughout - private browsing,
   * disabled storage, or a quota error should degrade to "no cache
   * available", never break the page.
   */
  function withCachedFallback(cacheKey, fetchFn, renderFn) {
    const storageKey = "ukgl-cache-" + cacheKey;
    let usedCache = false;
    // A periodic refresh (see autoRefresh) - the page is already showing
    // the last good figures, so do not repaint them as "cached/refreshing",
    // and report usedCache so a failed fetch keeps them rather than
    // switching the page to "Figures appear here when the next reading arrives."
    if (window.GridPreview && window.GridPreview._refreshing) {
      return { usedCache: true, promise: fetchFn().then((fresh) => {
        if (fresh) {
          renderFn(fresh, false);
          try { localStorage.setItem(storageKey, JSON.stringify(fresh)); } catch (e) {}
        }
        return fresh;
      }) };
    }
    try {
      const raw = localStorage.getItem(storageKey);
      if (raw) {
        renderFn(JSON.parse(raw), true);
        usedCache = true;
      }
    } catch (e) {
      // Corrupt cache entry, storage disabled, or private-browsing quota -
      // proceed exactly as if there were no cache.
    }
    const promise = fetchFn().then((fresh) => {
      if (fresh) {
        renderFn(fresh, false);
        try {
          localStorage.setItem(storageKey, JSON.stringify(fresh));
        } catch (e) {
          // Storage full or disabled - caching is a nicety, never fatal.
        }
      }
      return fresh;
    });
    return { usedCache, promise };
  }
  window.GridPreview.withCachedFallback = withCachedFallback;

  /** Raw read/write for the same localStorage-backed cache
      withCachedFallback uses - for pages like ireland.html that combine
      two independent sources (EirGrid + ENTSO-E) into one rendered view
      and need to read/write each source's cache entry on their own
      schedule rather than through the single-source wrapper above. */
  function cacheGet(cacheKey) {
    try {
      const raw = localStorage.getItem("ukgl-cache-" + cacheKey);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }
  function cacheSet(cacheKey, data) {
    try {
      localStorage.setItem("ukgl-cache-" + cacheKey, JSON.stringify(data));
    } catch (e) {
      // Storage full or disabled - caching is a nicety, never fatal.
    }
  }
  window.GridPreview.cacheGet = cacheGet;
  window.GridPreview.cacheSet = cacheSet;

  /* ---------- Stacked area chart ---------- */
  function renderStackedArea(canvas, labels, datasets, opts) {
    opts = opts || {};
    function draw() {
      const { ctx, w, h } = prepareCanvas(canvas);
      const colors = themeAwareColors();
      const n = labels.length;
      const pad = { l: 42, r: 12, t: 12, b: 22 };
      const plotW = w - pad.l - pad.r;
      const plotH = h - pad.t - pad.b;
      const xAt = (i) => pad.l + (n > 1 ? plotW * (i / (n - 1)) : plotW / 2);

      const cum = new Array(n).fill(0);
      const layers = datasets.map((d) => {
        const bottom = cum.slice();
        for (let i = 0; i < n; i++) cum[i] += d.data[i];
        return { bottom, top: cum.slice(), color: d.color };
      });
      const maxV = Math.max(...cum, 0.0001) * 1.08;
      const yAt = (v) => pad.t + plotH - plotH * (v / maxV);

      ctx.strokeStyle = colors.grid;
      ctx.fillStyle = colors.text;
      const yTicks = 4;
      for (let i = 0; i <= yTicks; i++) {
        const v = (maxV * i) / yTicks;
        const y = yAt(v);
        ctx.beginPath();
        ctx.moveTo(pad.l, Math.round(y) + 0.5);
        ctx.lineTo(w - pad.r, Math.round(y) + 0.5);
        ctx.stroke();
        ctx.textAlign = "right";
        ctx.textBaseline = "middle";
        ctx.fillText(formatAxisValue(v, opts.unit), pad.l - 8, y);
      }
      ctx.textAlign = "center";
      ctx.textBaseline = "top";
      pickTickIndices(n, 6).forEach((idx) => ctx.fillText(labels[idx], xAt(idx), h - pad.b + 5));

      layers.forEach((layer) => {
        ctx.beginPath();
        for (let i = 0; i < n; i++) {
          const x = xAt(i), y = yAt(layer.top[i]);
          if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        }
        for (let i = n - 1; i >= 0; i--) {
          ctx.lineTo(xAt(i), yAt(layer.bottom[i]));
        }
        ctx.closePath();
        ctx.fillStyle = hexToRgba(layer.color, 0.82);
        ctx.fill();
        ctx.strokeStyle = layer.color;
        ctx.lineWidth = 1;
        ctx.stroke();
      });
    }
    registerChart(canvas, draw);
    if (opts.legendTarget) {
      buildLegend(opts.legendTarget, datasets.map((d) => d.label), datasets.map((d) => d.color));
    }
  }
  window.GridPreview.renderStackedArea = renderStackedArea;

  /* ---------- Bar chart (horizontal by default) ---------- */
  function renderBarChart(canvas, labels, values, colorsInput, opts) {
    opts = opts || {};
    const horizontal = opts.horizontal !== false;
    function draw() {
      const { ctx, w, h } = prepareCanvas(canvas);
      const colors = themeAwareColors();
      const n = values.length;
      const colorFor = (i) => (Array.isArray(colorsInput) ? colorsInput[i] : colorsInput);

      if (horizontal) {
        ctx.font = '12px "Open Sans", sans-serif';
        const labelW = Math.min(160, Math.max(...labels.map((l) => ctx.measureText(l).width)) + 4);
        const pad = { l: labelW + 12, r: 46, t: 8, b: 8 };
        const plotW = w - pad.l - pad.r;
        const plotH = h - pad.t - pad.b;
        const maxV = Math.max(...values, 0.0001) * 1.08;
        const gap = plotH / n;
        const barH = Math.min(opts.thickness || 18, gap - 8);
        values.forEach((v, i) => {
          const y = pad.t + i * gap + (gap - barH) / 2;
          const bw = Math.max(plotW * (v / maxV), 2);
          const barColor = colorFor(i);
          // Fade toward the axis (zero) the same way the line/area charts
          // fade toward their baseline - full colour at the tip (the
          // actual value), lighter back at the zero end.
          const grad = ctx.createLinearGradient(pad.l + bw, 0, pad.l, 0);
          grad.addColorStop(0, barColor);
          grad.addColorStop(1, hexToRgba(barColor, 0.5));
          ctx.fillStyle = grad;
          roundRectPath(ctx, pad.l, y, bw, barH, 4);
          ctx.fill();
          ctx.fillStyle = colors.text;
          ctx.textAlign = "right";
          ctx.textBaseline = "middle";
          ctx.fillText(labels[i], pad.l - 8, y + barH / 2);
          ctx.textAlign = "left";
          ctx.fillText(opts.decimals != null ? withUnit(Number(v).toFixed(opts.decimals), opts.unit) : formatAxisValue(v, opts.unit), pad.l + bw + 6, y + barH / 2);
        });
      } else {
        const pad = { l: 40, r: 10, t: 16, b: 24 };
        const plotW = w - pad.l - pad.r;
        const plotH = h - pad.t - pad.b;
        const maxV = Math.max(...values, 0.0001) * 1.12;
        const gap = plotW / n;
        const barW = Math.min(opts.thickness || 30, gap - 10);
        values.forEach((v, i) => {
          const bh = plotH * (v / maxV);
          const x = pad.l + i * gap + (gap - barW) / 2;
          const y = pad.t + plotH - bh;
          const barColor = colorFor(i);
          // Same "full colour at the value, fading toward the baseline"
          // treatment as the horizontal bars above and the line/area charts.
          const grad = ctx.createLinearGradient(0, y, 0, pad.t + plotH);
          grad.addColorStop(0, barColor);
          grad.addColorStop(1, hexToRgba(barColor, 0.5));
          ctx.fillStyle = grad;
          roundRectPath(ctx, x, y, barW, bh, 4);
          ctx.fill();
          ctx.fillStyle = colors.text;
          ctx.textAlign = "center";
          ctx.textBaseline = "top";
          ctx.fillText(labels[i], x + barW / 2, pad.t + plotH + 5);
          ctx.textBaseline = "bottom";
          ctx.fillText(formatAxisValue(v, opts.unit), x + barW / 2, y - 4);
        });
      }
    }
    registerChart(canvas, draw);

    if (!opts.noExport) {
      const colorArr = Array.isArray(colorsInput) ? colorsInput : labels.map(() => colorsInput);
      attachChartExport(canvas, {
        title: opts.exportTitle,
        kind: "categories",
        data: { labels, values, colors: colorArr, unit: opts.unit },
      });
    }
  }
  window.GridPreview.renderBarChart = renderBarChart;

  /* ---------- Donut chart (2026 redesign) ----------
     Every donut on the site now shares one interaction model - the same one
     the overview page's two mix donuts pioneered: hovering (or, on touch
     devices, tapping) a segment pops it out, gives it a soft colour-matched
     glow, dims the rest, swaps the centre readout to that segment's own
     label/value/share, and opens a floating tooltip anchored to the pointer
     - the same rounded, theme-aware tooltip box style used by the line
     charts (see drawOverlay() above), so every chart on the site now shares
     one tooltip look. This replaces the old opts.leaderLines mode, which
     drew each label directly on the canvas next to its segment: elegant on
     a wide desktop panel, but the horizontal leader-line + text run was not
     bounded by the canvas's own width, so it clipped off-canvas on phones
     and the cramped two-column tablet band (see the mobile fix this
     replaces). A pointer-anchored tooltip has no such failure mode - it is
     always positioned and clamped against the canvas it is drawn on - so
     every donut can now safely have the same rich hover treatment the
     overview page used to reserve for just its two largest charts.
     Segments are drawn with a small angular gap between them (rather than
     one continuous ring) so each slice reads as a distinct piece at a
     glance, before you have hovered anything - still filled with the same
     centre-to-rim radial gradient every other chart on the site uses. */
  function renderDonutChart(canvas, labels, values, colors, opts) {
    opts = opts || {};
    // Hover state deliberately lives ON THE CANVAS ELEMENT, not as a local
    // variable in this function's closure. Several pages (every ENTSO-E
    // country page via renderPsrMixSection, plus any page that draws an
    // illustrative donut first and a live one later) call renderDonutChart
    // a SECOND time on the very same <canvas> once live data arrives. The
    // mousemove/touchstart listeners below are only ever bound ONCE per
    // canvas (see the canvas._donutHoverBound guard) - they were written
    // against THIS call's hoverIndex/pointer. But canvas._donutDraw gets
    // reassigned to the newest call's draw() on every re-render. A local
    // `let hoverIndex/pointer` here meant the first call's listeners kept
    // mutating the FIRST closure's variables while canvas._donutDraw()
    // invoked the SECOND (or later) closure's draw() - which read its OWN
    // always-(-1/null) hoverIndex/pointer instead, so the hover glow and
    // tooltip silently stopped appearing after any re-render (bug report:
    // "the donut in Ireland does not allow hover over" - reproducible on
    // every page using renderPsrMixSection, not just Ireland). Storing this
    // state on the canvas itself means every draw() call - whichever
    // render "owns" canvas._donutDraw at the time - reads and writes the
    // same shared state the listeners update.
    if (canvas._donutHoverIndex === undefined) canvas._donutHoverIndex = -1;
    if (canvas._donutPointer === undefined) canvas._donutPointer = null; // {x, y} in canvas-local CSS px - drives the floating tooltip
    // A re-render with fewer slices than before (e.g. a source dropping to
    // zero on the next refresh) would otherwise leave a hover index
    // pointing past the end of the new arcs array and throw mid-draw.
    if (canvas._donutHoverIndex >= values.length) canvas._donutHoverIndex = -1;

    // Value/share text for the centre readout and hover tooltip. With
    // opts.decimals set (every "Generation mix right now" donut, via
    // renderMixBreakdown), this uses exactly the same rounding as the mix
    // table beside it - GW to that many decimals, share to one decimal
    // place - so hovering a slice shows the very same two numbers as its
    // table row. Without it, the original axis-style rounding is kept.
    const fmtValue = (v) => opts.decimals != null ? withUnit(Number(v).toFixed(opts.decimals), opts.unit) : formatAxisValue(v, opts.unit);
    const fmtShare = (frac) => opts.decimals != null ? `${(frac * 100).toFixed(opts.pctDecimals != null ? opts.pctDecimals : 1)}%` : `${Math.round(frac * 100)}%`;

    function draw() {
      const { ctx, w, h } = prepareCanvas(canvas);
      const total = values.reduce((a, b) => a + Math.max(b, 0), 0) || 1;
      const cx = w / 2, cy = h / 2;
      const outerR = Math.max(10, Math.min(w, h) / 2 - 4);
      const innerR = outerR * 0.6;
      // A small fixed gap between segments (in radians), capped so it can
      // never eat more than a fifth of any one segment's own sweep - keeps
      // very thin slices (a 1% interconnector sliver, say) from vanishing
      // into the gap either side of them.
      const rawGap = values.length > 1 ? 0.05 : 0;
      const themeColors = themeAwareColors();
      let start = -Math.PI / 2;
      const arcs = [];
      values.forEach((v, i) => {
        const frac = Math.max(v, 0) / total;
        const sweep = frac * Math.PI * 2;
        const end = start + sweep;
        const gap = Math.min(rawGap, sweep * 0.2);
        const segStart = start + gap / 2;
        const segEnd = Math.max(segStart + 0.001, end - gap / 2);
        const isHover = i === canvas._donutHoverIndex;
        const r = isHover ? outerR + 6 : outerR;

        ctx.save();
        ctx.globalAlpha = canvas._donutHoverIndex === -1 || isHover ? 1 : 0.32;
        if (isHover) {
          ctx.shadowColor = hexToRgba(colors[i], 0.6);
          ctx.shadowBlur = 16;
        }
        ctx.beginPath();
        ctx.moveTo(cx + Math.cos(segStart) * innerR, cy + Math.sin(segStart) * innerR);
        ctx.arc(cx, cy, r, segStart, segEnd);
        ctx.arc(cx, cy, innerR, segEnd, segStart, true);
        ctx.closePath();
        // Radial fade from the hole outward - lighter near the centre, full
        // colour at the rim - the same treatment used by every other chart
        // type on the site (lines, stacked areas, bars).
        const grad = ctx.createRadialGradient(cx, cy, innerR, cx, cy, r);
        grad.addColorStop(0, hexToRgba(colors[i], 0.5));
        grad.addColorStop(1, colors[i]);
        ctx.fillStyle = grad;
        ctx.fill();
        ctx.restore();

        arcs.push({ start, end, mid: (start + end) / 2, color: colors[i], label: labels[i], value: v, frac, index: i });
        start = end;
      });

      ctx.fillStyle = themeColors.text;
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.font = '600 13px "Open Sans", sans-serif';
      if (canvas._donutHoverIndex !== -1) {
        const a = arcs[canvas._donutHoverIndex];
        ctx.fillText(a.label, cx, cy - 6);
        ctx.font = '11px "Open Sans", sans-serif';
        ctx.fillText(`${fmtValue(a.value)} · ${fmtShare(a.frac)}`, cx, cy + 12);
      } else {
        if (opts.centerLabel) ctx.fillText(opts.centerLabel, cx, cy - 6);
        if (opts.centerSub) {
          ctx.font = '11px "Open Sans", sans-serif';
          ctx.fillText(opts.centerSub, cx, cy + 12);
        }
      }

      // Floating tooltip, anchored to the pointer and clamped inside the
      // canvas - same box style as the line charts' hover tooltip.
      if (canvas._donutHoverIndex !== -1 && canvas._donutPointer) {
        const a = arcs[canvas._donutHoverIndex];
        const pointer = canvas._donutPointer;
        const labelLine = a.label;
        const valueLine = `${fmtValue(a.value)} · ${fmtShare(a.frac)}`;
        ctx.font = '700 12px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
        let boxW = ctx.measureText(labelLine).width;
        ctx.font = '11px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
        boxW = Math.max(boxW, ctx.measureText(valueLine).width) + 20;
        const boxH = 40;

        let boxX = pointer.x + 12;
        if (boxX + boxW > w - 4) boxX = pointer.x - boxW - 12;
        boxX = Math.max(4, Math.min(boxX, w - boxW - 4));
        let boxY = pointer.y - boxH - 12;
        if (boxY < 4) boxY = Math.min(pointer.y + 12, h - boxH - 4);

        ctx.save();
        roundRectPath(ctx, boxX, boxY, boxW, boxH, 6);
        ctx.fillStyle = themeColors.panelBg;
        ctx.fill();
        ctx.strokeStyle = themeColors.panelBorder;
        ctx.lineWidth = 1;
        ctx.stroke();
        ctx.restore();

        ctx.textAlign = "left";
        ctx.textBaseline = "top";
        ctx.fillStyle = a.color;
        ctx.font = '700 12px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
        ctx.fillText(labelLine, boxX + 10, boxY + 7);
        ctx.fillStyle = themeColors.text;
        ctx.font = '11px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
        ctx.fillText(valueLine, boxX + 10, boxY + 23);
      }

      canvas._donutArcs = arcs;
      canvas._donutCenter = { cx, cy, outerR, innerR };
    }

    registerChart(canvas, draw);
    // What this donut is currently showing, kept on the element so it can
    // be checked against the table beside it (the automated donut-vs-table
    // audit reads this) without having to decode canvas pixels.
    canvas._donutMeta = { labels: labels.slice(), values: values.slice(), centerLabel: opts.centerLabel || null, centerSub: opts.centerSub || null, decimals: opts.decimals };
    if (opts.legendTarget) buildLegend(opts.legendTarget, labels, colors, values, opts.unit, opts.decimals != null ? { decimals: opts.decimals, pctDecimals: opts.pctDecimals } : null);

    if (!opts.noExport) {
      attachChartExport(canvas, {
        title: opts.exportTitle,
        kind: "categories",
        data: { labels, values, colors, unit: opts.unit, decimals: opts.decimals, pctDecimals: opts.pctDecimals },
      });
    }

    if (!canvas._donutHoverBound) {
      canvas._donutHoverBound = true;

      function hitTest(clientX, clientY) {
        const rect = canvas.getBoundingClientRect();
        const x = clientX - rect.left, y = clientY - rect.top;
        const arcs = canvas._donutArcs, c = canvas._donutCenter;
        if (!arcs || !c) return -1;
        const dx = x - c.cx, dy = y - c.cy;
        const dist = Math.hypot(dx, dy);
        if (dist < c.innerR || dist > c.outerR + 6) return -1;
        let angle = Math.atan2(dy, dx);
        if (angle < -Math.PI / 2) angle += Math.PI * 2;
        for (const a of arcs) {
          if (angle >= a.start && angle < a.end) return a.index;
        }
        return -1;
      }

      canvas.addEventListener("mousemove", (e) => {
        const rect = canvas.getBoundingClientRect();
        const idx = hitTest(e.clientX, e.clientY);
        canvas.style.cursor = idx === -1 ? "default" : "pointer";
        canvas._donutPointer = { x: e.clientX - rect.left, y: e.clientY - rect.top };
        if (idx !== canvas._donutHoverIndex) {
          canvas._donutHoverIndex = idx;
          canvas._donutDraw && canvas._donutDraw();
        } else if (idx !== -1) {
          // Same segment, pointer moved - redraw so the tooltip follows it.
          canvas._donutDraw && canvas._donutDraw();
        }
      });
      canvas.addEventListener("mouseleave", () => {
        canvas.style.cursor = "default";
        canvas._donutPointer = null;
        if (canvas._donutHoverIndex !== -1) {
          canvas._donutHoverIndex = -1;
          canvas._donutDraw && canvas._donutDraw();
        }
      });

      // Touch devices get no hover event at all, so a tap has to do the
      // same job: identify the segment under the finger, show its glow +
      // tooltip, and leave it showing (rather than needing a held touch)
      // until the visitor taps elsewhere. Deliberately only handled on
      // touchstart, not touchmove, so this never has to call
      // preventDefault() and never interferes with ordinary page scrolling.
      canvas.addEventListener("touchstart", (e) => {
        const t = e.changedTouches[0];
        if (!t) return;
        const rect = canvas.getBoundingClientRect();
        const idx = hitTest(t.clientX, t.clientY);
        canvas._donutPointer = { x: t.clientX - rect.left, y: t.clientY - rect.top };
        canvas._donutHoverIndex = idx;
        canvas._donutDraw && canvas._donutDraw();
      }, { passive: true });

      document.addEventListener("touchstart", (e) => {
        if (canvas._donutHoverIndex === -1 || e.target === canvas) return;
        canvas._donutHoverIndex = -1;
        canvas._donutPointer = null;
        canvas._donutDraw && canvas._donutDraw();
      }, { passive: true });
    }
    canvas._donutDraw = draw;
  }
  window.GridPreview.renderDonutChart = renderDonutChart;

  /**
   * Replaces a "generation mix right now" section's table/donut/bar with an
   * explicit "Figures appear here when the next reading arrives." state, rather than leaving old illustrative
   * numbers on screen looking like they might be current. Shared by
   * renderPsrMixSection below and by callers that already know up front
   * they have nothing to show (e.g. a totally failed fetch with no cache).
   */
  function showNoMixData(opts, message) {
    const msg = message || "Figures appear here when the next reading arrives.";
    const tbody = document.getElementById(opts.tbodyId);
    if (tbody) {
      const colCount = tbody.closest("table") ? tbody.closest("table").querySelectorAll("thead th").length : 3;
      tbody.innerHTML = `<tr><td colspan="${colCount}" style="text-align:center;padding:1.5rem 0;color:var(--text-muted);">${msg}</td></tr>`;
    }
    const donutCanvas = document.getElementById(opts.donutId);
    if (donutCanvas) showChartError(donutCanvas, msg);
    const mixHost = donutCanvas && mixHostFor(donutCanvas, opts);
    if (mixHost) mixHost.innerHTML = `<p class="mixchart__readout">${escapeHtml(msg)}</p>`;
    const barCanvas = document.getElementById(opts.barId);
    if (barCanvas) showChartError(barCanvas, msg);
    if (opts.legendId) {
      const legend = document.getElementById(opts.legendId);
      if (legend) legend.innerHTML = "";
    }
    if (opts.donutCaptionId) {
      const el = document.getElementById(opts.donutCaptionId);
      if (el) el.textContent = msg;
    }
    if (opts.statusCaptionId) {
      const el = document.getElementById(opts.statusCaptionId);
      if (el) el.textContent = msg;
    }
  }
  window.GridPreview.showNoMixData = showNoMixData;

  /**
   * THE single renderer behind every "Generation mix right now" section on
   * the site (GB, Ireland, every ENTSO-E country page, the EU aggregate,
   * the USA, Canada and Australia) - table, donut, donut legend, bar chart
   * and captions are all built here from ONE list of rows, so they cannot
   * drift apart. Before this existed, each page built its table and its
   * donut separately and they genuinely disagreed in several ways (see
   * CHANGELOG.md, 2026-09-30): the GB table's % was "of demand" while its
   * donut's % was "of generation"; interconnectors and pumped storage were
   * table rows with no donut slice; Ireland's GB-interconnect line was
   * added as a positive "import" wedge even while Ireland was exporting;
   * negative readings (pumping, battery charging, net export) cut the
   * table's total but were silently dropped from the donut's; the table
   * showed 2 decimal places while the donut legend/tooltip rounded to 1
   * and to whole percentages; and fuel codes with no table group appeared
   * in the donut only.
   *
   * The rules, applied identically to the table and the donut:
   *   - A row counts towards the mix if it rounds to a non-zero positive
   *     figure at the table's own precision. Those rows, and only those,
   *     are the donut's slices, the legend's entries and the bar chart's
   *     bars, in the same order as the table.
   *   - Every % (table rows, group subtotals, donut tooltip, legend) is a
   *     share of the same total: the sum of those positive rows - which is
   *     also the donut's centre figure.
   *   - Negative rows (pumping load, battery charging, net exports) are
   *     listed in their own clearly-labelled table group, with "-" for %,
   *     since a negative cannot be a slice of a donut. They are excluded
   *     from the total, and the caption says so.
   *   - Rows that are exactly zero (e.g. solar at night) are left out of
   *     both, and named in the caption instead.
   *   - A row whose group is not one of opts.groups lands in an "Other"
   *     group rather than vanishing from the table.
   *
   * rows: [{ label, mw, group, color }] - color must be a literal hex (it
   *       feeds the donut's canvas gradient, which cannot resolve var()).
   * opts: tbodyId, donutId, legendId, barId (required); donutCaptionId,
   *       statusCaptionId, statusCaptionHtml (optional);
   *       groups: [{ key, label, color }] in table order;
   *       decimals: GW decimal places (default 2);
   *       centerSub: text under the donut's centre total (default "generated");
   *       captionFn(summary): returns the donut caption text (optional);
   *       exportTitle: donut export title (optional).
   * Returns a summary { totalMw, positive, negative, zero } or null.
   */
  function renderMixBreakdown(rows, opts) {
    opts = opts || {};
    const decimals = opts.decimals != null ? opts.decimals : 2;
    // Under half a megawatt either way counts as zero. (Deliberately not
    // "anything that rounds to 0.0GW": a real 13MW biofuel reading on a
    // 1-decimal page is small, not zero, and belongs in both the table and
    // the donut rather than being named as "currently at zero".)
    const epsMw = 0.5;
    const groups = (opts.groups || []).slice();
    const knownGroup = (key) => groups.some((g) => g.key === key);

    const clean = (rows || [])
      .filter((r) => r && r.mw != null && isFinite(Number(r.mw)))
      .map((r) => ({ label: r.label, mw: Number(r.mw), color: r.color || "#7a7a7a", group: knownGroup(r.group) ? r.group : "__other" }));
    if (clean.some((r) => r.group === "__other") && !knownGroup("__other")) {
      groups.push({ key: "__other", label: "Other", color: "#7a7a7a" });
    }

    const positive = clean.filter((r) => r.mw >= epsMw);
    const negative = clean.filter((r) => r.mw <= -epsMw);
    const zero = clean.filter((r) => r.mw > -epsMw && r.mw < epsMw);
    if (!positive.length) { showNoMixData(opts); return null; }

    const totalMw = positive.reduce((sum, r) => sum + r.mw, 0);
    const gw = (mw) => (mw / 1000).toFixed(decimals);
    const pct = (mw) => totalMw > 0 ? (mw / totalMw * 100).toFixed(1) : "0.0";

    // Table order = donut order = legend order: groups in opts.groups
    // order, largest row first within each group.
    const ordered = [];
    groups.forEach((g) => {
      positive.filter((r) => r.group === g.key).sort((a, b) => b.mw - a.mw).forEach((r) => ordered.push(r));
    });

    const tbody = document.getElementById(opts.tbodyId);
    if (tbody) {
      tbody.innerHTML = "";
      groups.forEach((g) => {
        const members = ordered.filter((r) => r.group === g.key);
        if (!members.length) return;
        const groupMw = members.reduce((sum, r) => sum + r.mw, 0);
        const groupRow = document.createElement("tr");
        groupRow.className = "group-row";
        groupRow.innerHTML = `<th scope="row"><span class="swatch" style="background:${g.color}"></span>${escapeHtml(g.label)}</th><td class="num">${gw(groupMw)}</td><td class="num">${pct(groupMw)}</td>`;
        tbody.appendChild(groupRow);
        members.forEach((r) => {
          const row = document.createElement("tr");
          row.innerHTML = `<td><span class="swatch swatch--row" style="background:${r.color}"></span>${escapeHtml(r.label)}</td><td class="num">${gw(r.mw)}</td><td class="num">${pct(r.mw)}</td>`;
          tbody.appendChild(row);
        });
      });
      if (negative.length) {
        const negRow = document.createElement("tr");
        negRow.className = "group-row";
        negRow.innerHTML = `<th scope="row"><span class="swatch" style="background:var(--text-muted)"></span>Consuming or exporting right now</th><td class="num">${gw(negative.reduce((s, r) => s + r.mw, 0))}</td><td class="num" title="Not part of the generation mix, so not included in the percentages">-</td>`;
        tbody.appendChild(negRow);
        negative.sort((a, b) => a.mw - b.mw).forEach((r) => {
          const row = document.createElement("tr");
          row.innerHTML = `<td><span class="swatch swatch--row" style="background:${r.color}"></span>${escapeHtml(r.label)}</td><td class="num">${gw(r.mw)}</td><td class="num">-</td>`;
          tbody.appendChild(row);
        });
      }
    }

    const labels = ordered.map((r) => r.label);
    const values = ordered.map((r) => r.mw / 1000);
    const colors = ordered.map((r) => r.color);

    const donutCanvas = document.getElementById(opts.donutId);
    const mixHost = mixHostFor(donutCanvas, opts);
    if (donutCanvas && !mixHost) {
      const wrap = donutCanvas.closest(".chart-wrap");
      if (wrap) wrap.classList.remove("is-loading");
      renderDonutChart(donutCanvas, labels, values, colors, {
        legendTarget: opts.legendId, unit: "GW", decimals, pctDecimals: 1,
        centerLabel: gw(totalMw) + "GW", centerSub: opts.centerSub || "generated",
        exportTitle: opts.exportTitle,
      });
    }
    const barCanvas = document.getElementById(opts.barId);
    if (barCanvas) {
      const wrap = barCanvas.closest(".chart-wrap");
      if (wrap) wrap.classList.remove("is-loading");
      const paired = ordered.slice().sort((a, b) => b.mw - a.mw);
      renderBarChart(barCanvas, paired.map((p) => p.label), paired.map((p) => p.mw / 1000), paired.map((p) => p.color), { unit: "GW", decimals });
    }

    const summary = { totalMw, positive: ordered, negative, zero, gw, pct };
    let note = opts.captionFn ? opts.captionFn(summary) : `Total ${gw(totalMw)}GW.`;
    note += " Every percentage is a share of this same total.";
    if (negative.length) {
      note += ` ${joinAnd(negative.map((r) => r.label))} ${negative.length === 1 ? "is" : "are"} using power or exporting right now, so the table lists ${negative.length === 1 ? "it" : "them"} on ${negative.length === 1 ? "its" : "their"} own and leaves ${negative.length === 1 ? "it" : "them"} out of the total.`;
    }
    if (zero.length) {
      note += ` At zero right now: ${joinAnd(zero.map((r) => r.label))}.`;
    }
    if (opts.donutCaptionId) {
      const el = document.getElementById(opts.donutCaptionId);
      if (el) el.textContent = note;
    }
    if (mixHost) {
      renderMixChart(mixHost, ordered, totalMw, { gw, pct, note, centerSub: opts.centerSub || "generated" });
      const table = tbody && tbody.closest("table");
      const section = mixHost.closest("section") || mixHost.parentNode;
      let tools = section && section.querySelector(".download-row");
      if (!tools) {
        tools = mixHost._tools || document.createElement("p");
        tools.className = "download-row";
        mixHost._tools = tools;
        if (!tools.parentNode) mixHost.parentNode.insertBefore(tools, mixHost.nextSibling);
      }
      attachShowTable(table, tools);
      attachMixSnapshot(mixHost, tools);
    }
    if (opts.statusCaptionId && opts.statusCaptionHtml) {
      const el = document.getElementById(opts.statusCaptionId);
      if (el) el.innerHTML = opts.statusCaptionHtml;
    }
    return summary;
  }
  window.GridPreview.renderMixBreakdown = renderMixBreakdown;

  /**
   * ENTSO-E flavour of renderMixBreakdown above - shared by pages/
   * ireland.html, pages/eu.html and every ENTSO-E-sourced country page.
   * Turns a live psrType -> MW breakdown (api/country_current.php's
   * mix_mw) into renderMixBreakdown's rows, using the site-wide canonical
   * psrType labels/colours from assets/data.js.
   *
   * opts: everything renderMixBreakdown takes, plus
   *   sourceLabel - e.g. "ENTSO-E Transparency Platform (France bidding zone)", used in the caption
   *   mixTs       - the mix's own timestamp (api/country_current.php's mix_ts), shown in the caption when given
   *   extraLine   - { label, mw, color } - one extra row, e.g. Ireland's EirGrid-sourced GB interconnection
   *                 (positive = importing; a negative value is shown as an export, not as a slice)
   *   captionNote - extra sentence appended to the caption (optional)
   */
  function renderPsrMixSection(mixMw, opts) {
    opts = opts || {};
    if (!window.GridData || !window.GridData.ENTSOE_PSR_LABELS) { showNoMixData(opts); return false; }
    const D = window.GridData;
    const groupOf = (code) => {
      if (D.ENTSOE_FOSSIL_PSR && D.ENTSOE_FOSSIL_PSR.indexOf(code) !== -1) return "fossil";
      if (D.ENTSOE_RENEWABLE_PSR && D.ENTSOE_RENEWABLE_PSR.indexOf(code) !== -1) return "renewable";
      if (D.ENTSOE_NUCLEAR_PSR && D.ENTSOE_NUCLEAR_PSR.indexOf(code) !== -1) return "nuclear";
      return "other";
    };
    const rows = Object.keys(mixMw || {}).map((code) => ({
      label: D.ENTSOE_PSR_LABELS[code] || code,
      mw: mixMw[code],
      group: groupOf(code),
      color: (D.ENTSOE_PSR_COLORS && D.ENTSOE_PSR_COLORS[code]) || "#7a7a7a",
    }));
    const groups = [
      { key: "renewable", label: "Renewables", color: "#1f9d5a" },
      { key: "fossil", label: "Fossil fuels", color: "#8a5a3c" },
      { key: "nuclear", label: "Nuclear", color: "#6a5acd" },
      { key: "other", label: "Other & storage", color: "#7a7a7a" },
    ];
    if (opts.extraLine && opts.extraLine.mw != null && isFinite(Number(opts.extraLine.mw))) {
      const mw = Number(opts.extraLine.mw);
      groups.push({ key: "interconnection", label: "Imports", color: opts.extraLine.color });
      rows.push({ label: mw >= 0 ? opts.extraLine.label + " (importing)" : opts.extraLine.label + " (exporting)", mw, group: "interconnection", color: opts.extraLine.color });
    }
    const asOf = opts.mixTs ? ` (mix as of ${formatClockTime(opts.mixTs)})` : "";
    const summary = renderMixBreakdown(rows, Object.assign({}, opts, {
      groups,
      decimals: 2,
      // "supply" only when an import is actually part of the total.
      centerSub: (opts.extraLine && Number(opts.extraLine.mw) > 0) ? "supply" : "generated",
      captionFn: (sm) => {
        const imported = sm.positive.filter((r) => r.group === "interconnection").reduce((s, r) => s + r.mw, 0);
        const generated = sm.totalMw - imported;
        return `Generation ${sm.gw(generated)}GW` + (imported > 0 ? ` plus ${sm.gw(imported)}GW imported (${sm.gw(sm.totalMw)}GW total)` : "") +
          `, live from the ${opts.sourceLabel || "ENTSO-E Transparency Platform"}${asOf}.` + (opts.captionNote ? " " + opts.captionNote : "");
      },
    }));
    return !!summary;
  }
  window.GridPreview.renderPsrMixSection = renderPsrMixSection;

  /** "14:05"-style local time for an ISO timestamp ("29 Sep, 14:05" if it
      is not today, so an older reading cannot pass for a fresh one), or "". */
  function formatClockTime(iso) {
    const d = new Date(iso);
    if (isNaN(d.getTime())) return "";
    const time = fmtTime12(d);
    return d.toDateString() === new Date().toDateString() ? time : d.toLocaleDateString("en-GB", { day: "numeric", month: "long" }) + ", " + time;
  }
  window.GridPreview.formatClockTime = formatClockTime;

  /**
   * Keeps a page's live figures current while it stays open: calls fn()
   * now, then again every intervalMs (default 5 minutes) while the tab is
   * visible, and straight away when a hidden tab becomes visible again if
   * it is due. Every page's status strip + mix section used to load exactly
   * once, so a tab left open showed an ever-older table and donut. During
   * a refresh (GridPreview._refreshing), withCachedFallback skips its
   * cached first paint - the page is already showing those figures - and
   * treats them as "already showing something", so a failed refresh
   * leaves the last good figures in place instead of blanking them.
   */
  function autoRefresh(fn, intervalMs) {
    const every = intervalMs || 5 * 60 * 1000;
    let last = Date.now();
    let running = false;
    Promise.resolve().then(fn);
    function tick() {
      if (running || document.visibilityState === "hidden" || Date.now() - last < every) return;
      last = Date.now();
      running = true;
      window.GridPreview._refreshing = true;
      Promise.resolve()
        .then(fn)
        .catch(() => {})
        .then(() => { window.GridPreview._refreshing = false; running = false; });
    }
    setInterval(tick, 30 * 1000);
    document.addEventListener("visibilitychange", tick);
  }
  window.GridPreview.autoRefresh = autoRefresh;

  /* ==========================================================================
     Universal chart export toolbar - Copy as text / Download PNG / Download CSV
     ==========================================================================
     Attached automatically by renderLineChart, renderStackedAreaChart,
     renderBarChart and renderDonutChart below, rather than requiring every
     page to hand-wire its own buttons - that is what makes "every graph and
     donut on the site" tractable: enhance the ~5 shared render functions
     once, and every one of the ~100 chart calls across every page picks it
     up automatically. Two exclusions, both automatic:
       - a canvas marked aria-hidden="true" (the small inline sparklines
         next to the headline stats) has nothing meaningful to export, so
         it is skipped;
       - a call that passes opts.noExport (e.g. history.html's charts,
         which already have their own more capable per-metric Copy data
         button plus a combined whole-range CSV/JSON download - adding a
         second, near-duplicate toolbar there would just be clutter).
     A page can still pass opts.exportTitle for a nicer label; if it
     does not, deriveChartTitle() below finds one from the page's own
     heading structure, which every page already has consistently (an <h2>
     for the section, an <h3> inside chart-card for an individual metric).
  */

  /** Finds a human title for a chart with no explicit opts.exportTitle, by
      walking up from the canvas to the nearest heading, prefixed with the
      page's own <h1> so "Demand" (say) does not read as ambiguous once
      copied/downloaded somewhere that is lost the page context. */
  function deriveChartTitle(canvas) {
    const card = canvas.closest(".chart-card");
    const cardHeading = card && card.querySelector("h3, h4");
    const section = canvas.closest("section, .panel");
    const sectionHeading = section && section.querySelector("h2");
    const heading = cardHeading || sectionHeading;
    const h1 = document.querySelector("h1");
    const pageTitle = (h1 && h1.textContent.trim()) || document.title.split("|")[0].trim();
    const sectionTitle = heading ? heading.textContent.trim() : "";
    return sectionTitle && sectionTitle !== pageTitle ? `${pageTitle} – ${sectionTitle}` : pageTitle;
  }

  function slugify(s) {
    return String(s).toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/(^-+|-+$)/g, "") || "chart";
  }

  function todayStamp() {
    return new Date().toISOString().slice(0, 10);
  }

  /** Same narrative style as mixTableToText (e.g. "Wind: 10.33 GW - 35.8%"),
      but built straight from the arrays already in hand at chart-render
      time rather than re-parsing an HTML table - works for any donut/bar
      snapshot, not just the one page with a matching <table>. Deliberately
      plain text with no markdown syntax (no *bold*, no #headings|), so it
      pastes cleanly into a forum/social comment box or a plain-text email
      exactly as written, rather than leaving stray asterisks visible on a
      site that does not render markdown. */
  function categoriesToText(labels, values, unit, title) {
    const lines = [];
    if (title) lines.push(title, "");
    const total = values.reduce((a, b) => a + Math.max(Number(b) || 0, 0), 0) || 1;
    labels.forEach((l, i) => {
      const v = values[i];
      const pct = ((Math.max(Number(v) || 0, 0) / total) * 100).toFixed(1);
      lines.push(`${l}: ${formatAxisValue(v, unit)} (${pct}%)`);
    });
    lines.push("", "Source: https://gridpulse.info/");
    return lines.join("\n");
  }

  function categoriesToCsv(labels, values, unit) {
    const header = ["label", unit ? `value (${unit})` : "value", "share of total (%)"];
    const total = values.reduce((a, b) => a + Math.max(Number(b) || 0, 0), 0) || 1;
    const lines = [header.map(csvField).join(",")];
    labels.forEach((l, i) => {
      const pct = ((Math.max(Number(values[i]) || 0, 0) / total) * 100).toFixed(1);
      lines.push([l, values[i], pct].map(csvField).join(","));
    });
    return lines.join("\r\n");
  }

  /** Legend entries for the PNG card - one swatch + label per category
      (donut/bar), or one swatch + series name per line in a multi-column
      time series (stacked area, or a line chart with a compare overlay).
      A single-column time series (the common case: one metric, one line)
      gets no legend at all - there is nothing to distinguish, and the
      chart's own title already says what it is. */
  function buildLegendEntries(spec) {
    if (spec.kind === "categories") {
      const { labels, values, colors, unit, decimals, pctDecimals } = spec.data;
      const total = (values || []).reduce((a, b) => a + Math.max(Number(b) || 0, 0), 0) || 1;
      return labels.map((l, i) => {
        const frac = Math.max(Number(values[i]) || 0, 0) / total;
        const valueText = decimals != null ? `${Number(values[i]).toFixed(decimals)}${unit || ""}` : formatAxisValue(values[i], unit);
        const shareText = decimals != null ? (frac * 100).toFixed(pctDecimals != null ? pctDecimals : 1) : String(Math.round(frac * 100));
        return { color: (colors && colors[i]) || "#7a7a7a", text: `${l}: ${valueText} (${shareText}%)` };
      });
    }
    if (spec.signed) {
      const sc = signedColors();
      return [{ color: sc.pos, text: "Above the line: " + spec.signed.pos }, { color: sc.neg, text: "Below the line: " + spec.signed.neg }];
    }
    const cols = (spec.data.columns || []).filter((c) => c && c.color);
    if (cols.length > 1) {
      return cols.map((c) => ({ color: c.color, text: c.name }));
    }
    return [];
  }

  /** Wraps legend entries into as many rows as the card's width actually
      needs, measured with real canvas text metrics rather than guessing a
      fixed row length - this is what keeps every category's key legible
      on the exported image regardless of how many there are (checked
      against the site's own worst cases: Australia's ~10 fuel-type
      categories including both battery directions, and GB's own
      multi-row generation-mix table) instead of a fixed-height legend
      area that would clip or overlap once a page has more categories
      than whichever count the layout happened to be eyeballed against. */
  function layoutLegendRows(ctx, entries, maxWidth) {
    const rows = [];
    let row = [];
    let rowW = 0;
    const gap = 20;
    entries.forEach((e) => {
      const w = 16 + ctx.measureText(e.text).width + gap;
      if (row.length && rowW + w > maxWidth) {
        rows.push(row);
        row = [];
        rowW = 0;
      }
      row.push(e);
      rowW += w;
    });
    if (row.length) rows.push(row);
    return rows;
  }

  function drawLegendRows(ctx, rows, x0, y0, rowH, palette) {
    ctx.font = '600 13px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
    ctx.textBaseline = "middle";
    rows.forEach((row, ri) => {
      let x = x0;
      const y = y0 + ri * rowH + rowH / 2;
      row.forEach((e) => {
        ctx.fillStyle = e.color;
        ctx.beginPath();
        ctx.arc(x + 5, y, 5, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = palette.text;
        ctx.fillText(e.text, x + 16, y + 1);
        x += 16 + ctx.measureText(e.text).width + 20;
      });
    });
  }

  /** Fits a title onto one line, truncating with an ellipsis only in the
      rare case a page heading plus section heading combined do not fit -
      simpler and more predictable than wrapping to a second line and
      having to recompute every other element's y-offset underneath it. */
  function fitTitleLine(ctx, text, maxWidth) {
    if (ctx.measureText(text).width <= maxWidth) return text;
    let t = text;
    while (t.length > 1 && ctx.measureText(t + "…").width > maxWidth) t = t.slice(0, -1);
    return t + "…";
  }

  /**
   * Renders a shareable PNG of a live chart: the chart's own current canvas
   * pixels (already theme-correct, already showing real data - captured via
   * drawImage rather than redrawn from scratch, so this works identically
   * for every chart type without reimplementing each one's drawing code a
   * second time) plus a title/timestamp header, a legend sized to fit every
   * category, and a source footer. Card height is computed AFTER measuring
   * how many legend rows are actually needed, so nothing is ever clipped -
   * see layoutLegendRows() above.
   */
  function downloadChartSnapshotPng(canvas, spec) {
    const scale = 2;
    const cardW = 900;
    const pad = 28;
    const dark = themeAwareColors().dark;
    const palette = dark
      ? { bg: "#14161c", border: "#333846", text: "#edeef2", muted: "#a2a8b6" }
      : { bg: "#ffffff", border: "#dde1e7", text: "#171a21", muted: "#5b6270" };

    const srcW = canvas.clientWidth || canvas.width || 600;
    const srcH = canvas.clientHeight || canvas.height || 330;
    const chartW = cardW - pad * 2;
    const chartH = Math.round(chartW * Math.min(srcH / srcW, 0.75));

    const measureCanvas = document.createElement("canvas");
    const mctx = measureCanvas.getContext("2d");
    mctx.font = '600 13px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
    const legendEntries = buildLegendEntries(spec);
    const legendRows = layoutLegendRows(mctx, legendEntries, chartW);

    const headerH = 74;
    const legendRowH = 24;
    const legendH = legendRows.length ? legendRows.length * legendRowH + 14 : 0;
    const footerH = 30;
    const cardH = headerH + chartH + legendH + footerH + pad;

    const out = document.createElement("canvas");
    out.width = cardW * scale;
    out.height = cardH * scale;
    const ctx = out.getContext("2d");
    ctx.scale(scale, scale);

    ctx.fillStyle = palette.bg;
    ctx.fillRect(0, 0, cardW, cardH);

    ctx.textBaseline = "alphabetic";
    ctx.fillStyle = palette.text;
    ctx.font = '700 18px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
    ctx.fillText(fitTitleLine(ctx, spec.title || "Grid Pulse", cardW - pad * 2), pad, 32);
    ctx.fillStyle = palette.muted;
    ctx.font = '500 12px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
    ctx.fillText("gridpulse.info – as of " + new Date().toLocaleString(), pad, 52);
    ctx.strokeStyle = palette.border;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(pad, headerH - 8);
    ctx.lineTo(cardW - pad, headerH - 8);
    ctx.stroke();

    ctx.drawImage(canvas, pad, headerH, chartW, chartH);

    if (legendRows.length) {
      drawLegendRows(ctx, legendRows, pad, headerH + chartH + 14, legendRowH, palette);
    }

    ctx.fillStyle = palette.muted;
    ctx.font = '400 11px "Open Sans", -apple-system, "Segoe UI", Roboto, sans-serif';
    ctx.fillText("Source: https://gridpulse.info/", pad, cardH - 12);

    out.toBlob((blob) => {
      if (!blob) return;
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `gridpulse-${spec.base}-${todayStamp()}.png`;
      a.style.position = "fixed";
      a.style.opacity = "0";
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    }, "image/png");
  }
  window.GridPreview.downloadChartSnapshotPng = downloadChartSnapshotPng;

  /** Creates (once) the Copy as text / Download PNG / Download CSV row for
      a chart, inserted as a sibling right after its .chart-wrap - not
      inside it, since .chart-wrap has its own fixed aspect-ratio/height
      CSS plus the is-loading::before shimmer overlay, and sibling buttons
      inside that box would get squeezed or clipped by that layout. */
  function ensureChartToolbar(canvas) {
    if (canvas._exportToolbar) return canvas._exportToolbar;
    const wrap = canvas.closest(".chart-wrap") || canvas.parentElement;
    const toolbar = document.createElement("div");
    toolbar.className = "chart-toolbar";
    toolbar.innerHTML = [
      ["copy", "Copy as text"],
      ["png", "Download PNG"],
      ["csv", "Download CSV"],
      ["link", "Copy link"],
    ].map(([action, label]) => (
      `<button type="button" class="copy-btn chart-toolbar__btn" data-chart-action="${action}">` +
      `<span data-copy-label>${label}</span></button>`
    )).join("");
    wrap.insertAdjacentElement("afterend", toolbar);
    canvas._exportToolbar = toolbar;
    return toolbar;
  }

  /** The single entry point every render*Chart function below calls. Safe
      to call on every (re-)render of the same canvas: toolbar creation and
      button wiring happen once (guarded by toolbar.dataset.wired), while
      canvas._exportSpec - what the buttons actually read when clicked - is
      refreshed every time, so a chart that is re-rendered with new data
      (illustrative placeholder swapped for live data, a range-tab switch
      reusing the same canvas) always exports what is currently on screen. */
  function attachChartExport(canvas, spec) {
    if (!spec || spec.noExport) return;
    if (canvas.getAttribute("aria-hidden") === "true") return;
    const title = spec.title || deriveChartTitle(canvas);
    const base = spec.filenameBase || slugify(title);
    canvas._exportSpec = Object.assign({}, spec, { title, base });

    const toolbar = ensureChartToolbar(canvas);
    if (toolbar.dataset.wired) return;
    toolbar.dataset.wired = "1";

    const copyBtn = toolbar.querySelector('[data-chart-action="copy"]');
    const pngBtn = toolbar.querySelector('[data-chart-action="png"]');
    const csvBtn = toolbar.querySelector('[data-chart-action="csv"]');

    copyBtn.addEventListener("click", () => {
      const s = canvas._exportSpec;
      const text = s.kind === "categories"
        ? categoriesToText(s.data.labels, s.data.values, s.data.unit, s.title)
        : seriesToText(s.data.times, s.data.labels, s.data.columns, { title: s.title });
      copyText(text).then(() => announceCopyResult(copyBtn, true)).catch(() => announceCopyResult(copyBtn, false));
    });

    csvBtn.addEventListener("click", () => {
      const s = canvas._exportSpec;
      const csv = s.kind === "categories"
        ? categoriesToCsv(s.data.labels, s.data.values, s.data.unit)
        : seriesToCsv(s.data.times, s.data.labels, s.data.columns, {});
      downloadTextFile(`gridpulse-${s.base}-${todayStamp()}.csv`, csv, "text/csv");
      announceCopyResult(csvBtn, true);
    });

    pngBtn.addEventListener("click", () => {
      downloadChartSnapshotPng(canvas, canvas._exportSpec);
      announceCopyResult(pngBtn, true);
    });

    const linkBtn = toolbar.querySelector('[data-chart-action="link"]');
    if (linkBtn) {
      linkBtn.addEventListener("click", () => {
        copyText(chartLink(canvas)).then(() => announceCopyResult(linkBtn, true)).catch(() => announceCopyResult(linkBtn, false));
      });
    }
  }

  /** A link that reopens this chart: the page, its query (the range tab
      is already kept there - see initTabs - plus ?date= on day pages) and
      the nearest heading above the chart as the #anchor. */
  function chartLink(canvas) {
    let anchor = "";
    const section = canvas.closest("section[aria-labelledby], section[id], [data-chart-anchor]");
    if (section) {
      anchor = section.getAttribute("data-chart-anchor") || section.getAttribute("aria-labelledby") || section.id || "";
    }
    const u = new URL(window.location.href);
    u.hash = anchor ? "#" + anchor : "";
    return u.toString();
  }
  window.GridPreview.chartLink = chartLink;
  window.GridPreview.attachChartExport = attachChartExport;

  /* ---------- External links: open in a new tab, mark it clearly ---------- */
  function externalizeLinks() {
    const here = window.location.hostname;
    document.querySelectorAll('a[href^="http://"], a[href^="https://"]').forEach((a) => {
      if (a.hostname && here && a.hostname === here) return; // internal absolute link
      if (a.dataset.extDone) return;
      a.dataset.extDone = "1";
      a.setAttribute("target", "_blank");
      a.setAttribute("rel", "noopener noreferrer");
      a.classList.add("ext-link");
      const icon = document.createElement("span");
      icon.className = "ext-link__icon";
      icon.setAttribute("aria-hidden", "true");
      icon.textContent = " ↗";
      const sr = document.createElement("span");
      sr.className = "visually-hidden";
      sr.textContent = " (opens in new window)";
      a.appendChild(icon);
      a.appendChild(sr);
    });
  }

  /* ---------- Copy-table-as-text buttons ---------- */
  // Turns a .mix table (Source / GW / % - or similar 2-3 column layout) into
  // a plain-text block using its <thead> labels as units, so copied text
  // reads naturally (e.g. "Wind: 10.33 GW - 35.8%") rather than dumping
  // raw HTML. Indents non-group rows under their group-row parent.
  function mixTableToText(table, title) {
    const headers = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
    const lines = [];
    if (title) lines.push(title, '');
    // Skips rows inside a hidden <tbody> (e.g. index.html's battery preview
    // row while its toggle is off), so exports match what is on screen.
    table.querySelectorAll('tbody:not([hidden]) tr').forEach((tr) => {
      const cells = Array.from(tr.children).map((c) => c.textContent.replace(/\s+/g, ' ').trim());
      if (!cells[0]) return;
      const indent = tr.classList.contains('group-row') ? '' : '  ';
      const valueParts = [];
      for (let i = 1; i < cells.length; i++) {
        if (cells[i] === '' || cells[i] === '-' || cells[i] === '-') continue;
        const unit = headers[i] || '';
        valueParts.push(unit === '%' ? `${cells[i]}%` : `${cells[i]}${unit ? ' ' + unit : ''}`);
      }
      lines.push(indent + cells[0] + (valueParts.length ? ': ' + valueParts.join(' - ') : ''));
    });
    lines.push('', 'Source: https://gridpulse.info/');
    return lines.join('\n');
  }
  window.GridPreview.mixTableToText = mixTableToText;

  /** Turns any data table (thead th headers + tbody rows) into a CSV
      string - a generic counterpart to mixTableToText above, used by
      [data-csv-table] buttons wherever a page shows a table but has no
      backing labels/values array in JS to hand to categoriesToCsv
      directly (the Records/Context and Notable moments tables, e.g.,
      exist only as server-rendered/DOM content, not JS arrays). */
  function tableToCsv(table) {
    const headers = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
    const lines = [headers.map(csvField).join(',')];
    // Skips rows inside a hidden <tbody> (e.g. index.html's battery preview
    // row while its toggle is off), so exports match what is on screen.
    table.querySelectorAll('tbody:not([hidden]) tr').forEach((tr) => {
      const cells = Array.from(tr.children).map((c) => c.textContent.replace(/\s+/g, ' ').trim());
      if (!cells[0]) return;
      lines.push(cells.map(csvField).join(','));
    });
    return lines.join('\r\n');
  }
  window.GridPreview.tableToCsv = tableToCsv;

  /** Same job as mixTableToText/tableToCsv above, but for a Records/Context
      or Notable moments list (<ul class="records-list"><li><span>label</span>
      <strong>value</strong></li>...</ul>) rather than a <table> - shared by
      every "Copy as text" button on those sections, sitewide. */
  function listToText(ul, title) {
    const lines = [];
    if (title) lines.push(title, "");
    ul.querySelectorAll("li").forEach((li) => {
      const label = li.querySelector("span");
      const value = li.querySelector("strong");
      if (!label) return;
      const labelText = label.textContent.replace(/\s+/g, " ").trim();
      const valueText = value ? value.textContent.replace(/\s+/g, " ").trim() : "";
      lines.push(valueText ? `${labelText}: ${valueText}` : labelText);
    });
    lines.push("", "Source: https://gridpulse.info/");
    return lines.join("\n");
  }
  window.GridPreview.listToText = listToText;

  function listToCsv(ul) {
    const lines = ["label,value"];
    ul.querySelectorAll("li").forEach((li) => {
      const label = li.querySelector("span");
      const value = li.querySelector("strong");
      if (!label) return;
      const labelText = label.textContent.replace(/\s+/g, " ").trim();
      const valueText = value ? value.textContent.replace(/\s+/g, " ").trim() : "";
      lines.push([labelText, valueText].map(csvField).join(","));
    });
    return lines.join("\r\n");
  }
  window.GridPreview.listToCsv = listToCsv;

  /* Turns one or more time-series columns into a tab-separated block, one
     row per timestamp - pastes straight into a spreadsheet as proper
     columns, unlike mixTableToText's narrative style above (which is meant
     for the single-snapshot mix table, not a run of data points). Used by
     the History page's "Copy data" buttons.
       times/labels: parallel arrays of ISO timestamps / axis labels - times
         is preferred (full precision); labels is the fallback.
       columns: [{ name, unit, values }, ...] - one or more series sharing
         the same times/labels.
     Returns a string ready for copyText(). */
  function seriesToText(times, labels, columns, opts) {
    opts = opts || {};
    const lines = [];
    if (opts.title) lines.push(opts.title, '');
    const header = ['Date/time', ...columns.map((c) => (c.unit ? `${c.name} (${c.unit})` : c.name))];
    lines.push(header.join('\t'));
    const n = (times && times.length) || (labels && labels.length) || (columns[0] && columns[0].values.length) || 0;
    for (let i = 0; i < n; i++) {
      const when = (times && times[i]) ? formatTooltipDateTime(times[i]) : (labels ? labels[i] : '');
      const row = [when, ...columns.map((c) => (c.values[i] == null ? '' : c.values[i]))];
      lines.push(row.join('\t'));
    }
    lines.push('', 'Source: https://gridpulse.info/');
    return lines.join('\n');
  }
  window.GridPreview.seriesToText = seriesToText;

  /** One CSV field, quoted (and internal quotes doubled) only if it actually needs it - commas, quotes or newlines. */
  function csvField(v) {
    const s = v == null ? '' : String(v);
    return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  }

  /** Same shape/inputs as seriesToText, but returns a proper CSV string (comma-separated, quoted where needed, ISO timestamps rather than formatted ones - a downloaded file is for feeding into another tool, not for reading on screen). */
  function seriesToCsv(times, labels, columns, opts) {
    opts = opts || {};
    const lines = [];
    const header = ['timestamp', ...columns.map((c) => (c.unit ? `${c.name} (${c.unit})` : c.name))];
    lines.push(header.map(csvField).join(','));
    const n = (times && times.length) || (labels && labels.length) || (columns[0] && columns[0].values.length) || 0;
    for (let i = 0; i < n; i++) {
      const when = (times && times[i]) || (labels ? labels[i] : '');
      const row = [when, ...columns.map((c) => (c.values[i] == null ? '' : c.values[i]))];
      lines.push(row.map(csvField).join(','));
    }
    return lines.join('\r\n');
  }
  window.GridPreview.seriesToCsv = seriesToCsv;

  /** Same shape/inputs as seriesToText, but returns a JSON string: one object per timestamp, one key per column (by name), plus a source/generated-at footer. */
  function seriesToJson(times, labels, columns, opts) {
    opts = opts || {};
    const n = (times && times.length) || (labels && labels.length) || (columns[0] && columns[0].values.length) || 0;
    const points = [];
    for (let i = 0; i < n; i++) {
      const point = { t: (times && times[i]) || (labels ? labels[i] : null) };
      columns.forEach((c) => { point[c.name] = c.values[i] == null ? null : c.values[i]; });
      points.push(point);
    }
    return JSON.stringify({
      title: opts.title || null,
      units: Object.fromEntries(columns.map((c) => [c.name, c.unit || null])),
      generatedAt: new Date().toISOString(),
      source: 'https://gridpulse.info/',
      points,
    }, null, 2);
  }
  window.GridPreview.seriesToJson = seriesToJson;

  /**
   * Combines several independently-fetched series (each its own
   * {times, values, unit}, keyed by name) onto one shared, sorted time
   * axis - the shape seriesToCsv/seriesToJson expect. Each metric on a
   * History range panel is fetched as its own API call and can come back
   * with a slightly different point count/timing (a slow source, a gap in
   * one table but not another), so a naive index-by-index zip would
   * silently misalign values against the wrong timestamp. This instead
   * unions every series's own timestamps, sorts them, and looks each
   * metric's value up by its own timestamp for every row - metrics with no
   * point at a given timestamp just get a null in that cell rather than a
   * value borrowed from a neighbouring row.
   */
  function mergeSeriesByTime(seriesMap) {
    const allTimes = new Set();
    Object.keys(seriesMap).forEach((name) => {
      const s = seriesMap[name];
      if (s && s.times) s.times.forEach((t) => allTimes.add(t));
    });
    const times = Array.from(allTimes).sort();
    const columns = Object.keys(seriesMap).map((name) => {
      const s = seriesMap[name];
      const byTime = {};
      if (s && s.times) s.times.forEach((t, i) => { byTime[t] = s.values[i]; });
      return { name, unit: s && s.unit, values: times.map((t) => (t in byTime ? byTime[t] : null)) };
    });
    return { times, columns };
  }
  window.GridPreview.mergeSeriesByTime = mergeSeriesByTime;

  /** Turns a topic's own output series (e.g. fossil GW) plus the sitewide
      total generation series into a third series of that topic's %
      share of generation at each matching point in time - used by the
      "Explore by energy type" pages' "Share of total generation" charts.
      Matches points by their ISO timestamp (not array index): the two
      input series both come from api/series.php's identical bucketing
      logic over the same range, so their time-sets are normally the same,
      but matching by time rather than assuming index alignment is a small
      amount of extra safety for the rare bucket that only one of the two
      queries returns a row for (e.g. a fuel type with a single zero-mw
      reading that got excluded upstream). Returns null if either input is
      null, so callers can treat "cannot compute a share" the same as any
      other "no data yet" case. */
  function computeShareSeries(partSeries, totalSeries) {
    if (!partSeries || !totalSeries) return null;
    const totalByTime = {};
    totalSeries.times.forEach((t, i) => { totalByTime[t] = totalSeries.values[i]; });
    const times = [];
    const labels = [];
    const values = [];
    partSeries.times.forEach((t, i) => {
      if (!(t in totalByTime)) return;
      const total = totalByTime[t];
      const part = partSeries.values[i];
      if (typeof total !== "number" || total <= 0 || typeof part !== "number") return;
      times.push(t);
      labels.push(partSeries.labels[i]);
      values.push((part / total) * 100);
    });
    if (!times.length) return null;
    return { times, labels, values, unit: "%" };
  }
  window.GridPreview.computeShareSeries = computeShareSeries;

  /** Triggers a browser download of in-memory text as a file - no server round trip, just a Blob + a momentary off-screen link click. Shared by every "download data" button on the site. */
  /* Source and licence lines at the top of every CSV the site makes.
     A page can name its own with data-csv-source / data-csv-licence on
     <body>; otherwise the page's file name picks one below. */
  const CSV_ATTRIBUTION = {
    gb: ["Elexon Insights Solution (BMRS), NESO Data Portal and the Carbon Intensity API (NESO and the University of Oxford)",
         "Contains BMRS data © Elexon Limited copyright and database right " + new Date().getFullYear() + ". NESO data under the NESO Open Data Licence."],
    ie: ["EirGrid Smart Grid Dashboard (all-island) and the ENTSO-E Transparency Platform (price)",
         "EirGrid data reused under EirGrid's website terms. ENTSO-E data reused under the ENTSO-E Transparency Platform terms and conditions."],
    entsoe: ["ENTSO-E Transparency Platform", "Reused under the ENTSO-E Transparency Platform terms and conditions. Source: ENTSO-E."],
    us: ["U.S. Energy Information Administration (EIA)", "EIA data is public domain. Source: U.S. Energy Information Administration."],
    ca: ["IESO (Independent Electricity System Operator, Ontario) public reports", "Reused under the IESO's terms of use for public reports."],
    au: ["Open Electricity (openelectricity.org.au)", "CC BY-NC 4.0 - non-commercial use only. Attribution: Open Electricity."],
  };
  const CSV_PAGE_KIND = {
    "ireland.html": "ie", "usa.html": "us", "canada.html": "ca", "australia.html": "au",
    "france.html": "entsoe", "nl.html": "entsoe", "belgium.html": "entsoe", "norway.html": "entsoe", "denmark.html": "entsoe",
    "germany.html": "entsoe", "spain.html": "entsoe", "italy.html": "entsoe", "sweden.html": "entsoe", "portugal.html": "entsoe",
    "poland.html": "entsoe", "eu.html": "entsoe",
  };
  function csvAttribution() {
    const b = document.body ? document.body.dataset : {};
    let src = b.csvSource, lic = b.csvLicence;
    if (!src) {
      const file = (window.location.pathname.split("/").pop() || "index.html");
      const kind = CSV_PAGE_KIND[file] || "gb";
      [src, lic] = CSV_ATTRIBUTION[kind];
    }
    const lines = ["Source: " + src];
    if (lic) lines.push("Licence: " + lic);
    lines.push("From: Grid Pulse, " + window.location.origin + window.location.pathname + ", downloaded " + new Date().toISOString().slice(0, 16).replace("T", " ") + " UTC");
    return lines.map(csvField).join("\r\n") + "\r\n\r\n";
  }
  window.GridPreview.csvAttribution = csvAttribution;

  function downloadTextFile(filename, content, mimeType) {
    if (mimeType && /csv/.test(mimeType) && typeof content === "string" && content.indexOf("Source: ") !== 0 && content.indexOf('"Source: ') !== 0) {
      content = csvAttribution() + content;
    }
    const blob = new Blob([content], { type: mimeType || 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.style.position = 'fixed';
    a.style.opacity = '0';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    // Revoked after a tick rather than immediately - some browsers cancel
    // the download if the object URL disappears before the click finishes
    // being handled.
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
  window.GridPreview.downloadTextFile = downloadTextFile;

  /**
   * Renders a shareable PNG "snapshot card" - generation mix donut plus a
   * few headline stats - onto an off-screen canvas and triggers a
   * download. Deliberately uses a fixed dark brand palette rather than
   * the viewer's current light/dark theme: the image is meant to be
   * shared and viewed outside the site (social media, messaging apps),
   * where the local theme setting has no meaning.
   *
   * data shape: {
   *   title: "Grid Pulse",
   *   asOf: "as of 14:32, 16 Aug 2026",
   *   sourceLabels: [...], sourceValues: [...], sourceColors: [...],
   *   renewablePct: 42,
   *   stats: [{ label, value, unit }, ...] (up to 4),
   *   filename: "gridpulse-snapshot-2026-08-16.png"
   * }
   */
  function downloadSnapshotCard(data) {
    const W = 1200, H = 630, scale = 2;
    const canvas = document.createElement("canvas");
    canvas.width = W * scale;
    canvas.height = H * scale;
    const ctx = canvas.getContext("2d");
    ctx.scale(scale, scale);

    const bg = "#14161c", panel = "#1c1f27", border = "#333846";
    const text = "#edeef2", muted = "#a2a8b6", accent = "#35c299";

    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, W, H);

    ctx.textBaseline = "alphabetic";
    ctx.fillStyle = text;
    ctx.font = "700 30px system-ui, -apple-system, Segoe UI, sans-serif";
    ctx.fillText(data.title || "Grid Pulse", 48, 64);
    ctx.fillStyle = muted;
    ctx.font = "500 16px system-ui, -apple-system, Segoe UI, sans-serif";
    ctx.fillText("gridpulse.info", 48, 88);

    ctx.textAlign = "right";
    ctx.fillStyle = muted;
    ctx.font = "500 16px system-ui, -apple-system, Segoe UI, sans-serif";
    ctx.fillText(data.asOf || "", W - 48, 64);
    ctx.textAlign = "left";

    ctx.strokeStyle = border;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(48, 108);
    ctx.lineTo(W - 48, 108);
    ctx.stroke();

    // Donut - left half, plain gradient fill (no hover state, this is a
    // static export), gap-segmented to match the site's on-page donuts.
    const cx = 250, cy = 380, outerR = 148, innerR = 90;
    const values = data.sourceValues || [];
    const labels = data.sourceLabels || [];
    const colors = data.sourceColors || [];
    const total = values.reduce((a, b) => a + b, 0) || 1;
    let angle = -Math.PI / 2;
    const rawGap = values.length > 1 ? 0.045 : 0;
    values.forEach((v, i) => {
      const sweep = (v / total) * Math.PI * 2;
      const gap = Math.min(rawGap, sweep * 0.2);
      const start = angle + gap / 2;
      const end = angle + sweep - gap / 2;
      if (end > start && v > 0) {
        const grad = ctx.createRadialGradient(cx, cy, innerR, cx, cy, outerR);
        grad.addColorStop(0, hexToRgba(colors[i], 0.55));
        grad.addColorStop(1, colors[i]);
        ctx.beginPath();
        ctx.arc(cx, cy, outerR, start, end);
        ctx.arc(cx, cy, innerR, end, start, true);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();
      }
      angle += sweep;
    });

    ctx.textAlign = "center";
    ctx.fillStyle = text;
    ctx.font = "700 26px system-ui, -apple-system, Segoe UI, sans-serif";
    ctx.fillText(Math.round(data.renewablePct || 0) + "%", cx, cy - 2);
    ctx.fillStyle = muted;
    ctx.font = "500 13px system-ui, -apple-system, Segoe UI, sans-serif";
    ctx.fillText("renewable", cx, cy + 18);
    ctx.textAlign = "left";

    let ly = cy + outerR + 34;
    const lx0 = cx - outerR;
    labels.forEach((label, i) => {
      const pct = Math.round((values[i] / total) * 100);
      if (pct <= 0) return;
      ctx.fillStyle = colors[i];
      ctx.beginPath();
      ctx.arc(lx0 + 6, ly - 5, 6, 0, Math.PI * 2);
      ctx.fill();
      ctx.fillStyle = text;
      ctx.font = "500 14px system-ui, -apple-system, Segoe UI, sans-serif";
      ctx.fillText(label + " " + pct + "%", lx0 + 20, ly);
      ly += 22;
    });

    // Stat cards - right half, 2-column grid.
    const sx = 560, sw = W - 48 - sx, cols = 2;
    const cellW = sw / cols, cellH = 108;
    (data.stats || []).forEach((s, i) => {
      const col = i % cols, row = Math.floor(i / cols);
      const x = sx + col * cellW, y = 150 + row * cellH;
      const w = cellW - 16, h = cellH - 16;

      roundRectPath(ctx, x, y, w, h, 10);
      ctx.fillStyle = panel;
      ctx.fill();
      ctx.strokeStyle = border;
      ctx.lineWidth = 1;
      ctx.stroke();

      ctx.fillStyle = muted;
      ctx.font = "600 12px system-ui, -apple-system, Segoe UI, sans-serif";
      ctx.fillText(String(s.label || "").toUpperCase(), x + 18, y + 28);

      ctx.fillStyle = accent;
      ctx.font = "700 28px system-ui, -apple-system, Segoe UI, sans-serif";
      ctx.fillText(s.value, x + 18, y + 62);

      if (s.unit) {
        const valWidth = ctx.measureText(s.value).width;
        ctx.fillStyle = muted;
        ctx.font = "500 14px system-ui, -apple-system, Segoe UI, sans-serif";
        ctx.fillText(s.unit, x + 18 + valWidth + 6, y + 62);
      }
    });

    ctx.fillStyle = muted;
    ctx.font = "400 13px system-ui, -apple-system, Segoe UI, sans-serif";
    ctx.fillText("Snapshot generated " + new Date().toLocaleString(), 48, H - 28);

    canvas.toBlob((blob) => {
      if (!blob) return;
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = data.filename || "gridpulse-snapshot.png";
      a.style.position = "fixed";
      a.style.opacity = "0";
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    }, "image/png");
  }
  window.GridPreview.downloadSnapshotCard = downloadSnapshotCard;

  async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return;
    }
    // Fallback for non-HTTPS/older browsers: a hidden, selected textarea + execCommand.
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.top = '0';
    ta.style.left = '0';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    if (!ok) throw new Error('execCommand copy failed');
  }
  window.GridPreview.copyText = copyText;

  function announceCopyResult(btn, ok) {
    const label = btn.querySelector('[data-copy-label]');
    const original = label ? label.getAttribute('data-original') || label.textContent : null;
    if (label && !label.getAttribute('data-original')) label.setAttribute('data-original', label.textContent);
    if (label) label.textContent = ok ? 'Copied!' : 'Copy failed';
    btn.classList.toggle('is-copied', ok);
    btn.classList.toggle('is-copy-failed', !ok);
    clearTimeout(btn._copyResetTimer);
    btn._copyResetTimer = setTimeout(() => {
      if (label && original) label.textContent = original;
      btn.classList.remove('is-copied', 'is-copy-failed');
    }, 1800);
  }
  window.GridPreview.announceCopyResult = announceCopyResult;

  function initCopyButtons() {
    document.querySelectorAll('[data-copy-table]').forEach((btn) => {
      const table = document.getElementById(btn.getAttribute('data-copy-table'));
      if (!table) return;
      btn.addEventListener('click', () => {
        const title = btn.getAttribute('data-copy-title') || '';
        const text = mixTableToText(table, title);
        copyText(text)
          .then(() => announceCopyResult(btn, true))
          .catch(() => announceCopyResult(btn, false));
      });
    });
    document.querySelectorAll('[data-csv-table]').forEach((btn) => {
      const table = document.getElementById(btn.getAttribute('data-csv-table'));
      if (!table) return;
      btn.addEventListener('click', () => {
        const csv = tableToCsv(table);
        const base = slugify(btn.getAttribute('data-csv-title') || table.id || 'table');
        downloadTextFile(`gridpulse-${base}-${todayStamp()}.csv`, csv, 'text/csv');
        announceCopyResult(btn, true);
      });
    });
    document.querySelectorAll('[data-copy-list]').forEach((btn) => {
      const list = document.getElementById(btn.getAttribute('data-copy-list'));
      if (!list) return;
      btn.addEventListener('click', () => {
        const title = btn.getAttribute('data-copy-title') || '';
        const text = listToText(list, title);
        copyText(text)
          .then(() => announceCopyResult(btn, true))
          .catch(() => announceCopyResult(btn, false));
      });
    });
    document.querySelectorAll('[data-csv-list]').forEach((btn) => {
      const list = document.getElementById(btn.getAttribute('data-csv-list'));
      if (!list) return;
      btn.addEventListener('click', () => {
        const csv = listToCsv(list);
        const base = slugify(btn.getAttribute('data-csv-title') || list.id || 'list');
        downloadTextFile(`gridpulse-${base}-${todayStamp()}.csv`, csv, 'text/csv');
        announceCopyResult(btn, true);
      });
    });
  }

  /* ---------- Toast notifications ----------
     A small, generic toast system - stacks in the bottom-right corner (full-
     width at the bottom on narrow screens), auto-dismisses, and can also be
     dismissed by hand. Used below by the data-pipeline status check to flag
     genuine problems and successful data updates without the user having to
     go looking at the small footer pill.

     showToast(message, type) - type is "success" | "error" | "info",
     defaulting to "info". Safe to call before the page has finished
     loading; the container is created lazily on first use. */
  let toastContainer = null;
  function showToast(message, type) {
    if (!toastContainer) {
      toastContainer = document.createElement("div");
      toastContainer.className = "toast-container";
      toastContainer.setAttribute("aria-live", "polite");
      toastContainer.setAttribute("aria-atomic", "false");
      document.body.appendChild(toastContainer);
    }

    const toast = document.createElement("div");
    toast.className = "toast toast--" + (type || "info");
    toast.setAttribute("role", type === "error" ? "alert" : "status");

    const text = document.createElement("span");
    text.className = "toast__text";
    text.textContent = message;
    toast.appendChild(text);

    const closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.className = "toast__close";
    closeBtn.setAttribute("aria-label", "Dismiss notification");
    closeBtn.innerHTML = "&times;";
    toast.appendChild(closeBtn);

    function remove() {
      toast.classList.add("is-leaving");
      toast.addEventListener("animationend", () => toast.remove(), { once: true });
      // Fallback in case animations are disabled/skipped for any reason.
      setTimeout(() => toast.remove(), 400);
    }
    closeBtn.addEventListener("click", remove);

    toastContainer.appendChild(toast);
    const autoDismissMs = type === "error" ? 9000 : 6000;
    setTimeout(remove, autoDismissMs);
  }

  /* Compares the data-pipeline status just fetched against what was seen on
     the previous page load (remembered in localStorage) and toasts on
     genuinely new-to-this-browser events - a fresh error, or a completed
     data update - rather than repeating the same toast on every single page
     load once things are stable.

     Deliberately toasts on the very FIRST check a browser ever makes too
     (there is nothing to compare against yet, so no prior state just counts
     as "different" from whatever's live now) - the alternative, requiring
     two page loads before ever showing a "live data updated" toast, means
     you would never see confirmation right after the pipeline starts working,
     which defeats the point. After that first toast, it naturally settles
     into only firing on actual changes (a new last_run_at, a new error).

     Falls back to doing nothing if localStorage is not available (private
     browsing, etc.); that is a minor loss of the "do not repeat" dedupe,
     never a functional problem. */
  function notifyPipelineChange(data) {
    const STORAGE_KEY = "ukgrid_pipeline_last_seen";
    let prev = null;
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (raw) prev = JSON.parse(raw);
    } catch (e) {
      /* ignore - treated as "no previous state known" below */
    }

    const current = { overall: data.overall, last_run_at: data.last_run_at || null };
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(current));
    } catch (e) {
      /* ignore - storage full/unavailable, notifications just will not dedupe */
    }

    const prevOverall = prev ? prev.overall : null;
    const prevLastRunAt = prev ? prev.last_run_at : null;

    if (data.overall === "fail" && prevOverall !== "fail") {
      const sources = data.sources || {};
      const failed = Object.keys(sources).filter((k) => sources[k] && sources[k].status !== "OK");
      showToast(
        "Data pipeline error" + (failed.length ? ": " + failed.join(", ") : "") + ". Check the footer for details.",
        "error"
      );
    } else if (data.overall === "error" && prevOverall !== "error") {
      showToast("We cannot check the data feed right now. Figures may be out of date.", "error");
    } else if (data.overall === "success" && current.last_run_at && current.last_run_at !== prevLastRunAt) {
      showToast("Live data updated.", "success");
    }
  }

  /* ---------- Data-pipeline status pill (footer) ----------
     Reports whether the backend is actually pulling data from Elexon/NESO/
     Carbon Intensity and writing it to MySQL, by reading api/status.php
     (which reports the most recent ingest_log row per source). Injected via
     JS into every page's .site-footer so all 12+ pages get it without
     editing each footer template individually.

     Three real states, matching how api/status.php reports things, plus a
     transient "checking" state while the request is in flight:
       - "success" - the backend is reachable and every source that is run
         at least once last succeeded
       - "fail"    - the backend is reachable but at least one source's
         last run ended in ERROR (e.g. an API was down, NESO's schema
         changed) - hover/focus the pill for which source and why
       - "pending" - the backend is reachable but nothing has run yet
         (fresh install, on-demand refresh has not triggered yet)
       - "error"   - could not even reach/parse api/status.php (this preview
         build with no backend at all, a missing config.php, a database
         connection failure, network error, etc.)
     If there is no .site-footer on the page at all, this does nothing. */
  function initIngestStatusPill() {
    const footerWrap = document.querySelector(".site-footer .wrap");
    // Error pages (errors/*.html) skip the data check: they can be shown at
    // any address, and a "data feed" warning there would only add confusion.
    if (!footerWrap || document.body.hasAttribute("data-no-status")) return;

    const holder = document.createElement("p");
    holder.className = "ingest-status";
    holder.innerHTML =
      '<span class="status-pill" data-status="checking">' +
      '<span class="status-pill__dot" aria-hidden="true"></span>' +
      '<span class="status-pill__label">Checking data pipeline…</span>' +
      "</span>";
    footerWrap.appendChild(holder);

    const pill = holder.querySelector(".status-pill");
    const label = holder.querySelector(".status-pill__label");

    function setState(state, text, title) {
      pill.setAttribute("data-status", state);
      label.textContent = text;
      if (title) pill.setAttribute("title", title);
      else pill.removeAttribute("title");
    }

    function timeAgo(iso) {
      if (!iso) return "";
      const mins = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
      if (mins < 1) return "just now";
      if (mins === 1) return "1 minute ago";
      if (mins < 60) return mins + " minutes ago";
      const hrs = Math.round(mins / 60);
      return hrs === 1 ? "1 hour ago" : hrs + " hours ago";
    }

    // Shared with buildApiIssueBanner()'s per-page checks below - fetchStatus()
    // caches the request, so this and any per-page banner check on the same
    // load hit api/status.php once between them, not twice.
    (window.GridData ? window.GridData.fetchStatus() : Promise.resolve(null)).then((data) => {
      if (!data) {
        setState("error", "Data pipeline: status unavailable");
        notifyPipelineChange({ overall: "error", last_run_at: null });
        return;
      }
      if (data.overall === "success") {
        setState("success", "Data pipeline: OK" + (data.last_run_at ? " - updated " + timeAgo(data.last_run_at) : ""));
      } else if (data.overall === "fail") {
        const sources = data.sources || {};
        const failed = Object.keys(sources).filter((k) => sources[k] && sources[k].status !== "OK");
        setState(
          "fail",
          "Data pipeline: error" + (failed.length ? " (" + failed.join(", ") + ")" : ""),
          failed.map((k) => k + ": " + (sources[k].message || "unknown error")).join("\n")
        );
      } else {
        setState("pending", "Data pipeline: no data yet");
      }
      notifyPipelineChange(data);
    });
  }

  /* ---------- Scroll-reveal animation for panels/cards ---------- */
  function initReveal() {
    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches || root.classList.contains("pref-calm");
    const targets = document.querySelectorAll(".panel, .source-card");
    if (reduceMotion || !("IntersectionObserver" in window)) {
      targets.forEach((el) => el.classList.add("is-visible"));
      return;
    }
    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry, i) => {
          if (entry.isIntersecting) {
            entry.target.style.animationDelay = (i % 4) * 0.06 + "s";
            entry.target.classList.add("is-visible");
            io.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.12, rootMargin: "0px 0px -40px 0px" }
    );
    targets.forEach((el) => io.observe(el));
  }

  /* Every page sets live figures by writing straight into an element whose
     id starts with "stat-" (id="stat-demand", id="stat-price", the "Data as
     of" id="stat-time" line, etc.) once a background fetch resolves. That is
     a silent DOM write - nothing tells the reader a number just moved. This
     watches every such element for a genuine text change and toggles
     .stat-refresh-pulse (see style.css) to give it a brief, self-clearing
     flash. Deliberately excludes ids ending "-band" (the traffic-light dot
     text, which toggles [hidden] rather than refreshing a value) since that
     already has its own visual treatment. */
  /* Every plain-text "Loading…" placeholder on the site (chart captions,
     table rows, "Updated" lines, the "Data as of" tile) gets the same
     gentle pulse, and loses it the moment its text is replaced - whether
     the page writes real data, an error, or "Figures appear here when the next reading arrives." Done
     here once rather than in every page's own script, so a placeholder a
     page adds later (e.g. a caption reset to "Loading…" on a tab switch)
     is covered automatically too. */
  function initLoadingTextObserver() {
    const re = /^\s*Loading\b/;
    const check = (el) => {
      if (!el || el.nodeType !== 1 || el.closest("script, style, template, .ic-tip, .chart-tooltip")) return;
      const loading = el.children.length === 0 && re.test(el.textContent);
      if (loading) el.classList.add("is-loading-text");
      else if (el.classList.contains("is-loading-text") && !re.test(el.textContent)) el.classList.remove("is-loading-text");
    };
    document.querySelectorAll("p, td, dd, span, div, li").forEach((el) => {
      if (el.children.length === 0 && re.test(el.textContent)) check(el);
    });
    new MutationObserver((mutations) => {
      mutations.forEach((m) => {
        const el = m.type === "characterData" ? m.target.parentElement : m.target;
        check(el);
        if (m.type === "childList") m.addedNodes.forEach((n) => { if (n.nodeType === 1 && n.children.length === 0) check(n); });
      });
    }).observe(document.body, { childList: true, subtree: true, characterData: true });
  }

  /* Safety net for the pulsing "not loaded yet" stat values: if a page's
     own fetch never writes a value or an error (an older cached page
     script, a request that hangs), stop pulsing after 30 seconds and show
     a plain dash instead of animating forever. */
  function initLoadingValueTimeout() {
    const settle = () => {
      document.querySelectorAll(".stat dd.is-loading-value").forEach((dd) => {
        dd.classList.remove("is-loading-value");
        const span = dd.querySelector('[id^="stat-"]');
        if (span && span.textContent.trim() === "–") span.textContent = "-";
      });
    };
    setTimeout(settle, 30000);
    // Also settle as soon as the page itself says its first fetch is done
    // - the "Data as of" tile stops loading, or the status banner moves off
    // "Loading live data…" - so a figure that page has no value for (and
    // never writes) shows a plain dash straight away rather than pulsing.
    // Deferred a tick so values written in the same update land first.
    const watched = Array.from(document.querySelectorAll("#stat-time.is-loading-text, .data-status-banner.is-loading"));
    if (!watched.length) return;
    const obs = new MutationObserver(() => {
      if (watched.some((el) => !el.classList.contains("is-loading-text") && !el.classList.contains("is-loading"))) {
        obs.disconnect();
        setTimeout(settle, 0);
      }
    });
    watched.forEach((el) => obs.observe(el, { attributes: true, attributeFilter: ["class"] }));
  }

  function initStatRefreshAnim() {
    const targets = Array.from(document.querySelectorAll('[id^="stat-"]')).filter(
      (el) => !/-band$/.test(el.id)
    );
    if (!targets.length) return;
    const lastText = new WeakMap();
    targets.forEach((el) => {
      lastText.set(el, el.textContent);
      // Already written before this ran (e.g. a synchronous first paint
      // from cached data) - it is not loading any more.
      const dd = el.closest("dd");
      if (dd && el.textContent.trim() !== "–") dd.classList.remove("is-loading-value");
    });

    const flash = (el) => {
      el.classList.remove("stat-refresh-pulse");
      // Force a reflow so the animation restarts even if it is already
      // mid-flight from a very recent previous update.
      void el.offsetWidth;
      el.classList.add("stat-refresh-pulse");
    };

    const observer = new MutationObserver((mutations) => {
      const touched = new Set();
      mutations.forEach((m) => {
        let el = m.target.nodeType === 1 ? m.target : m.target.parentElement;
        while (el && !targets.includes(el)) el = el.parentElement;
        if (el) touched.add(el);
      });
      touched.forEach((el) => {
        const next = el.textContent;
        if (lastText.get(el) !== next) {
          lastText.set(el, next);
          const dd = el.closest("dd");
          if (dd) dd.classList.remove("is-loading-value");
          flash(el);
        }
      });
    });
    targets.forEach((el) =>
      observer.observe(el, { characterData: true, childList: true, subtree: true })
    );
  }

  /* ==========================================================================
     Design C additions (2026-10-29)
     ========================================================================== */

  /* ---------- Times: 12-hour clock, written 12.35pm (content design system) ---------- */
  function fmtTime12(d, extra) {
    const s = d.toLocaleTimeString("en-GB", Object.assign({ hour: "numeric", minute: "2-digit", hour12: true }, extra || {}));
    // "12:35 pm BST" -> "12.35pm BST"
    return s.replace(/(\d{1,2}):(\d{2})\s?([ap])\.?m\.?/i, (m, h, mm, ap) => `${h}.${mm}${ap.toLowerCase()}m`);
  }
  window.GridPreview.fmtTime12 = fmtTime12;

  /* ---------- Display and accessibility settings ---------- */
  const PREFS_KEY = "grid-live-prefs";
  const PREF_DEFAULTS = { theme: "system", size: "100", readable: false, hc: false, calm: false, underline: true, spacing: false };
  let prefs = Object.assign({}, PREF_DEFAULTS);

  function loadPrefs() {
    try {
      const saved = JSON.parse(localStorage.getItem(PREFS_KEY) || "null");
      if (saved && typeof saved === "object") Object.assign(prefs, saved);
      else {
        const oldTheme = localStorage.getItem(THEME_KEY); // the old three-state theme toggle
        if (oldTheme === "light" || oldTheme === "dark") prefs.theme = oldTheme;
      }
    } catch (e) { /* storage blocked: defaults */ }
  }
  function savePrefs() {
    try { localStorage.setItem(PREFS_KEY, JSON.stringify(prefs)); } catch (e) { /* ignore */ }
  }
  function ensureReadableFont() {
    if (document.getElementById("pref-readable-font")) return;
    const link = document.createElement("link");
    link.id = "pref-readable-font";
    link.rel = "stylesheet";
    link.href = "https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible+Next:wght@400;700&display=swap";
    document.head.appendChild(link);
  }
  function applyPrefs() {
    applyTheme(prefs.theme);
    root.style.fontSize = Number(prefs.size) > 100 ? prefs.size + "%" : "";
    root.classList.toggle("pref-readable", !!prefs.readable);
    root.classList.toggle("pref-hc", !!prefs.hc);
    root.classList.toggle("pref-calm", !!prefs.calm);
    root.classList.toggle("pref-underline", !!prefs.underline);
    root.classList.toggle("pref-spacing", !!prefs.spacing);
    if (prefs.readable) ensureReadableFont();
    window.GridPreview.reduceMotion = !!prefs.calm || window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (window.GridPreview.redrawAll) setTimeout(window.GridPreview.redrawAll, 60);
    document.dispatchEvent(new CustomEvent("grid-prefs", { detail: Object.assign({}, prefs) }));
  }

  function initPrefs() {
    loadPrefs();
    applyPrefs();
    const panel = document.getElementById("display-panel");
    const toggles = Array.from(document.querySelectorAll("[data-display-toggle]"));
    const mql = window.matchMedia("(prefers-color-scheme: dark)");
    const onSystem = () => { if (prefs.theme === "system") applyPrefs(); };
    if (mql.addEventListener) mql.addEventListener("change", onSystem);
    else if (mql.addListener) mql.addListener(onSystem);
    if (!panel || !toggles.length) return;

    function sync() {
      panel.querySelectorAll('input[name="pref-theme"]').forEach((i) => { i.checked = i.value === prefs.theme; });
      panel.querySelectorAll('input[name="pref-size"]').forEach((i) => { i.checked = i.value === String(prefs.size); });
      panel.querySelectorAll("input[data-pref]").forEach((i) => { i.checked = !!prefs[i.dataset.pref]; });
    }
    let opener = null;
    function open(btn) {
      opener = btn; sync(); panel.hidden = false;
      toggles.forEach((b) => b.setAttribute("aria-expanded", "true"));
      const first = panel.querySelector("input:checked") || panel.querySelector("input");
      if (first) first.focus();
    }
    function close(returnFocus) {
      if (panel.hidden) return;
      panel.hidden = true;
      toggles.forEach((b) => b.setAttribute("aria-expanded", "false"));
      if (returnFocus !== false && opener) opener.focus();
    }
    window.GridPreview.closeDisplayPanel = close;
    toggles.forEach((btn) => btn.addEventListener("click", (e) => {
      e.stopPropagation();
      if (panel.hidden) open(btn); else close();
    }));
    panel.addEventListener("change", (e) => {
      const i = e.target;
      if (i.name === "pref-theme") prefs.theme = i.value;
      else if (i.name === "pref-size") prefs.size = i.value;
      else if (i.dataset.pref) prefs[i.dataset.pref] = i.checked;
      savePrefs(); applyPrefs();
    });
    const reset = panel.querySelector("[data-pref-reset]");
    if (reset) reset.addEventListener("click", () => { prefs = Object.assign({}, PREF_DEFAULTS); savePrefs(); applyPrefs(); sync(); });
    const done = panel.querySelector("[data-pref-done]");
    if (done) done.addEventListener("click", () => close());
    panel.addEventListener("keydown", (e) => { if (e.key === "Escape") { e.stopPropagation(); close(); } });
    document.addEventListener("click", (e) => {
      if (!panel.hidden && !panel.contains(e.target) && !toggles.some((b) => b.contains(e.target))) close(false);
    });
  }

  /* ---------- Generation mix chart: donut first, with a Bar switch ----------
     Built by renderMixBreakdown from the same ordered rows as the table, so
     the chart, the table, the copy and the CSV always agree. The outer ring
     (or top bar) is each source; the inner ring (or bottom bar) is its
     group: renewable, non-renewable, storage or net imports, in line with
     the sources above it. Pointing at, focusing or selecting any part
     highlights it with its group and reads out the figures. */
  const MIX_VIEW_KEY = "grid-mix-view";
  const CMP_GROUPS = {
    ren: { label: "Renewable", ring: "var(--mix-ren)", tint: "var(--mix-ren-tint)", ink: "var(--mix-ren-ink)" },
    non: { label: "Non-renewable", ring: "var(--mix-non)", tint: "var(--mix-non-tint)", ink: "var(--mix-non-ink)" },
    sto: { label: "Storage", ring: "var(--mix-sto)", tint: "var(--mix-sto-tint)", ink: "var(--mix-sto-ink)" },
    imp: { label: "Net imports", ring: "var(--mix-imp)", tint: "var(--mix-imp-tint)", ink: "var(--mix-imp-ink)" },
  };
  const CMP_ORDER = ["ren", "non", "sto", "imp"];
  function cmpKeyOf(group) {
    if (group === "renewable") return "ren";
    if (group === "storage") return "sto";
    if (group === "interconnector" || group === "interconnection") return "imp";
    return "non";
  }
  function joinAnd(list) {
    if (list.length <= 1) return list.join("");
    return list.slice(0, -1).join(", ") + " and " + list[list.length - 1];
  }

  /** "Download snapshot (PNG)" for a mix chart: the donut and the bars
      together on one card, drawn from the same figures as the chart, in
      the colours of the current theme. */
  function downloadMixSnapshot(host, d) {
    const cs = getComputedStyle(host);
    const v = (name, fb) => (cs.getPropertyValue(name) || "").trim() || fb;
    const col = (ref) => { const m = /var\((--[\w-]+)\)/.exec(ref || ""); return m ? v(m[1], "#888888") : ref; };
    const pal = { bg: v("--surface", "") || v("--bg", "#ffffff"), panel: v("--panel", "#f4f5f7"), text: v("--text", "#14181f"), muted: v("--text-muted", "#3d4350"), border: v("--border", "#dde1e7"), ink: v("--ink", "#14181f") };
    const bodyFont = getComputedStyle(document.body).fontFamily || "sans-serif";
    const F = (w, px) => `${w} ${px}px ${bodyFont}`;
    const W = 1000, pad = 36, scale = 2;
    const m = document.createElement("canvas").getContext("2d");
    function wrapLines(text, font, max) {
      m.font = font;
      const words = String(text).split(" "), lines = [];
      let line = "";
      words.forEach((w) => { const t = line ? line + " " + w : w; if (line && m.measureText(t).width > max) { lines.push(line); line = w; } else line = t; });
      if (line) lines.push(line);
      return lines;
    }
    const now = new Date();
    const title = d.title || "Generation mix";
    const sub = "gridpulse.info - as of " + fmtTime12(now) + ", " + now.toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric" });
    const headLines = wrapLines(d.headline, F(600, 17), W - pad * 2);

    // Legend beside the donut: groups in two columns, row by row.
    const legX = pad + 360, legW = W - pad - legX, colW = (legW - 24) / 2;
    const groupH = (g) => 6 + 8 + 20 + 20 + g.members.length * 24 + 12;
    const legRows = [];
    for (let i = 0; i < d.groups.length; i += 2) legRows.push(d.groups.slice(i, i + 2));
    const legH = legRows.reduce((s, r) => s + Math.max(...r.map(groupH)), 0);

    // Bar labels: the same tiering as the on-screen bars, worked right to left.
    const barW = W - pad * 2, TIER = 46;
    let acc = 0;
    const placed = d.items.map((it) => {
      const x = d.totalMw > 0 ? acc / d.totalMw * barW : 0;
      acc += it.mw;
      m.font = F(700, 14); const w1 = m.measureText(it.label).width;
      const val = `${d.gw(it.mw)}GW, ${d.pct(it.mw)}%`;
      m.font = F(500, 13); const w2 = m.measureText(val).width;
      return { it, x, val, w: Math.max(w1, w2) + 16 };
    });
    const tiers = [];
    let maxTier = 0;
    for (let j = placed.length - 1; j >= 0; j--) {
      const p = placed[j];
      p.left = p.x + p.w > barW ? Math.max(0, barW - p.w) : p.x;
      let t = 0;
      for (let i = j + 1; i < placed.length; i++) if (placed[i].x < p.left + p.w) t = Math.max(t, placed[i].tier + 1);
      while ((tiers[t] || []).some((b) => p.left < b[1] && p.left + p.w > b[0])) t++;
      (tiers[t] = tiers[t] || []).push([p.left, p.left + p.w]);
      p.tier = t; maxTier = Math.max(maxTier, t);
    }
    const topH = (maxTier + 1) * TIER;
    acc = 0;
    const gPlaced = [];
    d.groups.forEach((g) => {
      const x = d.totalMw > 0 ? acc / d.totalMw * barW : 0;
      acc += g.mw;
      m.font = F(700, 14); const w1 = m.measureText(g.label).width;
      const val = `${d.gw(g.mw)}GW, ${d.pct(g.mw)}%`;
      m.font = F(500, 13); const w2 = m.measureText(val).width;
      const w = Math.max(w1, w2) + 16;
      const left = x + w > barW ? Math.max(0, barW - w) : x;
      let t = 0;
      while (gPlaced.some((q) => q.tier === t && left < q.left + q.w && left + w > q.left)) t++;
      gPlaced.push({ g, x, left, w, val, tier: t });
    });
    const bottomH = (Math.max(0, ...gPlaced.map((q) => q.tier)) + 1) * TIER;

    const headTop = pad + 26 + 26;
    const ruleY = headTop + headLines.length * 24 + 10;
    const donutTop = ruleY + 24;
    const midH = Math.max(320, legH);
    const barsTop = donutTop + midH + 32;
    const stripY = barsTop + topH, SRC_H = 40, GRP_H = 28;
    const bottomY = stripY + SRC_H + 4 + GRP_H;
    const H = bottomY + bottomH + 20 + 24 + pad;

    const out = document.createElement("canvas");
    out.width = W * scale; out.height = H * scale;
    const c = out.getContext("2d");
    c.scale(scale, scale);
    c.fillStyle = pal.bg; c.fillRect(0, 0, W, H);
    c.textBaseline = "alphabetic";
    c.fillStyle = pal.text; c.font = F(800, 26); c.fillText(fitTitleLine(c, title, W - pad * 2), pad, pad + 22);
    c.fillStyle = pal.muted; c.font = F(500, 14); c.fillText(sub, pad, pad + 46);
    c.fillStyle = pal.text; c.font = F(600, 17);
    headLines.forEach((l, i) => c.fillText(l, pad, headTop + 20 + i * 24));
    c.strokeStyle = pal.border; c.lineWidth = 1;
    c.beginPath(); c.moveTo(pad, ruleY + 0.5); c.lineTo(W - pad, ruleY + 0.5); c.stroke();

    // Donut: same geometry as the on-screen SVG (viewBox 320).
    const cx = pad + 160, cy = donutTop + midH / 2;
    function ring(list, r, width, colorOf) {
      let start = -Math.PI / 2;
      const gapA = list.length > 1 ? 2 / r : 0;
      list.forEach((x) => {
        const a = d.totalMw > 0 ? x.mw / d.totalMw * Math.PI * 2 : 0;
        const end = start + Math.max(a - gapA, 0.6 / r);
        c.beginPath(); c.arc(cx, cy, r, start, end); c.strokeStyle = colorOf(x); c.lineWidth = width; c.stroke();
        start += a;
      });
    }
    c.lineCap = "butt";
    ring(d.items, 128, 44, (it) => it.color);
    ring(d.groups, 90, 26, (g) => col(CMP_GROUPS[g.key].tint));
    ring(d.groups, 101, 4, (g) => col(CMP_GROUPS[g.key].ring));
    c.textAlign = "center";
    c.fillStyle = pal.text; c.font = F(900, 26); c.fillText(d.gw(d.totalMw) + "GW", cx, cy + 4);
    c.fillStyle = pal.muted; c.font = F(500, 14); c.fillText(d.centreLabel, cx, cy + 26);
    c.textAlign = "left";

    // Legend
    let ly = donutTop + (midH - legH) / 2;
    legRows.forEach((row) => {
      row.forEach((g, k) => {
        const x = legX + k * (colW + 24);
        let y = ly;
        c.fillStyle = col(CMP_GROUPS[g.key].ring); c.fillRect(x, y, colW, 6);
        y += 6 + 8 + 16;
        c.fillStyle = pal.text; c.font = F(800, 16); c.fillText(g.label, x, y);
        y += 20;
        c.fillStyle = pal.muted; c.font = F(500, 14); c.fillText(`${d.gw(g.mw)}GW, ${d.pct(g.mw)}%`, x, y);
        y += 6;
        g.members.forEach((i) => {
          const it = d.items[i];
          y += 24;
          c.fillStyle = it.color; c.fillRect(x, y - 12, 12, 12);
          c.fillStyle = pal.text; c.font = F(600, 14);
          const lab = fitTitleLine(c, it.label, colW - 70);
          c.fillText(lab, x + 20, y);
          c.fillStyle = pal.muted; c.font = F(500, 14); c.textAlign = "right"; c.fillText(d.pct(it.mw) + "%", x + colW, y); c.textAlign = "left";
        });
      });
      ly += Math.max(...row.map(groupH));
    });

    // Bars
    acc = 0;
    d.items.forEach((it) => {
      const x = pad + (d.totalMw > 0 ? acc / d.totalMw * barW : 0), w = d.totalMw > 0 ? it.mw / d.totalMw * barW : 0;
      acc += it.mw;
      c.fillStyle = it.color; c.fillRect(x, stripY, Math.max(w, 1), SRC_H);
    });
    acc = 0;
    d.groups.forEach((g, i) => {
      const x = pad + (d.totalMw > 0 ? acc / d.totalMw * barW : 0), w = d.totalMw > 0 ? g.mw / d.totalMw * barW : 0;
      acc += g.mw;
      const y = stripY + SRC_H + 4;
      c.fillStyle = col(CMP_GROUPS[g.key].tint); c.fillRect(x, y, Math.max(w, 1), GRP_H);
      c.fillStyle = col(CMP_GROUPS[g.key].ring); c.fillRect(x, y, Math.max(w, 1), 4);
      if (i > 0) { c.fillStyle = pal.bg; c.fillRect(x, y, 3, GRP_H); }
    });
    c.strokeStyle = pal.ink; c.lineWidth = 1;
    placed.forEach((p) => {
      const y = barsTop + topH - (p.tier + 1) * TIER;
      const lx = pad + p.left;
      c.beginPath();
      if (p.left !== p.x) { c.moveTo(pad + p.x + 0.5, y + 40); c.lineTo(pad + p.x + 0.5, stripY); }
      else { c.moveTo(lx + 0.5, y); c.lineTo(lx + 0.5, stripY); }
      c.stroke();
      c.fillStyle = pal.text; c.font = F(700, 14); c.fillText(p.it.label, lx + 6, y + 14);
      c.fillStyle = pal.muted; c.font = F(500, 13); c.fillText(p.val, lx + 6, y + 32);
    });
    gPlaced.forEach((q) => {
      const y = bottomY + q.tier * TIER;
      const lx = pad + q.left;
      c.strokeStyle = col(CMP_GROUPS[q.g.key].ring);
      c.beginPath(); c.moveTo(pad + q.x + 0.5, bottomY); c.lineTo(pad + q.x + 0.5, y + 38); c.stroke();
      c.fillStyle = col(CMP_GROUPS[q.g.key].ink); c.font = F(800, 14); c.fillText(q.g.label, lx + 6, y + 18);
      c.fillStyle = pal.muted; c.font = F(500, 13); c.fillText(q.val, lx + 6, y + 36);
    });

    c.fillStyle = pal.muted; c.font = F(500, 12);
    c.fillText("Outer ring and top bar: each source. Inner ring and bottom bar: its group. Source: https://gridpulse.info/", pad, H - pad + 8);

    out.toBlob((blob) => {
      if (!blob) return;
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `gridpulse-${slugify(title)}-${todayStamp()}.png`;
      a.style.position = "fixed"; a.style.opacity = "0";
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    }, "image/png");
  }

  function attachMixSnapshot(host, toolsEl) {
    if (!host || !toolsEl || host._snapBtn) return;
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "copy-btn";
    btn.innerHTML = '<span data-copy-label aria-live="polite">Download chart snapshot (PNG)</span>';
    btn.addEventListener("click", () => {
      if (!host._mixSnap) return;
      const label = btn.querySelector("[data-copy-label]");
      const go = () => {
        try { host._mixSnap(); label.textContent = "Snapshot downloaded"; }
        catch (e) { label.textContent = "Snapshot failed. Try again."; }
        clearTimeout(btn._t);
        btn._t = setTimeout(() => { label.textContent = "Download chart snapshot (PNG)"; }, 2000);
      };
      if (document.fonts && document.fonts.ready) document.fonts.ready.then(go); else go();
    });
    const tableBtn = toolsEl.querySelector(".mixchart__table-btn");
    toolsEl.insertBefore(btn, tableBtn || null);
    host._snapBtn = btn;
  }

  function renderMixChart(host, ordered, totalMw, o) {
    const gw = o.gw, pct = o.pct;
    const items = [];
    const groups = [];
    CMP_ORDER.forEach((k) => {
      const members = ordered.filter((r) => cmpKeyOf(r.group) === k).sort((a, b) => b.mw - a.mw);
      if (!members.length) return;
      const mw = members.reduce((s, r) => s + r.mw, 0);
      const g = { key: k, label: CMP_GROUPS[k].label, mw, first: items.length, members: [] };
      members.forEach((r) => { g.members.push(items.length); items.push({ label: r.label, mw: r.mw, color: r.color, g: k }); });
      groups.push(g);
    });
    if (!items.length) return;
    const gById = {}; groups.forEach((g) => { gById[g.key] = g; });
    let view = "donut";
    try { view = localStorage.getItem(MIX_VIEW_KEY) === "bar" ? "bar" : "donut"; } catch (e) { /* default */ }
    const esc = escapeHtml;
    const biggest = items.slice().sort((a, b) => b.mw - a.mw)[0];
    const ren = gById.ren ? gById.ren.mw : 0;
    const headline = `${biggest.label} is the biggest source, at ${pct(biggest.mw)}% of supply.` + (totalMw > 0 ? ` ${pct(ren)}% is renewable.` : "");
    const centreDefault = [gw(totalMw) + "GW", o.centerSub || "total supply"];

    // Donut rings
    const R1 = 128, R2 = 90, RE = 101, C1 = 2 * Math.PI * R1, C2 = 2 * Math.PI * R2, CE = 2 * Math.PI * RE;
    const gap = items.length > 1 ? 2 : 0;
    let cum = 0;
    const outer = items.map((it, i) => {
      const f = totalMw > 0 ? it.mw / totalMw : 0;
      const dash = Math.max(f * C1 - gap, 0.6);
      const s = `<circle cx="160" cy="160" r="${R1}" stroke="${esc(it.color)}" stroke-width="44" stroke-dasharray="${dash.toFixed(2)} ${C1.toFixed(2)}" stroke-dashoffset="${(-cum * C1).toFixed(2)}" data-key="i:${i}"></circle>`;
      cum += f; return s;
    }).join("");
    cum = 0;
    const inner = groups.map((g) => {
      const f = totalMw > 0 ? g.mw / totalMw : 0;
      const dash = Math.max(f * C2 - (groups.length > 1 ? 2 : 0), 0.6);
      // Same look as the bottom bar: the group's tint, edged in its colour.
      const dashE = Math.max(f * CE - (groups.length > 1 ? 2 : 0), 0.6);
      const s = `<circle cx="160" cy="160" r="${R2}" style="stroke:${CMP_GROUPS[g.key].tint}" stroke-width="26" stroke-dasharray="${dash.toFixed(2)} ${C2.toFixed(2)}" stroke-dashoffset="${(-cum * C2).toFixed(2)}" data-key="g:${g.key}"></circle>` +
        `<circle cx="160" cy="160" r="${RE}" style="stroke:${CMP_GROUPS[g.key].ring}" stroke-width="4" stroke-dasharray="${dashE.toFixed(2)} ${CE.toFixed(2)}" stroke-dashoffset="${(-cum * CE).toFixed(2)}" data-key="g:${g.key}"></circle>`;
      cum += f; return s;
    }).join("");
    const srcAria = "Outer ring, supply by source: " + items.map((it) => `${it.label} ${pct(it.mw)}%`).join(", ") + ". Inner ring, by group: " + groups.map((g) => `${g.label.toLowerCase()} ${pct(g.mw)}%`).join(", ") + ".";
    const legend = groups.map((g) => {
      const c = CMP_GROUPS[g.key];
      return `<div class="mixchart__group" style="--g:${c.ring};--gi:${c.ink}">` +
        `<button type="button" class="mixchart__gbtn" data-key="g:${g.key}"><strong>${esc(g.label)}</strong><span class="mixchart__val">${gw(g.mw)}GW, ${pct(g.mw)}%</span></button>` +
        g.members.map((i) => `<button type="button" class="mixchart__ibtn" data-key="i:${i}"><span class="mixchart__sw" style="background:${esc(items[i].color)}"></span><strong>${esc(items[i].label)}</strong><span class="mixchart__val">${pct(items[i].mw)}%</span></button>`).join("") +
        `</div>`;
    }).join("");

    // Bars
    cum = 0;
    const srcBtns = items.map((it, i) => {
      const w = totalMw > 0 ? it.mw / totalMw * 100 : 0;
      cum += w;
      return `<button type="button" data-key="i:${i}" style="width:${w.toFixed(3)}%;background:${esc(it.color)}" aria-label="${esc(it.label)}, ${gw(it.mw)}GW, ${pct(it.mw)}%"></button>`;
    }).join("");
    const grpBtns = groups.map((g) => {
      const w = totalMw > 0 ? g.mw / totalMw * 100 : 0;
      const c = CMP_GROUPS[g.key];
      return `<button type="button" data-key="g:${g.key}" style="width:${w.toFixed(3)}%;--g:${c.ring};--t:${c.tint}" aria-label="${esc(g.label)}, ${gw(g.mw)}GW, ${pct(g.mw)}%"></button>`;
    }).join("");

    host.innerHTML =
      `<div class="mixchart__top"><p class="mixchart__readout" role="status" aria-live="polite"></p>` +
      `<div class="mixchart__switch" role="group" aria-label="Chart type"><button type="button" data-view="donut">Donut</button><button type="button" data-view="bar">Bar</button></div></div>` +
      `<div class="mixchart__donut" data-panel="donut"><div class="mixchart__ring"><svg viewBox="0 0 320 320" role="img" aria-label="${esc(srcAria)}"><g transform="rotate(-90 160 160)" fill="none">${outer}${inner}</g></svg>` +
      `<div class="mixchart__centre" aria-hidden="true"><strong></strong><span></span></div></div><div class="mixchart__legend">${legend}</div></div>` +
      `<div class="mixchart__bars" data-panel="bar"><div class="mixchart__labels mixchart__labels--top" aria-hidden="true"></div>` +
      `<div class="mixchart__strip mixchart__strip--src" role="group" aria-label="Supply by source">${srcBtns}</div>` +
      `<div class="mixchart__strip mixchart__strip--grp" role="group" aria-label="Supply by group">${grpBtns}</div>` +
      `<div class="mixchart__labels mixchart__labels--bottom" aria-hidden="true"></div></div>` +
      `<p class="mixchart__note" data-panel="donut">Outer ring: each source. Inner ring: its group. Each source sits in line with its group, reading clockwise from the top.</p>` +
      `<p class="mixchart__note" data-panel="bar">Top bar: each source. Bottom bar: its group, directly below the sources in it.</p>` +
      (o.note ? `<details class="mixchart__how"><summary>How we measure this</summary><p>${esc(o.note)}</p></details>` : "");

    const readout = host.querySelector(".mixchart__readout");
    const centre = host.querySelector(".mixchart__centre");
    const keyed = Array.from(host.querySelectorAll("[data-key]"));
    let hi = "";
    function groupOfKey(k) { return k.indexOf("i:") === 0 ? items[Number(k.slice(2))].g : k.slice(2); }
    function lit(k) {
      if (!hi) return true;
      if (hi.indexOf("i:") === 0) return k === hi || k === "g:" + items[Number(hi.slice(2))].g;
      return k === hi || (k.indexOf("i:") === 0 && groupOfKey(k) === hi.slice(2));
    }
    function paint() {
      keyed.forEach((el) => el.classList.toggle("is-dim", !lit(el.dataset.key)));
      let text = headline, cv = centreDefault[0], cl = centreDefault[1];
      if (hi.indexOf("i:") === 0) {
        const it = items[Number(hi.slice(2))], g = gById[it.g];
        text = it.g === "imp" ? `${it.label}: ${gw(it.mw)}GW, ${pct(it.mw)}% of supply, through the interconnectors.`
          : `${it.label}: ${gw(it.mw)}GW, ${pct(it.mw)}% of supply. Part of ${g.label.toLowerCase()}, ${pct(g.mw)}%.`;
        cv = gw(it.mw) + "GW"; cl = it.label.toLowerCase();
      } else if (hi.indexOf("g:") === 0) {
        const g = gById[hi.slice(2)];
        text = g.key === "imp" ? `Net imports: ${gw(g.mw)}GW, ${pct(g.mw)}% of supply, through the interconnectors.`
          : `${g.label}: ${gw(g.mw)}GW, ${pct(g.mw)}% of supply, from ${joinAnd(g.members.map((i) => items[i].label.toLowerCase()))}.`;
        cv = gw(g.mw) + "GW"; cl = g.label.toLowerCase();
      }
      readout.textContent = text;
      readout.classList.toggle("is-active", !!hi);
      centre.querySelector("strong").textContent = cv;
      centre.querySelector("span").textContent = cl;
    }
    function setHi(k) { if (k !== hi) { hi = k; paint(); } }
    const sec = host.closest("section");
    const secTitle = sec && sec.querySelector("h2") ? sec.querySelector("h2").textContent.trim() : "";
    host._mixSnap = () => downloadMixSnapshot(host, { items, groups, totalMw, gw, pct, headline, centreLabel: centreDefault[1], title: secTitle || "Generation mix" });
    // Listeners are bound once per container; each re-render swaps in its own handlers.
    host._mix = {
      over: (e) => { const el = e.target.closest("[data-key]"); if (el && host.contains(el)) setHi(el.dataset.key); },
      leave: () => setHi(""),
      focusin: (e) => { const el = e.target.closest("[data-key]"); setHi(el ? el.dataset.key : ""); },
      focusout: (e) => { if (!host.contains(e.relatedTarget)) setHi(""); },
      click: (e) => {
        const v = e.target.closest("[data-view]");
        if (v) { setView(v.dataset.view, true); return; }
        const el = e.target.closest("[data-key]");
        if (el) setHi(el.dataset.key);
      },
    };
    if (!host._mixBound) {
      host._mixBound = true;
      host.addEventListener("mouseover", (e) => host._mix.over(e));
      host.addEventListener("mouseleave", (e) => host._mix.leave(e));
      host.addEventListener("focusin", (e) => host._mix.focusin(e));
      host.addEventListener("focusout", (e) => host._mix.focusout(e));
      host.addEventListener("click", (e) => host._mix.click(e));
    }

    function layoutBars() {
      const top = host.querySelector(".mixchart__labels--top");
      const bottom = host.querySelector(".mixchart__labels--bottom");
      if (!top || !top.offsetParent) return;
      const W = top.clientWidth;
      const TIER = 50;
      top.innerHTML = ""; bottom.innerHTML = "";
      const tiers = [];
      let maxTier = 0;
      let acc = 0;
      const placed = items.map((it, i) => {
        const x = totalMw > 0 ? acc / totalMw * W : 0;
        acc += it.mw;
        const lab = document.createElement("div");
        lab.className = "mixchart__label";
        lab.dataset.key = "i:" + i;
        lab.innerHTML = `<strong>${esc(it.label)}</strong><span>${gw(it.mw)}GW, ${pct(it.mw)}%</span>`;
        lab.style.left = "0px"; lab.style.top = "0px";
        top.appendChild(lab);
        return { lab, x, w: lab.offsetWidth + 8 };
      });
      // Tiers, worked right to left: a label sits one tier above every
      // label whose leader line starts underneath it, so no leader line
      // ever crosses a label, and labels in one tier never overlap.
      for (let j = placed.length - 1; j >= 0; j--) {
        const p = placed[j];
        p.left = p.x + p.w > W ? Math.max(0, W - p.w) : p.x;
        let t = 0;
        for (let i = j + 1; i < placed.length; i++) {
          const q = placed[i];
          if (q.x < p.left + p.w) t = Math.max(t, q.tier + 1);
        }
        while ((tiers[t] || []).some((b) => p.left < b[1] && p.left + p.w > b[0])) t++;
        (tiers[t] = tiers[t] || []).push([p.left, p.left + p.w]);
        p.tier = t; maxTier = Math.max(maxTier, t);
      }
      const H = (maxTier + 1) * TIER;
      top.style.height = H + "px";
      placed.forEach((p) => {
        const y = H - (p.tier + 1) * TIER;
        p.lab.style.left = p.left + "px";
        p.lab.style.top = y + "px";
        p.lab.style.height = (H - y) + "px";
        if (p.left !== p.x) { // label pulled left to fit: keep a leader line at the segment's own start
          p.lab.style.borderLeft = "0";
          const lead = document.createElement("div");
          lead.className = "mixchart__label"; lead.dataset.key = p.lab.dataset.key;
          lead.style.cssText = `left:${p.x}px;top:${y + 40}px;height:${H - y - 40}px;padding:0;border-left:1px solid var(--ink)`;
          top.appendChild(lead);
        }
      });
      // Bottom: group labels under the start of each group, pushed down a row if they would collide
      const rows = [];
      let gacc = 0, gMax = 0;
      groups.forEach((g) => {
        const x = totalMw > 0 ? gacc / totalMw * W : 0;
        gacc += g.mw;
        const lab = document.createElement("div");
        lab.className = "mixchart__label";
        lab.dataset.key = "g:" + g.key;
        lab.style.setProperty("--gi", CMP_GROUPS[g.key].ink);
        lab.innerHTML = `<strong>${esc(g.label)}</strong><span>${gw(g.mw)}GW, ${pct(g.mw)}%</span>`;
        bottom.appendChild(lab);
        const w = lab.offsetWidth + 8;
        let left = x + w > W ? Math.max(0, W - w) : x;
        let r = 0;
        while ((rows[r] || []).some((b) => left < b[1] && left + w > b[0])) r++;
        (rows[r] = rows[r] || []).push([left, left + w]);
        gMax = Math.max(gMax, r);
        lab.style.left = left + "px";
        lab.style.top = (8 + r * 46) + "px";
      });
      bottom.style.height = (8 + (gMax + 1) * 46) + "px";
      keyed.splice(0, keyed.length, ...host.querySelectorAll("[data-key]"));
      paint();
    }

    function setView(v, save) {
      view = v === "bar" ? "bar" : "donut";
      if (save) { try { localStorage.setItem(MIX_VIEW_KEY, view); } catch (e) { /* ignore */ } }
      host.querySelectorAll("[data-view]").forEach((b) => b.setAttribute("aria-pressed", String(b.dataset.view === view)));
      host.querySelectorAll("[data-panel]").forEach((p) => { p.hidden = p.dataset.panel !== view; });
      hi = "";
      if (view === "bar") layoutBars(); else paint();
    }
    setView(view, false);
    if (!host._mixResize) {
      host._mixResize = true;
      let t;
      window.addEventListener("resize", () => { clearTimeout(t); t = setTimeout(() => { if (host._layoutBars) host._layoutBars(); }, 150); });
    }
    host._layoutBars = () => { if (view === "bar") layoutBars(); };
  }
  window.GridPreview.renderMixChart = renderMixChart;

  /** Show as table: the mix table starts hidden behind the chart, with a
      button beside the chart's Copy as text and Download CSV buttons. */
  function attachShowTable(table, toolsEl) {
    if (!table || table._showTable) return;
    table._showTable = true;
    table.classList.add("mixchart__table");
    table.hidden = true;
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "mixchart__table-btn";
    btn.setAttribute("aria-pressed", "false");
    if (!table.id) table.id = "mix-table-" + Math.random().toString(36).slice(2, 8);
    btn.setAttribute("aria-controls", table.id);
    btn.textContent = "Show as table";
    btn.addEventListener("click", () => {
      const show = table.hidden;
      table.hidden = !show;
      btn.setAttribute("aria-pressed", String(show));
      btn.textContent = show ? "Hide table" : "Show as table";
    });
    if (toolsEl) toolsEl.appendChild(btn);
    else table.parentNode.insertBefore(btn, table);
  }

  /** Finds (or creates) the mix chart's container in place of the old canvas donut. */
  function mixHostFor(donutCanvas, opts) {
    if (!donutCanvas) return null;
    if (donutCanvas._mixHost) return donutCanvas._mixHost;
    // The old layout put the donut either in its own grid (GB home) or in a
    // two-column grid beside the table (country pages). The chart now takes
    // the full width; the table moves below it, behind Show as table.
    const grid = donutCanvas.closest(".chart-grid--donuts, .panel-grid");
    const wrap = donutCanvas.closest(".chart-wrap");
    const anchor = grid || wrap;
    if (!anchor || !anchor.parentNode) return null;
    const host = document.createElement("div");
    host.className = "mixchart";
    anchor.parentNode.insertBefore(host, anchor);
    if (grid) {
      let after = host;
      Array.from(grid.querySelectorAll("table")).forEach((t) => { after.parentNode.insertBefore(t, after.nextSibling); after = t; });
    } else {
      [opts.legendId, opts.donutCaptionId].forEach((id) => { const el = id && document.getElementById(id); if (el) el.hidden = true; });
    }
    anchor.hidden = true;
    donutCanvas._mixHost = host;
    return host;
  }

  /* ---------- Page template: breadcrumb, On this page, country switcher ---------- */
  function initPageTools() {
    const main = document.getElementById("main");
    const hero = main && main.querySelector(".hero");
    if (!hero || document.body.classList.contains("is-home")) return;
    const current = document.querySelector('.nav-panel a[aria-current="page"]');
    // Breadcrumb: section > this page
    if (current) {
      const groupBtn = current.closest(".nav-group") && current.closest(".nav-group").querySelector(".nav-group__toggle");
      if (groupBtn && !hero.querySelector(".breadcrumb")) {
        const nav = document.createElement("nav");
        nav.className = "breadcrumb";
        nav.setAttribute("aria-label", "Breadcrumb");
        const home = document.querySelector(".brand").getAttribute("href");
        nav.innerHTML = `<a href="${escapeHtml(home)}">Home</a><span aria-hidden="true">›</span>${escapeHtml(groupBtn.textContent.trim())}<span aria-hidden="true">›</span><span aria-current="page">${escapeHtml(current.querySelector(".nav-panel__name").textContent)}</span>`;
        hero.insertBefore(nav, hero.firstChild);
      }
    }
    const tools = document.createElement("div");
    tools.className = "page-tools";
    // On this page: every main section heading with an id
    const heads = Array.from(main.querySelectorAll("section > h2[id], .panel > h2[id]")).filter((h) => !h.closest(".hero") && h.offsetParent !== null);
    if (heads.length >= 3) {
      const otp = document.createElement("nav");
      otp.className = "on-this-page";
      otp.setAttribute("aria-label", "On this page");
      otp.innerHTML = `<span class="on-this-page__label" aria-hidden="true">On this page</span>` +
        heads.slice(0, 8).map((h) => `<a href="#${escapeHtml(h.id)}">${escapeHtml(h.textContent.replace(/\s+/g, " ").trim())}</a>`).join("");
      tools.appendChild(otp);
    }
    // Country switcher on country pages (Europe and the rest of the world)
    const group = current && current.closest(".nav-panel");
    if (group && (group.id === "navgroup-europe" || group.id === "navgroup-world")) {
      const links = Array.from(group.querySelectorAll("a"));
      const wrap = document.createElement("div");
      wrap.className = "country-switch";
      const id = "country-switch-select";
      wrap.innerHTML = `<label for="${id}">Switch country</label><select id="${id}">` +
        links.map((a) => `<option value="${escapeHtml(a.getAttribute("href"))}"${a === current ? " selected" : ""}>${escapeHtml(a.querySelector(".nav-panel__name").textContent)}</option>`).join("") +
        `</select><button type="button" class="btn">Go</button>`;
      wrap.querySelector("button").addEventListener("click", () => { window.location.href = wrap.querySelector("select").value; });
      const cmp = document.querySelector('.nav-link a[href$="comparisons.html"]');
      if (cmp) {
        const a = document.createElement("a");
        a.href = cmp.getAttribute("href");
        a.textContent = "Compare with Great Britain";
        wrap.appendChild(a);
      }
      tools.appendChild(wrap);
    }
    // Country pages: the "Data as of" pill sits on the left of the same line
    const pill = main.querySelector(".page-pill");
    const sw = tools.querySelector(".country-switch");
    if (pill && sw) tools.insertBefore(pill, sw);
    if (tools.children.length) hero.parentNode.insertBefore(tools, hero.nextSibling);
  }

  /** Folds the announcement banner to a thin line once the page scrolls.
      Two thresholds, so the height change itself cannot flip it back. */
  function initBannerFold() {
    if (!document.querySelector(".site-head .preview-banner")) return;
    let on = false;
    const check = () => {
      const y = window.scrollY || document.documentElement.scrollTop;
      if (!on && y > 80) { on = true; root.classList.add("is-scrolled"); }
      else if (on && y < 8) { on = false; root.classList.remove("is-scrolled"); }
    };
    window.addEventListener("scroll", check, { passive: true });
    check();
  }

  /* ---------- "We've moved" notice ----------
     ukgridlive.info forwards every address here with ?from=ukgridlive.
     Show a one-off notice, then take "from" out of the address so a
     bookmark or a shared link doesn't carry it on. */
  function initMovedNotice() {
    let params;
    try { params = new URLSearchParams(window.location.search); } catch (e) { return; }
    if (params.get("from") !== "ukgridlive") return;
    params.delete("from");
    const qs = params.toString();
    try {
      history.replaceState(history.state, "", window.location.pathname + (qs ? "?" + qs : "") + window.location.hash);
    } catch (e) { /* ignore */ }
    let seen = false;
    try { seen = localStorage.getItem("gp-moved-notice") === "1"; } catch (e) { /* ignore */ }
    if (seen) return;
    try { localStorage.setItem("gp-moved-notice", "1"); } catch (e) { /* ignore */ }
    const box = document.createElement("div");
    box.className = "moved-notice";
    box.setAttribute("role", "status");
    box.innerHTML = '<div class="wrap moved-notice__inner"><p><strong>UK Grid: Live+ is now Grid Pulse.</strong> Update your bookmarks.</p>' +
      '<button type="button" class="moved-notice__close" aria-label="Dismiss this notice">Dismiss</button></div>';
    box.querySelector("button").addEventListener("click", () => box.remove());
    const head = document.querySelector(".site-head");
    if (head && head.parentNode) head.parentNode.insertBefore(box, head.nextSibling);
    else document.body.insertBefore(box, document.body.firstChild);
  }

  /* ---------- Init ---------- */
  document.addEventListener("DOMContentLoaded", () => {
    initPrefs();
    initMovedNotice();
    initBannerFold();
    initNav();
    initPageTools();
    initTabs();
    initReveal();
    initCopyButtons();
    initIngestStatusPill();
    initStatRefreshAnim();
    initLoadingValueTimeout();
    initLoadingTextObserver();
    externalizeLinks();

    // Update aria-current year in footer
    document.querySelectorAll("[data-current-year]").forEach((el) => {
      el.textContent = new Date().getFullYear();
    });
  });
})();
