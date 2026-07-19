<?php
/**
 * Compiles the entire Admin System Documentation into ONE print-ready,
 * Goldwing-branded HTML document (cover, table of contents, part dividers,
 * every chapter, colophon). Print it to PDF and you have the committee manual.
 *
 * Callers:
 *   - manual.php                 in-admin page with a "Save as PDF" button
 *   - scripts/build_manual.php   CLI build (headless Chrome prints the PDF)
 *
 * Caller must include markdown.php first and should define GW_DOCS_PRINT
 * (and optionally GW_DOCS_IMG_BASE) before including it — see markdown.php.
 */

function gw_build_manual_html(array $opts = []): string
{
    $docsDir = __DIR__;
    $toc = json_decode((string) file_get_contents($docsDir . '/_toc.json'), true);
    if (!is_array($toc)) {
        return '<!doctype html><meta charset="utf-8"><p>Documentation TOC missing or invalid.</p>';
    }

    $logo = (string) ($opts['logo'] ?? '/uploads/library/2024/good-logo-cropped-white-notag.png');
    $generated = (string) ($opts['generated'] ?? date('j F Y'));
    $printButton = !empty($opts['print_button']);
    $esc = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $partCount = 0;
    $chapterCount = 0;
    $tocHtml = '';
    $bodyHtml = '';
    $partNo = 0;

    foreach (($toc['parts'] ?? []) as $part) {
        $partNo++;
        $partCount++;
        $partTitle = (string) ($part['title'] ?? '');
        $partId = 'part-' . $partNo;

        $tocHtml .= '<div class="toc-part"><a href="#' . $partId . '">' . $esc($partTitle) . '</a></div><ul class="toc-list">';
        $chaptersHtml = '';
        foreach (($part['chapters'] ?? []) as $ch) {
            $slug = (string) ($ch['slug'] ?? '');
            $title = (string) ($ch['title'] ?? $slug);
            $file = $docsDir . '/' . (string) ($ch['file'] ?? '');
            $tocHtml .= '<li><a href="#ch-' . $esc($slug) . '">' . $esc($title) . '</a></li>';
            if (!is_file($file)) {
                continue;
            }
            $chapterCount++;
            $rendered = gw_render_markdown((string) file_get_contents($file));
            // The printed manual can't toggle <details>; force dev-notes open
            // so the technical half of every chapter is on the page.
            $rendered = preg_replace('/<details(?![^>]*\bopen\b)/', '<details open', $rendered);
            $chaptersHtml .= '<section class="chapter" id="ch-' . $esc($slug) . '">'
                . '<div class="chapter-eyebrow">' . $esc($partTitle) . ' &middot; ' . $esc($slug) . '</div>'
                . $rendered
                . '</section>';
        }
        $tocHtml .= '</ul>';

        $bodyHtml .= '<section class="part-divider" id="' . $partId . '">'
            . '<div class="part-eyebrow">Goldwing System Documentation</div>'
            . '<h1>' . $esc($partTitle) . '</h1>'
            . '<div class="part-rule"></div>'
            . (!empty($part['blurb']) ? '<p class="part-blurb">' . $esc((string) $part['blurb']) . '</p>' : '')
            . '</section>'
            . $chaptersHtml;
    }

    $title = (string) ($toc['title'] ?? 'Goldwing System Documentation');
    $intro = (string) ($toc['intro'] ?? '');

    $coverLogo = $logo !== '' ? '<img class="cover-logo" src="' . $esc($logo) . '" alt="Australian Goldwing Association">' : '';
    $printBtnHtml = $printButton
        ? '<button class="print-btn" onclick="window.print()">&#128424;&nbsp; Save as PDF</button>'
        : '';

    return '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . $esc($title) . ' — Committee Manual</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Open+Sans:ital,wght@0,400;0,600;0,700;1,400&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --black: #0b0b0b; --coal: #111111; --cream: #f4f1e8; --paper: #ffffff;
    --sand: #e8e3d7; --gold: #9e9140; --gold-light: #cbbd6c;
    --green: #4a9114; --green-dark: #2f6a0f; --ink: #1c1a17; --muted: #5a5a55;
  }
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  html, body { margin: 0; padding: 0; }
  body { font-family: "Open Sans", Arial, sans-serif; font-size: 10.5pt; line-height: 1.65; color: var(--ink); background: var(--cream); }
  .sheet { max-width: 820px; margin: 0 auto; background: var(--paper); }
  @media screen { .sheet { box-shadow: 0 0 30px rgba(0,0,0,.12); } .page { padding: 40px 48px; } }
  @media print {
    body { background: var(--paper); }
    .sheet { max-width: none; box-shadow: none; }
    .page { padding: 0; }
    @page { size: A4; margin: 16mm 15mm 18mm; }
  }

  /* ---- Cover ---- */
  .cover { background: var(--black); color: var(--cream); text-align: center; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 60px 40px; page-break-after: always; }
  @media screen { .cover { min-height: 92vh; } }
  @media print { .cover { height: 258mm; margin: 0; } }
  .cover-logo { max-width: 300px; width: 60%; height: auto; margin-bottom: 34px; }
  .cover-eyebrow { font-family: Rajdhani, sans-serif; font-weight: 600; letter-spacing: .35em; text-transform: uppercase; color: var(--gold-light); font-size: 11pt; margin-bottom: 10px; }
  .cover h1 { font-family: "Bebas Neue", sans-serif; font-weight: 400; font-size: 46pt; line-height: 1.02; letter-spacing: .02em; margin: 0 0 14px; color: #fff; }
  .cover-sub { font-size: 12.5pt; color: var(--cream); opacity: .85; max-width: 430px; margin: 0 auto 40px; }
  .cover-rule { width: 70px; height: 3px; background: var(--gold); margin: 0 auto 40px; }
  .cover-meta { font-family: Rajdhani, sans-serif; font-weight: 600; letter-spacing: .18em; text-transform: uppercase; font-size: 9pt; color: var(--gold-light); }
  .cover-meta span { display: block; margin-top: 4px; color: var(--cream); opacity: .7; }

  /* ---- Front matter & TOC ---- */
  .front { page-break-after: always; }
  .front h2, .toc h2 { font-family: "Bebas Neue", sans-serif; font-weight: 400; font-size: 24pt; letter-spacing: .02em; margin: 0 0 6px; border-bottom: 3px solid var(--gold); padding-bottom: 6px; }
  .callout { background: var(--cream); border-left: 4px solid var(--gold); padding: 12px 16px; margin: 16px 0; border-radius: 0 6px 6px 0; }
  .toc { page-break-after: always; }
  .toc-cols { column-count: 2; column-gap: 34px; margin-top: 14px; }
  .toc-part { font-family: Rajdhani, sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; font-size: 10pt; color: var(--green-dark); margin: 14px 0 4px; break-inside: avoid; }
  .toc-part a { color: inherit; text-decoration: none; }
  .toc-list { list-style: none; margin: 0 0 6px; padding: 0; font-size: 9.5pt; }
  .toc-list li { padding: 1.5px 0; break-inside: avoid; }
  .toc-list a { color: var(--ink); text-decoration: none; }

  /* ---- Part dividers ---- */
  .part-divider { page-break-before: always; padding: 150px 0 60px; text-align: left; }
  @media print { .part-divider { padding-top: 70mm; } }
  .part-eyebrow { font-family: Rajdhani, sans-serif; font-weight: 600; letter-spacing: .3em; text-transform: uppercase; font-size: 9pt; color: var(--gold); margin-bottom: 12px; }
  .part-divider h1 { font-family: "Bebas Neue", sans-serif; font-weight: 400; font-size: 40pt; line-height: 1.05; margin: 0 0 18px; color: var(--ink); }
  .part-rule { width: 90px; height: 4px; background: var(--gold); margin-bottom: 18px; }
  .part-blurb { font-size: 12pt; color: var(--muted); max-width: 480px; }

  /* ---- Chapters ---- */
  .chapter { page-break-before: always; }
  .chapter-eyebrow { font-family: Rajdhani, sans-serif; font-weight: 600; letter-spacing: .22em; text-transform: uppercase; font-size: 8pt; color: var(--gold); margin-bottom: 6px; }
  .chapter h1 { font-family: "Bebas Neue", sans-serif; font-weight: 400; font-size: 26pt; letter-spacing: .02em; line-height: 1.08; margin: 0 0 14px; border-bottom: 3px solid var(--gold); padding-bottom: 8px; }
  .chapter h2 { font-family: "Bebas Neue", sans-serif; font-weight: 400; font-size: 17pt; letter-spacing: .03em; margin: 22px 0 8px; color: var(--ink); page-break-after: avoid; }
  .chapter h3 { font-size: 11.5pt; font-weight: 700; margin: 16px 0 6px; page-break-after: avoid; }
  .chapter h4 { font-size: 10.5pt; font-weight: 700; color: var(--muted); margin: 12px 0 4px; page-break-after: avoid; }
  p { margin: 0 0 9px; }
  ul, ol { margin: 0 0 10px; padding-left: 22px; }
  li { margin: 2px 0; }
  a { color: var(--green-dark); }
  .xref { color: var(--green-dark); text-decoration: none; border-bottom: 1px dotted var(--gold); }
  hr { border: 0; border-top: 1px solid var(--sand); margin: 18px 0; }
  blockquote { border-left: 4px solid var(--gold); background: var(--cream); margin: 12px 0; padding: 8px 14px; border-radius: 0 6px 6px 0; }

  table { width: 100%; border-collapse: collapse; margin: 10px 0 14px; font-size: 9pt; }
  th { font-family: Rajdhani, sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; font-size: 8pt; text-align: left; color: var(--muted); background: var(--cream); border: 1px solid var(--sand); padding: 5px 8px; }
  td { border: 1px solid var(--sand); padding: 5px 8px; vertical-align: top; }
  tr { page-break-inside: avoid; }

  pre { background: #f6f4ec; border: 1px solid var(--sand); border-radius: 6px; padding: 10px 12px; font-size: 8pt; line-height: 1.5; white-space: pre-wrap; word-break: break-word; page-break-inside: avoid; }
  pre, code { font-family: "SF Mono", Menlo, Consolas, monospace; }
  code { background: var(--cream); border-radius: 3px; padding: 1px 4px; font-size: .9em; overflow-wrap: anywhere; }
  pre code { background: none; padding: 0; }

  img { max-width: 100%; height: auto; border: 1px solid var(--sand); border-radius: 6px; margin: 10px 0; page-break-inside: avoid; }

  details { border: 1px solid var(--sand); border-radius: 8px; margin: 16px 0; padding: 0; page-break-inside: auto; }
  summary { font-family: Rajdhani, sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: .12em; font-size: 9pt; color: var(--green-dark); background: var(--cream); padding: 7px 14px; border-radius: 8px 8px 0 0; list-style: none; }
  summary::-webkit-details-marker { display: none; }
  details > *:not(summary) { margin-left: 14px; margin-right: 14px; }
  details > *:last-child { margin-bottom: 12px; }
  details > p:first-of-type { margin-top: 10px; }

  .tour-ref, .link-ref { display: inline-block; font-family: Rajdhani, sans-serif; font-weight: 600; font-size: 9pt; letter-spacing: .04em; background: var(--cream); border: 1px solid var(--sand); border-left: 3px solid var(--gold); border-radius: 4px; padding: 3px 10px; margin: 2px 0; color: var(--ink); }
  .link-url { color: var(--muted); font-weight: 500; }

  .colophon { page-break-before: always; text-align: center; padding-top: 140px; color: var(--muted); font-size: 9.5pt; }
  .colophon .mark { font-family: "Bebas Neue", sans-serif; font-size: 16pt; color: var(--ink); letter-spacing: .06em; margin-bottom: 8px; }

  .print-btn { position: fixed; top: 18px; right: 18px; z-index: 50; font-family: Rajdhani, sans-serif; font-weight: 700; letter-spacing: .06em; font-size: 14px; background: var(--green); color: #fff; border: 0; border-radius: 999px; padding: 12px 22px; cursor: pointer; box-shadow: 0 4px 14px rgba(0,0,0,.25); }
  .print-btn:hover { background: var(--green-dark); }
  @media print { .print-btn { display: none; } }
</style>
</head>
<body>
<div class="sheet">
' . $printBtnHtml . '
  <section class="cover">
    ' . $coverLogo . '
    <div class="cover-eyebrow">Australian Goldwing Association</div>
    <h1>Website Manual</h1>
    <p class="cover-sub">Committee guide &amp; technical reference for goldwing.org.au — how the site works, how to run it, and how it was built.</p>
    <div class="cover-rule"></div>
    <div class="cover-meta">' . $esc($generated) . '<span>' . (int) $partCount . ' parts &middot; ' . (int) $chapterCount . ' chapters &middot; goldwing.org.au</span></div>
  </section>

  <div class="page">
    <section class="front">
      <h2>About this manual</h2>
      <p>' . $esc($intro) . '</p>
      <p>Every chapter has two halves: a plain-English <strong>“For administrators”</strong> section for committee members
         running the site day to day, followed by a boxed <strong>“Dev notes”</strong> section with the technical detail a
         developer or future agency needs. Skip the boxes freely — they are not required reading for admins.</p>
      <div class="callout"><strong>This PDF is a snapshot.</strong> The always-current version of every chapter lives in the
         admin area at <a class="xref" href="https://goldwing.org.au/admin/help/docs/">goldwing.org.au/admin/help/docs</a>, where the
         “Walk me through” buttons launch interactive on-screen walkthroughs. To regenerate this PDF at any time, open
         <em>Admin &rarr; Help &amp; Docs &rarr; System Documentation &rarr; Print manual</em> and choose “Save as PDF”.</div>
      <p>Generated ' . $esc($generated) . '.</p>
    </section>

    <section class="toc">
      <h2>Contents</h2>
      <div class="toc-cols">' . $tocHtml . '</div>
    </section>

    ' . $bodyHtml . '

    <section class="colophon">
      <div class="mark">Australian Goldwing Association</div>
      <p>Compiled from the live admin documentation system &middot; ' . $esc($generated) . '<br>
      goldwing.org.au &middot; “Wings of Friendship”</p>
    </section>
  </div>
</div>
</body>
</html>';
}
