<?php

namespace App\Services;

use PDO;
use Throwable;

/**
 * AGM Awards service.
 *
 * Backs three surfaces:
 *   /admin/awards/         — categories + per-year winners CRUD, feature toggle
 *   /members/awards/       — public-facing wall of awards (teaser when feature_status="coming_soon")
 *   /member/index.php      — profile tab + dashboard trophy cabinet (phase 3, reads winnersForMember)
 *
 * Tables (migration 022):
 *   award_categories       — the 16 trophy types (group + memorial trophy name)
 *   award_winners          — one row per (category_id, year)
 *   award_winner_photos    — gallery per winner
 *
 * Feature toggle is stored in settings_global under awards.feature_status,
 * with values "coming_soon" or "live". Reads default to "coming_soon" so
 * the member-facing page never accidentally goes live before data is ready.
 */
final class AwardsService
{
    public const STATUS_COMING_SOON = 'coming_soon';
    public const STATUS_LIVE = 'live';

    public static function tablesReady(): bool
    {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->query("SHOW TABLES LIKE 'award_categories'");
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function getFeatureStatus(): string
    {
        $status = (string) SettingsService::getGlobal('awards.feature_status', self::STATUS_COMING_SOON);
        return $status === self::STATUS_LIVE ? self::STATUS_LIVE : self::STATUS_COMING_SOON;
    }

    public static function setFeatureStatus(int $actorUserId, string $status): void
    {
        $normalized = $status === self::STATUS_LIVE ? self::STATUS_LIVE : self::STATUS_COMING_SOON;
        SettingsService::setGlobal($actorUserId, 'awards.feature_status', $normalized);
    }

    public static function isLive(): bool
    {
        return self::getFeatureStatus() === self::STATUS_LIVE;
    }

    /** @return array<int, array<string, mixed>> */
    public static function listCategories(bool $includeInactive = false): array
    {
        if (!self::tablesReady()) {
            return [];
        }
        $pdo = Database::connection();
        $sql = 'SELECT * FROM award_categories';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function findCategory(int $id): ?array
    {
        if (!self::tablesReady()) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT * FROM award_categories WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function saveCategory(array $data): int
    {
        $pdo = Database::connection();
        $params = [
            'sort_order'  => (int) ($data['sort_order'] ?? 0),
            'name'        => trim((string) ($data['name'] ?? '')),
            'group_label' => self::nullIfBlank($data['group_label'] ?? null),
            'memorial'    => self::nullIfBlank($data['memorial_trophy_name'] ?? null),
            'description' => self::nullIfBlank($data['description'] ?? null),
            'is_active'   => !empty($data['is_active']) ? 1 : 0,
        ];
        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE award_categories SET sort_order = :sort_order, name = :name, group_label = :group_label, memorial_trophy_name = :memorial, description = :description, is_active = :is_active WHERE id = :id');
            $params['id'] = $id;
            $stmt->execute($params);
            return $id;
        }
        $stmt = $pdo->prepare('INSERT INTO award_categories (sort_order, name, group_label, memorial_trophy_name, description, is_active) VALUES (:sort_order, :name, :group_label, :memorial, :description, :is_active)');
        $stmt->execute($params);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int, int> List of years that have at least one winner, plus the current year, descending. */
    public static function listYears(): array
    {
        $years = [];
        if (self::tablesReady()) {
            $rows = Database::connection()->query('SELECT DISTINCT year FROM award_winners ORDER BY year DESC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($rows as $y) {
                $years[] = (int) $y;
            }
        }
        $currentYear = (int) date('Y');
        if (!in_array($currentYear, $years, true)) {
            $years[] = $currentYear;
        }
        rsort($years);
        return $years;
    }

    /**
     * One row per category for the given year. Categories without a winner have
     * winner_id = null. Result includes primary photo URL via LEFT JOIN.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function categoriesWithWinnersForYear(int $year, bool $includeInactive = false): array
    {
        if (!self::tablesReady()) {
            return [];
        }
        $pdo = Database::connection();
        $where = $includeInactive ? '' : 'WHERE c.is_active = 1';
        $sql = "SELECT c.*,
                       w.id AS winner_id, w.member_id, w.member_name_override,
                       w.bike_description, w.notes, w.awarded_at,
                       m.first_name AS member_first_name, m.last_name AS member_last_name,
                       p.media_path AS primary_photo
                FROM award_categories c
                LEFT JOIN award_winners w ON w.category_id = c.id AND w.year = :year
                LEFT JOIN members m ON m.id = w.member_id
                LEFT JOIN award_winner_photos p ON p.winner_id = w.id AND p.is_primary = 1
                {$where}
                ORDER BY c.sort_order ASC, c.id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['year' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function findWinner(int $id): ?array
    {
        if (!self::tablesReady()) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT w.*, c.name AS category_name, c.memorial_trophy_name, c.group_label,
                                                        m.first_name AS member_first_name, m.last_name AS member_last_name
                                                 FROM award_winners w
                                                 JOIN award_categories c ON c.id = w.category_id
                                                 LEFT JOIN members m ON m.id = w.member_id
                                                 WHERE w.id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findWinnerByCategoryAndYear(int $categoryId, int $year): ?array
    {
        if (!self::tablesReady()) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT * FROM award_winners WHERE category_id = :category_id AND year = :year LIMIT 1');
        $stmt->execute(['category_id' => $categoryId, 'year' => $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Upsert a winner row. Returns the winner id. Throws PDOException on
     * uniqueness conflict (category_id + year already taken by a different id).
     */
    public static function saveWinner(array $data, int $actorUserId): int
    {
        $pdo = Database::connection();
        $params = [
            'category_id'          => (int) ($data['category_id'] ?? 0),
            'year'                 => (int) ($data['year'] ?? date('Y')),
            'member_id'            => isset($data['member_id']) && (int) $data['member_id'] > 0 ? (int) $data['member_id'] : null,
            'member_name_override' => self::nullIfBlank($data['member_name_override'] ?? null),
            'bike_description'     => self::nullIfBlank($data['bike_description'] ?? null),
            'notes'                => self::nullIfBlank($data['notes'] ?? null),
            'awarded_at'           => self::nullIfBlank($data['awarded_at'] ?? null),
        ];
        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE award_winners SET category_id = :category_id, year = :year, member_id = :member_id, member_name_override = :member_name_override, bike_description = :bike_description, notes = :notes, awarded_at = :awarded_at WHERE id = :id');
            $params['id'] = $id;
            $stmt->execute($params);
            return $id;
        }
        $stmt = $pdo->prepare('INSERT INTO award_winners (category_id, year, member_id, member_name_override, bike_description, notes, awarded_at, created_by_user_id) VALUES (:category_id, :year, :member_id, :member_name_override, :bike_description, :notes, :awarded_at, :created_by_user_id)');
        $params['created_by_user_id'] = $actorUserId > 0 ? $actorUserId : null;
        $stmt->execute($params);
        return (int) $pdo->lastInsertId();
    }

    public static function deleteWinner(int $id): void
    {
        if (!self::tablesReady()) {
            return;
        }
        $stmt = Database::connection()->prepare('DELETE FROM award_winners WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function photosForWinner(int $winnerId): array
    {
        if (!self::tablesReady()) {
            return [];
        }
        $stmt = Database::connection()->prepare('SELECT * FROM award_winner_photos WHERE winner_id = :winner_id ORDER BY is_primary DESC, sort_order ASC, id ASC');
        $stmt->execute(['winner_id' => $winnerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function addPhoto(int $winnerId, string $mediaPath, ?string $caption = null, bool $isPrimary = false): int
    {
        $pdo = Database::connection();
        if ($isPrimary) {
            $clear = $pdo->prepare('UPDATE award_winner_photos SET is_primary = 0 WHERE winner_id = :winner_id');
            $clear->execute(['winner_id' => $winnerId]);
        }
        $sortOrder = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM award_winner_photos WHERE winner_id = ' . $winnerId)->fetchColumn();
        $stmt = $pdo->prepare('INSERT INTO award_winner_photos (winner_id, media_path, caption, sort_order, is_primary) VALUES (:winner_id, :media_path, :caption, :sort_order, :is_primary)');
        $stmt->execute([
            'winner_id'  => $winnerId,
            'media_path' => $mediaPath,
            'caption'    => self::nullIfBlank($caption),
            'sort_order' => $sortOrder,
            'is_primary' => $isPrimary ? 1 : 0,
        ]);
        $photoId = (int) $pdo->lastInsertId();

        // If this is the only photo, make it primary so the wall has a hero image.
        $count = (int) $pdo->query('SELECT COUNT(*) FROM award_winner_photos WHERE winner_id = ' . $winnerId)->fetchColumn();
        if ($count === 1) {
            $pdo->prepare('UPDATE award_winner_photos SET is_primary = 1 WHERE id = :id')->execute(['id' => $photoId]);
        }
        return $photoId;
    }

    public static function setPrimaryPhoto(int $photoId): void
    {
        $pdo = Database::connection();
        $row = $pdo->prepare('SELECT winner_id FROM award_winner_photos WHERE id = :id LIMIT 1');
        $row->execute(['id' => $photoId]);
        $winnerId = (int) ($row->fetchColumn() ?: 0);
        if ($winnerId <= 0) {
            return;
        }
        $pdo->prepare('UPDATE award_winner_photos SET is_primary = 0 WHERE winner_id = :winner_id')->execute(['winner_id' => $winnerId]);
        $pdo->prepare('UPDATE award_winner_photos SET is_primary = 1 WHERE id = :id')->execute(['id' => $photoId]);
    }

    public static function deletePhoto(int $photoId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT winner_id, is_primary FROM award_winner_photos WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $photoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $pdo->prepare('DELETE FROM award_winner_photos WHERE id = :id')->execute(['id' => $photoId]);
        if ((int) $row['is_primary'] === 1) {
            $promote = $pdo->prepare('UPDATE award_winner_photos SET is_primary = 1 WHERE winner_id = :winner_id ORDER BY sort_order ASC, id ASC LIMIT 1');
            $promote->execute(['winner_id' => (int) $row['winner_id']]);
        }
    }

    /**
     * Every award this member has won, newest first. Used by the profile tab
     * and the dashboard trophy cabinet panel.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function winnersForMember(int $memberId): array
    {
        if (!self::tablesReady() || $memberId <= 0) {
            return [];
        }
        $stmt = Database::connection()->prepare('SELECT w.*, c.name AS category_name, c.memorial_trophy_name, c.group_label,
                                                        p.media_path AS primary_photo
                                                 FROM award_winners w
                                                 JOIN award_categories c ON c.id = w.category_id
                                                 LEFT JOIN award_winner_photos p ON p.winner_id = w.id AND p.is_primary = 1
                                                 WHERE w.member_id = :member_id
                                                 ORDER BY w.year DESC, c.sort_order ASC');
        $stmt->execute(['member_id' => $memberId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Quick member typeahead for the winner form. Matches first/last/email.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function searchMembers(string $term, int $limit = 20): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $stmt = Database::connection()->prepare("SELECT id, first_name, last_name, email
                                                 FROM members
                                                 WHERE LOWER(first_name) LIKE :t1
                                                    OR LOWER(last_name) LIKE :t2
                                                    OR LOWER(CONCAT(first_name, ' ', last_name)) LIKE :t3
                                                    OR LOWER(email) LIKE :t4
                                                 ORDER BY status = 'ACTIVE' DESC, last_name, first_name
                                                 LIMIT " . max(1, min(50, $limit)));
        $like = '%' . strtolower($term) . '%';
        $stmt->execute(['t1' => $like, 't2' => $like, 't3' => $like, 't4' => $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Format the winner's display name — prefer the linked member's display name,
     * fall back to the override text stored at import time.
     */
    public static function displayWinnerName(array $winner): string
    {
        $first = trim((string) ($winner['member_first_name'] ?? ''));
        $last = trim((string) ($winner['member_last_name'] ?? ''));
        $linked = trim($first . ' ' . $last);
        if ($linked !== '') {
            return $linked;
        }
        $override = trim((string) ($winner['member_name_override'] ?? ''));
        return $override !== '' ? $override : '';
    }

    private static function nullIfBlank($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
