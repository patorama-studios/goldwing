# Wings Flipbook Reader — Audit & Rebuild Plan

**Date:** 2026-06-10
**Scope:** The "flipper" page reader for Wings magazine. Audit + rebuild recommendation only — no code was changed in this pass.
**File audited:** `public_html/member/read_wings.php` (785 lines, self-contained HTML/CSS/JS)

---

## 1. Current State

### What it is

A single PHP file that renders a magazine PDF into a 3D page-flip reader, entirely client-side. There is no build step, no `package.json`, no local vendored libraries — everything loads from CDNs at runtime.

| Layer | Technology | Notes |
|---|---|---|
| PDF rendering | **PDF.js 3.11.174** (cdnjs) | Rasterises every PDF page to a canvas in the browser |
| Flip animation | **StPageFlip / `page-flip` 2.0.7** (jsDelivr) | 3D page-turn effect; `window.St.PageFlip` |
| Fonts/icons | Playfair Display, Inter, Material Icons | Cosmetic |
| Source asset | **One PDF per issue** | `/member/download_wings.php?id={id}&view=1` |
| Storage | `/uploads/wings/YYYY/MM/` | PDF + optional cover image, set in admin |
| Route | `/member/read_wings.php?id={id}` | Login required (`require_login()`) |
| Admin upload | `/admin/index.php?page=wings` | Stores PDF, extracts cover, writes `wings_issues` row |

### How a page load actually works (the critical part)

1. Browser downloads the **entire PDF**.
2. PDF.js renders **every page** to an off-screen canvas at a **fixed `RENDER_SCALE = 1.5`** (`read_wings.php:446`).
3. Each canvas is flattened to a **JPEG at 0.85 quality** and kept in memory as a base64 data URL (`read_wings.php:616`).
4. Only once **all** pages are rendered does StPageFlip mount and the reader appear (`read_wings.php:624–662`).
5. Zoom is a CSS `transform: scale()` applied to the whole book wrapper (`read_wings.php:705–708`).

This "render the whole magazine into memory up front, then display flat JPEGs" model is the root cause of most of the complaints below.

### What it does well (worth keeping)

- Client-side rendering = no server CPU cost, works on shared hosting.
- `visualViewport`-aware sizing handles the iOS Safari URL-bar problem (`read_wings.php:475–481`).
- Safe-area insets, fullscreen, keyboard nav, progress bar, guided tour, graceful "download the PDF instead" fallback.
- The desktop two-page spread looks genuinely good.

---

## 2. Diagnosed Issues (Pat's list)

### A. Low-res / unreadable on phones & tablets — **the headline bug**

This has **three compounding causes**, in order of impact:

1. **Zoom never re-renders — it just stretches a flat JPEG.**
   When you pinch to zoom, `applyZoom()` sets `transform: scale(4)` on an image that was rasterised once at ~892px wide. Scaling a 892px JPEG up to fill a zoomed viewport is pure pixel-stretching — no new detail, plus visible JPEG blocking on text. **This is why zoomed pages are unreadable.** A real reader re-rasterises the visible page at the zoomed resolution (or uses tiles); this one cannot.

2. **`RENDER_SCALE` is fixed at 1.5 and ignores device pixel ratio.**
   The canonical PDF.js sharpness technique is to multiply the render scale by `window.devicePixelRatio` (verified against Mozilla's own examples — see Sources). Phones/tablets have a DPR of 2–3, so every physical pixel is being fed ~⅓ to ½ of a rendered pixel. Even at 1× zoom the pages look soft. Tablets are worst because the book fills more screen, stretching the same fixed raster over more device pixels.

3. **JPEG at 0.85 compresses text.**
   Magazine pages are text + line art — exactly what JPEG handles worst. Compression artifacts ("mosquito noise") around letterforms make small type fuzzy before zoom even enters the picture.

**Net:** the pages are low-resolution *by construction*, and the one tool the user reaches for to compensate — zoom — makes it worse, not better.

### B. Clunky / unpolished feel

- **Blocking up-front render.** Nothing shows until *every* page is rendered (`read_wings.php:624–632`). A 100-page issue means a long progress bar, ~100 base64 JPEGs held in memory simultaneously, and a real risk of mobile Safari killing the tab on large issues. There is no lazy/on-demand rendering.
- **Two touch systems fighting.** StPageFlip has its own swipe/drag/`mobileScrollSupport` handling (`read_wings.php:647,654`), and the file *also* adds custom `touchstart/move/end` pinch handlers on the same `#reader-area` (`read_wings.php:712–742`). They compete for the same gestures — a one-finger drag is ambiguous between "turn page" and "pan", which feels unpredictable.
- **Unmaintained engine.** StPageFlip 2.0.7 is the latest published version but the project is effectively abandoned; `flippingTime: 650ms` is on the slow side for mobile.

### C. Zoom only into the centre, no pan when zoomed — **confirmed, by design**

- `#flip-book-wrap` has `transform-origin: center center` (`read_wings.php:264`) and `applyZoom()` only ever sets `scale()`, never a `translate()` (`read_wings.php:705–708`).
- There is **no pan/drag handler at all** when zoomed. So you can zoom into the middle of the spread and that's it — you can't reach the corners or columns. This is a missing feature, not a bug in existing code.

### D. Single ↔ double page toggle — **hard-coded, no user control**

- View mode is decided once, by screen width: `usePortrait: isMobile` (`read_wings.php:650`), where `isMobile = width < 1024`. There is no button to switch, and StPageFlip can't cleanly flip this at runtime without re-initialising. A tablet user who'd prefer a single large page, or a phone-in-landscape user who wants the spread, has no say.

### E. Click / pinch to zoom into a specific area — **not implemented**

- Zoom origin is always the centre (see C). There is no point-anchored zoom: tapping/pinching a specific column doesn't zoom *there*. Double-tap only **resets** zoom to 1× (`read_wings.php:737–741`) — it doesn't zoom-to-point, which is the gesture users expect from Photos/Maps/every PDF app.

---

## 3. Library Survey

| Option | What it is | Pros | Cons | Fit for Wings |
|---|---|---|---|---|
| **StPageFlip / `page-flip` (current)** | JS 3D page-flip on images/HTML | Nice flip; we already use it | Unmaintained; no zoom/pan; no deep-zoom; engine and rendering are coupled in our setup | Keep the *look*, not the *foundation* |
| **`react-pageflip-enhanced` / community forks** | Maintained forks of StPageFlip (single-page mode, better touch; published Feb 2026) | Active; drop-in-ish; fixes some touch issues | Still image-based, still no deep-zoom/pan layer; React (we're vanilla PHP) | Useful reference, not a silver bullet |
| **PDF.js + custom UI (no flip)** | Mozilla's viewer engine, our own chrome | Gold-standard text sharpness, DPR-correct, true deep zoom, text layer/search, battle-tested on mobile | Heaviest to build; loses the flipbook charm Pat likes | Best *readability*, worst *vibe* |
| **Pre-rendered multi-res images + custom reader** | Generate page images at upload, serve sized to device | Crisp, instant load, tiny memory, full control over zoom/pan/toggle | Needs a server-side render step at upload; more upfront build | **Best all-round fit** |
| **turn.js** | jQuery-era flip | — | Abandoned ~2013, jQuery dependency | No |
| **Issuu / FlippingBook (hosted)** | SaaS embed | Zero maintenance, polished | Recurring £/$ per month, members leave our domain, branding/ads, content hosted off-site, login/gating friction | No — wrong for a members-only association magazine |

**Verified facts (June 2026):** `page-flip` latest is 2.0.7 and the original repo is not actively maintained; maintained forks exist. The PDF.js HiDPI fix is the `devicePixelRatio` multiplier — exactly what our `RENDER_SCALE = 1.5` omits. (Sources at the end.)

---

## 4. Recommended Path

**Rebuild from the ground up — keep the flip aesthetic, replace the foundation.** Specifically: move from "client renders the whole PDF to flat JPEGs every visit" to **server-side pre-rendered, multi-resolution page images + a dedicated zoom/pan layer + on-demand high-res for the zoomed page.**

### Why this and not the alternatives

- **Why not just patch the current file (Path A below)?** Two of the five complaints (low-res-on-zoom, and the slow/heavy blocking load) are *architectural* — they come from the flat-JPEG, render-everything-up-front model. You can make zoom sharper-ish and add pan in a day (worth doing as a stopgap — see Path A), but you can't make zoom truly crisp or the load truly fast without changing how pages are produced and delivered.
- **Why not a pure PDF.js viewer (Path C below)?** It's the sharpest possible result, but it throws away the page-flip experience Pat explicitly wants to keep ("use what we have as a base"). It's also the most code. Reserve it as the fallback if the flip turns out not to be worth the effort.
- **Why the pre-rendered image pipeline wins:** it fixes *every* complaint at once — crisp at any size (serve a resolution matched to the device), instant first paint (no up-front rasterising), low memory (load pages as you reach them), and it cleanly separates "the pages" from "the flip animation" and "the zoom/pan layer" so each can be made good independently.

### One honest constraint to resolve first

Server-side PDF→image needs a tool on the host: **`pdftoppm` (Poppler), Ghostscript, or ImageMagick**. On cPanel shared hosting these may or may not be available. Three ways to handle it, in order of preference:
1. **Render at upload, server-side** (`pdftoppm`/Ghostscript) — cleanest, if the binaries exist. *First action: check what's installed.*
2. **Render once on first view, client-side, then cache the images back to the server** — no server binary needed; the first reader "pays" the render, everyone after gets cached crisp images.
3. **Render client-side every time but DPR-correct + on-demand** — no pipeline, smallest change, but keeps the per-visit cost. This is essentially Path A done well.

---

## 5. Architecture Sketch (recommended rebuild)

```
UPLOAD (admin)
  PDF ──► [render step] ──► page images written to /uploads/wings/{id}/pages/
                              ├─ p001.webp   (low,   ~1000px wide — fast first paint)
                              ├─ p001@2x.webp (high, ~2200px wide — for zoom)
                              ├─ p002.webp ...
                              └─ manifest.json  { pageCount, w, h, sizes }

READER (member)
  ┌──────────────────────────────────────────────────────────────┐
  │ View-mode toggle: [ Single | Double ]   (user-controlled)     │
  ├──────────────────────────────────────────────────────────────┤
  │  FLIP LAYER  — page-flip engine, fed <img srcset> (low-res)    │
  │     • lazy: load current ± a few pages, not the whole issue    │
  │     • flip animation only; owns horizontal swipe gesture       │
  ├──────────────────────────────────────────────────────────────┤
  │  ZOOM/PAN LAYER  — sits above flip, activates on zoom > 1×     │
  │     • point-anchored pinch + double-tap-to-zoom-at-point       │
  │     • drag-to-pan, clamped to page bounds                      │
  │     • on zoom-in, swap the visible page to @2x (or PDF.js      │
  │       re-render at zoomScale × DPR) so it's genuinely crisp    │
  │     • owns one-finger drag *only while zoomed* (no gesture war)│
  └──────────────────────────────────────────────────────────────┘
```

**Component choices**

- **Pages:** WebP (smaller than JPEG, better text than JPEG, broad support in 2026) at two sizes. Sized to device on load via `srcset`/DPR so a phone gets a phone-appropriate image, a desktop gets the big one.
- **Flip:** keep a page-flip engine for the look — either StPageFlip pinned (it's stable even if unmaintained) or a maintained fork. The flip is now *decoupled* from rendering, so swapping engines later is cheap. If the flip proves more trouble than it's worth on mobile, degrade gracefully to a slide transition on small screens.
- **Zoom/pan:** a dedicated layer is the heart of the rebuild. Either a small library (`panzoom` / `react-zoom-pan-pinch`-equivalent) or ~100 lines of pointer-event handlers giving: pinch with focal-point anchoring, double-tap-to-zoom-at-point, drag-to-pan with bounds clamping. **Crucially, on zoom-in, swap to the high-res asset** so zoom adds detail instead of blur.
- **View toggle:** explicit Single/Double button, persisted per user (localStorage), independent of screen width. Default to double on wide screens, single on narrow.
- **Loading:** show page 1 as soon as *it* is ready; render/fetch neighbours on demand. Kills the blocking progress bar and the memory blow-up.
- **Accessibility:** real `<img alt>` per page, focus-visible controls, keyboard zoom (+/−/0), `aria-live` page counter, respect `prefers-reduced-motion` (skip the flip).
- **Performance budget:** first page visible < 1.5s on a mid phone; never hold more than ~6 page images in memory; 60fps pan/zoom (transform-only, `will-change` on the active page).
- **Deployment:** fits the existing cPanel "Update from Remote" flow. If using the upload-time render step, that runs in admin on upload — no change to the deploy workflow. Pre-rendered images live under `/uploads/wings/` like today.

---

## 6. Estimated Effort

Rough, solo, including testing on real phone + tablet:

| Path | What you get | Effort |
|---|---|---|
| **A — Targeted patches (stopgap)** | DPR-aware render (sharper at 1×), re-render visible page on zoom (sharper zoom), add drag-to-pan + point-anchored zoom, add a Single/Double toggle. Keeps the flat-JPEG/up-front-load model — load stays slow on big issues. | **0.5–1.5 days** |
| **B — Full rebuild (recommended)** | Everything in §5: pre-rendered multi-res images, lazy loading, decoupled flip + zoom/pan layers, high-res-on-zoom, user view toggle, a11y, perf budget. Fixes all five complaints properly. | **4–7 days** (add ~0.5 day if option-2 client-render-and-cache is needed because the host lacks `pdftoppm`/Ghostscript) |
| **C — PDF.js viewer, no flip (fallback)** | Maximum sharpness + text search, but loses the flipbook feel. | **3–5 days** |

### Suggested sequencing

1. **Now:** ship **Path A** as a same-week stopgap — DPR-correct render + sharp-on-zoom + pan are the highest-leverage fixes and directly answer the "unreadable / can't move around" pain.
2. **Decision gate:** confirm whether the host has `pdftoppm`/Ghostscript (determines render strategy for B).
3. **Then:** build **Path B** as the proper, robust reader. Keep Path C in your back pocket if the flip animation proves not worth maintaining on mobile.

---

### Sources
- [page-flip — npm](https://www.npmjs.com/package/page-flip) · [StPageFlip — GitHub](https://github.com/Nodlik/StPageFlip) (maintenance status)
- [react-pageflip-enhanced — npmx](https://npmx.dev/package/react-pageflip-enhanced) (maintained fork, Feb 2026)
- [Open Source Page Flip and PDF Viewer Solutions in JavaScript 2026 — portalZINE](https://portalzine.de/open-source-page-flip-and-pdf-viewer-solutions-in-javascript-2026/)
- [PDF.js Examples — Mozilla](https://mozilla.github.io/pdf.js/examples/) and [PDF.js mobile zoom discussion #17444](https://github.com/mozilla/pdf.js/discussions/17444) (the `devicePixelRatio` HiDPI technique our code omits)
