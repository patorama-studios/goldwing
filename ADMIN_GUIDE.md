# Australian Goldwing Association Admin Guide

## Access
- Login at `/login.php` with your admin account.
- Admin CRM is at `/admin/index.php`.

## Dashboard
- View member counts, pending approvals, upcoming renewals, and recent activity.

## Applications
- Review pending applications.
- Approve: assigns a membership period and marks the application approved.
- Reject: store the rejection reason and notes.

## Members Directory
- Search by name.
- View membership number, type, status, and chapter.

## Memberships & Renewals
- Review membership periods and expiry dates.
- Renewals are handled through Stripe payment links (configure in `config/app.php`).
- Approve or reject chapter change requests from members.

## Payments & Refunds
- Configure Stripe keys and webhook secret under Admin → Payments.
- View recent orders, issue full refunds (Committee Member, Treasurer, Super Admin only).
- Review webhook processing status in the Payments Debug table.

## Stripe Setup & Webhooks
- Set `APP_KEY` in `.env` to enable encryption for Stripe secrets at rest.
- Endpoint: `/api/webhooks/stripe` (configure the endpoint secret in Payments settings).
- Test mode is driven by the Stripe secret key prefix (`sk_test_...` vs `sk_live_...`).
- Local testing with Stripe CLI:
  - `stripe login`
  - `stripe listen --forward-to https://your-domain.test/api/webhooks/stripe`
  - Trigger an event: `stripe trigger checkout.session.completed`

## Events
- Edit event descriptions and visibility.

## Navigation / Menus
- Review menu items and their linked pages.
- Use the “Edit Page” button to open the AI Page Builder for a page-linked item.

## Notices
- Edit notice content; pinned notices appear first in the member portal.

## Pages
- Public page content is stored as draft/live HTML.
- Versions are created only when publishing live.

## AI Page Builder (Visual)
- Open at `/admin/page-builder` (admins + committee only).
- Select a page from the left list, then click elements in the preview to target edits.
- Chat uses a continuous history per page.
- Save Draft stores draft HTML; Push Live publishes and creates a version.
- Versions can be restored into draft and published later.
- Access control is set per page (public or role-locked).

### Manual test steps
1. Open `/admin/page-builder` as admin or committee.
2. Select a `?page=` page and verify the preview loads the draft.
3. Click an element in the preview and confirm it highlights + appears in the selector panel.
4. Use “Edit manually” to update the element and verify the preview updates immediately.
5. Use “Save Draft” then refresh the builder to confirm draft persists.
6. Use “Push Live” and confirm a new version appears in Versions.
7. Use Versions → Restore and confirm the draft updates.
8. Try hitting `/admin/page-builder/pages/{id}/publish` for a non-eligible page id and confirm it is blocked.

## Wings Magazine
- Review uploaded issues.
- Mark an issue as latest in the database (V1 uses `is_latest`).

## Media Library
- Uploaded assets are stored in `/public_html/uploads/`.
- Media metadata is stored in the `media` table.

## AI Settings
- Configure provider, model, and API key at `/admin/settings/ai.php`.

## Audit Log
- Review approvals, rejections, content edits, and AI applies.

## Reports
- View basic member status counts. Extend for exports as needed.
