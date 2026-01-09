<?php
namespace App\Services;

class NotificationService
{
    public static function definitions(): array
    {
        return [
            'member_set_password' => [
                'label' => 'Set password email (new member)',
                'description' => 'Sent when an admin creates a member account.',
                'trigger' => 'Admin approves a membership application.',
                'category' => 'security',
                'is_mandatory' => true,
                'placeholders' => ['reset_link'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Set your password',
                    'body' => '<p>Set your password:</p><p><a href="{{reset_link}}">Set password</a></p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'member_password_reset_admin' => [
                'label' => 'Password reset (admin initiated)',
                'description' => 'Sent when an admin requests a password reset for a member.',
                'trigger' => 'Admin clicks "Send reset link" on a member profile.',
                'category' => 'security',
                'is_mandatory' => true,
                'placeholders' => ['reset_link'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Password reset request',
                    'body' => '<p>Reset your password:</p><p><a href="{{reset_link}}">Reset password</a></p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'member_password_reset_self' => [
                'label' => 'Password reset (self-service)',
                'description' => 'Sent when a member requests a password reset from the login screen.',
                'trigger' => 'Member submits the reset form.',
                'category' => 'security',
                'is_mandatory' => true,
                'placeholders' => ['reset_link'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Password Reset',
                    'body' => '<p>Reset your password:</p><p><a href="{{reset_link}}">Reset Password</a></p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'security_email_otp' => [
                'label' => 'Login verification code (email OTP)',
                'description' => 'Sent when a member must verify a login with an email OTP.',
                'trigger' => 'Member logs in and requires email OTP verification.',
                'category' => 'security',
                'is_mandatory' => true,
                'placeholders' => ['otp_code', 'expires_minutes', 'member_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Your login verification code',
                    'body' => '<p>Hello {{member_name}},</p><p>Your verification code is:</p><p style="font-size:24px; font-weight:bold; letter-spacing:2px;">{{otp_code}}</p><p>This code expires in {{expires_minutes}} minutes.</p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_approved' => [
                'label' => 'Membership approved',
                'description' => 'Sent when a membership application is approved.',
                'trigger' => 'Admin approves an application.',
                'category' => 'payments',
                'is_mandatory' => true,
                'placeholders' => ['payment_link'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Membership approved - complete payment',
                    'body' => '<p>Your membership has been approved. Please complete payment:</p><p><a href="{{payment_link}}">Pay now</a></p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_order_created' => [
                'label' => 'Membership order created',
                'description' => 'Sent when a membership order is created or re-sent.',
                'trigger' => 'Membership order is created.',
                'category' => 'payments',
                'is_mandatory' => true,
                'placeholders' => ['order_number', 'payment_link', 'payment_method', 'bank_transfer_instructions', 'member_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Membership order #{{order_number}}',
                    'body' => '<p>Hello {{member_name}},</p><p>Your membership order <strong>#{{order_number}}</strong> is ready.</p><p>Payment method: {{payment_method}}</p><p><a href="{{payment_link}}">Pay now</a></p><p>{{bank_transfer_instructions}}</p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_payment_received' => [
                'label' => 'Membership payment received',
                'description' => 'Sent when a membership payment is received.',
                'trigger' => 'Stripe webhook or admin approval.',
                'category' => 'payments',
                'is_mandatory' => true,
                'placeholders' => ['order_number', 'member_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Payment received for order #{{order_number}}',
                    'body' => '<p>Hello {{member_name}},</p><p>We have received your membership payment for order <strong>#{{order_number}}</strong>.</p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_order_approved' => [
                'label' => 'Membership order approved',
                'description' => 'Sent when an admin approves a membership order.',
                'trigger' => 'Admin approves a pending membership order.',
                'category' => 'payments',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'member_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Membership order approved #{{order_number}}',
                    'body' => '<p>Hello {{member_name}},</p><p>Your membership order <strong>#{{order_number}}</strong> has been approved.</p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_order_rejected' => [
                'label' => 'Membership order rejected',
                'description' => 'Sent when an admin rejects a membership order.',
                'trigger' => 'Admin rejects a pending membership order.',
                'category' => 'payments',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'member_name', 'rejection_reason'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Membership order rejected #{{order_number}}',
                    'body' => '<p>Hello {{member_name}},</p><p>Your membership order <strong>#{{order_number}}</strong> was rejected.</p><p>Reason: {{rejection_reason}}</p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_payment_failed' => [
                'label' => 'Membership payment failed',
                'description' => 'Sent when a membership payment fails.',
                'trigger' => 'Stripe payment fails.',
                'category' => 'payments',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'member_name', 'payment_link'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Payment failed for order #{{order_number}}',
                    'body' => '<p>Hello {{member_name}},</p><p>Your membership payment for order <strong>#{{order_number}}</strong> failed.</p><p><a href="{{payment_link}}">Try payment again</a></p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_admin_pending_approval' => [
                'label' => 'Admin: membership payment awaiting approval',
                'description' => 'Sent to admins when a bank transfer membership order needs approval.',
                'trigger' => 'Membership order created with bank transfer.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'member_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'admin',
                    'custom_recipients' => '',
                    'subject' => 'Membership payment pending approval #{{order_number}}',
                    'body' => '<p>Membership order <strong>#{{order_number}}</strong> for {{member_name}} requires approval.</p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_migration_invite' => [
                'label' => 'Manual migration invite',
                'description' => 'Sent when an admin invites a member to complete a manual migration.',
                'trigger' => 'Admin clicks "Send Manual Migrate Form" on a member profile.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['migration_link', 'member_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Complete your membership setup',
                    'body' => '<p>Hello {{member_name}},</p><p>Please complete your membership setup:</p><p><a href="{{migration_link}}">Finish setup</a></p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'membership_activated_confirmation' => [
                'label' => 'Membership activated',
                'description' => 'Sent when a membership is activated without a Stripe payment.',
                'trigger' => 'Manual migration or admin manual activation.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['membership_type', 'renewal_date', 'member_name'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Your membership is active',
                    'body' => '<p>Hello {{member_name}},</p><p>Your {{membership_type}} membership is now active.</p><p>Renewal date: {{renewal_date}}</p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'member_of_year_nomination_receipt' => [
                'label' => 'Member of the Year nomination receipt',
                'description' => 'Sent to the nominator after they submit a Member of the Year nomination.',
                'trigger' => 'Member submits a nomination.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['nominator_name', 'nominee_name', 'submission_year', 'nomination_details', 'submission_id'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Member of the Year nomination received',
                    'body' => '<p>Hello {{nominator_name}},</p><p>Thanks for submitting a Member of the Year nomination for {{nominee_name}} in {{submission_year}}.</p><p>We received the following details:</p><p>{{nomination_details}}</p><p>Reference: #{{submission_id}}</p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'member_of_year_nomination_admin' => [
                'label' => 'Admin: Member of the Year nomination',
                'description' => 'Sent to admins when a Member of the Year nomination is submitted.',
                'trigger' => 'Member submits a nomination.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['nominator_name', 'nominator_email', 'nominee_name', 'nominee_chapter', 'submission_year', 'nomination_details', 'submission_id'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'admin',
                    'custom_recipients' => '',
                    'subject' => 'New Member of the Year nomination #{{submission_id}}',
                    'body' => '<p>A new Member of the Year nomination was submitted for {{submission_year}}.</p><p><strong>Nominator:</strong> {{nominator_name}} ({{nominator_email}})<br><strong>Nominee:</strong> {{nominee_name}}<br><strong>Chapter:</strong> {{nominee_chapter}}</p><p><strong>Details:</strong><br>{{nomination_details}}</p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'store_order_confirmation' => [
                'label' => 'Store order confirmation',
                'description' => 'Sent after a paid store order is received.',
                'trigger' => 'Stripe checkout succeeds.',
                'category' => 'payments',
                'is_mandatory' => true,
                'placeholders' => ['order_number', 'address_html', 'items_html', 'totals_html'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Order confirmation #{{order_number}}',
                    'body' => '<p>Thanks for your order <strong>#{{order_number}}</strong>.</p>{{address_html}}{{items_html}}{{totals_html}}',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'store_ticket_codes' => [
                'label' => 'Store ticket codes',
                'description' => 'Sent with ticket codes for ticketed items.',
                'trigger' => 'Store order contains tickets.',
                'category' => 'orders',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'ticket_list_html'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Your ticket codes for order #{{order_number}}',
                    'body' => '<p>Your ticket codes are below for order <strong>#{{order_number}}</strong>.</p>{{ticket_list_html}}',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'store_shipping_update' => [
                'label' => 'Store shipping update',
                'description' => 'Sent when a shipment is saved for an order.',
                'trigger' => 'Admin adds tracking details.',
                'category' => 'orders',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'carrier', 'tracking_number'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'primary',
                    'custom_recipients' => '',
                    'subject' => 'Shipping update for order #{{order_number}}',
                    'body' => '<p>Your order <strong>#{{order_number}}</strong> has shipped.</p><p>Carrier: {{carrier}}<br>Tracking: {{tracking_number}}</p>',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
            'store_admin_new_order' => [
                'label' => 'Admin: new store order',
                'description' => 'Sent to admins when a new paid store order arrives.',
                'trigger' => 'Stripe checkout succeeds.',
                'category' => 'admin',
                'is_mandatory' => false,
                'placeholders' => ['order_number', 'address_html', 'items_html', 'totals_html'],
                'defaults' => [
                    'enabled' => true,
                    'recipient_mode' => 'admin',
                    'custom_recipients' => '',
                    'subject' => 'New store order #{{order_number}}',
                    'body' => '<p>New paid store order <strong>#{{order_number}}</strong>.</p>{{address_html}}{{items_html}}{{totals_html}}',
                    'from_name' => 'Goldwing Association',
                    'from_email' => 'no-reply@goldwing.org.au',
                    'reply_to' => 'webmaster@goldwing.org.au',
                ],
            ],
        ];
    }

    public static function defaultCatalog(): array
    {
        $defaults = [];
        $globalSender = [
            'from_name' => SettingsService::getGlobal('notifications.from_name', 'Goldwing Association'),
            'from_email' => SettingsService::getGlobal('notifications.from_email', 'no-reply@goldwing.org.au'),
            'reply_to' => SettingsService::getGlobal('notifications.reply_to', ''),
        ];
        foreach (self::definitions() as $key => $definition) {
            $definitionDefaults = $definition['defaults'] ?? [];
            $defaults[$key] = array_merge($globalSender, $definitionDefaults);
        }
        return $defaults;
    }

    public static function getCatalogSettings(): array
    {
        $defaults = self::defaultCatalog();
        $saved = SettingsService::getGlobal('notifications.catalog', []);
        if (!is_array($saved)) {
            return $defaults;
        }
        $merged = $defaults;
        foreach ($saved as $key => $settings) {
            if (!isset($defaults[$key]) || !is_array($settings)) {
                continue;
            }
            $merged[$key] = array_merge($defaults[$key], $settings);
        }
        return $merged;
    }

    public static function buildMessage(string $key, array $context): ?array
    {
        $catalog = self::getCatalogSettings();
        $settings = $catalog[$key] ?? null;
        if (!$settings || empty($settings['enabled'])) {
            return null;
        }
        $subject = self::applyTemplate((string) ($settings['subject'] ?? ''), $context);
        $body = self::applyTemplate((string) ($settings['body'] ?? ''), $context);
        return [
            'subject' => $subject,
            'body' => $body,
            'settings' => $settings,
        ];
    }

    public static function dispatch(string $key, array $context, array $options = []): bool
    {
        $catalog = self::getCatalogSettings();
        $settings = $catalog[$key] ?? null;
        if (!$settings) {
            return false;
        }
        $force = !empty($options['force']);
        $adminOverride = !empty($options['admin_override']);
        if (!$force && empty($settings['enabled'])) {
            return false;
        }
        if (!$force && !$adminOverride && !self::isGloballyEnabled()) {
            return false;
        }

        $memberId = self::resolveMemberId($context);
        $category = $settings['category'] ?? 'admin';
        $isMandatory = !empty($settings['is_mandatory']);

        $allowPrimary = self::shouldSendToMember($memberId, $category, $isMandatory, $options, $force);
        $recipients = self::resolveRecipients($settings, $context, $allowPrimary);
        if (!$recipients) {
            return false;
        }

        $subject = self::applyTemplate((string) ($settings['subject'] ?? ''), $context);
        $body = self::applyTemplate((string) ($settings['body'] ?? ''), $context);
        $sender = self::resolveSender($settings);
        if ($sender['from_email'] === '') {
            ActivityLogger::log('system', null, $memberId, 'notification.invalid_sender', [
                'notification' => $key,
            ]);
            return false;
        }

        $visibility = $options['visibility'] ?? ((string) ($settings['recipient_mode'] ?? 'primary') === 'admin' ? 'admin' : 'member');
        $metadata = [
            'member_id' => $memberId,
            'user_id' => $context['user_id'] ?? null,
            'notification_key' => $key,
            'category' => $category,
            'is_mandatory' => $isMandatory,
            'admin_override' => $adminOverride,
            'from_name' => $sender['from_name'],
            'from_email' => $sender['from_email'],
            'reply_to' => $sender['reply_to'],
            'context' => $context,
            'resend_of' => $options['resend_of'] ?? null,
            'visibility' => $visibility,
        ];

        $sent = false;
        foreach ($recipients as $recipient) {
            if (EmailService::send($recipient, $subject, $body, $metadata)) {
                $sent = true;
            }
        }

        if ($adminOverride && !empty($options['actor_id'])) {
            ActivityLogger::log('admin', (int) $options['actor_id'], $memberId, 'notification.override', [
                'notification' => $key,
            ]);
        }
        return $sent;
    }

    public static function sampleContext(string $key): array
    {
        $defaults = [
            'reset_link' => self::sampleEmailUrl('/member/reset_password_confirm.php?token=sample', 'https://example.org/reset?token=sample'),
            'payment_link' => self::sampleEmailUrl('/membership/payment', 'https://example.org/pay'),
            'migration_link' => self::sampleEmailUrl('/migrate.php?token=sample', 'https://example.org/migrate?token=sample'),
            'member_name' => 'Member',
            'membership_type' => 'Member',
            'renewal_date' => '2025-07-31',
            'order_number' => 'GW-12345',
            'payment_method' => 'stripe',
            'bank_transfer_instructions' => 'Bank: Goldwing Association\nBSB: 123-456\nAccount: 987654',
            'rejection_reason' => 'Payment could not be verified.',
            'carrier' => 'Australia Post',
            'tracking_number' => 'TRACK123456',
            'address_html' => '<p><strong>Shipping address</strong><br>123 Goldwing Road<br>Canberra ACT 2600</p>',
            'items_html' => '<table style="width:100%; border-collapse:collapse; font-size:13px;"><tr><td>Sample item</td><td style="text-align:right;">$25.00</td></tr></table>',
            'totals_html' => '<p><strong>Total:</strong> $25.00</p>',
            'ticket_list_html' => '<p><strong>Tickets</strong><br>Goldwing Rally - ABC123</p>',
            'otp_code' => '482915',
            'expires_minutes' => '10',
        ];
        return $defaults;
    }

    private static function sampleEmailUrl(string $path, string $fallback): string
    {
        $link = BaseUrlService::emailLink($path);
        if ($link !== '') {
            return $link;
        }
        return $fallback;
    }

    public static function getAdminEmails(string $fallback = ''): array
    {
        $emails = self::normalizeEmails(SettingsService::getGlobal('notifications.admin_emails', ''));
        if (!$emails && $fallback !== '') {
            $emails = self::normalizeEmails($fallback);
        }
        if (!$emails) {
            $emails = self::normalizeEmails(SettingsService::getGlobal('site.contact_email', ''));
        }
        return $emails;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function resolveRecipients(array $settings, array $context, bool $allowPrimary): array
    {
        $mode = (string) ($settings['recipient_mode'] ?? 'primary');
        $primary = self::normalizeEmails($context['primary_email'] ?? '');
        $admin = self::normalizeEmails($context['admin_emails'] ?? '');
        $custom = self::normalizeEmails($settings['custom_recipients'] ?? '');
        switch ($mode) {
            case 'admin':
                return $admin;
            case 'both':
                return self::uniqueEmails(array_merge($allowPrimary ? $primary : [], $admin));
            case 'custom':
                return $custom;
            case 'primary':
            default:
                return $allowPrimary ? $primary : [];
        }
    }

    private static function shouldSendToMember(?int $memberId, string $category, bool $isMandatory, array $options, bool $force): bool
    {
        if ($isMandatory || $force || !empty($options['admin_override'])) {
            return true;
        }
        if (!self::isGloballyEnabled()) {
            return false;
        }
        return NotificationPreferenceService::shouldReceive($memberId, $category, $isMandatory, $force);
    }

    private static function resolveMemberId(array $context): ?int
    {
        if (!empty($context['member_id'])) {
            return (int) $context['member_id'];
        }
        if (!empty($context['user_id'])) {
            $memberId = self::lookupMemberIdByUserId((int) $context['user_id']);
            if ($memberId) {
                return $memberId;
            }
        }
        if (!empty($context['primary_email'])) {
            return self::lookupMemberIdByEmail((string) $context['primary_email']);
        }
        return null;
    }

    private static function lookupMemberIdByUserId(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT member_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return !empty($row['member_id']) ? (int) $row['member_id'] : null;
    }

    private static function lookupMemberIdByEmail(string $email): ?int
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT member_id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return !empty($row['member_id']) ? (int) $row['member_id'] : null;
    }

    private static function isGloballyEnabled(): bool
    {
        return SettingsService::getGlobal('notifications.enabled', true);
    }

    private static function resolveSender(array $settings): array
    {
        $fromName = trim((string) ($settings['from_name'] ?? ''));
        if ($fromName === '') {
            $fromName = SettingsService::getGlobal('notifications.from_name', 'Goldwing Association');
        }
        $fromEmail = self::normalizeSenderEmail($settings['from_email'] ?? '');
        if ($fromEmail === '') {
            $fromEmail = self::normalizeSenderEmail(SettingsService::getGlobal('notifications.from_email', 'no-reply@goldwing.org.au'));
        }
        $replyTo = trim((string) ($settings['reply_to'] ?? ''));
        if ($replyTo === '' || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyTo = SettingsService::getGlobal('notifications.reply_to', '');
        }
        return [
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'reply_to' => $replyTo,
        ];
    }

    private static function normalizeSenderEmail(string $value): string
    {
        $email = trim($value);
        if ($email === '') {
            return '';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        if (!preg_match('/@([a-z0-9-]+\\.)*goldwing\\.org\\.au$/i', $email)) {
            return '';
        }
        return $email;
    }

    private static function normalizeEmails($value): array
    {
        if (is_array($value)) {
            $emails = [];
            foreach ($value as $item) {
                $emails = array_merge($emails, self::normalizeEmails($item));
            }
            return self::uniqueEmails($emails);
        }
        $parts = preg_split('/[,\n;]+/', (string) $value);
        $emails = [];
        foreach ($parts as $part) {
            $email = trim($part);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }
        return self::uniqueEmails($emails);
    }

    private static function uniqueEmails(array $emails): array
    {
        $emails = array_values(array_unique($emails));
        sort($emails);
        return $emails;
    }

    private static function applyTemplate(string $template, array $context): string
    {
        if ($template === '') {
            return '';
        }
        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{{' . $key . '}}'] = self::stringifyContextValue($value);
        }
        return strtr($template, $replacements);
    }

    /**
     * Normalize template context values so arrays render safely as text.
     */
    private static function stringifyContextValue(mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts[] = self::stringifyContextValue($item);
            }
            $parts = array_filter($parts, fn ($item) => $item !== '');
            return implode(', ', $parts);
        }
        return (string) $value;
    }
}
