<?php
namespace App\Services;

use App\Services\BaseUrlService;
use App\Services\EmailPreferencesTokenService;

class EmailService
{
    public static function send(string $to, string $subject, string $message, array $metadata = []): bool
    {
        $message = self::wrapIfNeeded($subject, $message);
        $message = self::appendFooter($message, $metadata);
        $baseUrlError = BaseUrlService::validationError();
        if ($baseUrlError !== null) {
            ActivityLogger::log('system', null, null, 'email.base_url_invalid', [
                'recipient' => $to,
                'subject' => $subject,
                'error' => $baseUrlError,
            ]);
            self::log($to, $subject, $message, false, $metadata);
            return false;
        }
        $sender = self::resolveSender($metadata);
        $from = $sender['from_email'];
        $fromName = $sender['from_name'];
        $replyTo = $sender['reply_to'];
        if ($from === '') {
            ActivityLogger::log('system', null, null, 'email.invalid_sender', [
                'recipient' => $to,
                'subject' => $subject,
            ]);
            self::log($to, $subject, $message, false, $metadata);
            return false;
        }
        $provider = SettingsService::getGlobal('integrations.email_provider', 'php_mail');
        $sent = false;
        if ($provider === 'smtp') {
            $smtpConfig = [
                'host' => SettingsService::getGlobal('integrations.smtp_host', ''),
                'port' => (int) SettingsService::getGlobal('integrations.smtp_port', 587),
                'username' => SettingsService::getGlobal('integrations.smtp_user', ''),
                'password' => SettingsService::getGlobal('integrations.smtp_password', ''),
                'encryption' => SettingsService::getGlobal('integrations.smtp_encryption', 'tls'),
                'helo' => SettingsService::getGlobal('site.base_url', ''),
            ];
            $sent = SmtpMailer::send($smtpConfig, $from, $fromName, $to, $subject, $message, $replyTo);
        }
        if (!$sent) {
            $headers = 'From: ' . $fromName . ' <' . $from . ">\r\n";
            if (!empty($replyTo)) {
                $headers .= 'Reply-To: ' . $replyTo . "\r\n";
            }
            if (!empty($provider)) {
                $headers .= 'X-Email-Provider: ' . $provider . "\r\n";
            }
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $sent = @mail($to, $subject, $message, $headers);
        }
        self::log($to, $subject, $message, $sent, $metadata, [
            'from_name' => $fromName,
            'from_email' => $from,
            'reply_to' => $replyTo,
        ]);
        return $sent;
    }

    private static function wrapIfNeeded(string $subject, string $message): string
    {
        if (strpos($message, 'data-email-template="goldwing"') !== false) {
            return $message;
        }
        return self::wrapHtml($subject, $message);
    }

    public static function wrapHtml(string $subject, string $bodyHtml): string
    {
        $siteName = (string) SettingsService::getGlobal('site.name', 'Goldwing Association');
        $logoUrl = trim((string) SettingsService::getGlobal('site.logo_url', ''));
        if ($logoUrl === '') {
            $logoUrl = '/uploads/library/2023/good-logo-cropped.png';
        }
        $logoUrl = self::normalizeAssetUrl($logoUrl);
        $safeSiteName = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');

        $logoHtml = $logoUrl !== ''
            ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $safeSiteName . ' logo" style="max-height:72px; width:auto; display:block;">'
            : '<div style="font-size:20px; font-weight:bold; letter-spacing:0.02em;">' . $safeSiteName . '</div>';

        $supportEmail = 'webmaster@goldwing.org.au';
        $safeSupportEmail = htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8');

        return '<div data-email-template="goldwing" style="font-family: Arial, sans-serif; background:#f5f6f2; padding:32px 0;">'
            . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">'
            . '<tr><td align="center">'
            . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="640" style="width:640px; max-width:640px; background:#ffffff; border:1px solid #e5e7eb; border-radius:14px; padding:28px;">'
            . '<tr><td style="text-align:center; padding-bottom:14px;">' . $logoHtml . '</td></tr>'
            . '<tr><td style="font-size:22px; font-weight:bold; color:#111827; padding:0 0 12px 0;">' . $safeSubject . '</td></tr>'
            . '<tr><td style="font-size:14px; line-height:1.6; color:#374151;">' . $bodyHtml . '</td></tr>'
            . '</table>'
            . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="640" style="width:640px; max-width:640px; padding-top:10px;">'
            . '<tr><td style="text-align:center; font-size:12px; color:#9ca3af;">'
            . $safeSiteName . ' &bull; <a href="mailto:' . $safeSupportEmail . '" style="color:#9ca3af; text-decoration:none;">' . $safeSupportEmail . '</a>'
            . '</td></tr>'
            . '</table>'
            . '</td></tr></table></div>';
    }

    private static function normalizeAssetUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if (preg_match('/^https?:\\/\\//i', $url)) {
            return $url;
        }
        $baseUrl = BaseUrlService::configuredBaseUrl();
        if ($baseUrl === '') {
            return $url;
        }
        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }

    private static function resolveSender(array $metadata): array
    {
        $fromName = trim((string) ($metadata['from_name'] ?? ''));
        if ($fromName === '') {
            $fromName = (string) SettingsService::getGlobal('notifications.from_name', 'Goldwing Association');
        }
        $fromEmail = self::normalizeSenderEmail((string) ($metadata['from_email'] ?? ''));
        if ($fromEmail === '') {
            $fromEmail = self::normalizeSenderEmail((string) SettingsService::getGlobal('notifications.from_email', 'no-reply@goldwing.org.au'));
        }
        $replyTo = trim((string) ($metadata['reply_to'] ?? ''));
        if ($replyTo === '' || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyTo = trim((string) SettingsService::getGlobal('notifications.reply_to', ''));
        }
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyTo = '';
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
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        if (!str_ends_with(strtolower($email), '@goldwing.org.au')) {
            return '';
        }
        return $email;
    }

    private static function appendFooter(string $message, array $metadata): string
    {
        $footer = self::renderFooter($metadata);
        if ($footer === '') {
            return $message;
        }
        return $message . $footer;
    }

    private static function renderFooter(array $metadata): string
    {
        $memberId = isset($metadata['member_id']) ? (int) $metadata['member_id'] : 0;
        $isMandatory = !empty($metadata['is_mandatory']);
        $links = [];
        if ($memberId > 0) {
            $prefsToken = EmailPreferencesTokenService::createToken($memberId, 'preferences');
            if ($prefsToken) {
                $links[] = '<a href="' . e(BaseUrlService::emailLink('/email_preferences.php?token=' . urlencode($prefsToken))) . '" style="color:#6b7280; text-decoration:none;">Update email preferences</a>';
            }
            if (!$isMandatory) {
                $unsubscribeToken = EmailPreferencesTokenService::createToken($memberId, 'unsubscribe');
                if ($unsubscribeToken) {
                    $links[] = '<a href="' . e(BaseUrlService::emailLink('/email_preferences.php?token=' . urlencode($unsubscribeToken) . '&action=unsubscribe')) . '" style="color:#6b7280; text-decoration:none;">Unsubscribe from all non-essential emails</a>';
                }
            }
        } else {
            $configured = BaseUrlService::configuredBaseUrl();
            if ($configured !== '') {
                $baseLink = rtrim($configured, '/') . '/member/index.php?page=settings#notifications';
                $links[] = '<a href="' . e($baseLink) . '" style="color:#6b7280; text-decoration:none;">Update email preferences</a>';
                if (!$isMandatory) {
                    $links[] = '<a href="' . e($baseLink) . '" style="color:#6b7280; text-decoration:none;">Unsubscribe from all non-essential emails</a>';
                }
            }
        }
        if (empty($links)) {
            return '';
        }
        $content = implode(' &bull; ', $links);
        return '<div style="font-size:12px; line-height:1.4; color:#9ca3af; padding-top:18px; text-align:center;">' . $content . '</div>';
    }

    private static function log(string $to, string $subject, string $message, bool $sent, array $metadata = [], array $sender = []): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO email_log (recipient, subject, body, sent, created_at) VALUES (:recipient, :subject, :body, :sent, NOW())');
        $stmt->execute([
            'recipient' => $to,
            'subject' => $subject,
            'body' => $message,
            'sent' => $sent ? 1 : 0,
        ]);

        $activityMemberId = self::resolveMemberId($metadata, $to);
        $activityMeta = [
            'recipient' => $to,
            'subject' => $subject,
            'sent' => $sent,
            'notification_key' => $metadata['notification_key'] ?? null,
            'category' => $metadata['category'] ?? null,
            'is_mandatory' => $metadata['is_mandatory'] ?? false,
            'admin_override' => $metadata['admin_override'] ?? false,
            'from_name' => $sender['from_name'] ?? null,
            'from_email' => $sender['from_email'] ?? null,
            'reply_to' => $sender['reply_to'] ?? null,
            'context' => $metadata['context'] ?? null,
            'resend_of' => $metadata['resend_of'] ?? null,
            'visibility' => $metadata['visibility'] ?? 'member',
            'email_snapshot' => [
                'subject' => $subject,
                'body' => $message,
                'from_name' => $sender['from_name'] ?? null,
                'from_email' => $sender['from_email'] ?? null,
                'reply_to' => $sender['reply_to'] ?? null,
            ],
        ];
        ActivityLogger::log('system', null, $activityMemberId, 'email.sent', array_filter($activityMeta, fn ($value) => $value !== null));
    }

    private static function resolveMemberId(array $metadata, string $recipient): ?int
    {
        if (!empty($metadata['member_id'])) {
            return (int) $metadata['member_id'];
        }
        if (!empty($metadata['user_id'])) {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT member_id FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $metadata['user_id']]);
            $row = $stmt->fetch();
            if (!empty($row['member_id'])) {
                return (int) $row['member_id'];
            }
        }
        $email = trim($recipient);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT member_id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        if (!empty($row['member_id'])) {
            return (int) $row['member_id'];
        }
        $stmt = $pdo->prepare('SELECT id FROM members WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return !empty($row['id']) ? (int) $row['id'] : null;
    }
}
