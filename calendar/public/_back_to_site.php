<?php
// Shared "Back to Goldwing" bar for public-facing calendar pages.
// Auto-hides when the calendar is embedded in an iframe (e.g. the home page
// already provides site navigation around the iframe). Include this file
// inside <body>, before page content.
?>
<div id="agaBackBar" class="aga-back-bar" role="navigation" aria-label="Site navigation">
    <a href="/" class="aga-back-bar__link" rel="noopener">
        <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2.4"
                stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span>Back to Goldwing</span>
    </a>
    <span class="aga-back-bar__divider" aria-hidden="true">·</span>
    <span class="aga-back-bar__title">Ride Calendar</span>
</div>
<style>
    .aga-back-bar {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 18px;
        background: #111;
        color: #fff;
        font-family: 'Manrope', system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
        font-size: 13px;
        font-weight: 600;
        letter-spacing: 0.02em;
        border-bottom: 1px solid #1f2937;
    }
    .aga-back-bar__link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: inherit;
        text-decoration: none;
        padding: 4px 10px;
        border-radius: 999px;
        transition: background 0.15s ease;
    }
    .aga-back-bar__link:hover,
    .aga-back-bar__link:focus-visible {
        background: rgba(255, 255, 255, 0.08);
        outline: none;
    }
    .aga-back-bar__divider {
        opacity: 0.4;
    }
    .aga-back-bar__title {
        opacity: 0.75;
        font-weight: 500;
    }
    /* Hide when embedded in an iframe (e.g. on the public home page). */
    html.aga-in-iframe .aga-back-bar { display: none; }
</style>
<script>
    (function () {
        try {
            if (window.self !== window.top) {
                document.documentElement.classList.add('aga-in-iframe');
            }
        } catch (e) {
            // Cross-origin frame access throws — treat as embedded.
            document.documentElement.classList.add('aga-in-iframe');
        }
    })();
</script>
