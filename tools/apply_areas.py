#!/usr/bin/env python3
"""Write the shared dashboard sections into every country page and the GB home page.

Run from the repo root, after tools/apply_shell.py:  python3 tools/apply_areas.py

Every country page is a dashboard in the same order as the Great Britain
home page:

  the page's own heading and intro
  "X% renewable right now"
  the figures (+ copy / CSV / snapshot)
  generation mix right now, with "Replay today" inside it
  cards: Today so far, Records, Borders (where there are flows), This year so far
  the last 24 hours
  [country] and its neighbours right now (European pages)
  history, then the long-term chart
  Explore tiles (Records, Borders, Day archive, ...)
  the page's own About (open), Records and context, and data sources (folded)

The shared parts live between <!-- area:NAME:start --> and
<!-- area:NAME:end --> markers and are rewritten each run, so edit them
here, not in the pages. Each page's own text is never changed: About,
"Records and context" and the sources are only wrapped in a fold.
assets/areas.js fills the sections in.

The GB home page keeps its own hero, figures, mix and Explore tiles; this
script only adds Replay, the cards and the area marker there.
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# page -> (area code, possessive used in headings, has per-border flows, European)
PAGES = {
    "index.html": ("GB", "Great Britain's", True, False),
    "pages/ireland.html": ("IE", "Ireland's", True, True),
    "pages/france.html": ("FR", "France's", True, True),
    "pages/nl.html": ("NL", "the Netherlands'", True, True),
    "pages/belgium.html": ("BE", "Belgium's", True, True),
    "pages/norway.html": ("NO", "southwest Norway's", True, True),
    "pages/denmark.html": ("DK", "western Denmark's", True, True),
    "pages/germany.html": ("DE", "Germany's", True, True),
    "pages/spain.html": ("ES", "Spain's", True, True),
    "pages/italy.html": ("IT", "Italy's", True, True),
    "pages/sweden.html": ("SE", "Sweden's (SE3)", True, True),
    "pages/portugal.html": ("PT", "Portugal's", True, True),
    "pages/poland.html": ("PL", "Poland's", True, True),
    "pages/usa.html": ("US", "the USA's", False, False),
    "pages/canada.html": ("CA", "Ontario's", False, False),
    "pages/australia.html": ("AU", "Australia's (NEM)", False, False),
}
NAMES = {"GB": "Great Britain", "IE": "Ireland", "FR": "France", "NL": "the Netherlands", "BE": "Belgium",
         "NO": "Norway", "DK": "Denmark", "DE": "Germany", "ES": "Spain", "IT": "Italy", "SE": "Sweden",
         "PT": "Portugal", "PL": "Poland", "US": "the USA", "CA": "Ontario", "AU": "Australia"}


def block(name, html):
    return f"<!-- area:{name}:start (written by tools/apply_areas.py) -->\n{html}\n<!-- area:{name}:end -->"


def strip_blocks(text):
    """Removes every block this script wrote, with exactly the white space it added."""
    text = re.sub(r"\n\n<!-- area:([a-z]+):start.*?<!-- area:\1:end -->", "", text, flags=re.S)
    text = re.sub(r"<!-- area:replay:start.*?<!-- area:replay:end -->\n    ", "", text, flags=re.S)
    text = re.sub(r"<!-- area:fold[a-c]open:start.*?<!-- area:fold[a-c]open:end -->\n", "", text, flags=re.S)
    return re.sub(r"\n<!-- area:fold[a-c]close:start.*?<!-- area:fold[a-c]close:end -->", "", text, flags=re.S)


def section_span(text, label):
    m = re.search(r'<section[^>]*aria-labelledby="' + label + r'"[^>]*>.*?</section>', text, re.S)
    if not m:
        raise ValueError("no section " + label)
    return m.start(), m.end()


def cards(code, flows):
    today = """      <section class="gp-card gp-card--dark" data-area-today aria-labelledby="today-h">
        <h2 id="today-h" class="gp-card__h">Today so far</h2>
        <div data-area-today-body><p class="area-empty">Loading live data…</p></div>
      </section>"""
    records = """      <section class="gp-card" data-area-recsum aria-labelledby="recsum-h">
        <h2 id="recsum-h" class="gp-card__h">Records</h2>
        <div data-area-recsum-body><p class="area-empty">Loading…</p></div>
      </section>"""
    borders = f"""      <section class="gp-card" data-area-borders aria-labelledby="borders-h">
        <h2 id="borders-h" class="gp-card__h">{"Interconnectors" if code == "GB" else "Borders"}</h2>
        <div data-area-borders-body><p class="area-empty">Loading…</p></div>
      </section>"""
    year = """      <section class="gp-card" data-area-year aria-labelledby="year-h">
        <h2 id="year-h" class="gp-card__h">This year so far</h2>
        <div data-area-year-body><p class="area-empty">Loading…</p></div>
      </section>"""
    # Two columns that stack independently, so a short card never leaves a
    # gap beside a tall one: Today and the map on the left, Records and
    # This year on the right (Today and This year when there's no map).
    # On narrow screens the columns dissolve and the cards stack in reading
    # order: Today, Records, Borders, This year (CSS "order").
    left = [today] + ([borders] if flows else [year])
    right = [records] + ([year] if flows else [])
    col = lambda cards: '      <div class="gp-cards__col">\n' + "\n".join(c.replace("\n      ", "\n        ").replace("      <section", "        <section", 1) for c in cards) + "\n      </div>"
    return block("cards", '    <div class="gp-cards">\n' + col(left) + "\n" + col(right) + "\n    </div>")


def apply(path, code, poss, flows, europe):
    text = path.read_text(encoding="utf-8")
    orig = text
    text = strip_blocks(text)
    is_gb = code == "GB"
    prefix = "" if path.parent == ROOT else "../"
    name = NAMES[code]

    replay = block("replay", """      <div class="gp-replay" aria-labelledby="replay-heading" role="group">
        <h3 id="replay-heading">Replay today</h3>
        <div data-area-replay><p class="area-empty">Loading live data…</p></div>
      </div>""")

    # Work from the bottom of the page up, so earlier positions stay valid.
    if not is_gb:
        # Fold About (open), Records and context, and the sources.
        for label, opened in (("[a-z]+-sources-heading", False), ("records-heading", False), ("about-heading", True)):
            s, e = section_span(text, label)
            h2 = re.search(r"<h2[^>]*>(.*?)</h2>", text[s:e], re.S).group(1)
            key = {"about-heading": "a", "records-heading": "b"}.get(label, "c")
            text = (text[:s] + block("fold" + key + "open", f'    <details class="gp-fold"{" open" if opened else ""}>\n      <summary><h2 class="gp-fold__h">{h2}</h2></summary>')
                    + "\n" + text[s:e] + "\n" + block("fold" + key + "close", "    </details>") + text[e:])

        explore = [
            ("Records", "data-area-records-link", f"records.html?area={code}", f"Since this site started recording {name}"),
        ]
        if flows:
            explore.append(("Borders", "data-area-borders-link", f"borders.html?area={code}", "Every neighbour, in and out"))
        explore += [
            ("History", "", "#history-heading", "A day to 10 years, and yearly"),
            ("Day archive", "data-area-archive-link", f"archive.html?area={code}", "Any day, shaded by renewables"),
            ("Compare", "", "comparisons.html", f"{name[0].upper() + name[1:]} against other grids"),
        ]
        tiles = "\n".join(f'        <a class="gp-tile" {attr} href="{href}"><strong>{t}</strong><span>{sub}</span></a>'.replace("  href", " href")
                          for t, attr, href, sub in explore)
        later = []
        later.append(f"""    <section class="panel" aria-labelledby="annual-heading">
      <h2 id="annual-heading">Long-term history</h2>
      <p class="chart-caption">Electricity generated each year, by source, from the country's official yearly statistics.</p>
      <div data-area-annual><div data-area-annual-body><p class="area-empty">Loading…</p></div></div>
    </section>""")
        later.append(f"""    <section aria-labelledby="explore-heading" class="gp-explore">
      <h2 id="explore-heading">Explore {name}</h2>
      <div class="gp-tiles">
{tiles}
      </div>
    </section>""")
        s, e = section_span(text, "history-heading")
        text = text[:e] + "\n\n" + block("later", "\n".join(later)) + text[e:]

        if europe:
            cmp_title = name[0].upper() + name[1:]
            compare = block("compare", f"""    <section class="panel gp-dark" data-area-compare aria-labelledby="compare-heading">
      <div class="gp-head"><h2 id="compare-heading">{cmp_title} and its neighbours right now</h2><a href="comparisons.html">Compare any countries →</a></div>
      <div data-area-compare-body><p class="area-empty">Loading…</p></div>
    </section>""")
            s, e = section_span(text, "stack-heading")
            text = text[:e] + "\n\n" + compare + text[e:]

    # Cards after the mix; Replay inside the mix panel.
    s, e = section_span(text, "mix-heading")
    text = text[:e] + "\n\n" + cards(code, flows) + text[e:]
    close = text.rfind("</section>", s, e + 1)
    text = text[:close] + replay + "\n    " + text[close:]

    if not is_gb:
        m = re.search(r'<p class="chart-caption"[^>]*id="stat-caption"[^>]*>.*?</p>', text, re.S)
        text = text[:m.end()] + "\n\n" + block("figures", '    <p class="download-row" data-area-figures></p>') + text[m.end():]
        m = re.search(r'<section class="hero">.*?</section>', text, re.S)
        now = block("now", f"""    <section class="home-hero area-now" data-area-now aria-label="Right now">
      <div class="home-hero__main">
        <p class="home-hero__pill"><span class="home-hero__dot" aria-hidden="true"></span><span class="home-hero__updated">Live, updated at:</span> <span data-area-now-time>Loading live data…</span></p>
        <p class="area-now__big"><span class="home-hero__share"><span data-area-now-share>-</span>%</span> <span class="home-hero__lead">of {poss} power is renewable right now</span></p>
      </div>
      <div class="home-hero__card"><p data-area-now-summary>Loading live data…</p></div>
    </section>""")
        text = text[:m.end()] + "\n\n" + now + text[m.end():]

    text = re.sub(r'<main id="main"(?: data-area="[A-Z]+")?>', f'<main id="main" data-area="{code}">', text, count=1)
    if "assets/areas.js" not in text:
        text = re.sub(r'(<script src="(?:\.\./)?assets/data\.js\?v=[0-9a-z]+"></script>)',
                      lambda mm: mm.group(1) + f'\n<script src="{prefix}assets/areas.js?v=0"></script>', text, count=1)
    if is_gb and "assets/alerts.js" not in text:
        text = re.sub(r'(<script src="assets/areas\.js\?v=[0-9a-z]+"></script>)',
                      lambda mm: mm.group(1) + '\n<script src="assets/alerts.js?v=0"></script>', text, count=1)
    if text != orig:
        path.write_text(text, encoding="utf-8")
        return True
    return False


def main():
    changed = 0
    for rel, (code, poss, flows, europe) in PAGES.items():
        try:
            if apply(ROOT / rel, code, poss, flows, europe):
                changed += 1
        except (ValueError, AttributeError) as e:
            print(rel, e, file=sys.stderr)
            sys.exit(1)
    print(f"{changed} of {len(PAGES)} pages updated")
    print("Now run python3 tools/apply_shell.py to set the cache-busting version.")


if __name__ == "__main__":
    main()
