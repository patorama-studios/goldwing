<?php
namespace App\Services;

use App\Services\Database;
use PDO;

class AdminMemberAccess
{
    private const CHAPTER_ROLE = 'chapter_leader';

    public static function getChapterRestrictionId(?array $user): ?int
    {
        if (!$user) {
            return null;
        }
        if (self::isFullAccess($user) || self::isTreasurer($user)) {
            return null;
        }
        if (!self::isChapterLeader($user)) {
            return null;
        }
        $memberId = $user['member_id'] ?? null;
        if (!$memberId) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT chapter_id FROM members WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['chapter_id']) {
            return (int) $row['chapter_id'];
        }
        return null;
    }

    public static function isFullAccess(array $user): bool
    {
        return function_exists('current_admin_can') && current_admin_can('admin.members.edit', $user);
    }

    public static function isTreasurer(array $user): bool
    {
        return function_exists('current_admin_can') && current_admin_can('admin.payments.view', $user);
    }

    public static function isChapterLeader(array $user): bool
    {
        return self::hasRole($user, self::CHAPTER_ROLE);
    }

    public static function canEditProfile(array $user): bool
    {
        return function_exists('current_admin_can') && current_admin_can('admin.members.edit', $user);
    }

    public static function canEditFullProfile(array $user): bool
    {
        return function_exists('current_admin_can') && current_admin_can('admin.members.edit', $user);
    }

    public static function canEditAddress(array $user): bool
    {
        return function_exists('current_admin_can') && current_admin_can('admin.members.edit', $user);
    }

    public static function canEditContact(array $user): bool
    {
        return function_exists('current_admin_can') && current_admin_can('admin.members.edit', $user);
    }

    public static function canResetPassword(array $user): bool
    {
        return function_exists('current_admin_can') && current_admin_can('admin.users.edit', $user);
    }

    public static function canSetPassword(array $user): bool
    {
        return function_exists('current_admin_can') && current_admin_can('admin.users.edit', $user);
    }

    public static function canImpersonate(array $user): bool
    {
        return function_exists('current_admin_can') && current_admin_can('admin.users.edit', $user);
    }

    public static function canRefund(array $user): bool
    {
        return function_exists('current_admin_can') && current_admin_can('admin.payments.refund', $user);
    }

    public static function canManualOrderFix(array $user): bool
    {
        return function_exists('current_admin_can') && current_admin_can('admin.members.manual_payment', $user);
    }

    public static function canManageVehicles(array $user): bool
    {
        return function_exists('current_admin_can') && current_admin_can('admin.members.edit', $user);
    }

    private static function hasRole(array $user, string $role): bool
    {
        return in_array($role, $user['roles'] ?? [], true);
    }
}
