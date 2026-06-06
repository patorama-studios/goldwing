-- AGM Awards system: trophy catalog, annual winners, and winner photos.
-- Used by:
--   - /admin/awards/        (categories + winners CRUD; feature toggle)
--   - /members/awards/      (public/member-facing wall of awards;
--                            renders a "coming soon" teaser when
--                            settings_global.awards.feature_status = "coming_soon")
--   - /member/index.php     (profile awards tab + dashboard trophy cabinet — phase 3)
--
-- Feature toggle lives in settings_global under category=awards,
-- key_name=feature_status. Values: "coming_soon" (default) or "live".
-- See AwardsService::getFeatureStatus() / setFeatureStatus().

CREATE TABLE IF NOT EXISTS award_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sort_order INT NOT NULL DEFAULT 0,
  name VARCHAR(180) NOT NULL,
  group_label VARCHAR(120) NULL,          -- e.g. "Best Original Goldwing", NULL for ungrouped
  memorial_trophy_name VARCHAR(180) NULL, -- e.g. "Burden Memorial Trophy"
  description VARCHAR(500) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_award_categories_sort (sort_order),
  INDEX idx_award_categories_group (group_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS award_winners (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  year SMALLINT NOT NULL,                  -- AGM year, e.g. 2025
  member_id INT NULL,                       -- nullable for imported historical winners
  member_name_override VARCHAR(200) NULL,  -- used when member_id IS NULL
  bike_description VARCHAR(255) NULL,      -- e.g. "1985 GL1200 Aspencade"
  notes VARCHAR(500) NULL,                 -- e.g. distance figures, judging notes
  awarded_at DATE NULL,                     -- date of the AGM
  created_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_award_winner_category_year (category_id, year),
  INDEX idx_award_winners_year (year),
  INDEX idx_award_winners_member (member_id),
  CONSTRAINT fk_award_winners_category FOREIGN KEY (category_id) REFERENCES award_categories(id) ON DELETE CASCADE,
  CONSTRAINT fk_award_winners_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS award_winner_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  winner_id INT NOT NULL,
  media_path VARCHAR(512) NOT NULL,         -- /uploads/awards/... — also registered with MediaService
  caption VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_award_winner_photos_winner (winner_id),
  CONSTRAINT fk_award_winner_photos_winner FOREIGN KEY (winner_id) REFERENCES award_winners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the 16 trophy categories. ON DUPLICATE KEY UPDATE keeps name + memorial
-- fresh on re-run without resetting the sort_order if an admin reordered them.
-- We use sort_order as the upsert key by adding a transient unique index pattern
-- via INSERT IGNORE — the seeds are only inserted if the table is empty.

INSERT INTO award_categories (sort_order, name, group_label, memorial_trophy_name, description)
SELECT * FROM (
  SELECT 10  AS sort_order, 'Best Original Classic Goldwing GL1000, GL1100, GL1200' AS name, 'Best Original Goldwing' AS group_label, NULL AS memorial_trophy_name, NULL AS description UNION ALL
  SELECT 20,  'Best Original GL1500',                                  'Best Original Goldwing', NULL, NULL UNION ALL
  SELECT 30,  'Best Original GL1800',                                  'Best Original Goldwing', NULL, NULL UNION ALL
  SELECT 40,  'Best Original F6B',                                     'Best Original Goldwing', NULL, NULL UNION ALL
  SELECT 50,  'Best Custom Classic Goldwing GL1000, GL1100, GL1200',   'Best Custom Goldwing',   NULL, NULL UNION ALL
  SELECT 60,  'Best Custom Goldwing GL1500',                           'Best Custom Goldwing',   NULL, NULL UNION ALL
  SELECT 70,  'Best Custom Goldwing GL1800',                           'Best Custom Goldwing',   NULL, NULL UNION ALL
  SELECT 80,  'Best Custom F6B',                                       'Best Custom Goldwing',   NULL, NULL UNION ALL
  SELECT 90,  'Best Goldwing and Trailer',                             NULL, 'Burden Memorial Trophy',          NULL UNION ALL
  SELECT 100, 'Best Goldwing Trike',                                   NULL, NULL,                              NULL UNION ALL
  SELECT 110, 'Best Goldwing and Sidecar',                             NULL, 'Harry Ward Memorial Trophy',      NULL UNION ALL
  SELECT 120, 'Best non-Goldwing',                                     NULL, NULL,                              NULL UNION ALL
  SELECT 130, 'Longest Distance Travelled by an AGA Member over 65',   NULL, 'Harry Gates Memorial Trophy',     NULL UNION ALL
  SELECT 140, 'Longest Distance Travelled by an AGA Member',           NULL, NULL,                              NULL UNION ALL
  SELECT 150, 'Longest Distance Pillion',                              NULL, 'Shirley Ward Trophy',             NULL UNION ALL
  SELECT 160, 'Peoples Choice Award',                                  NULL, 'Greg O''Loughlin Memorial Trophy',NULL UNION ALL
  SELECT 170, 'Member of the Year',                                    NULL, NULL,                              'Annual recognition for the member who has best embodied the spirit of the AGA over the past year.'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM award_categories LIMIT 1);
