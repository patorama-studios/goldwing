# Bulk catalogue import

## What this covers

How to load (or reload) the AGA merchandise catalogue from a single JSON spec instead of clicking through the admin product CRUD product-by-product. Specifically: the `scripts/import_store_catalogue.php` CLI, its dry-run vs apply modes, the optional shipping push, and what gets touched vs left alone.

## Why it exists

The AGA reissues the merchandise list roughly once a year — sizing changes, new accessories come in, stock counts get rebaselined off the warehouse spreadsheet. Doing that hand-by-hand through [Chapter 27 — Store architecture](view.php?slug=27-store-architecture)'s product form means 20+ products × 8 sizes × stock-quantity-per-variant fiddling, which is both slow and error-prone.

A JSON file is the source of truth instead:

- The committee maintains the catalogue as an xlsx (`scripts/data/Current Stock List April 2026.xlsx`); a one-time pass converts it to `scripts/data/store_catalogue_2026_04.json`. Both are committed so the conversion is auditable.
- The importer is **idempotent by SKU** — re-run it next week with one stock count tweaked and only that row moves.
- Dry-run mode prints exactly what *would* change before anything writes.

The trade-off: anything edited in the UI on a SKU that's also in the JSON gets clobbered next time someone runs `--apply` — see Gotchas.

## How it works

### The script flow

```bash
php scripts/import_store_catalogue.php                       # dry-run, default JSON
php scripts/import_store_catalogue.php --apply               # commit to DB
php scripts/import_store_catalogue.php --apply --update-shipping
php scripts/import_store_catalogue.php --apply scripts/data/store_catalogue_2026_07.json
```

Internally the script is a thin CLI wrapper around `includes/store_catalogue_import.php::catalogue_import_run()` — the same shared function powers the admin web wrapper at `public_html/admin/store/import.php`, so the CLI and the UI produce identical results.

For each product in `catalogue.products[]`:

1. Look up `store_products` by `sku`.
2. **Match found** → `UPDATE` title, description, type, base_price, has_variants, track_inventory, stock_quantity, low_stock_threshold, event_name, is_active.
3. **No match** → `INSERT` with a slugified URL and `is_active = 1` (unless the JSON says otherwise).
4. Sync `store_product_categories` and `store_product_tags` (any new category/tag names get auto-created in `store_categories` / `store_tags`).
5. Sync variants — covered below.

The whole pass runs in a single `BEGIN`/`COMMIT` transaction in apply mode, so a mid-run failure rolls back cleanly.

### The JSON shape

```json
{
  "version": "2026-04",
  "source": "Current Stock List April 2026.xlsx",
  "shipping": { "flat_rate": 15.00, "note": "..." },
  "categories": ["Apparel", "Accessories"],
  "products": [
    {
      "sku": "AGA-POLO-MENS",
      "title": "AGA Polo Shirt (Mens)",
      "description": "...",
      "type": "physical",
      "base_price": 56.00,
      "track_inventory": true,
      "categories": ["Apparel"],
      "options": [{ "name": "Size", "values": ["S", "M", "L"] }],
      "variants": [
        { "options": { "Size": "S" }, "stock": 2 }
      ]
    }
  ]
}
```

`type` accepts `physical` or `ticket` (see [Chapter 28 — Tickets](view.php?slug=28-tickets)). For ticket products add `event_name`. Optional per-variant fields: `sku`, `price_override`, `stock`.

### Variants get fully replaced

This is important. For products that ship with `variants[]`, the script does:

```
DELETE FROM store_product_options  WHERE product_id = :id;
DELETE FROM store_product_variants WHERE product_id = :id;
-- then re-INSERT everything from the JSON
```

Old `store_product_variants.id` values are gone forever after apply. Carts and order-line snapshots survive because the FKs use `ON DELETE SET NULL` and each line item snapshots the SKU/title/price at purchase time — see [Chapter 15 — Orders & checkout](view.php?slug=15-orders-checkout). Historical orders stay accurate; the live catalogue is rebuilt clean.

### `--update-shipping`

When passed, the importer also runs:

```sql
UPDATE store_settings
   SET shipping_flat_enabled = 1,
       shipping_flat_rate    = :rate,
       updated_at            = NOW()
 WHERE id = 1;
```

…using `catalogue.shipping.flat_rate`. That's the only thing this script writes outside the `store_products*` tables. See [Chapter 29 — Discounts, shipping & fees](view.php?slug=29-discounts-shipping).

### Validation

Before any DB access the script:

- confirms the JSON file exists,
- `json_decode`s it,
- checks the result is an array with a non-empty `products[]`.

Per-product, it skips entries missing a `sku` or `title` and logs `! skip product without SKU or title`. Variant blocks without an `options[]` log `! product XXX has variants but no options[] — skipping variant sync` and the importer moves on rather than throwing.

### Logging

The script prints two streams to STDOUT:

- A line-by-line change log: `~ update product`, `+ create product`, `+ create store_categories: Apparel`, etc.
- A summary block: `products_inserted`, `products_updated`, `variants_written`, `variants_removed`, `categories_created`, `tags_created`.

It does **not** write into `audit_log` — capture the terminal output yourself if you need a record. The admin web wrapper at `/admin/store/import.php` is the place to add audit-log integration if we ever want it.

## Where to change it

- **Annual catalogue refresh:** drop the new JSON into `scripts/data/store_catalogue_YYYY_MM.json` (commit alongside its source xlsx), then `php scripts/import_store_catalogue.php --apply scripts/data/store_catalogue_YYYY_MM.json`. Update `catalogue_default_paths()` in `includes/store_catalogue_import.php` to make it the new default.
- **One-off tweak between catalogues:** don't edit the JSON. Use the product CRUD at `/admin/store/products/` — see [Chapter 27](view.php?slug=27-store-architecture).

Run from cPanel's Terminal (or SSH) so you're hitting the live DB:

```bash
cd ~/draft.goldwing.org.au
php scripts/import_store_catalogue.php                          # dry-run first, ALWAYS
php scripts/import_store_catalogue.php --apply --update-shipping
```

You *can* run `--apply` locally pointing at the production DB by editing `config/database.php`, but only if you really mean to. The cPanel terminal is the safer default.

## Settings

The importer has no settings of its own. The only setting it writes is when `--update-shipping` is passed, which pushes `shipping.flat_rate` into `store_settings` (covered in [Chapter 32 — Settings by section](view.php?slug=32-settings-by-section) under Store → Shipping).

## Screenshots

<!-- SCREENSHOT: Terminal output of `php scripts/import_store_catalogue.php` (dry-run) on draft, showing the per-product log lines and the summary stats block. Save as 30-import-dryrun.png. -->
<!-- ![Catalogue importer dry-run](../images/30-import-dryrun.png) -->

<!-- SCREENSHOT: Admin product list at /admin/store/products/ before and after running --apply for a fresh catalogue version. Save as 30-products-before-after.png. -->
<!-- ![Products list after import](../images/30-products-before-after.png) -->

## Gotchas

- **Variants get fully replaced on every run.** If you remove a variant from the JSON, it disappears from the live catalogue. Past orders still show the snapshotted SKU/title/price, but the variant ID is gone and the customer can't re-buy the exact same line.
- **Images are not imported.** The JSON has no image field. After `--apply` creates new products, upload images via `/admin/store/products/<id>/edit.php` — `store_product_images` is untouched.
- **Product DELETION isn't supported.** Products in the DB but absent from the JSON are left alone. Discontinued lines must be soft-deleted (`is_active = 0`) or removed manually through the admin UI.
- **Running `--apply` on a stale JSON WILL revert UI edits.** If a SKU exists in both DB and JSON, the JSON wins for every field listed under "The script flow" — including stock counts and `is_active`. Refresh the JSON from the latest xlsx before a hotfix re-run.
- **Taxonomy is additive only.** Categories/tags named in the JSON get created if missing, but unused entries from previous catalogues stay in `store_categories`. Clean them up from `/admin/store/categories.php` if needed.
- **No cron schedule.** This is a deliberate manual step — see [Chapter 34 — Cron jobs](view.php?slug=34-cron-jobs) for what *is* scheduled. We don't want stock counts silently rewritten overnight.

## Related chapters

- [15 — Orders & checkout flow](view.php?slug=15-orders-checkout) — why historical orders survive a variant rebuild (`ON DELETE SET NULL` + line-item snapshots).
- [27 — Store architecture](view.php?slug=27-store-architecture) — the `store_products*` tables this script writes into, and the admin UI for one-off edits.
- [29 — Discounts, shipping & fees](view.php?slug=29-discounts-shipping) — where `--update-shipping` lands.
- [32 — Settings by section](view.php?slug=32-settings-by-section) — `store_settings` reference, including the shipping flat rate.
- [34 — Cron jobs](view.php?slug=34-cron-jobs) — what *is* scheduled; this importer deliberately isn't.
