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

$stmt = db()->prepare('SELECT * FROM exam_documents WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$examId]);
$documents = $stmt->fetchAll();

$stmt = db()->prepare('SELECT COUNT(*) FROM submissions WHERE exam_id = ?');
$stmt->execute([$examId]);
$hasSubmissions = ((int) $stmt->fetchColumn()) > 0;

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $examCode = trim((string) ($_POST['exam_code'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $startTime = trim((string) ($_POST['start_time'] ?? ''));
    $endTime = trim((string) ($_POST['end_time'] ?? ''));
    $bufferPre = (int) ($_POST['buffer_pre_minutes'] ?? 0);
    $bufferPost = (int) ($_POST['buffer_post_minutes'] ?? 0);
    $fileNameTemplate = trim((string) ($_POST['file_name_template'] ?? ''));
    $folderNameTemplate = trim((string) ($_POST['folder_name_template'] ?? ''));

    $startDt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $startTime);
    $endDt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $endTime);

    $existingDocs = (array) ($_POST['documents'] ?? []);
    $newDocs = (array) ($_POST['new_documents'] ?? []);
    $deleteDocs = (array) ($_POST['delete_documents'] ?? []);
    $forceDelete = isset($_POST['force_delete']);

    $existingDocs = array_map('trim', $existingDocs);
    $newDocs = array_values(array_filter(array_map('trim', $newDocs)));
    $deleteDocs = array_map('intval', $deleteDocs);

    if ($examCode === '' || $title === '' || !$startDt || !$endDt) {
        $errors[] = 'Exam ID, title, start time, and end time are required.';
    }

    if ($startDt && $endDt && $endDt < $startDt) {
        $errors[] = 'End time must be after start time.';
    }

    $validExisting = 0;
    foreach ($existingDocs as $docId => $docTitle) {
        $docId = (int) $docId;
        if ((!$hasSubmissions || $forceDelete) && in_array($docId, $deleteDocs, true)) {
            continue;
        }
        if ($docTitle === '') {
            $errors[] = 'Document titles cannot be empty.';
            break;
        }
        $validExisting++;
    }

    $totalDocs = $validExisting + count($newDocs);
    if ($totalDocs === 0) {
        $errors[] = 'At least one document is required.';
    }

    if (count($errors) === 0) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE exams
                 SET exam_code = ?, title = ?, start_time = ?, end_time = ?, buffer_pre_minutes = ?, buffer_post_minutes = ?,
                     file_name_template = ?, folder_name_template = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $examCode,
                $title,
                $startDt->format('Y-m-d H:i:s'),
                $endDt->format('Y-m-d H:i:s'),
                $bufferPre,
                $bufferPost,
                $fileNameTemplate !== '' ? $fileNameTemplate : null,
                $folderNameTemplate !== '' ? $folderNameTemplate : null,
                $examId,
            ]);

            $order = 1;
            $updateDoc = $pdo->prepare('UPDATE exam_documents SET title = ?, sort_order = ? WHERE id = ?');
            foreach ($existingDocs as $docId => $docTitle) {
                $docId = (int) $docId;
                if ((!$hasSubmissions || $forceDelete) && in_array($docId, $deleteDocs, true)) {
                    continue;
                }

                $updateDoc->execute([$docTitle, $order, $docId]);
                $order++;
            }

            if ((!$hasSubmissions || $forceDelete) && count($deleteDocs) > 0) {
                $placeholders = implode(',', array_fill(0, count($deleteDocs), '?'));
                $deleteStmt = $pdo->prepare("DELETE FROM exam_documents WHERE id IN ($placeholders)");
                $deleteStmt->execute($deleteDocs);
            }

            if (count($newDocs) > 0) {
                $insertDoc = $pdo->prepare('INSERT INTO exam_documents (exam_id, title, sort_order) VALUES (?, ?, ?)');
                foreach ($newDocs as $docTitle) {
                    $insertDoc->execute([$examId, $docTitle, $order]);
                    $order++;
                }
            }

            $pdo->commit();
            $success = true;

            $stmt = $pdo->prepare('SELECT * FROM exams WHERE id = ?');
            $stmt->execute([$examId]);
            $exam = $stmt->fetch();

            $stmt = $pdo->prepare('SELECT * FROM exam_documents WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
            $stmt->execute([$examId]);
            $documents = $stmt->fetchAll();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Failed to update exam.';
        }
    }
}

$startValue = '';
$endValue = '';
try {
    $startValue = (new DateTimeImmutable($exam['start_time']))->format('Y-m-d\TH:i');
    $endValue = (new DateTimeImmutable($exam['end_time']))->format('Y-m-d\TH:i');
} catch (Throwable $e) {
    $startValue = '';
    $endValue = '';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Exam - <?php echo e($exam['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg bg-white border-bottom">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="index.php">Staff</a>
        <a class="btn btn-outline-secondary btn-sm" href="exam.php?id=<?php echo (int) $exam['id']; ?>">Back to exam</a>
    </div>
</nav>

<main class="container py-4">
    <div class="card shadow-sm">
        <div class="card-body">
            <h1 class="h4 mb-3">Edit Exam</h1>
            <p class="text-muted">Update exam details, required documents, and naming templates.</p>

            <?php if ($success): ?>
                <div class="alert alert-success">Exam updated.</div>
            <?php endif; ?>

            <?php if (count($errors) > 0): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo e($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="delete_confirmed" id="delete-confirmed" value="0">
                <div class="mb-3">
                    <label class="form-label">Exam ID</label>
                    <input class="form-control" type="text" name="exam_code" value="<?php echo e((string) $exam['exam_code']); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Exam Title</label>
                    <input class="form-control" type="text" name="title" value="<?php echo e((string) $exam['title']); ?>" required>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Start Time</label>
                        <input class="form-control" type="datetime-local" name="start_time" value="<?php echo e($startValue); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">End Time</label>
                        <input class="form-control" type="datetime-local" name="end_time" value="<?php echo e($endValue); ?>" required>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label">Pre-Buffer (minutes)</label>
                        <input class="form-control" type="number" name="buffer_pre_minutes" min="0" value="<?php echo (int) $exam['buffer_pre_minutes']; ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Post-Buffer (minutes)</label>
                        <input class="form-control" type="number" name="buffer_post_minutes" min="0" value="<?php echo (int) $exam['buffer_post_minutes']; ?>">
                    </div>
                </div>

                <div class="mt-4">
                    <label class="form-label">Required Documents</label>
                    <div id="document-list" class="d-grid gap-2">
                        <?php foreach ($documents as $doc): ?>
                            <div class="input-group">
                                <input class="form-control" type="text" name="documents[<?php echo (int) $doc['id']; ?>]" value="<?php echo e($doc['title']); ?>" required>
                                <span class="input-group-text">
                                    <input class="form-check-input mt-0" type="checkbox" name="delete_documents[]" value="<?php echo (int) $doc['id']; ?>" aria-label="Delete document">
                                    <span class="ms-2">Delete</span>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($hasSubmissions): ?>
                        <div class="alert alert-warning mt-2">
                            <strong>Warning:</strong> This exam already has submissions. Deleting a document will also delete any uploaded files tied to it.
                            Check the confirmation box below to allow deletions.
                        </div>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="force_delete" value="1" id="force-delete">
                            <label class="form-check-label" for="force-delete">
                                I understand this will permanently remove submitted files for deleted documents.
                            </label>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mt-3">
                    <label class="form-label">Add new documents</label>
                    <div id="new-document-list" class="d-grid gap-2">
                        <input class="form-control" type="text" name="new_documents[]" placeholder="Activity">
                    </div>
                    <button class="btn btn-outline-secondary btn-sm mt-2" type="button" id="add-document">Add another document</button>
                </div>

                <div class="mt-4">
                    <label class="form-label">File name template</label>
                    <input class="form-control" type="text" name="file_name_template" id="edit-file-template" value="<?php echo e((string) ($exam['file_name_template'] ?? '')); ?>" placeholder="{candidate_number}_{document_title}_{original_name}">
                    <div class="form-text d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{exam_id}">{exam_id}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{exam_title}">{exam_title}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{student_name}">{student_name}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{candidate_number}">{candidate_number}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{document_title}">{document_title}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{original_name}">{original_name}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{submission_id}">{submission_id}</button>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label">Folder name template</label>
                    <input class="form-control" type="text" name="folder_name_template" id="edit-folder-template" value="<?php echo e((string) ($exam['folder_name_template'] ?? '')); ?>" placeholder="{candidate_number}_{student_name}">
                    <div class="form-text d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-folder-template" data-token="{exam_id}">{exam_id}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-folder-template" data-token="{exam_title}">{exam_title}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-folder-template" data-token="{student_name}">{student_name}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-folder-template" data-token="{candidate_number}">{candidate_number}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-folder-template" data-token="{submission_id}">{submission_id}</button>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button class="btn btn-primary" type="submit">Save changes</button>
                    <a class="btn btn-outline-secondary" href="exam.php?id=<?php echo (int) $exam['id']; ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</main>

<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteConfirmLabel">Confirm deletions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><strong>Warning:</strong> You are about to delete document slots that already have submitted files.</p>
                <p class="mb-0">These files will be permanently removed and cannot be recovered.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteDocs">Yes, delete files</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const addButton = document.getElementById('add-document');
    const documentList = document.getElementById('new-document-list');
    const form = document.querySelector('form');
    const deleteConfirmed = document.getElementById('delete-confirmed');
    const forceDelete = document.getElementById('force-delete');
    const confirmDeleteDocs = document.getElementById('confirmDeleteDocs');
    const deleteConfirmModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));

    addButton.addEventListener('click', () => {
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'new_documents[]';
        input.placeholder = 'Activity';
        input.className = 'form-control';
        documentList.appendChild(input);
    });

    form.addEventListener('submit', (event) => {
        if (!forceDelete || !forceDelete.checked) {
            return;
        }

        if (deleteConfirmed.value === '1') {
            return;
        }

        const deletions = form.querySelectorAll('input[name="delete_documents[]"]:checked');
        if (deletions.length === 0) {
            return;
        }

        event.preventDefault();
        deleteConfirmModal.show();
    });

    confirmDeleteDocs.addEventListener('click', () => {
        deleteConfirmed.value = '1';
        deleteConfirmModal.hide();
        form.submit();
    });

    document.querySelectorAll('.token-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const targetId = button.dataset.target;
            const token = button.dataset.token || '';
            const input = document.getElementById(targetId);
            if (!input) {
                return;
            }
            input.value = input.value + token;
            input.focus();
        });
    });
</script>
</body>
</html>
