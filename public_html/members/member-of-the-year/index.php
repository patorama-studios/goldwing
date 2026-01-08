<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\NotificationService;

require_login();

$pdo = db();
$user = current_user();
$copy = require __DIR__ . '/../../../config/member_of_year.php';

$errors = [];
$success = false;
$tableReady = false;
try {
    $tableReady = (bool) $pdo->query("SHOW TABLES LIKE 'member_of_year_nominations'")->fetch();
} catch (Throwable $e) {
    $tableReady = false;
}

$formValues = [
    'nominator_first_name' => '',
    'nominator_last_name' => '',
    'nominator_email' => '',
    'nominee_first_name' => '',
    'nominee_last_name' => '',
    'nominee_chapter' => '',
    'nomination_details' => '',
];

function text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }
    return strlen($value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tableReady) {
        $errors[] = 'Nominations are not available yet. Please try again later.';
    }
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    }

    $formValues['nominator_first_name'] = trim((string) ($_POST['nominator_first_name'] ?? ''));
    $formValues['nominator_last_name'] = trim((string) ($_POST['nominator_last_name'] ?? ''));
    $formValues['nominator_email'] = strtolower(trim((string) ($_POST['nominator_email'] ?? '')));
    $formValues['nominee_first_name'] = trim((string) ($_POST['nominee_first_name'] ?? ''));
    $formValues['nominee_last_name'] = trim((string) ($_POST['nominee_last_name'] ?? ''));
    $formValues['nominee_chapter'] = trim((string) ($_POST['nominee_chapter'] ?? ''));
    $formValues['nomination_details'] = trim((string) ($_POST['nomination_details'] ?? ''));

    if ($formValues['nominator_first_name'] === '') {
        $errors[] = 'Nominator first name is required.';
    }
    if ($formValues['nominator_last_name'] === '') {
        $errors[] = 'Nominator last name is required.';
    }
    if ($formValues['nominator_email'] === '' || !filter_var($formValues['nominator_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid nominator email is required.';
    }
    if ($formValues['nominee_first_name'] === '') {
        $errors[] = 'Nominee first name is required.';
    }
    if ($formValues['nominee_last_name'] === '') {
        $errors[] = 'Nominee last name is required.';
    }
    if ($formValues['nominee_chapter'] === '') {
        $errors[] = 'Nominee chapter is required.';
    }

    $detailsLength = text_length($formValues['nomination_details']);
    if ($detailsLength < 50) {
        $errors[] = 'Nomination details must be at least 50 characters.';
    }
    if ($detailsLength > 3000) {
        $errors[] = 'Nomination details must be 3000 characters or fewer.';
    }

    // TODO: Allow nominations to open/close per year via settings.
    $submissionYear = (int) date('Y');

    if (!$errors) {
        $duplicateStmt = $pdo->prepare('SELECT id FROM member_of_year_nominations WHERE submission_year = :submission_year AND nominator_email = :nominator_email AND nominee_first_name = :nominee_first_name AND nominee_last_name = :nominee_last_name LIMIT 1');
        $duplicateStmt->execute([
            'submission_year' => $submissionYear,
            'nominator_email' => $formValues['nominator_email'],
            'nominee_first_name' => $formValues['nominee_first_name'],
            'nominee_last_name' => $formValues['nominee_last_name'],
        ]);
        if ($duplicateStmt->fetch()) {
            $errors[] = 'You have already submitted a nomination for this nominee this year.';
        }
    }

    if (!$errors) {
        $submittedByUserId = !empty($user['id']) ? (int) $user['id'] : null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($userAgent !== null) {
            $userAgent = substr($userAgent, 0, 255);
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO member_of_year_nominations (submission_year, submitted_by_user_id, nominator_first_name, nominator_last_name, nominator_email, nominee_first_name, nominee_last_name, nominee_chapter, nomination_details, status, admin_notes, ip_address, user_agent, submitted_at)
                VALUES (:submission_year, :submitted_by_user_id, :nominator_first_name, :nominator_last_name, :nominator_email, :nominee_first_name, :nominee_last_name, :nominee_chapter, :nomination_details, :status, :admin_notes, :ip_address, :user_agent, NOW())');
            $stmt->execute([
                'submission_year' => $submissionYear,
                'submitted_by_user_id' => $submittedByUserId,
                'nominator_first_name' => $formValues['nominator_first_name'],
                'nominator_last_name' => $formValues['nominator_last_name'],
                'nominator_email' => $formValues['nominator_email'],
                'nominee_first_name' => $formValues['nominee_first_name'],
                'nominee_last_name' => $formValues['nominee_last_name'],
                'nominee_chapter' => $formValues['nominee_chapter'],
                'nomination_details' => $formValues['nomination_details'],
                'status' => 'new',
                'admin_notes' => '',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);
            $submissionId = (int) $pdo->lastInsertId();

            $nominatorName = trim($formValues['nominator_first_name'] . ' ' . $formValues['nominator_last_name']);
            $nomineeName = trim($formValues['nominee_first_name'] . ' ' . $formValues['nominee_last_name']);
            $safeDetails = nl2br(NotificationService::escape($formValues['nomination_details']));

            $context = [
                'primary_email' => $formValues['nominator_email'],
                'admin_emails' => NotificationService::getAdminEmails(),
                'nominator_name' => NotificationService::escape($nominatorName),
                'nominator_email' => NotificationService::escape($formValues['nominator_email']),
                'nominee_name' => NotificationService::escape($nomineeName),
                'nominee_chapter' => NotificationService::escape($formValues['nominee_chapter']),
                'submission_year' => $submissionYear,
                'nomination_details' => $safeDetails,
                'submission_id' => $submissionId,
                'user_id' => $user['id'] ?? null,
            ];

            NotificationService::dispatch('member_of_year_nomination_receipt', $context, ['force' => true]);
            NotificationService::dispatch('member_of_year_nomination_admin', $context, ['force' => true, 'visibility' => 'admin']);

            $success = true;
            $formValues = [
                'nominator_first_name' => '',
                'nominator_last_name' => '',
                'nominator_email' => '',
                'nominee_first_name' => '',
                'nominee_last_name' => '',
                'nominee_chapter' => '',
                'nomination_details' => '',
            ];
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'You have already submitted a nomination for this nominee this year.';
            } else {
                $errors[] = 'Unable to save your nomination right now.';
            }
        }
    }
}

$pageTitle = $copy['page_title'] ?? 'Member of the Year';
$activePage = 'member-of-the-year';
$activeSubPage = $activePage;
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_member_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = $pageTitle; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <?php if (!$tableReady): ?>
        <div class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700">
          Member of the Year nominations are not available yet. Please check back soon.
        </div>
      <?php endif; ?>
      <?php if ($success): ?>
        <?php require __DIR__ . '/../../../app/Views/member_of_year/SubmissionSuccess.php'; ?>
      <?php else: ?>
        <?php require __DIR__ . '/../../../app/Views/member_of_year/MemberOfYearForm.php'; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
