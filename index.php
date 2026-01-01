<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

foreach (array_keys($_SESSION) as $key) {
    if (strpos($key, 'exam_roster_student_') === 0) {
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
    $stmt = db()->prepare("SELECT * FROM exam_files WHERE exam_id IN ($placeholders) ORDER BY uploaded_at DESC, id DESC");
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
                                <a class="btn btn-primary" href="student_exam.php?id=<?php echo (int) $exam['id']; ?>">
                                    Submit Files
                                </a>
                                <?php
                                $examId = (int) $exam['id'];
                                $examFiles = $examFilesByExam[$examId] ?? [];
                                ?>
                                <?php if (count($examFiles) > 0): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            Download Exam Materials
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="download_exam_files_zip.php?exam_id=<?php echo $examId; ?>">Download all (ZIP)</a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <?php foreach ($examFiles as $file): ?>
                                                <li>
                                                    <a class="dropdown-item" href="download_exam_file.php?id=<?php echo (int) $file['id']; ?>">
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/footer.php'; ?>
