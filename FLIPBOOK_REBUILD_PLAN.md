# Wings Flipbook Reader — Rebuild Plan (Path B)

**Date:** 2026-06-11
**Status:** DRAFT for Pat's review. **No code written yet.**
**Approach:** **On-the-fly rendering from the PDF** (no stored images) — decided with Pat 2026-06-11 for storage efficiency. The PDF stays the single source of truth.
**Companion doc:** [FLIPBOOK_AUDIT.md](FLIPBOOK_AUDIT.md) (the diagnosis this plan answers).

---

## 0. Confirmed constraints

- **Vanilla PHP stack** — no `package.json`, no build step, no React, CDN-loaded libs. → zoom/pan layer is **vanilla JS**, not `react-zoom-pan-pinch`.
- **cPanel shared host**, deploy via git → cPanel "Update from Remote". Pat has **no terminal/SSH** ([[project_goldwing_host]]).
- **Wings is a paid member benefit** — the PDF is already served auth-gated via [download_wings.php](public_html/member/download_wings.php) (`?view=1`). We reuse that gate; no new endpoint needed.
- **No extra server storage** — we do NOT convert PDFs to stored images. (~145 MB/year of derived files avoided.) Render happens in the browser, on demand, from the existing PDF.

---

## 1. Why on-the-fly (and why the old reader looked bad anyway)

The current reader already reads off the PDF with PDF.js — the blur isn't *because* it reads the PDF, it's because it reads it badly:

| Old behaviour (the bug) | New behaviour (the fix) |
|---|---|
| Renders **all** pages up front at a **fixed** `RENDER_SCALE = 1.5` | Renders **only visible page(s) + neighbours**, **lazily** |
| Ignores `devicePixelRatio` → soft on retina/tablets | Renders at `scale × devicePixelRatio` → crisp on any screen |
| Zoom = CSS-stretch a flat JPEG → pixelated | Zoom = **re-rasterise that page at the zoomed resolution** → genuinely sharp |
| Holds ~30+ base64 JPEGs in memory → heavy, can crash mobile tabs | Small **LRU cache** of recent pages; optional cross-session cache |

**Net:** every readability complaint is fixed, and the server stores nothing beyond the PDF it already has. The only trade-off is the device re-rendering each session — sub-second per A4 page on any phone/tablet from the last ~6 years, and mitigated by caching. If real-device testing surfaces a genuinely weak-device problem, stored images remain a fallback (see §8).

**Sharpness ceiling note:** if older issues are *scanned* (raster inside the PDF), no approach beats the original scan DPI — on-the-fly is still as sharp and free. If issues are *digitally produced* (vector text), on-the-fly is effectively infinite-resolution at any zoom.

---

## 2. Target architecture at a glance

```
MEMBER OPENS /member/read_wings.php?id={id}
   │
   ▼  fetch the gated PDF (download_wings.php?id=&view=1) — PDF.js with range requests
┌────────────────────────────────────────────────────────────┐
│ Top bar: title · [Single|Double] toggle · page x/y · ⛶      │
├────────────────────────────────────────────────────────────┤
│ RENDER CORE (PDF.js)                                         │
│   • render queue: current page ±N, at scale × DPR            │
│   • LRU page cache (in-memory; optional Cache API cross-sess)│
│   • re-render the active page at higher scale on zoom        │
│                                                              │
│ BROWSE MODE (zoom = 1×)                                      │
│   • flip engine animates between rendered pages              │
│   • owns swipe / tap-corner / arrows / keyboard nav          │
│                                                              │
│ ZOOM MODE (zoom > 1×)  ── pinch / dbl-tap / +               │
│   • flip frozen; active page shown as a high-res render      │
│   • vanilla pan/zoom: focal-point pinch, drag-to-pan         │
│     (bounds-clamped), dbl-tap-to-zoom-AT-POINT, wheel        │
│   • exit → back to browse mode at same page                  │
├────────────────────────────────────────────────────────────┤
│ Bottom bar: ‹ · page scrubber · › · Download PDF            │
└────────────────────────────────────────────────────────────┘
```

The **two-mode state machine** is the core fix for the audit's "two touch systems fighting": only one system owns gestures at a time.

---

## 3. Render core (the heart of the rebuild)

- **Source:** the existing gated PDF (`download_wings.php?id=&view=1`). Use PDF.js **range requests** so it streams pages rather than waiting on the whole file (the server supports byte ranges; we verify and fall back to full download if not).
- **Lazy render queue:** render the current page and a small look-ahead/behind window (e.g. ±2 in single mode, the current spread ±1 in double mode). Navigation pre-renders the next target so the flip target is ready before the animation.
- **Resolution:** render scale = `CSSpagewidth / PDFpagewidth × window.devicePixelRatio`, capped to a sane max so a DPR-3 phone doesn't render absurd canvases. This is the DPR-aware fix.
- **Zoom re-render:** on entering zoom, re-render the active page at `targetZoom × DPR` (capped) so zoom shows real detail. Debounced so a pinch gesture renders once it settles, with the stretched preview shown during the gesture for responsiveness.
- **Caching:**
  - In-memory **LRU** of rendered bitmaps (`ImageBitmap`/canvas), cap ~6–8 pages → bounded memory, instant re-flip.
  - Optional **Cache API / IndexedDB** keyed by `issueId+page+scaleBucket` for cross-session reuse (nice-to-have; behind a flag, evaluated in polish).
- **No DB migration, no manifest, no new storage.** Page count comes from `pdf.numPages` at load.

## 4. Flip engine

- **Keep the flip aesthetic; vendor StPageFlip 2.0.7** locally (`/public_html/assets/js/vendor/`) rather than CDN — it's unmaintained, so pinning a CDN copy of an abandoned package is an availability/supply-chain risk; vendoring also brings it under the existing file-integrity checks.
- **Feed it lazily:** use StPageFlip's HTML mode — one lightweight per-page container; the render core paints the rendered bitmap into the current ±window, placeholders elsewhere. The engine only animates; it's decoupled from rendering.
- **Degrade gracefully:** `prefers-reduced-motion` or a detected weak device ⇒ instant page swap instead of the 3D flip. If StPageFlip proves janky on mobile, the decoupling makes swapping it (or dropping to a CSS slide) cheap.

## 5. Zoom / pan layer (vanilla)

- **Activation:** pinch-out, double-tap, wheel-up (desktop), or `+` → **zoom mode**.
- **On entry:** freeze the flip engine (disable its pointer handlers), trigger a high-res re-render of the active page, hand control to the pan/zoom layer.
- **Gestures:**
  - **Focal-point pinch** — origin tracks the finger midpoint (the audit's "zoom into the area you're looking at").
  - **Double-tap-to-zoom-at-point** — tap a column, it zooms *there* (today double-tap only resets).
  - **Drag-to-pan** — clamped to page bounds so the page can't be lost off-screen (the audit's "no move around when zoomed").
  - **Wheel + cursor-anchored zoom** on desktop.
- **Exit:** pinch ≤1×, double-tap when zoomed, `0`/Esc, or a button → browse mode at the same page.
- **Implementation:** lead with a small vendored vanilla lib (**`@panzoom/panzoom`**, maintained, focal pinch + bounded pan) inside the frozen overlay; fall back to ~150 lines of custom pointer handlers if it fights the layout. It lives only in the overlay, never on the live flip book — that's what prevents the gesture war.

## 6. Controls, view mode, security, a11y

- **Single/Double toggle:** a real top-bar button, persisted in `localStorage` per user. Default = double on ≥1024px, single below; user choice wins and sticks. Implemented by re-initialising the flip engine with the new mode at the current page (cheap — rendering is lazy).
- **Scrubber:** replace the capped 20-dot row with a page scrubber. Thumbnails can be tiny low-scale PDF.js renders generated lazily (still no storage), or a simple numeric/slider scrubber for v1.
- **Security:** unchanged and simpler — the only asset fetched is the PDF, through the existing auth-gated `download_wings.php`. No new public files, no new endpoint to gate. (Members can already download the PDF, so client-side rendering exposes nothing new.)
- **Accessibility:** keyboard zoom (`+`/`-`/`0`), focus-visible controls, `aria-live` page counter, `prefers-reduced-motion` ⇒ skip flip. Keep the existing `data-tour` anchors so the guided tour ([member-read-wings.js](public_html/assets/js/tours/tours/member-read-wings.js)) still works.

## 7. Performance budget

| Metric | Target | How |
|---|---|---|
| First page visible | **< 1.5s** on a mid phone / 4G | range-request the PDF, render only page 1 first |
| Page-turn | 60fps | pre-render the flip target; transform-only animation |
| Rendered pages in memory | **≤ 6–8** | LRU cache eviction |
| Zoom/pan | 60fps, sharp to ~3× | high-res re-render of active page; transform-only pan |
| Server storage added | **0 bytes** | nothing stored beyond the existing PDF |

## 8. What happens to `read_wings.php` + open items

- **Replace in place** — keep route `/member/read_wings.php?id={id}` so every existing link, the wings list page, and the tour keep working. Keep back button, Download PDF, fullscreen, `data-tour` hooks. Internally rebuilt around the render core + flip + zoom/pan layers.
- **Nothing to migrate or backfill** — every issue already has a PDF, so the new reader works for the entire archive on day one. (This was the big simplification from going on-the-fly.)
- **Stored-images fallback (only if needed):** if real-device testing shows weak/old member devices struggling to render, we can add an *optional, opt-in* per-issue pre-render later — the render core is structured so a "use stored image if present, else render" check is a small addition. Not building it now.

**Decisions — resolved with Pat (2026-06-11):**
1. **Render approach → ON-THE-FLY from the PDF** (no stored images). Storage-efficient; PDF is single source of truth.
2. *(Obsolete)* image gating / backfill / host-detection — no longer apply, since nothing is pre-rendered or stored.

**Open — pending from Pat:** anything about **CMS coupling / member-auth flow (session handling for the PDF fetch) / deployment** I haven't seen that I should design around.

## 9. Implementation phases (after sign-off)

1. **Render core** — PDF.js range-request load, lazy DPR-aware per-page render, LRU cache. Validate sharpness on a real issue (phone + tablet) **before** building UI on top.
2. **Reader shell** — flip engine (vendored, lazy-fed), browse-mode nav, single/double toggle, scrubber.
3. **Zoom/pan layer** — frozen-overlay, focal pinch, bounded pan, dbl-tap-to-point, high-res re-render on zoom.
4. **Polish** — a11y, reduced-motion, tour re-anchor, optional cross-session cache, remove old code.

Real-device testing (phone + tablet) each phase — that's the whole point of the rebuild. Run `doc-sync-check` + `tour-impact-check` before any push.
