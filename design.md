# Goldwing Design System

The visual language of the Australian Goldwing Association website. This is the source of truth — if code and this doc disagree, the code wins, but raise the gap so we fix the doc.

A **live, in-browser version** of this guide is at `/admin/help/brand-style.php` (admin-only). That page pulls from the same CSS the production site uses, so swatches and components always reflect what visitors actually see.

---

## 1. Brand identity

The site speaks for a riders' club, not a corporate membership body. The tone is warm, plain, a little weathered. Black and gold do the heavy lifting — they read as Honda Goldwing without ever saying it — and a forest green carries every call-to-action so members always know what's clickable.

The whole site sits on a warm cream/sand background with soft gold and green light bleeding in at the corners. Nothing on the page should feel sterile, blue, or "SaaS". When in doubt, lean older, warmer, and quieter.

**Words we describe the brand with:** warm, plain, weathered, trustworthy, mate-to-mate, road-built.
**Words we don't:** premium, exclusive, luxurious, slick, disruptive.

---

## 2. Surfaces

The site has three distinct design surfaces. They share a palette but use different type stacks and component vocabularies because they serve different audiences. Don't mix them.

| Surface | Audience | CSS source | Type stack |
|---|---|---|---|
| **Public site** | Visitors, members browsing | `public_html/assets/styles.css` | Bebas Neue + Open Sans + Rajdhani |
| **Member forms** | Signup, account forms inside the public site | `.form-page` / `.form-card` rules in `styles.css` | Manrope + Noto Sans |
| **Admin console** | Staff, area reps, webmasters | Tailwind CDN config in `app/Views/partials/backend_head.php` | Playfair Display + Inter |

The public site is the "front of house" — moody, photographic, the road. The member forms are the "service counter" — soft, calm, easy on the eyes when someone's typing their card number in. The admin is the "back office" — dense, neutral, fast to scan.

---

## 3. Colour

All public-site tokens live in `:root {}` at the top of `public_html/assets/styles.css`.

### Core palette

| Token | Hex | Used for |
|---|---|---|
| `--black` | `#0b0b0b` | Navbar background, deep contrast surfaces |
| `--coal` | `#111111` | Dropdown menus, dark video sections |
| `--ink` | `#1c1a17` | Default text colour |
| `--cream` | `#f4f1e8` | Hero body copy, soft text on dark |
| `--paper` | `#ffffff` | Cards, page-cards, plan cards |
| `--sand` | `#e8e3d7` | Card borders, table dividers |
| `--gold` | `#9e9140` | Primary accents: badges, plan headers, navbar CTAs |
| `--gold-light` | `#cbbd6c` | Hero eyebrows, active nav state, hover gold |
| `--green` | `#4a9114` | Buttons, links, focus rings — the "do this" colour |
| `--green-dark` | `#2f6a0f` | Button hover, dark plan headers |
| `--muted` | `#5a5a55` | Secondary copy, table headers, meta text |

### Page background

The body is never flat. Default background is a layered gradient:

```css
radial-gradient(900px 500px at 5% 0%, rgba(158, 145, 64, 0.18), transparent 60%),
radial-gradient(800px 500px at 95% 10%, rgba(74, 145, 20, 0.18), transparent 65%),
linear-gradient(135deg, #f6f2ea 0%, #f1eee5 45%, #ebe6db 100%);
```

That gold/green corner glow is the brand's signature. If you build a new section that overrides the background, get the same atmospheric warmth back somewhere on the page (or sit a `.page-card` on top so the body shows through).

### Status colours

Defined inline on `.alert` rules — keep using these, don't introduce new ones:

| State | Background | Text | Border |
|---|---|---|---|
| Error | `#f9e7e2` | `#9a2b1e` | `#f2c2b8` |
| Success | `#e7f2e3` | `#2f6a0f` | `#c3dfbc` |

### Admin palette (Tailwind)

The admin runs on Tailwind with a custom theme. Same gold-and-green idea, paler and softer:

| Token | Hex | Used for |
|---|---|---|
| `primary` | `#F2C94C` | Accent buttons, search input focus ring, active nav |
| `secondary` | `#2F7D32` | "Written" status chips, walkthrough tiles |
| `background-light` | `#F7F7F4` | Page background |
| `card-light` | `#FFFFFF` | Cards |
| `primary-strong` | `#cfa032` | Deep accents |
| `ocean` | `#2f7d32` | Same green, semantic alias |
| `ember` | `#c4723b` | Warning / attention chips |

Defined in `app/Views/partials/backend_head.php` inside the inline `tailwind.config`.

---

## 4. Typography

### Public site

Three faces, each with a strict role. Don't add a fourth without a real reason.

| Family | Role | Where |
|---|---|---|
| **Bebas Neue** | Display — all headings, brand title, plan headers | `h1`–`h5`, `.brand-title`, `.membership-plan__header h3` |
| **Open Sans** | Body — everything that's read at length | `body` default |
| **Rajdhani** | UI — nav links, eyebrows, buttons, labels, badges | `.nav-link`, `.hero__eyebrow`, `.button`, `label`, `.badge` |

**Rules of thumb:**
- Headings are uppercase, slightly letter-spaced (`letter-spacing: 0.04em`).
- Body copy is 17px / line-height 1.7. Don't shrink it — older eyes read this site.
- Eyebrows and small UI text sit at ~0.75rem with `letter-spacing: 0.2em` and uppercase. They're a strong signal of the brand voice.
- Hero h1 uses `clamp(2.6rem, 5vw, 4.6rem)` — never set fixed huge font sizes.

### Member forms

A deliberate softening for forms. Same site, gentler typography so a signup doesn't feel like a press release.

| Family | Role |
|---|---|
| **Manrope** | Headings inside `.form-card`, `.form-button`, `.form-header h1` |
| **Noto Sans** | Body inside `.form-page`, `.form-card` |

Form headings keep title case and `letter-spacing: -0.02em` — the opposite vibe to public site headings.

### Admin

| Family | Role |
|---|---|
| **Playfair Display** | `font-display` — page titles, section headings, card headers |
| **Inter** | Default sans for everything else |

Use Material Icons Outlined for any icon work in the admin (`<span class="material-icons-outlined">`).

---

## 5. Spacing & layout

### Container

The public-site container is `max-width: 1200px` with `padding: 0 1.75rem`. Admin pages use Tailwind's `max-w-5xl` / `max-w-4xl` containers with `p-6 md:p-10`.

### Vertical rhythm

- Hero: `5.5rem 0 4.5rem` top/bottom padding, `--compact` variant drops to `3.5rem 0 3rem`.
- Page sections: pulled up `-2.5rem` so the page-card overlaps the hero.
- Page-card padding: `2.2rem`.
- Card padding: `1.75rem`.

### Grid utilities

- `.grid` with `gap: 1.5rem` — base.
- `.grid-3` with `repeat(auto-fit, minmax(240px, 1fr))` — flexible 3-up.
- `.committee-grid` / `.chapter-grid` are **locked** to 3 columns at 900px+ regardless of content. Don't switch them to auto-fit — a solo card in Tasmania expanding to full width looks wrong next to a 9-card NSW grid.

---

## 6. Radii, shadows, depth

Tokenised on `:root`:

| Token | Value | Used for |
|---|---|---|
| `--shadow` | `0 24px 50px rgba(0,0,0,0.18)` | Page-cards, hero-overlapping cards |
| `--shadow-soft` | `0 12px 30px rgba(0,0,0,0.12)` | Plain cards, intro images |

**Border radii** (no token, but follow the convention):

- Pills / buttons: `999px`
- Cards: `16px`
- Page-card: `18px`
- Membership plan cards: `20–24px`
- Inputs, alerts, dropdowns: `10–14px`

The signature card detail is the **6–8px top border in gold** on `.page-card` and `.membership-plan`. Don't drop that — it's how a card identifies itself as a "section" vs a "tile".

---

## 7. Components

Every component below lives in `public_html/assets/styles.css`. The brand-style preview page renders each one live.

### Buttons

`.button` — outlined pill, green border, green text on white.
`.button.primary` — solid green with a soft green shadow. The default "do this".
`.button.ghost` — transparent with white border, only on dark surfaces (hero, navbar).

All three use Rajdhani, uppercase, `letter-spacing: 0.12em`, 999px radius. Don't add new button variants — if you need a different action, use copy to disambiguate, not a new colour.

### Cards

- `.card` — base card, sand border, soft shadow.
- `.page-card` — the "section" card with the gold top border, used as the main content container on every page.
- `.membership-card` — slightly chunkier (`border-radius: 20px`, gold 8px top stripe, 2.5rem padding).
- `.membership-plan` — pricing card with a coloured header band (`__header` gold, `__header--dark` green-dark, `__header--mid` paler).

### Navbar

Black background, **4px solid green bottom border**. White Rajdhani nav links, gold CTA pill. The green border is non-negotiable — it's how we anchor brand colour above the fold.

### Hero

Photographic background, dark gradient overlay, gold corner-glow. Eyebrow (Rajdhani uppercase gold-light) → h1 (Bebas Neue cream/white) → `.hero__lead` (cream body) → `.hero__actions` (button row). Animates in with a 0.85s `hero-rise` keyframe.

### Badges

Gold pill, black text, uppercase Rajdhani, 0.75rem. Used for plan tiers, member statuses, small flags. Don't use badges for navigation.

### Forms

- Labels: Rajdhani uppercase 0.85rem, `letter-spacing: 0.08em`.
- Inputs: white background, sand border, 10px radius, 0.65rem 0.8rem padding.
- Focus: 2px green ring at 30% alpha + green border.
- Form buttons inside `.form-card` use the softer Manrope variant (see Surfaces).

### Tables

- Full-width, sand bottom borders.
- Headers in Rajdhani uppercase muted, 0.75rem, `letter-spacing: 0.12em`.

### Alerts

`.alert.error` and `.alert.success` only. See the status colours table above.

---

## 8. Imagery

- **Hero photography** is dark, moody, road-shot. We layer a black gradient overlay on top so text always reads cream-on-near-black.
- **Member portraits** (committee, area reps) sit in 1:1 squares with `object-fit: cover` — locked 3-up grid at desktop.
- **Chapter photos** use 4:3 within `.chapter-state-card`.
- **Membership pricing background** is a soft cream tint (`rgba(244, 241, 232, 0.8)`) so the white plan cards still pop.

Don't upload bright, oversaturated, or stock-looking photos. Real rides, real members, golden hour where possible.

---

## 9. Voice & tone

- **Plain English.** Write the way you'd explain it to a mate over a coffee.
- **Active voice.** "We'll email you" beats "An email will be sent".
- **No filler.** Cut "please", "kindly", "we are pleased to inform you".
- **Member-first.** "You" / "your". Not "the user" / "the customer".
- **Headings are short.** Two or three words is a heading. Five words is a sentence — make it body copy.
- **Empty states have personality.** "Nothing here yet — that'll change once you sign up." beats "No records found."

The site does not use emojis. Icons (Material Icons Outlined in admin, inline SVG in public) carry visual signals instead.

---

## 10. Accessibility

- **Body text never below 16px**, default 17px. Forms keep this.
- **Focus rings are visible** — green 2px ring on inputs, never `outline: none` without a replacement.
- **Buttons have hover and active states** — never disable both.
- **Honour `prefers-reduced-motion`** — there's already a media query at line ~1304 of `styles.css` that disables hero-rise and other animations. Any new animation must respect it.
- **Colour contrast** — green-on-white passes AA. Gold-on-white does **not** at body sizes; only use `--gold` on dark backgrounds or for ≥18px text.

---

## 11. Don'ts

- Don't introduce a new font family without removing one.
- Don't introduce a new accent colour — green is "do this", gold is "this is special", nothing else needs a colour.
- Don't use blue. There is no blue in this brand.
- Don't use harsh black (`#000`). Use `--black #0b0b0b` or `--ink #1c1a17`.
- Don't drop the gold top-border on `.page-card` or membership cards.
- Don't switch heading case to title-case on public pages — uppercase Bebas Neue is the look.
- Don't use sentence-case Rajdhani for nav links — they're always uppercase.
- Don't replace Material Icons Outlined in the admin with a different icon set.

---

## 12. Where things live

| What | File |
|---|---|
| Public-site CSS (single source) | `public_html/assets/styles.css` |
| Color/shadow tokens | `public_html/assets/styles.css` lines 1–14 |
| Admin Tailwind theme | `app/Views/partials/backend_head.php` |
| Backend layout shell | `app/Views/partials/backend_head.php` + `backend_footer.php` + `backend_admin_sidebar.php` |
| Member-facing nav | rendered by `App\Services\NavigationService` into the public layout |
| Site-wide settings (logo, favicon, name) | `SettingsService::getGlobal('site.*')` |
| Hero image upload location | `public_html/uploads/about/` |
| Sponsors, member photos | `public_html/assets/img/`, `public_html/uploads/` |
| Live brand showcase | `public_html/admin/help/brand-style.php` |

---

## 13. Changing the design system

1. Update the CSS in `public_html/assets/styles.css` (or admin theme in `backend_head.php`).
2. Update this `design.md` so the doc stays the source of truth.
3. Open `/admin/help/brand-style.php` in the browser to eyeball the changes against every component at once.
4. If you've moved or removed a token, search the codebase — `grep -rn "var(--old-token)" public_html/` — before you delete.

The brand-style page is the visual smoke test. If something there looks wrong, something on a real member-facing page also looks wrong.
