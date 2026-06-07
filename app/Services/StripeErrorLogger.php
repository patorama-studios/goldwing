<?php
namespace App\Services;

/**
 * Best-effort logger for Stripe SDK failures and silent webhook bail-outs.
 *
 * Writes to the `stripe_errors` table (see migrations/2026_06_07_stripe_errors.sql).
 *
 * Hard rules:
 *   - Never throw. If logging itself fails, swallow the error and return 0.
 *   - Never import the Stripe SDK. We accept a generic \Throwable so this
 *     class has no compile-time dependency on Stripe.
 *   - Redact anything that looks like a secret key from context_json before
 *     it lands in the database.
 *
 * Usage from StripeService:
 *   } catch (ApiErrorException $e) {
 *       StripeErrorLogger::log(__METHOD__, 'checkout_session.create', $e, [
 *           'account_key'    => $accountKey,
 *           'customer_email' => $customerEmail,
 *           'metadata'       => $metadata,
 *       ]);
 *       return null;
 *   }
 *
 * Usage from PaymentWebhookService:
 *   if ($orderId <= 0) {
 *       StripeErrorLogger::logWebhookSkip(__METHOD__, 'metadata.order_id missing', [
 *           'event_id' => $event['id'] ?? null,
 *           'metadata' => $metadata,
 *       ]);
 *       return null;
 *   }
 */
class StripeErrorLogger
{
    /**
     * Log a Stripe SDK exception (or any throwable raised in a Stripe code path).
     *
     * @param string          $source     Typically __METHOD__.
     * @param string          $operation  Short op tag, e.g. 'checkout_session.create'.
     * @param \Throwable|null $exception  The caught exception, if any.
     * @param array           $context    Free-form context. Recognised keys:
     *                                    account_key, error_code, related_order_id,
     *                                    related_store_order_id,
     *                                    related_stripe_session_id,
     *                                    related_stripe_pi_id, webhook_event_id.
     *
     * @return int Inserted row id, or 0 on any failure.
     */
    public static function log(string $source, string $operation, ?\Throwable $exception, array $context = []): int
    {
        try {
            $errorMessage = $exception !== null ? $exception->getMessage() : null;
            $errorCode = self::extractErrorCode($exception, $context);
            return self::insert($source, $operation, $errorCode, $errorMessage, $context);
        } catch (\Throwable $e) {
            // Logging must never break the request. Swallow.
            return 0;
        }
    }

    /**
     * Log a silent-return branch inside a webhook handler.
     *
     * @param string $source  Typically __METHOD__.
     * @param string $reason  Plain-English reason, e.g. 'metadata.order_id missing'.
     * @param array  $context Free-form context. Recognised keys same as log().
     *
     * @return int Inserted row id, or 0 on any failure.
     */
    public static function logWebhookSkip(string $source, string $reason, array $context = []): int
    {
        try {
            return self::insert($source, 'webhook.silent_skip', null, $reason, $context);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function insert(string $source, string $operation, ?string $errorCode, ?string $errorMessage, array $context): int
    {
        // Pull the convenience indexed fields out of context, then strip them
        // so they don't get duplicated inside context_json.
        $accountKey = self::popString($context, 'account_key') ?? self::popString($context, 'stripe_account');
        $relatedOrderId = self::popInt($context, 'related_order_id');
        $relatedStoreOrderId = self::popInt($context, 'related_store_order_id');
        $relatedSessionId = self::popString($context, 'related_stripe_session_id');
        $relatedPiId = self::popString($context, 'related_stripe_pi_id');
        $webhookEventId = self::popInt($context, 'webhook_event_id');
        $errorCodeOverride = self::popString($context, 'error_code');
        if ($errorCodeOverride !== null) {
            $errorCode = $errorCodeOverride;
        }

        // If the caller didn't give us a local webhook_events.id but did give
        // us a Stripe event id, try to resolve it. This is best-effort.
        $stripeEventId = self::popString($context, 'event_id') ?? self::popString($context, 'stripe_event_id');
        if ($webhookEventId === null && $stripeEventId !== null && $stripeEventId !== '') {
            $webhookEventId = self::resolveWebhookEventId($stripeEventId);
            // Put the stripe event id back so it shows up in context_json too.
            $context['stripe_event_id'] = $stripeEventId;
        } elseif ($stripeEventId !== null) {
            $context['stripe_event_id'] = $stripeEventId;
        }

        $redactedContext = self::redact($context);
        $contextJson = json_encode($redactedContext, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($contextJson === false) {
            $contextJson = null;
        }

        try {
            $pdo = Database::connection();
        } catch (\Throwable $e) {
            return 0;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO stripe_errors '
            . '(occurred_at, source, operation, stripe_account, error_code, error_message, context_json, '
            . ' related_order_id, related_store_order_id, related_stripe_session_id, related_stripe_pi_id, webhook_event_id) '
            . 'VALUES (NOW(), :source, :operation, :stripe_account, :error_code, :error_message, :context_json, '
            . ' :related_order_id, :related_store_order_id, :related_stripe_session_id, :related_stripe_pi_id, :webhook_event_id)'
        );

        $stmt->execute([
            'source' => self::truncate($source, 80),
            'operation' => self::truncate($operation, 80),
            'stripe_account' => self::truncate($accountKey, 40),
            'error_code' => self::truncate($errorCode, 80),
            'error_message' => $errorMessage,
            'context_json' => $contextJson,
            'related_order_id' => $relatedOrderId,
            'related_store_order_id' => $relatedStoreOrderId,
            'related_stripe_session_id' => self::truncate($relatedSessionId, 120),
            'related_stripe_pi_id' => self::truncate($relatedPiId, 120),
            'webhook_event_id' => $webhookEventId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Pull a Stripe error code out of either the exception (if it's a Stripe
     * ApiErrorException, which exposes getStripeCode()) or the context array.
     *
     * We avoid importing the Stripe SDK, so we use method_exists() to detect it.
     */
    private static function extractErrorCode(?\Throwable $exception, array $context): ?string
    {
        if ($exception !== null && method_exists($exception, 'getStripeCode')) {
            $code = $exception->getStripeCode();
            if (is_string($code) && $code !== '') {
                return $code;
            }
        }
        if ($exception !== null && method_exists($exception, 'getError')) {
            try {
                $err = $exception->getError();
                if (is_object($err) && isset($err->code) && is_string($err->code) && $err->code !== '') {
                    return $err->code;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return null;
    }

    /**
     * Replace anything that looks like a Stripe secret key (live/test API key,
     * webhook signing secret, restricted key) with [REDACTED].
     *
     * Walks the array recursively. Applied to both keys' values and to any
     * string anywhere in the tree.
     */
    private static function redact($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::redact($v);
            }
            return $out;
        }
        if (is_string($value)) {
            return self::redactString($value);
        }
        return $value;
    }

    private static function redactString(string $s): string
    {
        // Match Stripe-style secrets: sk_live_..., sk_test_..., rk_live_...,
        // rk_test_..., whsec_..., as well as bare bearer-style tokens that
        // begin with these prefixes embedded in larger strings.
        return preg_replace(
            '/\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]+\b|\bwhsec_[A-Za-z0-9]+\b/',
            '[REDACTED]',
            $s
        ) ?? $s;
    }

    private static function popString(array &$context, string $key): ?string
    {
        if (!array_key_exists($key, $context)) {
            return null;
        }
        $v = $context[$key];
        unset($context[$key]);
        if ($v === null) {
            return null;
        }
        if (is_string($v)) {
            return $v !== '' ? $v : null;
        }
        if (is_scalar($v)) {
            $s = (string) $v;
            return $s !== '' ? $s : null;
        }
        return null;
    }

    private static function popInt(array &$context, string $key): ?int
    {
        if (!array_key_exists($key, $context)) {
            return null;
        }
        $v = $context[$key];
        unset($context[$key]);
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            $i = (int) $v;
            return $i > 0 ? $i : null;
        }
        return null;
    }

    private static function truncate(?string $s, int $max): ?string
    {
        if ($s === null) {
            return null;
        }
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max);
    }

    private static function resolveWebhookEventId(string $stripeEventId): ?int
    {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT id FROM webhook_events WHERE stripe_event_id = :id LIMIT 1');
            $stmt->execute(['id' => $stripeEventId]);
            $row = $stmt->fetch();
            if ($row && isset($row['id'])) {
                return (int) $row['id'];
            }
        } catch (\Throwable $e) {
            // best-effort
        }
        return null;
    }
}
