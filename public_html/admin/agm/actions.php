<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AgmEventService;
use App\Services\AgmRegistrationService;
use App\Services\Csrf;
use App\Services\StripeService;
use App\Services\StripeSettingsService;

require_permission('admin.agm.view');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/agm/');
    exit;
}

if (!Csrf::verify($_POST['_token'] ?? '')) {
    http_response_code(403);
    echo 'Invalid CSRF token.';
    exit;
}

$user = current_user();
$userId = (int) ($user['id'] ?? 0);
$action = $_POST['action'] ?? '';
$tab = $_POST['tab'] ?? 'dashboard';
$eventId = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;

function agm_back(string $tab, int $eventId, string $msg = '', string $err = ''): void
{
    $url = '/admin/agm/?tab=' . urlencode($tab);
    if ($eventId > 0) {
        $url .= '&event_id=' . $eventId;
    }
    if ($msg !== '') {
        $url .= '&msg=' . urlencode($msg);
    }
    if ($err !== '') {
        $url .= '&err=' . urlencode($err);
    }
    header('Location: ' . $url);
    exit;
}

try {
    switch ($action) {
        case 'create_event':
            require_permission('admin.agm.manage');
            $payload = [
                'year' => (int) ($_POST['year'] ?? date('Y') + 1),
                'slug' => trim((string) ($_POST['slug'] ?? '')),
                'title' => trim((string) ($_POST['title'] ?? '')),
                'subtitle' => $_POST['subtitle'] ?? null,
                'hosting_chapter' => $_POST['hosting_chapter'] ?? null,
                'venue_name' => $_POST['venue_name'] ?? null,
                'venue_address' => $_POST['venue_address'] ?? null,
                'venue_phone' => $_POST['venue_phone'] ?? null,
                'start_date' => $_POST['start_date'] ?? null,
                'end_date' => $_POST['end_date'] ?? null,
                'registration_opens_at' => $_POST['registration_opens_at'] ?? null,
                'registration_closes_at' => $_POST['registration_closes_at'] ?? null,
                'late_fee_starts_at' => $_POST['late_fee_starts_at'] ?? null,
                'contact_name' => $_POST['contact_name'] ?? null,
                'contact_phone' => $_POST['contact_phone'] ?? null,
                'contact_email' => $_POST['contact_email'] ?? null,
                'bank_transfer_instructions' => $_POST['bank_transfer_instructions'] ?? null,
                'allow_bank_transfer' => !empty($_POST['allow_bank_transfer']) ? 1 : 0,
                'allow_stripe' => !empty($_POST['allow_stripe']) ? 1 : 0,
                'status' => 'draft',
                'stripe_account_key' => 'agm',
            ];
            if ($payload['title'] === '' || $payload['slug'] === '') {
                agm_back('event', 0, '', 'Title and slug are required.');
            }
            $newEventId = AgmEventService::createEvent($userId, $payload);
            agm_back('event', $newEventId, 'event_created');

        case 'update_event':
            require_permission('admin.agm.manage');
            $payload = [
                'year' => (int) ($_POST['year'] ?? 0),
                'slug' => trim((string) ($_POST['slug'] ?? '')),
                'title' => trim((string) ($_POST['title'] ?? '')),
                'subtitle' => $_POST['subtitle'] ?? null,
                'hosting_chapter' => $_POST['hosting_chapter'] ?? null,
                'venue_name' => $_POST['venue_name'] ?? null,
                'venue_address' => $_POST['venue_address'] ?? null,
                'venue_phone' => $_POST['venue_phone'] ?? null,
                'start_date' => $_POST['start_date'] ?? null,
                'end_date' => $_POST['end_date'] ?? null,
                'registration_opens_at' => $_POST['registration_opens_at'] ?? null,
                'registration_closes_at' => $_POST['registration_closes_at'] ?? null,
                'late_fee_starts_at' => $_POST['late_fee_starts_at'] ?? null,
                'contact_name' => $_POST['contact_name'] ?? null,
                'contact_phone' => $_POST['contact_phone'] ?? null,
                'contact_email' => $_POST['contact_email'] ?? null,
                'bank_transfer_instructions' => $_POST['bank_transfer_instructions'] ?? null,
                'allow_bank_transfer' => !empty($_POST['allow_bank_transfer']) ? 1 : 0,
                'allow_stripe' => !empty($_POST['allow_stripe']) ? 1 : 0,
                'status' => $_POST['status'] ?? 'draft',
                'stripe_account_key' => 'agm',
                // Preserve existing description_html — that's saved via content tab.
                'description_html' => AgmEventService::getEventById($eventId)['description_html'] ?? null,
                'cover_image_path' => AgmEventService::getEventById($eventId)['cover_image_path'] ?? null,
            ];
            AgmEventService::updateEvent($userId, $eventId, $payload);
            agm_back('event', $eventId, 'event_updated');

        case 'publish_event':
            require_permission('admin.agm.manage');
            $existing = AgmEventService::getEventById($eventId);
            if (!$existing) {
                agm_back('event', 0, '', 'Event not found.');
            }
            $existing['status'] = 'published';
            AgmEventService::updateEvent($userId, $eventId, $existing);
            AgmEventService::setCurrentEvent($userId, $eventId);
            agm_back('event', $eventId, 'event_published');

        case 'archive_event':
            require_permission('admin.agm.manage');
            AgmEventService::archiveEvent($userId, $eventId);
            agm_back('archive', 0, 'event_archived');

        case 'save_content':
            require_permission('admin.agm.manage');
            $existing = AgmEventService::getEventById($eventId);
            if (!$existing) {
                agm_back('content', 0, '', 'Event not found.');
            }
            $existing['description_html'] = $_POST['description_html'] ?? '';
            $existing['cover_image_path'] = trim((string) ($_POST['cover_image_path'] ?? '')) ?: null;
            AgmEventService::updateEvent($userId, $eventId, $existing);
            agm_back('content', $eventId, 'content_saved');

        case 'save_product':
            require_permission('admin.agm.manage');
            $choices = [];
            if (!empty($_POST['choices_text'])) {
                foreach (preg_split('/\r?\n/', (string) $_POST['choices_text']) as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $choices[] = $line;
                    }
                }
            }
            $payload = [
                'id' => !empty($_POST['product_id']) ? (int) $_POST['product_id'] : null,
                'category' => $_POST['category'] ?? 'custom',
                'name' => trim((string) ($_POST['name'] ?? '')),
                'description' => $_POST['description'] ?? null,
                'early_price' => $_POST['early_price'] ?? 0,
                'late_price' => $_POST['late_price'] ?? '',
                'member_only' => !empty($_POST['member_only']),
                'non_member_only' => !empty($_POST['non_member_only']),
                'requires_choice' => !empty($_POST['requires_choice']),
                'choices' => $choices,
                'quantity_limit' => $_POST['quantity_limit'] ?? '',
                'per_registration_limit' => $_POST['per_registration_limit'] ?? '',
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                'is_active' => !empty($_POST['is_active']),
            ];
            if ($payload['name'] === '') {
                agm_back('products', $eventId, '', 'Product name is required.');
            }
            AgmEventService::saveProduct($userId, $eventId, $payload);
            agm_back('products', $eventId, 'product_saved');

        case 'delete_product':
            require_permission('admin.agm.manage');
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId > 0) {
                AgmEventService::deleteProduct($userId, $productId);
            }
            agm_back('products', $eventId, 'product_deleted');

        case 'save_field':
            require_permission('admin.agm.manage');
            $options = [];
            if (!empty($_POST['options_text'])) {
                foreach (preg_split('/\r?\n/', (string) $_POST['options_text']) as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $options[] = $line;
                    }
                }
            }
            $payload = [
                'id' => !empty($_POST['field_id']) ? (int) $_POST['field_id'] : null,
                'field_key' => $_POST['field_key'] ?? '',
                'label' => $_POST['label'] ?? '',
                'helper_text' => $_POST['helper_text'] ?? null,
                'field_group' => $_POST['field_group'] ?? 'other',
                'field_type' => $_POST['field_type'] ?? 'text',
                'options' => $options,
                'is_required' => !empty($_POST['is_required']),
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                'is_active' => !empty($_POST['is_active']),
            ];
            if (trim((string) $payload['label']) === '' || trim((string) $payload['field_key']) === '') {
                agm_back('fields', $eventId, '', 'Field key and label are required.');
            }
            AgmEventService::saveFormField($userId, $eventId, $payload);
            agm_back('fields', $eventId, 'field_saved');

        case 'delete_field':
            require_permission('admin.agm.manage');
            $fieldId = (int) ($_POST['field_id'] ?? 0);
            if ($fieldId > 0) {
                AgmEventService::deleteFormField($userId, $fieldId);
            }
            agm_back('fields', $eventId, 'field_deleted');

        case 'clone_from_previous':
            require_permission('admin.agm.manage');
            $sourceId = (int) ($_POST['source_event_id'] ?? 0);
            if ($sourceId <= 0 || $sourceId === $eventId) {
                agm_back('products', $eventId, '', 'Pick a different source event.');
            }
            AgmEventService::cloneFromPrevious($userId, $sourceId, $eventId);
            agm_back('products', $eventId, 'cloned');

        case 'save_settings':
            require_permission('admin.agm.settings');
            $errors = [];
            StripeSettingsService::saveAgmAdminSettings($userId, $_POST, $errors);
            if ($errors) {
                agm_back('settings', $eventId, '', implode(' ', $errors));
            }
            agm_back('settings', $eventId, 'settings_saved');

        case 'toggle_feature':
            require_permission('admin.agm.settings');
            $enabled = !empty($_POST['enabled']);
            AgmEventService::setFeatureEnabled($userId, $enabled);
            agm_back('settings', $eventId, $enabled ? 'feature_enabled' : 'feature_disabled');

        case 'mark_registration_paid':
            require_permission('admin.agm.manage');
            $regId = (int) ($_POST['registration_id'] ?? 0);
            if ($regId > 0) {
                AgmRegistrationService::markPaid($regId, null, null, $userId);
            }
            agm_back('submissions', $eventId, 'reg_marked_paid');

        case 'cancel_registration':
            require_permission('admin.agm.manage');
            $regId = (int) ($_POST['registration_id'] ?? 0);
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if ($regId > 0) {
                AgmRegistrationService::markCancelled($regId, $userId, $reason);
            }
            agm_back('submissions', $eventId, 'reg_cancelled');

        case 'refund_registration':
            require_permission('admin.agm.refund');
            $regId = (int) ($_POST['registration_id'] ?? 0);
            $reg = $regId > 0 ? AgmRegistrationService::getRegistrationById($regId) : null;
            if (!$reg) {
                agm_back('submissions', $eventId, '', 'Registration not found.');
            }
            $intent = (string) ($reg['stripe_payment_intent_id'] ?? '');
            if ($intent === '') {
                AgmRegistrationService::markRefunded($regId, null, $userId);
                agm_back('submissions', $eventId, 'reg_refunded');
            }
            $refund = StripeService::createRefund($intent, 0, StripeSettingsService::ACCOUNT_AGM);
            if ($refund === null) {
                agm_back('submissions', $eventId, '', 'Stripe refund failed. Check AGM Stripe keys.');
            }
            AgmRegistrationService::markRefunded($regId, $refund['id'] ?? null, $userId);
            agm_back('submissions', $eventId, 'reg_refunded');

        default:
            agm_back($tab, $eventId, '', 'Unknown action.');
    }
} catch (Throwable $e) {
    agm_back($tab, $eventId, '', $e->getMessage());
}
