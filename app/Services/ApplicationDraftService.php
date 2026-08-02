<?php

namespace App\Services;

use PDO;

/**
 * The applicant's full /apply.php form, captured server-side BEFORE their card
 * is charged.
 *
 * Why this exists: the card path charges the finalized Stripe Invoice and only
 * writes the member/application/order afterwards, in a browser AJAX POST. If
 * that POST never lands (issuer/3DS full-page redirect, closed tab, dropped
 * connection) the money is captured and the applicant is nowhere — which is
 * exactly how two paying new members vanished in July 2026.
 *
 * The draft closes that window: apply.php posts the whole form here in the same
 * click that confirms the payment, so from the moment the card is charged the
 * server already holds everything needed to rebuild the application —
 * addresses, bikes, associate details and all, not just the truncated slice
 * that fits in Stripe invoice metadata.
 *
 * Drafts are throwaway: cleared as soon as the normal POST lands, and swept
 * after 30 days. Nothing reads them except MembershipApplicationRecoveryService.
 */
class ApplicationDraftService
{
    /** Form fields never worth storing (secrets, transport noise). */
    private const SKIP_FIELDS = ['csrf_token', 'ajax', 'stage', 'payment_intent_id', 'stripe_invoice_id'];

    private static bool $tableChecked = false;

    /**
     * Store (or replace) the draft for a Stripe invoice.
     *
     * @param array<string,mixed> $form Raw $_POST from the apply form.
     */
    public static function save(string $invoiceId, string $paymentIntentId, string $email, array $form): bool
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') {
            return false;
        }
        $payload = [];
        foreach ($form as $key => $value) {
            if (in_array($key, self::SKIP_FIELDS, true) || !is_string($value)) {
                continue;
            }
            $payload[$key] = $value;
        }
        if (!$payload) {
            return false;
        }

        try {
            $pdo = Database::connection();
            self::ensureTable($pdo);
            $stmt = $pdo->prepare('INSERT INTO application_drafts (stripe_invoice_id, stripe_payment_intent_id, email, payload_json, created_at)
                                   VALUES (:invoice, :intent, :email, :payload, NOW())
                                   ON DUPLICATE KEY UPDATE stripe_payment_intent_id = VALUES(stripe_payment_intent_id),
                                                           email = VALUES(email),
                                                           payload_json = VALUES(payload_json),
                                                           updated_at = NOW()');
            $stmt->execute([
                'invoice' => $invoiceId,
                'intent' => trim($paymentIntentId) !== '' ? trim($paymentIntentId) : null,
                'email' => strtolower(trim($email)),
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('[ApplicationDraftService] save failed for ' . $invoiceId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * The stored form for an invoice, or null. Falls back to the applicant email
     * so a draft still matches when the invoice was re-created mid-flow (each
     * change of membership selection finalizes a fresh invoice).
     *
     * @return array<string,string>|null
     */
    public static function find(string $invoiceId, string $email = ''): ?array
    {
        try {
            $pdo = Database::connection();
            self::ensureTable($pdo);
            $row = null;
            if (trim($invoiceId) !== '') {
                $stmt = $pdo->prepare('SELECT payload_json FROM application_drafts WHERE stripe_invoice_id = :invoice LIMIT 1');
                $stmt->execute(['invoice' => trim($invoiceId)]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$row && trim($email) !== '') {
                $stmt = $pdo->prepare('SELECT payload_json FROM application_drafts WHERE email = :email ORDER BY id DESC LIMIT 1');
                $stmt->execute(['email' => strtolower(trim($email))]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$row) {
                return null;
            }
            $payload = json_decode((string) $row['payload_json'], true);
            return is_array($payload) ? $payload : null;
        } catch (\Throwable $e) {
            error_log('[ApplicationDraftService] lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Drop every draft for an applicant — called once the application is safely
     * in the DB, whether by the normal POST or by recovery.
     */
    public static function clearForEmail(string $email): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }
        try {
            $pdo = Database::connection();
            self::ensureTable($pdo);
            $stmt = $pdo->prepare('DELETE FROM application_drafts WHERE email = :email');
            $stmt->execute(['email' => $email]);
        } catch (\Throwable $e) {
            error_log('[ApplicationDraftService] clear failed for ' . $email . ': ' . $e->getMessage());
        }
    }

    /**
     * Created lazily rather than through run-migration.php: this table has to
     * exist the first time an applicant reaches the payment step, and a draft
     * that silently isn't stored would put us straight back into the July 2026
     * failure. Drafts older than 30 days are dropped on the way past — they are
     * long since either recovered or abandoned.
     */
    private static function ensureTable(PDO $pdo): void
    {
        if (self::$tableChecked) {
            return;
        }
        self::$tableChecked = true;
        $pdo->exec('CREATE TABLE IF NOT EXISTS application_drafts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            stripe_invoice_id VARCHAR(255) NOT NULL,
            stripe_payment_intent_id VARCHAR(255) NULL,
            email VARCHAR(255) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_application_drafts_invoice (stripe_invoice_id),
            KEY idx_application_drafts_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('DELETE FROM application_drafts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    }
}
