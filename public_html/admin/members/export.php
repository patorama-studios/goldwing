<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AdminMemberAccess;
use App\Services\ActivityLogger;
use App\Services\Database;
use App\Services\MemberRepository;
use App\Services\SecurityAlertService;

// Read-only list export (email PDF list / printed-copy post list). Gated on
// members.view — not import_export — so committee roles that can see members
// can pull the lists they already see on screen; the destructive import/
// backfill/merge tools stay behind admin.members.import_export. The chapter
// restriction below still scopes what a restricted admin can export.
require_permission('admin.members.view');
require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/members');

$user = current_user();
$chapterRestriction = AdminMemberAccess::getChapterRestrictionId($user);

$directoryPrefInput = $_GET['directory_pref'] ?? [];
if (!is_array($directoryPrefInput)) {
    $directoryPrefInput = $directoryPrefInput === '' ? [] : [$directoryPrefInput];
}

// "Email PDF list" mode — the electronic Wings distribution list. Everyone is
// emailed the PDF regardless of their print/digital preference or any directory
// opt-out, so this mode ignores the wings_preference filter entirely and only
// keeps members who actually have an email address (see the output loop below).
$emailPdfList = ($_GET['list'] ?? '') === 'email_pdf';

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'membership_type_id' => isset($_GET['membership_type_id']) && $_GET['membership_type_id'] !== '' ? (int) $_GET['membership_type_id'] : null,
    'status' => $_GET['status'] ?? '',
    'role' => trim((string) ($_GET['role'] ?? '')),
    'directory_prefs' => $directoryPrefInput,
    'vehicle_type' => $_GET['vehicle_type'] ?? '',
    'vehicle_make' => trim((string) ($_GET['vehicle_make'] ?? '')),
    'vehicle_model' => trim((string) ($_GET['vehicle_model'] ?? '')),
    'vehicle_year_exact' => trim((string) ($_GET['vehicle_year_exact'] ?? '')),
    'vehicle_year_from' => trim((string) ($_GET['vehicle_year_from'] ?? '')),
    'vehicle_year_to' => trim((string) ($_GET['vehicle_year_to'] ?? '')),
    'has_trike' => $_GET['has_trike'] ?? null,
    'has_trailer' => $_GET['has_trailer'] ?? null,
    'has_sidecar' => $_GET['has_sidecar'] ?? null,
    'has_historic_rego' => $_GET['has_historic_rego'] ?? null,
    'wings_preference' => (!$emailPdfList && in_array($_GET['wings_preference'] ?? '', ['digital', 'print', 'both', 'printed'], true)) ? $_GET['wings_preference'] : '',
];
if (isset($_GET['sort_by'])) {
    $filters['sort_by'] = $_GET['sort_by'];
}
if (isset($_GET['sort_dir'])) {
    $filters['sort_dir'] = $_GET['sort_dir'];
}

if ($chapterRestriction !== null) {
    $filters['chapter_id'] = $chapterRestriction;
} elseif (isset($_GET['chapter_id']) && $_GET['chapter_id'] !== '') {
    $filters['chapter_id'] = (int) $_GET['chapter_id'];
}

if ($filters['status'] === 'archived') {
    $filters['status'] = 'cancelled';
}
if ($filters['status'] === '') {
    $filters['exclude_statuses'] = ['cancelled'];
}

$result = MemberRepository::search($filters, 5000, 0);
$members = $result['data'];

$directoryPrefs = MemberRepository::directoryPreferences();

// Full-backup enrichment: membership expiry (latest ACTIVE period end date) and
// the member's primary bike are not in the SELECT m.* result set. Fetch both in
// two bulk queries keyed by member_id — never per-row — to avoid N+1 lookups.
$memberIds = [];
foreach ($members as $member) {
    if (isset($member['id'])) {
        $memberIds[] = (int) $member['id'];
    }
}
$expiryByMember = [];
$bikeByMember = [];
if ($memberIds !== []) {
    $pdo = Database::connection();
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));

    $expiryStmt = $pdo->prepare(
        "SELECT member_id, MAX(end_date) AS expiry FROM membership_periods "
        . "WHERE status = 'ACTIVE' AND member_id IN ($placeholders) GROUP BY member_id"
    );
    $expiryStmt->execute($memberIds);
    foreach ($expiryStmt->fetchAll() as $r) {
        $expiryByMember[(int) $r['member_id']] = $r['expiry'];
    }

    $bikeStmt = $pdo->prepare(
        "SELECT member_id, make, model, year, rego, colour FROM member_bikes "
        . "WHERE member_id IN ($placeholders) ORDER BY is_primary DESC, id ASC"
    );
    $bikeStmt->execute($memberIds);
    foreach ($bikeStmt->fetchAll() as $r) {
        $mid = (int) $r['member_id'];
        if (!isset($bikeByMember[$mid])) {
            $bikeByMember[$mid] = $r; // first row per member = primary, else lowest id
        }
    }
}

ActivityLogger::log('admin', $user['id'] ?? null, null, 'member.export', [
    'count' => count($members),
    'target_type' => 'members',
]);
SecurityAlertService::send('member_export', 'Security alert: member export', '<p>Member export performed by ' . e($user['email'] ?? '') . '.</p>');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . ($emailPdfList ? 'wings_email_list.csv' : 'members.csv') . '"');
$out = fopen('php://output', 'w');

// Printed-Wings mailing-list export (?wings_preference=printed): this CSV is the
// artifact handed to Australia Post, so include the admin-only presort/Zone code
// (members.australia_presort_code, migration 043) alongside the postal address.
$printedList = ($filters['wings_preference'] === 'printed');

$header = ['Member #', 'Name', 'Email', 'Phone', 'Address 1', 'Address 2', 'Suburb', 'State', 'Postcode', 'Country'];
if ($printedList) {
    $header[] = 'Zone';
}
array_push($header, 'Chapter', 'Membership Type', 'Status', 'Last Login', 'Created', 'Directory Preferences');
// Appended full-backup columns (kept after all existing columns so current
// exports/scripts that read by position keep working).
array_push(
    $header,
    'First Name', 'Last Name', 'Date of Birth', 'Join Date', 'Membership Expiry',
    'Member Type', 'Wings Preference', 'Do Not Renew',
    'Bike Make', 'Bike Model', 'Bike Year', 'Bike Rego', 'Bike Colour'
);
fputcsv($out, $header);

foreach ($members as $member) {
    // Email PDF list: a member with no email address can't receive the emailed
    // magazine, so leave them off this list (they belong on the printed list).
    if ($emailPdfList && trim((string) ($member['email'] ?? '')) === '') {
        continue;
    }
    $prefs = [];
    foreach ($directoryPrefs as $letter => $info) {
        if (!empty($member[$info['column']])) {
            $prefs[] = $letter;
        }
    }
    $row = [
        $member['member_number_display'] ?? '—',
        ($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''),
        $member['email'] ?? '',
        $member['phone'] ?? '',
        $member['address_line1'] ?? '',
        $member['address_line2'] ?? '',
        $member['city'] ?? '',
        $member['state'] ?? '',
        $member['postal_code'] ?? '',
        $member['country'] ?? '',
    ];
    if ($printedList) {
        $row[] = $member['australia_presort_code'] ?? '';
    }
    array_push(
        $row,
        $member['chapter_name'] ?? '',
        $member['membership_type_name'] ?? '',
        ucfirst($member['status'] ?? ''),
        $member['last_login_at'] ? date('Y-m-d H:i:s', strtotime($member['last_login_at'])) : 'Never',
        $member['created_at'] ?? '',
        implode(', ', $prefs),
    );
    // Appended full-backup columns (see header above).
    $mid = isset($member['id']) ? (int) $member['id'] : 0;
    $bike = $bikeByMember[$mid] ?? [];
    array_push(
        $row,
        $member['first_name'] ?? '',
        $member['last_name'] ?? '',
        $member['date_of_birth'] ?? '',
        $member['join_date'] ?? '',
        $expiryByMember[$mid] ?? '',
        $member['member_type'] ?? '',
        $member['wings_preference'] ?? '',
        !empty($member['do_not_renew']) ? 'yes' : 'no',
        $bike['make'] ?? '',
        $bike['model'] ?? '',
        $bike['year'] ?? '',
        $bike['rego'] ?? '',
        $bike['colour'] ?? '',
    );
    fputcsv($out, $row);
}
fclose($out);
exit;
