-- Add chapters confirmed during member data audit (May 2026)
-- Holiday Coast, South Coast NSW, Southern Districts = NSW coastal chapters
-- NFC Chapter = No Fixed Chapter, for members not attached to a local chapter

INSERT INTO chapters (name, state, is_active)
SELECT 'Holiday Coast Chapter', 'ACT & New South Wales', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'Holiday Coast Chapter');

INSERT INTO chapters (name, state, is_active)
SELECT 'South Coast NSW Chapter', 'ACT & New South Wales', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'South Coast NSW Chapter');

INSERT INTO chapters (name, state, is_active)
SELECT 'Southern Districts Chapter', 'ACT & New South Wales', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'Southern Districts Chapter');

-- NFC = No Fixed Chapter. Members with no local chapter are assigned here.
INSERT INTO chapters (name, state, is_active)
SELECT 'NFC Chapter', 'National', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chapters WHERE name = 'NFC Chapter');
