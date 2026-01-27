# Goldwing Website

## Navigation menus
- Admin route: `/admin/navigation.php` (admin-only).
- Create a menu, assign it to a location (primary/footer/secondary), and save.
- Add pages via the “Add Pages” panel, or create custom links with labels + URLs.
- Reorder using Up/Down, and nest items with Indent/Outdent.
- AI Page Builder lives at `/admin/ai_editor.php` (admin-only).
- If no menus exist yet, a default Primary Menu is created from the current public navigation (Home/About/Ride Calendar/Membership/Events + Member Login).

## Menu locations
- Menu locations are managed in the “Menu Locations” panel.
- Only one menu can be assigned to a location at a time.
- Public navigation loads the menu assigned to `primary`. If none is assigned, it falls back to Home + public pages.

## Settings Hub
- Run migration: `database/settings_hub.sql` (creates `settings_global`, `settings_user`, and `audit_log`).
- Admin Settings Hub: `/admin/settings/index.php` (site, store, payments, notifications, security, integrations, media, events, audit).
- Membership Pricing: `/admin/settings/index.php?section=membership_pricing` (matrix of Printed/PDF pricing by period; values stored in `membership.pricing_matrix`).
- Personal Settings: `/member/index.php?page=settings`.
- Default settings are seeded on first Settings Hub visit, and legacy store/Stripe settings are migrated into the new tables.
- Stripe keys, email sender details, site name/logo, and store rules now live in the Settings Hub (not `config/app.php`).

## Store module (members-only)
- Run migration: `database/store_module.sql` (creates all store tables and seed settings).
- Admin area: `/admin/store/*` (products, categories, tags, discounts, orders, low stock).
- Store settings: `/admin/settings/index.php?section=store`.
- Storefront: `/store` (requires login; cart and checkout at `/store/cart` + `/store/checkout`).
- Roles: `admin` and `store_manager` can access `/admin/store/*` (migration adds missing roles).
- Stripe keys: configure in Settings Hub → Payments (Stripe).
- Webhook endpoint: `/api/stripe_webhook.php` (handles memberships and store payments).
- Configure shipping + processing fee passthrough in Store Settings.
- Create products (physical or ticket), add options/variants, then assign categories/tags.
- Ticket products generate codes on paid orders and email them to customers.

## Members admin portal
- Run the migration `database/members_module.sql` to add membership lookups, directory preferences (A–F), the member vehicles table, refunds/log tables, and the activity logging stack.
- Visit `/admin/members` for the new member list, filters, stats, and export; detail pages include overview, profile, vehicles, orders, refunds, and activity tabs with RBAC and logging.
- Sensitive actions (password resets, order fixes/refreshes, refunds, vehicle CRUD, profile updates) write into `activity_log`, and admins can always see directory preference fields regardless of member flags. Chapter leaders see only their chapter.

## cPanel deployment notes
- Upload the repository into your cPanel account so `public_html` becomes the document root and PHP 8+ is used automatically.
- Update `config/database.php` (or the `.env` file if you override it) with the cPanel MySQL hostname, database, username, and password.
- Run the SQL migrations on the cPanel MySQL shell (e.g., `mysql -u user -p database < database/members_module.sql`), then rerun any older migrations that may still be pending.
- Keep file ownership/permissions compatible with the web server, and clear PHP opcode caches (if available) after deploying new files.

## Stripe requirements (refunds & billing)
- The new refunds workflow hits Stripe via `RefundService::processRefund`, so `Settings Hub → Payments (Stripe)` must store the secret key under `payments.stripe.secret_key`.
- Use the same Stripe account for store payments so the admin resyncs and refund amounts resolve against the same payment intents/charges.
- When you rotate Stripe keys, update the settings, rerun any pending refunds manually, and verify webhook credentials if you’ve changed endpoint secrets.
