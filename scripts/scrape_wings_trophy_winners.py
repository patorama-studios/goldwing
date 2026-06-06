#!/usr/bin/env python3
"""
Scrape AGM trophy winners out of the historical Wings magazines.

Walks every PDF under public_html/uploads/wings/, finds the page that lists
the post-AGM trophy winners (header text "Trophy Winners" / "AGM Trophy"),
parses the category → winner rows on that page (and the following page if
the list spills over), normalizes the trophy names against the 17 official
categories registered in /admin/awards/categories.php, and writes:

  - scripts/data/wings_trophy_winners.csv
      Structured rows: year, category_name, member_name, bike_description,
      notes, source_pdf, source_page, raw_line. Ready for human review and
      import into the awards module.

  - scripts/data/wings_trophy_winners_raw.txt
      Per-magazine trophy page text dump so a human can verify the parser
      didn't miss anything.

Usage:
  python3 scripts/scrape_wings_trophy_winners.py

Run from the project root. Requires pypdf (pip3 install --user pypdf).

This is a one-shot historical importer. The parser is intentionally
conservative — when a line doesn't match a known category prefix, it's
skipped rather than guessed at. The raw-text dump is the safety net.
"""

from __future__ import annotations

import csv
import os
import re
import sys
from pathlib import Path
from typing import Iterable

try:
    from pypdf import PdfReader
except ImportError:
    sys.stderr.write("Missing dependency: pip3 install --user pypdf\n")
    sys.exit(1)


PROJECT_ROOT = Path(__file__).resolve().parent.parent
WINGS_DIR = PROJECT_ROOT / "public_html" / "uploads" / "wings"
OUTPUT_DIR = PROJECT_ROOT / "scripts" / "data"
CSV_PATH = OUTPUT_DIR / "wings_trophy_winners.csv"
RAW_PATH = OUTPUT_DIR / "wings_trophy_winners_raw.txt"

# Phrase patterns that identify a trophy-winners page.
TROPHY_PAGE_RE = re.compile(
    r"(trophy\s+winners|agm\s+trophy|trophy\s+presentation)", re.I
)

# When the trophy section starts. The text after this header is the winner list.
SECTION_START_RE = re.compile(
    r"(trophy\s+winners[\s\.]*|agm\s+trophy\s+winners[\s\.]*)", re.I
)

# Some headers are rendered letter-spaced ("A G M   T r o p h y   W i n n e r s")
# which breaks the simple phrase match. We collapse single-letter runs first
# before re-applying TROPHY_PAGE_RE. Also a "category density" fallback catches
# pages where the header is rendered as an image or otherwise garbled.
LETTER_SPACED_RE = re.compile(r"(?:\b[A-Za-z]\s){4,}[A-Za-z]\b")

CATEGORY_DENSITY_KEYWORDS = [
    "best original", "best custom", "best gold", "best trike", "best sidecar",
    "best outfit", "best non-gold", "best non gold", "best in show",
    "long distance", "longest distance", "people's choice", "peoples choice",
    "club person of the year", "person of the year", "member of the year",
    "best bike and trailer", "best trailer", "best classic",
]


def collapse_letter_spaced(text: str) -> str:
    """Turn 'T r o p h y   W i n n e r s' into 'Trophy Winners' (one pass).
    Also normalises curly typographic quotes (’ → ', “” → ") so downstream
    regexes don't have to handle both encodings — pypdf preserves whatever
    the PDF had, and most issues use curly quotes.
    """
    text = text.replace("’", "'").replace("‘", "'")
    text = text.replace("“", '"').replace("”", '"')
    def _collapse(m: re.Match) -> str:
        return re.sub(r"\s+", "", m.group(0))
    return LETTER_SPACED_RE.sub(_collapse, text)


def looks_like_trophy_page(text: str) -> bool:
    """True if either the explicit phrase or the keyword density says yes."""
    if TROPHY_PAGE_RE.search(text):
        return True
    collapsed = collapse_letter_spaced(text)
    if TROPHY_PAGE_RE.search(collapsed):
        return True
    lower = text.lower()
    hits = sum(1 for kw in CATEGORY_DENSITY_KEYWORDS if kw in lower)
    return hits >= 3


# ─────────────────────────────────────────────────────────────────────────────
# Category normalization
# ─────────────────────────────────────────────────────────────────────────────
# Each (regex, official_name) tuple maps a wide variety of trophy-name phrasings
# to one of the 17 official categories seeded into award_categories. Patterns
# are evaluated in order — first match wins, so put more specific patterns
# before more generic ones. `_NOTE` constants suffix the notes column with
# extra context (e.g. "Female rider", "Over 60").
#
# Lines whose left-hand side doesn't match anything here are skipped (and
# captured in the unmatched-lines log for review).

CategoryRule = tuple[re.Pattern[str], str, str]  # (pattern, official_name, note)

# Official category names — must match the seeds in migration 022.
ORIG_CLASSIC = "Best Original Classic Goldwing GL1000, GL1100, GL1200"
ORIG_1500 = "Best Original GL1500"
ORIG_1800 = "Best Original GL1800"
ORIG_F6B = "Best Original F6B"
CUST_CLASSIC = "Best Custom Classic Goldwing GL1000, GL1100, GL1200"
CUST_1500 = "Best Custom Goldwing GL1500"
CUST_1800 = "Best Custom Goldwing GL1800"
CUST_F6B = "Best Custom F6B"
TRAILER = "Best Goldwing and Trailer"
TRIKE = "Best Goldwing Trike"
SIDECAR = "Best Goldwing and Sidecar"
NON_GW = "Best non-Goldwing"
LD_OVER_65 = "Longest Distance Travelled by an AGA Member over 65"
LD_ANY = "Longest Distance Travelled by an AGA Member"
LD_PILLION = "Longest Distance Pillion"
PEOPLES_CHOICE = "Peoples Choice Award"
MEMBER_OF_YEAR = "Member of the Year"

CATEGORY_RULES: list[CategoryRule] = [
    # MOST SPECIFIC FIRST — over-60/65 must come before plain "long distance"
    (re.compile(r"long(?:est)?\s+distance.*(?:male|man).*(?:over\s*60|over\s*65|65\+|60\+|over\s*sixty)", re.I), LD_OVER_65, "Male rider"),
    (re.compile(r"long(?:est)?\s+distance.*(?:female|fe?male|woman|lady).*(?:over\s*60|over\s*65|65\+|60\+|over\s*sixty)", re.I), LD_OVER_65, "Female rider"),
    (re.compile(r"long(?:est)?\s+distance.*(?:rider\s*over|over\s*60\s*yo|over\s*65\s*yo|over\s*60\s*rider|over\s*65\s*rider|over\s*60\b|over\s*65\b)", re.I), LD_OVER_65, ""),

    (re.compile(r"long(?:est)?\s+distance.*pillion", re.I), LD_PILLION, ""),

    (re.compile(r"long(?:est)?\s+distance.*(?:male|man).*rider", re.I), LD_ANY, "Male rider"),
    (re.compile(r"long(?:est)?\s+distance.*(?:female|woman|lady).*rider", re.I), LD_ANY, "Female rider"),
    (re.compile(r"long(?:est)?\s+distance.*rider", re.I), LD_ANY, ""),
    (re.compile(r"long(?:est)?\s+distance(?!\s+pillion)", re.I), LD_ANY, ""),

    # Specialty bikes — these must come before the generic "Best Original/Custom"
    # patterns because some entries read e.g. "Best Trike GL1800".
    (re.compile(r"best\s+gold\s*wing\s+(?:and|&)\s+trailer", re.I), TRAILER, ""),
    (re.compile(r"best\s+(?:bike|outfit)\s+(?:and|&)\s+trailer", re.I), TRAILER, ""),
    (re.compile(r"best\s+trailer", re.I), TRAILER, ""),
    (re.compile(r"best\s+gold\s*wing\s+sidecar", re.I), SIDECAR, ""),
    (re.compile(r"best\s+(?:bike|gold\s*wing)\s+(?:and|&)\s+side\s*car", re.I), SIDECAR, ""),
    (re.compile(r"best\s+side\s*car", re.I), SIDECAR, ""),
    (re.compile(r"best\s+outfit\b", re.I), SIDECAR, ""),
    (re.compile(r"best\s+gold\s*wing\s+trike", re.I), TRIKE, ""),
    (re.compile(r"best\s+trike\b", re.I), TRIKE, ""),
    (re.compile(r"best\s+non[- ]gold\s*wing", re.I), NON_GW, ""),

    # F6B (rarer)
    (re.compile(r"best\s+original.*f6b", re.I), ORIG_F6B, ""),
    (re.compile(r"best\s+custom.*f6b", re.I), CUST_F6B, ""),

    # GL1800 splits (sometimes split by year: "up to 2017" / "2018+")
    (re.compile(r"best\s+original.*gl\s*1800", re.I), ORIG_1800, ""),
    (re.compile(r"best\s+custom.*gl\s*1800", re.I), CUST_1800, ""),

    # GL1500
    (re.compile(r"best\s+original.*gl\s*1500", re.I), ORIG_1500, ""),
    (re.compile(r"best\s+custom.*gl\s*1500", re.I), CUST_1500, ""),
    (re.compile(r"best\s+gl\s*1500", re.I), ORIG_1500, "Original/Custom not specified"),

    # Classic Goldwings (GL1000 / GL1100 / GL1200)
    (re.compile(r"best\s+original.*(?:classic|gl\s*100?0|gl\s*1100|gl\s*1200)", re.I), ORIG_CLASSIC, ""),
    (re.compile(r"best\s+custom.*(?:classic|gl\s*100?0|gl\s*1100|gl\s*1200)", re.I), CUST_CLASSIC, ""),
    (re.compile(r"best\s+classic.*gold\s*wing", re.I), ORIG_CLASSIC, ""),
    (re.compile(r"best\s+gl\s*100?0\b", re.I), ORIG_CLASSIC, ""),
    (re.compile(r"best\s+gl\s*1100\b", re.I), ORIG_CLASSIC, ""),
    (re.compile(r"best\s+gl\s*1200\b", re.I), ORIG_CLASSIC, ""),

    # People's Choice
    (re.compile(r"people'?s\s+choice", re.I), PEOPLES_CHOICE, ""),

    # Member / Person of the Year
    (re.compile(r"(?:club\s+)?(?:person|member)\s+of\s+the\s+year", re.I), MEMBER_OF_YEAR, ""),
]


# Categories the user is NOT asking about — recognised so they're filtered out
# cleanly instead of polluting the unmatched-lines log.
IGNORED_CATEGORY_PATTERNS = [
    re.compile(r"hard\s+luck", re.I),
    re.compile(r"best\s+(?:light|lights)\s*(?:show|display|parade)?", re.I),
    re.compile(r"light\s+parade", re.I),
    re.compile(r"best\s+number\s+plate", re.I),
    re.compile(r"oldest\s+gold\s*wing", re.I),
    re.compile(r"shortest\s+distance", re.I),
    re.compile(r"most\s+shiny", re.I),
    re.compile(r"best\s+in\s+show", re.I),
    re.compile(r"best\s+chapter\s+attendance", re.I),
    re.compile(r"20\s+year\s+membership", re.I),
    re.compile(r"25\s+year\s+membership", re.I),
    re.compile(r"10\s+year\s+membership", re.I),
]


# ─────────────────────────────────────────────────────────────────────────────
# Helpers
# ─────────────────────────────────────────────────────────────────────────────

# Bike model token — used both for "name then bike" (suffix) and
# "bike then name" (prefix) layouts. Year is optional and can sit before or
# after the model word.
BIKE_TOKEN = (
    r"(?:GL\s*\d{3,4}\w*|F6B|Gold\s*[Ww]ing|Honda|Valkyrie|Can-?Am|Harley(?:\s+Davidson)?|"
    r"Kawasaki|Vulcan|BMW|Yamaha|Suzuki|Spyder|Triumph|Indian|Boom\s+Trike)"
)
BIKE_SUFFIX_RE = re.compile(
    rf"(?P<bike>\b(?:19|20)\d{{2}}\b\s*{BIKE_TOKEN}.*$)", re.I
)
# Bike modifier words that follow a model (e.g. "GL1800 Trike", "GL1500 Outfit").
BIKE_MODIFIER = r"(?:Trike|Sidecar|Outfit|Combo|SE|Custom|Classic|Aspencade|Interstate)"

BIKE_PREFIX_RE = re.compile(
    rf"^(?P<bike>{BIKE_TOKEN}(?:\s+{BIKE_MODIFIER})?(?:\s+(?:19|20)\d{{2}}\w*)?)\s+(?P<rest>.+)$",
    re.I,
)
YEAR_FIRST_BIKE_RE = re.compile(
    rf"^(?P<bike>(?:19|20)\d{{2}}\s+{BIKE_TOKEN}\w*(?:\s+{BIKE_MODIFIER})?)\s+(?P<rest>.+)$",
    re.I,
)

# Distance pattern: "2674 km" / "2,230 kms"
DISTANCE_RE = re.compile(r"\b(\d{1,3}(?:,?\d{3})?\s*k?ms?\b)", re.I)

# Trailing Australian state codes (e.g. "Robyn Strong NSW") — strip into notes.
STATE_SUFFIX_RE = re.compile(r"\s+(NSW|VIC|QLD|WA|SA|TAS|NT|ACT)\.?\s*$", re.I)

# Sub-category markers ("up to 2017", "2018+", "GL1800 up to 2017") that appear
# at the start of the winner cell because they modify the trophy but aren't
# part of the winner's name. Strip these before name parsing.
SUBCAT_PREFIX_RE = re.compile(
    r"^(?:GL\s*1800\s+)?"
    r"(?:up\s+to\s+\d{4}|pre[- ]?\d{4}|post[- ]?\d{4}|"
    r"\d{4}\s*[-–]\s*\d{4}|\d{4}\+)"
    r"\s+",
    re.I,
)

# A "no winner" indicator that may appear after stripping a sub-category prefix.
NO_WINNER_RE = re.compile(r"^(?:none|n/?a|—|-|not\s+awarded|vacant|tba|tbc)\.?$", re.I)


def find_category(left: str) -> tuple[str, str] | None:
    """Return (official_name, note_suffix) if `left` matches a known category."""
    norm = left.strip()
    if not norm:
        return None
    for rx, name, note in CATEGORY_RULES:
        if rx.search(norm):
            return name, note
    return None


def is_ignored_category(left: str) -> bool:
    for rx in IGNORED_CATEGORY_PATTERNS:
        if rx.search(left):
            return True
    return False


def year_from_path(path: Path) -> int | None:
    # public_html/uploads/wings/2017/04/04-April-2017-Wings-Mag.pdf
    parts = path.parts
    try:
        idx = parts.index("wings")
        return int(parts[idx + 1])
    except (ValueError, IndexError):
        return None


def extract_trophy_pages(reader: PdfReader) -> list[tuple[int, str]]:
    """Return [(page_no, text)] for pages that look like a trophy winners list.

    Two-pass: first find any page that explicitly says "Trophy Winners" (handling
    letter-spaced rendering) OR has 3+ category keywords. Then for every match
    also grab the prev + next page so multi-page lists are captured.
    """
    n = len(reader.pages)
    if n == 0:
        return []

    # Pre-extract all page text once.
    texts: list[str] = []
    for i in range(n):
        try:
            texts.append(reader.pages[i].extract_text() or "")
        except Exception:
            texts.append("")

    matched_idx: set[int] = set()
    for i, text in enumerate(texts):
        if looks_like_trophy_page(text):
            # Include i-1, i, i+1 so multi-page lists are captured.
            for j in (i - 1, i, i + 1):
                if 0 <= j < n:
                    matched_idx.add(j)

    return [(j + 1, texts[j]) for j in sorted(matched_idx)]


def split_left_right(line: str) -> tuple[str, str] | None:
    """Heuristic: split a row like 'Best Original GL1500    Glen Emonson 2007 GL1500' into
    (category, winner+bike). Trophy lists usually separate the two halves with
    2+ spaces or tabs; some PDFs collapse to a single space, so we also try
    splitting on "  " then on known category endings.
    """
    line = line.strip()
    if not line:
        return None
    # Strong split: 2+ spaces
    m = re.split(r"\s{2,}", line, maxsplit=1)
    if len(m) == 2:
        return m[0].strip(), m[1].strip()
    # Fallback: look for the first uppercase-name token after a category keyword
    # ("Best …", "Long Distance …", "People's Choice …", "Club Person of the Year …")
    # We anchor on a known prefix and split right before the first capitalised
    # multi-word stretch that looks like a name.
    return None


def parse_block_format(text: str) -> list[tuple[str, str]]:
    """Some issues use the multi-line block format:
        Best Original GL1500
        Greg Swan
        1997 GL1500SE
    Returns [(category_line, winner_block)] where winner_block joins the next
    1-3 non-blank lines until another category prefix appears.

    A line is only treated as a standalone category HEADER if the line is
    almost entirely the category — anything more than ~3 trailing characters
    means the winner is on the same line, and that's the inline parser's job.
    """
    out: list[tuple[str, str]] = []
    lines = [l.strip() for l in text.splitlines()]
    i = 0
    while i < len(lines):
        left = lines[i]
        if not left:
            i += 1
            continue
        cat_match = None
        for rx, _, _ in CATEGORY_RULES:
            mm = rx.search(left)
            if mm:
                cat_match = mm
                break
        is_standalone_header = (
            cat_match is not None
            and (len(left) - cat_match.end()) <= 3
            and cat_match.start() <= 3
        )
        if (
            is_standalone_header
            and i + 1 < len(lines)
            and lines[i + 1]
            and find_category(lines[i + 1]) is None
            and not is_ignored_category(lines[i + 1])
        ):
            # Collect 1-3 follow-up lines as the winner block.
            winner_lines: list[str] = []
            j = i + 1
            while j < len(lines) and j - i <= 3:
                nxt = lines[j]
                if not nxt:
                    break
                if find_category(nxt) or is_ignored_category(nxt):
                    break
                winner_lines.append(nxt)
                j += 1
            if winner_lines:
                out.append((left, " ".join(winner_lines)))
                i = j
                continue
        i += 1
    return out


def parse_inline_format(text: str) -> list[tuple[str, str]]:
    """The other common format:
        Best Original GL1500     Glen Emonson 2007 GL1500SE
    One row per line. Returns [(left, right)] where left is the matched
    category prefix and right is the rest of the line. Anchors on the
    category regex's match position so layouts like "Best Trike GL1800 2001
    Rowland Wayman" (bike between category and name) come out clean.
    """
    out: list[tuple[str, str]] = []
    for raw in text.splitlines():
        line = raw.strip()
        # Filter junk lines: too short, too long (a paragraph isn't a row),
        # or no actual content (page numbers, separators).
        if not line or len(line) < 8 or len(line) > 150:
            continue
        # Skip pure-number lines (page numbers) and decorative dividers.
        if re.fullmatch(r"[\d\s\.\-\—\=_]+", line):
            continue

        # Find the FIRST category rule that matches anywhere on the line.
        # We anchor parsing on the match span so we don't rely on whitespace
        # being preserved by the PDF extractor.
        best_match: tuple[re.Match[str], str, str] | None = None
        for rx, name, note in CATEGORY_RULES:
            m = rx.search(line)
            if m and (best_match is None or m.start() < best_match[0].start()):
                best_match = (m, name, note)
        if not best_match:
            continue
        m, _, _ = best_match
        # Take everything after the matched category as the right-hand side.
        # Strip a wider set of separator punctuation including en/em dashes.
        right_raw = line[m.end():].strip(" .-–—:|\t")
        # If the right side is empty, this line is just the category header —
        # block-format will pick it up via the next-line lookup.
        if not right_raw:
            continue
        # Strip any sub-category marker that leaked across the split (e.g.
        # "Best Original GL1800 | up to 2017 Mal Allen").
        right_raw = SUBCAT_PREFIX_RE.sub("", right_raw).strip()
        if not right_raw:
            continue
        # Filter out non-winners (some lists explicitly mark "None" / "n/a").
        if NO_WINNER_RE.match(right_raw):
            continue
        # Reject right-side that starts with another category keyword — that
        # means the previous line leaked into this one.
        for rx2, _, _ in CATEGORY_RULES:
            if rx2.match(right_raw):
                right_raw = ""
                break
        if not right_raw:
            continue
        left_raw = line[: m.end()].strip(" .-:|\t")
        out.append((left_raw, right_raw))
    return out


def split_winner_and_bike(right: str) -> tuple[str, str, str]:
    """Returns (member_name, bike_description, notes).

    Handles three layouts in the wild:
        "Mark Johannesen 2001 GL1800 + Shadow trailer"   → name then bike
        "GL1800 2001 Rowland Wayman"                     → bike then name
        "Harley Davidson Kev Lane"                       → bike token then name
    Also pulls a trailing distance ("2674 km") and a trailing state suffix
    ("NSW") into the notes column so the name field stays clean.
    """
    notes_parts: list[str] = []

    # Pull distance to notes if present.
    dist_match = DISTANCE_RE.search(right)
    if dist_match:
        notes_parts.append(dist_match.group(0).strip())
        working = (right[: dist_match.start()] + " " + right[dist_match.end():]).strip()
    else:
        working = right

    bike = ""
    name = working

    # Layout 1: "YEAR BIKE-MODEL ... NAME" — year+model at start, name at end
    m_year_first = YEAR_FIRST_BIKE_RE.match(working)
    if m_year_first:
        bike = m_year_first.group("bike").strip()
        name = m_year_first.group("rest").strip()
    else:
        # Layout 2: "BIKE-MODEL [YEAR] NAME" — model first, optional year, then name
        m_bike_first = BIKE_PREFIX_RE.match(working)
        if m_bike_first:
            bike = m_bike_first.group("bike").strip()
            name = m_bike_first.group("rest").strip()
        else:
            # Layout 3: "NAME YEAR BIKE-MODEL ..." — bike at end
            m_bike_suffix = BIKE_SUFFIX_RE.search(working)
            if m_bike_suffix:
                bike = m_bike_suffix.group("bike").strip()
                name = working[: m_bike_suffix.start()].strip()

    # Strip trailing state suffix from name.
    m_state = STATE_SUFFIX_RE.search(name)
    if m_state:
        notes_parts.append(m_state.group(1).upper())
        name = name[: m_state.start()].strip()

    # Clean punctuation crumbs.
    name = name.strip(" ,.-+&():;|")
    bike = bike.strip(" ,.-+&():;|")
    name = re.sub(r"\s+", " ", name)
    bike = re.sub(r"\s+", " ", bike)

    # Sanity: a "name" longer than 60 chars is probably a paragraph fragment.
    if len(name) > 60:
        name = ""
    # A real name must start with a capital letter and have at least one space
    # OR be a single proper noun ≥ 3 chars; reject common false-positives like
    # "male'", "the", "award".
    if name:
        if len(name) < 3:
            name = ""
        elif not re.match(r"^[A-Z][a-z]+", name):
            name = ""
        elif name.lower() in {"male", "female", "rider", "pillion", "the", "award", "and", "trophy"}:
            name = ""

    notes = " · ".join(p for p in notes_parts if p).strip()
    return name, bike, notes


# ─────────────────────────────────────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────────────────────────────────────

def main() -> int:
    if not WINGS_DIR.is_dir():
        sys.stderr.write(f"Wings directory not found: {WINGS_DIR}\n")
        return 1
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

    pdfs = sorted(WINGS_DIR.rglob("*.pdf"))
    sys.stderr.write(f"Scanning {len(pdfs)} PDFs...\n")

    rows: list[dict] = []
    raw_dump: list[str] = []
    unmatched_lines: list[str] = []
    seen: set[tuple[int, str, str]] = set()  # (year, category, name) dedupe

    for pdf in pdfs:
        try:
            reader = PdfReader(str(pdf))
        except Exception as e:
            sys.stderr.write(f"  ! {pdf.name}: {e}\n")
            continue

        pages = extract_trophy_pages(reader)
        if not pages:
            continue
        year = year_from_path(pdf) or 0

        for page_no, text in pages:
            raw_dump.append(f"\n========== {pdf.relative_to(PROJECT_ROOT)} · page {page_no} · year {year} ==========\n")
            raw_dump.append(text)

            # Collapse letter-spaced headers like "A G M  T r o p h y  W i n n e r s"
            # before parsing so they don't fragment the line stream.
            text = collapse_letter_spaced(text)
            entries = parse_block_format(text)
            inline = parse_inline_format(text)
            seen_lefts = {l for l, _ in entries}
            for l, r in inline:
                if l not in seen_lefts:
                    entries.append((l, r))

            for left, right in entries:
                cat = find_category(left)
                if cat is None:
                    if is_ignored_category(left):
                        continue
                    unmatched_lines.append(f"{pdf.relative_to(PROJECT_ROOT)} p{page_no}: {left} | {right}")
                    continue
                official, note_suffix = cat
                name, bike, dist_note = split_winner_and_bike(right)
                if not name:
                    continue
                notes = " ".join(n for n in (note_suffix, dist_note) if n).strip()
                key = (year, official, name.lower())
                if key in seen:
                    continue
                seen.add(key)
                rows.append({
                    "year": year,
                    "category_name": official,
                    "member_name": name,
                    "bike_description": bike,
                    "notes": notes,
                    "source_pdf": str(pdf.relative_to(PROJECT_ROOT)),
                    "source_page": page_no,
                    "raw_line": f"{left} | {right}",
                })

    # Sort: year desc, category alphabetic.
    rows.sort(key=lambda r: (-r["year"], r["category_name"], r["member_name"]))

    with CSV_PATH.open("w", newline="", encoding="utf-8") as fh:
        writer = csv.DictWriter(fh, fieldnames=[
            "year", "category_name", "member_name", "bike_description", "notes",
            "source_pdf", "source_page", "raw_line",
        ])
        writer.writeheader()
        writer.writerows(rows)

    with RAW_PATH.open("w", encoding="utf-8") as fh:
        fh.write("\n".join(raw_dump))
        if unmatched_lines:
            fh.write("\n\n========== UNMATCHED LINES (for parser tuning) ==========\n")
            fh.write("\n".join(unmatched_lines))

    # Summary on stderr.
    by_year: dict[int, int] = {}
    by_cat: dict[str, int] = {}
    for r in rows:
        by_year[r["year"]] = by_year.get(r["year"], 0) + 1
        by_cat[r["category_name"]] = by_cat.get(r["category_name"], 0) + 1

    sys.stderr.write(f"\nWrote {CSV_PATH.relative_to(PROJECT_ROOT)} — {len(rows)} winners\n")
    sys.stderr.write(f"Wrote {RAW_PATH.relative_to(PROJECT_ROOT)} — raw text dump\n")
    sys.stderr.write(f"Unmatched lines (skipped): {len(unmatched_lines)}\n")
    sys.stderr.write("\nBy year:\n")
    for y, n in sorted(by_year.items(), reverse=True):
        sys.stderr.write(f"  {y}: {n}\n")
    sys.stderr.write("\nBy category:\n")
    for c, n in sorted(by_cat.items(), key=lambda kv: -kv[1]):
        sys.stderr.write(f"  {n:3d}  {c}\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
