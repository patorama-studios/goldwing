-- Associate life members (Aug 2026, member 950.1).
-- A linked associate can also be a life member. member_type stays ASSOCIATE
-- (the household link and .1 member number keep working); is_life_member = 1
-- carries the life status: never lapses, no renewal reminders, no renewal
-- prompts. Full life members keep member_type = 'LIFE' with the flag at 0 —
-- checks must treat member_type = 'LIFE' OR is_life_member = 1 as life
-- (MemberRepository::isLifeMember / notLifeSql).
-- Applied on production via /admin/run-migration.php (Migration 049).

ALTER TABLE members
  ADD COLUMN is_life_member TINYINT(1) NOT NULL DEFAULT 0 AFTER member_type;

INSERT IGNORE INTO membership_types (name, billing_period, price_cents, is_active, created_at)
VALUES ('Associate Life', 'one_off', 0, 1, NOW());
