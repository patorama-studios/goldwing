<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\BaseUrlService;
use App\Services\MembershipPricingService;
use App\Services\PaymentSettingsService;
use App\Services\NotificationService;
use App\Services\SecuritySettingsService;
use App\Services\SettingsService;
use App\Services\ChapterRepository;
use App\Services\Validator;

require_login();

$user = current_user();
$roles = $user['roles'] ?? [];

$sections = [
    'site' => ['label' => 'Site Settings', 'roles' => ['admin']],
    'store' => ['label' => 'Store Settings', 'roles' => ['admin', 'store_manager']],
    'payments' => ['label' => 'Payments (Stripe)', 'roles' => ['committee', 'treasurer']],
    'notifications' => ['label' => 'Notifications', 'roles' => ['admin']],
    'accounts' => ['label' => 'Accounts & Roles', 'roles' => ['super_admin', 'admin']],
    'security' => ['label' => 'Security & Authentication', 'roles' => ['super_admin', 'admin', 'committee']],
    'integrations' => ['label' => 'Integrations', 'roles' => ['super_admin', 'admin']],
    'media' => ['label' => 'Media & Files', 'roles' => ['admin']],
    'events' => ['label' => 'Events', 'roles' => ['admin']],
    'membership_pricing' => ['label' => 'Membership Settings', 'roles' => ['admin', 'store_manager', 'committee', 'treasurer']],
    'audit' => ['label' => 'Audit Log', 'roles' => ['super_admin', 'admin']],
    'advanced' => ['label' => 'Advanced / Developer', 'roles' => ['super_admin', 'admin']],
];

function can_access_section(array $roles, array $allowed): bool
{
    if (in_array('super_admin', $roles, true)) {
        return true;
    }
    foreach ($allowed as $role) {
        if (in_array($role, $roles, true)) {
            return true;
        }
    }
    return false;
}

$section = $_GET['section'] ?? 'site';
if (!isset($sections[$section])) {
    $section = 'site';
}

if (!can_access_section($roles, $sections[$section]['roles'])) {
    header('Location: /locked-out');
    exit;
}

SettingsService::migrateLegacy((int) $user['id']);
SettingsService::ensureDefaults((int) $user['id']);
$securitySettings = SecuritySettingsService::get();

$errors = [];
$toast = '';

function normalize_text(?string $value): string
{
    return trim((string) $value);
}

function normalize_list(string $value): array
{
    $parts = preg_split('/[,\n]+/', $value);
    $clean = [];
    foreach ($parts as $part) {
        $item = trim($part);
        if ($item !== '') {
            $clean[] = $item;
        }
    }
    return array_values(array_unique($clean));
}

function parse_money_to_cents(string $value, ?string &$error = null): ?int
{
    $raw = trim($value);
    if ($raw === '') {
        $error = 'Amount is required.';
        return null;
    }
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $raw)) {
        $error = 'Enter a valid dollar amount (e.g. 90 or 90.00).';
        return null;
    }
    [$dollars, $cents] = array_pad(explode('.', $raw, 2), 2, '0');
    $cents = substr(str_pad($cents, 2, '0'), 0, 2);
    $amount = ((int) $dollars) * 100 + (int) $cents;
    if ($amount < 0) {
        $error = 'Amount cannot be negative.';
        return null;
    }
    return $amount;
}

function format_cents_to_dollars(?int $amount): string
{
    if ($amount === null) {
        return '';
    }
    return number_format($amount / 100, 2, '.', '');
}

function is_goldwing_sender(string $email): bool
{
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    return str_ends_with(strtolower($email), '@goldwing.org.au');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } elseif ($action === 'send_test_notification') {
        if ($section !== 'payments') {
            require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/settings/index.php');
        }
        $postedSection = $_POST['section'] ?? '';
        if ($postedSection !== $section) {
            $errors[] = 'Invalid settings section.';
        } elseif (!can_access_section($roles, $sections[$section]['roles'])) {
            $errors[] = 'Unauthorized.';
        } else {
            $definitions = NotificationService::definitions();
            $key = normalize_text($_POST['test_notification_key'] ?? '');
            if (!isset($definitions[$key])) {
                $errors[] = 'Select a valid notification to test.';
            } else {
                $testEmail = normalize_text($_POST['test_notification_email'] ?? '');
                if ($testEmail !== '' && !Validator::email($testEmail)) {
                    $errors[] = 'Enter a valid test email address.';
                } else {
                    $recipient = $testEmail !== '' ? $testEmail : ($user['email'] ?? SettingsService::getGlobal('site.contact_email', ''));
                $context = NotificationService::sampleContext($key);
                $context['primary_email'] = $recipient;
                $context['admin_emails'] = NotificationService::getAdminEmails(SettingsService::getGlobal('notifications.admin_emails', ''));
                $sent = NotificationService::dispatch($key, $context, ['force' => true]);
                $toast = $sent ? 'Test notification sent.' : 'Unable to send test notification.';
                }
            }
        }
    } elseif ($action === 'save_settings') {
        $requiresStepup = $section !== 'payments';
        if ($section === 'security' && $securitySettings['stepup_enabled'] && !isset($_POST['stepup_enabled'])) {
            $requiresStepup = false;
        }
        if ($requiresStepup) {
            require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/settings/index.php');
        }
        $postedSection = $_POST['section'] ?? '';
        if ($postedSection !== $section) {
            $errors[] = 'Invalid settings section.';
        } elseif (!can_access_section($roles, $sections[$section]['roles'])) {
            $errors[] = 'Unauthorized.';
        } else {
            if ($section === 'site') {
                $siteName = normalize_text($_POST['site_name'] ?? '');
                $timezone = normalize_text($_POST['site_timezone'] ?? '');
                if ($siteName === '') {
                    $errors[] = 'Site name is required.';
                }
                if ($timezone === '') {
                    $errors[] = 'Timezone is required.';
                }
                if (!$errors) {
                    $siteBaseUrl = normalize_text($_POST['site_base_url'] ?? '');
                    $baseUrlError = BaseUrlService::validateSettingValue($siteBaseUrl);
                    if ($baseUrlError) {
                        $errors[] = $baseUrlError;
                    } else {
                        SettingsService::setGlobal((int) $user['id'], 'site.name', $siteName);
                        SettingsService::setGlobal((int) $user['id'], 'site.tagline', normalize_text($_POST['site_tagline'] ?? ''));
                        SettingsService::setGlobal((int) $user['id'], 'site.logo_url', normalize_text($_POST['site_logo_url'] ?? ''));
                        SettingsService::setGlobal((int) $user['id'], 'site.favicon_url', normalize_text($_POST['site_favicon_url'] ?? ''));
                        SettingsService::setGlobal((int) $user['id'], 'site.timezone', $timezone);
                        SettingsService::setGlobal((int) $user['id'], 'site.base_url', BaseUrlService::normalize($siteBaseUrl));
                        SettingsService::setGlobal((int) $user['id'], 'site.contact_email', normalize_text($_POST['site_contact_email'] ?? ''));
                        SettingsService::setGlobal((int) $user['id'], 'site.contact_phone', normalize_text($_POST['site_contact_phone'] ?? ''));
                        SettingsService::setGlobal((int) $user['id'], 'site.show_nav', isset($_POST['site_show_nav']));
                        SettingsService::setGlobal((int) $user['id'], 'site.show_footer', isset($_POST['site_show_footer']));
                        SettingsService::setGlobal((int) $user['id'], 'site.social_links', [
                            'facebook' => normalize_text($_POST['social_facebook'] ?? ''),
                            'instagram' => normalize_text($_POST['social_instagram'] ?? ''),
                            'youtube' => normalize_text($_POST['social_youtube'] ?? ''),
                            'tiktok' => normalize_text($_POST['social_tiktok'] ?? ''),
                        ]);
                        SettingsService::setGlobal((int) $user['id'], 'site.legal_urls', [
                            'privacy' => normalize_text($_POST['legal_privacy'] ?? ''),
                            'terms' => normalize_text($_POST['legal_terms'] ?? ''),
                        ]);
                        $toast = 'Site settings saved.';
                    }
                }
            } elseif ($section === 'store') {
                SettingsService::setGlobal((int) $user['id'], 'store.name', normalize_text($_POST['store_name'] ?? ''));
                SettingsService::setGlobal((int) $user['id'], 'store.slug', normalize_text($_POST['store_slug'] ?? 'store'));
                SettingsService::setGlobal((int) $user['id'], 'store.members_only', isset($_POST['store_members_only']));
                SettingsService::setGlobal((int) $user['id'], 'store.shipping_region', normalize_text($_POST['store_shipping_region'] ?? 'AU'));
                SettingsService::setGlobal((int) $user['id'], 'store.gst_enabled', isset($_POST['store_gst_enabled']));
                SettingsService::setGlobal((int) $user['id'], 'store.pass_stripe_fees', isset($_POST['store_pass_fees']));
                SettingsService::setGlobal((int) $user['id'], 'store.stripe_fee_percent', (float) ($_POST['store_fee_percent'] ?? 0));
                SettingsService::setGlobal((int) $user['id'], 'store.stripe_fee_fixed', (float) ($_POST['store_fee_fixed'] ?? 0));
                SettingsService::setGlobal((int) $user['id'], 'store.shipping_flat_enabled', isset($_POST['store_shipping_flat_enabled']));
                SettingsService::setGlobal((int) $user['id'], 'store.shipping_flat_rate', $_POST['store_shipping_flat_rate'] !== '' ? (float) $_POST['store_shipping_flat_rate'] : null);
                SettingsService::setGlobal((int) $user['id'], 'store.shipping_free_enabled', isset($_POST['store_shipping_free_enabled']));
                SettingsService::setGlobal((int) $user['id'], 'store.shipping_free_threshold', $_POST['store_shipping_free_threshold'] !== '' ? (float) $_POST['store_shipping_free_threshold'] : null);
                SettingsService::setGlobal((int) $user['id'], 'store.pickup_enabled', isset($_POST['store_pickup_enabled']));
                SettingsService::setGlobal((int) $user['id'], 'store.pickup_instructions', normalize_text($_POST['store_pickup_instructions'] ?? ''));
                SettingsService::setGlobal((int) $user['id'], 'store.notification_emails', normalize_text($_POST['store_notification_emails'] ?? ''));
                SettingsService::setGlobal((int) $user['id'], 'store.email_logo_url', normalize_text($_POST['store_email_logo'] ?? ''));
                SettingsService::setGlobal((int) $user['id'], 'store.email_footer_text', normalize_text($_POST['store_email_footer'] ?? ''));
                SettingsService::setGlobal((int) $user['id'], 'store.support_email', normalize_text($_POST['store_support_email'] ?? ''));
                SettingsService::setGlobal((int) $user['id'], 'store.order_paid_status', normalize_text($_POST['store_order_paid_status'] ?? 'paid'));
                $toast = 'Store settings saved.';
            } elseif ($section === 'payments') {
                $publishableKey = normalize_text($_POST['stripe_publishable_key'] ?? '');
                $secretKey = normalize_text($_POST['stripe_secret_key'] ?? '');
                $webhookSecret = normalize_text($_POST['stripe_webhook_secret'] ?? '');
                $invoicePrefix = normalize_text($_POST['stripe_invoice_prefix'] ?? 'INV');
                $invoiceTemplate = normalize_text($_POST['stripe_invoice_email_template'] ?? '');
                $generatePdf = isset($_POST['stripe_generate_pdf']) ? 1 : 0;

                $channel = PaymentSettingsService::getChannelByCode('primary');
                PaymentSettingsService::updateSettings((int) $channel['id'], [
                    'publishable_key' => $publishableKey,
                    'secret_key' => $secretKey,
                    'webhook_secret' => $webhookSecret,
                    'invoice_prefix' => $invoicePrefix,
                    'invoice_email_template' => $invoiceTemplate,
                    'generate_pdf' => $generatePdf,
                ]);

                $settings = PaymentSettingsService::getSettingsByChannelId((int) $channel['id']);
                SettingsService::setGlobal((int) $user['id'], 'payments.stripe.mode', $settings['mode'] ?? 'test');
                SettingsService::setGlobal((int) $user['id'], 'payments.stripe.publishable_key', $publishableKey);
                if ($secretKey !== '') {
                    SettingsService::setGlobal((int) $user['id'], 'payments.stripe.secret_key', $secretKey, ['encrypt' => true]);
                }
                if ($webhookSecret !== '') {
                    SettingsService::setGlobal((int) $user['id'], 'payments.stripe.webhook_secret', $webhookSecret, ['encrypt' => true]);
                }
                SettingsService::setGlobal((int) $user['id'], 'payments.stripe.generate_pdf', $generatePdf === 1);
                SettingsService::setGlobal((int) $user['id'], 'payments.stripe.invoice_prefix', $invoicePrefix);
                SettingsService::setGlobal((int) $user['id'], 'payments.stripe.invoice_email_template', $invoiceTemplate);
                $prices = [
                    'FULL_1Y' => normalize_text($_POST['price_full_1y'] ?? ''),
                    'FULL_3Y' => normalize_text($_POST['price_full_3y'] ?? ''),
                    'ASSOCIATE_1Y' => normalize_text($_POST['price_associate_1y'] ?? ''),
                    'ASSOCIATE_3Y' => normalize_text($_POST['price_associate_3y'] ?? ''),
                    'LIFE' => normalize_text($_POST['price_life'] ?? ''),
                ];
                SettingsService::setGlobal((int) $user['id'], 'payments.membership_prices', $prices);
                $toast = 'Stripe settings saved.';
            } elseif ($section === 'notifications') {
                $fromName = normalize_text($_POST['notify_from_name'] ?? '');
                $fromEmail = normalize_text($_POST['notify_from_email'] ?? '');
                $replyTo = normalize_text($_POST['notify_reply_to'] ?? '');
                if ($fromEmail !== '' && !is_goldwing_sender($fromEmail)) {
                    $errors[] = 'From email must be a valid address ending with @goldwing.org.au.';
                }
                if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Reply-to email must be valid.';
                }
                if ($errors) {
                    $section = 'notifications';
                } else {
                    SettingsService::setGlobal((int) $user['id'], 'notifications.from_name', $fromName);
                    SettingsService::setGlobal((int) $user['id'], 'notifications.from_email', $fromEmail);
                    SettingsService::setGlobal((int) $user['id'], 'notifications.reply_to', $replyTo);
                }
                SettingsService::setGlobal((int) $user['id'], 'notifications.admin_emails', normalize_text($_POST['notify_admin_emails'] ?? ''));
                SettingsService::setGlobal((int) $user['id'], 'notifications.weekly_digest_enabled', isset($_POST['notify_weekly_digest']));
                SettingsService::setGlobal((int) $user['id'], 'notifications.event_reminders_enabled', isset($_POST['notify_event_reminders']));
                SettingsService::setGlobal((int) $user['id'], 'notifications.in_app_categories', normalize_list($_POST['notify_categories'] ?? ''));
                SettingsService::setGlobal((int) $user['id'], 'notifications.template_basic', normalize_text($_POST['notify_template_basic'] ?? ''));
                $definitions = NotificationService::definitions();
                $currentCatalog = NotificationService::getCatalogSettings();
                $activeKey = normalize_text($_POST['notification_active_key'] ?? '');
                $updatedCatalog = [];
                foreach ($definitions as $key => $definition) {
                    $current = $currentCatalog[$key] ?? ($definition['defaults'] ?? []);
                    if ($activeKey !== '' && $key !== $activeKey) {
                        $updatedCatalog[$key] = $current;
                        continue;
                    }
                    $subject = normalize_text($_POST['notification_subject'][$key] ?? ($current['subject'] ?? ''));
                    $body = trim((string) ($_POST['notification_body'][$key] ?? ($current['body'] ?? '')));
                    $mode = normalize_text($_POST['notification_recipient_mode'][$key] ?? ($current['recipient_mode'] ?? 'primary'));
                    $entryFromName = normalize_text($_POST['notification_from_name'][$key] ?? ($current['from_name'] ?? ''));
                    $entryFromEmail = normalize_text($_POST['notification_from_email'][$key] ?? ($current['from_email'] ?? ''));
                    $entryReplyTo = normalize_text($_POST['notification_reply_to'][$key] ?? ($current['reply_to'] ?? ''));
                    if ($entryFromEmail !== '' && !is_goldwing_sender($entryFromEmail)) {
                        $errors[] = 'Notification "' . ($definition['label'] ?? $key) . '" has an invalid from address.';
                    }
                    if ($entryReplyTo !== '' && !filter_var($entryReplyTo, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = 'Notification "' . ($definition['label'] ?? $key) . '" has an invalid reply-to address.';
                    }
                    if (!in_array($mode, ['primary', 'admin', 'both', 'custom'], true)) {
                        $mode = $current['recipient_mode'] ?? 'primary';
                    }
                    $updatedCatalog[$key] = [
                        'enabled' => isset($_POST['notification_enabled'][$key]),
                        'recipient_mode' => $mode,
                        'custom_recipients' => normalize_text($_POST['notification_custom_recipients'][$key] ?? ''),
                        'subject' => $subject,
                        'body' => $body,
                        'from_name' => $entryFromName,
                        'from_email' => $entryFromEmail,
                        'reply_to' => $entryReplyTo,
                    ];
                }
                if (!$errors) {
                    SettingsService::setGlobal((int) $user['id'], 'notifications.catalog', $updatedCatalog);
                    $toast = 'Notification settings saved.';
                }
            } elseif ($section === 'accounts') {
                if (SettingsService::isFeatureEnabled('accounts.roles')) {
                    SettingsService::setGlobal((int) $user['id'], 'accounts.user_approval_required', isset($_POST['accounts_user_approval']));
                    SettingsService::setGlobal((int) $user['id'], 'accounts.membership_status_visibility', normalize_text($_POST['accounts_membership_visibility'] ?? 'member'));
                    SettingsService::setGlobal((int) $user['id'], 'accounts.audit_role_changes', isset($_POST['accounts_audit_roles']));
                    $toast = 'Account settings saved.';
                }
            } elseif ($section === 'security') {
                SettingsService::setGlobal((int) $user['id'], 'security.force_https', isset($_POST['security_force_https']));
                SettingsService::setGlobal((int) $user['id'], 'security.password_min_length', (int) ($_POST['security_password_min'] ?? 12));
                $twofaRoles = isset($_POST['twofa_roles']) ? (array) $_POST['twofa_roles'] : [];
                $alerts = [
                    'failed_login' => isset($_POST['alert_failed_login']),
                    'new_admin_device' => isset($_POST['alert_new_admin_device']),
                    'refund_created' => isset($_POST['alert_refund_created']),
                    'role_escalation' => isset($_POST['alert_role_escalation']),
                    'member_export' => isset($_POST['alert_member_export']),
                    'fim_changes' => isset($_POST['alert_fim_changes']),
                    'webhook_failure' => isset($_POST['alert_webhook_failure']),
                ];
                SecuritySettingsService::update((int) $user['id'], [
                    'enable_2fa' => isset($_POST['twofa_enabled']),
                    'twofa_mode' => $_POST['twofa_mode'] ?? 'REQUIRED_FOR_ALL',
                    'twofa_required_roles' => $twofaRoles,
                    'twofa_grace_days' => (int) ($_POST['twofa_grace_days'] ?? 0),
                    'stepup_enabled' => isset($_POST['stepup_enabled']),
                    'stepup_window_minutes' => (int) ($_POST['stepup_window_minutes'] ?? 10),
                    'login_ip_max_attempts' => (int) ($_POST['login_ip_max_attempts'] ?? 10),
                    'login_ip_window_minutes' => (int) ($_POST['login_ip_window_minutes'] ?? 10),
                    'login_account_max_attempts' => (int) ($_POST['login_account_max_attempts'] ?? 5),
                    'login_account_window_minutes' => (int) ($_POST['login_account_window_minutes'] ?? 15),
                    'login_lockout_minutes' => (int) ($_POST['login_lockout_minutes'] ?? 30),
                    'login_progressive_delay' => isset($_POST['login_progressive_delay']),
                    'alert_email' => normalize_text($_POST['alert_email'] ?? ''),
                    'alerts' => $alerts,
                    'fim_enabled' => isset($_POST['fim_enabled']),
                    'fim_paths' => normalize_list($_POST['fim_paths'] ?? ''),
                    'fim_exclude_paths' => normalize_list($_POST['fim_exclude_paths'] ?? ''),
                    'webhook_alerts_enabled' => isset($_POST['webhook_alerts_enabled']),
                    'webhook_alert_threshold' => (int) ($_POST['webhook_alert_threshold'] ?? 3),
                    'webhook_alert_window_minutes' => (int) ($_POST['webhook_alert_window_minutes'] ?? 10),
                ]);
                if (isset($_POST['approve_fim_baseline'])) {
                    require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/settings/index.php');
                    $root = dirname(__DIR__, 3);
                    $settings = SecuritySettingsService::get();
                    $baseline = App\Services\FileIntegrityService::computeBaseline($root, $settings['fim_paths'], $settings['fim_exclude_paths']);
                    App\Services\FileIntegrityService::saveBaseline($baseline, (int) $user['id']);
                    App\Services\ActivityLogger::log('admin', $user['id'] ?? null, null, 'security.fim_baseline_approved');
                }
                $toast = 'Security settings saved.';
            } elseif ($section === 'integrations') {
                SettingsService::setGlobal((int) $user['id'], 'integrations.email_provider', normalize_text($_POST['integrations_email_provider'] ?? 'php_mail'));
                SettingsService::setGlobal((int) $user['id'], 'integrations.smtp_host', normalize_text($_POST['integrations_smtp_host'] ?? ''));
                SettingsService::setGlobal((int) $user['id'], 'integrations.smtp_port', (int) ($_POST['integrations_smtp_port'] ?? 587));
                SettingsService::setGlobal((int) $user['id'], 'integrations.smtp_user', normalize_text($_POST['integrations_smtp_user'] ?? ''));
                SettingsService::setGlobal((int) $user['id'], 'integrations.smtp_password', normalize_text($_POST['integrations_smtp_password'] ?? ''), ['encrypt' => true]);
                SettingsService::setGlobal((int) $user['id'], 'integrations.smtp_encryption', normalize_text($_POST['integrations_smtp_encryption'] ?? 'tls'));
                SettingsService::setGlobal((int) $user['id'], 'integrations.youtube_embeds_enabled', isset($_POST['integrations_youtube']));
                SettingsService::setGlobal((int) $user['id'], 'integrations.vimeo_embeds_enabled', isset($_POST['integrations_vimeo']));
                SettingsService::setGlobal((int) $user['id'], 'integrations.zoom_default_url', normalize_text($_POST['integrations_zoom_default'] ?? ''));
                if (SettingsService::isFeatureEnabled('integrations.myob')) {
                    SettingsService::setGlobal((int) $user['id'], 'integrations.myob_enabled', isset($_POST['integrations_myob_enabled']));
                }
                $toast = 'Integrations settings saved.';
            } elseif ($section === 'media') {
                SettingsService::setGlobal((int) $user['id'], 'media.allowed_types', normalize_list($_POST['media_allowed_types'] ?? ''));
                SettingsService::setGlobal((int) $user['id'], 'media.max_upload_mb', (float) ($_POST['media_max_upload'] ?? 10));
                SettingsService::setGlobal((int) $user['id'], 'media.storage_limit_mb', (float) ($_POST['media_storage_limit'] ?? 5120));
                SettingsService::setGlobal((int) $user['id'], 'media.image_optimization_enabled', isset($_POST['media_image_opt']));
                SettingsService::setGlobal((int) $user['id'], 'media.privacy_default', normalize_text($_POST['media_privacy_default'] ?? 'member'));
                if (SettingsService::isFeatureEnabled('media.folder_taxonomy')) {
                    SettingsService::setGlobal((int) $user['id'], 'media.folder_taxonomy', normalize_list($_POST['media_folder_taxonomy'] ?? ''));
                }
                $toast = 'Media settings saved.';
            } elseif ($section === 'events') {
                SettingsService::setGlobal((int) $user['id'], 'events.rsvp_default_enabled', isset($_POST['events_rsvp_default']));
                SettingsService::setGlobal((int) $user['id'], 'events.visibility_default', normalize_text($_POST['events_visibility_default'] ?? 'member'));
                SettingsService::setGlobal((int) $user['id'], 'events.public_ticketing_enabled', isset($_POST['events_public_ticketing']));
                SettingsService::setGlobal((int) $user['id'], 'events.timezone', normalize_text($_POST['events_timezone'] ?? 'Australia/Sydney'));
                SettingsService::setGlobal((int) $user['id'], 'events.include_map_link', isset($_POST['events_include_map']));
                SettingsService::setGlobal((int) $user['id'], 'events.include_zoom_link', isset($_POST['events_include_zoom']));
                $toast = 'Event settings saved.';
            } elseif ($section === 'membership_pricing') {
                $fieldErrors = [];
                $idFieldErrors = [];
                $chapterFieldErrors = [];
                $matrix = [];
                $periods = MembershipPricingService::periodDefinitions();
                foreach (MembershipPricingService::MAGAZINE_TYPES as $magazineType) {
                    foreach (MembershipPricingService::MEMBERSHIP_TYPES as $membershipType) {
                        foreach (array_keys($periods) as $periodKey) {
                            $fieldKey = $magazineType . '.' . $membershipType . '.' . $periodKey;
                            $rawValue = normalize_text($_POST['pricing'][$magazineType][$membershipType][$periodKey] ?? '');
                            $errorText = null;
                            $amount = parse_money_to_cents($rawValue, $errorText);
                            if ($amount === null) {
                                $fieldErrors[$fieldKey] = $errorText ?: 'Invalid amount.';
                                continue;
                            }
                            $matrix[$magazineType][$membershipType][$periodKey] = $amount;
                        }
                    }
                }

                $memberNumberStart = (int) ($_POST['member_number_start'] ?? 1000);
                $associateSuffixStart = (int) ($_POST['associate_suffix_start'] ?? 1);
                $memberNumberFormatFull = trim((string) ($_POST['member_number_format_full'] ?? '{base}'));
                $memberNumberFormatAssociate = trim((string) ($_POST['member_number_format_associate'] ?? '{base}.{suffix}'));
                $memberNumberBasePadding = (int) ($_POST['member_number_base_padding'] ?? 0);
                $memberNumberSuffixPadding = (int) ($_POST['member_number_suffix_padding'] ?? 0);
                $manualMigrationEnabled = isset($_POST['manual_migration_enabled']);
                $manualMigrationExpiryDays = (int) ($_POST['manual_migration_expiry_days'] ?? 14);

                $pdo = db();
                $hasChapterState = ChapterRepository::hasColumn($pdo, 'state');
                $hasChapterActive = ChapterRepository::hasColumn($pdo, 'is_active');
                $hasChapterSortOrder = ChapterRepository::hasColumn($pdo, 'sort_order');

                $chapterUpdates = [];
                $chaptersPayload = $_POST['chapters'] ?? [];
                if (is_array($chaptersPayload)) {
                    $position = 0;
                    foreach ($chaptersPayload as $chapterId => $chapterData) {
                        if (!is_numeric($chapterId)) {
                            continue;
                        }
                        $position++;
                        $name = normalize_text($chapterData['name'] ?? '');
                        $state = normalize_text($chapterData['state'] ?? '');
                        $sortOrder = (int) ($chapterData['sort_order'] ?? ($position * 10));
                        $isActive = isset($chapterData['is_active']) ? 1 : 0;

                        if (!Validator::required($name)) {
                            $chapterFieldErrors[$chapterId]['name'] = 'Name is required.';
                            continue;
                        }
                        if ($hasChapterSortOrder && $sortOrder < 0) {
                            $chapterFieldErrors[$chapterId]['sort_order'] = 'Order must be 0 or greater.';
                            continue;
                        }
                        $chapterUpdates[] = [
                            'id' => (int) $chapterId,
                            'name' => $name,
                            'state' => $state,
                            'sort_order' => $sortOrder,
                            'is_active' => $isActive,
                        ];
                    }
                }

                $newChapterName = normalize_text($_POST['new_chapter_name'] ?? '');
                $newChapterState = normalize_text($_POST['new_chapter_state'] ?? '');
                $newChapterSortOrder = (int) ($_POST['new_chapter_sort_order'] ?? 0);
                $newChapterActive = isset($_POST['new_chapter_active']) ? 1 : 0;

                if ($memberNumberStart < 1) {
                    $idFieldErrors['member_number_start'] = 'Start number must be 1 or greater.';
                }
                if ($associateSuffixStart < 1) {
                    $idFieldErrors['associate_suffix_start'] = 'Suffix start must be 1 or greater.';
                }
                if ($memberNumberBasePadding < 0 || $memberNumberBasePadding > 12) {
                    $idFieldErrors['member_number_base_padding'] = 'Base padding must be between 0 and 12.';
                }
                if ($memberNumberSuffixPadding < 0 || $memberNumberSuffixPadding > 12) {
                    $idFieldErrors['member_number_suffix_padding'] = 'Suffix padding must be between 0 and 12.';
                }
                if ($manualMigrationExpiryDays < 1 || $manualMigrationExpiryDays > 60) {
                    $idFieldErrors['manual_migration_expiry_days'] = 'Expiry must be between 1 and 60 days.';
                }
                if (strpos($memberNumberFormatFull, '{base}') === false && strpos($memberNumberFormatFull, '{base_padded}') === false) {
                    $idFieldErrors['member_number_format_full'] = 'Include {base} or {base_padded}.';
                }
                $associateHasBase = strpos($memberNumberFormatAssociate, '{base}') !== false || strpos($memberNumberFormatAssociate, '{base_padded}') !== false;
                $associateHasSuffix = strpos($memberNumberFormatAssociate, '{suffix}') !== false || strpos($memberNumberFormatAssociate, '{suffix_padded}') !== false;
                if (!$associateHasBase || !$associateHasSuffix) {
                    $idFieldErrors['member_number_format_associate'] = 'Include {base} and {suffix}.';
                }

                if ($newChapterName === '' && ($newChapterState !== '' || $newChapterSortOrder > 0)) {
                    $chapterFieldErrors['new']['name'] = 'Name is required for new chapters.';
                }

                if (!$fieldErrors && !$idFieldErrors && !$chapterFieldErrors) {
                    if (isset($_POST['reset_defaults']) && $_POST['reset_defaults'] === '1') {
                        $matrix = [];
                        foreach (MembershipPricingService::defaultPricingRows() as $row) {
                            $matrix[$row['magazine_type']][$row['membership_type']][$row['period_key']] = (int) $row['amount_cents'];
                        }
                    }
                    MembershipPricingService::updateMembershipPricing((int) $user['id'], [
                        'currency' => 'AUD',
                        'matrix' => $matrix,
                    ]);
                    SettingsService::setGlobal((int) $user['id'], 'membership.member_number_start', $memberNumberStart);
                    SettingsService::setGlobal((int) $user['id'], 'membership.associate_suffix_start', $associateSuffixStart);
                    SettingsService::setGlobal((int) $user['id'], 'membership.member_number_format_full', $memberNumberFormatFull);
                    SettingsService::setGlobal((int) $user['id'], 'membership.member_number_format_associate', $memberNumberFormatAssociate);
                    SettingsService::setGlobal((int) $user['id'], 'membership.member_number_base_padding', $memberNumberBasePadding);
                    SettingsService::setGlobal((int) $user['id'], 'membership.member_number_suffix_padding', $memberNumberSuffixPadding);
                    SettingsService::setGlobal((int) $user['id'], 'membership.manual_migration_enabled', $manualMigrationEnabled);
                    SettingsService::setGlobal((int) $user['id'], 'membership.manual_migration_expiry_days', $manualMigrationExpiryDays);

                    foreach ($chapterUpdates as $chapterUpdate) {
                        $updateColumns = ['name = :name'];
                        $params = [
                            'id' => $chapterUpdate['id'],
                            'name' => $chapterUpdate['name'],
                        ];
                        if ($hasChapterState) {
                            $updateColumns[] = 'state = :state';
                            $params['state'] = $chapterUpdate['state'] !== '' ? $chapterUpdate['state'] : null;
                        }
                        if ($hasChapterActive) {
                            $updateColumns[] = 'is_active = :is_active';
                            $params['is_active'] = $chapterUpdate['is_active'];
                        }
                        if ($hasChapterSortOrder) {
                            $updateColumns[] = 'sort_order = :sort_order';
                            $params['sort_order'] = $chapterUpdate['sort_order'];
                        }
                        $stmt = $pdo->prepare('UPDATE chapters SET ' . implode(', ', $updateColumns) . ' WHERE id = :id');
                        $stmt->execute($params);
                    }

                    if ($newChapterName !== '') {
                        $columns = ['name'];
                        $placeholders = [':name'];
                        $params = ['name' => $newChapterName];
                        if ($hasChapterState) {
                            $columns[] = 'state';
                            $placeholders[] = ':state';
                            $params['state'] = $newChapterState !== '' ? $newChapterState : null;
                        }
                        if ($hasChapterActive) {
                            $columns[] = 'is_active';
                            $placeholders[] = ':is_active';
                            $params['is_active'] = $newChapterActive;
                        }
                        if ($hasChapterSortOrder) {
                            $columns[] = 'sort_order';
                            $placeholders[] = ':sort_order';
                            if ($newChapterSortOrder <= 0) {
                                $maxSort = (int) $pdo->query('SELECT MAX(sort_order) FROM chapters')->fetchColumn();
                                $newChapterSortOrder = max($maxSort, 0) + 10;
                            }
                            $params['sort_order'] = $newChapterSortOrder;
                        }
                        $stmt = $pdo->prepare('INSERT INTO chapters (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
                        $stmt->execute($params);
                    }

                    $toast = isset($_POST['reset_defaults']) && $_POST['reset_defaults'] === '1'
                        ? 'Membership pricing reset to defaults.'
                        : 'Membership settings saved.';
                } else {
                    if ($fieldErrors) {
                        $errors[] = 'Fix the highlighted pricing fields.';
                    }
                    if ($idFieldErrors) {
                        $errors[] = 'Fix the highlighted member ID fields.';
                    }
                    if ($chapterFieldErrors) {
                        $errors[] = 'Fix the highlighted chapter fields.';
                    }
                }
            } elseif ($section === 'advanced') {
                SettingsService::setGlobal((int) $user['id'], 'advanced.maintenance_mode', isset($_POST['advanced_maintenance']));
                $flags = [
                    'security.two_factor' => isset($_POST['flag_security_two_factor']),
                    'payments.secondary_stripe' => isset($_POST['flag_payments_secondary']),
                    'integrations.myob' => isset($_POST['flag_integrations_myob']),
                    'accounts.roles' => isset($_POST['flag_accounts_roles']),
                    'media.folder_taxonomy' => isset($_POST['flag_media_folder_taxonomy']),
                ];
                SettingsService::setGlobal((int) $user['id'], 'advanced.feature_flags', $flags);
                $toast = 'Advanced settings saved.';
            }
        }
    }
}

function section_last_updated(array $keys, PDO $pdo): array
{
    $latest = null;
    $userId = null;
    foreach ($keys as $key) {
        $meta = SettingsService::getGlobalMeta($key);
        if (!empty($meta['updated_at']) && ($latest === null || $meta['updated_at'] > $latest)) {
            $latest = $meta['updated_at'];
            $userId = $meta['updated_by_user_id'] ?? null;
        }
    }
    $userName = null;
    if ($userId) {
        $stmt = $pdo->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        $userName = $row['name'] ?? null;
    }
    return [
        'updated_at' => $latest,
        'updated_by' => $userName,
    ];
}

$pdo = db();
$activePage = 'settings';
$pageTitle = $sections[$section]['label'] ?? 'Settings Hub';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Settings Hub'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <?php if ($errors): ?>
        <div class="rounded-lg px-4 py-2 text-sm bg-red-50 text-red-700">
          <?= e(implode(' ', $errors)) ?>
        </div>
      <?php endif; ?>
      <?php if ($toast): ?>
        <div id="toast" class="rounded-lg px-4 py-2 text-sm bg-green-50 text-green-700"><?= e($toast) ?></div>
      <?php endif; ?>

      <section class="space-y-6">
        <div class="bg-card-light rounded-2xl border border-gray-100 p-6">
          <div class="flex items-center justify-between gap-4">
              <div>
                <h1 class="font-display text-2xl font-bold text-gray-900"><?= e($pageTitle) ?></h1>
                <p class="text-sm text-slate-500">Single source of truth for configuration across the platform.</p>
              </div>
              <?php if ($section !== 'audit'): ?>
                <div class="text-xs text-slate-500 text-right">
                  <?php
                    $sectionKeys = [
                        'site' => ['site.name', 'site.logo_url', 'site.timezone'],
                        'store' => ['store.name', 'store.members_only', 'store.shipping_region'],
                        'payments' => ['payments.stripe.mode', 'payments.stripe.publishable_key'],
                        'notifications' => ['notifications.from_email', 'notifications.weekly_digest_enabled'],
                        'security' => ['security.force_https', 'security.password_min_length'],
                        'integrations' => ['integrations.email_provider', 'integrations.youtube_embeds_enabled'],
                        'media' => ['media.allowed_types', 'media.max_upload_mb'],
                        'events' => ['events.visibility_default', 'events.timezone'],
                        'membership_pricing' => [
                            'membership.pricing_matrix',
                            'membership.member_number_start',
                            'membership.associate_suffix_start',
                            'membership.member_number_format_full',
                            'membership.member_number_format_associate',
                            'membership.member_number_base_padding',
                            'membership.member_number_suffix_padding',
                            'membership.manual_migration_enabled',
                            'membership.manual_migration_expiry_days',
                        ],
                        'advanced' => ['advanced.maintenance_mode', 'advanced.feature_flags'],
                        'accounts' => ['accounts.user_approval_required', 'accounts.audit_role_changes'],
                    ];
                    $last = $sectionKeys[$section] ?? [];
                    $lastMeta = $last ? section_last_updated($last, $pdo) : ['updated_at' => null, 'updated_by' => null];
                  ?>
                  <?php if (!empty($lastMeta['updated_at'])): ?>
                    <div>Last updated: <?= e($lastMeta['updated_at']) ?></div>
                    <?php if (!empty($lastMeta['updated_by'])): ?>
                      <div>By: <?= e($lastMeta['updated_by']) ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <div>Not updated yet</div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($section === 'site'): ?>
            <form method="post" class="space-y-6">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="site">

              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Brand</h2>
                  <label class="text-sm text-slate-600">Site name
                    <input name="site_name" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('site.name', '')) ?>" required>
                  </label>
                  <label class="text-sm text-slate-600">Tagline
                    <input name="site_tagline" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('site.tagline', '')) ?>">
                  </label>
                  <label class="text-sm text-slate-600">Logo URL
                    <input name="site_logo_url" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('site.logo_url', '')) ?>" placeholder="https://">
                  </label>
                  <label class="text-sm text-slate-600">Favicon URL
                    <input name="site_favicon_url" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('site.favicon_url', '')) ?>" placeholder="https://">
                  </label>
                </div>

                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Contact & Locale</h2>
                  <label class="text-sm text-slate-600">Base URL
                    <input name="site_base_url" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('site.base_url', '')) ?>" placeholder="https://goldwing.org.au">
                  </label>
                  <label class="text-sm text-slate-600">Timezone
                    <input name="site_timezone" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('site.timezone', 'Australia/Sydney')) ?>" required>
                  </label>
                  <label class="text-sm text-slate-600">Contact email
                    <input name="site_contact_email" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('site.contact_email', '')) ?>">
                  </label>
                  <label class="text-sm text-slate-600">Contact phone
                    <input name="site_contact_phone" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('site.contact_phone', '')) ?>">
                  </label>
                </div>
              </div>

              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Social Links</h2>
                  <?php $social = SettingsService::getGlobal('site.social_links', []); ?>
                  <label class="text-sm text-slate-600">Facebook
                    <input name="social_facebook" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($social['facebook'] ?? '') ?>">
                  </label>
                  <label class="text-sm text-slate-600">Instagram
                    <input name="social_instagram" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($social['instagram'] ?? '') ?>">
                  </label>
                  <label class="text-sm text-slate-600">YouTube
                    <input name="social_youtube" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($social['youtube'] ?? '') ?>">
                  </label>
                  <label class="text-sm text-slate-600">TikTok
                    <input name="social_tiktok" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($social['tiktok'] ?? '') ?>">
                  </label>
                </div>

                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Legal & Navigation</h2>
                  <?php $legal = SettingsService::getGlobal('site.legal_urls', []); ?>
                  <label class="text-sm text-slate-600">Privacy URL
                    <input name="legal_privacy" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($legal['privacy'] ?? '') ?>">
                  </label>
                  <label class="text-sm text-slate-600">Terms URL
                    <input name="legal_terms" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($legal['terms'] ?? '') ?>">
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="site_show_nav" class="rounded border-gray-200" <?= SettingsService::getGlobal('site.show_nav', true) ? 'checked' : '' ?>>
                    Show navigation
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="site_show_footer" class="rounded border-gray-200" <?= SettingsService::getGlobal('site.show_footer', true) ? 'checked' : '' ?>>
                    Show footer
                  </label>
                </div>
              </div>

              <div class="flex items-center justify-between">
                <a class="text-sm text-slate-500" href="/admin/settings/index.php?section=site">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save settings</button>
              </div>
            </form>
          <?php elseif ($section === 'store'): ?>
            <form method="post" class="space-y-6">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="store">

              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Store Basics</h2>
                  <label class="text-sm text-slate-600">Store name
                    <input name="store_name" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('store.name', '')) ?>">
                  </label>
                  <label class="text-sm text-slate-600">Store slug
                    <input name="store_slug" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('store.slug', 'store')) ?>">
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="store_members_only" class="rounded border-gray-200" <?= SettingsService::getGlobal('store.members_only', true) ? 'checked' : '' ?>>
                    Members-only purchasing
                  </label>
                  <label class="text-sm text-slate-600">Shipping region
                    <select name="store_shipping_region" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                      <?php $region = SettingsService::getGlobal('store.shipping_region', 'AU'); ?>
                      <option value="AU" <?= $region === 'AU' ? 'selected' : '' ?>>Australia only</option>
                      <option value="INTL" <?= $region === 'INTL' ? 'selected' : '' ?>>International</option>
                    </select>
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="store_gst_enabled" class="rounded border-gray-200" <?= SettingsService::getGlobal('store.gst_enabled', true) ? 'checked' : '' ?>>
                    Apply GST to orders
                  </label>
                </div>

                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Fees & Fulfillment</h2>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="store_pass_fees" class="rounded border-gray-200" <?= SettingsService::getGlobal('store.pass_stripe_fees', true) ? 'checked' : '' ?>>
                    Pass Stripe fees to buyer
                  </label>
                  <div class="grid grid-cols-2 gap-3">
                    <label class="text-sm text-slate-600">Fee percent
                      <input name="store_fee_percent" type="number" step="0.01" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) SettingsService::getGlobal('store.stripe_fee_percent', 0)) ?>">
                    </label>
                    <label class="text-sm text-slate-600">Fee fixed
                      <input name="store_fee_fixed" type="number" step="0.01" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) SettingsService::getGlobal('store.stripe_fee_fixed', 0)) ?>">
                    </label>
                  </div>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="store_shipping_flat_enabled" class="rounded border-gray-200" <?= SettingsService::getGlobal('store.shipping_flat_enabled', false) ? 'checked' : '' ?>>
                    Enable flat-rate shipping
                  </label>
                  <label class="text-sm text-slate-600">Flat-rate amount
                    <input name="store_shipping_flat_rate" type="number" step="0.01" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) SettingsService::getGlobal('store.shipping_flat_rate', '')) ?>">
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="store_shipping_free_enabled" class="rounded border-gray-200" <?= SettingsService::getGlobal('store.shipping_free_enabled', false) ? 'checked' : '' ?>>
                    Enable free shipping threshold
                  </label>
                  <label class="text-sm text-slate-600">Free shipping threshold
                    <input name="store_shipping_free_threshold" type="number" step="0.01" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) SettingsService::getGlobal('store.shipping_free_threshold', '')) ?>">
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="store_pickup_enabled" class="rounded border-gray-200" <?= SettingsService::getGlobal('store.pickup_enabled', false) ? 'checked' : '' ?>>
                    Enable pickup
                  </label>
                  <label class="text-sm text-slate-600">Pickup instructions
                    <textarea name="store_pickup_instructions" rows="3" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"><?= e(SettingsService::getGlobal('store.pickup_instructions', '')) ?></textarea>
                  </label>
                </div>
              </div>

              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Store Emails</h2>
                  <label class="text-sm text-slate-600">Admin notification emails
                    <textarea name="store_notification_emails" rows="2" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"><?= e(SettingsService::getGlobal('store.notification_emails', '')) ?></textarea>
                  </label>
                  <label class="text-sm text-slate-600">Email logo URL
                    <input name="store_email_logo" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('store.email_logo_url', '')) ?>">
                  </label>
                  <label class="text-sm text-slate-600">Email footer text
                    <input name="store_email_footer" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('store.email_footer_text', '')) ?>">
                  </label>
                  <label class="text-sm text-slate-600">Support email
                    <input name="store_support_email" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('store.support_email', '')) ?>">
                  </label>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Order Rules</h2>
                  <label class="text-sm text-slate-600">Paid order status
                    <input name="store_order_paid_status" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('store.order_paid_status', 'paid')) ?>">
                  </label>
                  <a class="text-sm text-blue-600" href="/admin/store/products">Manage products</a>
                </div>
              </div>

              <div class="flex items-center justify-between">
                <a class="text-sm text-slate-500" href="/admin/settings/index.php?section=store">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save settings</button>
              </div>
            </form>
          <?php elseif ($section === 'payments'): ?>
            <?php
              $paymentChannel = PaymentSettingsService::getChannelByCode('primary');
              $paymentSettings = PaymentSettingsService::getSettingsByChannelId((int) ($paymentChannel['id'] ?? 0));
              $secretLast4 = !empty($paymentSettings['secret_key']) ? substr($paymentSettings['secret_key'], -4) : '';
              $webhookLast4 = !empty($paymentSettings['webhook_secret']) ? substr($paymentSettings['webhook_secret'], -4) : '';
              $prices = SettingsService::getGlobal('payments.membership_prices', []);
            ?>
            <form method="post" class="space-y-6">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="payments">

              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Stripe Connection</h2>
                  <div class="flex items-center justify-between text-sm text-slate-600">
                    <span>Status</span>
                    <span class="font-semibold <?= !empty($paymentSettings['secret_key']) && !empty($paymentSettings['publishable_key']) ? 'text-green-600' : 'text-red-600' ?>">
                      <?= !empty($paymentSettings['secret_key']) && !empty($paymentSettings['publishable_key']) ? 'Connected' : 'Not connected' ?>
                    </span>
                  </div>
                  <div class="flex items-center justify-between text-sm text-slate-600">
                    <span>Mode</span>
                    <span class="font-semibold text-slate-900"><?= e($paymentSettings['mode'] ?? 'test') ?></span>
                  </div>
                  <label class="text-sm text-slate-600">Publishable key
                    <input name="stripe_publishable_key" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($paymentSettings['publishable_key'] ?? '') ?>">
                  </label>
                  <label class="text-sm text-slate-600">Secret key
                    <input name="stripe_secret_key" type="password" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="<?= $secretLast4 !== '' ? 'Configured (last 4: ' . e($secretLast4) . ')' : 'Not configured' ?>">
                    <span class="text-xs text-slate-500">Leave blank to keep current secret.</span>
                  </label>
                  <label class="text-sm text-slate-600">Webhook secret
                    <input name="stripe_webhook_secret" type="password" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="<?= $webhookLast4 !== '' ? 'Configured (last 4: ' . e($webhookLast4) . ')' : 'Not configured' ?>">
                  </label>
                  <div class="text-xs text-slate-500">
                    Last webhook: <?= e($paymentSettings['last_webhook_received_at'] ?? 'Never') ?><br>
                    Last error: <?= e($paymentSettings['last_webhook_error'] ?? 'None') ?>
                  </div>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Checkout Rules (Locked)</h2>
                  <div class="text-sm text-slate-600 space-y-2">
                    <div class="flex items-center justify-between">
                      <span>Stripe Checkout</span>
                      <span class="font-semibold text-slate-900">On</span>
                    </div>
                    <div class="flex items-center justify-between">
                      <span>Members-only checkout</span>
                      <span class="font-semibold text-slate-900">On</span>
                    </div>
                    <div class="flex items-center justify-between">
                      <span>Currency</span>
                      <span class="font-semibold text-slate-900">AUD</span>
                    </div>
                  </div>
                  <div class="pt-4 text-xs text-slate-500">Shipping rules follow Store Settings.</div>
                </div>
              </div>

              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                <h2 class="font-display text-lg font-bold text-gray-900">Tax Invoices</h2>
                <label class="flex items-center gap-3 text-sm text-slate-600">
                  <input type="checkbox" name="stripe_generate_pdf" class="rounded border-gray-200" <?= !empty($paymentSettings['generate_pdf']) ? 'checked' : '' ?>>
                  Generate PDF invoices
                </label>
                <label class="text-sm text-slate-600">Invoice prefix
                  <input name="stripe_invoice_prefix" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($paymentSettings['invoice_prefix'] ?? 'INV') ?>">
                </label>
                <label class="text-sm text-slate-600">Invoice email template
                  <textarea name="stripe_invoice_email_template" rows="3" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"><?= e($paymentSettings['invoice_email_template'] ?? '') ?></textarea>
                  <span class="text-xs text-slate-500">Optional. Supports tokens: {{invoice_number}}, {{invoice_date}}, {{total}}, {{download_url}}, {{download_link}}.</span>
                </label>
              </div>

              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                <h2 class="font-display text-lg font-bold text-gray-900">Membership Price IDs</h2>
                <div class="grid gap-4 md:grid-cols-2">
                  <label class="text-sm text-slate-600">FULL 1Y
                    <input name="price_full_1y" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($prices['FULL_1Y'] ?? '') ?>">
                  </label>
                  <label class="text-sm text-slate-600">FULL 3Y
                    <input name="price_full_3y" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($prices['FULL_3Y'] ?? '') ?>">
                  </label>
                  <label class="text-sm text-slate-600">ASSOCIATE 1Y
                    <input name="price_associate_1y" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($prices['ASSOCIATE_1Y'] ?? '') ?>">
                  </label>
                  <label class="text-sm text-slate-600">ASSOCIATE 3Y
                    <input name="price_associate_3y" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($prices['ASSOCIATE_3Y'] ?? '') ?>">
                  </label>
                  <label class="text-sm text-slate-600">LIFE
                    <input name="price_life" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($prices['LIFE'] ?? '') ?>">
                  </label>
                </div>
                <?php if (!SettingsService::isFeatureEnabled('payments.secondary_stripe')): ?>
                  <div class="text-xs text-slate-500">Secondary Stripe account (AGM) is disabled. Enable via feature flags.</div>
                <?php endif; ?>
              </div>

              <div class="flex items-center justify-between">
                <a class="text-sm text-slate-500" href="/admin/settings/index.php?section=payments">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save settings</button>
              </div>
            </form>
          <?php elseif ($section === 'notifications'): ?>
            <form method="post" class="space-y-6">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="notifications">
              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Sender Defaults</h2>
                  <label class="text-sm text-slate-600">From name
                    <input name="notify_from_name" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('notifications.from_name', '')) ?>">
                  </label>
                  <label class="text-sm text-slate-600">From email
                    <input name="notify_from_email" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('notifications.from_email', '')) ?>">
                  </label>
                  <label class="text-sm text-slate-600">Reply-to
                    <input name="notify_reply_to" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('notifications.reply_to', '')) ?>">
                  </label>
                  <p class="text-xs text-amber-600">SPF/DKIM/DMARC are not configured yet. Expect reduced deliverability until DNS records are added.</p>
                  <label class="text-sm text-slate-600">Admin notification emails
                    <textarea name="notify_admin_emails" rows="2" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"><?= e(SettingsService::getGlobal('notifications.admin_emails', '')) ?></textarea>
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="notify_weekly_digest" class="rounded border-gray-200" <?= SettingsService::getGlobal('notifications.weekly_digest_enabled', false) ? 'checked' : '' ?>>
                    Weekly digest enabled
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="notify_event_reminders" class="rounded border-gray-200" <?= SettingsService::getGlobal('notifications.event_reminders_enabled', true) ? 'checked' : '' ?>>
                    Event reminders enabled
                  </label>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">In-app Notifications</h2>
                  <label class="text-sm text-slate-600">Categories (comma-separated)
                    <input name="notify_categories" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(implode(', ', SettingsService::getGlobal('notifications.in_app_categories', []))) ?>">
                  </label>
                  <label class="text-sm text-slate-600">Template
                    <textarea name="notify_template_basic" rows="5" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"><?= e(SettingsService::getGlobal('notifications.template_basic', '')) ?></textarea>
                  </label>
                  <p class="text-xs text-slate-500">Template supports <code>{{body}}</code> for rendered content.</p>
                </div>
              </div>
              <?php
              $notificationDefinitions = NotificationService::definitions();
              $notificationCatalog = NotificationService::getCatalogSettings();
              $defaultNotificationKey = array_key_first($notificationDefinitions) ?? '';
              $recipientLabels = [
                  'primary' => 'Primary recipient',
                  'admin' => 'Admin emails',
                  'both' => 'Primary + admin',
                  'custom' => 'Custom list',
              ];
              ?>
              <input type="hidden" name="notification_active_key" id="notification-active-key" value="<?= e($defaultNotificationKey) ?>">
              <div class="space-y-4">
                <h2 class="font-display text-lg font-bold text-gray-900">Notification Templates</h2>
                <label class="text-sm text-slate-600">Select notification
                  <select id="notification-selector" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                    <?php foreach ($notificationDefinitions as $key => $definition): ?>
                      <option value="<?= e($key) ?>"><?= e($definition['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <?php $firstNotification = true; ?>
                <?php foreach ($notificationDefinitions as $key => $definition): ?>
                  <?php $settings = $notificationCatalog[$key] ?? ($definition['defaults'] ?? []); ?>
                  <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4 notification-panel<?= $firstNotification ? '' : ' hidden' ?>" data-notification-key="<?= e($key) ?>">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                      <div>
                        <h3 class="text-base font-semibold text-gray-900"><?= e($definition['label']) ?></h3>
                        <p class="text-xs text-slate-500"><?= e($definition['description']) ?></p>
                        <p class="text-xs text-slate-500">Trigger: <?= e($definition['trigger']) ?></p>
                      </div>
                      <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="notification_enabled[<?= e($key) ?>]" class="rounded border-gray-200" <?= !empty($settings['enabled']) ? 'checked' : '' ?>>
                        Enabled
                      </label>
                    </div>
                    <div class="grid gap-4 lg:grid-cols-2">
                      <label class="text-sm text-slate-600">Recipients
                        <select name="notification_recipient_mode[<?= e($key) ?>]" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                          <?php foreach ($recipientLabels as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($settings['recipient_mode'] ?? 'primary') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <label class="text-sm text-slate-600">Custom recipients (comma-separated)
                        <input name="notification_custom_recipients[<?= e($key) ?>]" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($settings['custom_recipients'] ?? '') ?>">
                      </label>
                    </div>
                    <div class="grid gap-4 lg:grid-cols-3">
                      <label class="text-sm text-slate-600">From name
                        <input name="notification_from_name[<?= e($key) ?>]" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($settings['from_name'] ?? '') ?>">
                      </label>
                      <label class="text-sm text-slate-600">From email
                        <input name="notification_from_email[<?= e($key) ?>]" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($settings['from_email'] ?? '') ?>">
                      </label>
                      <label class="text-sm text-slate-600">Reply-to
                        <input name="notification_reply_to[<?= e($key) ?>]" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($settings['reply_to'] ?? '') ?>">
                      </label>
                    </div>
                    <label class="text-sm text-slate-600">Subject
                      <input name="notification_subject[<?= e($key) ?>]" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($settings['subject'] ?? '') ?>">
                    </label>
                    <div class="space-y-2">
                      <p class="text-sm text-slate-600">Body (rich editor)</p>
                      <div class="flex flex-wrap gap-2 border border-gray-200 rounded-lg bg-gray-50 px-3 py-2 text-xs notification-toolbar" data-target="notification-body-<?= e($key) ?>">
                        <button type="button" data-command="formatBlock" data-arg="<h1>" class="rounded border border-gray-200 bg-white px-2 py-1">H1</button>
                        <button type="button" data-command="formatBlock" data-arg="<h2>" class="rounded border border-gray-200 bg-white px-2 py-1">H2</button>
                        <button type="button" data-command="formatBlock" data-arg="<h3>" class="rounded border border-gray-200 bg-white px-2 py-1">H3</button>
                        <button type="button" data-command="formatBlock" data-arg="<h4>" class="rounded border border-gray-200 bg-white px-2 py-1">H4</button>
                        <button type="button" data-command="bold" class="rounded border border-gray-200 bg-white px-2 py-1">Bold</button>
                        <button type="button" data-command="italic" class="rounded border border-gray-200 bg-white px-2 py-1">Italic</button>
                        <button type="button" data-command="underline" class="rounded border border-gray-200 bg-white px-2 py-1">Underline</button>
                        <button type="button" data-command="insertUnorderedList" class="rounded border border-gray-200 bg-white px-2 py-1">List</button>
                        <button type="button" data-command="createLink" class="rounded border border-gray-200 bg-white px-2 py-1">Link</button>
                        <button type="button" data-command="insertButton" class="rounded border border-gray-200 bg-white px-2 py-1">Button</button>
                        <button type="button" data-command="insertImage" class="rounded border border-gray-200 bg-white px-2 py-1">Image URL</button>
                        <label class="rounded border border-gray-200 bg-white px-2 py-1 cursor-pointer">
                          Upload image
                          <input type="file" class="hidden notification-image-upload" data-target="notification-body-<?= e($key) ?>" accept="image/*">
                        </label>
                      </div>
                      <textarea id="notification-body-<?= e($key) ?>" name="notification_body[<?= e($key) ?>]" class="hidden"><?= e($settings['body'] ?? '') ?></textarea>
                      <div class="notification-editor mt-2 min-h-[180px] rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm focus:outline-none" contenteditable="true" data-target="notification-body-<?= e($key) ?>"><?= $settings['body'] ?? '' ?></div>
                      <?php if (!empty($definition['placeholders'])): ?>
                        <p class="text-xs text-slate-500">Merge tags: <?= e(implode(', ', array_map(function ($item) { return '{{' . $item . '}}'; }, $definition['placeholders']))) ?></p>
                      <?php endif; ?>
                    </div>
                  </div>
                  <?php $firstNotification = false; ?>
                <?php endforeach; ?>
              </div>
              <div class="flex items-center justify-between">
                <a class="text-sm text-slate-500" href="/admin/settings/index.php?section=notifications">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save settings</button>
              </div>
            </form>
            <form method="post" class="mt-6">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="send_test_notification">
              <input type="hidden" name="section" value="notifications">
              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                <h2 class="font-display text-lg font-bold text-gray-900">Send Test Notification</h2>
                <label class="text-sm text-slate-600">Notification to test
                  <select name="test_notification_key" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                    <?php foreach ($notificationDefinitions as $key => $definition): ?>
                      <option value="<?= e($key) ?>"><?= e($definition['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="text-sm text-slate-600">Send test to (optional)
                  <input name="test_notification_email" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="you@example.com">
                </label>
                <p class="text-xs text-slate-500">Leave blank to use your admin email; admin recipients still apply based on template settings.</p>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Send test email</button>
              </div>
            </form>
            <script>
              (() => {
                const selector = document.getElementById('notification-selector');
                const activeKeyInput = document.getElementById('notification-active-key');
                const panels = Array.from(document.querySelectorAll('.notification-panel'));
                const editors = Array.from(document.querySelectorAll('.notification-editor'));
                let activeEditor = editors[0] || null;

                const showPanel = (key) => {
                  panels.forEach((panel) => {
                    panel.classList.toggle('hidden', panel.dataset.notificationKey !== key);
                  });
                  if (activeKeyInput) {
                    activeKeyInput.value = key;
                  }
                  const visible = panels.find((panel) => panel.dataset.notificationKey === key);
                  if (visible) {
                    const editor = visible.querySelector('.notification-editor');
                    if (editor) {
                      activeEditor = editor;
                    }
                  }
                };

                if (selector && panels.length) {
                  showPanel(selector.value);
                  selector.addEventListener('change', () => showPanel(selector.value));
                }

                const syncEditor = (editor) => {
                  const targetId = editor.dataset.target;
                  const target = document.getElementById(targetId);
                  if (target) {
                    target.value = editor.innerHTML;
                  }
                };

                editors.forEach((editor) => {
                  syncEditor(editor);
                  editor.addEventListener('focus', () => {
                    activeEditor = editor;
                  });
                  editor.addEventListener('input', () => syncEditor(editor));
                });

                const insertHtml = (editor, html) => {
                  if (!editor) {
                    return;
                  }
                  editor.focus();
                  document.execCommand('insertHTML', false, html);
                  syncEditor(editor);
                };

                document.querySelectorAll('.notification-toolbar').forEach((toolbar) => {
                  toolbar.addEventListener('click', (event) => {
                    const button = event.target.closest('button');
                    if (!button) {
                      return;
                    }
                    const command = button.dataset.command;
                    const arg = button.dataset.arg || null;
                    const editor = activeEditor || editors[0];
                    if (!command) {
                      return;
                    }
                    if (command === 'createLink') {
                      const url = prompt('Enter link URL');
                      if (url) {
                        if (editor) {
                          editor.focus();
                        }
                        document.execCommand('createLink', false, url);
                        syncEditor(editor);
                      }
                      return;
                    }
                    if (command === 'insertButton') {
                      const label = prompt('Button label');
                      const url = prompt('Button URL');
                      if (label && url) {
                        const html = '<a href="' + url + '" style="display:inline-block;background:#f59e0b;color:#111827;padding:10px 16px;border-radius:999px;text-decoration:none;font-weight:600;">' + label + '</a>';
                        insertHtml(editor, html);
                      }
                      return;
                    }
                    if (command === 'insertImage') {
                      const url = prompt('Image URL');
                      if (url) {
                        insertHtml(editor, '<img src=\"' + url + '\" alt=\"\" style=\"max-width:100%; height:auto;\">');
                      }
                      return;
                    }
                    if (command === 'formatBlock') {
                      document.execCommand('formatBlock', false, arg);
                      syncEditor(editor);
                      return;
                    }
                    document.execCommand(command, false, null);
                    syncEditor(editor);
                  });
                });

                document.querySelectorAll('.notification-image-upload').forEach((input) => {
                  input.addEventListener('change', async () => {
                    const file = input.files && input.files[0];
                    if (!file) {
                      return;
                    }
                    const form = new FormData();
                    form.append('file', file);
                    form.append('context', 'notifications');
                    const response = await fetch('/api/uploads/image', {
                      method: 'POST',
                      headers: {
                        'X-CSRF-TOKEN': '<?= e(Csrf::token()) ?>',
                      },
                      body: form,
                    });
                    const result = await response.json();
                    if (result && result.url) {
                      const targetId = input.dataset.target;
                      const editor = editors.find((item) => item.dataset.target === targetId) || activeEditor;
                      insertHtml(editor, '<img src=\"' + result.url + '\" alt=\"\" style=\"max-width:100%; height:auto;\">');
                    } else {
                      alert(result.error || 'Upload failed.');
                    }
                    input.value = '';
                  });
                });
              })();
            </script>
          <?php elseif ($section === 'accounts'): ?>
            <?php if (!SettingsService::isFeatureEnabled('accounts.roles')): ?>
              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 text-sm text-slate-600">
                Accounts & roles management is planned. Enable the feature flag in Advanced settings to unlock these controls.
              </div>
            <?php else: ?>
              <form method="post" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="section" value="accounts">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="accounts_user_approval" class="rounded border-gray-200" <?= SettingsService::getGlobal('accounts.user_approval_required', true) ? 'checked' : '' ?>>
                    Require admin approval for new users
                  </label>
                  <label class="text-sm text-slate-600">Membership status visibility
                    <select name="accounts_membership_visibility" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                      <?php $visibility = SettingsService::getGlobal('accounts.membership_status_visibility', 'member'); ?>
                      <option value="public" <?= $visibility === 'public' ? 'selected' : '' ?>>Public</option>
                      <option value="member" <?= $visibility === 'member' ? 'selected' : '' ?>>Members only</option>
                      <option value="admin" <?= $visibility === 'admin' ? 'selected' : '' ?>>Admin only</option>
                    </select>
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="accounts_audit_roles" class="rounded border-gray-200" <?= SettingsService::getGlobal('accounts.audit_role_changes', true) ? 'checked' : '' ?>>
                    Audit role changes
                  </label>
                </div>
                <div class="flex items-center justify-between">
                  <a class="text-sm text-slate-500" href="/admin/settings/index.php?section=accounts">Cancel</a>
                  <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save settings</button>
                </div>
              </form>
            <?php endif; ?>
          <?php elseif ($section === 'security'): ?>
            <form method="post" class="space-y-6">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="security">
              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Security & Authentication</h2>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="security_force_https" class="rounded border-gray-200" <?= SettingsService::getGlobal('security.force_https', false) ? 'checked' : '' ?>>
                    Enforce HTTPS
                  </label>
                  <label class="text-sm text-slate-600">Password minimum length
                    <input name="security_password_min" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) SettingsService::getGlobal('security.password_min_length', 12)) ?>">
                  </label>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">2FA Enforcement Policy</h2>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="twofa_enabled" class="rounded border-gray-200" <?= $securitySettings['enable_2fa'] ? 'checked' : '' ?>>
                    Enable 2FA
                  </label>
                  <label class="text-sm text-slate-600">Default enforcement mode
                    <select name="twofa_mode" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                      <option value="REQUIRED_FOR_ALL" <?= $securitySettings['twofa_mode'] === 'REQUIRED_FOR_ALL' ? 'selected' : '' ?>>Required for all</option>
                      <option value="REQUIRED_FOR_ROLES" <?= $securitySettings['twofa_mode'] === 'REQUIRED_FOR_ROLES' ? 'selected' : '' ?>>Required for selected roles</option>
                      <option value="OPTIONAL_FOR_ALL" <?= $securitySettings['twofa_mode'] === 'OPTIONAL_FOR_ALL' ? 'selected' : '' ?>>Optional for all</option>
                      <option value="DISABLED" <?= $securitySettings['twofa_mode'] === 'DISABLED' ? 'selected' : '' ?>>Disabled</option>
                    </select>
                  </label>
                  <label class="text-sm text-slate-600">Roles required (when mode is role-based)</label>
                  <div class="grid gap-2 text-sm text-slate-600">
                    <?php foreach (['super_admin','admin','store_manager','committee','treasurer','chapter_leader','member'] as $roleOption): ?>
                      <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="twofa_roles[]" value="<?= e($roleOption) ?>" class="rounded border-gray-200" <?= in_array($roleOption, $securitySettings['twofa_required_roles'], true) ? 'checked' : '' ?>>
                        <?= e($roleOption) ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <label class="text-sm text-slate-600">Grace period (days)
                    <input name="twofa_grace_days" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) $securitySettings['twofa_grace_days']) ?>">
                  </label>
                  <p class="text-xs text-slate-500">Per-user overrides are managed in the Members screen.</p>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Step-up Authentication</h2>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="stepup_enabled" class="rounded border-gray-200" <?= $securitySettings['stepup_enabled'] ? 'checked' : '' ?>>
                    Require step-up for sensitive actions
                  </label>
                  <label class="text-sm text-slate-600">Step-up window (minutes)
                    <input name="stepup_window_minutes" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) $securitySettings['stepup_window_minutes']) ?>">
                  </label>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Login Security</h2>
                  <label class="text-sm text-slate-600">Max attempts per IP
                    <input name="login_ip_max_attempts" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) $securitySettings['login_ip_max_attempts']) ?>">
                  </label>
                  <label class="text-sm text-slate-600">IP window (minutes)
                    <input name="login_ip_window_minutes" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) $securitySettings['login_ip_window_minutes']) ?>">
                  </label>
                  <label class="text-sm text-slate-600">Max attempts per account
                    <input name="login_account_max_attempts" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) $securitySettings['login_account_max_attempts']) ?>">
                  </label>
                  <label class="text-sm text-slate-600">Account window (minutes)
                    <input name="login_account_window_minutes" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) $securitySettings['login_account_window_minutes']) ?>">
                  </label>
                  <label class="text-sm text-slate-600">Lockout duration (minutes)
                    <input name="login_lockout_minutes" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) $securitySettings['login_lockout_minutes']) ?>">
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="login_progressive_delay" class="rounded border-gray-200" <?= $securitySettings['login_progressive_delay'] ? 'checked' : '' ?>>
                    Progressive delay enabled
                  </label>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Security Alerting</h2>
                  <label class="text-sm text-slate-600">Alert email
                    <input name="alert_email" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) $securitySettings['alert_email']) ?>">
                  </label>
                  <div class="grid gap-2 text-sm text-slate-600">
                    <label class="flex items-center gap-2">
                      <input type="checkbox" name="alert_failed_login" class="rounded border-gray-200" <?= !empty($securitySettings['alerts']['failed_login']) ? 'checked' : '' ?>>
                      Failed login attempts
                    </label>
                    <label class="flex items-center gap-2">
                      <input type="checkbox" name="alert_new_admin_device" class="rounded border-gray-200" <?= !empty($securitySettings['alerts']['new_admin_device']) ? 'checked' : '' ?>>
                      Admin login from new device
                    </label>
                    <label class="flex items-center gap-2">
                      <input type="checkbox" name="alert_refund_created" class="rounded border-gray-200" <?= !empty($securitySettings['alerts']['refund_created']) ? 'checked' : '' ?>>
                      Refunds created
                    </label>
                    <label class="flex items-center gap-2">
                      <input type="checkbox" name="alert_role_escalation" class="rounded border-gray-200" <?= !empty($securitySettings['alerts']['role_escalation']) ? 'checked' : '' ?>>
                      Role escalations
                    </label>
                    <label class="flex items-center gap-2">
                      <input type="checkbox" name="alert_member_export" class="rounded border-gray-200" <?= !empty($securitySettings['alerts']['member_export']) ? 'checked' : '' ?>>
                      Member data exports
                    </label>
                    <label class="flex items-center gap-2">
                      <input type="checkbox" name="alert_fim_changes" class="rounded border-gray-200" <?= !empty($securitySettings['alerts']['fim_changes']) ? 'checked' : '' ?>>
                      File integrity changes
                    </label>
                    <label class="flex items-center gap-2">
                      <input type="checkbox" name="alert_webhook_failure" class="rounded border-gray-200" <?= !empty($securitySettings['alerts']['webhook_failure']) ? 'checked' : '' ?>>
                      Stripe webhook failures
                    </label>
                  </div>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">File Integrity Monitoring</h2>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="fim_enabled" class="rounded border-gray-200" <?= $securitySettings['fim_enabled'] ? 'checked' : '' ?>>
                    Enable file integrity monitoring
                  </label>
                  <p class="text-xs text-slate-500">Recommended cron frequency: hourly or nightly.</p>
                  <label class="text-sm text-slate-600">Directories to monitor (comma or newline)
                    <textarea name="fim_paths" rows="3" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"><?= e(implode("\n", $securitySettings['fim_paths'])) ?></textarea>
                  </label>
                  <label class="text-sm text-slate-600">Exclude paths
                    <textarea name="fim_exclude_paths" rows="2" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"><?= e(implode("\n", $securitySettings['fim_exclude_paths'])) ?></textarea>
                  </label>
                  <button type="submit" name="approve_fim_baseline" value="1" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700">Approve baseline</button>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Stripe Webhook Monitoring</h2>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="webhook_alerts_enabled" class="rounded border-gray-200" <?= $securitySettings['webhook_alerts_enabled'] ? 'checked' : '' ?>>
                    Alert on webhook failures
                  </label>
                  <label class="text-sm text-slate-600">Alert threshold
                    <input name="webhook_alert_threshold" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) $securitySettings['webhook_alert_threshold']) ?>">
                  </label>
                  <label class="text-sm text-slate-600">Window (minutes)
                    <input name="webhook_alert_window_minutes" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) $securitySettings['webhook_alert_window_minutes']) ?>">
                  </label>
                </div>
              </div>
              <div class="flex items-center justify-between">
                <a class="text-sm text-slate-500" href="/admin/settings/index.php?section=security">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save settings</button>
              </div>
            </form>
          <?php elseif ($section === 'integrations'): ?>
            <form method="post" class="space-y-6">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="integrations">
              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Email Provider</h2>
                  <label class="text-sm text-slate-600">Provider
                    <select name="integrations_email_provider" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                      <?php $provider = SettingsService::getGlobal('integrations.email_provider', 'php_mail'); ?>
                      <option value="php_mail" <?= $provider === 'php_mail' ? 'selected' : '' ?>>PHP Mail</option>
                      <option value="smtp" <?= $provider === 'smtp' ? 'selected' : '' ?>>SMTP</option>
                      <option value="mailgun" <?= $provider === 'mailgun' ? 'selected' : '' ?>>Mailgun</option>
                    </select>
                  </label>
                  <label class="text-sm text-slate-600">SMTP host
                    <input name="integrations_smtp_host" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('integrations.smtp_host', '')) ?>">
                  </label>
                  <label class="text-sm text-slate-600">SMTP port
                    <input name="integrations_smtp_port" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) SettingsService::getGlobal('integrations.smtp_port', 587)) ?>">
                  </label>
                  <label class="text-sm text-slate-600">SMTP username
                    <input name="integrations_smtp_user" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('integrations.smtp_user', '')) ?>">
                  </label>
                  <label class="text-sm text-slate-600">SMTP password
                    <input name="integrations_smtp_password" type="password" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="">
                  </label>
                  <label class="text-sm text-slate-600">SMTP encryption
                    <select name="integrations_smtp_encryption" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                      <?php $smtpEnc = SettingsService::getGlobal('integrations.smtp_encryption', 'tls'); ?>
                      <option value="tls" <?= $smtpEnc === 'tls' ? 'selected' : '' ?>>TLS</option>
                      <option value="ssl" <?= $smtpEnc === 'ssl' ? 'selected' : '' ?>>SSL</option>
                      <option value="none" <?= $smtpEnc === 'none' ? 'selected' : '' ?>>None</option>
                    </select>
                  </label>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Media Embeds</h2>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="integrations_youtube" class="rounded border-gray-200" <?= SettingsService::getGlobal('integrations.youtube_embeds_enabled', true) ? 'checked' : '' ?>>
                    YouTube embeds enabled
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="integrations_vimeo" class="rounded border-gray-200" <?= SettingsService::getGlobal('integrations.vimeo_embeds_enabled', true) ? 'checked' : '' ?>>
                    Vimeo embeds enabled
                  </label>
                  <label class="text-sm text-slate-600">Zoom default URL
                    <input name="integrations_zoom_default" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('integrations.zoom_default_url', '')) ?>">
                  </label>
                  <?php if (SettingsService::isFeatureEnabled('integrations.myob')): ?>
                    <label class="flex items-center gap-3 text-sm text-slate-600">
                      <input type="checkbox" name="integrations_myob_enabled" class="rounded border-gray-200" <?= SettingsService::getGlobal('integrations.myob_enabled', false) ? 'checked' : '' ?>>
                      MYOB integration enabled
                    </label>
                  <?php else: ?>
                    <p class="text-xs text-slate-500">MYOB integration is disabled. Enable via feature flags.</p>
                  <?php endif; ?>
                </div>
              </div>
              <div class="flex items-center justify-between">
                <a class="text-sm text-slate-500" href="/admin/settings/index.php?section=integrations">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save settings</button>
              </div>
            </form>
          <?php elseif ($section === 'media'): ?>
            <form method="post" class="space-y-6">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="media">
              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Upload Limits</h2>
                  <label class="text-sm text-slate-600">Allowed MIME types
                    <textarea name="media_allowed_types" rows="4" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"><?= e(implode(', ', SettingsService::getGlobal('media.allowed_types', []))) ?></textarea>
                  </label>
                  <label class="text-sm text-slate-600">Max upload (MB)
                    <input name="media_max_upload" type="number" step="0.1" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) SettingsService::getGlobal('media.max_upload_mb', 10)) ?>">
                  </label>
                  <label class="text-sm text-slate-600">Storage limit (MB)
                    <input name="media_storage_limit" type="number" step="1" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) SettingsService::getGlobal('media.storage_limit_mb', 5120)) ?>">
                  </label>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Defaults</h2>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="media_image_opt" class="rounded border-gray-200" <?= SettingsService::getGlobal('media.image_optimization_enabled', false) ? 'checked' : '' ?>>
                    Enable image optimization on upload
                  </label>
                  <label class="text-sm text-slate-600">Default privacy
                    <select name="media_privacy_default" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                      <?php $privacy = SettingsService::getGlobal('media.privacy_default', 'member'); ?>
                      <option value="public" <?= $privacy === 'public' ? 'selected' : '' ?>>Public</option>
                      <option value="member" <?= $privacy === 'member' ? 'selected' : '' ?>>Member</option>
                      <option value="admin" <?= $privacy === 'admin' ? 'selected' : '' ?>>Admin only</option>
                    </select>
                  </label>
                  <?php if (SettingsService::isFeatureEnabled('media.folder_taxonomy')): ?>
                    <label class="text-sm text-slate-600">Folder taxonomy
                      <textarea name="media_folder_taxonomy" rows="3" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"><?= e(implode(', ', SettingsService::getGlobal('media.folder_taxonomy', []))) ?></textarea>
                    </label>
                  <?php else: ?>
                    <p class="text-xs text-slate-500">Folder taxonomy is disabled. Enable via feature flags.</p>
                  <?php endif; ?>
                </div>
              </div>
              <div class="flex items-center justify-between">
                <a class="text-sm text-slate-500" href="/admin/settings/index.php?section=media">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save settings</button>
              </div>
            </form>
          <?php elseif ($section === 'events'): ?>
            <form method="post" class="space-y-6">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="events">
              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Defaults</h2>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="events_rsvp_default" class="rounded border-gray-200" <?= SettingsService::getGlobal('events.rsvp_default_enabled', true) ? 'checked' : '' ?>>
                    RSVP enabled by default
                  </label>
                  <label class="text-sm text-slate-600">Visibility default
                    <select name="events_visibility_default" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                      <?php $visibility = SettingsService::getGlobal('events.visibility_default', 'member'); ?>
                      <option value="public" <?= $visibility === 'public' ? 'selected' : '' ?>>Public</option>
                      <option value="member" <?= $visibility === 'member' ? 'selected' : '' ?>>Member</option>
                    </select>
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="events_public_ticketing" class="rounded border-gray-200" <?= SettingsService::getGlobal('events.public_ticketing_enabled', false) ? 'checked' : '' ?>>
                    Allow public ticket purchasing
                  </label>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Fields</h2>
                  <label class="text-sm text-slate-600">Timezone
                    <input name="events_timezone" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(SettingsService::getGlobal('events.timezone', 'Australia/Sydney')) ?>">
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="events_include_map" class="rounded border-gray-200" <?= SettingsService::getGlobal('events.include_map_link', true) ? 'checked' : '' ?>>
                    Include map link field
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="events_include_zoom" class="rounded border-gray-200" <?= SettingsService::getGlobal('events.include_zoom_link', true) ? 'checked' : '' ?>>
                    Include Zoom link field
                  </label>
                  <a class="text-sm text-blue-600" href="/admin/index.php?page=events">Go to Events</a>
                </div>
              </div>
              <div class="flex items-center justify-between">
                <a class="text-sm text-slate-500" href="/admin/settings/index.php?section=events">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save settings</button>
              </div>
            </form>
          <?php elseif ($section === 'membership_pricing'): ?>
            <?php
              $pricing = MembershipPricingService::getMembershipPricing();
              $pricingMatrix = $pricing['matrix'] ?? [];
              $periods = MembershipPricingService::periodDefinitions();
              $fieldErrors = $fieldErrors ?? [];
              $idFieldErrors = $idFieldErrors ?? [];
              $chapterFieldErrors = $chapterFieldErrors ?? [];
              $memberNumberStart = (int) SettingsService::getGlobal('membership.member_number_start', 1000);
              $associateSuffixStart = (int) SettingsService::getGlobal('membership.associate_suffix_start', 1);
              $memberNumberFormatFull = SettingsService::getGlobal('membership.member_number_format_full', '{base}');
              $memberNumberFormatAssociate = SettingsService::getGlobal('membership.member_number_format_associate', '{base}.{suffix}');
              $memberNumberBasePadding = (int) SettingsService::getGlobal('membership.member_number_base_padding', 0);
              $memberNumberSuffixPadding = (int) SettingsService::getGlobal('membership.member_number_suffix_padding', 0);
              $manualMigrationEnabled = (bool) SettingsService::getGlobal('membership.manual_migration_enabled', true);
              $manualMigrationExpiryDays = (int) SettingsService::getGlobal('membership.manual_migration_expiry_days', 14);
              $chaptersPdo = db();
              $chaptersHasState = ChapterRepository::hasColumn($chaptersPdo, 'state');
              $chaptersHasActive = ChapterRepository::hasColumn($chaptersPdo, 'is_active');
              $chaptersHasSortOrder = ChapterRepository::hasColumn($chaptersPdo, 'sort_order');
              $chapters = ChapterRepository::listForManagement($chaptersPdo);
            ?>
            <form method="post" class="space-y-6">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="membership_pricing">
              <input type="hidden" name="reset_defaults" id="reset-membership-defaults" value="0">

              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-3">
                <div class="flex items-start justify-between gap-4">
                  <div>
                    <h2 class="font-display text-lg font-bold text-gray-900">Membership Settings</h2>
                    <p class="text-sm text-slate-500">Manage pricing, member ID sequencing, and chapters.</p>
                  </div>
                </div>
                <p class="text-xs text-slate-500"><?= e(MembershipPricingService::pricingNote()) ?></p>
              </div>

              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                <div>
                  <h2 class="font-display text-lg font-bold text-gray-900">Member ID Sequencing</h2>
                  <p class="text-sm text-slate-500">Control the starting number and format for full and associate member IDs.</p>
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                  <label class="text-sm text-slate-600">Full member ID start
                    <input
                      name="member_number_start"
                      type="number"
                      min="1"
                      class="mt-2 w-full rounded-lg border <?= isset($idFieldErrors['member_number_start']) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-3 py-2 text-sm"
                      value="<?= e((string) $memberNumberStart) ?>"
                      required
                    >
                    <?php if (isset($idFieldErrors['member_number_start'])): ?>
                      <div class="text-xs text-red-600 mt-1"><?= e($idFieldErrors['member_number_start']) ?></div>
                    <?php endif; ?>
                  </label>
                  <label class="text-sm text-slate-600">Associate suffix start
                    <input
                      name="associate_suffix_start"
                      type="number"
                      min="1"
                      class="mt-2 w-full rounded-lg border <?= isset($idFieldErrors['associate_suffix_start']) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-3 py-2 text-sm"
                      value="<?= e((string) $associateSuffixStart) ?>"
                      required
                    >
                    <?php if (isset($idFieldErrors['associate_suffix_start'])): ?>
                      <div class="text-xs text-red-600 mt-1"><?= e($idFieldErrors['associate_suffix_start']) ?></div>
                    <?php endif; ?>
                  </label>
                  <label class="text-sm text-slate-600">Full member ID format
                    <input
                      name="member_number_format_full"
                      type="text"
                      class="mt-2 w-full rounded-lg border <?= isset($idFieldErrors['member_number_format_full']) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-3 py-2 text-sm"
                      value="<?= e((string) $memberNumberFormatFull) ?>"
                      placeholder="{base}"
                      required
                    >
                    <?php if (isset($idFieldErrors['member_number_format_full'])): ?>
                      <div class="text-xs text-red-600 mt-1"><?= e($idFieldErrors['member_number_format_full']) ?></div>
                    <?php endif; ?>
                  </label>
                  <label class="text-sm text-slate-600">Associate member ID format
                    <input
                      name="member_number_format_associate"
                      type="text"
                      class="mt-2 w-full rounded-lg border <?= isset($idFieldErrors['member_number_format_associate']) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-3 py-2 text-sm"
                      value="<?= e((string) $memberNumberFormatAssociate) ?>"
                      placeholder="{base}.{suffix}"
                      required
                    >
                    <?php if (isset($idFieldErrors['member_number_format_associate'])): ?>
                      <div class="text-xs text-red-600 mt-1"><?= e($idFieldErrors['member_number_format_associate']) ?></div>
                    <?php endif; ?>
                  </label>
                  <label class="text-sm text-slate-600">Base padding digits
                    <input
                      name="member_number_base_padding"
                      type="number"
                      min="0"
                      max="12"
                      class="mt-2 w-full rounded-lg border <?= isset($idFieldErrors['member_number_base_padding']) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-3 py-2 text-sm"
                      value="<?= e((string) $memberNumberBasePadding) ?>"
                    >
                    <?php if (isset($idFieldErrors['member_number_base_padding'])): ?>
                      <div class="text-xs text-red-600 mt-1"><?= e($idFieldErrors['member_number_base_padding']) ?></div>
                    <?php endif; ?>
                  </label>
                  <label class="text-sm text-slate-600">Suffix padding digits
                    <input
                      name="member_number_suffix_padding"
                      type="number"
                      min="0"
                      max="12"
                      class="mt-2 w-full rounded-lg border <?= isset($idFieldErrors['member_number_suffix_padding']) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-3 py-2 text-sm"
                      value="<?= e((string) $memberNumberSuffixPadding) ?>"
                    >
                    <?php if (isset($idFieldErrors['member_number_suffix_padding'])): ?>
                      <div class="text-xs text-red-600 mt-1"><?= e($idFieldErrors['member_number_suffix_padding']) ?></div>
                    <?php endif; ?>
                  </label>
                </div>
                <p class="text-xs text-slate-500">Tokens: {base}, {suffix}, {base_padded}, {suffix_padded}. Use padding digits to left-pad numbers.</p>
              </div>

              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                <div>
                  <h2 class="font-display text-lg font-bold text-gray-900">Manual Migration</h2>
                  <p class="text-sm text-slate-500">Control manual migration link availability and expiry windows.</p>
                </div>
                <label class="flex items-center gap-3 text-sm text-slate-600">
                  <input type="checkbox" name="manual_migration_enabled" class="rounded border-gray-200" <?= $manualMigrationEnabled ? 'checked' : '' ?>>
                  Enable manual migration links
                </label>
                <label class="text-sm text-slate-600">Link expiry (days)
                  <input
                    name="manual_migration_expiry_days"
                    type="number"
                    min="1"
                    max="60"
                    class="mt-2 w-full rounded-lg border <?= isset($idFieldErrors['manual_migration_expiry_days']) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-3 py-2 text-sm"
                    value="<?= e((string) $manualMigrationExpiryDays) ?>"
                  >
                  <?php if (isset($idFieldErrors['manual_migration_expiry_days'])): ?>
                    <div class="text-xs text-red-600 mt-1"><?= e($idFieldErrors['manual_migration_expiry_days']) ?></div>
                  <?php endif; ?>
                </label>
              </div>

              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                <div>
                  <h2 class="font-display text-lg font-bold text-gray-900">Chapters</h2>
                  <p class="text-sm text-slate-500">Create chapters and control their display order.</p>
                </div>
                <div class="overflow-x-auto">
                  <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500 border-b">
                      <tr>
                        <?php if ($chaptersHasSortOrder): ?>
                          <th class="py-2 pr-3">Order</th>
                        <?php endif; ?>
                        <th class="py-2 pr-3">Name</th>
                        <?php if ($chaptersHasState): ?>
                          <th class="py-2 pr-3">State / Region</th>
                        <?php endif; ?>
                        <?php if ($chaptersHasActive): ?>
                          <th class="py-2 pr-3">Active</th>
                        <?php endif; ?>
                      </tr>
                    </thead>
                    <tbody class="divide-y">
                      <?php foreach ($chapters as $chapter): ?>
                        <tr>
                          <?php if ($chaptersHasSortOrder): ?>
                            <td class="py-3 pr-3">
                              <input
                                type="number"
                                name="chapters[<?= e((string) $chapter['id']) ?>][sort_order]"
                                class="w-20 rounded-lg border <?= isset($chapterFieldErrors[$chapter['id']]['sort_order']) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-2 py-1 text-sm"
                                value="<?= e((string) ($chapter['sort_order'] ?? 0)) ?>"
                                min="0"
                              >
                              <?php if (isset($chapterFieldErrors[$chapter['id']]['sort_order'])): ?>
                                <div class="text-xs text-red-600 mt-1"><?= e($chapterFieldErrors[$chapter['id']]['sort_order']) ?></div>
                              <?php endif; ?>
                            </td>
                          <?php endif; ?>
                          <td class="py-3 pr-3">
                            <input
                              type="text"
                              name="chapters[<?= e((string) $chapter['id']) ?>][name]"
                              class="w-full rounded-lg border <?= isset($chapterFieldErrors[$chapter['id']]['name']) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-2 py-1 text-sm"
                              value="<?= e((string) ($chapter['name'] ?? '')) ?>"
                              required
                            >
                            <?php if (isset($chapterFieldErrors[$chapter['id']]['name'])): ?>
                              <div class="text-xs text-red-600 mt-1"><?= e($chapterFieldErrors[$chapter['id']]['name']) ?></div>
                            <?php endif; ?>
                          </td>
                          <?php if ($chaptersHasState): ?>
                            <td class="py-3 pr-3">
                              <input
                                type="text"
                                name="chapters[<?= e((string) $chapter['id']) ?>][state]"
                                class="w-full rounded-lg border border-gray-200 bg-white px-2 py-1 text-sm"
                                value="<?= e((string) ($chapter['state'] ?? '')) ?>"
                              >
                            </td>
                          <?php endif; ?>
                          <?php if ($chaptersHasActive): ?>
                            <td class="py-3 pr-3">
                              <input
                                type="checkbox"
                                name="chapters[<?= e((string) $chapter['id']) ?>][is_active]"
                                class="rounded border-gray-200"
                                <?= !empty($chapter['is_active']) ? 'checked' : '' ?>
                              >
                            </td>
                          <?php endif; ?>
                        </tr>
                      <?php endforeach; ?>
                      <tr>
                        <?php if ($chaptersHasSortOrder): ?>
                          <td class="py-3 pr-3">
                            <input
                              type="number"
                              name="new_chapter_sort_order"
                              class="w-20 rounded-lg border border-gray-200 bg-white px-2 py-1 text-sm"
                              placeholder="Auto"
                              min="0"
                            >
                          </td>
                        <?php endif; ?>
                        <td class="py-3 pr-3">
                          <input
                            type="text"
                            name="new_chapter_name"
                            class="w-full rounded-lg border <?= isset($chapterFieldErrors['new']['name']) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-2 py-1 text-sm"
                            placeholder="Add new chapter"
                          >
                          <?php if (isset($chapterFieldErrors['new']['name'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= e($chapterFieldErrors['new']['name']) ?></div>
                          <?php endif; ?>
                        </td>
                        <?php if ($chaptersHasState): ?>
                          <td class="py-3 pr-3">
                            <input
                              type="text"
                              name="new_chapter_state"
                              class="w-full rounded-lg border border-gray-200 bg-white px-2 py-1 text-sm"
                              placeholder="State / Region"
                            >
                          </td>
                        <?php endif; ?>
                        <?php if ($chaptersHasActive): ?>
                          <td class="py-3 pr-3">
                            <input type="checkbox" name="new_chapter_active" class="rounded border-gray-200" checked>
                          </td>
                        <?php endif; ?>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <p class="text-xs text-slate-500">Order controls the chapter list sequence on member-facing forms.</p>
              </div>

              <div class="bg-card-light rounded-2xl border border-gray-100 p-6">
                <div class="overflow-x-auto">
                  <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500 border-b">
                      <tr>
                        <th class="py-2 pr-3">Magazine / Membership</th>
                        <?php foreach ($periods as $meta): ?>
                          <th class="py-2 pr-3"><?= e($meta['label']) ?></th>
                        <?php endforeach; ?>
                      </tr>
                    </thead>
                    <tbody class="divide-y">
                      <?php foreach (MembershipPricingService::MAGAZINE_TYPES as $magazineType): ?>
                        <?php foreach (MembershipPricingService::MEMBERSHIP_TYPES as $membershipType): ?>
                          <tr>
                            <td class="py-3 pr-3 text-gray-700 font-semibold">
                              <?= e($magazineType === 'PRINTED' ? 'Printed — ' : 'PDF Only — ') ?><?= e(ucfirst(strtolower($membershipType))) ?>
                            </td>
                            <?php foreach (array_keys($periods) as $periodKey): ?>
                              <?php
                                $fieldKey = $magazineType . '.' . $membershipType . '.' . $periodKey;
                                $valueCents = $pricingMatrix[$magazineType][$membershipType][$periodKey] ?? null;
                              ?>
                              <td class="py-3 pr-3 align-top">
                                <label class="flex items-center gap-2">
                                  <span class="text-slate-500">$</span>
                                  <input
                                    name="pricing[<?= e($magazineType) ?>][<?= e($membershipType) ?>][<?= e($periodKey) ?>]"
                                    type="text"
                                    inputmode="decimal"
                                    class="w-24 rounded-lg border <?= isset($fieldErrors[$fieldKey]) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-2 py-1 text-sm"
                                    value="<?= e(format_cents_to_dollars($valueCents)) ?>"
                                    required
                                  >
                                </label>
                                <?php if (isset($fieldErrors[$fieldKey])): ?>
                                  <div class="text-xs text-red-600 mt-1"><?= e($fieldErrors[$fieldKey]) ?></div>
                                <?php endif; ?>
                              </td>
                            <?php endforeach; ?>
                          </tr>
                        <?php endforeach; ?>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="flex items-center justify-between">
                <button type="button" id="reset-membership-button" class="inline-flex items-center gap-2 rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-600">Reset to defaults</button>
                <div class="flex items-center gap-3">
                  <a class="text-sm text-slate-500" href="/admin/settings/index.php?section=membership_pricing">Cancel</a>
                  <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save changes</button>
                </div>
              </div>
            </form>

            <div id="reset-membership-modal" class="fixed inset-0 hidden items-center justify-center bg-black/40 p-4">
              <div class="bg-white rounded-2xl p-6 max-w-sm w-full shadow-card">
                <h3 class="font-display text-lg font-bold text-gray-900">Reset membership pricing?</h3>
                <p class="text-sm text-slate-600 mt-2">This restores the default pricing matrix for Printed and PDF memberships.</p>
                <div class="flex justify-end gap-2 mt-6">
                  <button type="button" id="reset-membership-cancel" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-slate-600">Cancel</button>
                  <button type="button" id="reset-membership-confirm" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white">Reset</button>
                </div>
              </div>
            </div>

            <script>
              (function () {
                const resetButton = document.getElementById('reset-membership-button');
                const resetModal = document.getElementById('reset-membership-modal');
                const resetCancel = document.getElementById('reset-membership-cancel');
                const resetConfirm = document.getElementById('reset-membership-confirm');
                const resetInput = document.getElementById('reset-membership-defaults');
                const form = resetButton ? resetButton.closest('form') : null;

                if (!resetButton || !resetModal || !resetCancel || !resetConfirm || !resetInput || !form) {
                  return;
                }

                resetButton.addEventListener('click', () => {
                  resetModal.classList.remove('hidden');
                  resetModal.classList.add('flex');
                });

                resetCancel.addEventListener('click', () => {
                  resetModal.classList.add('hidden');
                  resetModal.classList.remove('flex');
                });

                resetConfirm.addEventListener('click', () => {
                  resetInput.value = '1';
                  form.submit();
                });
              })();
            </script>
          <?php elseif ($section === 'audit'): ?>
            <?php
              $actionFilter = normalize_text($_GET['action'] ?? '');
              $actorFilter = normalize_text($_GET['actor'] ?? '');
              $params = [];
              $sql = 'SELECT a.*, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id';
              $conditions = [];
              if ($actionFilter !== '') {
                  $conditions[] = 'a.action = :action';
                  $params['action'] = $actionFilter;
              }
              if ($actorFilter !== '') {
                  $conditions[] = 'u.name LIKE :actor';
                  $params['actor'] = '%' . $actorFilter . '%';
              }
              if ($conditions) {
                  $sql .= ' WHERE ' . implode(' AND ', $conditions);
              }
              $sql .= ' ORDER BY a.created_at DESC LIMIT 100';
              $stmt = $pdo->prepare($sql);
              $stmt->execute($params);
              $auditRows = $stmt->fetchAll();
            ?>
            <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
              <form method="get" class="flex flex-wrap gap-3">
                <input type="hidden" name="section" value="audit">
                <input name="action" placeholder="Action" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($actionFilter) ?>">
                <input name="actor" placeholder="Actor" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($actorFilter) ?>">
                <button class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink" type="submit">Filter</button>
              </form>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead class="text-left text-xs uppercase text-slate-500 border-b">
                    <tr>
                      <th class="py-2 pr-3">Time</th>
                      <th class="py-2 pr-3">Actor</th>
                      <th class="py-2 pr-3">Action</th>
                      <th class="py-2 pr-3">Entity</th>
                      <th class="py-2">Diff</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y">
                    <?php foreach ($auditRows as $row): ?>
                      <tr>
                        <td class="py-2 pr-3 text-slate-600"><?= e($row['created_at']) ?></td>
                        <td class="py-2 pr-3 text-slate-900"><?= e($row['name'] ?? 'System') ?></td>
                        <td class="py-2 pr-3 text-slate-600"><?= e($row['action']) ?></td>
                        <td class="py-2 pr-3 text-slate-600"><?= e($row['entity_type']) ?> #<?= e((string) ($row['entity_id'] ?? '')) ?></td>
                        <td class="py-2">
                          <details class="text-xs text-slate-600">
                            <summary class="cursor-pointer">View</summary>
                            <pre class="mt-2 whitespace-pre-wrap"><?= e(json_encode(json_decode($row['diff_json'] ?? 'null', true), JSON_PRETTY_PRINT)) ?></pre>
                          </details>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (!$auditRows): ?>
                      <tr><td colspan="5" class="py-4 text-center text-slate-500">No audit entries found.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php elseif ($section === 'advanced'): ?>
            <?php $flags = SettingsService::getGlobal('advanced.feature_flags', []); ?>
            <form method="post" class="space-y-6">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="advanced">
              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">System</h2>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="advanced_maintenance" class="rounded border-gray-200" <?= SettingsService::getGlobal('advanced.maintenance_mode', false) ? 'checked' : '' ?>>
                    Maintenance mode
                  </label>
                  <p class="text-xs text-slate-500">Maintenance mode blocks non-admin access.</p>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-3">
                  <h2 class="font-display text-lg font-bold text-gray-900">Feature Flags</h2>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="flag_security_two_factor" class="rounded border-gray-200" <?= !empty($flags['security.two_factor']) ? 'checked' : '' ?>>
                    Security: 2FA
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="flag_payments_secondary" class="rounded border-gray-200" <?= !empty($flags['payments.secondary_stripe']) ? 'checked' : '' ?>>
                    Payments: Secondary Stripe account
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="flag_integrations_myob" class="rounded border-gray-200" <?= !empty($flags['integrations.myob']) ? 'checked' : '' ?>>
                    Integrations: MYOB
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="flag_accounts_roles" class="rounded border-gray-200" <?= !empty($flags['accounts.roles']) ? 'checked' : '' ?>>
                    Accounts: Roles management
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input type="checkbox" name="flag_media_folder_taxonomy" class="rounded border-gray-200" <?= !empty($flags['media.folder_taxonomy']) ? 'checked' : '' ?>>
                    Media: Folder taxonomy
                  </label>
                </div>
              </div>
              <div class="flex items-center justify-between">
                <a class="text-sm text-slate-500" href="/admin/settings/index.php?section=advanced">Cancel</a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Save settings</button>
              </div>
            </form>
          <?php endif; ?>
      </section>
    </div>
  </main>
</div>
<script>
  const toast = document.getElementById('toast');
  if (toast) {
    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transition = 'opacity 0.4s ease';
    }, 2000);
  }
</script>
