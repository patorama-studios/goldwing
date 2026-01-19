<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\AdminMemberAccess;
use App\Services\ChapterRepository;
use App\Services\Csrf;
use App\Services\Database;
use App\Services\MemberRepository;
use App\Services\MembershipService;
use App\Services\SecurityAlertService;
use App\Services\Validator;

require_role(['super_admin', 'admin', 'committee', 'treasurer', 'chapter_leader']);
require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/members');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/members');
    exit;
}

if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
    $_SESSION['members_flash'] = ['type' => 'error', 'message' => 'Invalid CSRF token.'];
    header('Location: /admin/members');
    exit;
}

$upload = $_FILES['members_csv'] ?? null;
if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $_SESSION['members_flash'] = ['type' => 'error', 'message' => 'Please upload a CSV file to import.'];
    header('Location: /admin/members');
    exit;
}

function normalizeHeader(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    return trim($value, '_');
}

function parseCsvBoolean(?string $value, int $default = 0): int
{
    if ($value === null || trim($value) === '') {
        return $default;
    }
    $result = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($result === null) {
        return $default;
    }
    return $result ? 1 : 0;
}

function fetchMemberColumns(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute(['table' => 'members']);
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return array_fill_keys($columns, true);
}

$handle = fopen($upload['tmp_name'], 'r');
if (!$handle) {
    $_SESSION['members_flash'] = ['type' => 'error', 'message' => 'Unable to read the uploaded CSV file.'];
    header('Location: /admin/members');
    exit;
}

$headerRow = fgetcsv($handle);
if (!$headerRow) {
    fclose($handle);
    $_SESSION['members_flash'] = ['type' => 'error', 'message' => 'The CSV file is missing a header row.'];
    header('Location: /admin/members');
    exit;
}

if (isset($headerRow[0])) {
    $headerRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headerRow[0]);
}

$headerLookup = [
    'first_name' => 'first_name',
    'firstname' => 'first_name',
    'given_name' => 'first_name',
    'last_name' => 'last_name',
    'lastname' => 'last_name',
    'surname' => 'last_name',
    'family_name' => 'last_name',
    'email' => 'email',
    'email_address' => 'email',
    'member' => 'member_id',
    'member_id' => 'member_id',
    'member_number' => 'member_id',
    'member_no' => 'member_id',
    'member_number_id' => 'member_id',
    'memberid' => 'member_id',
    'member_id_number' => 'member_id',
    'member_type' => 'member_type',
    'membership_type' => 'member_type',
    'membership_type_id' => 'membership_type_id',
    'status' => 'status',
    'chapter' => 'chapter',
    'chapter_name' => 'chapter',
    'chapter_id' => 'chapter_id',
    'phone' => 'phone',
    'phone_number' => 'phone',
    'mobile' => 'phone',
    'address_line1' => 'address_line1',
    'address_1' => 'address_line1',
    'address1' => 'address_line1',
    'street' => 'address_line1',
    'address_line2' => 'address_line2',
    'address_2' => 'address_line2',
    'address2' => 'address_line2',
    'city' => 'city',
    'suburb' => 'suburb',
    'state' => 'state',
    'postal_code' => 'postal_code',
    'postcode' => 'postal_code',
    'zip' => 'postal_code',
    'country' => 'country',
    'privacy_level' => 'privacy_level',
    'privacy' => 'privacy_level',
    'assist_ute' => 'assist_ute',
    'assist_phone' => 'assist_phone',
    'assist_bed' => 'assist_bed',
    'assist_tools' => 'assist_tools',
    'exclude_printed' => 'exclude_printed',
    'exclude_electronic' => 'exclude_electronic',
    'notes' => 'notes',
    'note' => 'notes',
    'full_member_number' => 'full_member_number',
    'full_member_id' => 'full_member_number',
];

$headerKeys = [];
foreach ($headerRow as $index => $label) {
    $normalized = normalizeHeader((string) $label);
    $headerKeys[$index] = $headerLookup[$normalized] ?? null;
}

$requiredHeaders = ['first_name', 'last_name', 'email', 'member_id'];
foreach ($requiredHeaders as $required) {
    if (!in_array($required, $headerKeys, true)) {
        fclose($handle);
        $_SESSION['members_flash'] = ['type' => 'error', 'message' => 'CSV missing required column: ' . $required . '.'];
        header('Location: /admin/members');
        exit;
    }
}

$pdo = Database::connection();
$columnExists = fetchMemberColumns($pdo);
$memberNumberAvailable = MemberRepository::hasMemberNumberColumn($pdo);

$chapterMap = [];
$chapters = ChapterRepository::listForSelection($pdo, false);
foreach ($chapters as $chapter) {
    $nameKey = strtolower(trim((string) ($chapter['name'] ?? '')));
    if ($nameKey !== '') {
        $chapterMap[$nameKey] = (int) $chapter['id'];
    }
}

$candidateColumns = [
    'member_type',
    'status',
    'member_number_base',
    'member_number_suffix',
    'full_member_id',
    'chapter_id',
    'first_name',
    'last_name',
    'email',
    'phone',
    'address_line1',
    'address_line2',
    'city',
    'suburb',
    'state',
    'postal_code',
    'country',
    'privacy_level',
    'assist_ute',
    'assist_phone',
    'assist_bed',
    'assist_tools',
    'exclude_printed',
    'exclude_electronic',
    'membership_type_id',
    'notes',
    'member_number',
    'created_at',
];

$insertColumns = [];
foreach ($candidateColumns as $column) {
    if (isset($columnExists[$column])) {
        if ($column === 'member_number' && !$memberNumberAvailable) {
            continue;
        }
        $insertColumns[] = $column;
    }
}

$valueParts = [];
foreach ($insertColumns as $column) {
    $valueParts[] = $column === 'created_at' ? 'NOW()' : ':' . $column;
}

$insertSql = 'INSERT INTO members (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $valueParts) . ')';
$insertStmt = $pdo->prepare($insertSql);
$existsStmt = $pdo->prepare('SELECT id FROM members WHERE member_number_base = :base AND member_number_suffix = :suffix LIMIT 1');
$fullMemberStmt = $pdo->prepare('SELECT id FROM members WHERE member_number_base = :base AND member_number_suffix = 0 LIMIT 1');

$createdCount = 0;
$skippedCount = 0;
$errors = [];
$rowNumber = 1;

while (($row = fgetcsv($handle)) !== false) {
    $rowNumber++;
    if (!$row || array_filter($row, static fn($value) => trim((string) $value) !== '') === []) {
        continue;
    }

    $data = [];
    foreach ($headerKeys as $index => $key) {
        if ($key === null) {
            continue;
        }
        $data[$key] = isset($row[$index]) ? trim((string) $row[$index]) : '';
    }

    $firstName = trim($data['first_name'] ?? '');
    $lastName = trim($data['last_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $memberIdRaw = trim($data['member_id'] ?? '');

    if (!Validator::required($firstName) || !Validator::required($lastName) || !Validator::email($email) || !Validator::required($memberIdRaw)) {
        $errors[] = 'Row ' . $rowNumber . ': Missing required fields (first_name, last_name, email, member_id).';
        $skippedCount++;
        continue;
    }
    if (!MemberRepository::isEmailAvailable($email)) {
        $errors[] = 'Row ' . $rowNumber . ': Email already linked to another member.';
        $skippedCount++;
        continue;
    }

    $parsedNumber = MembershipService::parseMemberNumberString($memberIdRaw);
    if (!$parsedNumber) {
        $errors[] = 'Row ' . $rowNumber . ': Invalid member ID format.';
        $skippedCount++;
        continue;
    }

    $memberTypeInput = strtoupper(trim($data['member_type'] ?? ''));
    if (!in_array($memberTypeInput, ['FULL', 'ASSOCIATE', 'LIFE'], true)) {
        $memberTypeInput = 'FULL';
    }

    if ($memberTypeInput !== 'ASSOCIATE' && $parsedNumber['suffix'] > 0) {
        $errors[] = 'Row ' . $rowNumber . ': Associate member IDs must include a suffix.';
        $skippedCount++;
        continue;
    }
    if ($memberTypeInput === 'ASSOCIATE' && $parsedNumber['suffix'] === 0) {
        $errors[] = 'Row ' . $rowNumber . ': Associate members require a member ID suffix.';
        $skippedCount++;
        continue;
    }

    $existsStmt->execute([
        'base' => $parsedNumber['base'],
        'suffix' => $parsedNumber['suffix'],
    ]);
    if ($existsStmt->fetch()) {
        $errors[] = 'Row ' . $rowNumber . ': Member ID already exists.';
        $skippedCount++;
        continue;
    }

    $statusInput = strtolower(trim($data['status'] ?? ''));
    if ($statusInput === 'archived') {
        $statusInput = 'cancelled';
    }
    if (!in_array($statusInput, ['pending', 'active', 'expired', 'cancelled', 'suspended'], true)) {
        $statusInput = 'active';
    }

    $fullMemberId = null;
    $fullMemberNumber = trim($data['full_member_number'] ?? '');
    if ($memberTypeInput === 'ASSOCIATE') {
        $fullParsed = null;
        if ($fullMemberNumber !== '') {
            $fullParsed = MembershipService::parseMemberNumberString($fullMemberNumber);
            if (!$fullParsed || ($fullParsed['suffix'] ?? 0) !== 0) {
                $errors[] = 'Row ' . $rowNumber . ': Full member number is invalid.';
                $skippedCount++;
                continue;
            }
        } else {
            $fullParsed = ['base' => $parsedNumber['base'], 'suffix' => 0];
        }
        $fullMemberStmt->execute(['base' => $fullParsed['base']]);
        $fullMemberId = (int) $fullMemberStmt->fetchColumn();
        if ($fullMemberId <= 0) {
            $errors[] = 'Row ' . $rowNumber . ': Full member ID not found for associate member.';
            $skippedCount++;
            continue;
        }
    }

    $chapterId = null;
    if (isset($data['chapter_id']) && $data['chapter_id'] !== '') {
        $chapterId = is_numeric($data['chapter_id']) ? (int) $data['chapter_id'] : null;
    } elseif (isset($data['chapter']) && $data['chapter'] !== '') {
        $lookup = strtolower(trim($data['chapter']));
        $chapterId = $chapterMap[$lookup] ?? null;
    }

    $membershipTypeId = null;
    if (isset($data['membership_type_id']) && $data['membership_type_id'] !== '' && is_numeric($data['membership_type_id'])) {
        $membershipTypeId = (int) $data['membership_type_id'];
    }

    $privacyLevel = strtoupper(trim($data['privacy_level'] ?? ''));
    if ($privacyLevel === '') {
        $privacyLevel = 'A';
    }

    $params = [];
    foreach ($insertColumns as $column) {
        if ($column === 'created_at') {
            continue;
        }
        switch ($column) {
            case 'member_type':
                $params[$column] = $memberTypeInput;
                break;
            case 'status':
                $params[$column] = $statusInput;
                break;
            case 'member_number_base':
                $params[$column] = $parsedNumber['base'];
                break;
            case 'member_number_suffix':
                $params[$column] = $parsedNumber['suffix'];
                break;
            case 'full_member_id':
                $params[$column] = $fullMemberId ?: null;
                break;
            case 'chapter_id':
                $params[$column] = $chapterId ?: null;
                break;
            case 'first_name':
                $params[$column] = $firstName;
                break;
            case 'last_name':
                $params[$column] = $lastName;
                break;
            case 'email':
                $params[$column] = $email;
                break;
            case 'phone':
                $params[$column] = $data['phone'] ?? null;
                break;
            case 'address_line1':
                $params[$column] = $data['address_line1'] ?? null;
                break;
            case 'address_line2':
                $params[$column] = $data['address_line2'] ?? null;
                break;
            case 'city':
                $params[$column] = $data['city'] ?? null;
                break;
            case 'suburb':
                $params[$column] = $data['suburb'] ?? null;
                break;
            case 'state':
                $params[$column] = $data['state'] ?? null;
                break;
            case 'postal_code':
                $params[$column] = $data['postal_code'] ?? null;
                break;
            case 'country':
                $params[$column] = $data['country'] ?? null;
                break;
            case 'privacy_level':
                $params[$column] = $privacyLevel;
                break;
            case 'assist_ute':
                $params[$column] = parseCsvBoolean($data['assist_ute'] ?? null, 0);
                break;
            case 'assist_phone':
                $params[$column] = parseCsvBoolean($data['assist_phone'] ?? null, 0);
                break;
            case 'assist_bed':
                $params[$column] = parseCsvBoolean($data['assist_bed'] ?? null, 0);
                break;
            case 'assist_tools':
                $params[$column] = parseCsvBoolean($data['assist_tools'] ?? null, 0);
                break;
            case 'exclude_printed':
                $params[$column] = parseCsvBoolean($data['exclude_printed'] ?? null, 0);
                break;
            case 'exclude_electronic':
                $params[$column] = parseCsvBoolean($data['exclude_electronic'] ?? null, 0);
                break;
            case 'membership_type_id':
                $params[$column] = $membershipTypeId ?: null;
                break;
            case 'notes':
                $params[$column] = $data['notes'] ?? null;
                break;
            case 'member_number':
                $params[$column] = $parsedNumber['display'] ?? $memberIdRaw;
                break;
            default:
                $params[$column] = $data[$column] ?? null;
        }
    }

    try {
        $insertStmt->execute($params);
        $createdCount++;
    } catch (\Throwable $e) {
        $errors[] = 'Row ' . $rowNumber . ': Import failed.';
        $skippedCount++;
    }
}

fclose($handle);

$errorCount = count($errors);
$errorSummary = '';
if ($errorCount > 0) {
    $displayErrors = array_slice($errors, 0, 5);
    $errorSummary = ' Errors: ' . implode(' ', $displayErrors);
    if ($errorCount > 5) {
        $errorSummary .= ' (+' . ($errorCount - 5) . ' more)';
    }
}

$user = current_user();
ActivityLogger::log('admin', $user['id'] ?? null, null, 'member.import', [
    'created' => $createdCount,
    'skipped' => $skippedCount,
    'errors' => $errorCount,
    'target_type' => 'members',
]);
SecurityAlertService::send('member_import', 'Security alert: member import', '<p>Member import performed by ' . e($user['email'] ?? '') . '.</p>');

$flashType = $createdCount > 0 ? 'success' : 'error';
$flashMessage = 'Imported ' . $createdCount . ' members. Skipped ' . $skippedCount . '.' . $errorSummary;
$_SESSION['members_flash'] = ['type' => $flashType, 'message' => $flashMessage];

header('Location: /admin/members');
exit;
