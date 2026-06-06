<?php
namespace App\Services;

class AgmWebhookService
{
    public static function handleEvent(array $event): void
    {
        $type = $event['type'] ?? '';
        switch ($type) {
            case 'checkout.session.completed':
                self::handleCheckoutCompleted($event);
                break;
            case 'payment_intent.succeeded':
                self::handlePaymentIntentSucceeded($event);
                break;
            case 'payment_intent.payment_failed':
            case 'payment_intent.canceled':
                self::handlePaymentFailed($event);
                break;
            case 'charge.refunded':
                self::handleChargeRefunded($event);
                break;
            default:
                // Other events are recorded but require no AGM-side action.
                break;
        }
    }

    private static function handleCheckoutCompleted(array $event): void
    {
        $session = $event['data']['object'] ?? [];
        $metadata = $session['metadata'] ?? [];
        $registrationId = isset($metadata['agm_registration_id']) ? (int) $metadata['agm_registration_id'] : 0;
        $sessionId = (string) ($session['id'] ?? '');
        $paymentIntentId = (string) ($session['payment_intent'] ?? '');

        $registration = $registrationId > 0
            ? AgmRegistrationService::getRegistrationById($registrationId)
            : ($sessionId !== '' ? AgmRegistrationService::getRegistrationByStripeSession($sessionId) : null);

        if (!$registration) {
            return;
        }

        AgmRegistrationService::markPaid((int) $registration['id'], $paymentIntentId ?: null, $sessionId ?: null);
    }

    private static function handlePaymentIntentSucceeded(array $event): void
    {
        $intent = $event['data']['object'] ?? [];
        $paymentIntentId = (string) ($intent['id'] ?? '');
        if ($paymentIntentId === '') {
            return;
        }
        $metadata = $intent['metadata'] ?? [];
        $registrationId = isset($metadata['agm_registration_id']) ? (int) $metadata['agm_registration_id'] : 0;
        $registration = $registrationId > 0
            ? AgmRegistrationService::getRegistrationById($registrationId)
            : AgmRegistrationService::getRegistrationByPaymentIntent($paymentIntentId);
        if (!$registration) {
            return;
        }
        AgmRegistrationService::markPaid((int) $registration['id'], $paymentIntentId, null);
    }

    private static function handlePaymentFailed(array $event): void
    {
        $intent = $event['data']['object'] ?? [];
        $paymentIntentId = (string) ($intent['id'] ?? '');
        if ($paymentIntentId === '') {
            return;
        }
        $registration = AgmRegistrationService::getRegistrationByPaymentIntent($paymentIntentId);
        if (!$registration) {
            return;
        }
        if (($registration['payment_status'] ?? '') === 'paid') {
            return;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE agm_registrations SET payment_status = "cancelled", cancelled_at = NOW(), updated_at = NOW() WHERE id = :id AND payment_status NOT IN ("paid","refunded")');
        $stmt->execute(['id' => (int) $registration['id']]);
        ActivityLogger::log('system', null, null, 'agm.registration_payment_failed', [
            'registration_id' => (int) $registration['id'],
            'payment_intent' => $paymentIntentId,
        ]);
    }

    private static function handleChargeRefunded(array $event): void
    {
        $charge = $event['data']['object'] ?? [];
        $paymentIntentId = (string) ($charge['payment_intent'] ?? '');
        if ($paymentIntentId === '') {
            return;
        }
        $registration = AgmRegistrationService::getRegistrationByPaymentIntent($paymentIntentId);
        if (!$registration) {
            return;
        }
        $refundId = null;
        $refunds = $charge['refunds']['data'] ?? [];
        if ($refunds) {
            $refundId = $refunds[0]['id'] ?? null;
        }
        AgmRegistrationService::markRefunded((int) $registration['id'], $refundId);
    }
}
