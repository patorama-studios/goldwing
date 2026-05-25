<?php
/**
 * One-shot import trigger: reads import_main_life.csv or import_associates.csv
 * from scripts/data/ (project root) and imports them without requiring a file upload.
 *
 * Usage (browser fetch):
 *   POST /admin/members/import_from_datafile.php
 *   Body (form-encoded): csrf_token=<token>&file=main_life   OR   file=associates
 *
 * Returns JSON: { created, skipped, errors[], message }
 *
 * DELETE THIS FILE once the import is complete.
 */

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\ChapterRepository;
use App\Services\Csrf;
use App\Services\Database;
use App\Services\MemberRepository;
use App\Services\MembershipService;
use App\Services\Validator;

require_permission('admin.members.import_export');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST required']);
    exit;
}

if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$fileParam = $_POST['file'] ?? '';
$allowedFiles = [
    'main_life'  => __DIR__ . '/../../../scripts/data/import_main_life.csv',
    'associates' => __DIR__ . '/../../../scripts/data/import_associates.csv',
];

if (!isset($allowedFiles[$fileParam])) {
    echo json_encode(['error' => 'Invalid file parameter. Use main_life or associates.']);
    exit;
}

$csvPath = realpath($allowedFiles[$fileParam]);
if (!$csvPath || !is_readable($csvPath)) {
    echo json_encode(['error' => 'CSV file not found or not readable: ' . $allowedFiles[$fileParam]]);
    exit;
}

// ── helpers (mirrors import.php) ─────────────────────────────────────────────

function ifd_normalizeHeader(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    return trim($value, '_');
}

function ifd_parseCsvBoolean(?string $value, int $default = 0): int
{
    if ($value === null || trim($value) === '') {
        return $default;
    }
    $result = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $result === null ? $default : ($result ? 1 : 0);
}

function ifd_fetchMemberColumns(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $stmt->execute(['table' => 'members']);
    return array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
}

// ── header lookup (same as import.php) ──────────────────────────────────────

$headerLookup = [
    'first_name' => 'first_name', 'firstname' => 'first_name', 'given_name' => 'first_name',
    'last_name' => 'last_name', 'lastname' => 'last_name', 'surname' => 'last_name', 'family_name' => 'last_name',
    'email' => 'email', 'email_address' => 'email',
    'member' => 'member_id', 'member_id' => 'member_id', 'member_number' => 'member_id',
    'member_no' => 'member_id', 'member_number_id' => 'member_id', 'memberid' => 'member_id',
    'member_id_number' => 'member_id',
    'member_type' => 'member_type', 'membership_type' => 'member_type',
    'membership_type_id' => 'membership_type_id',
    'status' => 'status',
    'chapter' => 'chapter', 'chapter_name' => 'chapter', 'chapter_id' => 'chapter_id',
    'phone' => 'phone', 'phone_number' => 'phone', 'mobile' => 'phone',
    'address_line1' => 'address_line1', 'address_1' => 'address_line1', 'address1' => 'address_line1', 'street' => 'address_line1',
    'address_line2' => 'address_line2', 'address_2' => 'address_line2', 'address2' => 'address_line2',
    'city' => 'city', 'suburb' => 'suburb', 'state' => 'state',
    'postal_code' => 'postal_code', 'postcode' => 'postal_code', 'zip' => 'postal_code',
    'country' => 'country',
    'privacy_level' => 'privacy_level', 'privacy' => 'privacy_level',
    'assist_ute' => 'assist_ute', 'assist_phone' => 'assist_phone',
    'assist_bed' => 'assist_bed', 'assist_tools' => 'assist_tools',
    'exclude_printed' => 'exclude_printed', 'exclude_electronic' => 'exclude_electronic',
    'notes' => 'notes', 'note' => 'notes',
    'full_member_number' => 'full_member_number', 'full_member_id' => 'full_member_number',
    'is_historic' => 'is_historic', 'historic' => 'is_historic',
];

// ── open file ────────────────────────────────────────────────────────────────

$handle = fopen($csvPath, 'r');
if (!$handle) {
    echo json_encode(['error' => 'Unable to open CSV file.']);
    exit;
}

$headerRow = fgetcsv($handle);
if (!$headerRow) {
    fclose($handle);
    echo json_encode(['error' => 'CSV missing header row.']);
    exit;
}

if (isset($headerRow[0])) {
    $headerRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headerRow[0]);
}

$headerKeys = [];
foreach ($headerRow as $index => $label) {
    $normalized = ifd_normalizeHeader((string) $label);
    $headerKeys[$index] = $headerLookup[$normalized] ?? null;
}

foreach (['first_name', 'last_name', 'email', 'member_id'] as $required) {
    if (!in_array($required, $headerKeys, true)) {
        fclose($handle);
        echo json_encode(['error' => 'CSV missing required column: ' . $required]);
        exit;
    }
}

// ── DB setup ──────────────────────────────────────────────────────────────────

$pdo = Database::connection();
$columnExists      = ifd_fetchMemberColumns($pdo);
$memberNumberAvailable = MemberRepository::hasMemberNumberColumn($pdo);

$chapterMap = [];
foreach (ChapterRepository::listForSelection($pdo, false) as $chapter) {
    $nameKey = strtolower(trim((string) ($chapter['name'] ?? '')));
    if ($nameKey !== '') {
        $chapterMap[$nameKey] = (int) $chapter['id'];
    }
}

$candidateColumns = [
    'member_type', 'status', 'member_number_base', 'member_number_suffix',
    'full_member_id', 'chapter_id', 'first_name', 'last_name', 'email', 'phone',
    'address_line1', 'address_line2', 'city', 'suburb', 'state', 'postal_code', 'country',
    'privacy_level', 'assist_ute', 'assist_phone', 'assist_bed', 'assist_tools',
    'exclude_printed', 'exclude_electronic', 'membership_type_id', 'notes',
    'member_number', 'created_at', 'is_historic',
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

$insertSql  = 'INSERT INTO members (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $valueParts) . ')';
$insertStmt = $pdo->prepare($insertSql);
$existsStmt = $pdo->prepare('SELECT id FROM members WHERE member_number_base = :base AND member_number_suffix = :suffix LIMIT 1');
$fullMemberStmt = $pdo->prepare('SELECT id FROM members WHERE member_number_base = :base AND member_number_suffix = 0 LIMIT 1');

// ── import loop ───────────────────────────────────────────────────────────────

$createdCount = 0;
$skippedCount = 0;
$errors       = [];
$rowNumber    = 1;

while (($row = fgetcsv($handle)) !== false) {
    $rowNumber++;
    if (!$row || array_filter($row, static fn($v) => trim((string) $v) !== '') === []) {
        continue;
    }

    $data = [];
    foreach ($headerKeys as $index => $key) {
        if ($key === null) continue;
        $data[$key] = isset($row[$index]) ? trim((string) $row[$index]) : '';
    }

    $firstName   = trim($data['first_name'] ?? '');
    $lastName    = trim($data['last_name']  ?? '');
    $email       = trim($data['email']      ?? '');
    $memberIdRaw = trim($data['member_id']  ?? '');

    $memberTypeForValidation = strtoupper(trim($data['member_type'] ?? 'FULL'));
    $emailRequired = $memberTypeForValidation !== 'ASSOCIATE';

    if (!Validator::required($firstName) || !Validator::required($lastName) || !Validator::required($memberIdRaw)
        || ($emailRequired && !Validator::email($email))) {
        $errors[] = 'Row ' . $rowNumber . ': Missing required fields.';
        $skippedCount++;
        continue;
    }
    if (!$emailRequired && $email !== '' && !Validator::email($email)) {
        $errors[] = 'Row ' . $rowNumber . ': Invalid email for associate.';
        $skippedCount++;
        continue;
    }
    if ($email !== '' && !MemberRepository::isEmailAvailable($email)) {
        $errors[] = 'Row ' . $rowNumber . ': Email already used (' . $email . ').';
        $skippedCount++;
        continue;
    }

    $parsedNumber = MembershipService::parseMemberNumberString($memberIdRaw);
    if (!$parsedNumber) {
        $errors[] = 'Row ' . $rowNumber . ': Invalid member ID format (' . $memberIdRaw . ').';
        $skippedCount++;
        continue;
    }

    $memberTypeInput = strtoupper(trim($data['member_type'] ?? ''));
    if (!in_array($memberTypeInput, ['FULL', 'ASSOCIATE', 'LIFE'], true)) {
        $memberTypeInput = 'FULL';
    }

    if ($memberTypeInput !== 'ASSOCIATE' && $parsedNumber['suffix'] > 0) {
        $errors[] = 'Row ' . $rowNumber . ': Non-associate member ID cannot have suffix.';
        $skippedCount++;
        continue;
    }
    if ($memberTypeInput === 'ASSOCIATE' && $parsedNumber['suffix'] === 0) {
        $errors[] = 'Row ' . $rowNumber . ': Associate member ID needs suffix.';
        $skippedCount++;
        continue;
    }

    $existsStmt->execute(['base' => $parsedNumber['base'], 'suffix' => $parsedNumber['suffix']]);
    if ($existsStmt->fetch()) {
        $errors[] = 'Row ' . $rowNumber . ': Member ID ' . $memberIdRaw . ' already exists (skipped).';
        $skippedCount++;
        continue;
    }

    $fullMemberId = null;
    if ($memberTypeInput === 'ASSOCIATE') {
        $fullMemberNumber = trim($data['full_member_number'] ?? '');
        if ($fullMemberNumber !== '') {
            $fullParsed = MembershipService::parseMemberNumberString($fullMemberNumber);
            if (!$fullParsed || ($fullParsed['suffix'] ?? 0) !== 0) {
                $errors[] = 'Row ' . $rowNumber . ': Full member number invalid.';
                $skippedCount++;
                continue;
            }
        } else {
            $fullParsed = ['base' => $parsedNumber['base'], 'suffix' => 0];
        }
        $fullMemberStmt->execute(['base' => $fullParsed['base']]);
        $fullMemberId = (int) $fullMemberStmt->fetchColumn();
        if ($fullMemberId <= 0) {
            $errors[] = 'Row ' . $rowNumber . ': Full member not found for associate (base=' . $fullParsed['base'] . ').';
            $skippedCount++;
            continue;
        }
    }

    $chapterId = null;
    if (!empty($data['chapter_id']) && is_numeric($data['chapter_id'])) {
        $chapterId = (int) $data['chapter_id'];
    } elseif (!empty($data['chapter'])) {
        $chapterId = $chapterMap[strtolower(trim($data['chapter']))] ?? null;
    }

    $membershipTypeId = null;
    if (!empty($data['membership_type_id']) && is_numeric($data['membership_type_id'])) {
        $membershipTypeId = (int) $data['membership_type_id'];
    }

    $privacyLevel = strtoupper(trim($data['privacy_level'] ?? '')) ?: 'A';

    $statusInput = strtolower(trim($data['status'] ?? ''));
    if ($statusInput === 'archived') $statusInput = 'cancelled';
    if (!in_array($statusInput, ['pending', 'active', 'expired', 'cancelled', 'suspended'], true)) {
        $statusInput = 'active';
    }

    $params = [];
    foreach ($insertColumns as $column) {
        if ($column === 'created_at') continue;
        switch ($column) {
            case 'member_type':         $params[$column] = $memberTypeInput; break;
            case 'status':              $params[$column] = $statusInput; break;
            case 'member_number_base':  $params[$column] = $parsedNumber['base']; break;
            case 'member_number_suffix':$params[$column] = $parsedNumber['suffix']; break;
            case 'full_member_id':      $params[$column] = $fullMemberId ?: null; break;
            case 'chapter_id':          $params[$column] = $chapterId ?: null; break;
            case 'first_name':          $params[$column] = $firstName; break;
            case 'last_name':           $params[$column] = $lastName; break;
            case 'email':               $params[$column] = $email ?: null; break;
            case 'phone':               $params[$column] = $data['phone'] ?? null; break;
            case 'address_line1':       $params[$column] = $data['address_line1'] ?? null; break;
            case 'address_line2':       $params[$column] = $data['address_line2'] ?? null; break;
            case 'city':                $params[$column] = $data['city'] ?? null; break;
            case 'suburb':              $params[$column] = $data['suburb'] ?? null; break;
            case 'state':               $params[$column] = $data['state'] ?? null; break;
            case 'postal_code':         $params[$column] = $data['postal_code'] ?? null; break;
            case 'country':             $params[$column] = $data['country'] ?? null; break;
            case 'privacy_level':       $params[$column] = $privacyLevel; break;
            case 'assist_ute':          $params[$column] = ifd_parseCsvBoolean($data['assist_ute'] ?? null); break;
            case 'assist_phone':        $params[$column] = ifd_parseCsvBoolean($data['assist_phone'] ?? null); break;
            case 'assist_bed':          $params[$column] = ifd_parseCsvBoolean($data['assist_bed'] ?? null); break;
            case 'assist_tools':        $params[$column] = ifd_parseCsvBoolean($data['assist_tools'] ?? null); break;
            case 'exclude_printed':     $params[$column] = ifd_parseCsvBoolean($data['exclude_printed'] ?? null); break;
            case 'exclude_electronic':  $params[$column] = ifd_parseCsvBoolean($data['exclude_electronic'] ?? null); break;
            case 'membership_type_id':  $params[$column] = $membershipTypeId ?: null; break;
            case 'notes':               $params[$column] = $data['notes'] ?? null; break;
            case 'member_number':       $params[$column] = $parsedNumber['display'] ?? $memberIdRaw; break;
            case 'is_historic':         $params[$column] = ifd_parseCsvBoolean($data['is_historic'] ?? null); break;
            default:                    $params[$column] = $data[$column] ?? null;
        }
    }

    try {
        $insertStmt->execute($params);
        $createdCount++;
    } catch (\Throwable $e) {
        $errors[] = 'Row ' . $rowNumber . ': DB error — ' . $e->getMessage();
        $skippedCount++;
    }
}

fclose($handle);

$user = current_user();
ActivityLogger::log('admin', $user['id'] ?? null, null, 'member.import', [
    'created' => $createdCount,
    'skipped' => $skippedCount,
    'errors'  => count($errors),
    'source'  => 'import_from_datafile:' . $fileParam,
]);

echo json_encode([
    'created'  => $createdCount,
    'skipped'  => $skippedCount,
    'errors'   => $errors,
    'message'  => 'Imported ' . $createdCount . ' members. Skipped ' . $skippedCount . '.',
]);
