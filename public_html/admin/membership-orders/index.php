<?php
/**
 * Index for /admin/membership-orders/.
 *
 * The membership-orders dir only contains view.php (single-order detail).
 * Hitting the dir root used to return a raw Apache 403 (no DirectoryIndex,
 * directory listing disabled). The list of membership orders is surfaced
 * inside /admin/members/view.php (per-member Orders tab), so this index
 * simply redirects there for now.
 *
 * If/when a dedicated cross-member list page is built, replace this with the
 * real index page.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';
require_login();

// If a numeric order id is in the URL, route to the detail view.
if (isset($_GET['id']) && ctype_digit((string) $_GET['id'])) {
    header('Location: /admin/membership-orders/view.php?id=' . (int) $_GET['id'], true, 302);
    exit;
}

header('Location: /admin/members/', true, 302);
exit;
