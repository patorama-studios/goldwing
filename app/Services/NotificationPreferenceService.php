<?php
namespace App\Services;

use App\Services\SettingsService;

class NotificationPreferenceService
{
    private const CATEGORY_LABELS = [
        'calendar' => 'Calendar updates',
        'noticeboard' => 'Noticeboard updates',
        'orders' => 'Order updates',
        'payments' => 'Payment updates',
        'admin' => 'General admin announcements',
        'security' => 'Security / authentication',
    ];

    public static function categories(): array
    {
        return self::CATEGORY_LABELS;
    }

    public static function defaultPreferences(): array
    {
        $categories = self::loadDefaultCategories();
        return [
            'master_enabled' => true,
            'unsubscribe_all_non_essential' => false,
            'categories' => $categories,
        ];
    }

    public static function load(int $userId): array
    {
        if ($userId <= 0) {
            return self::defaultPreferences();
        }
        $stored = SettingsService::getUser((int) $userId, 'notification_preferences', []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $normalized = self::normalize($stored);
        return array_replace_recursive(self::defaultPreferences(), $normalized);
    }

    public static function save(int $userId, array $preferences): void
    {
        if ($userId <= 0) {
            return;
        }
        $normalized = self::normalize($preferences);
        SettingsService::setUser($userId, 'notification_preferences', $normalized);
    }

    public static function shouldReceive(
        ?int $userId,
        string $category,
        bool $isMandatory = false,
        bool $force = false
    ): bool {
        if ($isMandatory || $force) {
            return true;
        }
        if ($userId === null || $userId <= 0) {
            return true;
        }
        $prefs = self::load($userId);
        if (empty($prefs['master_enabled'])) {
            return false;
        }
        if (!empty($prefs['unsubscribe_all_non_essential'])) {
            return false;
        }
        return !empty($prefs['categories'][$category]);
    }

    private static function normalize(array $preferences): array
    {
        $result = [];
        if (isset($preferences['master_enabled'])) {
            $result['master_enabled'] = (bool) $preferences['master_enabled'];
        } elseif (isset($preferences['email_notifications'])) {
            $result['master_enabled'] = (bool) $preferences['email_notifications'];
        } else {
            $result['master_enabled'] = true;
        }
        $result['unsubscribe_all_non_essential'] = !empty($preferences['unsubscribe_all_non_essential']);

        $defaultCategories = self::loadDefaultCategories();
        $categoryValues = $preferences['categories'] ?? [];
        $categories = [];
        foreach (array_keys(self::CATEGORY_LABELS) as $key) {
            if (array_key_exists($key, $categoryValues)) {
                $categories[$key] = (bool) $categoryValues[$key];
            } else {
                $categories[$key] = $defaultCategories[$key] ?? true;
            }
        }
        $result['categories'] = $categories;
        return $result;
    }

    private static function loadDefaultCategories(): array
    {
        $stored = SettingsService::getGlobal('notifications.default_categories', []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $defaults = [];
        foreach (array_keys(self::CATEGORY_LABELS) as $key) {
            $defaults[$key] = array_key_exists($key, $stored) ? (bool) $stored[$key] : true;
        }
        return $defaults;
    }
}
