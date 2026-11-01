#!/usr/bin/env python3
"""Write the shared site header, menus, banner and Display panel into every page.

Run from the repo root:  python3 tools/apply_shell.py

Every page carries the same header between <!-- shell:start --> and
<!-- shell:end -->. Edit the menu here, not in the pages, then run this
script again: it rewrites that block in all 39 pages, sets the current
page and section, and keeps the font link and cache-busting version in step.
"""
import html
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
VERSION = "20261101b"
FONTS = '<link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,100..900&display=swap" rel="stylesheet">'

# Menu: (group id, label, [(column heading, [(page file, link text, short description)])])
GROUPS = [
    ("gb", "Great Britain", [
        ("Live now", [
            ("index.html", "Overview", "Live figures and the mix right now"),
            ("electricity-prices.html", "Prices", "Wholesale prices today"),
        ]),
        ("By source", [
            ("renewables.html", "Renewables", "Wind, solar and hydro"),
            ("fossil-fuels.html", "Fossil fuels", "Gas, coal and oil"),
            ("nuclear-biomass.html", "Nuclear & biomass", "Output from each plant type"),
            ("storage.html", "Storage", "Batteries and pumped hydro"),
            ("interconnectors.html", "Interconnectors", "Imports and exports by country"),
        ]),
        ("Over time", [
            ("history.html", "History", "From the past day to the past decade"),
            ("price-history.html", "Historic prices", "Wholesale prices over the years"),
            ("renewables-plans.html", "2030 plans", "Clean Power 2030 targets and progress"),
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
    if page != "plugin-solar.html":
        out.append(f'<aside class="preview-banner" aria-label="Site announcement"><span class="preview-banner__tag">New</span> <a href="{link_for(base, "plugin-solar.html")}">Read the plug-in solar guide</a></aside>')
    out.append(DISPLAY_PANEL)
    out.append('</div>')
    out.append('<!-- shell:end -->')
    return "\n".join(out)


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
HEAD_BLOCK = re.compile(r'<!-- head:start.*?<!-- head:end -->\n?', re.S)
OLD_ICON = re.compile(r'<link rel="icon" href="[^"]*logo\.svg" type="image/svg\+xml">\n?')


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
           '<meta name="theme-color" content="#14181f">']
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
                '<meta name="twitter:card" content="summary_large_image">']
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
        new = re.sub(r'<link href="https://fonts\.googleapis\.com/css2\?[^"]*" rel="stylesheet">', FONTS, new)
        if "design-c.css" not in new:
            new = re.sub(r'(<link rel="stylesheet" href="((?:\.\./)?)assets/style\.css\?v=[0-9a-z]+">)',
                         lambda m: m.group(1) + '\n<link rel="stylesheet" href="' + m.group(2) + 'assets/design-c.css?v=' + VERSION + '">', new, count=1)
        new = re.sub(r'(assets/[a-z\-]+\.(?:css|js))\?v=[0-9a-z]+', lambda m: m.group(1) + "?v=" + VERSION, new)
        if new != text:
            path.write_text(new, encoding="utf-8")
            changed += 1
    print(f"{changed} of {len(pages)} pages updated")


if __name__ == "__main__":
    main()
