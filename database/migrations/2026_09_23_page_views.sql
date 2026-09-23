-- Migration 051 — page_views (admin Member Engagement report).
-- One row per HTML page load by a logged-in user, written by
-- App\Services\PageViewLogger from bootstrap.php. Deliberately thin: portal
-- area + path only (no IP, no query string beyond ?page=), pruned after
-- 13 months by EngagementReportService::prune(). Also created by
-- public_html/admin/run-migration.php — keep the two in sync.
CREATE TABLE IF NOT EXISTS page_views (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  member_id INT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  area VARCHAR(40) NOT NULL,
  path VARCHAR(191) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_page_views_created (created_at),
  INDEX idx_page_views_user_created (user_id, created_at),
  INDEX idx_page_views_area_created (area, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
