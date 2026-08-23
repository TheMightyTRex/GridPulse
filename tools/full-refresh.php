<?php
/**
 * tools/full-refresh.php - manual, one-off "pull everything right now"
 * diagnostic page. NOT part of the regular site - nothing on the site
 * links to this, and the whole tools/ folder is password-protected via
 * tools/.htaccess (HTTP Basic Auth) since it's for the site operator's own
 * use only, not visitors.
 *
 * Runs every configured source's ingest function - Elexon, Carbon
 * Intensity, NESO, EirGrid, EIA, ENTSO-E (once per configured country) -
 * regardless of whether each is actually due yet. The real readings_*
 * tables still get written to normally - this is a genuine pull, not a dry
 * run - but nothing is written to includes/refresh.log or the ingest_log
 * table (see ukgrid_start_log_capture()/ukgrid_stop_log_capture() in
 * includes/db.php): every result only exists for this one page load,
 * reload to run it again from scratch.
 *
 * DESIGN NOTE: this used to run everything inside one single PHP request.
 * On real shared hosting that was unreliable - a full run (ENTSO-E alone
 * can be up to 17 sequential requests across 6 countries) can take several
 * minutes, and many hosts enforce a hard request/process timeout at the
 * web-server or FastCGI/LiteSpeed level that a PHP-side set_time_limit()
 * call CANNOT override (that's a PHP-only setting; the server's own
 * proxy/process manager is a separate, often much shorter, ceiling) - the
 * result was a blank "Internal Server Error" partway through, with nothing
 * shown and no way to tell how far it got.
 *
 * This version instead loads instantly and drives the actual pulls from
 * JavaScript, one small AJAX request at a time (?ajax=1&source=...), each
 * bounded to a single source (and, for ENTSO-E, a single country per call)
 * with short per-request timeouts - so no single HTTP request this page
 * makes should ever take anywhere near as long as the old full run did,
 * regardless of what the host's real ceiling turns out to be. Progress and
 * results stream into the page live as each call finishes, and a "Copy
 * results" button at the bottom copies a plain-text summary of everything
 * so far - handy for pasting into a chat if something needs diagnosing.
 *
 * Safe to run repeatedly and at any time: every ingest function is the
 * same idempotent ON DUPLICATE KEY UPDATE upsert cron/on-demand refresh
 * already use, so re-running this can't create duplicate data.
 */

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/ingest.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('UTC');

$config = ukgrid_load_config();
$pdo = ukgrid_db();

function ukgrid_tool_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------
// AJAX branch - runs exactly ONE source (one country only, for ENTSO-E)
// and returns its captured log entries as JSON. Kept deliberately
// short-timeout per call - see the docblock above for why.
// ---------------------------------------------------------------------
if (isset($_GET['ajax'])) {
    set_time_limit(90); // a generous per-call ceiling, nowhere near the old full-run total

    $source = (string) ($_GET['source'] ?? '');
    $jobs = [
        'ELEXON' => static fn () => ukgrid_ingest_elexon($pdo, $config, 15),
        'CARBON_INTENSITY' => static fn () => ukgrid_ingest_carbon_intensity($pdo, $config, 12),
        'NESO' => static fn () => ukgrid_ingest_neso($pdo, $config, 15),
        'EIRGRID' => static fn () => ukgrid_ingest_eirgrid($pdo, $config, 12),
        'EIA' => static fn () => ukgrid_ingest_eia($pdo, $config, 12),
        // 8s per request, not 15s like the other sources here - IESO makes
        // TWO sequential HTTP calls internally (RealtimeTotals then
        // GenOutputbyFuelHourly), so worst case is already ~16s. IESO's
        // servers are also further away/slower to reach from a UK host than
        // this project's other sources, and a hang there risks tripping a
        // host-level proxy timeout that PHP itself can't catch or report on
        // (see this file's own docblock above) - keeping this tight leaves
        // more headroom below whatever that ceiling turns out to be.
        'IESO' => static fn () => ukgrid_ingest_ieso($pdo, $config, 8),
        // One country per call: skipFresherThanMinutes=0 so nothing is
        // ever skipped, maxCountriesPerRun=1 so this stays short. The page
        // calls this once per configured country, and staleness-based
        // ordering means each call naturally picks a different country
        // than the last one (whichever it just fetched becomes the
        // freshest, so it sorts to the back of the queue) - see
        // ukgrid_ingest_entsoe()'s own doc comment in includes/ingest.php
        // for the full mechanism.
        'ENTSOE' => static fn () => ukgrid_ingest_entsoe($pdo, $config, 15, 0, 1),
    ];

    header('Content-Type: application/json');

    if (!isset($jobs[$source])) {
        http_response_code(400);
        echo json_encode(['error' => 'unknown source: ' . $source]);
        exit;
    }

    $jobStart = microtime(true);
    ukgrid_start_log_capture();
    $threw = null;
    try {
        $jobs[$source]();
    } catch (Throwable $e) {
        $threw = get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    $entries = ukgrid_stop_log_capture();

    echo json_encode([
        'source' => $source,
        'entries' => $entries,
        'threw' => $threw,
        'seconds' => round(microtime(true) - $jobStart, 1),
    ]);
    exit;
}

// ---------------------------------------------------------------------
// Normal page load - just the shell + the JS that drives the AJAX calls
// above and renders their results as they come in.
// ---------------------------------------------------------------------
$countries = $config['sources']['entsoe_countries'] ?? [];
$entsoeCallCount = 0;
foreach ($countries as $countryCfg) {
    if (($countryCfg['domain'] ?? null) !== null && !empty($countryCfg['fetch'] ?? [])) {
        $entsoeCallCount++;
    }
}
// Always at least one call, even if entsoe_countries looks empty/unconfigured
// - it'll just report back "not set up" rather than the page having no
// ENTSOE card at all.
$entsoeCallCount = max($entsoeCallCount, 1);

$sourceOrder = ['ELEXON', 'CARBON_INTENSITY', 'NESO', 'EIRGRID', 'EIA', 'IESO', 'ENTSOE'];

// ---------------------------------------------------------------------
// Config check - runs on every normal page load, entirely from what's
// already in $config, no network calls involved. Added after two real
// gaps shipped invisibly in the same release: IESO missing from
// refresh.intervals_minutes/timeouts_seconds (on-demand refresh silently
// never ran it - no error anywhere, nothing to click on, just permanently
// stale data) and five new ENTSO-E countries not yet copied into a live
// entsoe_countries array (the code has no way to know a country "should"
// exist unless something tells it). entsoe_api_token/eia_api_key being
// left as 'CHANGE-ME' already logs an INFO line when ENTSOE/EIA actually
// run - but that's buried inside that source's own results table and easy
// to miss, especially before you've ever run this page. This block is
// meant to be the first thing you see, before anything below has run.
//
// Keep the two "expected" lists below in sync by hand whenever a country
// page or an on-demand-refreshable source is added - there's no single
// source of truth in the codebase to derive them from automatically.
// ---------------------------------------------------------------------
$configWarnings = [];

$entsoeToken = trim((string) ($config['sources']['entsoe_api_token'] ?? ''));
if ($entsoeToken === '' || $entsoeToken === 'CHANGE-ME') {
    $configWarnings[] = "entsoe_api_token isn't set (still 'CHANGE-ME' or blank) in includes/config.php - Ireland's SEM price/mix and all ten ENTSO-E country pages (France, Netherlands, Belgium, Norway, Denmark, Germany, Spain, Italy, Sweden, Portugal) will show no live data until you add one. Free to get, but needs registration plus an email approval step - see includes/config.php.example.";
}
$eiaKey = trim((string) ($config['sources']['eia_api_key'] ?? ''));
if ($eiaKey === '' || $eiaKey === 'CHANGE-ME') {
    $configWarnings[] = "eia_api_key isn't set in includes/config.php - the USA page will show no live data until you add one. Free, instant signup - see includes/config.php.example.";
}

$expectedEntsoeCountries = [
    'IE' => 'ireland.html', 'FR' => 'france.html', 'NL' => 'nl.html', 'BE' => 'belgium.html',
    'NO' => 'norway.html', 'DK' => 'denmark.html', 'DE' => 'germany.html', 'ES' => 'spain.html',
    'IT' => 'italy.html', 'SE' => 'sweden.html', 'PT' => 'portugal.html',
];
$configuredEntsoeCountries = array_keys($config['sources']['entsoe_countries'] ?? []);
$missingEntsoeCountries = array_diff(array_keys($expectedEntsoeCountries), $configuredEntsoeCountries);
if (!empty($missingEntsoeCountries)) {
    $missingList = [];
    foreach ($missingEntsoeCountries as $code) {
        $missingList[] = $code . ' (pages/' . $expectedEntsoeCountries[$code] . ')';
    }
    $configWarnings[] = 'entsoe_countries in includes/config.php is missing: ' . implode(', ', $missingList) . ' - those pages will stay on illustrative/no data until added. See includes/config.php.example for the exact entries to copy in.';
}

$expectedOnDemandSources = ['ELEXON', 'CARBON_INTENSITY', 'EIRGRID', 'EIA', 'IESO', 'ENTSOE', 'WEATHER', 'CONSTRAINTS', 'NOTABLE_MOMENTS'];
$intervalsCfg = $config['refresh']['intervals_minutes'] ?? [];
$timeoutsCfg = $config['refresh']['timeouts_seconds'] ?? [];
$missingOnDemand = array_unique(array_merge(
    array_diff($expectedOnDemandSources, array_keys($intervalsCfg)),
    array_diff($expectedOnDemandSources, array_keys($timeoutsCfg))
));
if (!empty($missingOnDemand)) {
    $configWarnings[] = "refresh.intervals_minutes and/or refresh.timeouts_seconds in includes/config.php has no entry for: " . implode(', ', $missingOnDemand) . " - without both, that source can only ever be refreshed by cron or by loading this page, never by an ordinary visitor's page load. See includes/config.php.example for the entries to add.";
}

if (empty($config['refresh']['on_demand'])) {
    $configWarnings[] = "refresh.on_demand is false (or missing) in includes/config.php - no source will ever refresh itself from an ordinary page visit. That's fine only if you have cron jobs set up for everything (see README-DEPLOY.md) - otherwise set this to true.";
}
?><!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Full refresh - UK Grid: Live+ tools</title>
<meta name="robots" content="noindex, nofollow">
<style>
  body { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; background: #14161a; color: #e8e8ea; margin: 0; padding: 2rem 1rem 4rem; }
  .wrap { max-width: 860px; margin: 0 auto; }
  h1 { font-size: 1.4rem; margin: 0 0 .3rem; }
  .sub { color: #9a9ba3; margin: 0 0 .4rem; font-size: .92rem; }
  .progress { color: #d8a83c; margin: 0 0 1.5rem; font-size: .88rem; font-weight: 600; min-height: 1.2em; }
  .progress.progress--done { color: #2fa876; }
  .source { border: 1px solid #2a2c33; border-radius: 8px; margin-bottom: 1rem; overflow: hidden; }
  .source__head { display: flex; align-items: center; gap: .6rem; padding: .75rem 1rem; background: #1b1d23; }
  .dot { width: .65rem; height: .65rem; border-radius: 50%; flex: none; }
  .dot--ok { background: #2fa876; }
  .dot--fail { background: #c94a3d; }
  .dot--pending { background: #4a4d57; }
  .dot--running { background: #d8a83c; animation: ukgrid-pulse 1s infinite ease-in-out; }
  @keyframes ukgrid-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }
  .source__name { font-weight: 700; font-size: .95rem; }
  .source__meta { margin-left: auto; color: #9a9ba3; font-size: .82rem; }
  .source__body { padding: .25rem 1rem .85rem; }
  table { width: 100%; border-collapse: collapse; font-size: .85rem; }
  td, th { text-align: left; padding: .3rem .5rem .3rem 0; vertical-align: top; }
  th { color: #9a9ba3; font-weight: 600; width: 6.5rem; }
  .msg { color: #c7c8cd; word-break: break-word; }
  .threw { color: #e88a7d; font-size: .85rem; padding: .4rem 0; margin: 0; }
  .empty { color: #9a9ba3; font-size: .85rem; padding: .4rem 0; margin: 0; font-style: italic; }
  .row-sep td { border-top: 1px solid #24262c; padding-top: .5rem; }
  .info-row th, .info-row td { color: #7d8a9e; font-style: italic; }
  .footer-note { color: #9a9ba3; font-size: .82rem; margin-top: 2rem; line-height: 1.5; }
  a { color: #6fb3ff; }
  .actions { display: flex; align-items: center; gap: .75rem; margin-top: 1rem; flex-wrap: wrap; }
  .btn { display: inline-block; padding: .5rem 1rem; background: #2a2c33; color: #e8e8ea; text-decoration: none; border-radius: 6px; font-size: .88rem; border: none; cursor: pointer; font-family: inherit; }
  .btn:hover { background: #34363e; }
  .btn--copied { background: #1f5c3f; color: #d8f5e6; }
  .config-check { border: 1px solid #6b3a2e; background: #241a17; border-radius: 8px; padding: .85rem 1rem; margin-bottom: 1.25rem; }
  .config-check__title { margin: 0 0 .5rem; font-weight: 700; font-size: .92rem; color: #e88a7d; }
  .config-check ul { margin: 0; padding-left: 1.1rem; }
  .config-check li { margin-bottom: .5rem; font-size: .85rem; color: #d8b9b3; line-height: 1.5; }
  .config-check li:last-child { margin-bottom: 0; }
  .config-check--ok { border-color: #1f5c3f; background: #16211c; color: #8fd6b4; padding: .6rem 1rem; margin-bottom: 1.25rem; border-radius: 8px; font-size: .88rem; font-weight: 600; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Full refresh - all sources</h1>
  <p class="sub">Runs each source one small request at a time (rather than one long request) so a slow or hanging source can't take the whole page down. Results stream in below as each one finishes.</p>

  <?php if (!empty($configWarnings)): ?>
    <div class="config-check">
      <p class="config-check__title"><?php echo count($configWarnings); ?> config issue<?php echo count($configWarnings) === 1 ? '' : 's'; ?> found in includes/config.php (checked before running anything below):</p>
      <ul>
        <?php foreach ($configWarnings as $w): ?>
          <li><?php echo ukgrid_tool_h($w); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php else: ?>
    <p class="config-check--ok">Config check passed - no missing API tokens, missing countries, or missing on-demand-refresh entries found.</p>
  <?php endif; ?>

  <p class="progress" id="progress-line">Starting...</p>

  <?php foreach ($sourceOrder as $name): ?>
    <div class="source" id="source-<?php echo ukgrid_tool_h($name); ?>">
      <div class="source__head">
        <span class="dot dot--pending" data-dot aria-hidden="true"></span>
        <span class="source__name"><?php echo ukgrid_tool_h($name); ?></span>
        <span class="source__meta" data-meta>queued</span>
      </div>
      <div class="source__body" data-body>
        <p class="empty">Not run yet.</p>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="actions">
    <a class="btn" href="?">Run again</a>
    <button class="btn" id="copy-btn" type="button">Copy results to clipboard</button>
  </div>

  <p class="footer-note">
    Real data is still written to the database as normal (every ingest function upserts, so re-running this is always safe) - only the pipeline-history logging is skipped. Check the live pages themselves, or <a href="../api/status.php">api/status.php</a>, to see the effect. This page is password-protected via tools/.htaccess - see that file if you ever need to change the password.
  </p>
</div>
<script>
(function () {
  var ENTSOE_CALLS = <?php echo (int) $entsoeCallCount; ?>;
  var sourceOrder = <?php echo json_encode($sourceOrder); ?>;

  var steps = ['ELEXON', 'CARBON_INTENSITY', 'NESO', 'EIRGRID', 'EIA', 'IESO'];
  for (var i = 0; i < ENTSOE_CALLS; i++) steps.push('ENTSOE');

  // Per-source accumulated result, kept around for the "copy results" button
  // and (for ENTSOE) for merging multiple calls' entries into one card.
  var results = {};
  sourceOrder.forEach(function (name) {
    results[name] = { entries: [], threw: null, seconds: 0, calls: 0 };
  });

  var overallStart = performance.now();
  var progressEl = document.getElementById('progress-line');

  function setDot(name, cls) {
    document.querySelector('#source-' + name + ' [data-dot]').className = 'dot dot--' + cls;
  }
  function setMeta(name, text) {
    document.querySelector('#source-' + name + ' [data-meta]').textContent = text;
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function renderEntries(name, entries, emptyNote) {
    var body = document.querySelector('#source-' + name + ' [data-body]');
    if (!entries.length) {
      body.innerHTML = '<p class="empty">' + escapeHtml(emptyNote || 'No log entries captured.') + '</p>';
      return;
    }
    var html = '<table>';
    entries.forEach(function (e, idx) {
      var sep = idx > 0 ? ' row-sep' : '';
      if (e.status === 'INFO') {
        // A raw HTTP-layer diagnostic line (see includes/db.php's
        // ukgrid_file_log()), not a structured ingest result - shown as a
        // single note rather than the usual Status/Rows/Message rows, and
        // deliberately excluded from the pass/fail dot (see overallOk()).
        html += '<tr class="info-row' + sep + '"><th>Note</th><td class="msg">' + escapeHtml(e.message) + '</td></tr>';
        return;
      }
      html += '<tr' + sep + '><th>Status</th><td>' + escapeHtml(e.status) + '</td></tr>';
      html += '<tr><th>Rows</th><td>' + escapeHtml(String(e.rows)) + '</td></tr>';
      if (e.message) html += '<tr><th>Message</th><td class="msg">' + escapeHtml(e.message) + '</td></tr>';
    });
    html += '</table>';
    body.innerHTML = html;
  }
  function overallOk(entries) {
    // INFO entries (raw HTTP-layer diagnostics - see renderEntries above)
    // are informational only and shouldn't affect the pass/fail dot.
    var real = entries.filter(function (e) { return e.status !== 'INFO'; });
    return real.length > 0 && real.every(function (e) { return e.status === 'OK'; });
  }

  async function runStep(name, stepIndex, totalSteps) {
    setDot(name, 'running');
    progressEl.textContent = 'Step ' + (stepIndex + 1) + ' of ' + totalSteps + ': working on ' + name +
      (name === 'ENTSOE' ? ' (country ' + (results.ENTSOE.calls + 1) + ' of ' + ENTSOE_CALLS + ')' : '') + '...';

    var r = results[name];

    try {
      var res = await fetch('?ajax=1&source=' + encodeURIComponent(name) + '&_=' + Date.now());
      var data = await res.json();

      r.calls++;
      r.seconds += (data.seconds || 0);

      if (data.threw) {
        r.threw = data.threw; // keep the most recent one if this source is called more than once (ENTSOE)
        if (name !== 'ENTSOE') {
          setDot(name, 'fail');
          setMeta(name, r.seconds.toFixed(1) + 's');
          document.querySelector('#source-' + name + ' [data-body]').innerHTML =
            '<p class="threw">Threw an uncaught exception before it could log anything: ' + escapeHtml(data.threw) + '</p>';
          return;
        }
        r.entries.push({ status: 'ERROR', rows: 0, message: 'Threw: ' + data.threw });
      } else {
        r.entries = r.entries.concat(data.entries || []);
      }

      var meta = name === 'ENTSOE'
        ? r.calls + '/' + ENTSOE_CALLS + ' countries, ' + r.seconds.toFixed(1) + 's total'
        : r.seconds.toFixed(1) + 's';
      setMeta(name, meta);
      setDot(name, r.entries.length ? (overallOk(r.entries) ? 'ok' : 'fail') : 'pending');
      renderEntries(name, r.entries, name === 'ENTSOE' && r.calls < ENTSOE_CALLS
        ? undefined
        : "The function returned without calling ukgrid_log_ingest() at all, which shouldn't normally happen.");
    } catch (err) {
      r.threw = String(err);
      setDot(name, 'fail');
      setMeta(name, 'request failed');
      document.querySelector('#source-' + name + ' [data-body]').innerHTML =
        '<p class="threw">The request itself failed (network error, or the response wasn\'t valid JSON - possibly a timeout on this particular source): ' + escapeHtml(String(err)) + '</p>';
    }
  }

  async function runAll() {
    for (var i = 0; i < steps.length; i++) {
      await runStep(steps[i], i, steps.length);
    }
    var totalSeconds = ((performance.now() - overallStart) / 1000).toFixed(1);
    progressEl.textContent = 'Done - ' + totalSeconds + 's total.';
    progressEl.className = 'progress progress--done';
  }

  function buildReport() {
    var lines = [];
    lines.push('UK Grid: Live+ - full refresh results');
    lines.push('Generated: ' + new Date().toString());
    lines.push('');
    sourceOrder.forEach(function (name) {
      var r = results[name];
      var header = '== ' + name + ' ==';
      if (name === 'ENTSOE') header += ' (' + r.calls + '/' + ENTSOE_CALLS + ' countries, ' + r.seconds.toFixed(1) + 's total)';
      else header += ' (' + r.seconds.toFixed(1) + 's)';
      lines.push(header);
      if (!r.entries.length) {
        lines.push('  No log entries captured' + (r.threw ? ' - threw: ' + r.threw : '') + '.');
      } else {
        r.entries.forEach(function (e, idx) {
          if (idx > 0) lines.push('  ---');
          lines.push('  Status: ' + e.status);
          lines.push('  Rows: ' + e.rows);
          if (e.message) lines.push('  Message: ' + e.message);
        });
      }
      lines.push('');
    });
    return lines.join('\n');
  }

  var copyBtn = document.getElementById('copy-btn');
  copyBtn.addEventListener('click', function () {
    var text = buildReport();
    var done = function () {
      copyBtn.textContent = 'Copied!';
      copyBtn.classList.add('btn--copied');
      setTimeout(function () {
        copyBtn.textContent = 'Copy results to clipboard';
        copyBtn.classList.remove('btn--copied');
      }, 1800);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text, done); });
    } else {
      fallbackCopy(text, done);
    }
  });
  function fallbackCopy(text, done) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { /* give up quietly */ }
    document.body.removeChild(ta);
  }

  runAll();
})();
</script>
</body>
</html>
