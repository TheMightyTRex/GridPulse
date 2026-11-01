#!/usr/bin/env python3
"""Write the shared country sections into every country page and the GB home page.

Run from the repo root, after tools/apply_shell.py:  python3 tools/apply_areas.py

Every country page follows the Great Britain home page's order:

  hero, "X% renewable right now", status figures (+ copy / CSV / snapshot),
  generation mix right now, replay today, Today so far + records cards,
  24-hour mix, history, cross-border flows, long-term history,
  records (since this site started recording), then the page's own
  About, "Records & context" and data sources.

The shared sections live between <!-- area:NAME:start --> and
<!-- area:NAME:end --> markers. This script rewrites them each time, so edit
them here, not in the pages. Each page's own text (intro, About, Records &
context, sources) is never touched. assets/areas.js fills the sections in.
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# page -> (area code, possessive name used in headings, has cross-border flows)
PAGES = {
    "index.html": ("GB", "Great Britain's", True),
    "pages/ireland.html": ("IE", "Ireland's", True),
    "pages/france.html": ("FR", "France's", True),
    "pages/nl.html": ("NL", "the Netherlands'", True),
    "pages/belgium.html": ("BE", "Belgium's", True),
    "pages/norway.html": ("NO", "southwest Norway's", True),
    "pages/denmark.html": ("DK", "western Denmark's", True),
    "pages/germany.html": ("DE", "Germany's", True),
    "pages/spain.html": ("ES", "Spain's", True),
    "pages/italy.html": ("IT", "Italy's", True),
    "pages/sweden.html": ("SE", "Sweden's (SE3)", True),
    "pages/portugal.html": ("PT", "Portugal's", True),
    "pages/poland.html": ("PL", "Poland's", True),
    "pages/usa.html": ("US", "the USA's", False),
    "pages/canada.html": ("CA", "Ontario's", False),
    "pages/australia.html": ("AU", "Australia's (NEM)", False),
}


def block(name, html):
    return f"<!-- area:{name}:start (written by tools/apply_areas.py) -->\n{html}\n<!-- area:{name}:end -->"


def strip_blocks(text):
    return re.sub(r"\n\n<!-- area:[a-z]+:start.*?<!-- area:[a-z]+:end -->", "", text, flags=re.S)


def sections(code, poss, flows, is_gb):
    now = block("now", f"""    <section class="home-hero area-now" data-area-now aria-label="Right now">
      <div class="home-hero__main">
        <p class="home-hero__pill"><span class="home-hero__dot" aria-hidden="true"></span><span class="home-hero__updated">Live, updated</span> <span data-area-now-time>Loading live data...</span></p>
        <p class="area-now__big"><span class="home-hero__share"><span data-area-now-share>-</span>%</span> <span class="home-hero__lead">of {poss} power is renewable right now</span></p>
      </div>
      <div class="home-hero__card"><p data-area-now-summary>Loading live data...</p></div>
    </section>""")


    figures = block("figures", """    <p class="download-row" data-area-figures></p>""")

    replay = block("replay", f"""    <section class="panel" aria-labelledby="replay-heading">
      <h2 id="replay-heading">Replay today</h2>
      <p class="chart-caption">Move the slider to see the generation mix at any point so far today.</p>
      <div data-area-replay><p class="area-empty">Loading live data...</p></div>
    </section>""")

    today_card = """      <section class="home-card home-card--today" data-area-today aria-labelledby="today-h">
        <h2 id="today-h">Today so far</h2>
        <div data-area-today-body><p class="area-empty">Loading live data...</p></div>
      </section>"""
    recsum_card = """      <section class="home-card home-card--records" data-area-recsum aria-labelledby="recsum-h">
        <h2 id="recsum-h">Records</h2>
        <div data-area-recsum-body><p class="area-empty">Loading...</p></div>
      </section>"""
    if is_gb:
        cards = block("cards", '    <div class="home-cards home-cards--one">\n' + today_card + "\n    </div>")
    else:
        cards = block("cards", '    <div class="home-cards">\n' + today_card + "\n" + recsum_card + "\n    </div>")

    later = []
    if flows:
        intro = {
            "GB": "Electricity through each of Great Britain's interconnectors today, grouped by country, from Elexon.",
            "IE": "The net flow between the island of Ireland and Great Britain today, from EirGrid.",
        }.get(code, "Physical flows across each border today, from the ENTSO-E Transparency Platform. Borders are fetched one at a time, so each one updates every few hours.")
        later.append(f"""    <section class="panel" aria-labelledby="flows-heading">
      <h2 id="flows-heading">Cross-border flows</h2>
      <p class="chart-caption">{intro}</p>
      <div data-area-flows><div data-area-flows-body><p class="area-empty">Loading...</p></div></div>
    </section>""")
    later.append("""    <section class="panel" aria-labelledby="annual-heading">
      <h2 id="annual-heading">Long-term history</h2>
      <p class="chart-caption">Electricity generated each year, by source, from each country's official yearly statistics.</p>
      <div data-area-annual><div data-area-annual-body><p class="area-empty">Loading...</p></div></div>
    </section>""")
    arch = "pages/archive.html" if is_gb else "archive.html"
    rec_title = "Records since we started recording" if is_gb else "Records"
    later.append(f"""    <section class="panel" id="area-records" aria-labelledby="area-records-heading">
      <h2 id="area-records-heading">{rec_title}</h2>
      <p class="chart-caption" data-area-records-since></p>
      <div data-area-records><div data-area-records-body><p class="area-empty">Loading...</p></div></div>
      <p class="download-row"><a class="btn" data-area-archive-link href="{arch}?area={code}">Browse every day in the archive</a></p>
    </section>""")
    return now, figures, replay, cards, block("later", "\n".join(later))


def section_end(text, label):
    m = re.search(r'<section[^>]*aria-labelledby="' + label + r'"[^>]*>.*?</section>', text, re.S)
    if not m:
        raise ValueError("no section " + label)
    return m.end()


def apply(path, code, poss, flows):
    text = path.read_text(encoding="utf-8")
    orig = text
    text = strip_blocks(text)
    is_gb = code == "GB"
    now, figures, replay, cards, later = sections(code, poss, flows, is_gb)

    # Later sections go after History (worked out first, so earlier
    # insertions don't move it).
    i = section_end(text, "history-heading")
    text = text[:i] + "\n\n" + later + text[i:]
    if is_gb:
        # GB keeps its own home cards; Today so far goes in its own row after them.
        m = re.search(r'<div class="home-cards">.*?</section>\s*</div>', text, re.S)
        if not m:
            raise ValueError("no home-cards in index.html")
        text = text[:m.end()] + "\n\n" + cards + text[m.end():]
        i = section_end(text, "mix-heading")
        text = text[:i] + "\n\n" + replay + text[i:]
    else:
        i = section_end(text, "mix-heading")
        text = text[:i] + "\n\n" + replay + "\n\n" + cards + text[i:]
        m = re.search(r'<p class="chart-caption"[^>]*id="stat-caption"[^>]*>.*?</p>', text, re.S)
        text = text[:m.end()] + "\n\n" + figures + text[m.end():]
        m = re.search(r'<section class="hero">.*?</section>', text, re.S)
        text = text[:m.end()] + "\n\n" + now + text[m.end():]

    # data-area on <main>, and the script after data.js.
    text = re.sub(r'<main id="main"(?: data-area="[A-Z]+")?>', f'<main id="main" data-area="{code}">', text, count=1)
    prefix = "" if path.parent == ROOT else "../"
    if "assets/areas.js" not in text:
        text = re.sub(r'(<script src="(?:\.\./)?assets/data\.js\?v=[0-9a-z]+"></script>)',
                      lambda mm: mm.group(1) + f'\n<script src="{prefix}assets/areas.js?v=0"></script>', text, count=1)
    if text != orig:
        path.write_text(text, encoding="utf-8")
        return True
    return False


def main():
    changed = 0
    for rel, (code, poss, flows) in PAGES.items():
        try:
            if apply(ROOT / rel, code, poss, flows):
                changed += 1
        except ValueError as e:
            print(rel, e, file=sys.stderr)
            sys.exit(1)
    print(f"{changed} of {len(PAGES)} pages updated")
    print("Now run python3 tools/apply_shell.py to set the cache-busting version.")


if __name__ == "__main__":
    main()
