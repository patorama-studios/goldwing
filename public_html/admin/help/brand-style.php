<?php
/**
 * Brand Style Guide — live, in-browser reference for the Goldwing design system.
 *
 * Renders every palette token, type face, and component the public site uses,
 * pulling from the real /assets/styles.css so swatches and components always
 * reflect what visitors actually see. Companion to /design.md at repo root.
 *
 * Admin-only. Deliberately doesn't load the admin Tailwind layout — the public-
 * site CSS and admin Tailwind cascade-fight, and the point of this page is to
 * show what real members see, not what staff see.
 */

require_once __DIR__ . '/../../../app/bootstrap.php';

require_role(['admin', 'webmaster']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Brand Style Guide — Goldwing</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Open+Sans:wght@400;600;700&family=Rajdhani:wght@500;600;700&family=Manrope:wght@400;500;600;700&family=Noto+Sans:wght@400;500;600&family=Inter:wght@300;400;500;600&family=Playfair+Display:wght@400;600;700&display=swap" rel="stylesheet">
  <link href="/assets/styles.css" rel="stylesheet">
  <style>
    /* Page-scoped layout — keep styleguide chrome out of the public CSS. */
    .sg-topbar {
      background: var(--black);
      border-bottom: 4px solid var(--green);
      color: #fff;
      padding: 1rem 1.75rem;
      display: flex;
      align-items: center;
      gap: 1.5rem;
      font-family: 'Rajdhani', sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.14em;
      font-size: 0.85rem;
    }
    .sg-topbar a { color: var(--gold-light); }
    .sg-topbar a:hover { color: #fff; }
    .sg-topbar__title { color: #fff; font-weight: 600; }

    .sg-wrap {
      max-width: 1180px;
      margin: 0 auto;
      padding: 2.5rem 1.75rem 5rem;
    }

    .sg-section { margin: 3rem 0 0; }
    .sg-section:first-of-type { margin-top: 0; }

    .sg-section__eyebrow {
      font-family: 'Rajdhani', sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.22em;
      font-size: 0.7rem;
      color: var(--muted);
      margin: 0 0 0.25rem;
    }
    .sg-section__title {
      font-family: 'Bebas Neue', sans-serif;
      font-size: 2rem;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      margin: 0 0 0.4rem;
      color: var(--ink);
    }
    .sg-section__lead {
      color: var(--muted);
      max-width: 720px;
      margin: 0 0 1.5rem;
      font-family: 'Open Sans', sans-serif;
    }

    .sg-grid {
      display: grid;
      gap: 1rem;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }

    /* Colour swatches */
    .sg-swatch {
      background: var(--paper);
      border: 1px solid var(--sand);
      border-radius: 14px;
      overflow: hidden;
      box-shadow: var(--shadow-soft);
      font-family: 'Open Sans', sans-serif;
    }
    .sg-swatch__chip { height: 96px; }
    .sg-swatch__meta { padding: 0.85rem 1rem 1rem; }
    .sg-swatch__name {
      font-family: 'Rajdhani', sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      font-size: 0.85rem;
      color: var(--ink);
      font-weight: 600;
    }
    .sg-swatch__hex {
      font-family: 'Open Sans', sans-serif;
      font-size: 0.78rem;
      color: var(--muted);
      letter-spacing: 0.02em;
    }
    .sg-swatch__use {
      font-size: 0.75rem;
      color: var(--muted);
      margin-top: 0.3rem;
      line-height: 1.45;
    }

    /* Type specimens */
    .sg-type {
      background: var(--paper);
      border: 1px solid var(--sand);
      border-radius: 14px;
      padding: 1.5rem;
      box-shadow: var(--shadow-soft);
    }
    .sg-type__label {
      font-family: 'Rajdhani', sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.16em;
      font-size: 0.7rem;
      color: var(--muted);
      margin: 0 0 0.45rem;
    }
    .sg-type__meta {
      font-family: 'Open Sans', sans-serif;
      font-size: 0.78rem;
      color: var(--muted);
      margin-top: 0.4rem;
    }

    .sg-eyebrow {
      font-family: 'Rajdhani', sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.2em;
      font-size: 0.75rem;
      color: var(--gold);
    }

    /* Component preview canvas */
    .sg-preview {
      background: var(--paper);
      border: 1px solid var(--sand);
      border-radius: 14px;
      padding: 1.5rem;
      box-shadow: var(--shadow-soft);
    }
    .sg-preview--dark {
      background: var(--coal);
      color: var(--cream);
      border-color: var(--coal);
    }
    .sg-preview--dark .sg-type__label { color: var(--gold-light); }

    .sg-row {
      display: flex;
      flex-wrap: wrap;
      gap: 0.85rem;
      align-items: center;
    }

    /* Hero mini-preview */
    .sg-hero {
      position: relative;
      border-radius: 18px;
      overflow: hidden;
      padding: 2.5rem 2rem;
      color: #fff;
      background:
        radial-gradient(600px 320px at 80% 20%, rgba(158, 145, 64, 0.45), transparent 60%),
        linear-gradient(115deg, rgba(0,0,0,0.92), rgba(0,0,0,0.55) 70%),
        linear-gradient(135deg, #2a2622 0%, #14110e 100%);
    }
    .sg-hero__eyebrow {
      font-family: 'Rajdhani', sans-serif;
      text-transform: uppercase;
      letter-spacing: 0.2em;
      font-size: 0.75rem;
      color: var(--gold-light);
      display: inline-block;
      margin-bottom: 0.6rem;
    }
    .sg-hero h2 {
      color: #fff;
      font-family: 'Bebas Neue', sans-serif;
      font-size: clamp(2rem, 4vw, 3.2rem);
      letter-spacing: 0.04em;
      margin: 0 0 0.6rem;
    }
    .sg-hero__lead {
      color: var(--cream);
      max-width: 540px;
      margin: 0 0 1.2rem;
    }

    /* Member-form surface preview */
    .sg-formdemo {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 18px;
      box-shadow: 0 20px 45px rgba(17, 24, 39, 0.08);
      padding: 2rem;
      font-family: 'Noto Sans', 'Open Sans', sans-serif;
    }
    .sg-formdemo h3 {
      font-family: 'Manrope', 'Open Sans', sans-serif;
      font-size: 1.5rem;
      letter-spacing: -0.02em;
      text-transform: none;
      color: #0f172a;
      margin: 0 0 0.3rem;
    }
    .sg-formdemo p { color: #6b7280; margin: 0 0 1.25rem; }
    .sg-formdemo label {
      font-family: 'Manrope', sans-serif;
      text-transform: none;
      letter-spacing: 0;
      color: #374151;
      font-size: 0.85rem;
    }
    .sg-formdemo input {
      border: 1px solid #d1d5db;
      border-radius: 12px;
    }
    .sg-formdemo__btn {
      background: var(--green);
      color: #fff;
      border: none;
      border-radius: 12px;
      padding: 0.75rem 1.4rem;
      font-family: 'Manrope', sans-serif;
      font-weight: 600;
      letter-spacing: 0.02em;
      cursor: pointer;
    }

    /* Admin-surface preview (Tailwind-ish, inline) */
    .sg-admin {
      background: #F7F7F4;
      border-radius: 18px;
      padding: 1.5rem;
      font-family: 'Inter', sans-serif;
      color: #111827;
    }
    .sg-admin__card {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      padding: 1.25rem 1.5rem;
      box-shadow: 0 12px 30px rgba(17, 24, 39, 0.08);
    }
    .sg-admin h3 {
      font-family: 'Playfair Display', serif;
      font-size: 1.4rem;
      letter-spacing: 0;
      text-transform: none;
      color: #111827;
      margin: 0 0 0.4rem;
      font-weight: 600;
    }
    .sg-admin__chip {
      display: inline-block;
      padding: 0.2rem 0.6rem;
      border-radius: 999px;
      font-size: 0.7rem;
      font-weight: 600;
      letter-spacing: 0.02em;
    }
    .sg-admin__chip--primary { background: #F2C94C; color: #1c1a17; }
    .sg-admin__chip--secondary { background: #e7f2e3; color: #2F7D32; }

    /* Two-col layout helpers */
    .sg-twocol {
      display: grid;
      gap: 1.25rem;
      grid-template-columns: 1fr;
    }
    @media (min-width: 760px) {
      .sg-twocol { grid-template-columns: 1fr 1fr; }
    }

    .sg-notes {
      font-family: 'Open Sans', sans-serif;
      font-size: 0.88rem;
      color: var(--muted);
      line-height: 1.6;
    }
    .sg-notes code {
      background: var(--sand);
      padding: 0.05rem 0.35rem;
      border-radius: 4px;
      font-size: 0.85rem;
      color: var(--ink);
    }
    .sg-notes ul { padding-left: 1.1rem; }
    .sg-notes li { margin-bottom: 0.35rem; }
  </style>
</head>
<body>

<div class="sg-topbar">
  <a href="/admin/help/docs/">&larr; Back to docs</a>
  <span class="sg-topbar__title">Brand Style Guide</span>
  <span style="margin-left:auto; opacity:0.7;">Live — pulls from /assets/styles.css</span>
</div>

<div class="sg-wrap">

  <!-- INTRO ─────────────────────────────────────────────────────────── -->
  <section class="sg-section">
    <p class="sg-section__eyebrow">Goldwing design system</p>
    <h1 class="sg-section__title" style="font-size: 2.6rem;">Brand style guide</h1>
    <p class="sg-section__lead">
      Every colour, typeface, and component the public site uses, rendered live with the
      production stylesheet. If something looks off here it looks off on a real member-facing
      page. Companion to <code>design.md</code> at the repo root.
    </p>
  </section>

  <!-- COLOUR ─────────────────────────────────────────────────────────── -->
  <section class="sg-section">
    <p class="sg-section__eyebrow">Section 01</p>
    <h2 class="sg-section__title">Colour palette</h2>
    <p class="sg-section__lead">All tokens come from <code style="background:var(--sand); padding:0.05rem 0.35rem; border-radius:4px;">public_html/assets/styles.css</code> lines 1–14. Don't introduce new accent colours.</p>

    <div class="sg-grid">
      <?php
      $palette = [
        ['name' => '--black',       'hex' => '#0b0b0b', 'fg' => '#fff', 'use' => 'Navbar background, deep contrast surfaces'],
        ['name' => '--coal',        'hex' => '#111111', 'fg' => '#fff', 'use' => 'Dropdown menus, dark video sections'],
        ['name' => '--ink',         'hex' => '#1c1a17', 'fg' => '#fff', 'use' => 'Default body text colour'],
        ['name' => '--muted',       'hex' => '#5a5a55', 'fg' => '#fff', 'use' => 'Secondary copy, table headers, meta text'],
        ['name' => '--gold',        'hex' => '#9e9140', 'fg' => '#111', 'use' => 'Primary accents — badges, plan headers, CTA pill'],
        ['name' => '--gold-light',  'hex' => '#cbbd6c', 'fg' => '#111', 'use' => 'Hero eyebrows, active nav, hover gold'],
        ['name' => '--green',       'hex' => '#4a9114', 'fg' => '#fff', 'use' => 'Buttons, links, focus ring — the "do this" colour'],
        ['name' => '--green-dark',  'hex' => '#2f6a0f', 'fg' => '#fff', 'use' => 'Button hover, dark plan headers'],
        ['name' => '--cream',       'hex' => '#f4f1e8', 'fg' => '#1c1a17', 'use' => 'Hero body copy, soft text on dark'],
        ['name' => '--paper',       'hex' => '#ffffff', 'fg' => '#1c1a17', 'use' => 'Card, page-card, plan card backgrounds'],
        ['name' => '--sand',        'hex' => '#e8e3d7', 'fg' => '#1c1a17', 'use' => 'Card borders, table dividers'],
      ];
      foreach ($palette as $c): ?>
        <div class="sg-swatch">
          <div class="sg-swatch__chip" style="background: <?= htmlspecialchars($c['hex']) ?>; color: <?= htmlspecialchars($c['fg']) ?>; display:flex; align-items:flex-end; padding:0.7rem 0.85rem; font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:0.12em; font-size:0.72rem;">
            <?= htmlspecialchars($c['hex']) ?>
          </div>
          <div class="sg-swatch__meta">
            <div class="sg-swatch__name"><?= htmlspecialchars($c['name']) ?></div>
            <div class="sg-swatch__hex"><?= htmlspecialchars($c['hex']) ?></div>
            <div class="sg-swatch__use"><?= htmlspecialchars($c['use']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <p class="sg-notes" style="margin-top:1.25rem;">
      <strong>Status colours</strong> live on <code>.alert</code>: error background <code>#f9e7e2</code> / text <code>#9a2b1e</code>;
      success background <code>#e7f2e3</code> / text <code>#2f6a0f</code>.
    </p>
  </section>

  <!-- TYPOGRAPHY ─────────────────────────────────────────────────────── -->
  <section class="sg-section">
    <p class="sg-section__eyebrow">Section 02</p>
    <h2 class="sg-section__title">Typography</h2>
    <p class="sg-section__lead">Three faces on the public site, each with a strict role. The member-form and admin surfaces use a separate stack — see further down.</p>

    <div class="sg-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
      <div class="sg-type">
        <p class="sg-type__label">Display — Bebas Neue</p>
        <div style="font-family:'Bebas Neue',sans-serif; font-size:3.2rem; letter-spacing:0.04em; text-transform:uppercase; line-height:1; color:var(--ink);">Ride together</div>
        <p class="sg-type__meta">All headings <code>h1</code>–<code>h5</code>, plan headers, brand title. Uppercase, <code>letter-spacing: 0.04em</code>.</p>
      </div>

      <div class="sg-type">
        <p class="sg-type__label">Body — Open Sans</p>
        <div style="font-family:'Open Sans',sans-serif; font-size:1.06rem; line-height:1.7; color:var(--ink);">
          Goldwing riders share kilometres, not status. The body copy across the site stays at 17px / line-height 1.7 because older eyes read this site too.
        </div>
        <p class="sg-type__meta">Default body. 17px / 1.7. Don't shrink it.</p>
      </div>

      <div class="sg-type">
        <p class="sg-type__label">UI — Rajdhani</p>
        <div style="font-family:'Rajdhani',sans-serif; text-transform:uppercase; letter-spacing:0.12em; font-size:1.05rem; color:var(--ink);">Become a member</div>
        <p class="sg-type__meta">Nav links, buttons, eyebrows, badges, form labels. Always uppercase.</p>
      </div>
    </div>

    <div class="sg-preview" style="margin-top:1.25rem;">
      <p class="sg-type__label">Scale specimen</p>
      <span class="sg-eyebrow">Eyebrow / hero overline</span>
      <h1 style="margin:0.4rem 0 0.6rem;">Heading 1 — Bebas Neue</h1>
      <h2 style="margin:0 0 0.5rem;">Heading 2 — section heading</h2>
      <h3 style="margin:0 0 0.5rem;">Heading 3 — card heading</h3>
      <h4 style="margin:0 0 0.5rem;">Heading 4 — minor heading</h4>
      <p style="margin:0 0 0.8rem;">Body copy in Open Sans. Plain English, active voice, never below 16px. Links sit in <a href="#">the green token</a> with a subtle hover shift.</p>
      <p style="color:var(--muted); margin:0;">Muted secondary copy uses <code style="background:var(--sand); padding:0.05rem 0.35rem; border-radius:4px;">var(--muted)</code> for legal text, meta, captions.</p>
    </div>
  </section>

  <!-- BUTTONS ────────────────────────────────────────────────────────── -->
  <section class="sg-section">
    <p class="sg-section__eyebrow">Section 03</p>
    <h2 class="sg-section__title">Buttons</h2>
    <p class="sg-section__lead">Three variants, no more. If you need a different action, use copy to disambiguate.</p>

    <div class="sg-twocol">
      <div class="sg-preview">
        <p class="sg-type__label">On light surface</p>
        <div class="sg-row">
          <a href="#" class="button primary" onclick="return false;">Become a member</a>
          <a href="#" class="button" onclick="return false;">Learn more</a>
        </div>
      </div>
      <div class="sg-preview sg-preview--dark">
        <p class="sg-type__label">On dark surface</p>
        <div class="sg-row">
          <a href="#" class="button primary" onclick="return false;">Become a member</a>
          <a href="#" class="button ghost" onclick="return false;">Watch the video</a>
        </div>
      </div>
    </div>

    <p class="sg-notes" style="margin-top:1rem;">
      <code>.button.primary</code> — solid green, default "do this".
      <code>.button</code> — outlined green pill, secondary.
      <code>.button.ghost</code> — transparent white border, dark surfaces only.
      All use Rajdhani, uppercase, 999px radius.
    </p>
  </section>

  <!-- BADGES + LINKS ─────────────────────────────────────────────────── -->
  <section class="sg-section">
    <p class="sg-section__eyebrow">Section 04</p>
    <h2 class="sg-section__title">Badges, links, eyebrows</h2>

    <div class="sg-preview">
      <div class="sg-row" style="gap:1.25rem;">
        <span class="badge">Featured</span>
        <span class="badge">Full member</span>
        <span class="badge">Sold out</span>
      </div>
      <hr style="border:none; border-top:1px solid var(--sand); margin:1.25rem 0;">
      <p style="margin:0 0 0.5rem;">A <a href="#" onclick="return false;">link</a> in body copy uses <code style="background:var(--sand);padding:0.05rem 0.35rem;border-radius:4px;">var(--green)</code>, no underline, with a colour-shift on hover.</p>
      <span class="sg-eyebrow">Eyebrow — Rajdhani 0.75rem letter-spacing 0.2em</span>
    </div>
  </section>

  <!-- CARDS ──────────────────────────────────────────────────────────── -->
  <section class="sg-section">
    <p class="sg-section__eyebrow">Section 05</p>
    <h2 class="sg-section__title">Cards</h2>
    <p class="sg-section__lead">The gold top-border on <code>.page-card</code> is the brand's signature card detail — don't drop it.</p>

    <div class="sg-twocol">
      <div class="card">
        <h3 style="margin:0 0 0.4rem;">Plain card</h3>
        <p style="margin:0; color:var(--muted);">Used for tiles, lists, area-rep cards. <code style="background:var(--sand);padding:0.05rem 0.35rem;border-radius:4px;">.card</code> — sand border, soft shadow, 16px radius.</p>
      </div>

      <div class="page-card">
        <h3 style="margin:0 0 0.4rem;">Page-card with gold stripe</h3>
        <p style="margin:0; color:var(--muted);">Main section container. <code style="background:var(--sand);padding:0.05rem 0.35rem;border-radius:4px;">.page-card</code> — 18px radius, 6px gold top-border, larger shadow.</p>
      </div>
    </div>
  </section>

  <!-- ALERTS ─────────────────────────────────────────────────────────── -->
  <section class="sg-section">
    <p class="sg-section__eyebrow">Section 06</p>
    <h2 class="sg-section__title">Alerts</h2>

    <div class="sg-preview">
      <div class="alert error">Your payment couldn't be processed. Check the card and try again.</div>
      <div class="alert success">Membership renewed — confirmation sent to your email.</div>
    </div>
  </section>

  <!-- FORMS ──────────────────────────────────────────────────────────── -->
  <section class="sg-section">
    <p class="sg-section__eyebrow">Section 07</p>
    <h2 class="sg-section__title">Form elements</h2>
    <p class="sg-section__lead">Public-site forms use the Rajdhani label / pill button look. Member signup forms use a softer Manrope + Noto Sans stack — see Section 11.</p>

    <div class="sg-preview">
      <div class="form-group">
        <label for="sg-name">Your name</label>
        <input type="text" id="sg-name" placeholder="Pat Goldwing" autocomplete="off">
      </div>
      <div class="form-group">
        <label for="sg-state">State</label>
        <select id="sg-state">
          <option>New South Wales</option>
          <option>Victoria</option>
          <option>Queensland</option>
        </select>
      </div>
      <div class="form-group">
        <label for="sg-msg">Tell us about your ride</label>
        <textarea id="sg-msg" rows="3" placeholder="2021 GL1800 Tour, gold over black…"></textarea>
      </div>
      <div class="sg-row">
        <button type="button" class="button primary">Submit</button>
        <button type="button" class="button">Cancel</button>
      </div>
    </div>
  </section>

  <!-- TABLES ─────────────────────────────────────────────────────────── -->
  <section class="sg-section">
    <p class="sg-section__eyebrow">Section 08</p>
    <h2 class="sg-section__title">Tables</h2>

    <div class="sg-preview">
      <table class="table">
        <thead>
          <tr>
            <th>Member</th>
            <th>Chapter</th>
            <th>Tier</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Pat M.</td>
            <td>NSW Northern Rivers</td>
            <td><span class="badge">Full</span></td>
            <td>Active</td>
          </tr>
          <tr>
            <td>Sam K.</td>
            <td>VIC Central</td>
            <td><span class="badge">Associate</span></td>
            <td>Expires Aug 2026</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <!-- HERO ───────────────────────────────────────────────────────────── -->
  <section class="sg-section">
    <p class="sg-section__eyebrow">Section 09</p>
    <h2 class="sg-section__title">Hero pattern</h2>
    <p class="sg-section__lead">Dark photographic background, gold corner-glow, eyebrow → headline → lead → actions. The 4px green border below the hero is the navbar accent — keep it.</p>

    <div class="sg-hero">
      <span class="sg-hero__eyebrow">Australian Goldwing Association</span>
      <h2>Ride further, ride together.</h2>
      <p class="sg-hero__lead">Join a community of Goldwing riders across every state. Tours, ride-days, and the people who know the back roads.</p>
      <div class="sg-row">
        <a href="#" class="button primary" onclick="return false;">Become a member</a>
        <a href="#" class="button ghost" onclick="return false;">Watch the video</a>
      </div>
    </div>
  </section>

  <!-- MEMBERSHIP PLAN HEADERS ────────────────────────────────────────── -->
  <section class="sg-section">
    <p class="sg-section__eyebrow">Section 10</p>
    <h2 class="sg-section__title">Membership plan headers</h2>
    <p class="sg-section__lead">The pricing page leans on three plan-header variants — gold, mid, dark — to differentiate tiers without introducing new accent colours.</p>

    <div class="sg-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
      <div style="border-radius:20px; overflow:hidden; box-shadow:var(--shadow-soft); border:1px solid var(--sand); background:var(--paper);">
        <div style="background:var(--gold); color:#1c1a17; text-align:center; padding:1.75rem; font-family:'Bebas Neue',sans-serif; letter-spacing:0.04em; text-transform:uppercase;">
          <div style="font-size:1.5rem;">Associate</div>
          <div style="font-size:0.8rem; font-family:'Rajdhani',sans-serif; letter-spacing:0.18em; opacity:0.8;">From $40 / year</div>
        </div>
        <div style="padding:1.25rem; color:var(--muted); font-size:0.9rem;">Standard header — gold on black text.</div>
      </div>
      <div style="border-radius:20px; overflow:hidden; box-shadow:var(--shadow-soft); border:1px solid var(--sand); background:var(--paper);">
        <div style="background:#7a8b3a; color:#fff; text-align:center; padding:1.75rem; font-family:'Bebas Neue',sans-serif; letter-spacing:0.04em; text-transform:uppercase;">
          <div style="font-size:1.5rem;">Full member</div>
          <div style="font-size:0.8rem; font-family:'Rajdhani',sans-serif; letter-spacing:0.18em; opacity:0.85;">$95 / year</div>
        </div>
        <div style="padding:1.25rem; color:var(--muted); font-size:0.9rem;">Mid header variant — paler bridge between gold and green.</div>
      </div>
      <div style="border-radius:20px; overflow:hidden; box-shadow:var(--shadow-soft); border:1px solid var(--sand); background:var(--paper);">
        <div style="background:var(--green-dark); color:#fff; text-align:center; padding:1.75rem; font-family:'Bebas Neue',sans-serif; letter-spacing:0.04em; text-transform:uppercase;">
          <div style="font-size:1.5rem;">Life member</div>
          <div style="font-size:0.8rem; font-family:'Rajdhani',sans-serif; letter-spacing:0.18em; opacity:0.85;">One-time $850</div>
        </div>
        <div style="padding:1.25rem; color:var(--muted); font-size:0.9rem;">Dark variant — green-dark on white text.</div>
      </div>
    </div>
  </section>

  <!-- MEMBER FORM SURFACE ───────────────────────────────────────────── -->
  <section class="sg-section">
    <p class="sg-section__eyebrow">Section 11</p>
    <h2 class="sg-section__title">Member-form surface</h2>
    <p class="sg-section__lead">A deliberate softening for signup / account forms. Same green CTA, but Manrope + Noto Sans typography, larger radii, less uppercase. Calmer on the eyes when someone's mid-payment.</p>

    <div class="sg-formdemo">
      <h3>Become a member</h3>
      <p>It takes about three minutes. You can save and come back.</p>
      <label for="sg-fdname">Full name</label>
      <input type="text" id="sg-fdname" placeholder="Pat Goldwing" style="margin-bottom:1rem;">
      <label for="sg-fdemail">Email</label>
      <input type="email" id="sg-fdemail" placeholder="pat@example.com" style="margin-bottom:1.25rem;">
      <button type="button" class="sg-formdemo__btn">Continue</button>
    </div>
  </section>

  <!-- ADMIN SURFACE ─────────────────────────────────────────────────── -->
  <section class="sg-section">
    <p class="sg-section__eyebrow">Section 12</p>
    <h2 class="sg-section__title">Admin surface</h2>
    <p class="sg-section__lead">The admin console runs on Tailwind with a custom theme — Playfair Display for headings, Inter for body. Same gold-and-green idea, paler and softer because admins live here all day.</p>

    <div class="sg-admin">
      <div class="sg-admin__card">
        <span class="sg-admin__chip sg-admin__chip--primary">PRIMARY · #F2C94C</span>
        <span class="sg-admin__chip sg-admin__chip--secondary">SECONDARY · #2F7D32</span>
        <h3 style="margin-top:0.8rem;">Recent activity</h3>
        <p style="margin:0; color:#6b7280; font-size:0.92rem;">Admin cards use 16–24px radii, 12/30 soft shadow, Playfair display headings, Inter body. Material Icons Outlined for iconography.</p>
      </div>
    </div>

    <p class="sg-notes" style="margin-top:1rem;">
      Theme defined in <code>app/Views/partials/backend_head.php</code> inside the inline
      <code>tailwind.config</code>. Tokens: <code>primary #F2C94C</code>,
      <code>secondary #2F7D32</code>, <code>background-light #F7F7F4</code>.
    </p>
  </section>

  <!-- VOICE ─────────────────────────────────────────────────────────── -->
  <section class="sg-section">
    <p class="sg-section__eyebrow">Section 13</p>
    <h2 class="sg-section__title">Voice &amp; tone</h2>

    <div class="sg-twocol">
      <div class="sg-preview">
        <p class="sg-type__label" style="color:var(--green);">Do</p>
        <ul class="sg-notes" style="margin:0;">
          <li>Plain English. "We'll email you when it's done."</li>
          <li>Active voice. "You can update your address here."</li>
          <li>Member-first. "You / your", not "the user".</li>
          <li>Short headings — two or three words.</li>
          <li>Empty states with personality.</li>
        </ul>
      </div>
      <div class="sg-preview">
        <p class="sg-type__label" style="color:#9a2b1e;">Don't</p>
        <ul class="sg-notes" style="margin:0;">
          <li>Corporate filler — "please", "kindly", "we are pleased to inform you".</li>
          <li>"Premium", "exclusive", "disruptive" — wrong vibe.</li>
          <li>Passive voice — "an email will be sent".</li>
          <li>Emojis. Use icons instead.</li>
          <li>Blue. There is no blue in this brand.</li>
        </ul>
      </div>
    </div>
  </section>

  <!-- WHERE TO CHANGE ──────────────────────────────────────────────── -->
  <section class="sg-section">
    <p class="sg-section__eyebrow">Section 14</p>
    <h2 class="sg-section__title">Where to change it</h2>

    <div class="sg-preview">
      <ul class="sg-notes" style="margin:0;">
        <li>Public-site CSS — <code>public_html/assets/styles.css</code></li>
        <li>Colour / shadow tokens — <code>public_html/assets/styles.css</code> lines 1–14</li>
        <li>Admin Tailwind theme — <code>app/Views/partials/backend_head.php</code></li>
        <li>Backend layout shell — <code>app/Views/partials/backend_head.php</code> + <code>backend_footer.php</code> + <code>backend_admin_sidebar.php</code></li>
        <li>Hero image upload path — <code>public_html/uploads/about/</code></li>
        <li>Full written system — <code>design.md</code> at the repo root</li>
      </ul>
    </div>

    <p class="sg-notes" style="margin-top:1rem;">
      When you change a token in <code>styles.css</code>, reload this page — every swatch and
      component above will reflect the new value. That's the smoke test.
    </p>
  </section>

</div>

</body>
</html>
