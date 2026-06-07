<?php
// Legacy URL — the Security Activity Log was folded into the unified Audit Hub.
// Preserve any filters the caller passed: actor → actor, action → action,
// dates → start/end, target_type/ip become free-text search.
require_once __DIR__ . '/../../../app/bootstrap.php';

require_permission('admin.logs.view');

$forward = [];
if (!empty($_GET['action'])) $forward['action'] = (string) $_GET['action'];
if (!empty($_GET['start']))  $forward['start']  = (string) $_GET['start'];
if (!empty($_GET['end']))    $forward['end']    = (string) $_GET['end'];

$searchBits = [];
if (!empty($_GET['user_id'])) $searchBits[] = (string) $_GET['user_id'];
if (!empty($_GET['ip']))      $searchBits[] = (string) $_GET['ip'];
if (!empty($_GET['target_type'])) $searchBits[] = (string) $_GET['target_type'];
if ($searchBits) {
    $forward['q'] = implode(' ', $searchBits);
}

// Pre-select the activity source so the user lands where they expect.
$forward['source'] = 'activity';

$qs = http_build_query($forward);
header('Location: /admin/audit/' . ($qs !== '' ? '?' . $qs : ''));
exit;
