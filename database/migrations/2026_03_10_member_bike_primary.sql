ALTER TABLE member_bikes
  ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER image_url;

UPDATE member_bikes mb
JOIN (
  SELECT member_id, MIN(id) AS primary_id
  FROM member_bikes
  GROUP BY member_id
) AS first_bikes ON first_bikes.primary_id = mb.id
SET mb.is_primary = 1
WHERE mb.is_primary = 0;
