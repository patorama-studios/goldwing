<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AwardsService;
use App\Services\Csrf;
use App\Services\MediaService;

require_permission('admin.awards.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/awards/');
    exit;
}

if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
    $_SESSION['awards_flash'] = ['type' => 'error', 'message' => 'Invalid CSRF token.'];
    header('Location: /admin/awards/');
    exit;
}

if (!AwardsService::tablesReady()) {
    $_SESSION['awards_flash'] = [
        'type' => 'error',
        'message' => 'Awards tables are not ready. Run the migration runner.',
    ];
    header('Location: /admin/awards/');
    exit;
}

$user = current_user();
$actorUserId = (int) ($user['id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$redirectAfter = (string) ($_POST['redirect_after'] ?? '/admin/awards/');
// Defence in depth: only allow same-site relative paths so a crafted form
// can't bounce the admin off-site after a successful action.
if ($redirectAfter === '' || $redirectAfter[0] !== '/' || str_starts_with($redirectAfter, '//')) {
    $redirectAfter = '/admin/awards/';
}

function awards_flash(string $type, string $message): void
{
    $_SESSION['awards_flash'] = ['type' => $type, 'message' => $message];
}

function awards_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

if ($action === 'set_feature_status') {
    $status = (string) ($_POST['feature_status'] ?? '');
    AwardsService::setFeatureStatus($actorUserId, $status);
    $label = $status === AwardsService::STATUS_LIVE ? 'LIVE — members will see the full Wall of Awards.' : 'Coming Soon — members will see the teaser.';
    awards_flash('success', 'Awards feature is now ' . $label);
    awards_redirect($redirectAfter);
}

if ($action === 'save_winner') {
    $winnerId = (int) ($_POST['id'] ?? 0);
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $year = (int) ($_POST['year'] ?? date('Y'));
    $memberId = (int) ($_POST['member_id'] ?? 0);
    $memberOverride = trim((string) ($_POST['member_name_override'] ?? ''));
    $bike = trim((string) ($_POST['bike_description'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $awardedAt = trim((string) ($_POST['awarded_at'] ?? ''));

    if ($categoryId <= 0) {
        awards_flash('error', 'A trophy category must be selected.');
        awards_redirect($redirectAfter);
    }
    if ($year < 1970 || $year > 2100) {
        awards_flash('error', 'Year looks invalid.');
        awards_redirect($redirectAfter);
    }
    if ($memberId <= 0 && $memberOverride === '') {
        awards_flash('error', 'Pick a member or enter a winner name.');
        awards_redirect($redirectAfter);
    }

    // Block creating a duplicate winner for the same (category, year).
    if ($winnerId <= 0) {
        $existing = AwardsService::findWinnerByCategoryAndYear($categoryId, $year);
        if ($existing) {
            awards_flash('error', 'A winner is already recorded for that trophy and year. Edit the existing record instead.');
            awards_redirect('/admin/awards/edit.php?id=' . (int) $existing['id']);
        }
    }

    try {
        $savedId = AwardsService::saveWinner([
            'id'                   => $winnerId,
            'category_id'          => $categoryId,
            'year'                 => $year,
            'member_id'            => $memberId,
            'member_name_override' => $memberOverride,
            'bike_description'     => $bike,
            'notes'                => $notes,
            'awarded_at'           => $awardedAt !== '' ? $awardedAt : null,
        ], $actorUserId);
    } catch (Throwable $e) {
        awards_flash('error', 'Could not save winner: ' . $e->getMessage());
        awards_redirect($redirectAfter);
    }

    // Handle photo uploads (multiple). Each upload accepted only if it's a real
    // image; the form sends them under photos[]. The first upload on a fresh
    // record becomes primary automatically (see AwardsService::addPhoto).
    if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'] ?? null)) {
        $uploadDir = __DIR__ . '/../../uploads/awards/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        $names = $_FILES['photos']['name'];
        $tmps = $_FILES['photos']['tmp_name'];
        $errors = $_FILES['photos']['error'];
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        $photoErrors = [];
        for ($i = 0, $n = count($names); $i < $n; $i++) {
            if ((int) $errors[$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ((int) $errors[$i] !== UPLOAD_ERR_OK) {
                $photoErrors[] = 'Upload error on photo ' . ($i + 1) . ' (code ' . (int) $errors[$i] . ').';
                continue;
            }
            $ext = strtolower(pathinfo((string) $names[$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts, true)) {
                $photoErrors[] = 'Photo ' . ($i + 1) . ' must be JPG, PNG, or WEBP.';
                continue;
            }
            $filename = 'award_' . $savedId . '_' . uniqid('', false) . '.' . $ext;
            $targetPath = $uploadDir . $filename;
            if (!move_uploaded_file($tmps[$i], $targetPath)) {
                $photoErrors[] = 'Failed to save photo ' . ($i + 1) . '.';
                continue;
            }
            $relativePath = '/uploads/awards/' . $filename;
            AwardsService::addPhoto($savedId, $relativePath);
            // Register in the media library so /admin Media → Awards lists it.
            MediaService::registerUpload([
                'path'                => $relativePath,
                'file_name'           => $filename,
                'visibility'          => 'public',
                'uploaded_by_user_id' => $actorUserId,
                'source_context'      => 'awards',
                'source_table'        => 'award_winner_photos',
                'source_record_id'    => $savedId,
                'title'               => 'AGM Award photo',
            ]);
        }
        if ($photoErrors) {
            awards_flash('error', 'Winner saved, but: ' . implode(' ', $photoErrors));
            awards_redirect('/admin/awards/edit.php?id=' . $savedId);
        }
    }

    awards_flash('success', $winnerId > 0 ? 'Winner updated.' : 'Winner added.');
    awards_redirect('/admin/awards/edit.php?id=' . $savedId);
}

if ($action === 'delete_winner') {
    $winnerId = (int) ($_POST['id'] ?? 0);
    if ($winnerId > 0) {
        AwardsService::deleteWinner($winnerId);
        awards_flash('success', 'Winner removed.');
    }
    awards_redirect($redirectAfter);
}

if ($action === 'set_primary_photo') {
    $photoId = (int) ($_POST['photo_id'] ?? 0);
    if ($photoId > 0) {
        AwardsService::setPrimaryPhoto($photoId);
        awards_flash('success', 'Primary photo updated.');
    }
    awards_redirect($redirectAfter);
}

if ($action === 'delete_photo') {
    $photoId = (int) ($_POST['photo_id'] ?? 0);
    if ($photoId > 0) {
        AwardsService::deletePhoto($photoId);
        awards_flash('success', 'Photo removed.');
    }
    awards_redirect($redirectAfter);
}

if ($action === 'save_category') {
    $categoryId = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        awards_flash('error', 'Category name is required.');
        awards_redirect($redirectAfter);
    }
    AwardsService::saveCategory([
        'id'                   => $categoryId,
        'sort_order'           => (int) ($_POST['sort_order'] ?? 0),
        'name'                 => $name,
        'group_label'          => $_POST['group_label'] ?? null,
        'memorial_trophy_name' => $_POST['memorial_trophy_name'] ?? null,
        'description'          => $_POST['description'] ?? null,
        'is_active'            => !empty($_POST['is_active']),
    ]);
    awards_flash('success', $categoryId > 0 ? 'Category updated.' : 'Category created.');
    awards_redirect($redirectAfter);
}

awards_flash('error', 'Unknown action.');
awards_redirect('/admin/awards/');
