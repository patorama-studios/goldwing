<?php
namespace App\Services;

class SecurityAlertService
{
    public static function send(string $type, string $subject, string $body): void
    {
        $settings = SecuritySettingsService::get();
        $alerts = $settings['alerts'] ?? [];
        if (isset($alerts[$type]) && !$alerts[$type]) {
            return;
        }
        $to = $settings['alert_email'];
        if ($to === '') {
            $to = SettingsService::getGlobal('site.contact_email', '');
        }
        if ($to === '') {
            return;
        }
        EmailService::send($to, $subject, $body);
    }
}
