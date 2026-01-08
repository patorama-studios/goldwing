<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AdminMemberAccess;
use App\Services\ActivityLogger;
use App\Services\MemberRepository;
use App\Services\SecurityAlertService;

require_role(['super_admin', 'admin', 'committee', 'treasurer', 'chapter_leader']);
require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/members');

$user = current_user();
$chapterRestriction = AdminMemberAccess::getChapterRestrictionId($user);

$directoryPrefInput = $_GET['directory_pref'] ?? [];
if (!is_array($directoryPrefInput)) {
    $directoryPrefInput = $directoryPrefInput === '' ? [] : [$directoryPrefInput];
}

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
];

if ($chapterRestriction !== null) {
    $filters['chapter_id'] = $chapterRestriction;
} elseif (isset($_GET['chapter_id']) && $_GET['chapter_id'] !== '') {
    $filters['chapter_id'] = (int) $_GET['chapter_id'];
}

if ($filters['status'] === 'archived') {
    $filters['status'] = 'cancelled';
}
if ($filters['status'] === '') {
    $filters['exclude_status'] = 'cancelled';
}

$result = MemberRepository::search($filters, 5000, 0);
$members = $result['data'];

$directoryPrefs = MemberRepository::directoryPreferences();

ActivityLogger::log('admin', $user['id'] ?? null, null, 'member.export', [
    'count' => count($members),
    'target_type' => 'members',
]);
SecurityAlertService::send('member_export', 'Security alert: member export', '<p>Member export performed by ' . e($user['email'] ?? '') . '.</p>');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="members.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Member #', 'Name', 'Email', 'Phone', 'Chapter', 'Membership Type', 'Status', 'Last Login', 'Created', 'Directory Preferences']);

foreach ($members as $member) {
    $prefs = [];
    foreach ($directoryPrefs as $letter => $info) {
        if (!empty($member[$info['column']])) {
            $prefs[] = $letter;
        }
    }
    fputcsv($out, [
        $member['member_number_display'] ?? '—',
        ($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''),
        $member['email'] ?? '',
        $member['phone'] ?? '',
        $member['chapter_name'] ?? '',
        $member['membership_type_name'] ?? '',
        ucfirst($member['status'] ?? ''),
        $member['last_login_at'] ? date('Y-m-d H:i:s', strtotime($member['last_login_at'])) : 'Never',
        $member['created_at'] ?? '',
        implode(', ', $prefs),
    ]);
}
fclose($out);
exit;
