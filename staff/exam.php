<?php

declare(strict_types=1);

require __DIR__ . '/../auth/require_auth.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers.php';

$config = require __DIR__ . '/../config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');

$examId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM exams WHERE id = ?');
$stmt->execute([$examId]);
$exam = $stmt->fetch();

if (!$exam) {
    http_response_code(404);
    echo 'Exam not found.';
    exit;
}

$pageTitle = 'Exam - ' . $exam['title'];
$brandHref = 'index.php';
$brandText = 'Exams Administration Portal';
$logoPath = '../logo.png';
$cssPath = '../style.css';
$navActions = '<a class="btn btn-outline-secondary btn-sm" href="../index.php">Student View</a>'
    . '<a class="btn btn-outline-secondary btn-sm" href="../auth/logout.php">Logout</a>';
require __DIR__ . '/../header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'complete') {
        $stmt = db()->prepare('UPDATE exams SET is_completed = 1, completed_at = ? WHERE id = ?');
        $stmt->execute([now_utc_string(), $examId]);
        header('Location: exam.php?id=' . $examId);
        exit;
    }
    if ($action === 'reopen') {
        $stmt = db()->prepare('UPDATE exams SET is_completed = 0, completed_at = NULL WHERE id = ?');
        $stmt->execute([$examId]);
        header('Location: exam.php?id=' . $examId);
        exit;
    }
    if ($action === 'reset_submission') {
        $submissionId = (int) ($_POST['submission_id'] ?? 0);
        if ($submissionId > 0) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT * FROM submissions WHERE id = ? AND exam_id = ?');
                $stmt->execute([$submissionId, $examId]);
                $submissionInfo = $stmt->fetch();

                $stmt = $pdo->prepare(
                    'SELECT sf.stored_path
                     FROM submissions s
                     JOIN submission_files sf ON sf.submission_id = s.id
                     WHERE s.id = ? AND s.exam_id = ?'
                );
                $stmt->execute([$submissionId, $examId]);
                $paths = $stmt->fetchAll();

                $stmt = $pdo->prepare('DELETE FROM submissions WHERE id = ? AND exam_id = ?');
                $stmt->execute([$submissionId, $examId]);

                $pdo->commit();

                $archiveRoot = $uploadsDir . '/archive/exam_' . $examId;
                $timestamp = (new DateTimeImmutable('now'))->format('Ymd_His');
                $archiveFolder = $archiveRoot . '/submission_' . $submissionId . '_' . $timestamp;
                $submissionFolder = null;

                foreach ($paths as $row) {
                    $path = $uploadsDir . '/' . ltrim($row['stored_path'], '/');
                    if ($submissionFolder === null) {
                        $submissionFolder = dirname($path);
                    }
                }

                if ($submissionFolder && is_dir($submissionFolder)) {
                    if (!is_dir($archiveRoot)) {
                        mkdir($archiveRoot, 0755, true);
                    }

                    if (!@rename($submissionFolder, $archiveFolder)) {
                        if (!is_dir($archiveFolder)) {
                            mkdir($archiveFolder, 0755, true);
                        }
                        foreach ($paths as $row) {
                            $path = $uploadsDir . '/' . ltrim($row['stored_path'], '/');
                            $realPath = realpath($path);
                            if ($realPath && strpos($realPath, realpath($uploadsDir)) === 0 && is_file($realPath)) {
                                $target = $archiveFolder . '/' . basename($realPath);
                                @rename($realPath, $target);
                            }
                        }
                        @rmdir($submissionFolder);
                    }

                    if ($submissionInfo) {
                        $metadata = [
                            'submission_id' => (int) $submissionInfo['id'],
                            'student_first_name' => (string) $submissionInfo['student_first_name'],
                            'student_last_name' => (string) $submissionInfo['student_last_name'],
                            'student_name' => (string) $submissionInfo['student_name'],
                            'candidate_number' => (string) $submissionInfo['candidate_number'],
                            'submitted_at' => (string) $submissionInfo['submitted_at'],
                            'archived_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                        ];
                        $metaPath = $archiveFolder . '/metadata.json';
                        @file_put_contents($metaPath, json_encode($metadata, JSON_PRETTY_PRINT));
                    }
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
            }
        }
        header('Location: exam.php?id=' . $examId);
        exit;
    }
}

$stmt = db()->prepare('SELECT * FROM exam_documents WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$examId]);
$documents = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT s.*, sf.id AS file_id, sf.original_name, sf.stored_path, ed.title AS document_title
     FROM submissions s
     LEFT JOIN submission_files sf ON sf.submission_id = s.id
     LEFT JOIN exam_documents ed ON ed.id = sf.exam_document_id
     WHERE s.exam_id = ?
     ORDER BY s.submitted_at DESC, sf.id ASC'
);
$stmt->execute([$examId]);
$rows = $stmt->fetchAll();

$submissions = [];
$hasFiles = false;
foreach ($rows as $row) {
    $submissionId = (int) $row['id'];
    if (!isset($submissions[$submissionId])) {
        $submissions[$submissionId] = [
            'info' => $row,
            'files' => [],
        ];
    }

    if (!empty($row['file_id'])) {
        $submissions[$submissionId]['files'][] = $row;
        $hasFiles = true;
    }
}

$rosterEnabled = !empty($exam['student_roster_enabled']);
if ($rosterEnabled) {
    $stmt = db()->prepare('SELECT * FROM exam_students WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$examId]);
    $rosterStudents = $stmt->fetchAll();

    $submissionByCandidate = [];
    foreach ($submissions as $submissionId => $submission) {
        $candidate = trim((string) ($submission['info']['candidate_number'] ?? ''));
        if ($candidate !== '' && !isset($submissionByCandidate[$candidate])) {
            $submissionByCandidate[$candidate] = $submissionId;
        }
    }

    $orderedSubmissions = [];
    $matchedSubmissionIds = [];
    foreach ($rosterStudents as $student) {
        $candidate = trim((string) ($student['candidate_number'] ?? ''));
        if ($candidate !== '' && isset($submissionByCandidate[$candidate])) {
            $submissionId = $submissionByCandidate[$candidate];
            $orderedSubmissions[] = $submissions[$submissionId];
            $matchedSubmissionIds[$submissionId] = true;
        } else {
            $orderedSubmissions[] = [
                'info' => [
                    'id' => 0,
                    'student_first_name' => (string) $student['student_first_name'],
                    'student_last_name' => (string) $student['student_last_name'],
                    'student_name' => '',
                    'candidate_number' => (string) $student['candidate_number'],
                    'examiner_note' => null,
                    'submitted_at' => '',
                ],
                'files' => [],
            ];
        }
    }

    foreach ($submissions as $submissionId => $submission) {
        if (!isset($matchedSubmissionIds[$submissionId])) {
            $orderedSubmissions[] = $submission;
        }
    }

    $submissions = $orderedSubmissions;
}
?>
<main class="container py-4">
    <div class="mb-4 d-flex flex-column flex-md-row justify-content-between align-items-start gap-2">
        <div>
            <h1 class="h3"><?php echo e($exam['title']); ?></h1>
            <p class="text-muted">Window: <?php echo e(format_datetime_display($exam['start_time'])); ?> to <?php echo e(format_datetime_display($exam['end_time'])); ?></p>
            <p class="text-muted">Buffers: <?php echo (int) $exam['buffer_pre_minutes']; ?> mins before, <?php echo (int) $exam['buffer_post_minutes']; ?> mins after</p>
            <?php if (!empty($exam['student_roster_enabled'])): ?>
                <p class="text-muted">Student roster: <?php echo ($exam['student_roster_mode'] ?? '') === 'password' ? 'Password' : 'Menu'; ?></p>
            <?php endif; ?>
            <?php if (!empty($exam['is_completed'])): ?>
                <span class="badge text-bg-success">Completed</span>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-column flex-md-row gap-2 align-items-stretch align-items-md-center submission-actions">
            <?php if (empty($exam['is_completed'])): ?>
                <form method="post">
                    <input type="hidden" name="action" value="complete">
                    <button class="btn btn-outline-success btn-sm" type="submit">Mark Completed</button>
                </form>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="reopen">
                    <button class="btn btn-outline-primary btn-sm" type="submit">Reopen</button>
                </form>
            <?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="edit_exam.php?id=<?php echo (int) $exam['id']; ?>">Edit exam</a>
            <a class="btn btn-outline-secondary btn-sm" href="exam_students.php?id=<?php echo (int) $exam['id']; ?>">Student roster</a>
            <a class="btn btn-outline-secondary btn-sm" href="archives.php?id=<?php echo (int) $exam['id']; ?>">Archived submissions</a>
            <?php if ($hasFiles): ?>
                <a class="btn btn-outline-primary btn-sm" href="download_exam.php?exam_id=<?php echo (int) $exam['id']; ?>">Download all submissions</a>
            <?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="index.php">Back to list</a>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5">Required Documents</h2>
            <?php if (count($documents) === 0): ?>
                <p class="text-muted mb-0">No documents configured.</p>
            <?php else: ?>
                <ul class="mb-0">
                    <?php foreach ($documents as $doc): ?>
                        <li><?php echo e($doc['title']); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5">Submissions</h2>

            <?php if (count($submissions) === 0): ?>
                <p class="text-muted mb-0">No submissions yet.</p>
            <?php else: ?>
                <?php foreach ($submissions as $submission): ?>
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-2 gap-2">
                            <div>
                                <div class="fw-semibold">
                                    <?php
                                    $fullName = trim($submission['info']['student_first_name'] . ' ' . $submission['info']['student_last_name']);
                                    echo e($fullName !== '' ? $fullName : $submission['info']['student_name']);
                                    ?>
                                </div>
                                <small class="text-muted">Candidate: <?php echo e($submission['info']['candidate_number']); ?></small>
                                <?php if (!empty($submission['info']['examiner_note'])): ?>
                                    <div class="text-muted small mt-1">
                                        <strong>Note:</strong> <?php echo e($submission['info']['examiner_note']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-column flex-md-row gap-2 align-items-start align-items-md-center submission-actions">
                                <?php if (!empty($submission['info']['submitted_at'])): ?>
                                    <small class="text-muted">Submitted: <?php echo e(format_datetime_display($submission['info']['submitted_at'])); ?></small>
                                    <form method="post" onsubmit="return confirm('Reset this submission so the student can submit again? Existing files will be archived.');">
                                        <input type="hidden" name="action" value="reset_submission">
                                        <input type="hidden" name="submission_id" value="<?php echo (int) $submission['info']['id']; ?>">
                                        <button class="btn btn-outline-warning btn-sm" type="submit">Reset submission</button>
                                    </form>
                                <?php else: ?>
                                    <small class="text-muted">Not submitted yet.</small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (count($submission['files']) === 0): ?>
                            <p class="text-muted mb-0"><?php echo !empty($submission['info']['submitted_at']) ? 'No files uploaded.' : 'No submission yet.'; ?></p>
                        <?php else: ?>
                            <div class="d-flex flex-wrap gap-2">
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        Download individual files
                                    </button>
                                    <ul class="dropdown-menu">
                                        <?php foreach ($submission['files'] as $file): ?>
                                            <li>
                                                <a class="dropdown-item" href="download.php?id=<?php echo (int) $file['file_id']; ?>">
                                                    <?php echo e($file['document_title']); ?> — <?php echo e($file['original_name']); ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <a class="btn btn-outline-primary btn-sm" href="download_submission.php?submission_id=<?php echo (int) $submission['info']['id']; ?>">Download all files</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/../footer.php'; ?>
