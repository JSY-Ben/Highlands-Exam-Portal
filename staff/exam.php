<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers.php';

$examId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM exams WHERE id = ?');
$stmt->execute([$examId]);
$exam = $stmt->fetch();

if (!$exam) {
    http_response_code(404);
    echo 'Exam not found.';
    exit;
}

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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Exam - <?php echo e($exam['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/flatly/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg bg-white border-bottom">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="index.php">Staff</a>
        <a class="btn btn-outline-secondary btn-sm" href="../index.php">Student View</a>
    </div>
</nav>

<main class="container py-4">
    <div class="mb-4 d-flex justify-content-between align-items-start">
        <div>
            <h1 class="h3"><?php echo e($exam['title']); ?></h1>
            <p class="text-muted">Window: <?php echo e(format_datetime_display($exam['start_time'])); ?> to <?php echo e(format_datetime_display($exam['end_time'])); ?></p>
            <p class="text-muted">Buffers: <?php echo (int) $exam['buffer_pre_minutes']; ?> mins before, <?php echo (int) $exam['buffer_post_minutes']; ?> mins after</p>
            <?php if (!empty($exam['is_completed'])): ?>
                <span class="badge text-bg-success">Completed</span>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
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
            <a class="btn btn-outline-secondary btn-sm" href="edit_exam.php?id=<?php echo (int) $exam['id']; ?>">Edit templates</a>
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
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <div class="fw-semibold"><?php echo e($submission['info']['student_name']); ?></div>
                                <small class="text-muted">Candidate: <?php echo e($submission['info']['candidate_number']); ?></small>
                            </div>
                            <small class="text-muted">Submitted: <?php echo e(format_datetime_display($submission['info']['submitted_at'])); ?></small>
                        </div>
                        <?php if (count($submission['files']) === 0): ?>
                            <p class="text-muted mb-0">No files uploaded.</p>
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
</body>
</html>
