<?php
namespace App\Services;

use App\Services\Database;
use PDO;

class AdminMemberAccess
{
    private const FULL_ACCESS_ROLES = ['super_admin', 'admin', 'committee'];
    private const CHAPTER_ROLE = 'chapter_leader';
    private const TREASURER_ROLE = 'treasurer';

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
        return self::hasAnyRole($user, self::FULL_ACCESS_ROLES);
    }

    public static function isTreasurer(array $user): bool
    {
        return self::hasRole($user, self::TREASURER_ROLE);
    }

    public static function isChapterLeader(array $user): bool
    {
        return self::hasRole($user, self::CHAPTER_ROLE);
    }

    public static function canEditProfile(array $user): bool
    {
        return self::isFullAccess($user) || self::isTreasurer($user) || self::isChapterLeader($user);
    }

    public static function canEditFullProfile(array $user): bool
    {
        return self::isFullAccess($user);
    }

    public static function canEditAddress(array $user): bool
    {
        return self::isFullAccess($user) || self::isTreasurer($user) || self::isChapterLeader($user);
    }

    public static function canEditContact(array $user): bool
    {
        return self::isFullAccess($user) || self::isChapterLeader($user);
    }

    public static function canResetPassword(array $user): bool
    {
        return self::isFullAccess($user) || self::isTreasurer($user);
    }

    public static function canSetPassword(array $user): bool
    {
        return self::isFullAccess($user);
    }

    public static function canImpersonate(array $user): bool
    {
        return self::isFullAccess($user);
    }

    public static function canRefund(array $user): bool
    {
        return self::hasAnyRole($user, ['super_admin', 'admin', 'treasurer']);
    }

    public static function canManualOrderFix(array $user): bool
    {
        return self::isFullAccess($user);
    }

    public static function canManageVehicles(array $user): bool
    {
        return self::isFullAccess($user);
    }

    private static function hasAnyRole(array $user, array $roles): bool
    {
        foreach ($roles as $role) {
            if (self::hasRole($user, $role)) {
                return true;
            }
        }
        return false;
    }

    private static function hasRole(array $user, string $role): bool
    {
        return in_array($role, $user['roles'] ?? [], true);
    }
}
