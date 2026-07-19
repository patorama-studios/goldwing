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
                SettingsService::setGlobal((int) $user['id'], 'notifications.member_of_year_emails', normalize_text($_POST['notify_moty_emails'] ?? ''));
                SettingsService::setGlobal((int) $user['id'], 'notifications.weekly_digest_enabled', isset($_POST['notify_weekly_digest']));
                SettingsService::setGlobal((int) $user['id'], 'notifications.event_reminders_enabled', isset($_POST['notify_event_reminders']));
                // In-app notification fields are currently hidden from the UI (the bell-inbox feature isn't wired yet).
                // Only persist them when the form actually submitted them, so a save from the redesigned page doesn't blank stored values.
                if (array_key_exists('notify_categories', $_POST)) {
                    SettingsService::setGlobal((int) $user['id'], 'notifications.in_app_categories', normalize_list($_POST['notify_categories']));
                }
                if (array_key_exists('notify_template_basic', $_POST)) {
                    SettingsService::setGlobal((int) $user['id'], 'notifications.template_basic', normalize_text($_POST['notify_template_basic']));
                }
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
                    $sendToMember = isset($_POST['notification_send_to_member'][$key]) ? 1 : 0;
                    $sendToAdmin  = isset($_POST['notification_send_to_admin'][$key])  ? 1 : 0;
                    $entryFromName = normalize_text($_POST['notification_from_name'][$key] ?? ($current['from_name'] ?? ''));
                    $entryFromEmail = normalize_text($_POST['notification_from_email'][$key] ?? ($current['from_email'] ?? ''));
                    $entryReplyTo = normalize_text($_POST['notification_reply_to'][$key] ?? ($current['reply_to'] ?? ''));
                    if ($entryFromEmail !== '' && !is_goldwing_sender($entryFromEmail)) {
                        $errors[] = 'Notification "' . ($definition['label'] ?? $key) . '" has an invalid from address.';
                    }
                    if ($entryReplyTo !== '' && !filter_var($entryReplyTo, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = 'Notification "' . ($definition['label'] ?? $key) . '" has an invalid reply-to address.';
                    }
                    $updatedCatalog[$key] = [
                        'enabled' => isset($_POST['notification_enabled'][$key]),
                        'send_to_member' => $sendToMember,
                        'send_to_admin'  => $sendToAdmin,
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

                // ---- Anchor / expiry / pro-rata toggle ----
                $newConfig = [
                    'anchor_month' => (int) ($_POST['pricing_anchor_month'] ?? MembershipPricingService::DEFAULT_ANCHOR_MONTH),
                    'anchor_day' => (int) ($_POST['pricing_anchor_day'] ?? MembershipPricingService::DEFAULT_ANCHOR_DAY),
                    'expiry_month' => (int) ($_POST['pricing_expiry_month'] ?? MembershipPricingService::DEFAULT_EXPIRY_MONTH),
                    'expiry_day' => (int) ($_POST['pricing_expiry_day'] ?? MembershipPricingService::DEFAULT_EXPIRY_DAY),
                    'currency' => 'AUD',
                    'prorata_enabled' => isset($_POST['prorata_enabled']),
                    'prorata_minimum_months' => 1,
                    'prorata_rounding' => 'nearest_dollar',
                    'renewal_periods' => [],
                    'renewal_prices' => [],
                    'prorata_annual_prices' => [],
                    'joining_enabled' => isset($_POST['joining_enabled']),
                    'joining_fee_cents' => 0,
                    'joining_prices' => [],
                ];

                // ---- Renewal periods ----
                $rawPeriods = $_POST['renewal_periods'] ?? [];
                if (!is_array($rawPeriods)) {
                    $rawPeriods = [];
                }
                $seenIds = [];
                foreach ($rawPeriods as $idx => $period) {
                    if (!is_array($period)) {
                        continue;
                    }
                    $label = normalize_text($period['label'] ?? '');
                    $duration = (int) ($period['duration_months'] ?? 0);
                    $id = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($period['id'] ?? '')) ?? '');
                    $active = isset($period['active']);
                    $sortOrder = (int) ($period['sort_order'] ?? (($idx + 1) * 10));
                    if ($label === '') {
                        $fieldErrors['period.' . $idx . '.label'] = 'Period name is required.';
                        continue;
                    }
                    if ($duration < 1 || $duration > 120) {
                        $fieldErrors['period.' . $idx . '.duration'] = 'Length must be between 1 and 120 months.';
                        continue;
                    }
                    if ($id === '' || isset($seenIds[$id])) {
                        $id = 'P_M' . $duration . '_' . ($idx + 1);
                    }
                    $seenIds[$id] = true;
                    $newConfig['renewal_periods'][] = [
                        'id' => $id,
                        'label' => $label,
                        'duration_months' => $duration,
                        'sort_order' => max(0, $sortOrder),
                        'active' => $active,
                    ];
                }
                if (!$newConfig['renewal_periods']) {
                    $fieldErrors['period.list'] = 'At least one renewal period is required.';
                }

                // ---- Renewal prices ----
                $rawPrices = $_POST['renewal_prices'] ?? [];
                foreach (MembershipPricingService::MAGAZINE_TYPES as $magazineType) {
                    foreach (MembershipPricingService::MEMBERSHIP_TYPES as $membershipType) {
                        foreach ($newConfig['renewal_periods'] as $period) {
                            $fieldKey = 'renewal.' . $magazineType . '.' . $membershipType . '.' . $period['id'];
                            $rawValue = normalize_text($rawPrices[$magazineType][$membershipType][$period['id']] ?? '0');
                            if ($rawValue === '') {
                                $rawValue = '0';
                            }
                            $errorText = null;
                            $amount = parse_money_to_cents($rawValue, $errorText);
                            if ($amount === null) {
                                $fieldErrors[$fieldKey] = $errorText ?: 'Invalid amount.';
                                continue;
                            }
                            $newConfig['renewal_prices'][$magazineType][$membershipType][$period['id']] = max(0, $amount);
                        }
                    }
                }

                // ---- Pro-rata annual base prices ----
                $rawAnnual = $_POST['prorata_annual_prices'] ?? [];
                foreach (MembershipPricingService::MAGAZINE_TYPES as $magazineType) {
                    foreach (MembershipPricingService::MEMBERSHIP_TYPES as $membershipType) {
                        $fieldKey = 'prorata.' . $magazineType . '.' . $membershipType;
                        $rawValue = normalize_text($rawAnnual[$magazineType][$membershipType] ?? '0');
                        if ($rawValue === '') {
                            $rawValue = '0';
                        }
                        $errorText = null;
                        $amount = parse_money_to_cents($rawValue, $errorText);
                        if ($amount === null) {
                            $fieldErrors[$fieldKey] = $errorText ?: 'Invalid amount.';
                            continue;
                        }
                        if ($newConfig['prorata_enabled'] && $amount <= 0) {
                            $fieldErrors[$fieldKey] = 'Set a yearly base price greater than $0, or disable pro-rata.';
                            continue;
                        }
                        $newConfig['prorata_annual_prices'][$magazineType][$membershipType] = max(0, $amount);
                    }
                }

                // ---- Joining matrix (per magazine × type × period × window) ----
                $rawJoining = $_POST['joining_prices'] ?? [];
                $joiningWindowIds = array_keys(MembershipPricingService::JOINING_WINDOWS);
                foreach (MembershipPricingService::MAGAZINE_TYPES as $magazineType) {
                    foreach (MembershipPricingService::MEMBERSHIP_TYPES as $membershipType) {
                        foreach ($newConfig['renewal_periods'] as $period) {
                            foreach ($joiningWindowIds as $winId) {
                                $fieldKey = 'joining.' . $magazineType . '.' . $membershipType . '.' . $period['id'] . '.' . $winId;
                                $rawValue = normalize_text($rawJoining[$magazineType][$membershipType][$period['id']][$winId] ?? '0');
                                if ($rawValue === '') {
                                    $rawValue = '0';
                                }
                                $errorText = null;
                                $amount = parse_money_to_cents($rawValue, $errorText);
                                if ($amount === null) {
                                    $fieldErrors[$fieldKey] = $errorText ?: 'Invalid amount.';
                                    continue;
                                }
                                $newConfig['joining_prices'][$magazineType][$membershipType][$period['id']][$winId] = max(0, $amount);
                            }
                        }
                    }
                }

                // ---- One-off joining fee (wizard default) ----
                $joiningFeeError = null;
                $joiningFeeCents = parse_money_to_cents(normalize_text($_POST['joining_fee'] ?? '0') ?: '0', $joiningFeeError);
                if ($joiningFeeCents === null) {
                    $fieldErrors['joining_fee'] = $joiningFeeError ?: 'Invalid amount.';
                } else {
                    $newConfig['joining_fee_cents'] = max(0, $joiningFeeCents);
                }

                if ($newConfig['anchor_month'] < 1 || $newConfig['anchor_month'] > 12) {
                    $fieldErrors['anchor_month'] = 'Anchor month must be between 1 and 12.';
                }
                if ($newConfig['expiry_month'] < 1 || $newConfig['expiry_month'] > 12) {
                    $fieldErrors['expiry_month'] = 'Expiry month must be between 1 and 12.';
                }

                // Member ID numbering settings UI removed — admins now type the
                // member number manually on approval. Stored settings are left
                // untouched in case any legacy formatter still reads them.

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

                if ($newChapterName === '' && ($newChapterState !== '' || $newChapterSortOrder > 0)) {
                    $chapterFieldErrors['new']['name'] = 'Name is required for new chapters.';
                }

                if (!$fieldErrors && !$idFieldErrors && !$chapterFieldErrors) {
                    if (isset($_POST['reset_defaults']) && $_POST['reset_defaults'] === '1') {
                        $newConfig = MembershipPricingService::defaultConfig();
                    }
                    MembershipPricingService::updateConfig((int) $user['id'], $newConfig);
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
                'membership.pricing.config',
                'membership.pricing_matrix',
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
                  ['label' => 'Developer Access', 'icon' => 'vpn_key', 'desc' => 'Grant or revoke the developer\'s timed admin access.', 'href' => '/admin/settings/developer-access.php', 'permission' => 'admin.settings.general.manage'],
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
                  <div class="rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 flex items-start gap-3">
                    <span class="material-icons-outlined text-primary text-base mt-0.5">info</span>
                    <div class="text-sm text-slate-700">
                      Card processing fee settings have moved to <a href="/admin/settings/index.php?section=payments#fee-passthrough" class="font-semibold text-primary hover:underline">Payments (Stripe) settings</a> — they apply to both store checkouts and membership payments from one place.
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

              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-slate-500">percent</span>
                  <div>
                    <h2 class="font-display text-lg font-bold text-gray-900">Card Processing Fee Pass-through</h2>
                    <p class="text-sm text-slate-500 mt-1">When enabled, Stripe's processing fee is added to the checkout total and clearly labelled as a "Card processing fee (Stripe)" line item. Applies to both store purchases and membership payments.</p>
                  </div>
                </div>
                <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer border border-gray-100">
                  <div>
                    <div class="text-sm font-medium text-gray-900">Pass card processing fee to member</div>
                    <div class="text-xs text-slate-500">Member pays the Stripe fee — Goldwing receives the full membership/order amount</div>
                  </div>
                  <input type="checkbox" name="store_pass_fees" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= SettingsService::getGlobal('store.pass_stripe_fees', false) ? 'checked' : '' ?>>
                </label>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label for="pay_fee_percent" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Fee % (e.g. 1.75)</label>
                    <div class="relative mt-2">
                      <input id="pay_fee_percent" name="store_fee_percent" type="number" step="0.01" min="0" max="10" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm pr-7" value="<?= e((string) SettingsService::getGlobal('store.stripe_fee_percent', 0)) ?>">
                      <span class="absolute inset-y-0 right-2.5 flex items-center text-slate-400 text-sm pointer-events-none">%</span>
                    </div>
                    <p class="text-xs text-slate-400 mt-1">Check Stripe Dashboard → Settings → Pricing for your exact rate</p>
                  </div>
                  <div>
                    <label for="pay_fee_fixed" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Fixed amount (e.g. 0.30)</label>
                    <div class="relative mt-2">
                      <span class="absolute inset-y-0 left-2.5 flex items-center text-slate-400 text-sm pointer-events-none">$</span>
                      <input id="pay_fee_fixed" name="store_fee_fixed" type="number" step="0.01" min="0" max="5" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm pl-6" value="<?= e((string) SettingsService::getGlobal('store.stripe_fee_fixed', 0)) ?>">
                    </div>
                    <p class="text-xs text-slate-400 mt-1">Stripe's per-transaction fixed fee — ~$0.30 for most AU accounts</p>
                  </div>
                </div>
                <div class="rounded-lg bg-amber-50 border border-amber-100 px-4 py-3 text-xs text-amber-800">
                  <strong>Note:</strong> Since Goldwing is not GST-registered, the GST Stripe charges on their own fee cannot be claimed back. Setting the fee pass-through ensures the club is not out of pocket on processing costs. The fee shown to members covers both the Stripe % rate and the per-transaction fixed amount.
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
            <?php
            $notificationDefinitions = NotificationService::definitions();
            $notificationCatalog = NotificationService::getCatalogSettings();
            $defaultNotificationKey = array_key_first($notificationDefinitions) ?? '';
            $categoryMeta = [
                'security' => ['label' => 'Security & Sign-in', 'icon' => 'lock_outline'],
                'payments' => ['label' => 'Payments & Membership', 'icon' => 'payments'],
                'orders'   => ['label' => 'Store Orders', 'icon' => 'shopping_bag'],
                'admin'    => ['label' => 'Admin & Approvals', 'icon' => 'task_alt'],
                'general'  => ['label' => 'General', 'icon' => 'mail_outline'],
            ];
            $groupedDefinitions = [];
            foreach ($notificationDefinitions as $key => $definition) {
                $category = $definition['category'] ?? 'general';
                if (!isset($categoryMeta[$category])) {
                    $category = 'general';
                }
                $groupedDefinitions[$category][$key] = $definition;
            }
            $notifyFromName = SettingsService::getGlobal('notifications.from_name', '');
            $notifyFromEmail = SettingsService::getGlobal('notifications.from_email', '');
            $notifyReplyTo = SettingsService::getGlobal('notifications.reply_to', '');
            $notifyAdminEmails = SettingsService::getGlobal('notifications.admin_emails', '');
            $notifyMotyEmails = SettingsService::getGlobal('notifications.member_of_year_emails', 'ausalper@gmail.com');
            $notifyWeeklyDigest = SettingsService::getGlobal('notifications.weekly_digest_enabled', false);
            $notifyEventReminders = SettingsService::getGlobal('notifications.event_reminders_enabled', true);
            $senderIdentitySummary = trim(($notifyFromName !== '' ? $notifyFromName : 'Australian Goldwing Association') . ($notifyFromEmail !== '' ? ' <' . $notifyFromEmail . '>' : ''));
            ?>
            <form method="post" class="space-y-6 pb-24" id="notifications-form">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="section" value="notifications">
              <input type="hidden" name="notification_active_key" id="notification-active-key" value="<?= e($defaultNotificationKey) ?>">

              <!-- Sender Identity (compact strip) -->
              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                  <div class="flex items-start gap-3 min-w-0">
                    <span class="material-icons-outlined text-slate-500">mark_email_read</span>
                    <div class="min-w-0">
                      <h2 class="font-display text-lg font-bold text-gray-900">Sender Identity</h2>
                      <p class="text-sm text-slate-500">How notification emails appear in the recipient's inbox.</p>
                      <div class="mt-3 space-y-1 text-sm text-slate-700">
                        <div class="flex flex-wrap items-center gap-x-2">
                          <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">From</span>
                          <span class="font-medium text-gray-900 truncate" id="sender-identity-from"><?= e($senderIdentitySummary) ?></span>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-2">
                          <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Reply-to</span>
                          <span class="text-slate-700 truncate" id="sender-identity-reply"><?= e($notifyReplyTo !== '' ? $notifyReplyTo : '—') ?></span>
                        </div>
                      </div>
                    </div>
                  </div>
                  <button type="button" id="edit-identity-btn" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 px-3 py-2 text-sm font-medium text-slate-700">
                    <span class="material-icons-outlined text-base">edit</span>
                    Edit identity
                  </button>
                </div>
                <div class="flex items-center gap-2 rounded-lg bg-amber-50 text-amber-700 px-3 py-2 text-xs">
                  <span class="material-icons-outlined text-base">warning_amber</span>
                  SPF/DKIM/DMARC are not configured yet. Expect reduced deliverability until DNS records are added.
                </div>
                <div class="grid gap-3 md:grid-cols-2 pt-1">
                  <label class="flex items-start justify-between gap-3 rounded-lg border border-gray-100 p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Weekly digest</div>
                      <div class="text-xs text-slate-500">Send a Monday summary to subscribers</div>
                    </div>
                    <input type="checkbox" name="notify_weekly_digest" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= $notifyWeeklyDigest ? 'checked' : '' ?>>
                  </label>
                  <label class="flex items-start justify-between gap-3 rounded-lg border border-gray-100 p-3 hover:bg-gray-50 cursor-pointer">
                    <div>
                      <div class="text-sm font-medium text-gray-900">Event reminders</div>
                      <div class="text-xs text-slate-500">Auto-send the day before events</div>
                    </div>
                    <input type="checkbox" name="notify_event_reminders" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= $notifyEventReminders ? 'checked' : '' ?>>
                  </label>
                </div>
                <!-- Sender identity inputs live here so they post with the main form; the dialog edits them in place. -->
                <input type="hidden" id="notify_from_name" name="notify_from_name" value="<?= e($notifyFromName) ?>">
                <input type="hidden" id="notify_from_email" name="notify_from_email" value="<?= e($notifyFromEmail) ?>">
                <input type="hidden" id="notify_reply_to" name="notify_reply_to" value="<?= e($notifyReplyTo) ?>">
                <input type="hidden" id="notify_admin_emails" name="notify_admin_emails" value="<?= e($notifyAdminEmails) ?>">
              </div>

              <!-- Member of the Year nomination recipient -->
              <div class="bg-card-light rounded-2xl border border-gray-100 p-6">
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-slate-500">emoji_events</span>
                  <div class="min-w-0 flex-1">
                    <h2 class="font-display text-lg font-bold text-gray-900">Member of the Year nominations</h2>
                    <p class="text-sm text-slate-500">Nomination submissions are emailed to this address. Separate multiple addresses with commas.</p>
                    <input type="text" name="notify_moty_emails" value="<?= e($notifyMotyEmails) ?>"
                           class="mt-3 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:ring-primary focus:border-primary"
                           placeholder="ausalper@gmail.com">
                  </div>
                </div>
              </div>

              <!-- Notification Templates (two-pane editor) -->
              <div class="bg-card-light rounded-2xl border border-gray-100 overflow-hidden">
                <div class="flex flex-wrap items-start justify-between gap-3 p-6 border-b border-gray-100">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">edit_note</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Notification Templates</h2>
                      <p class="text-sm text-slate-500"><?= count($notificationDefinitions) ?> templates across <?= count($groupedDefinitions) ?> categories. Pick one to edit.</p>
                    </div>
                  </div>
                </div>
                <div class="grid lg:grid-cols-[320px_minmax(0,1fr)]">
                  <!-- LEFT RAIL -->
                  <aside class="border-r border-gray-100 bg-gray-50/50">
                    <div class="p-4 border-b border-gray-100">
                      <div class="relative">
                        <span class="material-icons-outlined absolute left-3 top-1/2 -translate-y-1/2 text-base text-slate-400">search</span>
                        <input id="notification-search" type="search" placeholder="Search templates…" class="w-full rounded-lg border border-gray-200 bg-white pl-9 pr-3 py-2 text-sm">
                      </div>
                    </div>
                    <nav class="max-h-[640px] overflow-y-auto" id="notification-list">
                      <?php foreach ($groupedDefinitions as $category => $items): ?>
                        <?php $meta = $categoryMeta[$category]; ?>
                        <div class="notification-group" data-category="<?= e($category) ?>">
                          <div class="flex items-center gap-2 px-4 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500 bg-gray-50">
                            <span class="material-icons-outlined text-base"><?= e($meta['icon']) ?></span>
                            <span><?= e($meta['label']) ?></span>
                            <span class="ml-auto text-slate-400 normal-case tracking-normal font-medium"><?= count($items) ?></span>
                          </div>
                          <?php foreach ($items as $key => $definition): ?>
                            <?php $settings = $notificationCatalog[$key] ?? ($definition['defaults'] ?? []); ?>
                            <?php $isEnabled = !empty($settings['enabled']); ?>
                            <button type="button" data-notification-target="<?= e($key) ?>" data-search="<?= e(strtolower($definition['label'] . ' ' . ($definition['description'] ?? ''))) ?>" class="notification-list-item w-full flex items-center gap-3 px-4 py-2.5 text-left text-sm border-l-2 border-transparent hover:bg-white">
                              <span class="notification-status-dot inline-block h-2 w-2 rounded-full <?= $isEnabled ? 'bg-green-500' : 'bg-slate-300' ?>" data-status-for="<?= e($key) ?>"></span>
                              <span class="flex-1 min-w-0 truncate text-slate-700"><?= e($definition['label']) ?></span>
                              <span class="material-icons-outlined text-base text-slate-300">chevron_right</span>
                            </button>
                          <?php endforeach; ?>
                        </div>
                      <?php endforeach; ?>
                      <div id="notification-empty" class="hidden px-4 py-8 text-center text-sm text-slate-500">No templates match your search.</div>
                    </nav>
                  </aside>

                  <!-- RIGHT PANE: all panels rendered, only the selected one is visible -->
                  <section class="p-6 space-y-5 bg-white">
                    <?php $firstNotification = true; ?>
                    <?php foreach ($notificationDefinitions as $key => $definition): ?>
                      <?php $settings = $notificationCatalog[$key] ?? ($definition['defaults'] ?? []); ?>
                      <div class="notification-panel<?= $firstNotification ? '' : ' hidden' ?>" data-notification-key="<?= e($key) ?>">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                          <div class="min-w-0">
                            <h3 class="text-base font-semibold text-gray-900"><?= e($definition['label']) ?></h3>
                            <p class="text-xs text-slate-500 mt-1"><?= e($definition['description']) ?></p>
                          </div>
                          <div class="flex items-center gap-2">
                            <label class="inline-flex items-center gap-2 rounded-full bg-gray-50 border border-gray-200 px-3 py-1.5 text-xs font-medium text-slate-700 cursor-pointer">
                              <input type="checkbox" name="notification_enabled[<?= e($key) ?>]" class="notification-enabled-toggle h-3.5 w-3.5 rounded border-gray-300 text-primary focus:ring-primary" data-key="<?= e($key) ?>" <?= !empty($settings['enabled']) ? 'checked' : '' ?>>
                              <span>Enabled</span>
                            </label>
                            <button type="button" class="notification-send-test inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 px-3 py-1.5 text-xs font-medium text-slate-700" data-key="<?= e($key) ?>">
                              <span class="material-icons-outlined text-sm">outgoing_mail</span>
                              Send test
                            </button>
                          </div>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs">
                          <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 text-slate-600 px-2.5 py-1">
                            <span class="material-icons-outlined text-sm">bolt</span>
                            Trigger: <?= e($definition['trigger']) ?>
                          </span>
                        </div>

                        <!-- Tabs -->
                        <div class="mt-5 border-b border-gray-100 flex gap-4 text-sm">
                          <button type="button" class="notification-tab-btn -mb-px border-b-2 border-gray-900 px-1 py-2 font-semibold text-gray-900" data-tab="content">Content</button>
                          <button type="button" class="notification-tab-btn -mb-px border-b-2 border-transparent px-1 py-2 font-medium text-slate-500 hover:text-gray-900" data-tab="recipients">Recipients</button>
                          <button type="button" class="notification-tab-btn -mb-px border-b-2 border-transparent px-1 py-2 font-medium text-slate-500 hover:text-gray-900" data-tab="sender">Sender overrides</button>
                        </div>

                        <!-- Tab: Content -->
                        <div class="notification-tab-panel mt-5 space-y-4" data-tab="content">
                          <!-- Edit / Preview view toggle -->
                          <div class="flex items-center justify-between gap-3">
                            <div class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5 text-sm">
                              <button type="button" class="notification-view-btn rounded-md px-3 py-1.5 font-medium bg-white shadow-sm text-gray-900" data-view="edit">
                                <span class="material-icons-outlined text-sm align-middle">edit</span>
                                Edit
                              </button>
                              <button type="button" class="notification-view-btn rounded-md px-3 py-1.5 font-medium text-slate-500 hover:text-gray-900" data-view="preview">
                                <span class="material-icons-outlined text-sm align-middle">visibility</span>
                                Preview
                              </button>
                            </div>
                            <span class="notification-view-hint text-xs text-slate-400" data-view-hint="edit">Live preview available — click Preview to switch view</span>
                          </div>
                          <!-- Edit view -->
                          <div class="notification-view-panel space-y-4" data-view="edit">
                            <label class="block text-sm">
                              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Subject</span>
                              <input name="notification_subject[<?= e($key) ?>]" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm notification-subject-input" data-key="<?= e($key) ?>" value="<?= e($settings['subject'] ?? '') ?>">
                            </label>
                            <?php if (!empty($definition['placeholders'])): ?>
                              <div class="flex flex-wrap items-center gap-2 text-xs">
                                <span class="text-slate-500 font-semibold uppercase tracking-wider">Insert tag:</span>
                                <?php foreach ($definition['placeholders'] as $tag): ?>
                                  <button type="button" class="notification-merge-tag inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 text-amber-800 px-2.5 py-1 font-mono hover:bg-amber-100 transition" data-tag="{{<?= e($tag) ?>}}">
                                    <span class="material-icons-outlined text-sm">add</span>
                                    {{<?= e($tag) ?>}}
                                  </button>
                                <?php endforeach; ?>
                              </div>
                            <?php endif; ?>
                            <div class="space-y-2">
                              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Body</p>
                              <textarea name="notification_body[<?= e($key) ?>]" data-wysiwyg rows="14" placeholder="Write the message body…"><?= e($settings['body'] ?? '') ?></textarea>
                            </div>
                          </div>
                          <!-- Preview view -->
                          <div class="notification-view-panel hidden space-y-2" data-view="preview">
                            <div class="flex items-center justify-between">
                              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Branded email preview</p>
                              <span class="text-xs text-slate-400">Sample merge tag values shown</span>
                            </div>
                            <div class="rounded-lg border border-gray-200 bg-gray-50 overflow-hidden">
                              <iframe class="notification-preview-iframe w-full h-[720px] bg-white" data-key="<?= e($key) ?>" sandbox="allow-same-origin" title="Email preview"></iframe>
                            </div>
                            <p class="text-xs text-slate-400">Real merge tags get filled in when the email is sent. Unknown tags stay as <code class="font-mono">{{placeholder}}</code>.</p>
                          </div>
                        </div>

                        <!-- Tab: Recipients -->
                        <div class="notification-tab-panel mt-5 space-y-4 hidden" data-tab="recipients">
                          <?php
                            // Migrate legacy recipient_mode to checkbox values if not yet converted
                            if (isset($settings['send_to_member']) || isset($settings['send_to_admin'])) {
                                $chkMember = !empty($settings['send_to_member']);
                                $chkAdmin  = !empty($settings['send_to_admin']);
                            } else {
                                $legacyMode = $settings['recipient_mode'] ?? $definition['defaults']['recipient_mode'] ?? 'primary';
                                $chkMember = in_array($legacyMode, ['primary', 'both'], true);
                                $chkAdmin  = in_array($legacyMode, ['admin', 'both'], true);
                            }
                          ?>
                          <div>
                            <span class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">Send this email to</span>
                            <div class="space-y-2">
                              <label class="flex items-center gap-3 cursor-pointer select-none">
                                <input type="checkbox" name="notification_send_to_member[<?= e($key) ?>]" value="1" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= $chkMember ? 'checked' : '' ?>>
                                <span class="text-sm text-gray-800 font-medium">Member <span class="font-normal text-slate-500">(the person this email is about)</span></span>
                              </label>
                              <label class="flex items-center gap-3 cursor-pointer select-none">
                                <input type="checkbox" name="notification_send_to_admin[<?= e($key) ?>]" value="1" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= $chkAdmin ? 'checked' : '' ?>>
                                <span class="text-sm text-gray-800 font-medium">Admins <span class="font-normal text-slate-500">(the admin email list in Sender Identity)</span></span>
                              </label>
                            </div>
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1">Also send to these addresses <span class="font-normal normal-case text-slate-400">(optional, comma-separated)</span></label>
                            <input name="notification_custom_recipients[<?= e($key) ?>]" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e($settings['custom_recipients'] ?? '') ?>" placeholder="addr1@example.com, addr2@example.com">
                          </div>
                        </div>

                        <!-- Tab: Sender overrides -->
                        <div class="notification-tab-panel mt-5 space-y-4 hidden" data-tab="sender">
                          <p class="text-xs text-slate-500">Leave blank to inherit the global Sender Identity values above.</p>
                          <div class="grid gap-4 md:grid-cols-3">
                            <label class="block text-sm">
                              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">From name</span>
                              <input name="notification_from_name[<?= e($key) ?>]" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e($settings['from_name'] ?? '') ?>">
                            </label>
                            <label class="block text-sm">
                              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">From email</span>
                              <input name="notification_from_email[<?= e($key) ?>]" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e($settings['from_email'] ?? '') ?>">
                            </label>
                            <label class="block text-sm">
                              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Reply-to</span>
                              <input name="notification_reply_to[<?= e($key) ?>]" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e($settings['reply_to'] ?? '') ?>">
                            </label>
                          </div>
                        </div>
                      </div>
                      <?php $firstNotification = false; ?>
                    <?php endforeach; ?>
                  </section>
                </div>
              </div>

              <!-- Scheduled sends (slim collapsed strip) -->
              <details class="bg-card-light rounded-2xl border border-gray-100 group">
                <summary class="flex items-center justify-between gap-3 p-4 cursor-pointer list-none">
                  <div class="flex items-center gap-3">
                    <span class="material-icons-outlined text-slate-500">schedule_send</span>
                    <div>
                      <div class="text-sm font-semibold text-gray-900">Scheduled sends</div>
                      <div class="text-xs text-slate-500">Weekly digest &amp; event reminders. Configure cadence above.</div>
                    </div>
                  </div>
                  <span class="material-icons-outlined text-slate-400 group-open:rotate-180 transition-transform">expand_more</span>
                </summary>
                <div class="px-4 pb-4 text-sm text-slate-600">
                  Cadence toggles live in the Sender Identity card. Per-event reminder timing will be managed from the Events settings page.
                </div>
              </details>

              <!-- Sticky save bar -->
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

            <!-- Edit identity dialog -->
            <div id="identity-dialog-backdrop" class="hidden fixed inset-0 z-40 bg-black/40 items-center justify-center p-4">
              <div class="bg-white rounded-2xl shadow-xl max-w-lg w-full">
                <div class="p-6 border-b border-gray-100 flex items-start justify-between">
                  <div>
                    <h3 class="font-display text-lg font-bold text-gray-900">Sender Identity</h3>
                    <p class="text-sm text-slate-500">Used as the default From / Reply-to for all notification emails.</p>
                  </div>
                  <button type="button" id="identity-dialog-close" class="text-slate-400 hover:text-slate-600">
                    <span class="material-icons-outlined">close</span>
                  </button>
                </div>
                <div class="p-6 space-y-4">
                  <label class="block text-sm">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">From name</span>
                    <input id="identity-from-name" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e($notifyFromName) ?>">
                  </label>
                  <label class="block text-sm">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">From email</span>
                    <input id="identity-from-email" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e($notifyFromEmail) ?>">
                    <span class="mt-1 block text-xs text-slate-400">Must end with @goldwing.org.au.</span>
                  </label>
                  <label class="block text-sm">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Reply-to</span>
                    <input id="identity-reply-to" type="email" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e($notifyReplyTo) ?>">
                  </label>
                  <label class="block text-sm">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Admin notification emails</span>
                    <textarea id="identity-admin-emails" rows="2" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" placeholder="One address per line or comma-separated"><?= e($notifyAdminEmails) ?></textarea>
                  </label>
                </div>
                <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-end gap-3 bg-gray-50 rounded-b-2xl">
                  <button type="button" id="identity-dialog-cancel" class="text-sm font-medium text-slate-600 hover:text-slate-900 px-4 py-2">Cancel</button>
                  <button type="button" id="identity-dialog-apply" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">
                    <span class="material-icons-outlined text-base">check</span>
                    Apply
                  </button>
                </div>
              </div>
            </div>

            <!-- Hidden test-send form (one-shot, posts the active template key) -->
            <form method="post" id="send-test-form" class="hidden">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="send_test_notification">
              <input type="hidden" name="section" value="notifications">
              <input type="hidden" name="test_notification_key" id="send-test-key" value="">
              <input type="hidden" name="test_notification_email" value="">
            </form>

            <script>
              (() => {
                const activeKeyInput = document.getElementById('notification-active-key');
                const panels = Array.from(document.querySelectorAll('.notification-panel'));
                const listItems = Array.from(document.querySelectorAll('.notification-list-item'));
                const groups = Array.from(document.querySelectorAll('.notification-group'));
                const searchInput = document.getElementById('notification-search');
                const emptyState = document.getElementById('notification-empty');
                const sendTestForm = document.getElementById('send-test-form');
                const sendTestKey = document.getElementById('send-test-key');

                // ---- Preview helpers (declared before setActive so it can call them) ----
                const previewCsrf = '<?= e(Csrf::token()) ?>';
                const previewTimers = new WeakMap();

                const findQuillForPanel = (panel) => {
                  if (!window.Quill) return null;
                  const wrapper = panel.querySelector('.gw-wysiwyg');
                  if (!wrapper) return null;
                  const editorRoot = wrapper.firstElementChild;
                  if (!editorRoot) return null;
                  try { return window.Quill.find(editorRoot); } catch (e) { return null; }
                };

                const updatePreview = async (panel) => {
                  if (!panel) return;
                  const key = panel.dataset.notificationKey;
                  const iframe = panel.querySelector('.notification-preview-iframe');
                  const subjectInput = panel.querySelector('.notification-subject-input');
                  const bodyTextarea = panel.querySelector('textarea[name="notification_body[' + key + ']"]');
                  if (!iframe || !bodyTextarea) return;
                  try {
                    const resp = await fetch('/api/admin/settings/notifications/preview', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': previewCsrf },
                      body: JSON.stringify({
                        csrf_token: previewCsrf,
                        key: key,
                        subject: subjectInput ? subjectInput.value : '',
                        body: bodyTextarea.value || ''
                      }),
                    });
                    if (!resp.ok) return;
                    const data = await resp.json();
                    if (data && typeof data.html === 'string') iframe.srcdoc = data.html;
                  } catch (e) { /* soft-fail; keep prior preview */ }
                };

                const schedulePreview = (panel, delay) => {
                  if (!panel) return;
                  const wait = typeof delay === 'number' ? delay : 350;
                  const prior = previewTimers.get(panel);
                  if (prior) clearTimeout(prior);
                  const t = setTimeout(() => updatePreview(panel), wait);
                  previewTimers.set(panel, t);
                };

                const setActive = (key) => {
                  panels.forEach((panel) => {
                    panel.classList.toggle('hidden', panel.dataset.notificationKey !== key);
                  });
                  listItems.forEach((item) => {
                    const matches = item.dataset.notificationTarget === key;
                    item.classList.toggle('bg-white', matches);
                    item.classList.toggle('border-l-amber-500', matches);
                    item.classList.toggle('font-semibold', matches);
                  });
                  if (activeKeyInput) {
                    activeKeyInput.value = key;
                  }
                  const visible = panels.find((panel) => panel.dataset.notificationKey === key);
                  if (visible) {
                    visible.querySelectorAll('.notification-tab-btn').forEach((btn, i) => {
                      const active = i === 0;
                      btn.classList.toggle('border-gray-900', active);
                      btn.classList.toggle('text-gray-900', active);
                      btn.classList.toggle('font-semibold', active);
                      btn.classList.toggle('border-transparent', !active);
                      btn.classList.toggle('text-slate-500', !active);
                      btn.classList.toggle('font-medium', !active);
                    });
                    visible.querySelectorAll('.notification-tab-panel').forEach((panel) => {
                      panel.classList.toggle('hidden', panel.dataset.tab !== 'content');
                    });
                    schedulePreview(visible, 0);
                  }
                };

                listItems.forEach((item) => {
                  item.addEventListener('click', () => setActive(item.dataset.notificationTarget));
                });

                // Initial active state
                if (listItems.length && activeKeyInput) {
                  setActive(activeKeyInput.value || listItems[0].dataset.notificationTarget);
                }

                // Search filter
                if (searchInput) {
                  searchInput.addEventListener('input', () => {
                    const query = searchInput.value.trim().toLowerCase();
                    let visibleCount = 0;
                    listItems.forEach((item) => {
                      const haystack = item.dataset.search || '';
                      const matches = !query || haystack.includes(query);
                      item.classList.toggle('hidden', !matches);
                      if (matches) visibleCount++;
                    });
                    groups.forEach((group) => {
                      const anyVisible = Array.from(group.querySelectorAll('.notification-list-item')).some((item) => !item.classList.contains('hidden'));
                      group.classList.toggle('hidden', !anyVisible);
                    });
                    if (emptyState) emptyState.classList.toggle('hidden', visibleCount > 0);
                  });
                }

                // Tab switching (delegated)
                document.querySelectorAll('.notification-panel').forEach((panel) => {
                  panel.addEventListener('click', (event) => {
                    const btn = event.target.closest('.notification-tab-btn');
                    if (!btn) return;
                    const tab = btn.dataset.tab;
                    panel.querySelectorAll('.notification-tab-btn').forEach((b) => {
                      const active = b.dataset.tab === tab;
                      b.classList.toggle('border-gray-900', active);
                      b.classList.toggle('text-gray-900', active);
                      b.classList.toggle('font-semibold', active);
                      b.classList.toggle('border-transparent', !active);
                      b.classList.toggle('text-slate-500', !active);
                      b.classList.toggle('font-medium', !active);
                    });
                    panel.querySelectorAll('.notification-tab-panel').forEach((p) => {
                      p.classList.toggle('hidden', p.dataset.tab !== tab);
                    });
                  });
                });

                // Live status dot when toggling enabled
                document.querySelectorAll('.notification-enabled-toggle').forEach((toggle) => {
                  toggle.addEventListener('change', () => {
                    const dot = document.querySelector('[data-status-for="' + toggle.dataset.key + '"]');
                    if (!dot) return;
                    dot.classList.toggle('bg-green-500', toggle.checked);
                    dot.classList.toggle('bg-slate-300', !toggle.checked);
                  });
                });

                // Send test (posts the hidden form for the active key)
                document.querySelectorAll('.notification-send-test').forEach((btn) => {
                  btn.addEventListener('click', () => {
                    if (!sendTestForm || !sendTestKey) return;
                    sendTestKey.value = btn.dataset.key;
                    sendTestForm.submit();
                  });
                });

                // Edit-identity dialog
                const backdrop = document.getElementById('identity-dialog-backdrop');
                const fromNameInput = document.getElementById('notify_from_name');
                const fromEmailInput = document.getElementById('notify_from_email');
                const replyToInput = document.getElementById('notify_reply_to');
                const adminEmailsInput = document.getElementById('notify_admin_emails');
                const summaryFrom = document.getElementById('sender-identity-from');
                const summaryReply = document.getElementById('sender-identity-reply');
                const dialogFromName = document.getElementById('identity-from-name');
                const dialogFromEmail = document.getElementById('identity-from-email');
                const dialogReplyTo = document.getElementById('identity-reply-to');
                const dialogAdminEmails = document.getElementById('identity-admin-emails');

                const openDialog = () => {
                  if (!backdrop) return;
                  // Hydrate dialog from current hidden inputs in case Apply was cancelled previously
                  if (dialogFromName) dialogFromName.value = fromNameInput.value;
                  if (dialogFromEmail) dialogFromEmail.value = fromEmailInput.value;
                  if (dialogReplyTo) dialogReplyTo.value = replyToInput.value;
                  if (dialogAdminEmails) dialogAdminEmails.value = adminEmailsInput.value;
                  backdrop.classList.remove('hidden');
                  backdrop.classList.add('flex');
                };
                const closeDialog = () => {
                  if (!backdrop) return;
                  backdrop.classList.add('hidden');
                  backdrop.classList.remove('flex');
                };
                const applyDialog = () => {
                  fromNameInput.value = (dialogFromName && dialogFromName.value || '').trim();
                  fromEmailInput.value = (dialogFromEmail && dialogFromEmail.value || '').trim();
                  replyToInput.value = (dialogReplyTo && dialogReplyTo.value || '').trim();
                  adminEmailsInput.value = (dialogAdminEmails && dialogAdminEmails.value || '').trim();
                  if (summaryFrom) {
                    const name = fromNameInput.value || 'Australian Goldwing Association';
                    summaryFrom.textContent = fromEmailInput.value ? (name + ' <' + fromEmailInput.value + '>') : name;
                  }
                  if (summaryReply) {
                    summaryReply.textContent = replyToInput.value || '—';
                  }
                  closeDialog();
                };
                const editBtn = document.getElementById('edit-identity-btn');
                if (editBtn) editBtn.addEventListener('click', openDialog);
                const closeBtn = document.getElementById('identity-dialog-close');
                if (closeBtn) closeBtn.addEventListener('click', closeDialog);
                const cancelBtn = document.getElementById('identity-dialog-cancel');
                if (cancelBtn) cancelBtn.addEventListener('click', closeDialog);
                const applyBtn = document.getElementById('identity-dialog-apply');
                if (applyBtn) applyBtn.addEventListener('click', applyDialog);
                if (backdrop) {
                  backdrop.addEventListener('click', (event) => {
                    if (event.target === backdrop) closeDialog();
                  });
                }

                // Rich-text editor: Quill auto-mounts on textarea[data-wysiwyg] via /assets/js/goldwing-wysiwyg.js

                // Subject input → live preview
                document.querySelectorAll('.notification-subject-input').forEach((input) => {
                  input.addEventListener('input', () => {
                    const panel = input.closest('.notification-panel');
                    if (panel) schedulePreview(panel);
                  });
                });

                // Edit / Preview view toggle within Content tab
                document.querySelectorAll('.notification-panel').forEach((panel) => {
                  const viewBtns = Array.from(panel.querySelectorAll('.notification-view-btn'));
                  const viewPanels = Array.from(panel.querySelectorAll('.notification-view-panel'));
                  const hint = panel.querySelector('.notification-view-hint');
                  if (!viewBtns.length || !viewPanels.length) return;
                  const setView = (view) => {
                    viewBtns.forEach((b) => {
                      const active = b.dataset.view === view;
                      b.classList.toggle('bg-white', active);
                      b.classList.toggle('shadow-sm', active);
                      b.classList.toggle('text-gray-900', active);
                      b.classList.toggle('text-slate-500', !active);
                    });
                    viewPanels.forEach((p) => {
                      p.classList.toggle('hidden', p.dataset.view !== view);
                    });
                    if (hint) {
                      hint.textContent = view === 'edit'
                        ? 'Live preview available — click Preview to switch view'
                        : 'Showing rendered email with sample merge values — click Edit to keep writing';
                    }
                    if (view === 'preview') schedulePreview(panel, 0);
                  };
                  viewBtns.forEach((b) => b.addEventListener('click', () => setView(b.dataset.view)));
                });

                // Merge-tag chip → insert at Quill cursor
                document.querySelectorAll('.notification-merge-tag').forEach((chip) => {
                  chip.addEventListener('click', () => {
                    const panel = chip.closest('.notification-panel');
                    if (!panel) return;
                    const q = findQuillForPanel(panel);
                    if (!q) return;
                    q.focus();
                    const range = q.getSelection(true) || { index: q.getLength(), length: 0 };
                    const tag = chip.dataset.tag || '';
                    q.insertText(range.index, tag, 'user');
                    q.setSelection(range.index + tag.length, 0, 'user');
                    schedulePreview(panel, 50);
                  });
                });

                // Hook Quill text-change once instances are mounted, then trigger initial preview.
                const wireQuillPreview = () => {
                  if (!window.Quill) { setTimeout(wireQuillPreview, 200); return; }
                  let pending = false;
                  panels.forEach((panel) => {
                    const q = findQuillForPanel(panel);
                    if (!q) { pending = true; return; }
                    if (!q.__gwPreviewWired) {
                      q.on('text-change', () => schedulePreview(panel));
                      q.__gwPreviewWired = true;
                    }
                  });
                  if (pending) { setTimeout(wireQuillPreview, 200); return; }
                  const activePanel = panels.find((p) => !p.classList.contains('hidden'));
                  if (activePanel) schedulePreview(activePanel, 0);
                };
                wireQuillPreview();
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
              $pricingConfig = MembershipPricingService::getConfig();
              $renewalPeriods = $pricingConfig['renewal_periods'];
              $renewalPrices = $pricingConfig['renewal_prices'];
              $proRataAnnual = $pricingConfig['prorata_annual_prices'];
              $proRataEnabled = !empty($pricingConfig['prorata_enabled']);
              $joiningEnabled = !empty($pricingConfig['joining_enabled']);
              $joiningPrices = $pricingConfig['joining_prices'] ?? [];
              $joiningWindows = MembershipPricingService::JOINING_WINDOWS;
              $joiningFeeDisplay = number_format(((int) ($pricingConfig['joining_fee_cents'] ?? 0)) / 100, 2, '.', '');
              $anchorMonth = (int) $pricingConfig['anchor_month'];
              $anchorDay = (int) $pricingConfig['anchor_day'];
              $expiryMonth = (int) $pricingConfig['expiry_month'];
              $expiryDay = (int) $pricingConfig['expiry_day'];
              $monthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
              $monthAbbr = [1=>'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
              $magazineIcons = ['PRINTED' => ['icon' => 'menu_book', 'label' => 'Printed Wings'], 'PDF' => ['icon' => 'picture_as_pdf', 'label' => 'PDF Wings']];
              $membershipIcons = ['FULL' => ['icon' => 'person', 'label' => 'Full member'], 'ASSOCIATE' => ['icon' => 'group', 'label' => 'Associate member']];
              $proRataPreview = [];
              foreach (MembershipPricingService::MAGAZINE_TYPES as $mt) {
                  foreach (MembershipPricingService::MEMBERSHIP_TYPES as $tt) {
                      $proRataPreview[$mt][$tt] = MembershipPricingService::buildProRataPreview($mt, $tt);
                  }
              }
              $todayProRata = [];
              foreach (MembershipPricingService::MAGAZINE_TYPES as $mt) {
                  foreach (MembershipPricingService::MEMBERSHIP_TYPES as $tt) {
                      $todayProRata[$mt][$tt] = MembershipPricingService::calculateProRataCents($mt, $tt);
                  }
              }
              $monthsRemainingToday = MembershipPricingService::monthsRemainingUntilExpiry();
              // Legacy view kept for any helper references below.
              $pricing = MembershipPricingService::getMembershipPricing();
              $pricingMatrix = $pricing['matrix'] ?? [];
              $periods = MembershipPricingService::periodDefinitions();
              $fieldErrors = $fieldErrors ?? [];
              $idFieldErrors = $idFieldErrors ?? [];
              $chapterFieldErrors = $chapterFieldErrors ?? [];
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

              <!-- Member ID, Associate → Full Upgrade, and Chapter Creation are
                   rendered below the pricing block — see further down this file. -->

              <!-- Membership year card with calendar strip -->
              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4" data-pricing-card="year">
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-slate-500">calendar_month</span>
                  <div>
                    <h2 class="font-display text-lg font-bold text-gray-900">Membership year</h2>
                    <p class="text-sm text-slate-500">Every membership is anchored to a single date. New joiners pay pro-rata for the months until the next expiry; renewals run for whole years from the anchor date.</p>
                  </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                  <label class="text-sm text-slate-600">Membership year starts on
                    <div class="mt-2 flex items-center gap-2">
                      <select name="pricing_anchor_day" class="rounded-lg border border-gray-200 bg-white px-2 py-2 text-sm w-20" data-pricing-input="anchor_day">
                        <?php for ($d = 1; $d <= 28; $d++): ?>
                          <option value="<?= $d ?>" <?= $d === $anchorDay ? 'selected' : '' ?>><?= $d ?></option>
                        <?php endfor; ?>
                      </select>
                      <select name="pricing_anchor_month" class="rounded-lg border border-gray-200 bg-white px-2 py-2 text-sm flex-1" data-pricing-input="anchor_month">
                        <?php foreach ($monthNames as $num => $name): ?>
                          <option value="<?= $num ?>" <?= $num === $anchorMonth ? 'selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </label>
                  <label class="text-sm text-slate-600">Membership year ends on
                    <div class="mt-2 flex items-center gap-2">
                      <select name="pricing_expiry_day" class="rounded-lg border border-gray-200 bg-white px-2 py-2 text-sm w-20" data-pricing-input="expiry_day">
                        <?php for ($d = 1; $d <= 31; $d++): ?>
                          <option value="<?= $d ?>" <?= $d === $expiryDay ? 'selected' : '' ?>><?= $d ?></option>
                        <?php endfor; ?>
                      </select>
                      <select name="pricing_expiry_month" class="rounded-lg border border-gray-200 bg-white px-2 py-2 text-sm flex-1" data-pricing-input="expiry_month">
                        <?php foreach ($monthNames as $num => $name): ?>
                          <option value="<?= $num ?>" <?= $num === $expiryMonth ? 'selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </label>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-4">
                  <p class="text-xs uppercase tracking-wide text-slate-400 font-semibold mb-2">Year timeline</p>
                  <div class="relative" data-pricing-timeline>
                    <div class="grid grid-cols-12 gap-1">
                      <?php
                        for ($i = 0; $i < 12; $i++):
                          $monthNum = (($anchorMonth - 1 + $i) % 12) + 1;
                          $isAnchor = $i === 0;
                          $isExpiry = $monthNum === $expiryMonth;
                          $isCurrent = $monthNum === (int) date('n');
                      ?>
                        <div class="relative">
                          <div class="h-12 rounded-lg flex items-end justify-center pb-1 text-[10px] font-semibold border <?= $isAnchor ? 'bg-emerald-50 border-emerald-300 text-emerald-700' : ($isExpiry ? 'bg-rose-50 border-rose-300 text-rose-700' : 'bg-slate-50 border-slate-200 text-slate-600') ?>">
                            <?= e($monthAbbr[$monthNum]) ?>
                          </div>
                          <?php if ($isCurrent): ?>
                            <div class="absolute -top-2 left-1/2 -translate-x-1/2 px-1.5 py-0.5 rounded-full bg-blue-600 text-white text-[9px] font-bold uppercase shadow">Today</div>
                          <?php endif; ?>
                        </div>
                      <?php endfor; ?>
                    </div>
                    <div class="mt-2 flex items-center justify-between text-xs text-slate-500">
                      <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-emerald-200 border border-emerald-400"></span> Anchor (year starts)</span>
                      <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-rose-200 border border-rose-400"></span> Expiry (all memberships end)</span>
                      <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-blue-600"></span> Today (<?= e(date('j M')) ?>)</span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Renewal periods + matrix card -->
              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4" data-pricing-card="renewal">
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-slate-500">replay</span>
                  <div>
                    <h2 class="font-display text-lg font-bold text-gray-900">Renewal pricing</h2>
                    <p class="text-sm text-slate-500">Set the periods existing members can renew for, and the price for each. Renewals always start on the anchor date and run whole years. Drag the handle on the left to reorder periods.</p>
                  </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                  <table class="w-full text-sm" data-renewal-periods-table>
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                      <tr>
                        <th class="px-3 py-2 w-8"></th>
                        <th class="px-3 py-2">Period name</th>
                        <th class="px-3 py-2 w-32">Length (months)</th>
                        <th class="px-3 py-2 w-24 text-center">Active</th>
                        <th class="px-3 py-2 w-12"></th>
                      </tr>
                    </thead>
                    <tbody class="divide-y" data-renewal-periods-body>
                      <?php foreach ($renewalPeriods as $idx => $period): ?>
                        <tr data-period-row data-period-id="<?= e($period['id']) ?>">
                          <td class="px-3 py-3 text-slate-400 cursor-move select-none" data-drag-handle title="Drag to reorder">
                            <span class="material-icons-outlined">drag_indicator</span>
                          </td>
                          <td class="px-3 py-3">
                            <input type="hidden" name="renewal_periods[<?= $idx ?>][id]" value="<?= e($period['id']) ?>">
                            <input type="hidden" name="renewal_periods[<?= $idx ?>][sort_order]" value="<?= (int) ($period['sort_order'] ?? ($idx * 10)) ?>" data-sort-order>
                            <input type="text" name="renewal_periods[<?= $idx ?>][label]" value="<?= e($period['label']) ?>" required class="w-full rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-sm" placeholder="e.g. 1 Year">
                          </td>
                          <td class="px-3 py-3">
                            <input type="number" name="renewal_periods[<?= $idx ?>][duration_months]" value="<?= (int) $period['duration_months'] ?>" min="1" max="120" required class="w-full rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-sm" data-period-months>
                          </td>
                          <td class="px-3 py-3 text-center">
                            <label class="inline-flex items-center justify-center">
                              <input type="checkbox" name="renewal_periods[<?= $idx ?>][active]" value="1" <?= !empty($period['active']) ? 'checked' : '' ?> class="rounded border-gray-300 text-emerald-600">
                            </label>
                          </td>
                          <td class="px-3 py-3 text-right">
                            <button type="button" class="text-rose-500 hover:text-rose-700 inline-flex items-center" data-remove-period title="Remove this period">
                              <span class="material-icons-outlined text-base">delete</span>
                            </button>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                  <div class="px-3 py-2 border-t bg-slate-50">
                    <button type="button" class="inline-flex items-center gap-1 text-sm font-semibold text-emerald-700 hover:text-emerald-900" data-add-period>
                      <span class="material-icons-outlined text-base">add_circle</span>
                      Add another renewal period
                    </button>
                  </div>
                </div>

                <p class="text-xs uppercase tracking-wide text-slate-400 font-semibold mt-4">Renewal price per period</p>
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                  <table class="w-full text-sm" data-renewal-prices-table>
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                      <tr>
                        <th class="px-3 py-2">Magazine</th>
                        <th class="px-3 py-2">Member type</th>
                        <?php foreach ($renewalPeriods as $period): ?>
                          <th class="px-3 py-2 text-center" data-price-col-period="<?= e($period['id']) ?>">
                            <?= e($period['label']) ?>
                          </th>
                        <?php endforeach; ?>
                      </tr>
                    </thead>
                    <tbody class="divide-y">
                      <?php foreach (MembershipPricingService::MAGAZINE_TYPES as $magazineType): ?>
                        <?php foreach (MembershipPricingService::MEMBERSHIP_TYPES as $membershipType):
                          $magMeta = $magazineIcons[$magazineType];
                          $memMeta = $membershipIcons[$membershipType];
                          $magBadge = $magazineType === 'PRINTED' ? 'bg-amber-100 text-amber-800' : 'bg-sky-100 text-sky-800';
                          $memBadge = $membershipType === 'FULL' ? 'bg-violet-100 text-violet-800' : 'bg-teal-100 text-teal-800';
                        ?>
                          <tr data-price-row-magazine="<?= e($magazineType) ?>" data-price-row-membership="<?= e($membershipType) ?>">
                            <td class="px-3 py-3">
                              <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full text-xs font-semibold <?= e($magBadge) ?>">
                                <span class="material-icons-outlined text-sm"><?= e($magMeta['icon']) ?></span>
                                <?= e($magMeta['label']) ?>
                              </span>
                            </td>
                            <td class="px-3 py-3">
                              <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full text-xs font-semibold <?= e($memBadge) ?>">
                                <span class="material-icons-outlined text-sm"><?= e($memMeta['icon']) ?></span>
                                <?= e($memMeta['label']) ?>
                              </span>
                            </td>
                            <?php foreach ($renewalPeriods as $period):
                              $valueCents = $renewalPrices[$magazineType][$membershipType][$period['id']] ?? 0;
                              $fieldKey = 'renewal.' . $magazineType . '.' . $membershipType . '.' . $period['id'];
                            ?>
                              <td class="px-3 py-3 align-top" data-price-cell-period="<?= e($period['id']) ?>">
                                <label class="flex items-center gap-1">
                                  <span class="text-slate-400 text-xs">$</span>
                                  <input
                                    name="renewal_prices[<?= e($magazineType) ?>][<?= e($membershipType) ?>][<?= e($period['id']) ?>]"
                                    type="text"
                                    inputmode="decimal"
                                    class="w-24 rounded-lg border <?= isset($fieldErrors[$fieldKey]) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-2 py-1 text-sm text-right"
                                    value="<?= e(format_cents_to_dollars($valueCents)) ?>"
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
                <p class="text-xs text-slate-500 mt-2 flex items-center gap-1">
                  <span class="material-icons-outlined text-sm text-slate-400">info</span>
                  Add a new period above and a blank price column will appear here. Save the form to lock it in.
                </p>
              </div>

              <!-- New-member joining prices card -->
              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4" data-pricing-card="joining" data-tour="joining-card">
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-slate-500">person_add</span>
                  <div>
                    <h2 class="font-display text-lg font-bold text-gray-900">New-member joining prices</h2>
                    <p class="text-sm text-slate-500">Exact prices a <em>brand-new</em> member pays, per term and when in the year they join. These follow the committee's fee matrix cell-for-cell and already include the one-off joining fee. The join window is set automatically from the join date (thirds of the year): <strong>Start of year</strong> = before 1&nbsp;Dec, <strong>After 1&nbsp;Dec</strong>, <strong>After 1&nbsp;Apr</strong>.</p>
                  </div>
                </div>

                <label class="flex items-center gap-3 text-sm text-slate-700" data-tour="joining-enabled">
                  <input type="checkbox" name="joining_enabled" value="1" <?= $joiningEnabled ? 'checked' : '' ?> class="rounded border-gray-300 text-emerald-600">
                  Use this joining matrix for new members (recommended). When off, new joiners fall back to the pro-rata engine below.
                </label>

                <?php $joiningTableIdx = 0; foreach ($renewalPeriods as $period): if (empty($period['active'])) continue; ?>
                  <div class="rounded-xl border border-slate-200 bg-white overflow-hidden"<?= $joiningTableIdx === 0 ? ' data-tour="joining-matrix"' : '' ?>>
                    <?php $joiningTableIdx++; ?>
                    <div class="px-3 py-2 bg-slate-50 border-b text-xs font-semibold uppercase tracking-wide text-slate-600">
                      <?= e($period['label']) ?> term <span class="text-slate-400 normal-case font-normal">(<?= (int) $period['duration_months'] ?> months)</span>
                    </div>
                    <div class="overflow-x-auto">
                      <table class="w-full text-sm">
                        <thead class="bg-white text-left text-xs uppercase text-slate-500">
                          <tr>
                            <th class="px-3 py-2">Magazine</th>
                            <th class="px-3 py-2">Member type</th>
                            <?php foreach ($joiningWindows as $winId => $winLabel): ?>
                              <th class="px-3 py-2 text-center"><?= e($winLabel) ?></th>
                            <?php endforeach; ?>
                          </tr>
                        </thead>
                        <tbody class="divide-y">
                          <?php foreach (MembershipPricingService::MAGAZINE_TYPES as $magazineType): ?>
                            <?php foreach (MembershipPricingService::MEMBERSHIP_TYPES as $membershipType):
                              $magMeta = $magazineIcons[$magazineType];
                              $memMeta = $membershipIcons[$membershipType];
                              $magBadge = $magazineType === 'PRINTED' ? 'bg-amber-100 text-amber-800' : 'bg-sky-100 text-sky-800';
                              $memBadge = $membershipType === 'FULL' ? 'bg-violet-100 text-violet-800' : 'bg-teal-100 text-teal-800';
                            ?>
                              <tr>
                                <td class="px-3 py-3">
                                  <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full text-xs font-semibold <?= e($magBadge) ?>">
                                    <span class="material-icons-outlined text-sm"><?= e($magMeta['icon']) ?></span>
                                    <?= e($magMeta['label']) ?>
                                  </span>
                                </td>
                                <td class="px-3 py-3">
                                  <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full text-xs font-semibold <?= e($memBadge) ?>">
                                    <span class="material-icons-outlined text-sm"><?= e($memMeta['icon']) ?></span>
                                    <?= e($memMeta['label']) ?>
                                  </span>
                                </td>
                                <?php foreach ($joiningWindows as $winId => $winLabel):
                                  $valueCents = $joiningPrices[$magazineType][$membershipType][$period['id']][$winId] ?? 0;
                                  $fieldKey = 'joining.' . $magazineType . '.' . $membershipType . '.' . $period['id'] . '.' . $winId;
                                ?>
                                  <td class="px-3 py-3 align-top">
                                    <label class="flex items-center gap-1 justify-center">
                                      <span class="text-slate-400 text-xs">$</span>
                                      <input
                                        name="joining_prices[<?= e($magazineType) ?>][<?= e($membershipType) ?>][<?= e($period['id']) ?>][<?= e($winId) ?>]"
                                        type="text"
                                        inputmode="decimal"
                                        class="w-20 rounded-lg border <?= isset($fieldErrors[$fieldKey]) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-2 py-1 text-sm text-right"
                                        value="<?= e(format_cents_to_dollars($valueCents)) ?>"
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
                <?php endforeach; ?>

                <label class="flex items-center gap-3 py-1 text-sm" data-tour="joining-fee">
                  <span class="text-slate-700 flex-1">One-off joining fee <span class="text-slate-400">(default used by the Add Member wizard; the matrix above already bakes it into each cell)</span></span>
                  <span class="text-slate-400 text-xs">$</span>
                  <input
                    name="joining_fee"
                    type="text"
                    inputmode="decimal"
                    class="w-24 rounded-lg border <?= isset($fieldErrors['joining_fee']) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-2 py-1 text-sm text-right"
                    value="<?= e($joiningFeeDisplay) ?>"
                  >
                </label>
                <?php if (isset($fieldErrors['joining_fee'])): ?>
                  <div class="text-xs text-red-600"><?= e($fieldErrors['joining_fee']) ?></div>
                <?php endif; ?>
              </div>

              <!-- Pro-rata for new joins card -->
              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4" data-pricing-card="prorata">
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-slate-500">trending_down</span>
                  <div>
                    <h2 class="font-display text-lg font-bold text-gray-900">Pro-rata for new joiners</h2>
                    <p class="text-sm text-slate-500">When someone joins mid-year, they only pay for the months between now and the next expiry. Set the full-year base price; the system calculates the rest automatically.</p>
                  </div>
                </div>

                <label class="flex items-center gap-3 text-sm text-slate-700">
                  <input type="checkbox" name="prorata_enabled" value="1" <?= $proRataEnabled ? 'checked' : '' ?> class="rounded border-gray-300 text-emerald-600" data-pricing-input="prorata_enabled">
                  Enable pro-rata pricing for new joiners
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                  <?php foreach (MembershipPricingService::MAGAZINE_TYPES as $magazineType):
                    $magMeta = $magazineIcons[$magazineType];
                    $magBadge = $magazineType === 'PRINTED' ? 'bg-amber-100 text-amber-800' : 'bg-sky-100 text-sky-800';
                  ?>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                      <div class="flex items-center gap-2 mb-3">
                        <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full text-xs font-semibold <?= e($magBadge) ?>">
                          <span class="material-icons-outlined text-sm"><?= e($magMeta['icon']) ?></span>
                          <?= e($magMeta['label']) ?>
                        </span>
                        <span class="text-xs text-slate-500">full-year base price</span>
                      </div>
                      <?php foreach (MembershipPricingService::MEMBERSHIP_TYPES as $membershipType):
                        $memMeta = $membershipIcons[$membershipType];
                        $memBadge = $membershipType === 'FULL' ? 'text-violet-700' : 'text-teal-700';
                        $valueCents = $proRataAnnual[$magazineType][$membershipType] ?? 0;
                        $fieldKey = 'prorata.' . $magazineType . '.' . $membershipType;
                      ?>
                        <label class="flex items-center gap-3 py-2">
                          <span class="material-icons-outlined text-base <?= e($memBadge) ?>"><?= e($memMeta['icon']) ?></span>
                          <span class="text-sm text-slate-700 flex-1"><?= e($memMeta['label']) ?></span>
                          <span class="text-slate-400 text-xs">$</span>
                          <input
                            name="prorata_annual_prices[<?= e($magazineType) ?>][<?= e($membershipType) ?>]"
                            type="text"
                            inputmode="decimal"
                            class="w-24 rounded-lg border <?= isset($fieldErrors[$fieldKey]) ? 'border-red-300' : 'border-gray-200' ?> bg-white px-2 py-1 text-sm text-right"
                            value="<?= e(format_cents_to_dollars($valueCents)) ?>"
                            data-prorata-input="<?= e($magazineType) ?>.<?= e($membershipType) ?>"
                          >
                          <span class="text-xs text-slate-400">/yr</span>
                        </label>
                        <?php if (isset($fieldErrors[$fieldKey])): ?>
                          <div class="text-xs text-red-600 mt-1 pl-8"><?= e($fieldErrors[$fieldKey]) ?></div>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </div>
                  <?php endforeach; ?>
                </div>

                <p class="text-xs uppercase tracking-wide text-slate-400 font-semibold mt-2">Calendar preview — what a Full Printed joiner would pay each month</p>
                <div class="rounded-xl border border-slate-200 bg-white p-3 overflow-x-auto">
                  <div class="grid grid-cols-12 gap-1 min-w-[640px]">
                    <?php foreach ($proRataPreview['PRINTED']['FULL'] as $row):
                      $isCurrent = !empty($row['is_current']);
                      $heightPct = max(15, min(100, ($row['months_remaining'] / 12) * 100));
                    ?>
                      <div class="relative">
                        <div class="h-24 rounded-lg border <?= $isCurrent ? 'border-blue-400 bg-blue-50' : 'border-slate-200 bg-slate-50' ?> flex flex-col items-center justify-end p-1">
                          <div class="w-full rounded-md bg-gradient-to-t from-emerald-500 to-emerald-300" style="height: <?= e((string) round($heightPct)) ?>%"></div>
                          <div class="text-[10px] font-semibold text-slate-700 mt-1"><?= e($monthAbbr[$row['month']]) ?></div>
                          <div class="text-[10px] font-bold text-emerald-700">$<?= e(number_format(($row['amount_cents'] ?? 0) / 100, 0)) ?></div>
                        </div>
                        <?php if ($isCurrent): ?>
                          <div class="absolute -top-2 left-1/2 -translate-x-1/2 px-1.5 py-0.5 rounded-full bg-blue-600 text-white text-[9px] font-bold uppercase shadow">Now</div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <p class="text-xs text-slate-500 mt-2 flex items-center gap-1">
                    <span class="material-icons-outlined text-sm text-slate-400">timeline</span>
                    Bar height = portion of the year the joiner gets. Joiners closer to expiry pay less because they get less.
                  </p>
                </div>
              </div>

              <!-- Live preview card -->
              <div class="bg-gradient-to-br from-slate-900 to-slate-700 text-white rounded-2xl p-6 space-y-4" data-pricing-card="preview">
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-emerald-300">visibility</span>
                  <div>
                    <h2 class="font-display text-lg font-bold">If a member checked out today…</h2>
                    <p class="text-sm text-slate-300">These are the live prices the public site would quote right now (<?= e(date('j M Y')) ?>). Edit prices above and save to update.</p>
                  </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                  <div class="rounded-xl bg-white/10 backdrop-blur p-4 space-y-3">
                    <p class="text-xs uppercase tracking-wide text-emerald-300 font-semibold flex items-center gap-1">
                      <span class="material-icons-outlined text-sm">person_add</span> New joiner — pro-rata
                    </p>
                    <p class="text-xs text-slate-300"><?= (int) $monthsRemainingToday ?> months remaining until <?= (int) $expiryDay ?> <?= e($monthNames[$expiryMonth]) ?>.</p>
                    <table class="w-full text-sm">
                      <tbody class="divide-y divide-white/10">
                        <?php foreach (MembershipPricingService::MAGAZINE_TYPES as $mt): ?>
                          <?php foreach (MembershipPricingService::MEMBERSHIP_TYPES as $tt):
                            $cents = (int) ($todayProRata[$mt][$tt] ?? 0);
                          ?>
                            <tr>
                              <td class="py-1.5"><?= e($magazineIcons[$mt]['label']) ?> · <?= e($membershipIcons[$tt]['label']) ?></td>
                              <td class="py-1.5 text-right font-bold text-emerald-300">$<?= e(number_format($cents / 100, 2)) ?></td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                  <div class="rounded-xl bg-white/10 backdrop-blur p-4 space-y-3">
                    <p class="text-xs uppercase tracking-wide text-emerald-300 font-semibold flex items-center gap-1">
                      <span class="material-icons-outlined text-sm">autorenew</span> Renewer — fixed periods
                    </p>
                    <p class="text-xs text-slate-300">Whole-year renewals starting <?= (int) $anchorDay ?> <?= e($monthNames[$anchorMonth]) ?>.</p>
                    <?php foreach ($renewalPeriods as $period): ?>
                      <?php if (empty($period['active'])) continue; ?>
                      <div>
                        <p class="text-xs font-semibold uppercase text-white/70 mt-2"><?= e($period['label']) ?> (<?= (int) $period['duration_months'] ?> months)</p>
                        <table class="w-full text-sm">
                          <tbody class="divide-y divide-white/10">
                            <?php foreach (MembershipPricingService::MAGAZINE_TYPES as $mt): ?>
                              <?php foreach (MembershipPricingService::MEMBERSHIP_TYPES as $tt):
                                $cents = (int) ($renewalPrices[$mt][$tt][$period['id']] ?? 0);
                              ?>
                                <tr>
                                  <td class="py-1"><?= e($magazineIcons[$mt]['label']) ?> · <?= e($membershipIcons[$tt]['label']) ?></td>
                                  <td class="py-1 text-right font-bold text-emerald-300">$<?= e(number_format($cents / 100, 2)) ?></td>
                                </tr>
                              <?php endforeach; ?>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>

              <!-- Help / explainer card -->
              <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-3">
                <div class="flex items-start gap-3">
                  <span class="material-icons-outlined text-slate-500">help_outline</span>
                  <div>
                    <h2 class="font-display text-lg font-bold text-gray-900">How this works</h2>
                  </div>
                </div>
                <ol class="text-sm text-slate-600 list-decimal pl-5 space-y-2">
                  <li><strong>Membership year</strong> sets the anchor (when new memberships start) and the expiry date (when every membership ends, all at once).</li>
                  <li><strong>Renewal periods</strong> are the options existing members see at renewal time. Each one runs in whole years from the anchor — no pro-rata. Add, rename, reorder or disable any of them.</li>
                  <li><strong>Pro-rata</strong> only applies to brand-new joiners. The system charges a fair fraction of the annual price based on how many months remain until expiry. The preview above shows what every month of the year would cost.</li>
                  <li>The dark <strong>"If a member checked out today"</strong> card shows the live quotes — exactly what someone visiting the site this minute would be charged.</li>
                </ol>
              </div>

              <!-- Member-admin settings: Associate → Full Upgrade -->
              <div class="grid gap-6">
                <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">trending_up</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Associate → Full Upgrade</h2>
                      <p class="text-sm text-slate-500">Controls what an Associate member is charged when they upgrade to a Full membership from their profile. Member numbers are assigned manually by an admin on application approval.</p>
                    </div>
                  </div>
                  <div class="space-y-3">
                    <label class="flex items-start gap-3 text-sm text-slate-700">
                      <input type="radio" name="upgrade_mode" value="standard" class="mt-1" <?= $upgradeMode === 'standard' ? 'checked' : '' ?>>
                      <span>
                        <span class="font-medium">Standard full-member price</span>
                        <span class="block text-xs text-slate-500">Charges the 1-year Full member renewal price from the pricing block above (Printed or PDF based on the member's Wings preference).</span>
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
              </div>

              <!-- Chapter Creation: native <details> accordion, closed by default
                   (auto-opens if there are validation errors on chapter inputs) -->
              <details class="bg-card-light rounded-2xl border border-gray-100 group" <?= !empty($chapterFieldErrors) ? 'open' : '' ?>>
                <summary class="cursor-pointer list-none p-6 flex items-center justify-between gap-3 select-none">
                  <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-slate-500">map</span>
                    <div>
                      <h2 class="font-display text-lg font-bold text-gray-900">Chapter Creation</h2>
                      <p class="text-sm text-slate-500"><?= (int) count($chapters) ?> chapter<?= count($chapters) === 1 ? '' : 's' ?> configured. Click to add a new chapter or edit display order.</p>
                    </div>
                  </div>
                  <span class="material-icons-outlined text-slate-400 transition-transform group-open:rotate-180">expand_more</span>
                </summary>
                <div class="px-6 pb-6 space-y-4 border-t border-gray-100 pt-4">
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
              </details>

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
                  <button type="submit" data-tour="pricing-save" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">
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

            <script>
              // Membership pricing — add/remove/drag renewal periods + keep
              // the price-matrix columns in sync. Vanilla JS, no deps.
              (function () {
                const body = document.querySelector('[data-renewal-periods-body]');
                const addBtn = document.querySelector('[data-add-period]');
                const pricesTable = document.querySelector('[data-renewal-prices-table]');
                if (!body || !addBtn || !pricesTable) return;

                const magazineTypes = <?= json_encode(MembershipPricingService::MAGAZINE_TYPES) ?>;
                const membershipTypes = <?= json_encode(MembershipPricingService::MEMBERSHIP_TYPES) ?>;
                const magazineMeta = <?= json_encode(['PRINTED' => ['icon' => 'menu_book', 'label' => 'Printed Wings'], 'PDF' => ['icon' => 'picture_as_pdf', 'label' => 'PDF Wings']]) ?>;
                const membershipMeta = <?= json_encode(['FULL' => ['icon' => 'person', 'label' => 'Full member'], 'ASSOCIATE' => ['icon' => 'group', 'label' => 'Associate member']]) ?>;

                let counter = body.querySelectorAll('[data-period-row]').length;

                function reindex() {
                  Array.from(body.querySelectorAll('[data-period-row]')).forEach((row, idx) => {
                    row.querySelectorAll('input[name^="renewal_periods["]').forEach((input) => {
                      input.name = input.name.replace(/^renewal_periods\[\d+\]/, `renewal_periods[${idx}]`);
                    });
                    const sortInput = row.querySelector('[data-sort-order]');
                    if (sortInput) sortInput.value = (idx + 1) * 10;
                  });
                }

                function genId(months) {
                  const yrs = Math.floor(months / 12);
                  const rem = months % 12;
                  const base = (rem === 0 && yrs >= 1) ? `P_${yrs}Y` : `P_M${months}`;
                  let id = base;
                  let n = 1;
                  while (body.querySelector(`[data-period-id="${CSS.escape(id)}"]`)) {
                    id = `${base}_${++n}`;
                  }
                  return id;
                }

                function addPeriodColumn(period) {
                  // Header
                  const headRow = pricesTable.querySelector('thead tr');
                  const th = document.createElement('th');
                  th.className = 'px-3 py-2 text-center';
                  th.dataset.priceColPeriod = period.id;
                  th.textContent = period.label || '';
                  headRow.appendChild(th);

                  // Body cells
                  pricesTable.querySelectorAll('tbody tr').forEach((tr) => {
                    const mag = tr.dataset.priceRowMagazine || magazineTypes[0];
                    const mem = tr.dataset.priceRowMembership || membershipTypes[0];

                    const td = document.createElement('td');
                    td.className = 'px-3 py-3 align-top';
                    td.dataset.priceCellPeriod = period.id;
                    td.innerHTML = `
                      <label class="flex items-center gap-1">
                        <span class="text-slate-400 text-xs">$</span>
                        <input
                          name="renewal_prices[${mag}][${mem}][${period.id}]"
                          type="text"
                          inputmode="decimal"
                          class="w-24 rounded-lg border border-gray-200 bg-white px-2 py-1 text-sm text-right"
                          value="0.00"
                        >
                      </label>`;
                    tr.appendChild(td);
                  });
                }

                function removePeriodColumn(periodId) {
                  pricesTable.querySelectorAll(`[data-price-col-period="${CSS.escape(periodId)}"]`).forEach((el) => el.remove());
                  pricesTable.querySelectorAll(`[data-price-cell-period="${CSS.escape(periodId)}"]`).forEach((el) => el.remove());
                }

                function updatePriceColumnHeader(periodId, label) {
                  const th = pricesTable.querySelector(`[data-price-col-period="${CSS.escape(periodId)}"]`);
                  if (th) th.textContent = label;
                }

                addBtn.addEventListener('click', () => {
                  const idx = body.querySelectorAll('[data-period-row]').length;
                  counter++;
                  const id = genId(12);
                  const sortOrder = (idx + 1) * 10;
                  const tr = document.createElement('tr');
                  tr.dataset.periodRow = '';
                  tr.dataset.periodId = id;
                  tr.innerHTML = `
                    <td class="px-3 py-3 text-slate-400 cursor-move select-none" data-drag-handle title="Drag to reorder">
                      <span class="material-icons-outlined">drag_indicator</span>
                    </td>
                    <td class="px-3 py-3">
                      <input type="hidden" name="renewal_periods[${idx}][id]" value="${id}">
                      <input type="hidden" name="renewal_periods[${idx}][sort_order]" value="${sortOrder}" data-sort-order>
                      <input type="text" name="renewal_periods[${idx}][label]" value="New period" required class="w-full rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-sm" placeholder="e.g. 1 Year">
                    </td>
                    <td class="px-3 py-3">
                      <input type="number" name="renewal_periods[${idx}][duration_months]" value="12" min="1" max="120" required class="w-full rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-sm" data-period-months>
                    </td>
                    <td class="px-3 py-3 text-center">
                      <label class="inline-flex items-center justify-center">
                        <input type="checkbox" name="renewal_periods[${idx}][active]" value="1" checked class="rounded border-gray-300 text-emerald-600">
                      </label>
                    </td>
                    <td class="px-3 py-3 text-right">
                      <button type="button" class="text-rose-500 hover:text-rose-700 inline-flex items-center" data-remove-period title="Remove this period">
                        <span class="material-icons-outlined text-base">delete</span>
                      </button>
                    </td>`;
                  body.appendChild(tr);
                  addPeriodColumn({ id, label: 'New period' });
                  attachRowHandlers(tr);
                  reindex();
                });

                function attachRowHandlers(row) {
                  const removeBtn = row.querySelector('[data-remove-period]');
                  const labelInput = row.querySelector('input[name$="[label]"]');
                  const periodId = row.dataset.periodId;
                  if (removeBtn) {
                    removeBtn.addEventListener('click', () => {
                      if (body.querySelectorAll('[data-period-row]').length <= 1) {
                        alert('Keep at least one renewal period.');
                        return;
                      }
                      if (!confirm('Remove this renewal period? Members will no longer be able to renew for this length.')) return;
                      removePeriodColumn(periodId);
                      row.remove();
                      reindex();
                    });
                  }
                  if (labelInput) {
                    labelInput.addEventListener('input', () => updatePriceColumnHeader(periodId, labelInput.value));
                  }
                  // Drag-to-reorder
                  const handle = row.querySelector('[data-drag-handle]');
                  if (handle) {
                    handle.addEventListener('mousedown', () => row.setAttribute('draggable', 'true'));
                    row.addEventListener('mouseup', () => row.removeAttribute('draggable'));
                  }
                  row.addEventListener('dragstart', (e) => {
                    row.classList.add('opacity-50');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', row.dataset.periodId);
                  });
                  row.addEventListener('dragend', () => {
                    row.classList.remove('opacity-50');
                    row.removeAttribute('draggable');
                  });
                  row.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    const rect = row.getBoundingClientRect();
                    const before = (e.clientY - rect.top) < rect.height / 2;
                    row.classList.toggle('border-t-2', before);
                    row.classList.toggle('border-b-2', !before);
                    row.classList.add('border-emerald-400');
                  });
                  row.addEventListener('dragleave', () => {
                    row.classList.remove('border-t-2', 'border-b-2', 'border-emerald-400');
                  });
                  row.addEventListener('drop', (e) => {
                    e.preventDefault();
                    row.classList.remove('border-t-2', 'border-b-2', 'border-emerald-400');
                    const draggedId = e.dataTransfer.getData('text/plain');
                    if (!draggedId || draggedId === row.dataset.periodId) return;
                    const dragged = body.querySelector(`[data-period-id="${CSS.escape(draggedId)}"]`);
                    if (!dragged) return;
                    const rect = row.getBoundingClientRect();
                    const before = (e.clientY - rect.top) < rect.height / 2;
                    if (before) {
                      body.insertBefore(dragged, row);
                    } else {
                      body.insertBefore(dragged, row.nextSibling);
                    }
                    reindex();
                  });
                }

                body.querySelectorAll('[data-period-row]').forEach(attachRowHandlers);
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
