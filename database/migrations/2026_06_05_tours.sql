-- Tours system: tracks per-user tour completion and per-tour validator runs.
-- Used by:
--   - the floating "?" Help button (shows "✓ Done" / "Not yet" badges to members)
--   - the admin Tour Validator (logs human-tested + linter results)
--   - the admin sidebar badge ("X tours need attention")

CREATE TABLE IF NOT EXISTS tour_completions (
  user_id      INT          NOT NULL,
  tour_slug    VARCHAR(96)  NOT NULL,
  completed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, tour_slug),
  INDEX idx_tour_completions_slug (tour_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tour_test_runs (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  tour_slug       VARCHAR(96)  NOT NULL,
  run_kind        ENUM('linter','validator','playwright') NOT NULL,
  -- linter      = automated DOM selector check (scripts/lint_tours.php)
  -- validator   = human walked the tour in admin Tour Validator
  -- playwright  = phase 2 headless e2e
  run_as_role    VARCHAR(32)  NULL,            -- member | area_rep | store_manager | admin
  tested_by      INT          NULL,            -- user id of the tester (null for linter)
  status         ENUM('pass','fail','partial') NOT NULL,
  details_json   LONGTEXT     NULL,            -- per-step results, missing selectors, etc
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tour_test_runs_slug (tour_slug),
  INDEX idx_tour_test_runs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracks which files a tour depends on. Populated from tour-manifest.json by
-- scripts/check_tour_impact.php. Used by the git-push impact check skill to
-- decide which tours need re-verification after a code change.
CREATE TABLE IF NOT EXISTS tour_file_dependencies (
  tour_slug   VARCHAR(96)  NOT NULL,
  file_path   VARCHAR(255) NOT NULL,
  PRIMARY KEY (tour_slug, file_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
