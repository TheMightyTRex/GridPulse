/* ==========================================================================
   Grid Pulse - price and carbon alerts (Great Britain).
   The settings live on pages/records.html?area=GB (the "Alerts" panel).
   Any page that loads this file checks the rules every 5 minutes while it
   is open, so alerts still work with the home page left open in a tab.
   ========================================================================== */
(function () {
  "use strict";
  // Alerts: browser notifications when price/carbon-intensity cross a
  // user-set threshold. Deliberately NOT a push/email system - there is no
  // server component here that could send one while the browser is
  // closed, only client-side polling while this tab stays open (see the
  // panel's own caption). Rules are stored in localStorage; "already
  // notified for this crossing" state is stored in sessionStorage so a
  // rule fires once per crossing per browsing session rather than
  // repeatedly every 5 minutes while a value stays past its threshold.
  const ALERTS_STORAGE_KEY = "ukgl-alerts";
  const ALERT_RULES = [
    { key: "price", field: "price_gbp_mwh", label: "Price", format: (v) => "£" + v.toFixed(2) + "/MWh" },
    { key: "emissions", field: "emissions_gco2", label: "Carbon intensity", format: (v) => Math.round(v) + "g/kWh" },
  ];

  function loadAlertRules() {
    try {
      return JSON.parse(localStorage.getItem(ALERTS_STORAGE_KEY)) || {};
    } catch (e) {
      return {};
    }
  }
  function saveAlertRules(rules) {
    try {
      localStorage.setItem(ALERTS_STORAGE_KEY, JSON.stringify(rules));
    } catch (e) {}
  }

  (function () {
    const enableBtn = document.getElementById("alerts-enable-btn");
    const enableLabel = document.getElementById("alerts-enable-label");
    const statusEl = document.getElementById("alerts-permission-status");
    const supported = typeof Notification !== "undefined";

    function refreshPermissionUi() {
      if (!supported) {
        if (statusEl) statusEl.textContent = "Notifications are not supported in this browser.";
        if (enableBtn) enableBtn.disabled = true;
        return;
      }
      if (Notification.permission === "granted") {
        if (enableLabel) enableLabel.textContent = "Notifications enabled";
        if (statusEl) statusEl.textContent = "";
        if (enableBtn) enableBtn.classList.add("is-copied");
      } else if (Notification.permission === "denied") {
        if (statusEl) statusEl.textContent = "Notifications are blocked for this site in your browser settings.";
        if (enableBtn) enableBtn.disabled = true;
      }
    }
    refreshPermissionUi();

    if (enableBtn && supported) {
      enableBtn.addEventListener("click", () => {
        Notification.requestPermission().then(refreshPermissionUi);
      });
    }

    const rules = loadAlertRules();
    ALERT_RULES.forEach(({ key }) => {
      const enabledEl = document.getElementById("alert-" + key + "-enabled");
      const directionEl = document.getElementById("alert-" + key + "-direction");
      const thresholdEl = document.getElementById("alert-" + key + "-threshold");
      if (!enabledEl || !directionEl || !thresholdEl) return;

      const saved = rules[key] || {};
      enabledEl.checked = !!saved.enabled;
      directionEl.value = saved.direction || "below";
      thresholdEl.value = saved.threshold != null ? saved.threshold : "";

      const persist = () => {
        const current = loadAlertRules();
        current[key] = {
          enabled: enabledEl.checked,
          direction: directionEl.value,
          threshold: thresholdEl.value === "" ? null : parseFloat(thresholdEl.value),
        };
        saveAlertRules(current);
      };
      enabledEl.addEventListener("change", persist);
      directionEl.addEventListener("change", persist);
      thresholdEl.addEventListener("change", persist);
    });
  })();

  /** Checks the current live snapshot against every enabled alert rule and
      fires a browser Notification for any rule that has just crossed its
      threshold (not already firing before this check). Safe to call as
      often as you like - a no-op whenever notifications are not supported,
      permission has not been granted, or no rule is enabled. */
  function checkAlerts(current) {
    if (!current || typeof Notification === "undefined" || Notification.permission !== "granted") return;
    const rules = loadAlertRules();
    ALERT_RULES.forEach(({ key, field, label, format }) => {
      const rule = rules[key];
      const value = current[field];
      if (!rule || !rule.enabled || value == null || rule.threshold == null || !isFinite(rule.threshold)) return;

      const crossed = rule.direction === "below" ? value < rule.threshold : value > rule.threshold;
      const sideKey = "ukgl-alert-side-" + key;
      let wasCrossed = false;
      try {
        wasCrossed = sessionStorage.getItem(sideKey) === "1";
      } catch (e) {}

      if (crossed && !wasCrossed) {
        try {
          new Notification("Grid Pulse", {
            body: label + " is now " + rule.direction + " " + format(rule.threshold) + " - currently " + format(value),
            icon: (document.querySelector('link[rel="icon"]') || {}).href || "",
            tag: "ukgl-alert-" + key, // replaces any earlier notification for the same rule rather than piling up
          });
        } catch (e) {
          // Notification constructor can throw on some mobile browsers
          // that only support notifications via a service worker - fail
          // silently rather than breaking the rest of the page over a
          // best-effort feature.
        }
      }
      try {
        sessionStorage.setItem(sideKey, crossed ? "1" : "0");
      } catch (e) {}
    });
  }

  // Re-checks roughly every 5 minutes while this page stays open (see the
  // Alerts panel's own caption for why this cannot work once the tab is
  // closed). Independent of the status strip's own single page-load
  // fetch below - this is what makes "alerts" mean something more than
  // "checked once when you happened to load the page".
  setInterval(() => {
    (window.GridData ? window.GridData.fetchCurrent() : Promise.resolve(null)).then(checkAlerts);
  }, 5 * 60 * 1000);
  window.GPAlerts = { check: checkAlerts };
})();
