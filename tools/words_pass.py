#!/usr/bin/env python3
"""One-off rewrite of site wording to the content design system (v2.2).

Run from the repo root: python3 tools/words_pass.py [--dry]
Covers the rules that can be applied safely by pattern: contractions written
out in full, times as 12.35pm, and the fixed empty, error and help strings.
Everything else was rewritten by hand.
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FILES = [ROOT / "index.html"] + sorted((ROOT / "pages").glob("*.html")) + [ROOT / "assets" / f for f in ("app.js", "data.js", "plugin-solar-data.js")]

CONTRACTIONS = {
    "don't": "do not", "doesn't": "does not", "didn't": "did not", "isn't": "is not", "aren't": "are not",
    "wasn't": "was not", "weren't": "were not", "can't": "cannot", "couldn't": "could not", "won't": "will not",
    "wouldn't": "would not", "shouldn't": "should not", "haven't": "have not", "hasn't": "has not", "hadn't": "had not",
    "needn't": "need not", "mustn't": "must not",
    "it's": "it is", "that's": "that is", "there's": "there is", "here's": "here is", "what's": "what is",
    "who's": "who is", "where's": "where is", "let's": "let us",
    "we're": "we are", "you're": "you are", "they're": "they are",
    "we've": "we have", "you've": "you have", "they've": "they have", "i've": "I have",
    "we'll": "we will", "you'll": "you will", "it'll": "it will", "they'll": "they will", "that'll": "that will",
    "i'm": "I am", "you'd": "you would", "we'd": "we would", "they'd": "they would",
}

FIXED = [
    (" Hover a slice for details.", ""),
    ("Hover or tap a bar for each cable's own figures", "Select a bar for each cable's figures"),
    ("Live data is currently unavailable. The figures below could not be loaded - see the footer for the data pipeline's status.",
     "The live figures did not load. Try again in a few minutes."),
    ("Live data is currently unavailable - the figures shown are from the last successful update, not right now.",
     "The live figures did not refresh. These are from the last successful update."),
    ("Live data is currently unavailable.", "The live figures did not load."),
    ("Couldn't reach the data pipeline status check. The site may be showing illustrative data.",
     "We cannot check the data feed right now. Figures may be out of date."),
    ("could not reach the data pipeline status check. The site may be showing illustrative data.",
     "We cannot check the data feed right now. Figures may be out of date."),
    (" currently having trouble: ", " having trouble right now: "),
    (". Affected figures may be showing stale data until it recovers - see the footer for pipeline status.",
     ". Those figures may be out of date until it recovers."),
    ("Not enough recorded history yet for this range, for either selection.",
     "We do not have this much history for either choice yet. Choose a shorter range."),
    ("Not enough recorded history yet for this range.", "We do not have this much history yet. Choose a shorter range."),
    ("Not enough recorded history yet for the last 24 hours.", "We do not have 24 hours of history yet."),
    ("Not enough recorded history yet for this metric.", "We do not have history for this measure yet."),
    ("Not enough recorded history yet", "We do not have this much history yet"),
    ("No data available.", "Figures appear here when the next reading arrives."),
    ("No data available", "Figures appear here when the next reading arrives."),
    ("Each figure shows its trend over the last 3 hours.", "Each figure’s trend line covers the last 3 hours."),
    (" Each figure's trend covers the last 3 hours.", " Trend lines cover the last 3 hours."),
    ("so it's simply wasted", "so it is wasted"),
    ("simply because it never had to travel", "because it never had to travel"),
    ("the other 400W simply didn't need", "the other 400W did not need"),
    ("the inverter simply can't pass it through", "the inverter cannot pass it through"),
    ("you'll simply be importing", "you will be importing"),
]

APOS = "['’]"


def write_out(text):
    def fix(m):
        word = m.group(0)
        key = re.sub(APOS, "'", word).lower()
        full = CONTRACTIONS.get(key)
        if not full:
            return word
        if word[0].isupper() and full[0].islower():
            full = full[0].upper() + full[1:]
        return full
    pattern = r"\b(?:" + "|".join(re.escape(k).replace("'", APOS) for k in CONTRACTIONS) + r")\b"
    text = re.sub(pattern, fix, text, flags=re.I)
    # JavaScript single-quoted strings: don\'t, it\'s
    esc = r"\b(?:" + "|".join(re.escape(k).replace("'", r"\\'") for k in CONTRACTIONS) + r")\b"
    return re.sub(esc, lambda m: CONTRACTIONS.get(m.group(0).replace("\\'", "'").lower(), m.group(0)), text, flags=re.I)


def times(text):
    # 5:30pm -> 5.30pm, 1:30-2pm -> 1.30pm to 2pm
    text = re.sub(r"\b(\d{1,2}):(\d{2})-(\d{1,2})(am|pm)\b", r"\1.\2\4 to \3\4", text)
    return re.sub(r"\b(\d{1,2}):(\d{2})(am|pm)\b", r"\1.\2\3", text)


def main():
    dry = "--dry" in sys.argv
    total = 0
    for f in FILES:
        if not f.exists():
            continue
        s = f.read_text(encoding="utf-8")
        new = s
        for a, b in FIXED:
            new = new.replace(a, b)
        new = new.replace("arrives..", "arrives.").replace("arrives.\".", "arrives.\"")
        new = write_out(new)
        new = times(new)
        if new != s:
            n = sum(1 for x, y in zip(s.splitlines(), new.splitlines()) if x != y)
            total += n
            print(f"{f.relative_to(ROOT)}: {n} lines")
            if not dry:
                f.write_text(new, encoding="utf-8")
    print("lines changed:", total)


if __name__ == "__main__":
    main()
