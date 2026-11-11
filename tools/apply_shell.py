#!/usr/bin/env python3
"""Write the shared site header, menus, banner and Display panel into every page.

Run from the repo root:  python3 tools/apply_shell.py

Every page carries the same header between <!-- shell:start --> and
<!-- shell:end -->. Edit the menu here, not in the pages, then run this
script again: it rewrites that block in all 39 pages, sets the current
page and section, and keeps the font link and cache-busting version in step.
"""
import datetime
import html
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
VERSION = "20261112a"
FONTS_URL = "https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,100..900&display=swap"
# Loaded normally, so text draws once in Archivo instead of jumping from a
# fallback font. The preconnect lines in each page keep it quick.
FONTS = '<link href="' + FONTS_URL + '" rel="stylesheet">'

# Menu: (group id, label, [(column heading, [(page file, link text, short description)])])
GROUPS = [
    ("gb", "Great Britain", [
        ("Live now", [
            ("index.html", "Overview", "Live figures and the mix right now"),
            ("electricity-prices.html", "Prices", "Wholesale prices today"),
            ("gas.html", "Gas", "Gas use and supply right now"),
            ("power-cuts.html", "Power cuts", "Live power cuts on a map"),
            ("regional-carbon.html", "Carbon by area", "How clean power is where you are"),
            ("frequency.html", "Frequency", "The grid's 50 Hz, second by second"),
        ]),
        ("Plan ahead", [
            ("best-time.html", "Best time to use power", "The cleanest time to run things"),
            ("forecast.html", "Forecasts", "Carbon, demand and wind, next 2 days"),
        ]),
        ("By source", [
            ("renewables.html", "Renewables", "Wind, solar and hydro"),
            ("fossil-fuels.html", "Fossil fuels", "Gas, coal and oil"),
            ("nuclear-biomass.html", "Nuclear & biomass", "Output from each plant type"),
            ("storage.html", "Storage", "Batteries and pumped hydro"),
            ("interconnectors.html", "Interconnectors", "Imports and exports by country"),
            ("power-stations.html", "Power stations", "Every major station on a map"),
        ]),
        ("Over time", [
            ("history.html", "History", "From the past day to the past decade"),
            ("price-history.html", "Historic prices", "Wholesale prices over the years"),
            ("renewables-plans.html", "2030 plans", "Clean Power 2030 targets and progress"),
            ("balancing-costs.html", "Balancing costs", "What it costs to keep the grid balanced"),
        ]),
    ]),
    ("europe", "Europe", [
        ("Linked to Great Britain", [
            ("ireland.html", "Ireland", ""),
            ("france.html", "France", ""),
            ("nl.html", "Netherlands", ""),
            ("belgium.html", "Belgium", ""),
            ("norway.html", "Norway", ""),
            ("denmark.html", "Denmark", ""),
        ]),
        ("Rest of Europe", [
            ("germany.html", "Germany", ""),
            ("spain.html", "Spain", ""),
            ("italy.html", "Italy", ""),
            ("sweden.html", "Sweden", ""),
            ("portugal.html", "Portugal", ""),
            ("poland.html", "Poland", ""),
        ]),
        ("Europe-wide", [
            ("eu.html", "Europe overview", "Every country on one page"),
        ]),
    ]),
    ("world", "Rest of the world", [
        ("Americas", [
            ("usa.html", "USA", ""),
            ("canada.html", "Canada", ""),
            ("south-america.html", "South America", ""),
            ("americas-interconnects.html", "Americas interconnectors", ""),
        ]),
        ("Asia-Pacific", [
            ("australia.html", "Australia", ""),
        ]),
    ]),
    ("solar", "Plug-in solar", [
        ("Get started", [
            ("plugin-solar.html", "What is plug-in solar", ""),
            ("plugin-solar-considerations.html", "Considerations", ""),
            ("plugin-solar-calculator.html", "Energy calculator", ""),
        ]),
        ("Fitting it", [
            ("plugin-solar-safety.html", "Safety", ""),
            ("plugin-solar-mounting.html", "Mounting", ""),
            ("plugin-solar-battery.html", "Battery power", ""),
        ]),
        ("The rules", [
            ("plugin-solar-registration.html", "Registration", ""),
            ("plugin-solar-certification.html", "Certification", ""),
        ]),
    ]),
]
LINKS = [("comparisons.html", "Compare"), ("data-sources.html", "Sources"), ("about.html", "About")]

LOGO = """<svg class="brand__mark" width="40" height="40" viewBox="0 0 48 48" fill="none" aria-hidden="true" focusable="false">
        <rect class="gp-bar" style="--i:0" x="4" y="19" width="4.6" height="10" rx="2.3" fill="#ffffff"/>
        <rect class="gp-bar" style="--i:1" x="11" y="16" width="4.6" height="16" rx="2.3" fill="#ffffff"/>
        <rect class="gp-bar" style="--i:2" x="18" y="9" width="4.6" height="30" rx="2.3" fill="#ffd54a"/>
        <rect class="gp-bar" style="--i:3" x="25" y="18" width="4.6" height="12" rx="2.3" fill="#ffffff"/>
        <rect class="gp-bar" style="--i:4" x="32" y="13" width="4.6" height="22" rx="2.3" fill="#ffffff"/>
        <rect class="gp-bar" style="--i:5" x="39" y="20" width="4.6" height="8" rx="2.3" fill="#ffffff"/>
        <circle class="ug-ping" cx="45" cy="24" r="3" fill="#4fd18b"/>
        <circle class="gp-node" cx="45" cy="24" r="3" fill="#4fd18b"/>
      </svg>"""


def link_for(base, page):
    """base: "root" (index.html), "pages" (pages/*.html) or "abs" (errors/*.html).

    The error pages are served by the web server at whatever address was
    asked for (a missing /pages/x/y.html shows errors/404.html), so their
    links have to start from the site root, not from where the file sits.
    """
    if base == "abs":
        return "/index.html" if page == "index.html" else "/pages/" + page
    if page == "index.html":
        return "index.html" if base == "root" else "../index.html"
    return ("pages/" + page) if base == "root" else page


def section_of(page):
    for gid, _, cols in GROUPS:
        for _, items in cols:
            if any(p == page for p, _, _ in items):
                return gid
    return {"comparisons.html": "compare", "data-sources.html": "sources", "about.html": "about"}.get(page, "")


def build(page, base):
    sec = section_of(page)
    out = []
    out.append('<!-- shell:start (written by tools/apply_shell.py - edit the menu there, not here) -->')
    out.append('<div class="site-head">')
    out.append('<header class="site-header">')
    out.append('  <div class="wrap site-header__bar">')
    out.append(f'    <a class="brand ug-logo" href="{link_for(base, "index.html")}" aria-label="Grid Pulse, home">')
    out.append('      ' + LOGO)
    out.append('      <span class="brand__name" aria-hidden="true"><span class="brand__uk">Grid</span><span class="brand__tag ug-tag">Pulse</span></span>')
    out.append('    </a>')
    out.append('    <nav class="site-nav" aria-label="Main">')
    out.append('      <ul id="site-nav-list" data-nav-list>')
    for gid, label, cols in GROUPS:
        active = ' data-active="true"' if sec == gid else ''
        out.append('        <li class="nav-group">')
        out.append(f'          <button type="button" class="nav-group__toggle" id="navgroup-{gid}-btn" aria-expanded="false" aria-controls="navgroup-{gid}" data-nav-group{active}>')
        out.append(f'            {html.escape(label)}')
        out.append('            <svg class="nav-group__chevron" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>')
        out.append('          </button>')
        out.append(f'          <div class="nav-group__menu nav-panel nav-panel--{gid}" id="navgroup-{gid}">')
        for ci, (heading, items) in enumerate(cols):
            hid = f"navhead-{gid}-{ci}"
            out.append('            <div class="nav-panel__col">')
            out.append(f'              <p class="nav-panel__heading" id="{hid}">{html.escape(heading)}</p>')
            out.append(f'              <ul aria-labelledby="{hid}">')
            for p, text, desc in items:
                cur = ' aria-current="page"' if p == page else ''
                d = f'<span class="nav-panel__desc">{html.escape(desc)}</span>' if desc else ''
                out.append(f'                <li><a href="{link_for(base, p)}"{cur}><span class="nav-panel__name">{html.escape(text)}</span>{d}</a></li>')
            out.append('              </ul>')
            out.append('            </div>')
        out.append('          </div>')
        out.append('        </li>')
    for p, text in LINKS:
        cur = ' aria-current="page"' if p == page else ''
        out.append(f'        <li class="nav-link"><a href="{link_for(base, p)}"{cur}>{html.escape(text)}</a></li>')
    out.append('      </ul>')
    out.append('    </nav>')
    out.append('    <div class="header-controls">')
    out.append('      <button type="button" class="display-btn" data-display-toggle aria-expanded="false" aria-controls="display-panel">')
    out.append('        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3.2" stroke="currentColor" stroke-width="1.8"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>')
    out.append('        <span>Display</span>')
    out.append('      </button>')
    out.append('      <button type="button" class="nav-toggle" data-nav-toggle aria-expanded="false" aria-controls="site-nav-list">')
    out.append('        <span class="nav-toggle__icon" aria-hidden="true"></span>')
    out.append('        <span data-nav-toggle-label>Menu</span>')
    out.append('      </button>')
    out.append('    </div>')
    out.append('  </div>')
    out.append('</header>')
    # The yellow banner takes turns, one per page load: the paid placement
    # (Dave's PrivadoVPN affiliate link; rel="sponsored" tells search
    # engines it is an ad) and a "New" pointer to the widgets page. Without
    # JavaScript (or browser storage) the ad shows.
    out.append('<aside class="preview-banner" aria-label="Advertisement" data-banner-rotate>'
               '<span class="preview-banner__item" data-banner="ad"><span class="preview-banner__tag">Ad</span> <span><a href="https://privadovpn.com/getprivadovpn/#a_aid=themightytrexuk" rel="sponsored noopener">PrivadoVPN</a>: Instantly protect your PC, phone, or tablet.</span></span>'
               f'<span class="preview-banner__item" data-banner="new" hidden><span class="preview-banner__tag">New</span> <span><a href="{link_for(base, "widgets.html")}">Add a widget</a>: Show live electricity figures on your own site, free.</span></span>'
               '</aside>')
    out.append(BANNER_SCRIPT)
    out.append(DISPLAY_PANEL)
    out.append('</div>')
    out.append('<!-- shell:end -->')
    return "\n".join(out)


# Picks which banner to show on this page load, before the page paints.
BANNER_SCRIPT = ('<script>(function(){var b=document.querySelector("[data-banner-rotate]");if(!b)return;var n=0;'
                 'try{n=(parseInt(localStorage.getItem("gp-banner")||"0",10)||0)+1;localStorage.setItem("gp-banner",String(n))}catch(e){return}'
                 'if(n%2===0){b.querySelector(\'[data-banner="ad"]\').hidden=true;b.querySelector(\'[data-banner="new"]\').hidden=false;b.setAttribute("aria-label","New on Grid Pulse")}})();</script>')

DISPLAY_PANEL = """<div id="display-panel" class="display-panel" role="dialog" aria-modal="false" aria-labelledby="display-panel-heading" hidden>
  <h2 id="display-panel-heading">Display and accessibility</h2>
  <fieldset><legend>Theme</legend>
    <div class="seg">
      <label><input type="radio" name="pref-theme" value="system"><span>Auto</span></label>
      <label><input type="radio" name="pref-theme" value="light"><span>Light</span></label>
      <label><input type="radio" name="pref-theme" value="dark"><span>Dark</span></label>
    </div>
  </fieldset>
  <fieldset><legend>Text size</legend>
    <div class="seg">
      <label><input type="radio" name="pref-size" value="100"><span>100%</span></label>
      <label><input type="radio" name="pref-size" value="125"><span>125%</span></label>
      <label><input type="radio" name="pref-size" value="150"><span>150%</span></label>
      <label><input type="radio" name="pref-size" value="175"><span>175%</span></label>
    </div>
  </fieldset>
  <label class="pref-switch"><span>Low-vision font<small>Atkinson Hyperlegible Next, designed for low vision</small></span><input type="checkbox" data-pref="readable"></label>
  <label class="pref-switch"><span>High contrast</span><input type="checkbox" data-pref="hc"></label>
  <label class="pref-switch"><span>Reduce motion<small>Stops the logo and loading animations</small></span><input type="checkbox" data-pref="calm"></label>
  <label class="pref-switch"><span>Underline links</span><input type="checkbox" data-pref="underline"></label>
  <label class="pref-switch"><span>Wider text spacing</span><input type="checkbox" data-pref="spacing"></label>
  <div class="display-panel__foot"><button type="button" class="btn" data-pref-reset>Reset</button><button type="button" class="btn btn--primary" data-pref-done>Done</button></div>
</div>"""

SITE = "https://gridpulse.info"

# Matomo analytics (Dave's code, November 2026). The script goes in <head>;
# the <noscript> image goes straight after <body>, because an <img> isn't
# allowed inside <head> and browsers would move it anyway.
MATOMO = """<!-- Matomo -->
<script>
  var _paq = window._paq = window._paq || [];
  /* tracker methods like "setCustomDimension" should be called before "trackPageView" */
  _paq.push(['trackPageView']);
  _paq.push(['enableLinkTracking']);
  (function() {
    var u="//www.gridpulse.info/stat/";
    _paq.push(['setTrackerUrl', u+'matomo.php']);
    _paq.push(['setSiteId', '1']);
    var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
    g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
  })();
</script>
<!-- End Matomo Code -->"""
MATOMO_NOSCRIPT = """<!-- Matomo noscript -->
<noscript><p><img referrerpolicy="no-referrer-when-downgrade" src="//www.gridpulse.info/stat/matomo.php?idsite=1&amp;rec=1" style="border:0;" alt="" /></p></noscript>
<!-- End Matomo noscript -->"""
# Impact affiliate tracking (Dave's code, November 2026), in <head> on every page.
IMPACT = """<!-- Impact -->
<script type="text/javascript">(function(i,m,p,a,c,t){c.ire_o=p;c[p]=c[p]||function(){(c[p].a=c[p].a||[]).push(arguments)};t=a.createElement(m);var z=a.getElementsByTagName(m)[0];t.async=1;t.src=i;z.parentNode.insertBefore(t,z)})('https://utt.impactcdn.com/P-A8123239-1d4a-4d46-99c5-7f0dd0e3166e1.js','script','impactStat',document,window);impactStat('transformLinks');impactStat('trackImpression');</script>
<!-- End Impact -->"""
IMPACT_BLOCK = re.compile(r'<!-- Impact -->.*?<!-- End Impact -->\n?', re.S)
MATOMO_BLOCK = re.compile(r'<!-- Matomo -->.*?<!-- End Matomo Code -->', re.S)
MATOMO_NS_BLOCK = re.compile(r'<!-- Matomo noscript -->.*?<!-- End Matomo noscript -->\n?', re.S)
HEAD_BLOCK = re.compile(r'<!-- head:start.*?<!-- head:end -->\n?', re.S)
OLD_ICON = re.compile(r'<link rel="icon" href="[^"]*logo\.svg" type="image/svg\+xml">\n?')


# Pages that show data, and the place each one covers. They get schema.org
# Dataset markup so search engines and AI tools can tell what the page holds.
DATA_PAGES = {
    "index.html": "Great Britain", "ireland.html": "Ireland", "france.html": "France", "nl.html": "Netherlands",
    "belgium.html": "Belgium", "norway.html": "Norway", "denmark.html": "Denmark", "germany.html": "Germany",
    "spain.html": "Spain", "italy.html": "Italy", "sweden.html": "Sweden", "portugal.html": "Portugal",
    "poland.html": "Poland", "usa.html": "United States", "canada.html": "Ontario, Canada", "australia.html": "Australia",
    "eu.html": "Europe", "gas.html": "Great Britain", "power-cuts.html": "Great Britain",
    "interconnectors.html": "Great Britain", "electricity-prices.html": "Great Britain", "history.html": "Great Britain",
    "records.html": "Great Britain", "renewables.html": "Great Britain", "fossil-fuels.html": "Great Britain",
    "nuclear-biomass.html": "Great Britain", "storage.html": "Great Britain", "price-history.html": "Great Britain",
    "forecast.html": "Great Britain", "best-time.html": "Great Britain", "regional-carbon.html": "Great Britain",
    "frequency.html": "Great Britain", "balancing-costs.html": "Great Britain", "power-stations.html": "United Kingdom",
}


# The JSON behind each data page, offered to search engines and AI tools.
DATA_APIS = {
    "index.html": "day.php?area=GB", "ireland.html": "day.php?area=IE", "france.html": "day.php?area=FR",
    "nl.html": "day.php?area=NL", "belgium.html": "day.php?area=BE", "norway.html": "day.php?area=NO",
    "denmark.html": "day.php?area=DK", "germany.html": "day.php?area=DE", "spain.html": "day.php?area=ES",
    "italy.html": "day.php?area=IT", "sweden.html": "day.php?area=SE", "portugal.html": "day.php?area=PT",
    "poland.html": "day.php?area=PL", "usa.html": "day.php?area=US", "canada.html": "day.php?area=CA",
    "australia.html": "day.php?area=AU", "eu.html": "now_all.php", "gas.html": "gas.php",
    "power-cuts.html": "power_cuts.php", "interconnectors.html": "flows.php?area=GB", "records.html": "records.php?area=GB",
    "forecast.html": "forecast.php", "best-time.html": "forecast.php", "regional-carbon.html": "forecast.php",
    "frequency.html": "frequency.php", "balancing-costs.html": "balancing.php", "power-stations.html": "stations.php",
}


def page_group(name):
    """The menu group (label) a page sits under, for the breadcrumb markup."""
    for _gid, label, cols in GROUPS:
        for _h, links in cols:
            if any(f == name for f, _t, _d in links):
                return label
    return None


def structured_data(path, url, title, desc):
    """schema.org JSON-LD for the page: who runs the site, the page itself,
    where it sits, and (for data pages) what data it holds. Not shown on screen."""
    import json as _json
    name = re.sub(r"\s+-\s+Grid Pulse$", "", html.unescape(title)).strip()
    d = html.unescape(desc)
    org = {"@type": "Organization", "@id": SITE + "/#org", "name": "Grid Pulse", "url": SITE + "/",
           "logo": {"@type": "ImageObject", "url": SITE + "/assets/gridpulse-icon-512.png", "width": 512, "height": 512}}
    site = {"@type": "WebSite", "@id": SITE + "/#site", "name": "Grid Pulse", "url": SITE + "/", "inLanguage": "en-GB",
            "publisher": {"@id": SITE + "/#org"},
            "description": "Live electricity grids, from Great Britain to Europe and beyond: where the power comes from right now, what it costs and how clean it is."}
    # Every breadcrumb step needs its own address, so a menu group is only
    # included when it has a page of its own.
    crumbs = [{"@type": "ListItem", "position": 1, "name": "Home", "item": SITE + "/"}]
    group = page_group(path.name)
    group_page = {"Europe": "eu.html", "Plug-in solar": "plugin-solar.html"}.get(group)
    if path.name != "index.html":
        if group_page and group_page != path.name:
            crumbs.append({"@type": "ListItem", "position": 2, "name": group, "item": f"{SITE}/pages/{group_page}"})
        crumbs.append({"@type": "ListItem", "position": len(crumbs) + 1, "name": name, "item": url})
    page = {"@type": "WebPage", "@id": url + "#page", "url": url, "name": name, "description": d, "inLanguage": "en-GB",
            "dateModified": datetime.date.today().isoformat(),
            "primaryImageOfPage": {"@type": "ImageObject", "url": SITE + "/assets/gridpulse-social.png", "width": 1200, "height": 630},
            "isPartOf": {"@id": SITE + "/#site"}, "publisher": {"@id": SITE + "/#org"}}
    if len(crumbs) > 1:
        page["breadcrumb"] = {"@type": "BreadcrumbList", "itemListElement": crumbs}
    graph = [org, site, page]
    place = DATA_PAGES.get(path.name)
    if place:
        ds = {"@type": "Dataset", "@id": url + "#data", "name": name,
              "description": d if len(d) >= 50 else f"{name}: live and recent electricity data for {place}, from official sources, on Grid Pulse.",
              "url": url, "isAccessibleForFree": True,
              "creator": {"@id": SITE + "/#org"}, "publisher": {"@id": SITE + "/#org"},
              "spatialCoverage": {"@type": "Place", "name": place},
              "keywords": ["electricity", "power grid", "energy", place],
              "isBasedOn": SITE + "/pages/data-sources.html", "dateModified": datetime.date.today().isoformat()}
        api = DATA_APIS.get(path.name)
        if api:
            ds["distribution"] = [{"@type": "DataDownload", "encodingFormat": "application/json", "contentUrl": f"{SITE}/api/{api}"}]
        graph.append(ds)
        page["about"] = {"@id": url + "#data"}
    return '<script type="application/ld+json">' + _json.dumps({"@context": "https://schema.org", "@graph": graph}, ensure_ascii=False, separators=(",", ":")).replace("</", "<\\/") + "</script>"


def head_block(path, base, text):
    """Icons, canonical address and share-card tags, built from the page's own title and description."""
    title = re.search(r'<title>(.*?)</title>', text, re.S)
    desc = re.search(r'<meta name="description" content="([^"]*)">', text)
    title = title.group(1).strip() if title else "Grid Pulse"
    desc = desc.group(1) if desc else ""
    a = {"root": "", "pages": "../", "abs": "/"}[base]
    out = ['<!-- head:start (written by tools/apply_shell.py) -->',
           f'<link rel="icon" href="{a}assets/gridpulse-favicon.svg" type="image/svg+xml">',
           f'<link rel="icon" href="{a}assets/gridpulse-favicon-48.png" sizes="48x48" type="image/png">',
           f'<link rel="apple-touch-icon" href="{a}assets/gridpulse-apple-touch-icon-180.png">',
           f'<link rel="manifest" href="{a}site.webmanifest">',
           '<meta name="theme-color" content="#14181f">',
           # Set the theme before the page paints: light unless the Display
           # panel says otherwise (assets/app.js applies the full settings).
           '<script>(function(){var t="light";try{var p=JSON.parse(localStorage.getItem("grid-live-prefs")||"null");'
           'if(p&&p.theme){t=p.theme}else{var o=localStorage.getItem("grid-live-theme");if(o==="dark"||o==="light")t=o}}catch(e){}'
           'if(t!=="system")document.documentElement.setAttribute("data-theme",t==="dark"?"dark":"light")})();</script>']
    if base != "abs":
        url = SITE + "/" if path.name == "index.html" and base == "root" else f"{SITE}/pages/{path.name}"
        out += [f'<link rel="canonical" href="{url}">',
                '<meta property="og:site_name" content="Grid Pulse">',
                '<meta property="og:type" content="website">',
                f'<meta property="og:title" content="{title}">',
                f'<meta property="og:description" content="{desc}">' if desc else '',
                f'<meta property="og:url" content="{url}">',
                f'<meta property="og:image" content="{SITE}/assets/gridpulse-social.png?v={VERSION}">',
                '<meta property="og:image:width" content="1200">',
                '<meta property="og:image:height" content="630">',
                '<meta property="og:image:alt" content="Grid Pulse logo with the line: Live electricity grids, from Great Britain to Europe and beyond.">',
                '<meta property="og:image:type" content="image/png">',
                '<meta property="og:locale" content="en_GB">',
                '<meta name="twitter:card" content="summary_large_image">',
                f'<meta name="twitter:title" content="{title}">',
                f'<meta name="twitter:description" content="{desc}">' if desc else '',
                f'<meta name="twitter:image" content="{SITE}/assets/gridpulse-social.png?v={VERSION}">',
                '<meta name="twitter:image:alt" content="Grid Pulse logo with the line: Live electricity grids, from Great Britain to Europe and beyond.">',
                structured_data(path, url, title, desc)]
        if path.name == "index.html" and base == "root":
            # Bing Webmaster Tools ownership check (Dave's code). Search Console uses google122e67112e88d290.html.
            out.append('<meta name="msvalidate.01" content="1B46D77F8BA55A0A5007078591D260D7" />')
    else:
        out.append('<meta name="robots" content="noindex">')
    out.append('<!-- head:end -->')
    return "\n".join(x for x in out if x) + "\n"


OLD_BLOCK = re.compile(r'(<aside class="preview-banner".*?</aside>\s*)?<div class="site-head">.*?</nav>\s*</div>', re.S)
SHELL_BLOCK = re.compile(r'<!-- shell:start.*?<!-- shell:end -->', re.S)


def main():
    pages = [ROOT / "index.html"] + sorted((ROOT / "pages").glob("*.html")) + sorted((ROOT / "errors").glob("*.html"))
    changed = 0
    for path in pages:
        base = "root" if path.parent == ROOT else ("abs" if path.parent.name == "errors" else "pages")
        text = path.read_text(encoding="utf-8")
        block = build(path.name, base)
        if SHELL_BLOCK.search(text):
            new = SHELL_BLOCK.sub(lambda m: block, text, count=1)
        elif OLD_BLOCK.search(text):
            new = OLD_BLOCK.sub(lambda m: block, text, count=1)
        else:
            print("no header found in", path, file=sys.stderr)
            sys.exit(1)
        hb = head_block(path, base, new)
        if HEAD_BLOCK.search(new):
            new = HEAD_BLOCK.sub(lambda m: hb, new, count=1)
        else:
            new = OLD_ICON.sub("", new, count=1)
            new = re.sub(r'(</title>\n(?:<meta name="description" content="[^"]*">\n)?)', lambda m: m.group(1) + hb, new, count=1)
        if MATOMO_BLOCK.search(new):
            new = MATOMO_BLOCK.sub(lambda m: MATOMO, new, count=1)
        else:
            new = new.replace("</head>", MATOMO + "\n</head>", 1)
        new = IMPACT_BLOCK.sub("", new)
        new = new.replace("</head>", IMPACT + "\n</head>", 1)
        new = MATOMO_NS_BLOCK.sub("", new)
        new = re.sub(r"(<body[^>]*>\n?)", lambda m: m.group(1) + MATOMO_NOSCRIPT + "\n", new, count=1)
        new = re.sub(r'<link rel="preload" as="style" href="https://fonts\.googleapis\.com/css2\?family=Archivo[^"]*" onload="[^"]*"><noscript><link href="[^"]*" rel="stylesheet"></noscript>|<link href="https://fonts\.googleapis\.com/css2\?family=Archivo[^"]*" rel="stylesheet">', lambda m: FONTS, new)
        if "design-c.css" not in new:
            new = re.sub(r'(<link rel="stylesheet" href="((?:\.\./)?)assets/style\.css\?v=[0-9a-z]+">)',
                         lambda m: m.group(1) + '\n<link rel="stylesheet" href="' + m.group(2) + 'assets/design-c.css?v=' + VERSION + '">', new, count=1)
        new = re.sub(r'(assets/[a-z\-]+\.(?:css|js))\?v=[0-9a-z]+', lambda m: m.group(1) + "?v=" + VERSION, new)
        if new != text:
            path.write_text(new, encoding="utf-8")
            changed += 1
    print(f"{changed} of {len(pages)} pages updated")
    write_crawler_files(pages)


# Pages that only make sense with a query (?area=...&date=...) are listed
# once, at their plain address. Error pages are left out.
def write_crawler_files(pages):
    """sitemap.xml, robots.txt and llms.txt at the site root."""
    import datetime
    today = datetime.date.today().isoformat()
    urls = []
    for path in pages:
        if path.parent.name == "errors":
            continue
        url = SITE + "/" if path.name == "index.html" and path.parent == ROOT else f"{SITE}/pages/{path.name}"
        pri = "1.0" if path.name == "index.html" else ("0.8" if path.name in DATA_PAGES else "0.5")
        urls.append(f"  <url><loc>{url}</loc><lastmod>{today}</lastmod><priority>{pri}</priority></url>")
    (ROOT / "sitemap.xml").write_text('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' + "\n".join(urls) + "\n</urlset>\n", encoding="utf-8")
    (ROOT / "robots.txt").write_text(
        "# Grid Pulse - every page may be crawled, by search engines and AI tools alike.\n"
        "User-agent: *\nAllow: /\nDisallow: /includes/\nDisallow: /cron/\nDisallow: /tools/\nDisallow: /docker/\n\n"
        f"Sitemap: {SITE}/sitemap.xml\n", encoding="utf-8")
    lines = ["# Grid Pulse", "",
             "> Live electricity grids, from Great Britain to Europe and beyond: where the power comes from right now, what it costs and how clean it is. Free, no sign-in.", "",
             "Figures come from official sources: Elexon, NESO and the Carbon Intensity API (Great Britain), EirGrid (Ireland), the ENTSO-E Transparency Platform (European countries), the US EIA, IESO (Ontario), Open Electricity (Australia), National Gas Transmission (Great Britain gas) and the electricity network operators (power cuts). Each page names its sources and when hand-checked facts were last checked.", "",
             "## Pages", ""]
    for path in pages:
        if path.parent.name == "errors":
            continue
        text = path.read_text(encoding="utf-8")
        tm = re.search(r"<title>(.*?)</title>", text, re.S)
        dm = re.search(r'<meta name="description" content="([^"]*)">', text)
        name = re.sub(r"\s+-\s+Grid Pulse$", "", html.unescape(tm.group(1).strip())) if tm else path.name
        url = SITE + "/" if path.name == "index.html" and path.parent == ROOT else f"{SITE}/pages/{path.name}"
        lines.append(f"- [{name}]({url})" + (": " + html.unescape(dm.group(1)) if dm and dm.group(1) else ""))
    lines += ["", "## Live data as JSON", "",
              f"- {SITE}/api/now_all.php : every covered country right now",
              f"- {SITE}/api/areas.php : the countries covered and their codes",
              f"- {SITE}/api/day.php?area=GB : one country's day so far, or a past day with &date=YYYY-MM-DD",
              f"- {SITE}/api/records.php?area=GB : records since this site started recording",
              f"- {SITE}/api/flows.php?area=GB : flows across a country's borders today",
              f"- {SITE}/api/gas.php : Great Britain's gas use and supply",
              f"- {SITE}/api/power_cuts.php : live power cuts in Great Britain",
              f"- {SITE}/api/forecast.php : Great Britain's next 2 days (carbon intensity, demand, wind), and carbon intensity by area (?region=1-14)",
              f"- {SITE}/api/frequency.php : Great Britain's grid frequency",
              f"- {SITE}/api/balancing.php : NESO's daily balancing costs",
              f"- {SITE}/api/stations.php : UK power stations (DESNZ DUKES 5.11) with live planned output for the biggest",
              "", f"More detail: {SITE}/pages/data-access.html", "",
              "Please credit Grid Pulse and the original source named on each page."]
    (ROOT / "llms.txt").write_text("\n".join(lines) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
