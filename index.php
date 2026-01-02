<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

foreach (array_keys($_SESSION) as $key) {
    if (strpos($key, 'exam_access_') === 0 || strpos($key, 'exam_roster_student_') === 0) {
        unset($_SESSION[$key]);
    }
}

$now = new DateTimeImmutable('now');

$stmt = db()->query('SELECT * FROM exams WHERE is_completed = 0 ORDER BY start_time ASC');
$exams = [];
foreach ($stmt->fetchAll() as $exam) {
    if (exam_is_active($exam, $now)) {
        $exams[] = $exam;
    }
}

$examFilesByExam = [];
if (count($exams) > 0) {
    $examIds = array_map(static function (array $exam): int {
        return (int) $exam['id'];
    }, $exams);
    $placeholders = implode(',', array_fill(0, count($examIds), '?'));
    $stmt = db()->prepare(
        "SELECT * FROM exam_files WHERE exam_id IN ($placeholders)
         ORDER BY exam_id ASC, title ASC, original_name ASC, id ASC"
    );
    $stmt->execute($examIds);
    foreach ($stmt->fetchAll() as $file) {
        $examId = (int) $file['exam_id'];
        if (!isset($examFilesByExam[$examId])) {
            $examFilesByExam[$examId] = [];
        }
        $examFilesByExam[$examId][] = $file;
    }
}
$pageTitle = 'Exams Submission Portal';
$brandHref = 'index.php';
$brandText = 'Exams Submission Portal';
$logoPath = 'logo.png';
$cssPath = 'style.css';
$navActions = '<a class="btn btn-outline-secondary btn-sm" href="staff/index.php">Exam Administration</a>';
$preauthErrors = $_SESSION['preauth_exam_error'] ?? [];
if (isset($_SESSION['preauth_exam_error'])) {
    unset($_SESSION['preauth_exam_error']);
}
require __DIR__ . '/header.php';
?>
<main class="container py-4">
    <div class="mb-4">
        <h1 class="h3">Today's Exam(s)</h1>
        <p class="text-muted">Only exams currently open for submissions are shown.</p>
    </div>

    <?php if (count($exams) === 0): ?>
        <div class="alert alert-info">No active exams right now.</div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($exams as $exam): ?>
                <div class="col-12">
                    <div class="card shadow-sm h-100 exam-card">
                        <div class="card-body">
                            <h2 class="h5 mb-2"><?php echo e($exam['title']); ?></h2>
                            <p class="text-muted mb-3">
                                Window: <?php echo e(format_datetime_display($exam['start_time'])); ?> to <?php echo e(format_datetime_display($exam['end_time'])); ?>
                            </p>
                            <div class="d-flex flex-wrap gap-2">
                                <?php
                                $examId = (int) $exam['id'];
                                $examFiles = $examFilesByExam[$examId] ?? [];
                                $needsExamPassword = !empty($exam['access_password_hash']);
                                $needsRosterPassword = !empty($exam['student_roster_enabled']) && ($exam['student_roster_mode'] ?? '') === 'password';
                                $promptNeeded = $needsExamPassword || $needsRosterPassword;
                                $errorMessage = is_array($preauthErrors) ? ($preauthErrors[$examId] ?? '') : '';
                                ?>
                                <a class="btn btn-primary<?php echo $promptNeeded ? ' preauth-submit' : ''; ?>" href="student_exam.php?id=<?php echo $examId; ?>"
                                    data-exam-id="<?php echo $examId; ?>"
                                    data-exam-title="<?php echo e((string) $exam['title']); ?>"
                                    data-needs-exam-password="<?php echo $needsExamPassword ? '1' : '0'; ?>"
                                    data-needs-roster-password="<?php echo $needsRosterPassword ? '1' : '0'; ?>"
                                    data-error-message="<?php echo e((string) $errorMessage); ?>">
                                    Submit Files
                                </a>
                                <?php if (count($examFiles) > 0): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            Download Exam Materials
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item<?php echo $promptNeeded ? ' preauth-download' : ''; ?>" href="download_exam_files_zip.php?exam_id=<?php echo $examId; ?>"
                                                    data-exam-id="<?php echo $examId; ?>"
                                                    data-exam-title="<?php echo e((string) $exam['title']); ?>"
                                                    data-needs-exam-password="<?php echo $needsExamPassword ? '1' : '0'; ?>"
                                                    data-needs-roster-password="<?php echo $needsRosterPassword ? '1' : '0'; ?>"
                                                    data-error-message="<?php echo e((string) $errorMessage); ?>">
                                                    Download all (ZIP)
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <?php foreach ($examFiles as $file): ?>
                                                <li>
                                                    <a class="dropdown-item<?php echo $promptNeeded ? ' preauth-download' : ''; ?>" href="download_exam_file.php?id=<?php echo (int) $file['id']; ?>"
                                                        data-exam-id="<?php echo $examId; ?>"
                                                        data-exam-title="<?php echo e((string) $exam['title']); ?>"
                                                        data-needs-exam-password="<?php echo $needsExamPassword ? '1' : '0'; ?>"
                                                        data-needs-roster-password="<?php echo $needsRosterPassword ? '1' : '0'; ?>"
                                                        data-error-message="<?php echo e((string) $errorMessage); ?>">
                                                        <?php echo e($file['title'] !== '' ? $file['title'] : $file['original_name']); ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<div class="modal fade" id="examPasswordModal" tabindex="-1" aria-labelledby="examPasswordLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="examPasswordLabel">Enter access details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="preauth_exam.php">
                <div class="modal-body">
                    <p class="text-muted mb-3" id="examPasswordSubtitle"></p>
                    <div class="alert alert-danger d-none" id="examPasswordError"></div>
                    <input type="hidden" name="exam_id" id="examPasswordExamId" value="">
                    <input type="hidden" name="return_to" id="examPasswordReturnTo" value="">

                    <div class="mb-3 d-none" id="examPasswordExamField">
                        <label class="form-label">Exam access password</label>
                        <input class="form-control" type="password" name="exam_password" autocomplete="current-password">
                    </div>
                    <div class="mb-3 d-none" id="examPasswordRosterField">
                        <label class="form-label">Student password</label>
                        <input class="form-control" type="password" name="student_password" autocomplete="current-password">
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit">Continue</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const preauthModal = new bootstrap.Modal(document.getElementById('examPasswordModal'));
    const modalTitle = document.getElementById('examPasswordLabel');
    const modalSubtitle = document.getElementById('examPasswordSubtitle');
    const modalError = document.getElementById('examPasswordError');
    const modalExamId = document.getElementById('examPasswordExamId');
    const modalReturnTo = document.getElementById('examPasswordReturnTo');
    const examField = document.getElementById('examPasswordExamField');
    const rosterField = document.getElementById('examPasswordRosterField');
    const examPasswordInput = examField?.querySelector('input');
    const rosterPasswordInput = rosterField?.querySelector('input');
    const preauthForm = document.querySelector('#examPasswordModal form');

    const openPreauthModal = (button, returnTo) => {
        const examId = button.dataset.examId;
        const examTitle = button.dataset.examTitle || 'Exam';
        const needsExamPassword = button.dataset.needsExamPassword === '1';
        const needsRosterPassword = button.dataset.needsRosterPassword === '1';
        const errorMessage = button.dataset.errorMessage || '';

        modalTitle.textContent = 'Enter access details';
        modalSubtitle.textContent = 'Please enter the password supplied to you for this exam:';
        modalExamId.value = examId || '';
        modalReturnTo.value = returnTo || '';
        examField.classList.toggle('d-none', !needsExamPassword);
        rosterField.classList.toggle('d-none', !needsRosterPassword);

        if (examPasswordInput) {
            examPasswordInput.value = '';
        }
        if (rosterPasswordInput) {
            rosterPasswordInput.value = '';
        }

        if (errorMessage) {
            modalError.textContent = errorMessage;
            modalError.classList.remove('d-none');
        } else {
            modalError.textContent = '';
            modalError.classList.add('d-none');
        }

        preauthModal.show();
    };

    document.querySelectorAll('.preauth-submit').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            openPreauthModal(link, link.getAttribute('href'));
        });
    });

    document.querySelectorAll('.preauth-download').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            openPreauthModal(link, link.getAttribute('href'));
        });
    });

    document.querySelectorAll('.preauth-submit').forEach((link) => {
        const errorMessage = link.dataset.errorMessage || '';
        if (errorMessage) {
            openPreauthModal(link, link.getAttribute('href'));
        }
    });

    if (preauthForm) {
        preauthForm.addEventListener('submit', () => {
            preauthModal.hide();
        });
    }
</script>
<?php require __DIR__ . '/footer.php'; ?>
