<?php
/**
 * Access-gate smoke test for the member-facing payment endpoints.
 *
 * Background: the '/api/*' pages_registry row is admin-only. Any member-facing
 * /api endpoint that isn't whitelisted in access_control_is_always_allowed()
 * (or given its own registry row) gets 403 {"error":"Forbidden"} / 401
 * {"error":"Unauthorized"} for every non-admin — the July 2026 renewal outage.
 * Admins pass the gate, so the bug is invisible from an admin session; the only
 * honest test is an ANONYMOUS request.
 *
 * This tool loops back to the site over HTTP with NO session cookie (a
 * logged-out stranger) and asserts each payment endpoint reaches its own
 * handler instead of being turned away by the gate.
 *
 * Discriminator: the gate is the ONLY thing that emits these exact bodies:
 *     {"error":"Unauthorized"}   {"error":"Forbidden"}
 * Every handler's own rejection ends in a period ("Invalid CSRF token.",
 * "Unauthorized.", "Members only."), returns a signature error, or returns
 * real content. So: body == a gate body  ->  GATED (fail); anything else
 * -> handler reached (pass). Controls prove both directions.
 *
 * Read-only: anonymous POSTs are rejected at the CSRF / signature / login
 * check before any write, so nothing is created.
 *
 * Usage: /admin/diagnose-payment-gate.php
 */
if (function_exists('opcache_reset')) { @opcache_reset(); }

require_once __DIR__ . '/../../app/bootstrap.php';

require_permission('admin.settings.general.manage');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'www.goldwing.org.au';
$base   = $scheme . '://' . $host;

// Exact bodies only the access gate produces (no trailing period).
$GATE_BODIES = ['{"error":"Unauthorized"}', '{"error":"Forbidden"}'];

// method, path, and whether the gate SHOULD block it (controls).
$checks = [
    ['Membership renewal',        'POST', '/api/payments/membership-intent',   false],
    ['Inline payment intent',     'POST', '/api/payments/intent',              false],
    ['Store checkout intent',     'POST', '/api/stripe/create-payment-intent', false],
    ['Store config (public)',     'GET',  '/api/stripe/config',                false],
    ['Main Stripe webhook',       'POST', '/api/stripe_webhook.php',           false],
    ['AGM Stripe webhook',        'POST', '/api/stripe_webhook_agm.php',       false],
    ['Member card setup',         'POST', '/api/billing/setup-intent',         false],
    ['Member billing portal',     'GET',  '/api/billing/portal',               false],
    // Negative control: MUST stay gated. Proves the gate is alive and that this
    // probe can actually detect gating (else every "pass" above is meaningless).
    ['CONTROL: admin settings',   'GET',  '/api/admin/settings/stripe',        true],
];

echo "=== Payment Gate Smoke Test ===\n";
echo "Source mtime: " . @date('c', filemtime(__FILE__)) . "\n";
echo "Time:   " . date('c') . "\n";
echo "Target: {$base}  (anonymous / no session cookie)\n\n";

$probe = static function (string $method, string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'User-Agent: goldwing-gate-smoke-test'],
        // no cookies -> anonymous
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
    }
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    return [$status, $body === false ? '' : (string) $body, $err];
};

$fails = 0;
foreach ($checks as [$label, $method, $path, $expectGated]) {
    [$status, $body, $err] = $probe($method, $base . $path);
    $snippet = trim(substr($body, 0, 120));
    $isGated = in_array(trim($body), $GATE_BODIES, true);

    if ($err !== '') {
        $verdict = 'ERROR  ';
        $fails++;
    } elseif ($isGated === $expectGated) {
        $verdict = 'PASS   ';
    } else {
        $verdict = 'FAIL   ';
        $fails++;
    }

    $note = $expectGated
        ? ($isGated ? '(correctly gated)' : 'gate is NOT blocking an admin-only endpoint!')
        : ($isGated ? 'GATE BLOCKED a member endpoint (the Forbidden bug is back)' : '(handler reached)');

    printf("%s %-24s %-4s %-34s [%d] %s\n", $verdict, $label, $method, $path, $status, $note);
    if ($verdict !== 'PASS   ') {
        echo "         body: " . ($err !== '' ? "curl error: {$err}" : $snippet) . "\n";
    }
}

echo "\n" . ($fails === 0
    ? "ALL GREEN — the gate lets every payment endpoint through and still guards admin.\n"
    : "*** {$fails} PROBLEM(S) — a payment endpoint is gated (or the gate stopped guarding admin). Fix access_control_is_always_allowed(). ***\n");
