<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\BaseUrlService;
use App\Services\MembershipPricingService;
use App\Services\PaymentSettingsService;
use App\Services\NotificationService;
use App\Services\SecuritySettingsService;
use App\Services\SettingsService;
use App\Services\LogViewerService;
use App\Services\StripeSettingsService;
use App\Services\ChapterRepository;
use App\Services\Validator;

require_login();

$user = current_user();

$sections = [
    'site' => ['label' => 'Site Settings', 'permission' => 'admin.settings.general.manage'],
    'store' => ['label' => 'Store Settings', 'permission' => 'admin.store.view'],
    'payments' => ['label' => 'Payments (Stripe)', 'permission' => 'admin.payments.view'],
    'notifications' => ['label' => 'Notifications', 'permission' => 'admin.settings.general.manage'],
    'security' => ['label' => 'Security & Authentication', 'permission' => 'admin.settings.general.manage'],
    'integrations' => ['label' => 'Integrations', 'permission' => 'admin.integrations.manage'],
    'media' => ['label' => 'Media & Files', 'permission' => 'admin.media_library.manage'],
    'events' => ['label' => 'Events', 'permission' => 'admin.events.manage'],
    'membership_pricing' => ['label' => 'Membership Settings', 'permission' => 'admin.membership_types.manage'],
    'advanced' => ['label' => 'Advanced / Developer', 'permission' => 'admin.settings.general.manage'],
];

function can_access_section(string $permission, array $user): bool
{
    return function_exists('current_admin_can') && current_admin_can($permission, $user);
}

$section = $_GET['section'] ?? 'hub';
if ($section !== 'hub' && !isset($sections[$section])) {
    $section = 'hub';
}

// The Audit Log section has been folded into the unified Audit Hub.
if ($section === 'audit') {
    header('Location: /admin/audit/?source=settings');
    exit;
}

if ($section !== 'hub' && !can_access_section($sections[$section]['permission'], $user)) {
    admin_render_forbidden();
}

SettingsService::migrateLegacy((int) $user['id']);
SettingsService::ensureDefaults((int) $user['id']);
$securitySettings = SecuritySettingsService::get();
$systemLog = null;
$emailLogRows = [];
$emailLogError = null;
if ($section === 'advanced') {
    $systemLog = LogViewerService::readTail(300);
    try {
        $pdo = db();
        $stmt = $pdo->query("SHOW TABLES LIKE 'email_log'");
        if ($stmt->fetchColumn()) {
            $stmt = $pdo->query('SELECT recipient, subject, sent, created_at FROM email_log ORDER BY created_at DESC LIMIT 50');
            $emailLogRows = $stmt->fetchAll();
        } else {
            $emailLogError = 'email_log table not found.';
        }
    } catch (Throwable $e) {
        $emailLogError = 'Unable to load email log.';
    }
}

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
    } elseif ($action === 'clear_system_log') {
        require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/settings/index.php');
        $postedSection = $_POST['section'] ?? '';
        if ($postedSection !== $section) {
            $errors[] = 'Invalid settings section.';
        } elseif (!can_access_section($sections[$section]['permission'], $user)) {
            $errors[] = 'Unauthorized.';
        } else {
            $cleared = LogViewerService::clear();
            $toast = $cleared ? 'System log cleared.' : 'Unable to clear system log.';
        }
    } elseif ($action === 'send_test_notification') {
        if ($section !== 'payments') {
            require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/settings/index.php');
        }
        $postedSection = $_POST['section'] ?? '';
        if ($postedSection !== $section) {
            $errors[] = 'Invalid settings section.';
        } elseif (!can_access_section($sections[$section]['permission'], $user)) {
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
        } elseif (!can_access_section($sections[$section]['permission'], $user)) {
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
                StripeSettingsService::saveAdminSettings((int) $user['id'], $_POST, $errors);
                if (!$errors) {
                    $toast = 'Stripe settings saved.';
                }
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
                
                $newResend = normalize_text($_POST['integrations_resend_api_key'] ?? '');
                if ($newResend !== '') {
                    SettingsService::setGlobal((int) $user['id'], 'integrations.resend_api_key', $newResend, ['encrypt' => true]);
                }
                
                SettingsService::setGlobal((int) $user['id'], 'integrations.smtp_host', normalize_text($_POST['integrations_smtp_host'] ?? ''));
                SettingsService::setGlobal((int) $user['id'], 'integrations.smtp_port', (int) ($_POST['integrations_smtp_port'] ?? 587));
                SettingsService::setGlobal((int) $user['id'], 'integrations.smtp_user', normalize_text($_POST['integrations_smtp_user'] ?? ''));
                
                $newSmtpPass = normalize_text($_POST['integrations_smtp_password'] ?? '');
                if ($newSmtpPass !== '') {
                    SettingsService::setGlobal((int) $user['id'], 'integrations.smtp_password', $newSmtpPass, ['encrypt' => true]);
                }
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

                $upgradeMode = trim((string) ($_POST['upgrade_mode'] ?? 'standard'));
                if (!in_array($upgradeMode, ['standard', 'custom'], true)) {
                    $upgradeMode = 'standard';
                }
                $upgradeCustomFeeRaw = trim((string) ($_POST['upgrade_custom_fee'] ?? ''));
                $upgradeCustomFeeCents = 0;
                if ($upgradeCustomFeeRaw !== '') {
                    $parsedFee = (float) str_replace(['$', ','], '', $upgradeCustomFeeRaw);
                    if ($parsedFee < 0) {
                        $idFieldErrors['upgrade_custom_fee'] = 'Upgrade fee cannot be negative.';
                    } else {
                        $upgradeCustomFeeCents = (int) round($parsedFee * 100);
                    }
                }
                if ($upgradeMode === 'custom' && $upgradeCustomFeeCents <= 0) {
                    $idFieldErrors['upgrade_custom_fee'] = 'Enter an amount greater than $0 for the custom upgrade fee.';
                }

                $pdo = db();
                $hasChapterAbbreviation = ChapterRepository::hasColumn($pdo, 'abbreviation');
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
                        $abbreviation = normalize_text($chapterData['abbreviation'] ?? '');
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
                        if ($hasChapterAbbreviation && mb_strlen($abbreviation) > 16) {
                            $chapterFieldErrors[$chapterId]['abbreviation'] = 'Max 16 characters.';
                            continue;
                        }
                        $chapterUpdates[] = [
                            'id' => (int) $chapterId,
                            'name' => $name,
                            'abbreviation' => $abbreviation,
                            'state' => $state,
                            'sort_order' => $sortOrder,
                            'is_active' => $isActive,
                        ];
                    }
                }

                $newChapterName = normalize_text($_POST['new_chapter_name'] ?? '');
                $newChapterAbbreviation = normalize_text($_POST['new_chapter_abbreviation'] ?? '');
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
                    SettingsService::setGlobal((int) $user['id'], 'membership.upgrade_mode', $upgradeMode);
                    SettingsService::setGlobal((int) $user['id'], 'membership.upgrade_custom_fee_cents', $upgradeCustomFeeCents);

                    foreach ($chapterUpdates as $chapterUpdate) {
                        $updateColumns = ['name = :name'];
                        $params = [
                            'id' => $chapterUpdate['id'],
                            'name' => $chapterUpdate['name'],
                        ];
                        if ($hasChapterAbbreviation) {
                            $updateColumns[] = 'abbreviation = :abbreviation';
                            $params['abbreviation'] = $chapterUpdate['abbreviation'] !== '' ? $chapterUpdate['abbreviation'] : null;
                        }
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
                        if ($hasChapterAbbreviation) {
                            $columns[] = 'abbreviation';
                            $placeholders[] = ':abbreviation';
                            $params['abbreviation'] = $newChapterAbbreviation !== '' ? $newChapterAbbreviation : null;
                        }
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

                    // Keep the chapter rep role catalog in sync with the chapters
                    // table. Adds a new "<Chapter> Area Rep" role for any chapter
                    // we just created, renames existing roles when a chapter is
                    // renamed, and deactivates the role when a chapter is
                    // marked is_active=0. Idempotent — safe to call every save.
                    if (class_exists(\App\Services\CommitteeService::class)) {
                        \App\Services\CommitteeService::syncChapterRoles();
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
                SettingsService::setGlobal((int) $user['id'], 'advanced.disable_password_reset_rate_limit', isset($_POST['advanced_disable_password_reset_rate_limit']));
                $flags = [
                    'security.two_factor' => isset($_POST['flag_security_two_factor']),
                    'payments.secondary_stripe' => isset($_POST['flag_payments_secondary']),
                    'integrations.myob' => isset($_POST['flag_integrations_myob']),
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
if ($section === 'payments') {
    $pageTitle = 'Stripe Configuration';
}
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

      <?php
        $sectionKeys = [
            'site' => ['site.name', 'site.logo_url', 'site.timezone'],
            'store' => ['store.name', 'store.members_only', 'store.shipping_region'],
            'payments' => ['payments.stripe.mode', 'payments.stripe.publishable_key'],
            'notifications' => ['notifications.from_email', 'notifications.weekly_digest_enabled'],
            'security' => ['security.force_https', 'security.password_min_length'],
            'integrations' => ['integrations.email_provider', 'integrations.youtube_embeds_enabled', 'integrations.resend_api_key'],
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
        ];
        $sectionLabels = [
            'site' => 'Site',
            'store' => 'Store',
            'payments' => 'Payments &amp; Stripe',
            'notifications' => 'Notifications',
            'security' => 'Security &amp; Authentication',
            'integrations' => 'Integrations',
            'media' => 'Media',
            'events' => 'Events',
            'membership_pricing' => 'Membership &amp; Pricing',
            'advanced' => 'Advanced',
        ];
        $last = $sectionKeys[$section] ?? [];
        $lastMeta = $last ? section_last_updated($last, $pdo) : ['updated_at' => null, 'updated_by' => null];
        $lastModifiedDisplay = '';
        if (!empty($lastMeta['updated_at'])) {
            $ts = strtotime((string) $lastMeta['updated_at']);
            $lastModifiedDisplay = $ts ? date('j M Y', $ts) : (string) $lastMeta['updated_at'];
        }
        $cancelHref = '/admin/settings/index.php?section=' . urlencode($section);
      ?>
      <section class="space-y-6">
        <div class="bg-card-light rounded-2xl border border-gray-100 p-6">
          <div class="flex items-center justify-between gap-4">
              <div>
                <h1 class="font-display text-2xl font-bold text-gray-900"><?= e($pageTitle) ?></h1>
                <?php if ($section === 'hub'): ?>
                  <p class="text-sm text-slate-500">Choose a category below to manage configuration across the platform.</p>
                <?php else: ?>
                  <nav class="mt-1 flex items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
                    <a href="/admin/" class="hover:text-slate-700">Admin</a>
                    <span class="text-slate-300">/</span>
                    <a href="/admin/settings/" class="hover:text-slate-700">Settings</a>
                    <span class="text-slate-300">/</span>
                    <span class="font-semibold text-gray-900 border-b-2 border-primary pb-0.5"><?= $sectionLabels[$section] ?? e(ucwords(str_replace('_', ' ', $section))) ?></span>
                  </nav>
                <?php endif; ?>
              </div>
              <?php if ($section !== 'hub'): ?>
                <div class="text-xs text-slate-500 text-right">
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

          <?php if ($section === 'hub'): ?>
            <?php
              $hubGroups = [
                'General' => [
                  ['label' => 'Site Settings', 'icon' => 'language', 'desc' => 'Brand, contact details, social links, legal URLs.', 'href' => '/admin/settings/index.php?section=site', 'permission' => 'admin.settings.general.manage'],
                  ['label' => 'Store Settings', 'icon' => 'storefront', 'desc' => 'Store basics, GST, shipping, fulfilment.', 'href' => '/admin/settings/index.php?section=store', 'permission' => 'admin.store.view'],
                  ['label' => 'Payments (Stripe)', 'icon' => 'payments', 'desc' => 'Stripe keys, fees, live/test mode.', 'href' => '/admin/settings/index.php?section=payments', 'permission' => 'admin.payments.view'],
                  ['label' => 'Notifications', 'icon' => 'notifications', 'desc' => 'Email senders, digests, admin alerts.', 'href' => '/admin/settings/index.php?section=notifications', 'permission' => 'admin.settings.general.manage'],
                  ['label' => 'Events', 'icon' => 'calendar_month', 'desc' => 'Default visibility and event timezone.', 'href' => '/admin/settings/index.php?section=events', 'permission' => 'admin.events.manage'],
                ],
                'People & Access' => [
                  ['label' => 'Security & Authentication', 'icon' => 'shield', 'desc' => 'Login policy, password rules, step-up.', 'href' => '/admin/settings/index.php?section=security', 'permission' => 'admin.settings.general.manage'],
                  ['label' => 'Admin Role Builder', 'icon' => 'engineering', 'desc' => 'Define admin roles and permission sets.', 'href' => '/admin/settings/roles.php', 'permission' => 'admin.roles.view'],
                  ['label' => 'Committee & Leadership Roles', 'icon' => 'workspace_premium', 'desc' => 'Assign members to National and Chapter Rep roles.', 'href' => '/admin/settings/committee-roles.php', 'permission' => 'admin.members.view'],
                  ['label' => 'Membership Settings', 'icon' => 'badge', 'desc' => 'Pricing matrix and member-number format.', 'href' => '/admin/settings/index.php?section=membership_pricing', 'permission' => 'admin.membership_types.manage'],
                ],
                'Content & Media' => [
                  ['label' => 'Media & Files', 'icon' => 'photo_library', 'desc' => 'Allowed file types and upload limits.', 'href' => '/admin/settings/index.php?section=media', 'permission' => 'admin.media_library.manage'],
                  ['label' => 'Integrations', 'icon' => 'link', 'desc' => 'Email provider, YouTube, third-party keys.', 'href' => '/admin/settings/index.php?section=integrations', 'permission' => 'admin.integrations.manage'],
                ],
                'Advanced' => [
                  ['label' => 'AI Settings', 'icon' => 'smart_toy', 'desc' => 'AI keys, models, feature flags.', 'href' => '/admin/settings/ai.php', 'permission' => 'admin.settings.general.manage'],
                  ['label' => 'Advanced / Developer', 'icon' => 'code', 'desc' => 'Maintenance mode, system log, raw flags.', 'href' => '/admin/settings/index.php?section=advanced', 'permission' => 'admin.settings.general.manage'],
                ],
              ];
              $visibleGroups = [];
              foreach ($hubGroups as $groupLabel => $cards) {
                $allowed = array_values(array_filter($cards, fn($c) => can_access_section($c['permission'], $user)));
                if ($allowed) {
                  $visibleGroups[$groupLabel] = $allowed;
                }
              }
            ?>
            <?php if (!$visibleGroups): ?>
              <div class="bg-card-light rounded-2xl border border-gray-100 p-8 text-center text-sm text-slate-500">
                You don't have access to any settings sections. Contact a site administrator if you need access.
              </div>
            <?php else: ?>
              <?php foreach ($visibleGroups as $groupLabel => $cards): ?>
                <div class="space-y-3">
                  <h2 class="text-xs font-semibold uppercase tracking-wider text-gray-500 px-1"><?= e($groupLabel) ?></h2>
                  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <?php foreach ($cards as $card): ?>
                      <a href="<?= e($card['href']) ?>"
                         class="group block bg-card-light rounded-xl border border-gray-200 hover:border-primary hover:shadow-md p-5 transition-all">
                        <div class="flex items-start gap-4">
                          <div class="flex-shrink-0 w-12 h-12 rounded-lg bg-primary/10 group-hover:bg-primary/20 flex items-center justify-center transition-colors">
                            <span class="material-icons-outlined text-primary text-2xl"><?= e($card['icon']) ?></span>
                          </div>
                          <div class="flex-1 min-w-0">
                            <h3 class="font-display font-semibold text-gray-900 text-base mb-1 group-hover:text-primary transition-colors"><?= e($card['label']) ?></h3>
                            <p class="text-sm text-slate-500 leading-snug"><?= e($card['desc']) ?></p>
                          </div>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php elseif ($section === 'site'): ?>
            <form method="post" class="space-y-6 pb-24">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="site">

              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">palette</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Brand</h2>
                      <p class="text-sm text-slate-500">Public-facing identity used across the website.</p>
                    </div>
                  </div>
                  <div class="space-y-4">
                    <div>
                      <label for="site_name" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Site name</label>
                      <input id="site_name" name="site_name" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('site.name', '')) ?>" required>
                    </div>
                    <div>
                      <label for="site_tagline" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tagline</label>
                      <input id="site_tagline" name="site_tagline" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('site.tagline', '')) ?>">
                    </div>
                    <div>
                      <label for="site_logo_url" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Logo URL</label>
                      <input id="site_logo_url" name="site_logo_url" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('site.logo_url', '')) ?>" placeholder="https://">
                    </div>
                    <div>
                      <label for="site_favicon_url" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Favicon URL</label>
                      <input id="site_favicon_url" name="site_favicon_url" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('site.favicon_url', '')) ?>" placeholder="https://">
                    </div>
                  </div>
                </div>

                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">public</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Contact &amp; Locale</h2>
                      <p class="text-sm text-slate-500">Where members can reach you and the regional defaults.</p>
                    </div>
                  </div>
                  <div class="space-y-4">
                    <div>
                      <label for="site_base_url" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Base URL</label>
                      <input id="site_base_url" name="site_base_url" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('site.base_url', '')) ?>" placeholder="https://goldwing.org.au">
                    </div>
                    <div>
                      <label for="site_timezone" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Timezone</label>
                      <input id="site_timezone" name="site_timezone" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('site.timezone', 'Australia/Sydney')) ?>" required>
                    </div>
                    <div>
                      <label for="site_contact_email" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Contact email</label>
                      <input id="site_contact_email" name="site_contact_email" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('site.contact_email', '')) ?>">
                    </div>
                    <div>
                      <label for="site_contact_phone" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Contact phone</label>
                      <input id="site_contact_phone" name="site_contact_phone" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('site.contact_phone', '')) ?>">
                    </div>
                  </div>
                </div>
              </div>

              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">share</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Social Links</h2>
                      <p class="text-sm text-slate-500">Profiles linked from the footer and contact page.</p>
                    </div>
                  </div>
                  <?php $social = SettingsService::getGlobal('site.social_links', []); ?>
                  <div class="space-y-4">
                    <div>
                      <label for="social_facebook" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Facebook</label>
                      <input id="social_facebook" name="social_facebook" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e($social['facebook'] ?? '') ?>">
                    </div>
                    <div>
                      <label for="social_instagram" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Instagram</label>
                      <input id="social_instagram" name="social_instagram" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e($social['instagram'] ?? '') ?>">
                    </div>
                    <div>
                      <label for="social_youtube" class="text-xs font-semibold uppercase tracking-wider text-slate-500">YouTube</label>
                      <input id="social_youtube" name="social_youtube" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e($social['youtube'] ?? '') ?>">
                    </div>
                    <div>
                      <label for="social_tiktok" class="text-xs font-semibold uppercase tracking-wider text-slate-500">TikTok</label>
                      <input id="social_tiktok" name="social_tiktok" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e($social['tiktok'] ?? '') ?>">
                    </div>
                  </div>
                </div>

                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">policy</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Legal &amp; Navigation</h2>
                      <p class="text-sm text-slate-500">Footer links and high-level chrome toggles.</p>
                    </div>
                  </div>
                  <?php $legal = SettingsService::getGlobal('site.legal_urls', []); ?>
                  <div class="space-y-4">
                    <div>
                      <label for="legal_privacy" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Privacy URL</label>
                      <input id="legal_privacy" name="legal_privacy" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e($legal['privacy'] ?? '') ?>">
                    </div>
                    <div>
                      <label for="legal_terms" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Terms URL</label>
                      <input id="legal_terms" name="legal_terms" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e($legal['terms'] ?? '') ?>">
                    </div>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Show navigation</div>
                        <div class="text-xs text-slate-500">Display the main navigation across the site</div>
                      </div>
                      <input type="checkbox" name="site_show_nav" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('site.show_nav', true) ? 'checked' : '' ?>>
                    </label>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Show footer</div>
                        <div class="text-xs text-slate-500">Display the standard footer on public pages</div>
                      </div>
                      <input type="checkbox" name="site_show_footer" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('site.show_footer', true) ? 'checked' : '' ?>>
                    </label>
                  </div>
                </div>
              </div>

              <div class="sticky bottom-4 z-10 bg-card-light rounded-2xl border border-gray-100 shadow-soft p-4 flex flex-wrap items-center justify-between gap-3">
                <div class="text-xs text-slate-500 flex items-center gap-2">
                  <span class="material-icons-outlined text-base text-slate-400">history</span>
                  <?php if ($lastModifiedDisplay !== ''): ?>
                    Last modified <?= e($lastModifiedDisplay) ?><?php if (!empty($lastMeta['updated_by'])): ?> by <?= e($lastMeta['updated_by']) ?><?php endif; ?>
                  <?php else: ?>
                    Not modified yet
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-3">
                  <a href="<?= e($cancelHref) ?>" class="text-sm font-medium text-slate-600 hover:text-slate-900 px-4 py-2">Cancel</a>
                  <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">
                    <span class="material-icons-outlined text-base">save</span>
                    Save Settings
                  </button>
                </div>
              </div>
            </form>
          <?php elseif ($section === 'store'): ?>
            <form method="post" class="space-y-6 pb-24">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="store">

              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">storefront</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Store Basics</h2>
                      <p class="text-sm text-slate-500">Name, URL slug, regional and tax behaviour.</p>
                    </div>
                  </div>
                  <div class="space-y-4">
                    <div>
                      <label for="store_name" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Store name</label>
                      <input id="store_name" name="store_name" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('store.name', '')) ?>">
                    </div>
                    <div>
                      <label for="store_slug" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Store slug</label>
                      <input id="store_slug" name="store_slug" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('store.slug', 'store')) ?>">
                    </div>
                    <div>
                      <label for="store_shipping_region" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Shipping region</label>
                      <select id="store_shipping_region" name="store_shipping_region" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm">
                        <?php $region = SettingsService::getGlobal('store.shipping_region', 'AU'); ?>
                        <option value="AU" <?= $region === 'AU' ? 'selected' : '' ?>>Australia only</option>
                        <option value="INTL" <?= $region === 'INTL' ? 'selected' : '' ?>>International</option>
                      </select>
                    </div>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Members-only purchasing</div>
                        <div class="text-xs text-slate-500">Require sign-in to add items to cart</div>
                      </div>
                      <input type="checkbox" name="store_members_only" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('store.members_only', true) ? 'checked' : '' ?>>
                    </label>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Apply GST to orders</div>
                        <div class="text-xs text-slate-500">Calculate and include GST in totals</div>
                      </div>
                      <input type="checkbox" name="store_gst_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('store.gst_enabled', true) ? 'checked' : '' ?>>
                    </label>
                  </div>
                </div>

                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">local_shipping</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Fees &amp; Fulfilment</h2>
                      <p class="text-sm text-slate-500">Stripe fee handling, shipping, and pickup options.</p>
                    </div>
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Pass Stripe fees to buyer</div>
                      <div class="text-xs text-slate-500">Add the processing surcharge at checkout</div>
                    </div>
                    <input type="checkbox" name="store_pass_fees" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('store.pass_stripe_fees', true) ? 'checked' : '' ?>>
                  </label>
                  <div class="grid grid-cols-2 gap-3">
                    <div>
                      <label for="store_fee_percent" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Fee percent</label>
                      <input id="store_fee_percent" name="store_fee_percent" type="number" step="0.01" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) SettingsService::getGlobal('store.stripe_fee_percent', 0)) ?>">
                    </div>
                    <div>
                      <label for="store_fee_fixed" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Fee fixed</label>
                      <input id="store_fee_fixed" name="store_fee_fixed" type="number" step="0.01" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) SettingsService::getGlobal('store.stripe_fee_fixed', 0)) ?>">
                    </div>
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Enable flat-rate shipping</div>
                      <div class="text-xs text-slate-500">One amount applied to every shippable order</div>
                    </div>
                    <input type="checkbox" name="store_shipping_flat_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('store.shipping_flat_enabled', false) ? 'checked' : '' ?>>
                  </label>
                  <div>
                    <label for="store_shipping_flat_rate" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Flat-rate amount</label>
                    <input id="store_shipping_flat_rate" name="store_shipping_flat_rate" type="number" step="0.01" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) SettingsService::getGlobal('store.shipping_flat_rate', '')) ?>">
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Free shipping threshold</div>
                      <div class="text-xs text-slate-500">Waive shipping on orders above the limit</div>
                    </div>
                    <input type="checkbox" name="store_shipping_free_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('store.shipping_free_enabled', false) ? 'checked' : '' ?>>
                  </label>
                  <div>
                    <label for="store_shipping_free_threshold" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Free shipping threshold</label>
                    <input id="store_shipping_free_threshold" name="store_shipping_free_threshold" type="number" step="0.01" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) SettingsService::getGlobal('store.shipping_free_threshold', '')) ?>">
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Enable pickup</div>
                      <div class="text-xs text-slate-500">Offer a self-collect option at checkout</div>
                    </div>
                    <input type="checkbox" name="store_pickup_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('store.pickup_enabled', false) ? 'checked' : '' ?>>
                  </label>
                  <div>
                    <label for="store_pickup_instructions" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pickup instructions</label>
                    <textarea id="store_pickup_instructions" name="store_pickup_instructions" rows="3" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm"><?= e(SettingsService::getGlobal('store.pickup_instructions', '')) ?></textarea>
                  </div>
                </div>
              </div>

              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">email</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Store Emails</h2>
                      <p class="text-sm text-slate-500">Branding and recipients for transactional emails.</p>
                    </div>
                  </div>
                  <div class="space-y-4">
                    <div>
                      <label for="store_notification_emails" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Admin notification emails</label>
                      <textarea id="store_notification_emails" name="store_notification_emails" rows="2" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm"><?= e(SettingsService::getGlobal('store.notification_emails', '')) ?></textarea>
                    </div>
                    <div>
                      <label for="store_email_logo" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Email logo URL</label>
                      <input id="store_email_logo" name="store_email_logo" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('store.email_logo_url', '')) ?>">
                    </div>
                    <div>
                      <label for="store_email_footer" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Email footer text</label>
                      <input id="store_email_footer" name="store_email_footer" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('store.email_footer_text', '')) ?>">
                    </div>
                    <div>
                      <label for="store_support_email" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Support email</label>
                      <input id="store_support_email" name="store_support_email" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('store.support_email', '')) ?>">
                    </div>
                  </div>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">receipt_long</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Order Rules</h2>
                      <p class="text-sm text-slate-500">Statuses and references applied to new orders.</p>
                    </div>
                  </div>
                  <div>
                    <label for="store_order_paid_status" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Paid order status</label>
                    <input id="store_order_paid_status" name="store_order_paid_status" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('store.order_paid_status', 'paid')) ?>">
                  </div>
                  <a class="inline-flex items-center gap-1 text-sm text-blue-600 hover:underline" href="/admin/store/products">
                    Manage products
                    <span class="material-icons-outlined text-base">arrow_forward</span>
                  </a>
                </div>
              </div>

              <div class="sticky bottom-4 z-10 bg-card-light rounded-2xl border border-gray-100 shadow-soft p-4 flex flex-wrap items-center justify-between gap-3">
                <div class="text-xs text-slate-500 flex items-center gap-2">
                  <span class="material-icons-outlined text-base text-slate-400">history</span>
                  <?php if ($lastModifiedDisplay !== ''): ?>
                    Last modified <?= e($lastModifiedDisplay) ?><?php if (!empty($lastMeta['updated_by'])): ?> by <?= e($lastMeta['updated_by']) ?><?php endif; ?>
                  <?php else: ?>
                    Not modified yet
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-3">
                  <a href="<?= e($cancelHref) ?>" class="text-sm font-medium text-slate-600 hover:text-slate-900 px-4 py-2">Cancel</a>
                  <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">
                    <span class="material-icons-outlined text-base">save</span>
                    Save Settings
                  </button>
                </div>
              </div>
            </form>
          <?php elseif ($section === 'payments'): ?>
            <?php
              $paymentChannel = PaymentSettingsService::getChannelByCode('primary');
              $paymentSettings = PaymentSettingsService::getSettingsByChannelId((int) ($paymentChannel['id'] ?? 0));
              $stripeSettings = StripeSettingsService::getSettings();
              $activeKeys = StripeSettingsService::getActiveKeys();
              $activeMode = $activeKeys['mode'] ?? 'test';
              $testPublishableMask = StripeSettingsService::maskValue($stripeSettings['test_publishable_key'] ?? '');
              $testSecretMask = StripeSettingsService::maskValue($stripeSettings['test_secret_key'] ?? '');
              $livePublishableMask = StripeSettingsService::maskValue($stripeSettings['live_publishable_key'] ?? '');
              $liveSecretMask = StripeSettingsService::maskValue($stripeSettings['live_secret_key'] ?? '');
              $webhookMask = StripeSettingsService::maskValue($stripeSettings['webhook_secret'] ?? '');
              $testPublishableDisplay = $testPublishableMask['configured'] ? 'pk_test_****' . $testPublishableMask['last4'] : '';
              $testSecretDisplay = $testSecretMask['configured'] ? 'sk_test_****' . $testSecretMask['last4'] : '';
              $livePublishableDisplay = $livePublishableMask['configured'] ? 'pk_live_****' . $livePublishableMask['last4'] : '';
              $liveSecretDisplay = $liveSecretMask['configured'] ? 'sk_live_****' . $liveSecretMask['last4'] : '';
              $webhookDisplay = $webhookMask['configured'] ? 'whsec_****' . $webhookMask['last4'] : '';
              $webhookHealth = StripeSettingsService::webhookHealth($paymentSettings);
              $prices = $stripeSettings['membership_prices'] ?? [];
              if (!is_array($prices)) {
                  $prices = [];
              }
              $bankTransferInstructions = SettingsService::getGlobal('payments.bank_transfer_instructions', '');
              $webhookUrl = BaseUrlService::buildUrl('/api/stripe/webhook');
              $fromEmail = (string) SettingsService::getGlobal('notifications.from_email', '');
              $defaultTerm = (string) ($stripeSettings['membership_default_term'] ?? '12M');
              $dashboardUrl = $activeMode === 'test' ? 'https://dashboard.stripe.com/test' : 'https://dashboard.stripe.com';
            ?>
            <?php
              $statusClasses = $webhookHealth['status'] === 'ok' ? 'text-green-600' : ($webhookHealth['status'] === 'failing' ? 'text-red-600' : 'text-amber-600');
              $statusLabel = $webhookHealth['status'] === 'ok' ? 'Active' : ($webhookHealth['status'] === 'failing' ? 'Failing' : 'Stale');
              $statusDot = $webhookHealth['status'] === 'ok' ? 'bg-green-500' : ($webhookHealth['status'] === 'failing' ? 'bg-red-500' : 'bg-amber-500');
              $connectionPillBg = $activeMode === 'live' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700';
              $connectionPillDot = $activeMode === 'live' ? 'bg-green-500' : 'bg-amber-500';
              $connectionLabel = $activeMode === 'live' ? 'Live Connection' : 'Test Connection';
              $connectionState = $activeKeys['secret_key'] ?? '' ? 'Connected' : 'Not connected';
              $isTestMode = !empty($stripeSettings['use_test_mode']);
              $lastReceived = $webhookHealth['last_received_at'] ?? null;
              if ($lastReceived) {
                  $lastSyncDisplay = e($lastReceived);
              } else {
                  $lastSyncDisplay = 'Never';
              }
            ?>
            <form method="post" class="space-y-6 pb-24" id="stripe-settings-form">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="payments">

              <div class="grid gap-6 lg:grid-cols-3">
                <div class="lg:col-span-2 bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start justify-between gap-4">
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Stripe Connection</h2>
                      <p class="text-sm text-slate-500 mt-1">Manage your association's Stripe link and environment settings.</p>
                    </div>
                    <div class="inline-flex items-center gap-2 rounded-full <?= e($connectionPillBg) ?> px-3 py-1.5 text-xs font-semibold whitespace-nowrap">
                      <span class="h-2 w-2 rounded-full <?= e($connectionPillDot) ?>"></span>
                      <span><?= e($connectionLabel) ?> &middot; <?= e($connectionState) ?></span>
                    </div>
                  </div>

                  <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                      <div class="flex items-center gap-3 min-w-0">
                        <div class="h-10 w-10 rounded-lg bg-gray-900 flex items-center justify-center flex-shrink-0">
                          <span class="material-icons-outlined text-primary text-lg">bolt</span>
                        </div>
                        <div class="min-w-0">
                          <div class="text-sm font-semibold text-gray-900">Operational Mode</div>
                          <div class="text-xs text-slate-500">Switch between Live and Sandbox testing environments</div>
                        </div>
                      </div>
                      <div class="flex items-center gap-3 flex-shrink-0">
                        <span class="text-xs font-semibold <?= $isTestMode ? 'text-gray-900' : 'text-slate-400' ?>">Test Mode</span>
                        <label class="relative inline-flex h-6 w-11 cursor-pointer" title="Toggle Stripe mode">
                          <input type="checkbox" name="stripe_use_test_mode" value="1" class="sr-only peer" <?= $isTestMode ? 'checked' : '' ?>>
                          <span class="absolute inset-0 rounded-full bg-green-500 peer-checked:bg-gray-300 transition-colors"></span>
                          <span class="absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform translate-x-5 peer-checked:translate-x-0"></span>
                        </label>
                        <span class="text-xs font-semibold <?= $isTestMode ? 'text-slate-400' : 'text-gray-900' ?>">Live Mode</span>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="bg-card-light rounded-2xl border border-gray-100 border-t-4 <?= $webhookHealth['status'] === 'ok' ? 'border-t-green-500' : ($webhookHealth['status'] === 'failing' ? 'border-t-red-500' : 'border-t-amber-500') ?> p-6 space-y-4">
                  <div class="flex items-center gap-2">
                    <span class="material-icons-outlined text-green-500">verified_user</span>
                    <h2 class="font-display text-lg font-bold text-gray-900">Webhook Health</h2>
                  </div>
                  <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                      <dt class="text-slate-500">Endpoint Status</dt>
                      <dd class="font-semibold <?= $statusClasses ?>"><?= e($statusLabel) ?></dd>
                    </div>
                    <div class="flex items-center justify-between">
                      <dt class="text-slate-500">Last Error</dt>
                      <dd class="font-semibold text-gray-900 truncate max-w-[12rem]" title="<?= e($webhookHealth['last_error'] ?? 'None') ?>"><?= e($webhookHealth['last_error'] ? 'See log' : 'None') ?></dd>
                    </div>
                    <div class="flex items-center justify-between">
                      <dt class="text-slate-500">Last Sync</dt>
                      <dd class="font-semibold text-gray-900"><?= $lastSyncDisplay ?></dd>
                    </div>
                  </dl>
                  <button type="button" id="stripe-test-connection" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-slate-700 hover:bg-gray-50">
                    Run Connection Test
                  </button>
                  <div id="stripe-test-result" class="text-xs text-slate-500"></div>
                  <a class="block text-center text-xs text-blue-600 hover:underline" href="<?= e($dashboardUrl) ?>" target="_blank" rel="noopener">Open Stripe Dashboard &rarr;</a>
                </div>
              </div>

              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                <div class="flex items-start justify-between gap-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">API Credentials</h2>
                  <span class="material-icons-outlined text-slate-300 cursor-help" title="Stripe API keys never leave this server. Secrets are encrypted at rest.">info</span>
                </div>
                <div class="grid gap-6 md:grid-cols-2">
                  <div class="space-y-4">
                    <div class="flex items-center gap-2">
                      <span class="h-2 w-2 rounded-full bg-green-500"></span>
                      <span class="text-sm font-semibold text-gray-900">Production Keys (Live)</span>
                    </div>
                    <div>
                      <label for="stripe_live_publishable_key" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Publishable Key</label>
                      <div class="relative mt-2">
                        <input id="stripe_live_publishable_key" name="stripe_live_publishable_key" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 pr-10 text-sm font-mono" value="<?= e($livePublishableDisplay) ?>" data-mask-value="<?= e($livePublishableDisplay) ?>" placeholder="<?= $livePublishableMask['configured'] ? 'pk_live_…' . e($livePublishableMask['last4']) : 'pk_live_…' ?>">
                        <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 inline-flex items-center justify-center h-7 w-7 rounded text-slate-400 hover:text-slate-700 hover:bg-gray-100" data-copy-target="stripe_live_publishable_key" title="Copy to clipboard">
                          <span class="material-icons-outlined text-base">content_copy</span>
                        </button>
                      </div>
                    </div>
                    <div>
                      <label for="stripe_live_secret_key" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Secret Key</label>
                      <div class="relative mt-2">
                        <input id="stripe_live_secret_key" name="stripe_live_secret_key" type="password" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 pr-10 text-sm font-mono" value="<?= e($liveSecretDisplay) ?>" data-mask-value="<?= e($liveSecretDisplay) ?>" placeholder="<?= $liveSecretMask['configured'] ? 'sk_live_…' . e($liveSecretMask['last4']) : 'sk_live_…' ?>">
                        <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 inline-flex items-center justify-center h-7 w-7 rounded text-slate-400 hover:text-slate-700 hover:bg-gray-100" data-toggle-secret="stripe_live_secret_key" title="Show / hide secret">
                          <span class="material-icons-outlined text-base">visibility</span>
                        </button>
                      </div>
                      <span class="mt-1 block text-xs text-slate-400">Leave blank to keep current secret.</span>
                    </div>
                  </div>
                  <div class="space-y-4">
                    <div class="flex items-center gap-2">
                      <span class="h-2 w-2 rounded-full bg-amber-400"></span>
                      <span class="text-sm font-semibold text-gray-900">Sandbox Keys (Test)</span>
                    </div>
                    <div>
                      <label for="stripe_test_publishable_key" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Publishable Key</label>
                      <div class="relative mt-2">
                        <input id="stripe_test_publishable_key" name="stripe_test_publishable_key" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 pr-10 text-sm font-mono" value="<?= e($testPublishableDisplay) ?>" data-mask-value="<?= e($testPublishableDisplay) ?>" placeholder="<?= $testPublishableMask['configured'] ? 'pk_test_…' . e($testPublishableMask['last4']) : 'pk_test_…' ?>">
                        <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 inline-flex items-center justify-center h-7 w-7 rounded text-slate-400 hover:text-slate-700 hover:bg-gray-100" data-copy-target="stripe_test_publishable_key" title="Copy to clipboard">
                          <span class="material-icons-outlined text-base">content_copy</span>
                        </button>
                      </div>
                    </div>
                    <div>
                      <label for="stripe_test_secret_key" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Secret Key</label>
                      <div class="relative mt-2">
                        <input id="stripe_test_secret_key" name="stripe_test_secret_key" type="password" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 pr-10 text-sm font-mono" value="<?= e($testSecretDisplay) ?>" data-mask-value="<?= e($testSecretDisplay) ?>" placeholder="<?= $testSecretMask['configured'] ? 'sk_test_…' . e($testSecretMask['last4']) : 'sk_test_…' ?>">
                        <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 inline-flex items-center justify-center h-7 w-7 rounded text-slate-400 hover:text-slate-700 hover:bg-gray-100" data-toggle-secret="stripe_test_secret_key" title="Show / hide secret">
                          <span class="material-icons-outlined text-base">visibility</span>
                        </button>
                      </div>
                      <span class="mt-1 block text-xs text-slate-400">Leave blank to keep current secret.</span>
                    </div>
                  </div>
                </div>

                <div class="border-t border-gray-100 pt-5 grid gap-4 md:grid-cols-2">
                  <div>
                    <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Webhook URL</label>
                    <input class="mt-2 w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm font-mono text-slate-600" value="<?= e($webhookUrl) ?>" readonly>
                  </div>
                  <div>
                    <label for="stripe_webhook_secret" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Webhook Secret</label>
                    <div class="relative mt-2">
                      <input id="stripe_webhook_secret" name="stripe_webhook_secret" type="password" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 pr-10 text-sm font-mono" value="<?= e($webhookDisplay) ?>" data-mask-value="<?= e($webhookDisplay) ?>" placeholder="<?= $webhookMask['configured'] ? 'whsec_…' . e($webhookMask['last4']) : 'whsec_…' ?>">
                      <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 inline-flex items-center justify-center h-7 w-7 rounded text-slate-400 hover:text-slate-700 hover:bg-gray-100" data-toggle-secret="stripe_webhook_secret" title="Show / hide secret">
                        <span class="material-icons-outlined text-base">visibility</span>
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <div class="flex items-center gap-2">
                    <span class="material-icons-outlined text-slate-500">shopping_cart</span>
                    <h2 class="font-display text-lg font-bold text-gray-900">Checkout Behavior</h2>
                  </div>
                  <div class="space-y-3">
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Enable Stripe Checkout</div>
                        <div class="text-xs text-slate-500">Master switch for Stripe-powered payments</div>
                      </div>
                      <input type="checkbox" name="stripe_checkout_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= !empty($stripeSettings['checkout_enabled']) ? 'checked' : '' ?>>
                    </label>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Enable Apple Pay &amp; Google Pay</div>
                        <div class="text-xs text-slate-500">One-tap mobile wallet payments</div>
                      </div>
                      <input type="checkbox" name="stripe_enable_apple_pay" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= !empty($stripeSettings['enable_apple_pay']) ? 'checked' : '' ?>>
                    </label>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Enable Google Pay</div>
                        <div class="text-xs text-slate-500">Google wallet checkout</div>
                      </div>
                      <input type="checkbox" name="stripe_enable_google_pay" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= !empty($stripeSettings['enable_google_pay']) ? 'checked' : '' ?>>
                    </label>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Allow Guest Checkout</div>
                        <div class="text-xs text-slate-500">Permit payments without account login</div>
                      </div>
                      <input type="checkbox" name="stripe_allow_guest_checkout" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= !empty($stripeSettings['allow_guest_checkout']) ? 'checked' : '' ?>>
                    </label>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Customer Portal</div>
                        <div class="text-xs text-slate-500">Self-service for members</div>
                      </div>
                      <input type="checkbox" name="stripe_customer_portal_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= !empty($stripeSettings['customer_portal_enabled']) ? 'checked' : '' ?>>
                    </label>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Require shipping for physical items</div>
                        <div class="text-xs text-slate-500">Force address collection for shippable orders</div>
                      </div>
                      <input type="checkbox" name="stripe_require_shipping_for_physical" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= !empty($stripeSettings['require_shipping_for_physical']) ? 'checked' : '' ?>>
                    </label>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Digital-only minimal checkout</div>
                        <div class="text-xs text-slate-500">Only collect email + name for digital orders</div>
                      </div>
                      <input type="checkbox" name="stripe_digital_only_minimal" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= !empty($stripeSettings['digital_only_minimal']) ? 'checked' : '' ?>>
                    </label>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Enable BNPL methods</div>
                        <div class="text-xs text-slate-500">Buy-now-pay-later providers (where supported)</div>
                      </div>
                      <input type="checkbox" name="stripe_enable_bnpl" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= !empty($stripeSettings['enable_bnpl']) ? 'checked' : '' ?>>
                    </label>
                  </div>
                  <p class="text-xs text-slate-400 pt-2 border-t border-gray-100">Currency is fixed to AUD. Shipping rules follow Store Settings.</p>
                </div>

                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <div class="flex items-center gap-2">
                    <span class="material-icons-outlined text-slate-500">receipt_long</span>
                    <h2 class="font-display text-lg font-bold text-gray-900">Receipt &amp; Invoice preferences</h2>
                  </div>
                  <div class="space-y-3">
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Send Stripe Receipts</div>
                        <div class="text-xs text-slate-500">Automatic email after successful charge</div>
                      </div>
                      <input type="checkbox" name="stripe_send_receipts" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= !empty($stripeSettings['send_receipts']) ? 'checked' : '' ?>>
                    </label>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Generate PDF Invoices</div>
                        <div class="text-xs text-slate-500">Attach downloadable invoice to members area</div>
                      </div>
                      <input type="checkbox" name="stripe_generate_pdf" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= !empty($paymentSettings['generate_pdf']) ? 'checked' : '' ?>>
                    </label>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Save invoice references</div>
                        <div class="text-xs text-slate-500">Persist Stripe invoice IDs against orders</div>
                      </div>
                      <input type="checkbox" name="stripe_save_invoice_refs" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= !empty($stripeSettings['save_invoice_refs']) ? 'checked' : '' ?>>
                    </label>
                  </div>
                  <div class="pt-3 border-t border-gray-100 grid gap-4 md:grid-cols-2">
                    <div>
                      <label for="stripe_invoice_prefix" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Membership invoice prefix</label>
                      <input id="stripe_invoice_prefix" name="stripe_invoice_prefix" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm font-mono" value="<?= e($paymentSettings['invoice_prefix'] ?? 'MEM') ?>" placeholder="MEM" maxlength="20">
                      <p class="mt-1 text-xs text-slate-400">Used for new membership renewals. e.g. <code>MEM-2026-00001</code>.</p>
                    </div>
                    <div>
                      <label for="stripe_invoice_prefix_store" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Store invoice prefix</label>
                      <input id="stripe_invoice_prefix_store" name="stripe_invoice_prefix_store" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm font-mono" value="<?= e($paymentSettings['invoice_prefix_store'] ?? 'STORE') ?>" placeholder="STORE" maxlength="20">
                      <p class="mt-1 text-xs text-slate-400">Stamped on Stripe store invoices. e.g. <code>STORE-2026-00001</code>.</p>
                    </div>
                  </div>
                  <div>
                    <label for="stripe_invoice_email_template" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Invoice email template</label>
                    <textarea id="stripe_invoice_email_template" name="stripe_invoice_email_template" rows="3" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm"><?= e($paymentSettings['invoice_email_template'] ?? '') ?></textarea>
                    <p class="mt-1 text-xs text-slate-400">Tokens: {{invoice_number}}, {{invoice_date}}, {{total}}, {{download_url}}, {{download_link}}.</p>
                  </div>
                  <div class="text-xs text-slate-500 pt-2 border-t border-gray-100">
                    Email from: <span class="font-medium text-slate-700"><?= $fromEmail !== '' ? e($fromEmail) : 'Not configured' ?></span>
                  </div>
                </div>
              </div>

              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                <div class="flex items-start justify-between gap-4">
                  <h2 class="font-display text-lg font-bold text-gray-900">Membership Stripe Price IDs</h2>
                  <span class="text-xs text-slate-500 italic">Mapped to Association Billing Cycles</span>
                </div>
                <div class="grid gap-6 md:grid-cols-2">
                  <div class="space-y-3">
                    <h3 class="text-sm font-semibold text-gray-900 border-b border-gray-100 pb-2">Full Membership Plans</h3>
                    <label class="grid grid-cols-3 items-center gap-3 text-sm">
                      <span class="text-slate-600">Full 12m</span>
                      <input name="price_full_12" class="col-span-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-mono" value="<?= e($prices['FULL_12'] ?? '') ?>" placeholder="price_…">
                    </label>
                    <label class="grid grid-cols-3 items-center gap-3 text-sm">
                      <span class="text-slate-600">Full 24m</span>
                      <input name="price_full_24" class="col-span-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-mono" value="<?= e($prices['FULL_24'] ?? '') ?>" placeholder="price_…">
                    </label>
                    <label class="grid grid-cols-3 items-center gap-3 text-sm">
                      <span class="text-slate-600">Full 36m</span>
                      <input name="price_full_36" class="col-span-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-mono" value="<?= e($prices['FULL_36'] ?? '') ?>" placeholder="price_…">
                    </label>
                  </div>
                  <div class="space-y-3">
                    <h3 class="text-sm font-semibold text-gray-900 border-b border-gray-100 pb-2">Associate Membership Plans</h3>
                    <label class="grid grid-cols-3 items-center gap-3 text-sm">
                      <span class="text-slate-600">Associate 12m</span>
                      <input name="price_associate_12" class="col-span-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-mono" value="<?= e($prices['ASSOCIATE_12'] ?? '') ?>" placeholder="price_…">
                    </label>
                    <label class="grid grid-cols-3 items-center gap-3 text-sm">
                      <span class="text-slate-600">Associate 24m</span>
                      <input name="price_associate_24" class="col-span-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-mono" value="<?= e($prices['ASSOCIATE_24'] ?? '') ?>" placeholder="price_…">
                    </label>
                    <label class="grid grid-cols-3 items-center gap-3 text-sm">
                      <span class="text-slate-600">Associate 36m</span>
                      <input name="price_associate_36" class="col-span-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-mono" value="<?= e($prices['ASSOCIATE_36'] ?? '') ?>" placeholder="price_…">
                    </label>
                  </div>
                </div>

                <div class="border-t border-gray-100 pt-5 grid gap-4 md:grid-cols-2">
                  <label class="text-sm text-slate-600">Default membership term
                    <select name="membership_default_term" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                      <option value="12M" <?= $defaultTerm === '12M' ? 'selected' : '' ?>>12 months</option>
                      <option value="24M" <?= $defaultTerm === '24M' ? 'selected' : '' ?>>24 months</option>
                      <option value="36M" <?= $defaultTerm === '36M' ? 'selected' : '' ?>>36 months</option>
                    </select>
                  </label>
                  <label class="flex items-center gap-3 text-sm text-slate-600 mt-7">
                    <input type="checkbox" name="membership_allow_both_types" class="rounded border-gray-200" <?= !empty($stripeSettings['membership_allow_both_types']) ? 'checked' : '' ?>>
                    Allow selecting both membership types
                  </label>
                </div>

                <details class="border-t border-gray-100 pt-4">
                  <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wider text-slate-500 hover:text-slate-700">Legacy price IDs</summary>
                  <div class="grid gap-3 md:grid-cols-2 pt-4">
                    <label class="grid grid-cols-3 items-center gap-3 text-sm">
                      <span class="text-slate-600">Legacy FULL 1Y</span>
                      <input name="price_full_1y" class="col-span-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-mono" value="<?= e($prices['FULL_1Y'] ?? '') ?>">
                    </label>
                    <label class="grid grid-cols-3 items-center gap-3 text-sm">
                      <span class="text-slate-600">Legacy FULL 3Y</span>
                      <input name="price_full_3y" class="col-span-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-mono" value="<?= e($prices['FULL_3Y'] ?? '') ?>">
                    </label>
                    <label class="grid grid-cols-3 items-center gap-3 text-sm">
                      <span class="text-slate-600">Legacy ASSOCIATE 1Y</span>
                      <input name="price_associate_1y" class="col-span-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-mono" value="<?= e($prices['ASSOCIATE_1Y'] ?? '') ?>">
                    </label>
                    <label class="grid grid-cols-3 items-center gap-3 text-sm">
                      <span class="text-slate-600">Legacy ASSOCIATE 3Y</span>
                      <input name="price_associate_3y" class="col-span-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-mono" value="<?= e($prices['ASSOCIATE_3Y'] ?? '') ?>">
                    </label>
                    <label class="grid grid-cols-3 items-center gap-3 text-sm">
                      <span class="text-slate-600">Legacy LIFE</span>
                      <input name="price_life" class="col-span-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-mono" value="<?= e($prices['LIFE'] ?? '') ?>">
                    </label>
                  </div>
                </details>

                <?php if (!SettingsService::isFeatureEnabled('payments.secondary_stripe')): ?>
                  <div class="text-xs text-slate-400">Secondary Stripe account (AGM) is disabled. Enable via feature flags.</div>
                <?php endif; ?>
              </div>

              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-3">
                <div class="flex items-center gap-2">
                  <span class="material-icons-outlined text-slate-500">account_balance</span>
                  <h2 class="font-display text-lg font-bold text-gray-900">Bank Transfer Instructions <span class="text-sm font-normal text-slate-500">(Manual Billing)</span></h2>
                </div>
                <p class="text-sm text-slate-500">This text is displayed to users who opt for manual payment. It should include the BSB, Account Number, and Reference formatting.</p>
                <textarea name="bank_transfer_instructions" rows="6" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm font-mono"><?= e((string) $bankTransferInstructions) ?></textarea>
              </div>

              <div class="sticky bottom-4 z-10 bg-card-light rounded-2xl border border-gray-100 shadow-soft p-4 flex flex-wrap items-center justify-between gap-3">
                <div class="text-xs text-slate-500 flex items-center gap-2">
                  <span class="material-icons-outlined text-base text-slate-400">history</span>
                  <?php if ($lastModifiedDisplay !== ''): ?>
                    Last modified <?= e($lastModifiedDisplay) ?><?php if (!empty($lastMeta['updated_by'])): ?> by <?= e($lastMeta['updated_by']) ?><?php endif; ?>
                  <?php else: ?>
                    Not modified yet
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-3">
                  <a href="/admin/settings/index.php?section=payments" class="text-sm font-medium text-slate-600 hover:text-slate-900 px-4 py-2">Cancel</a>
                  <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">
                    <span class="material-icons-outlined text-base">save</span>
                    Save Configuration
                  </button>
                </div>
              </div>
            </form>
            <script>
              document.addEventListener('DOMContentLoaded', () => {
                const form = document.getElementById('stripe-settings-form');
                const maskedInputs = form ? Array.from(form.querySelectorAll('[data-mask-value]')) : [];
                if (form && maskedInputs.length) {
                  maskedInputs.forEach((input) => {
                    const mask = input.dataset.maskValue || '';
                    input.addEventListener('focus', () => {
                      if (mask && input.value === mask) {
                        input.value = '';
                      }
                    });
                  });
                  form.addEventListener('submit', () => {
                    maskedInputs.forEach((input) => {
                      const mask = input.dataset.maskValue || '';
                      if (mask && input.value === mask) {
                        input.value = '';
                      }
                    });
                  });
                }

                document.querySelectorAll('[data-copy-target]').forEach((btn) => {
                  btn.addEventListener('click', async () => {
                    const target = document.getElementById(btn.dataset.copyTarget);
                    if (!target || !target.value) return;
                    try {
                      await navigator.clipboard.writeText(target.value);
                      const icon = btn.querySelector('.material-icons-outlined');
                      if (icon) {
                        const original = icon.textContent;
                        icon.textContent = 'check';
                        btn.classList.add('text-green-600');
                        setTimeout(() => {
                          icon.textContent = original;
                          btn.classList.remove('text-green-600');
                        }, 1200);
                      }
                    } catch (e) {
                      target.select();
                      document.execCommand && document.execCommand('copy');
                    }
                  });
                });

                document.querySelectorAll('[data-toggle-secret]').forEach((btn) => {
                  btn.addEventListener('click', () => {
                    const target = document.getElementById(btn.dataset.toggleSecret);
                    if (!target) return;
                    const icon = btn.querySelector('.material-icons-outlined');
                    if (target.type === 'password') {
                      target.type = 'text';
                      if (icon) icon.textContent = 'visibility_off';
                    } else {
                      target.type = 'password';
                      if (icon) icon.textContent = 'visibility';
                    }
                  });
                });

                const testButton = document.getElementById('stripe-test-connection');
                const result = document.getElementById('stripe-test-result');
                if (!testButton || !result) {
                  return;
                }
                testButton.addEventListener('click', async () => {
                  result.textContent = 'Testing connection...';
                  result.className = 'text-xs text-slate-500';
                  try {
                    const response = await fetch('/api/admin/settings/stripe/test-connection', {
                      method: 'POST',
                      headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': '<?= e(Csrf::token()) ?>'
                      },
                      body: JSON.stringify({}),
                    });
                    const data = await response.json();
                    if (!response.ok || data.error) {
                      result.textContent = data.error || 'Connection test failed.';
                      result.className = 'text-xs text-red-600 font-medium';
                      return;
                    }
                    const account = data.account || {};
                    const label = account.name || 'Stripe Account';
                    const suffix = account.id_last4 ? `•••• ${account.id_last4}` : 'Unknown';
                    result.textContent = `Connected: ${label} (${suffix}) [${data.mode || 'unknown'}]`;
                    result.className = 'text-xs text-green-600 font-medium';
                  } catch (error) {
                    result.textContent = 'Connection test failed.';
                    result.className = 'text-xs text-red-600 font-medium';
                  }
                });
              });
            </script>
          <?php elseif ($section === 'notifications'): ?>
            <form method="post" class="space-y-6 pb-24">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="notifications">
              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">mark_email_read</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Sender Defaults</h2>
                      <p class="text-sm text-slate-500">From / reply-to addresses and digest preferences.</p>
                    </div>
                  </div>
                  <div class="space-y-4">
                    <div>
                      <label for="notify_from_name" class="text-xs font-semibold uppercase tracking-wider text-slate-500">From name</label>
                      <input id="notify_from_name" name="notify_from_name" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('notifications.from_name', '')) ?>">
                    </div>
                    <div>
                      <label for="notify_from_email" class="text-xs font-semibold uppercase tracking-wider text-slate-500">From email</label>
                      <input id="notify_from_email" name="notify_from_email" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('notifications.from_email', '')) ?>">
                    </div>
                    <div>
                      <label for="notify_reply_to" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Reply-to</label>
                      <input id="notify_reply_to" name="notify_reply_to" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('notifications.reply_to', '')) ?>">
                    </div>
                    <p class="text-xs text-amber-600 rounded-lg bg-amber-50 px-3 py-2">SPF/DKIM/DMARC are not configured yet. Expect reduced deliverability until DNS records are added.</p>
                    <div>
                      <label for="notify_admin_emails" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Admin notification emails</label>
                      <textarea id="notify_admin_emails" name="notify_admin_emails" rows="2" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm"><?= e(SettingsService::getGlobal('notifications.admin_emails', '')) ?></textarea>
                    </div>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Weekly digest</div>
                        <div class="text-xs text-slate-500">Send a Monday summary to subscribers</div>
                      </div>
                      <input type="checkbox" name="notify_weekly_digest" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('notifications.weekly_digest_enabled', false) ? 'checked' : '' ?>>
                    </label>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">Event reminders</div>
                        <div class="text-xs text-slate-500">Auto-send the day before events</div>
                      </div>
                      <input type="checkbox" name="notify_event_reminders" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('notifications.event_reminders_enabled', true) ? 'checked' : '' ?>>
                    </label>
                  </div>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">notifications</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">In-app Notifications</h2>
                      <p class="text-sm text-slate-500">Categories shown in the bell menu and the wrapper template.</p>
                    </div>
                  </div>
                  <div>
                    <label for="notify_categories" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Categories (comma-separated)</label>
                    <input id="notify_categories" name="notify_categories" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(implode(', ', SettingsService::getGlobal('notifications.in_app_categories', []))) ?>">
                  </div>
                  <div>
                    <label for="notify_template_basic" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Template</label>
                    <textarea id="notify_template_basic" name="notify_template_basic" rows="5" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm"><?= e(SettingsService::getGlobal('notifications.template_basic', '')) ?></textarea>
                    <p class="mt-1 text-xs text-slate-400">Template supports <code>{{body}}</code> for rendered content.</p>
                  </div>
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
              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-slate-500">edit_note</span>
                  <div>
                    <h2 class="font-display text-lg font-bold text-gray-900">Notification Templates</h2>
                    <p class="text-sm text-slate-500">Pick a template to edit its recipients, subject and body.</p>
                  </div>
                </div>
                <div>
                  <label for="notification-selector" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Select notification</label>
                  <select id="notification-selector" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm">
                    <?php foreach ($notificationDefinitions as $key => $definition): ?>
                      <option value="<?= e($key) ?>"><?= e($definition['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
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
              <div class="sticky bottom-4 z-10 bg-card-light rounded-2xl border border-gray-100 shadow-soft p-4 flex flex-wrap items-center justify-between gap-3">
                <div class="text-xs text-slate-500 flex items-center gap-2">
                  <span class="material-icons-outlined text-base text-slate-400">history</span>
                  <?php if ($lastModifiedDisplay !== ''): ?>
                    Last modified <?= e($lastModifiedDisplay) ?><?php if (!empty($lastMeta['updated_by'])): ?> by <?= e($lastMeta['updated_by']) ?><?php endif; ?>
                  <?php else: ?>
                    Not modified yet
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-3">
                  <a href="<?= e($cancelHref) ?>" class="text-sm font-medium text-slate-600 hover:text-slate-900 px-4 py-2">Cancel</a>
                  <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">
                    <span class="material-icons-outlined text-base">save</span>
                    Save Settings
                  </button>
                </div>
              </div>
            </form>
            <form method="post" class="mt-6">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="send_test_notification">
              <input type="hidden" name="section" value="notifications">
              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-slate-500">send</span>
                  <div>
                    <h2 class="font-display text-lg font-bold text-gray-900">Send Test Notification</h2>
                    <p class="text-sm text-slate-500">Trigger a one-off send to verify recipients and rendering.</p>
                  </div>
                </div>
                <div>
                  <label for="test_notification_key" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Notification to test</label>
                  <select id="test_notification_key" name="test_notification_key" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm">
                    <?php foreach ($notificationDefinitions as $key => $definition): ?>
                      <option value="<?= e($key) ?>"><?= e($definition['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label for="test_notification_email" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Send test to (optional)</label>
                  <input id="test_notification_email" name="test_notification_email" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" placeholder="you@example.com">
                  <p class="mt-1 text-xs text-slate-400">Leave blank to use your admin email; admin recipients still apply based on template settings.</p>
                </div>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">
                  <span class="material-icons-outlined text-base">outgoing_mail</span>
                  Send test email
                </button>
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
          <?php elseif ($section === 'security'): ?>
            <form method="post" class="space-y-6 pb-24">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="security">
              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">vpn_key</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Security &amp; Authentication</h2>
                      <p class="text-sm text-slate-500">Transport security and password requirements.</p>
                    </div>
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Enforce HTTPS</div>
                      <div class="text-xs text-slate-500">Redirect all HTTP traffic to HTTPS</div>
                    </div>
                    <input type="checkbox" name="security_force_https" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('security.force_https', false) ? 'checked' : '' ?>>
                  </label>
                  <div>
                    <label for="security_password_min" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Password minimum length</label>
                    <input id="security_password_min" name="security_password_min" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) SettingsService::getGlobal('security.password_min_length', 12)) ?>">
                  </div>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">security</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">2FA Enforcement Policy</h2>
                      <p class="text-sm text-slate-500">Who must enrol in two-factor authentication.</p>
                    </div>
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Enable 2FA</div>
                      <div class="text-xs text-slate-500">Master switch for the 2FA system</div>
                    </div>
                    <input type="checkbox" name="twofa_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= $securitySettings['enable_2fa'] ? 'checked' : '' ?>>
                  </label>
                  <div>
                    <label for="twofa_mode" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Default enforcement mode</label>
                    <select id="twofa_mode" name="twofa_mode" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm">
                      <option value="REQUIRED_FOR_ALL" <?= $securitySettings['twofa_mode'] === 'REQUIRED_FOR_ALL' ? 'selected' : '' ?>>Required for all</option>
                      <option value="REQUIRED_FOR_ROLES" <?= $securitySettings['twofa_mode'] === 'REQUIRED_FOR_ROLES' ? 'selected' : '' ?>>Required for selected roles</option>
                      <option value="OPTIONAL_FOR_ALL" <?= $securitySettings['twofa_mode'] === 'OPTIONAL_FOR_ALL' ? 'selected' : '' ?>>Optional for all</option>
                      <option value="DISABLED" <?= $securitySettings['twofa_mode'] === 'DISABLED' ? 'selected' : '' ?>>Disabled</option>
                    </select>
                  </div>
                  <div>
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Roles required (when mode is role-based)</span>
                    <div class="mt-2 grid gap-2 text-sm text-slate-600">
                      <?php foreach (['admin','store_manager','area_rep','member'] as $roleOption): ?>
                        <label class="inline-flex items-center gap-2">
                          <input type="checkbox" name="twofa_roles[]" value="<?= e($roleOption) ?>" class="rounded border-gray-200" <?= in_array($roleOption, $securitySettings['twofa_required_roles'], true) ? 'checked' : '' ?>>
                          <?= e($roleOption) ?>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <div>
                    <label for="twofa_grace_days" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Grace period (days)</label>
                    <input id="twofa_grace_days" name="twofa_grace_days" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) $securitySettings['twofa_grace_days']) ?>">
                    <p class="mt-1 text-xs text-slate-400">Per-user overrides are managed in the Members screen.</p>
                  </div>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">fingerprint</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Step-up Authentication</h2>
                      <p class="text-sm text-slate-500">Re-prompt admins before sensitive actions.</p>
                    </div>
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Require step-up for sensitive actions</div>
                      <div class="text-xs text-slate-500">Re-prompt before refunds, role edits, etc.</div>
                    </div>
                    <input type="checkbox" name="stepup_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= $securitySettings['stepup_enabled'] ? 'checked' : '' ?>>
                  </label>
                  <div>
                    <label for="stepup_window_minutes" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Step-up window (minutes)</label>
                    <input id="stepup_window_minutes" name="stepup_window_minutes" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) $securitySettings['stepup_window_minutes']) ?>">
                  </div>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">lock</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Login Security</h2>
                      <p class="text-sm text-slate-500">Rate-limiting and lockout protection.</p>
                    </div>
                  </div>
                  <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                      <label for="login_ip_max_attempts" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Max attempts per IP</label>
                      <input id="login_ip_max_attempts" name="login_ip_max_attempts" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) $securitySettings['login_ip_max_attempts']) ?>">
                    </div>
                    <div>
                      <label for="login_ip_window_minutes" class="text-xs font-semibold uppercase tracking-wider text-slate-500">IP window (minutes)</label>
                      <input id="login_ip_window_minutes" name="login_ip_window_minutes" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) $securitySettings['login_ip_window_minutes']) ?>">
                    </div>
                    <div>
                      <label for="login_account_max_attempts" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Max attempts per account</label>
                      <input id="login_account_max_attempts" name="login_account_max_attempts" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) $securitySettings['login_account_max_attempts']) ?>">
                    </div>
                    <div>
                      <label for="login_account_window_minutes" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Account window (minutes)</label>
                      <input id="login_account_window_minutes" name="login_account_window_minutes" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) $securitySettings['login_account_window_minutes']) ?>">
                    </div>
                    <div class="sm:col-span-2">
                      <label for="login_lockout_minutes" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Lockout duration (minutes)</label>
                      <input id="login_lockout_minutes" name="login_lockout_minutes" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) $securitySettings['login_lockout_minutes']) ?>">
                    </div>
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Progressive delay</div>
                      <div class="text-xs text-slate-500">Add increasing latency to repeated failures</div>
                    </div>
                    <input type="checkbox" name="login_progressive_delay" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= $securitySettings['login_progressive_delay'] ? 'checked' : '' ?>>
                  </label>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">notifications_active</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Security Alerting</h2>
                      <p class="text-sm text-slate-500">Events that page the security alert email.</p>
                    </div>
                  </div>
                  <div>
                    <label for="alert_email" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Alert email</label>
                    <input id="alert_email" name="alert_email" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) $securitySettings['alert_email']) ?>">
                  </div>
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
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">folder_managed</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">File Integrity Monitoring</h2>
                      <p class="text-sm text-slate-500">Detect unexpected file changes in monitored paths.</p>
                    </div>
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Enable file integrity monitoring</div>
                      <div class="text-xs text-slate-500">Recommended cron frequency: hourly or nightly</div>
                    </div>
                    <input type="checkbox" name="fim_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= $securitySettings['fim_enabled'] ? 'checked' : '' ?>>
                  </label>
                  <div>
                    <label for="fim_paths" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Directories to monitor (comma or newline)</label>
                    <textarea id="fim_paths" name="fim_paths" rows="3" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm"><?= e(implode("\n", $securitySettings['fim_paths'])) ?></textarea>
                  </div>
                  <div>
                    <label for="fim_exclude_paths" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Exclude paths</label>
                    <textarea id="fim_exclude_paths" name="fim_exclude_paths" rows="2" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm"><?= e(implode("\n", $securitySettings['fim_exclude_paths'])) ?></textarea>
                  </div>
                  <button type="submit" name="approve_fim_baseline" value="1" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                    <span class="material-icons-outlined text-base">verified</span>
                    Approve baseline
                  </button>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">webhook</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Stripe Webhook Monitoring</h2>
                      <p class="text-sm text-slate-500">Alert thresholds for failed Stripe webhook deliveries.</p>
                    </div>
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Alert on webhook failures</div>
                      <div class="text-xs text-slate-500">Email the security alert address on repeated errors</div>
                    </div>
                    <input type="checkbox" name="webhook_alerts_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= $securitySettings['webhook_alerts_enabled'] ? 'checked' : '' ?>>
                  </label>
                  <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                      <label for="webhook_alert_threshold" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Alert threshold</label>
                      <input id="webhook_alert_threshold" name="webhook_alert_threshold" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) $securitySettings['webhook_alert_threshold']) ?>">
                    </div>
                    <div>
                      <label for="webhook_alert_window_minutes" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Window (minutes)</label>
                      <input id="webhook_alert_window_minutes" name="webhook_alert_window_minutes" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) $securitySettings['webhook_alert_window_minutes']) ?>">
                    </div>
                  </div>
                </div>
              </div>
              <div class="sticky bottom-4 z-10 bg-card-light rounded-2xl border border-gray-100 shadow-soft p-4 flex flex-wrap items-center justify-between gap-3">
                <div class="text-xs text-slate-500 flex items-center gap-2">
                  <span class="material-icons-outlined text-base text-slate-400">history</span>
                  <?php if ($lastModifiedDisplay !== ''): ?>
                    Last modified <?= e($lastModifiedDisplay) ?><?php if (!empty($lastMeta['updated_by'])): ?> by <?= e($lastMeta['updated_by']) ?><?php endif; ?>
                  <?php else: ?>
                    Not modified yet
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-3">
                  <a href="<?= e($cancelHref) ?>" class="text-sm font-medium text-slate-600 hover:text-slate-900 px-4 py-2">Cancel</a>
                  <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">
                    <span class="material-icons-outlined text-base">save</span>
                    Save Settings
                  </button>
                </div>
              </div>
            </form>
          <?php elseif ($section === 'integrations'): ?>
            <form method="post" class="space-y-6 pb-24">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="integrations">
              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">mail</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Email Provider</h2>
                      <p class="text-sm text-slate-500">Outbound transport for transactional and bulk emails.</p>
                    </div>
                  </div>
                  <div>
                    <label for="integrations_email_provider" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Provider</label>
                    <select id="integrations_email_provider" name="integrations_email_provider" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm">
                      <?php $provider = SettingsService::getGlobal('integrations.email_provider', 'php_mail'); ?>
                      <option value="php_mail" <?= $provider === 'php_mail' ? 'selected' : '' ?>>PHP Mail</option>
                      <option value="smtp" <?= $provider === 'smtp' ? 'selected' : '' ?>>SMTP</option>
                      <option value="mailgun" <?= $provider === 'mailgun' ? 'selected' : '' ?>>Mailgun</option>
                      <option value="resend" <?= $provider === 'resend' ? 'selected' : '' ?>>Resend</option>
                    </select>
                  </div>
                  <div>
                    <label for="integrations_resend_api_key" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Resend API Key</label>
                    <?php $resendMask = SettingsService::getMaskedSecret('integrations.resend_api_key'); ?>
                    <input id="integrations_resend_api_key" name="integrations_resend_api_key" type="password" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm font-mono <?= $resendMask['configured'] ? 'placeholder-green-600' : '' ?>" placeholder="<?= $resendMask['configured'] ? 'Configured (ends in ' . e($resendMask['last4']) . ') - leave blank to keep' : 're_...' ?>" value="">
                  </div>
                  <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                      <label for="integrations_smtp_host" class="text-xs font-semibold uppercase tracking-wider text-slate-500">SMTP host</label>
                      <input id="integrations_smtp_host" name="integrations_smtp_host" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('integrations.smtp_host', '')) ?>">
                    </div>
                    <div>
                      <label for="integrations_smtp_port" class="text-xs font-semibold uppercase tracking-wider text-slate-500">SMTP port</label>
                      <input id="integrations_smtp_port" name="integrations_smtp_port" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) SettingsService::getGlobal('integrations.smtp_port', 587)) ?>">
                    </div>
                  </div>
                  <div>
                    <label for="integrations_smtp_user" class="text-xs font-semibold uppercase tracking-wider text-slate-500">SMTP username</label>
                    <input id="integrations_smtp_user" name="integrations_smtp_user" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('integrations.smtp_user', '')) ?>">
                  </div>
                  <div>
                    <label for="integrations_smtp_password" class="text-xs font-semibold uppercase tracking-wider text-slate-500">SMTP password</label>
                    <?php $smtpMask = SettingsService::getMaskedSecret('integrations.smtp_password'); ?>
                    <input id="integrations_smtp_password" name="integrations_smtp_password" type="password" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm font-mono <?= $smtpMask['configured'] ? 'placeholder-green-600' : '' ?>" placeholder="<?= $smtpMask['configured'] ? 'Configured - leave blank to keep' : '' ?>" value="">
                  </div>
                  <div>
                    <label for="integrations_smtp_encryption" class="text-xs font-semibold uppercase tracking-wider text-slate-500">SMTP encryption</label>
                    <select id="integrations_smtp_encryption" name="integrations_smtp_encryption" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm">
                      <?php $smtpEnc = SettingsService::getGlobal('integrations.smtp_encryption', 'tls'); ?>
                      <option value="tls" <?= $smtpEnc === 'tls' ? 'selected' : '' ?>>TLS</option>
                      <option value="ssl" <?= $smtpEnc === 'ssl' ? 'selected' : '' ?>>SSL</option>
                      <option value="none" <?= $smtpEnc === 'none' ? 'selected' : '' ?>>None</option>
                    </select>
                  </div>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">smart_display</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Media Embeds &amp; Apps</h2>
                      <p class="text-sm text-slate-500">Third-party embeds and connected services.</p>
                    </div>
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">YouTube embeds</div>
                      <div class="text-xs text-slate-500">Allow YouTube videos inside content areas</div>
                    </div>
                    <input type="checkbox" name="integrations_youtube" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('integrations.youtube_embeds_enabled', true) ? 'checked' : '' ?>>
                  </label>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Vimeo embeds</div>
                      <div class="text-xs text-slate-500">Allow Vimeo videos inside content areas</div>
                    </div>
                    <input type="checkbox" name="integrations_vimeo" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('integrations.vimeo_embeds_enabled', true) ? 'checked' : '' ?>>
                  </label>
                  <div>
                    <label for="integrations_zoom_default" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Zoom default URL</label>
                    <input id="integrations_zoom_default" name="integrations_zoom_default" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('integrations.zoom_default_url', '')) ?>">
                  </div>
                  <?php if (SettingsService::isFeatureEnabled('integrations.myob')): ?>
                    <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                      <div>
                        <div class="text-sm font-medium text-gray-900">MYOB integration</div>
                        <div class="text-xs text-slate-500">Sync invoices with MYOB AccountRight</div>
                      </div>
                      <input type="checkbox" name="integrations_myob_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('integrations.myob_enabled', false) ? 'checked' : '' ?>>
                    </label>
                  <?php else: ?>
                    <p class="text-xs text-slate-500">MYOB integration is disabled. Enable via feature flags.</p>
                  <?php endif; ?>
                </div>
              </div>
              <div class="sticky bottom-4 z-10 bg-card-light rounded-2xl border border-gray-100 shadow-soft p-4 flex flex-wrap items-center justify-between gap-3">
                <div class="text-xs text-slate-500 flex items-center gap-2">
                  <span class="material-icons-outlined text-base text-slate-400">history</span>
                  <?php if ($lastModifiedDisplay !== ''): ?>
                    Last modified <?= e($lastModifiedDisplay) ?><?php if (!empty($lastMeta['updated_by'])): ?> by <?= e($lastMeta['updated_by']) ?><?php endif; ?>
                  <?php else: ?>
                    Not modified yet
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-3">
                  <a href="<?= e($cancelHref) ?>" class="text-sm font-medium text-slate-600 hover:text-slate-900 px-4 py-2">Cancel</a>
                  <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">
                    <span class="material-icons-outlined text-base">save</span>
                    Save Settings
                  </button>
                </div>
              </div>
            </form>
          <?php elseif ($section === 'media'): ?>
            <form method="post" class="space-y-6 pb-24">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="media">
              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">cloud_upload</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Upload Limits</h2>
                      <p class="text-sm text-slate-500">Allowed file types and storage ceilings.</p>
                    </div>
                  </div>
                  <div>
                    <label for="media_allowed_types" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Allowed MIME types</label>
                    <textarea id="media_allowed_types" name="media_allowed_types" rows="4" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm"><?= e(implode(', ', SettingsService::getGlobal('media.allowed_types', []))) ?></textarea>
                  </div>
                  <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                      <label for="media_max_upload" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Max upload (MB)</label>
                      <input id="media_max_upload" name="media_max_upload" type="number" step="0.1" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) SettingsService::getGlobal('media.max_upload_mb', 10)) ?>">
                    </div>
                    <div>
                      <label for="media_storage_limit" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Storage limit (MB)</label>
                      <input id="media_storage_limit" name="media_storage_limit" type="number" step="1" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e((string) SettingsService::getGlobal('media.storage_limit_mb', 5120)) ?>">
                    </div>
                  </div>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">tune</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Defaults</h2>
                      <p class="text-sm text-slate-500">Behavioural defaults applied to new uploads.</p>
                    </div>
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Image optimisation on upload</div>
                      <div class="text-xs text-slate-500">Auto-compress JPEG/PNG/WebP at ingest</div>
                    </div>
                    <input type="checkbox" name="media_image_opt" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('media.image_optimization_enabled', false) ? 'checked' : '' ?>>
                  </label>
                  <div>
                    <label for="media_privacy_default" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Default privacy</label>
                    <select id="media_privacy_default" name="media_privacy_default" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm">
                      <?php $privacy = SettingsService::getGlobal('media.privacy_default', 'member'); ?>
                      <option value="public" <?= $privacy === 'public' ? 'selected' : '' ?>>Public</option>
                      <option value="member" <?= $privacy === 'member' ? 'selected' : '' ?>>Member</option>
                      <option value="admin" <?= $privacy === 'admin' ? 'selected' : '' ?>>Admin only</option>
                    </select>
                  </div>
                  <?php if (SettingsService::isFeatureEnabled('media.folder_taxonomy')): ?>
                    <div>
                      <label for="media_folder_taxonomy" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Folder taxonomy</label>
                      <textarea id="media_folder_taxonomy" name="media_folder_taxonomy" rows="3" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm"><?= e(implode(', ', SettingsService::getGlobal('media.folder_taxonomy', []))) ?></textarea>
                    </div>
                  <?php else: ?>
                    <p class="text-xs text-slate-500">Folder taxonomy is disabled. Enable via feature flags.</p>
                  <?php endif; ?>
                </div>
              </div>
              <div class="sticky bottom-4 z-10 bg-card-light rounded-2xl border border-gray-100 shadow-soft p-4 flex flex-wrap items-center justify-between gap-3">
                <div class="text-xs text-slate-500 flex items-center gap-2">
                  <span class="material-icons-outlined text-base text-slate-400">history</span>
                  <?php if ($lastModifiedDisplay !== ''): ?>
                    Last modified <?= e($lastModifiedDisplay) ?><?php if (!empty($lastMeta['updated_by'])): ?> by <?= e($lastMeta['updated_by']) ?><?php endif; ?>
                  <?php else: ?>
                    Not modified yet
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-3">
                  <a href="<?= e($cancelHref) ?>" class="text-sm font-medium text-slate-600 hover:text-slate-900 px-4 py-2">Cancel</a>
                  <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">
                    <span class="material-icons-outlined text-base">save</span>
                    Save Settings
                  </button>
                </div>
              </div>
            </form>
          <?php elseif ($section === 'events'): ?>
            <form method="post" class="space-y-6 pb-24">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="events">
              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">tune</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Defaults</h2>
                      <p class="text-sm text-slate-500">RSVP, visibility, and ticketing defaults for new events.</p>
                    </div>
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">RSVP enabled by default</div>
                      <div class="text-xs text-slate-500">Newly created events allow RSVPs out of the box</div>
                    </div>
                    <input type="checkbox" name="events_rsvp_default" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('events.rsvp_default_enabled', true) ? 'checked' : '' ?>>
                  </label>
                  <div>
                    <label for="events_visibility_default" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Visibility default</label>
                    <select id="events_visibility_default" name="events_visibility_default" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm">
                      <?php $visibility = SettingsService::getGlobal('events.visibility_default', 'member'); ?>
                      <option value="public" <?= $visibility === 'public' ? 'selected' : '' ?>>Public</option>
                      <option value="member" <?= $visibility === 'member' ? 'selected' : '' ?>>Member</option>
                    </select>
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Allow public ticket purchasing</div>
                      <div class="text-xs text-slate-500">Permit guest checkout for paid events</div>
                    </div>
                    <input type="checkbox" name="events_public_ticketing" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('events.public_ticketing_enabled', false) ? 'checked' : '' ?>>
                  </label>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">list_alt</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Fields</h2>
                      <p class="text-sm text-slate-500">Optional fields shown on the event form.</p>
                    </div>
                  </div>
                  <div>
                    <label for="events_timezone" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Timezone</label>
                    <input id="events_timezone" name="events_timezone" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(SettingsService::getGlobal('events.timezone', 'Australia/Sydney')) ?>">
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Map link field</div>
                      <div class="text-xs text-slate-500">Show the map URL input in the editor</div>
                    </div>
                    <input type="checkbox" name="events_include_map" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('events.include_map_link', true) ? 'checked' : '' ?>>
                  </label>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Zoom link field</div>
                      <div class="text-xs text-slate-500">Show the Zoom URL input in the editor</div>
                    </div>
                    <input type="checkbox" name="events_include_zoom" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('events.include_zoom_link', true) ? 'checked' : '' ?>>
                  </label>
                  <a class="inline-flex items-center gap-1 text-sm text-blue-600 hover:underline" href="/admin/index.php?page=events">
                    Go to Events
                    <span class="material-icons-outlined text-base">arrow_forward</span>
                  </a>
                </div>
              </div>
              <div class="sticky bottom-4 z-10 bg-card-light rounded-2xl border border-gray-100 shadow-soft p-4 flex flex-wrap items-center justify-between gap-3">
                <div class="text-xs text-slate-500 flex items-center gap-2">
                  <span class="material-icons-outlined text-base text-slate-400">history</span>
                  <?php if ($lastModifiedDisplay !== ''): ?>
                    Last modified <?= e($lastModifiedDisplay) ?><?php if (!empty($lastMeta['updated_by'])): ?> by <?= e($lastMeta['updated_by']) ?><?php endif; ?>
                  <?php else: ?>
                    Not modified yet
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-3">
                  <a href="<?= e($cancelHref) ?>" class="text-sm font-medium text-slate-600 hover:text-slate-900 px-4 py-2">Cancel</a>
                  <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">
                    <span class="material-icons-outlined text-base">save</span>
                    Save Settings
                  </button>
                </div>
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
              $upgradeMode = (string) SettingsService::getGlobal('membership.upgrade_mode', 'standard');
              if (!in_array($upgradeMode, ['standard', 'custom'], true)) {
                  $upgradeMode = 'standard';
              }
              $upgradeCustomFeeCents = (int) SettingsService::getGlobal('membership.upgrade_custom_fee_cents', 0);
              $upgradeCustomFeeDisplay = number_format($upgradeCustomFeeCents / 100, 2, '.', '');
              $chaptersPdo = db();
              $chaptersHasAbbreviation = ChapterRepository::hasColumn($chaptersPdo, 'abbreviation');
              $chaptersHasState = ChapterRepository::hasColumn($chaptersPdo, 'state');
              $chaptersHasActive = ChapterRepository::hasColumn($chaptersPdo, 'is_active');
              $chaptersHasSortOrder = ChapterRepository::hasColumn($chaptersPdo, 'sort_order');
              $chapters = ChapterRepository::listForManagement($chaptersPdo);
            ?>
            <form method="post" class="space-y-6 pb-24">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="membership_pricing">
              <input type="hidden" name="reset_defaults" id="reset-membership-defaults" value="0">

              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-3">
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-slate-500">workspaces</span>
                  <div>
                    <h2 class="font-display text-lg font-bold text-gray-900">Membership Settings</h2>
                    <p class="text-sm text-slate-500">Manage pricing, member ID sequencing, and chapters.</p>
                  </div>
                </div>
                <p class="text-xs text-slate-500"><?= e(MembershipPricingService::pricingNote()) ?></p>
              </div>

              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-slate-500">tag</span>
                  <div>
                    <h2 class="font-display text-lg font-bold text-gray-900">Member ID Sequencing</h2>
                    <p class="text-sm text-slate-500">Control the starting number and format for full and associate member IDs.</p>
                  </div>
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
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-slate-500">swap_horiz</span>
                  <div>
                    <h2 class="font-display text-lg font-bold text-gray-900">Manual Migration</h2>
                    <p class="text-sm text-slate-500">Control manual migration link availability and expiry windows.</p>
                  </div>
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
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-slate-500">trending_up</span>
                  <div>
                    <h2 class="font-display text-lg font-bold text-gray-900">Associate → Full Upgrade</h2>
                    <p class="text-sm text-slate-500">Controls what an Associate member is charged when they upgrade to a Full membership from their profile. New base member number is assigned, suffix dropped, link to primary member cleared.</p>
                  </div>
                </div>
                <div class="space-y-3">
                  <label class="flex items-start gap-3 text-sm text-slate-700">
                    <input type="radio" name="upgrade_mode" value="standard" class="mt-1" <?= $upgradeMode === 'standard' ? 'checked' : '' ?>>
                    <span>
                      <span class="font-medium">Standard full-member price</span>
                      <span class="block text-xs text-slate-500">Charges the 1-year Full member price from the pricing matrix above (Printed or PDF based on the member's Wings preference).</span>
                    </span>
                  </label>
                  <label class="flex items-start gap-3 text-sm text-slate-700">
                    <input type="radio" name="upgrade_mode" value="custom" class="mt-1" <?= $upgradeMode === 'custom' ? 'checked' : '' ?>>
                    <span class="flex-1">
                      <span class="font-medium">Custom upgrade fee</span>
                      <span class="block text-xs text-slate-500">Charge a flat amount for the upgrade regardless of magazine preference.</span>
                      <label class="mt-2 flex items-center gap-2 text-sm text-slate-700">
                        <span class="text-slate-500">$</span>
                        <input
                          name="upgrade_custom_fee"
                          type="text"
                          inputmode="decimal"
                          class="w-32 rounded-lg border <?= isset($idFieldErrors['upgrade_custom_fee']) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-3 py-2 text-sm"
                          value="<?= e($upgradeCustomFeeDisplay) ?>"
                          placeholder="0.00"
                        >
                      </label>
                      <?php if (isset($idFieldErrors['upgrade_custom_fee'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= e($idFieldErrors['upgrade_custom_fee']) ?></div>
                      <?php endif; ?>
                    </span>
                  </label>
                </div>
              </div>

              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-slate-500">map</span>
                  <div>
                    <h2 class="font-display text-lg font-bold text-gray-900">Chapters</h2>
                    <p class="text-sm text-slate-500">Create chapters and control their display order.</p>
                  </div>
                </div>
                <div class="overflow-x-auto">
                  <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500 border-b">
                      <tr>
                        <?php if ($chaptersHasSortOrder): ?>
                          <th class="py-2 pr-3">Order</th>
                        <?php endif; ?>
                        <th class="py-2 pr-3">Name</th>
                        <?php if ($chaptersHasAbbreviation): ?>
                          <th class="py-2 pr-3">Abbreviation</th>
                        <?php endif; ?>
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
                          <?php if ($chaptersHasAbbreviation): ?>
                            <td class="py-3 pr-3">
                              <input
                                type="text"
                                name="chapters[<?= e((string) $chapter['id']) ?>][abbreviation]"
                                class="w-24 rounded-lg border <?= isset($chapterFieldErrors[$chapter['id']]['abbreviation']) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-2 py-1 text-sm"
                                value="<?= e((string) ($chapter['abbreviation'] ?? '')) ?>"
                                maxlength="16"
                                placeholder="e.g. FCC"
                              >
                              <?php if (isset($chapterFieldErrors[$chapter['id']]['abbreviation'])): ?>
                                <div class="text-xs text-red-600 mt-1"><?= e($chapterFieldErrors[$chapter['id']]['abbreviation']) ?></div>
                              <?php endif; ?>
                            </td>
                          <?php endif; ?>
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
                        <?php if ($chaptersHasAbbreviation): ?>
                          <td class="py-3 pr-3">
                            <input
                              type="text"
                              name="new_chapter_abbreviation"
                              class="w-24 rounded-lg border border-gray-200 bg-white px-2 py-1 text-sm"
                              placeholder="e.g. FCC"
                              maxlength="16"
                            >
                          </td>
                        <?php endif; ?>
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

              <div class="sticky bottom-4 z-10 bg-card-light rounded-2xl border border-gray-100 shadow-soft p-4 flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-3">
                  <div class="text-xs text-slate-500 flex items-center gap-2">
                    <span class="material-icons-outlined text-base text-slate-400">history</span>
                    <?php if ($lastModifiedDisplay !== ''): ?>
                      Last modified <?= e($lastModifiedDisplay) ?><?php if (!empty($lastMeta['updated_by'])): ?> by <?= e($lastMeta['updated_by']) ?><?php endif; ?>
                    <?php else: ?>
                      Not modified yet
                    <?php endif; ?>
                  </div>
                  <button type="button" id="reset-membership-button" class="inline-flex items-center gap-2 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50">
                    <span class="material-icons-outlined text-base">restart_alt</span>
                    Reset to defaults
                  </button>
                </div>
                <div class="flex items-center gap-3">
                  <a href="<?= e($cancelHref) ?>" class="text-sm font-medium text-slate-600 hover:text-slate-900 px-4 py-2">Cancel</a>
                  <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">
                    <span class="material-icons-outlined text-base">save</span>
                    Save Settings
                  </button>
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
          <?php elseif ($section === 'advanced'): ?>
            <?php $flags = SettingsService::getGlobal('advanced.feature_flags', []); ?>
            <form method="post" class="space-y-6 pb-24">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="advanced">
              <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">settings_suggest</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">System</h2>
                      <p class="text-sm text-slate-500">Maintenance mode and global runtime toggles.</p>
                    </div>
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Maintenance mode</div>
                      <div class="text-xs text-slate-500">Blocks non-admin access to the site</div>
                    </div>
                    <input type="checkbox" name="advanced_maintenance" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('advanced.maintenance_mode', false) ? 'checked' : '' ?>>
                  </label>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Disable password reset rate limit</div>
                      <div class="text-xs text-slate-500">Temporary override for high-volume migrations</div>
                    </div>
                    <input type="checkbox" name="advanced_disable_password_reset_rate_limit" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('advanced.disable_password_reset_rate_limit', false) ? 'checked' : '' ?>>
                  </label>
                </div>
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">flag</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Feature Flags</h2>
                      <p class="text-sm text-slate-500">Reveal experimental sections in their respective settings tabs.</p>
                    </div>
                  </div>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Security: 2FA</div>
                      <div class="text-xs text-slate-500">Two-factor authentication subsystem</div>
                    </div>
                    <input type="checkbox" name="flag_security_two_factor" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= !empty($flags['security.two_factor']) ? 'checked' : '' ?>>
                  </label>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Payments: Secondary Stripe account</div>
                      <div class="text-xs text-slate-500">Extra Stripe channel for AGM / events</div>
                    </div>
                    <input type="checkbox" name="flag_payments_secondary" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= !empty($flags['payments.secondary_stripe']) ? 'checked' : '' ?>>
                  </label>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Integrations: MYOB</div>
                      <div class="text-xs text-slate-500">Toggle the MYOB sync controls</div>
                    </div>
                    <input type="checkbox" name="flag_integrations_myob" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= !empty($flags['integrations.myob']) ? 'checked' : '' ?>>
                  </label>
                  <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Media: Folder taxonomy</div>
                      <div class="text-xs text-slate-500">Allow custom folder categories on uploads</div>
                    </div>
                    <input type="checkbox" name="flag_media_folder_taxonomy" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= !empty($flags['media.folder_taxonomy']) ? 'checked' : '' ?>>
                  </label>
                </div>
              </div>
              <div class="sticky bottom-4 z-10 bg-card-light rounded-2xl border border-gray-100 shadow-soft p-4 flex flex-wrap items-center justify-between gap-3">
                <div class="text-xs text-slate-500 flex items-center gap-2">
                  <span class="material-icons-outlined text-base text-slate-400">history</span>
                  <?php if ($lastModifiedDisplay !== ''): ?>
                    Last modified <?= e($lastModifiedDisplay) ?><?php if (!empty($lastMeta['updated_by'])): ?> by <?= e($lastMeta['updated_by']) ?><?php endif; ?>
                  <?php else: ?>
                    Not modified yet
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-3">
                  <a href="<?= e($cancelHref) ?>" class="text-sm font-medium text-slate-600 hover:text-slate-900 px-4 py-2">Cancel</a>
                  <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">
                    <span class="material-icons-outlined text-base">save</span>
                    Save Settings
                  </button>
                </div>
              </div>
            </form>
            <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5 mt-6">
              <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-slate-500">terminal</span>
                  <div>
                    <h2 class="font-display text-lg font-bold text-gray-900">System Logs</h2>
                    <p class="text-sm text-slate-500">PHP error_log output and internal system error messages.</p>
                  </div>
                </div>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                  <input type="hidden" name="action" value="clear_system_log">
                  <input type="hidden" name="section" value="advanced">
                  <button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                    <span class="material-icons-outlined text-base">delete_sweep</span>
                    Clear log
                  </button>
                </form>
              </div>
              <?php if ($systemLog && $systemLog['path'] !== ''): ?>
                <div class="text-xs text-slate-500">
                  Log file: <?= e($systemLog['path']) ?> (<?= e(number_format((int) $systemLog['size'])) ?> bytes)
                </div>
              <?php endif; ?>
              <?php if ($systemLog && $systemLog['error']): ?>
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-xs text-red-700">
                  <?= e($systemLog['error']) ?>
                </div>
              <?php endif; ?>
              <div class="max-h-96 overflow-auto rounded-lg border border-gray-200 bg-white p-3 text-xs text-slate-700">
                <pre class="whitespace-pre-wrap"><?= e($systemLog['content'] ?? 'No log entries yet.') ?></pre>
              </div>
            </div>
            <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5 mt-6">
              <div class="flex items-start gap-3">
                <span class="material-icons-outlined text-slate-500">forward_to_inbox</span>
                <div>
                  <h2 class="font-display text-lg font-bold text-gray-900">Email Log</h2>
                  <p class="text-sm text-slate-500">Latest email sends recorded by the system.</p>
                </div>
              </div>
              <?php if ($emailLogError): ?>
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-xs text-red-700">
                  <?= e($emailLogError) ?>
                </div>
              <?php else: ?>
                <div class="max-h-96 overflow-auto rounded-lg border border-gray-200 bg-white">
                  <table class="min-w-full text-xs">
                    <thead class="bg-gray-50 text-gray-500 uppercase tracking-wide">
                      <tr>
                        <th class="px-3 py-2 text-left">Sent</th>
                        <th class="px-3 py-2 text-left">Recipient</th>
                        <th class="px-3 py-2 text-left">Subject</th>
                        <th class="px-3 py-2 text-left">Time</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                      <?php foreach ($emailLogRows as $row): ?>
                        <tr>
                          <td class="px-3 py-2"><?= !empty($row['sent']) ? 'Yes' : 'No' ?></td>
                          <td class="px-3 py-2"><?= e($row['recipient']) ?></td>
                          <td class="px-3 py-2"><?= e($row['subject']) ?></td>
                          <td class="px-3 py-2"><?= e($row['created_at']) ?></td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (!$emailLogRows): ?>
                        <tr><td colspan="4" class="px-3 py-4 text-center text-gray-500">No email log entries.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
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
<?php include __DIR__ . '/../../../app/Views/partials/help_button.php'; ?>
