<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$examId = (int) ($_GET['id'] ?? 0);
$now = new DateTimeImmutable('now');

$stmt = db()->prepare('SELECT * FROM exams WHERE id = ?');
$stmt->execute([$examId]);
$exam = $stmt->fetch();

if (!$exam || !exam_is_active($exam, $now)) {
    if (!$exam) {
        http_response_code(404);
        echo 'Exam not found.';
        exit;
    }

    $statusMessage = !empty($exam['is_completed'])
        ? 'This exam has been marked as completed and is no longer accepting submissions.'
        : 'This exam is not currently accepting submissions.';
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Exam Unavailable</title>
        <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/flatly/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
    <nav class="navbar navbar-expand-lg bg-white border-bottom">
        <div class="container">
            <a class="navbar-brand fw-semibold" href="index.php">Exam Submission Portal</a>
        </div>
    </nav>

    <main class="container py-5">
        <div class="alert alert-warning shadow-sm">
            <h1 class="h5 mb-2">Exam unavailable</h1>
            <p class="mb-3"><?php echo e($statusMessage); ?></p>
            <a class="btn btn-outline-secondary btn-sm" href="index.php">Back to active exams</a>
        </div>
    </main>
    </body>
    </html>
    <?php
    exit;
}

$stmt = db()->prepare('SELECT * FROM exam_documents WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$examId]);
$documents = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Submit Files - <?php echo e($exam['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/flatly/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg bg-white border-bottom">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="index.php">Exam Submission Portal</a>
    </div>
</nav>

<main class="container py-4">
    <div class="mb-4">
        <h1 class="h3">Submit for <?php echo e($exam['title']); ?></h1>
        <p class="text-muted">Upload all required files before the submission window ends.</p>
    </div>

    <form class="card shadow-sm" action="submit.php" method="post" enctype="multipart/form-data">
        <div class="card-body">
            <input type="hidden" name="exam_id" value="<?php echo (int) $exam['id']; ?>">

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Student Name</label>
                    <input class="form-control" type="text" name="student_name" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Candidate Number</label>
                    <input class="form-control" type="text" name="candidate_number" required>
                </div>
            </div>

            <div class="mb-3">
                <h2 class="h6">Required Documents</h2>
                <div class="row g-3">
                    <?php foreach ($documents as $doc): ?>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo e($doc['title']); ?></label>
                            <input class="form-control" type="file" name="file_<?php echo (int) $doc['id']; ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="confirm_final" value="1" required>
                <label class="form-check-label">I confirm this is my final submission.</label>
            </div>
            <input type="hidden" name="missing_confirmed" id="missing-confirmed" value="0">

            <button class="btn btn-primary" type="submit">Submit Files</button>
        </div>
    </form>
</main>

<div class="modal fade" id="missingFilesModal" tabindex="-1" aria-labelledby="missingFilesLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="missingFilesLabel">Files missing</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>You have not selected files for the following documents:</p>
                <ul id="missingFilesList" class="mb-3"></ul>
                <p class="mb-0">If you continue, you will not be able to submit again.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Review files</button>
                <button type="button" class="btn btn-primary" id="confirmMissingFiles">Submit anyway</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const form = document.querySelector('form');
    const missingConfirmed = document.getElementById('missing-confirmed');
    const missingFilesModal = new bootstrap.Modal(document.getElementById('missingFilesModal'));
    const missingFilesList = document.getElementById('missingFilesList');
    const confirmMissingFiles = document.getElementById('confirmMissingFiles');
    let pendingSubmit = false;

    form.addEventListener('submit', (event) => {
        if (pendingSubmit) {
            return;
        }

        const fileInputs = Array.from(form.querySelectorAll('input[type="file"]'));
        const missing = fileInputs.filter((input) => !input.files || input.files.length === 0);

        if (missing.length > 0) {
            event.preventDefault();
            missingFilesList.innerHTML = '';

            missing.forEach((input) => {
                const label = input.closest('div')?.querySelector('label');
                const item = document.createElement('li');
                item.textContent = label ? label.textContent.trim() : 'Document';
                missingFilesList.appendChild(item);
            });

            missingFilesModal.show();
        }
    });

    confirmMissingFiles.addEventListener('click', () => {
        missingConfirmed.value = '1';
        pendingSubmit = true;
        missingFilesModal.hide();
        form.submit();
    });
</script>
</body>
</html>
