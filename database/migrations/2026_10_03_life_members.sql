-- Migration 053 — life_members (member portal "Life Members" page, Oct 2026).
-- The honour roll printed in Wings: name, year awarded, deceased, honorary.
-- Standalone from the members table on purpose — deceased life members and
-- the honorary member have no (active) member record. Maintained from
-- Admin → Life Members. Seeded once from the 2024-25 Wings roll. Also created
-- by public_html/admin/run-migration.php — keep the two in sync.
CREATE TABLE IF NOT EXISTS life_members (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  year_awarded SMALLINT UNSIGNED NOT NULL,
  is_deceased TINYINT(1) NOT NULL DEFAULT 0,
  is_honorary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  INDEX idx_life_members_year (year_awarded, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO life_members (full_name, year_awarded, is_deceased, is_honorary, created_at)
SELECT s.n, s.y, s.d, s.h, NOW() FROM (
  SELECT 'Harry Ward' AS n, 1993 AS y, 1 AS d, 0 AS h
  UNION ALL SELECT 'Shirley Ward', 1993, 1, 0
  UNION ALL SELECT 'Mal Pryor', 1997, 0, 0
  UNION ALL SELECT 'Helen Pryor', 1997, 0, 0
  UNION ALL SELECT 'Kevin Woodward', 2000, 1, 0
  UNION ALL SELECT 'Wendy Woodward', 2000, 0, 0
  UNION ALL SELECT 'Mal Allen', 2002, 0, 0
  UNION ALL SELECT 'Bonnie Allen', 2002, 0, 0
  UNION ALL SELECT 'Peter Brannan', 2010, 0, 0
  UNION ALL SELECT 'Dot Brannan', 2010, 0, 0
  UNION ALL SELECT 'Frank Milligan', 2012, 0, 0
  UNION ALL SELECT 'Mark Johannesen', 2015, 0, 0
  UNION ALL SELECT 'Cecily Johannesen', 2015, 0, 0
  UNION ALL SELECT 'Greg O''Loughlin', 2021, 1, 0
  UNION ALL SELECT 'Graham Merrick', 2022, 0, 0
  UNION ALL SELECT 'Christine Merrick', 2022, 0, 0
  UNION ALL SELECT 'Greg Snart', 2019, 0, 1
) s
WHERE NOT EXISTS (SELECT 1 FROM life_members);
