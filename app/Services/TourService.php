<?php
namespace App\Services;

use PDO;
use Throwable;

/**
 * Reads the tour manifest, tracks completions, and reports on test/lint status.
 *
 * Source of truth: /config/tour-manifest.json. Tables: tour_completions,
 * tour_test_runs, tour_file_dependencies (see 2026_06_05_tours.sql).
 */
class TourService
{
    private const MANIFEST_PATH = __DIR__ . '/../../config/tour-manifest.json';

    /** How many days a tour can go un-verified before it's flagged stale. */
    public const STALE_AFTER_DAYS = 60;

    public static function manifest(): array
    {
        $raw = @file_get_contents(self::MANIFEST_PATH);
        if ($raw === false) {
            return ['tours' => []];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['tours'])) {
            return ['tours' => []];
        }
        return $decoded;
    }

    /** @return array<string, array> tours keyed by slug */
    public static function allTours(): array
    {
        return self::manifest()['tours'] ?? [];
    }

    public static function tour(string $slug): ?array
    {
        return self::allTours()[$slug] ?? null;
    }

    /**
     * Tours an authenticated user can see, filtered by audience + role.
     * @param array $user current_user() payload (must include 'roles' array)
     * @param string|null $pageMatch if set, only return tours whose page_match substring is in this URL/path
     */
    public static function toursFor(array $user, ?string $pageMatch = null): array
    {
        $userRoles = $user['roles'] ?? [];
        $isAdmin = in_array('admin', $userRoles, true) || in_array('webmaster', $userRoles, true);
        $out = [];
        foreach (self::allTours() as $slug => $tour) {
            // Audience gate: member tours always available to members & admins.
            // Admin tours hidden from plain members.
            if (($tour['audience'] ?? 'member') === 'admin' && !$isAdmin) {
                continue;
            }
            // Role gate (optional finer control).
            $allowedRoles = $tour['roles'] ?? [];
            if ($allowedRoles && !array_intersect($allowedRoles, $userRoles)) {
                continue;
            }
            // Page filter.
            if ($pageMatch !== null && !empty($tour['page_match'])) {
                if (strpos($pageMatch, $tour['page_match']) === false) {
                    continue;
                }
            }
            $out[$slug] = $tour;
        }
        return $out;
    }

    public static function markCompleted(int $userId, string $slug): void
    {
        if ($userId <= 0 || $slug === '') {
            return;
        }
        try {
            $pdo = db();
            $stmt = $pdo->prepare(
                'INSERT INTO tour_completions (user_id, tour_slug, completed_at)
                 VALUES (:uid, :slug, NOW())
                 ON DUPLICATE KEY UPDATE completed_at = NOW()'
            );
            $stmt->execute(['uid' => $userId, 'slug' => $slug]);
        } catch (Throwable $e) {
            // Don't break the UX over a tracking failure.
        }
    }

    /** @return array<string,bool> slug => true if completed */
    public static function completionsFor(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        try {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT tour_slug FROM tour_completions WHERE user_id = :uid');
            $stmt->execute(['uid' => $userId]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $slug) {
                $out[$slug] = true;
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Latest test run for each tour (pass/fail/partial + when).
     * @return array<string, array{status:string,run_kind:string,created_at:string}|null>
     */
    public static function latestRunsBySlug(): array
    {
        try {
            $pdo = db();
            $rows = $pdo->query(
                "SELECT t1.tour_slug, t1.status, t1.run_kind, t1.created_at
                   FROM tour_test_runs t1
                   JOIN (
                       SELECT tour_slug, MAX(id) AS max_id
                         FROM tour_test_runs
                        GROUP BY tour_slug
                   ) t2 ON t1.id = t2.max_id"
            )->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                $out[$r['tour_slug']] = [
                    'status' => $r['status'],
                    'run_kind' => $r['run_kind'],
                    'created_at' => $r['created_at'],
                ];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Count of tours that need admin attention (failing or never tested or stale). */
    public static function attentionCount(): int
    {
        $tours = self::allTours();
        if (!$tours) {
            return 0;
        }
        $runs = self::latestRunsBySlug();
        $count = 0;
        $staleCutoff = time() - (self::STALE_AFTER_DAYS * 86400);
        foreach ($tours as $slug => $_tour) {
            $latest = $runs[$slug] ?? null;
            if ($latest === null) {
                $count++;
                continue;
            }
            if ($latest['status'] !== 'pass') {
                $count++;
                continue;
            }
            $ts = strtotime((string) $latest['created_at']);
            if ($ts === false || $ts < $staleCutoff) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Load the steps for a tour from the DB.
     *
     * @param string $slug
     * @param bool $includeDrafts when true (admin preview), returns the draft_*
     *        values overlaid on the published ones for any row with has_draft=1
     * @return array list of step objects shaped for the JS engine
     */
    public static function stepsFor(string $slug, bool $includeDrafts = false): array
    {
        try {
            $pdo = db();
            $stmt = $pdo->prepare(
                'SELECT step_index, element_selector, title, description, side, align,
                        draft_title, draft_description, draft_side, draft_align,
                        draft_element_selector, has_draft
                   FROM tour_steps
                  WHERE tour_slug = :slug
                  ORDER BY step_index ASC'
            );
            $stmt->execute(['slug' => $slug]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $useDraft = $includeDrafts && (int) ($r['has_draft'] ?? 0) === 1;
                $title = $useDraft && $r['draft_title'] !== null ? $r['draft_title'] : $r['title'];
                $desc  = $useDraft && $r['draft_description'] !== null ? $r['draft_description'] : $r['description'];
                $side  = $useDraft && $r['draft_side'] !== null ? $r['draft_side'] : $r['side'];
                $align = $useDraft && $r['draft_align'] !== null ? $r['draft_align'] : $r['align'];
                $sel   = $useDraft && $r['draft_element_selector'] !== null ? $r['draft_element_selector'] : $r['element_selector'];
                $out[] = [
                    'element' => $sel,
                    'popover' => [
                        'title' => $title,
                        'description' => $desc,
                        'side' => $side,
                        'align' => $align,
                    ],
                ];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Admin-side: load step rows with full draft + published columns for the editor UI.
     * @return array<int, array<string,mixed>>
     */
    public static function stepRowsFor(string $slug): array
    {
        try {
            $pdo = db();
            $stmt = $pdo->prepare(
                'SELECT id, tour_slug, step_index, element_selector, title, description, side, align,
                        draft_title, draft_description, draft_side, draft_align,
                        draft_element_selector, has_draft, updated_at, updated_by, published_at
                   FROM tour_steps
                  WHERE tour_slug = :slug
                  ORDER BY step_index ASC'
            );
            $stmt->execute(['slug' => $slug]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Last N runs for a tour, newest first, decoded details. */
    public static function recentRunsFor(string $slug, int $limit = 5): array
    {
        try {
            $pdo = db();
            $limit = max(1, min(50, $limit));
            $stmt = $pdo->prepare(
                "SELECT id, run_kind, run_as_role, tested_by, status, details_json, created_at
                   FROM tour_test_runs
                  WHERE tour_slug = :slug
                  ORDER BY id DESC
                  LIMIT $limit"
            );
            $stmt->execute(['slug' => $slug]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) {
                $r['details'] = $r['details_json'] ? json_decode($r['details_json'], true) : null;
                unset($r['details_json']);
            }
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function recordRun(
        string $slug,
        string $kind,           // 'linter' | 'validator' | 'playwright'
        string $status,         // 'pass' | 'fail' | 'partial'
        ?int $testedBy = null,
        ?string $runAsRole = null,
        ?array $details = null
    ): void {
        try {
            $pdo = db();
            $stmt = $pdo->prepare(
                'INSERT INTO tour_test_runs
                    (tour_slug, run_kind, run_as_role, tested_by, status, details_json, created_at)
                 VALUES (:slug, :kind, :role, :tester, :status, :details, NOW())'
            );
            $stmt->execute([
                'slug'    => $slug,
                'kind'    => $kind,
                'role'    => $runAsRole,
                'tester'  => $testedBy,
                'status'  => $status,
                'details' => $details ? json_encode($details, JSON_UNESCAPED_SLASHES) : null,
            ]);
        } catch (Throwable $e) {
            // Logged silently — failure to record shouldn't break the validator.
        }
    }
}
