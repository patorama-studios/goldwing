-- Seed: Perth AGM 2026 event + product catalogue
-- Source: 2026 AGM Rego Form v5.pdf (Friday 1st May – Sunday 3rd May 2026, Discovery Park Caversham)
-- Apply after agm_module.sql has created the tables.
-- The event is inserted in 'draft' status; publish it from /admin/agm/?tab=event when ready.

SET @event_slug := 'perth-2026';
SET @event_year := 2026;

INSERT INTO agm_events (
  year, slug, title, subtitle, hosting_chapter,
  venue_name, venue_address, venue_phone,
  start_date, end_date,
  registration_opens_at, registration_closes_at, late_fee_starts_at,
  contact_name, contact_phone, contact_email,
  bank_transfer_instructions,
  allow_bank_transfer, allow_stripe,
  status, is_current, stripe_account_key,
  created_at
) VALUES (
  @event_year, @event_slug,
  'Perth AGM 2026',
  'Friday 1st May to Sunday 3rd May 2026',
  'Perth Chapter',
  'Discovery Park Caversham',
  '91 Benara Rd, Caversham WA',
  '08 9279 6700',
  '2026-05-01', '2026-05-03',
  NULL, '2026-03-16 23:59:00', '2026-03-17 00:00:00',
  'David Goodchild', '0417 987 742', 'arnoldschraven@yahoo.com',
  'Account Name: Australian GoldWing Association Inc.\nFinancial Institution: Bendigo Bank\nBSB: 633-000\nAccount: 158 060 657\nReference: Surname and Member #\n\nPostal address for posted registrations: 10 Thaxter Rd, Landsdale WA 6065',
  1, 1,
  'draft', 0, 'agm',
  NOW()
)
ON DUPLICATE KEY UPDATE
  title = VALUES(title),
  subtitle = VALUES(subtitle),
  hosting_chapter = VALUES(hosting_chapter),
  venue_name = VALUES(venue_name),
  venue_address = VALUES(venue_address),
  venue_phone = VALUES(venue_phone),
  start_date = VALUES(start_date),
  end_date = VALUES(end_date),
  registration_closes_at = VALUES(registration_closes_at),
  late_fee_starts_at = VALUES(late_fee_starts_at),
  contact_name = VALUES(contact_name),
  contact_phone = VALUES(contact_phone),
  contact_email = VALUES(contact_email),
  bank_transfer_instructions = VALUES(bank_transfer_instructions),
  updated_at = NOW();

SET @event_id := (SELECT id FROM agm_events WHERE year = @event_year AND slug = @event_slug);

-- Wipe existing products for this event so re-running this seed is idempotent.
DELETE FROM agm_products WHERE agm_event_id = @event_id;

INSERT INTO agm_products (agm_event_id, category, name, description, early_price, late_price, member_only, non_member_only, requires_choice, choices_json, per_registration_limit, sort_order, is_active, created_at) VALUES
  -- Registration tiers
  (@event_id, 'registration', 'Member — Full registration', 'AGM dinner, patch & badge', 74.00, 89.00, 1, 0, 0, NULL, 2, 10, 1, NOW()),
  (@event_id, 'registration', 'Non-member — Full registration', 'AGM dinner, patch & badge', 89.00, 104.00, 0, 1, 0, NULL, 2, 20, 1, NOW()),
  (@event_id, 'registration', 'Member — Registration only', 'Patch & badge (no dinner)', 45.00, 60.00, 1, 0, 0, NULL, 2, 30, 1, NOW()),
  (@event_id, 'registration', 'Non-member — Registration only', 'Patch & badge (no dinner)', 55.00, 70.00, 0, 1, 0, NULL, 2, 40, 1, NOW()),

  -- Merchandise
  (@event_id, 'merchandise', 'Cloth patch', NULL, 4.00, NULL, 0, 0, 0, NULL, NULL, 110, 1, NOW()),
  (@event_id, 'merchandise', 'Metal badge', NULL, 4.00, NULL, 0, 0, 0, NULL, NULL, 120, 1, NOW()),

  -- Meals
  (@event_id, 'meal', 'Thursday night — sausage sanga & salad roll', 'Subsidised by Perth Chapter', 10.00, NULL, 0, 0, 0, NULL, NULL, 210, 1, NOW()),
  (@event_id, 'meal', 'Friday breakfast — bacon & egg roll', NULL, 12.00, NULL, 0, 0, 0, NULL, NULL, 220, 1, NOW()),
  (@event_id, 'meal', 'Friday dinner', 'Choose a main. Comes with dinner rolls & 3 salads.', 24.00, NULL, 0, 0, 1, JSON_ARRAY('Lasagna','Spaghetti Bolognese','Tortellini Carbonara','Italian Meatballs','Vegetarian pasta bake'), NULL, 230, 1, NOW()),
  (@event_id, 'meal', 'Saturday breakfast — bacon & egg roll', NULL, 12.00, NULL, 0, 0, 0, NULL, NULL, 240, 1, NOW()),
  (@event_id, 'meal', 'Sunday breakfast — bacon & egg roll', NULL, 12.00, NULL, 0, 0, 0, NULL, NULL, 250, 1, NOW());
