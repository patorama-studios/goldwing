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
- Edit public page HTML content.
- Changes are versioned in `page_versions`.

## AI Page Builder
- Open from Navigation / Menus and use chat instructions to update a page.
- Click elements in the preview to target a specific section.
- Choose OpenAI, Gemini, or Claude models per session.
- Every change is logged with a diff and can be reverted.

## Wings Magazine
- Review uploaded issues.
- Mark an issue as latest in the database (V1 uses `is_latest`).

## Media Library
- Uploaded assets are stored in `/public_html/uploads/`.
- Media metadata is stored in the `media` table.

## AI Editor
- Enter a prompt to draft page/notice/event updates.
- Preview and click Apply to commit changes.
- All AI drafts are stored and audited.

## Audit Log
- Review approvals, rejections, content edits, and AI applies.

## Reports
- View basic member status counts. Extend for exports as needed.
