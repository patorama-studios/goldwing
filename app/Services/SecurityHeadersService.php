<?php
namespace App\Services;

class SecurityHeadersService
{
    public static function apply(): void
    {
        if (headers_sent()) {
            return;
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        $allowFraming = in_array($path, ['/calendar/events_public.php'], true);
        $frameAncestors = $allowFraming ? "frame-ancestors 'self'" : "frame-ancestors 'none'";
        $styleSrc = $allowFraming
            ? "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net"
            : "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com";
        $googleScriptSources = 'https://maps.googleapis.com https://maps.gstatic.com';
        $stripeScriptSources = 'https://js.stripe.com https://m.stripe.network';
        $scriptSrc = $allowFraming
            ? "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net {$googleScriptSources} {$stripeScriptSources}"
            : "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com {$googleScriptSources} {$stripeScriptSources}";
        $csp = "default-src 'self'; img-src 'self' data: https:; {$styleSrc}; {$scriptSrc}; font-src 'self' data: https://fonts.gstatic.com; connect-src 'self' https://api.stripe.com https://m.stripe.network; {$frameAncestors}";
        header('Content-Security-Policy: ' . $csp);
        header('X-Frame-Options: ' . ($allowFraming ? 'SAMEORIGIN' : 'DENY'));
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
        }
    }
}
