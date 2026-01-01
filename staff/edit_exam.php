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

$stmt = db()->prepare('SELECT * FROM exam_documents WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$examId]);
$documents = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM exam_files WHERE exam_id = ? ORDER BY uploaded_at DESC, id DESC');
$stmt->execute([$examId]);
$examFiles = $stmt->fetchAll();

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
    $newExamPassword = trim((string) ($_POST['exam_password'] ?? ''));
    $clearExamPassword = isset($_POST['clear_exam_password']);

    $startDt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $startTime);
    $endDt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $endTime);

    $existingDocs = (array) ($_POST['documents'] ?? []);
    $newDocsTitle = (array) ($_POST['new_documents_title'] ?? []);
    $newDocsNote = (array) ($_POST['new_documents_note'] ?? []);
    $newDocsRequire = (array) ($_POST['new_documents_require'] ?? []);
    $newDocsTypes = (array) ($_POST['new_documents_types'] ?? []);
    $deleteDocs = (array) ($_POST['delete_documents'] ?? []);
    $deleteExamFiles = (array) ($_POST['delete_exam_files'] ?? []);
    $existingExamFiles = (array) ($_POST['exam_files'] ?? []);
    $newExamFilesTitle = (array) ($_POST['new_exam_files_title'] ?? []);
    if (is_string($deleteDocs)) {
        $deleteDocs = array_filter(explode(',', $deleteDocs), static function (string $value): bool {
            return trim($value) !== '';
        });
    }
    $forceDelete = isset($_POST['force_delete']);

    foreach ($existingDocs as $docId => $docData) {
        if (is_array($docData)) {
            $existingDocs[$docId]['title'] = trim((string) ($docData['title'] ?? ''));
            $existingDocs[$docId]['note'] = trim((string) ($docData['note'] ?? ''));
            $existingDocs[$docId]['require'] = isset($docData['require']) ? 1 : 0;
            $existingDocs[$docId]['types'] = trim((string) ($docData['types'] ?? ''));
        } else {
            $existingDocs[$docId] = [
                'title' => trim((string) $docData),
                'note' => '',
                'require' => 0,
                'types' => '',
            ];
        }
    }

    $newDocs = [];
    foreach ($newDocsTitle as $index => $titleValue) {
        $titleValue = trim((string) $titleValue);
        if ($titleValue === '') {
            continue;
        }
        $noteValue = trim((string) ($newDocsNote[$index] ?? ''));
        $requireValue = isset($newDocsRequire[$index]) ? 1 : 0;
        $typesValue = trim((string) ($newDocsTypes[$index] ?? ''));
        $newDocs[] = [
            'title' => $titleValue,
            'note' => $noteValue,
            'require' => $requireValue,
            'types' => $typesValue,
        ];
    }

    $deleteDocs = array_map('intval', $deleteDocs);
    $deleteExamFiles = array_map('intval', $deleteExamFiles);

    $existingExamFileTitles = [];
    foreach ($existingExamFiles as $fileId => $fileData) {
        $fileId = (int) $fileId;
        if ($fileId <= 0) {
            continue;
        }
        $fileTitle = is_array($fileData) ? trim((string) ($fileData['title'] ?? '')) : trim((string) $fileData);
        if (in_array($fileId, $deleteExamFiles, true)) {
            continue;
        }
        if ($fileTitle === '') {
            $errors[] = 'Exam file titles cannot be empty.';
            break;
        }
        $existingExamFileTitles[$fileId] = $fileTitle;
    }

    if ($examCode === '' || $title === '' || !$startDt || !$endDt) {
        $errors[] = 'Exam ID, title, start time, and end time are required.';
    }

    if ($startDt && $endDt && $endDt < $startDt) {
        $errors[] = 'End time must be after start time.';
    }

    $validExisting = 0;
    foreach ($existingDocs as $docId => $docData) {
        $docId = (int) $docId;
        if ((!$hasSubmissions || $forceDelete) && in_array($docId, $deleteDocs, true)) {
            continue;
        }
        if ($docData['title'] === '') {
            $errors[] = 'Document titles cannot be empty.';
            break;
        }
        if ($docData['require'] && $docData['types'] === '') {
            $errors[] = 'File types are required when file type enforcement is enabled.';
            break;
        }
        $validExisting++;
    }

    $totalDocs = $validExisting + count($newDocs);
    if ($totalDocs === 0) {
        $errors[] = 'At least one document is required.';
    }

    $newExamFilesToStore = [];
    if (count($errors) === 0 && !empty($_FILES['new_exam_files_file']) && is_array($_FILES['new_exam_files_file']['name'])) {
        foreach ($_FILES['new_exam_files_file']['name'] as $index => $name) {
            $fileTitle = trim((string) ($newExamFilesTitle[$index] ?? ''));
            $error = (int) ($_FILES['new_exam_files_file']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                if ($fileTitle !== '') {
                    $errors[] = 'Exam file title requires a file upload.';
                    break;
                }
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                $errors[] = 'Problem uploading exam files.';
                break;
            }
            if ($fileTitle === '') {
                $errors[] = 'Exam file title is required when uploading a file.';
                break;
            }
            $newExamFilesToStore[] = [
                'title' => $fileTitle,
                'original_name' => (string) $name,
                'tmp_name' => (string) ($_FILES['new_exam_files_file']['tmp_name'][$index] ?? ''),
                'size' => (int) ($_FILES['new_exam_files_file']['size'][$index] ?? 0),
            ];
        }
    }

    if (count($errors) === 0) {
        $passwordHash = $exam['access_password_hash'] ?? null;
        if ($newExamPassword !== '') {
            $passwordHash = password_hash($newExamPassword, PASSWORD_DEFAULT);
        } elseif ($clearExamPassword) {
            $passwordHash = null;
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE exams
                 SET exam_code = ?, title = ?, start_time = ?, end_time = ?, buffer_pre_minutes = ?, buffer_post_minutes = ?,
                     file_name_template = ?, folder_name_template = ?, access_password_hash = ?
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
                $passwordHash,
                $examId,
            ]);

            $order = 1;
            $updateDoc = $pdo->prepare(
                'UPDATE exam_documents SET title = ?, student_note = ?, require_file_type = ?, allowed_file_types = ?, sort_order = ? WHERE id = ?'
            );
            foreach ($existingDocs as $docId => $docData) {
                $docId = (int) $docId;
                if ((!$hasSubmissions || $forceDelete) && in_array($docId, $deleteDocs, true)) {
                    continue;
                }

                $updateDoc->execute([
                    $docData['title'],
                    $docData['note'] !== '' ? $docData['note'] : null,
                    $docData['require'],
                    $docData['types'] !== '' ? $docData['types'] : null,
                    $order,
                    $docId,
                ]);
                $order++;
            }

            if ((!$hasSubmissions || $forceDelete) && count($deleteDocs) > 0) {
                $placeholders = implode(',', array_fill(0, count($deleteDocs), '?'));
                $deleteStmt = $pdo->prepare("DELETE FROM exam_documents WHERE id IN ($placeholders)");
                $deleteStmt->execute($deleteDocs);
            }

            if (count($newDocs) > 0) {
                $insertDoc = $pdo->prepare(
                    'INSERT INTO exam_documents (exam_id, title, student_note, require_file_type, allowed_file_types, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($newDocs as $doc) {
                    $insertDoc->execute([
                        $examId,
                        $doc['title'],
                        $doc['note'] !== '' ? $doc['note'] : null,
                        $doc['require'],
                        $doc['types'] !== '' ? $doc['types'] : null,
                        $order,
                    ]);
                    $order++;
                }
            }

            $deletedFilePaths = [];
            if (count($deleteExamFiles) > 0) {
                $placeholders = implode(',', array_fill(0, count($deleteExamFiles), '?'));
                $selectStmt = $pdo->prepare("SELECT stored_path FROM exam_files WHERE exam_id = ? AND id IN ($placeholders)");
                $selectStmt->execute(array_merge([$examId], $deleteExamFiles));
                $deletedFilePaths = $selectStmt->fetchAll(PDO::FETCH_COLUMN);

                $deleteStmt = $pdo->prepare("DELETE FROM exam_files WHERE exam_id = ? AND id IN ($placeholders)");
                $deleteStmt->execute(array_merge([$examId], $deleteExamFiles));
            }

            if (count($existingExamFileTitles) > 0) {
                $updateFile = $pdo->prepare('UPDATE exam_files SET title = ? WHERE id = ? AND exam_id = ?');
                foreach ($existingExamFileTitles as $fileId => $title) {
                    $updateFile->execute([$title, $fileId, $examId]);
                }
            }

            if (count($newExamFilesToStore) > 0) {
                $examFilesDir = $uploadsDir . '/exam_' . $examId . '/exam_files';
                if (!is_dir($examFilesDir) && !mkdir($examFilesDir, 0755, true)) {
                    throw new RuntimeException('Unable to create exam files directory.');
                }
                $insertFile = $pdo->prepare(
                    'INSERT INTO exam_files (exam_id, title, original_name, stored_name, stored_path, file_size, uploaded_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($newExamFilesToStore as $file) {
                    $tmpName = $file['tmp_name'];
                    $originalName = $file['original_name'];
                    $storedName = uniqid('exam_file_', true) . '_' . sanitize_name_component($originalName);
                    $storedPath = $examFilesDir . '/' . $storedName;
                    if (!move_uploaded_file($tmpName, $storedPath)) {
                        throw new RuntimeException('Failed to store exam file.');
                    }
                    $relativePath = str_replace($uploadsDir . '/', '', $storedPath);
                    $insertFile->execute([
                        $examId,
                        $file['title'],
                        $originalName,
                        $storedName,
                        $relativePath,
                        $file['size'],
                        now_utc_string(),
                    ]);
                }
            }

            $pdo->commit();
            $success = true;

            if (count($deletedFilePaths) > 0) {
                $uploadsRoot = realpath($uploadsDir);
                foreach ($deletedFilePaths as $path) {
                    $fullPath = $uploadsDir . '/' . ltrim((string) $path, '/');
                    $realPath = realpath($fullPath);
                    if ($realPath && $uploadsRoot && strpos($realPath, $uploadsRoot) === 0 && is_file($realPath)) {
                        @unlink($realPath);
                    }
                }
            }

            $stmt = $pdo->prepare('SELECT * FROM exams WHERE id = ?');
            $stmt->execute([$examId]);
            $exam = $stmt->fetch();

            $stmt = $pdo->prepare('SELECT * FROM exam_documents WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
            $stmt->execute([$examId]);
            $documents = $stmt->fetchAll();

            $stmt = $pdo->prepare('SELECT * FROM exam_files WHERE exam_id = ? ORDER BY uploaded_at DESC, id DESC');
            $stmt->execute([$examId]);
            $examFiles = $stmt->fetchAll();
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
$pageTitle = 'Edit Exam - ' . $exam['title'];
$brandHref = 'index.php';
$brandText = 'Exams Administration Portal';
$logoPath = '../logo.png';
$cssPath = '../style.css';
$navActions = '<a class="btn btn-outline-secondary btn-sm" href="../index.php">Student View</a>'
    . '<a class="btn btn-outline-secondary btn-sm" href="exam.php?id=' . (int) $exam['id'] . '">Back to exam</a>'
    . '<a class="btn btn-outline-secondary btn-sm" href="../auth/logout.php">Logout</a>';
require __DIR__ . '/../header.php';
?>
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

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="delete_confirmed" id="delete-confirmed" value="0">
                <input type="hidden" name="delete_documents" id="delete-documents" value="">
                <div class="mt-2 pb-4 border-bottom">
                    <h2 class="h6 text-uppercase fw-bold mb-2">Exam Details</h2>
                    <div class="mb-3">
                        <label class="form-label">Exam ID</label>
                        <input class="form-control" type="text" name="exam_code" value="<?php echo e((string) $exam['exam_code']); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Exam Title</label>
                        <input class="form-control" type="text" name="title" value="<?php echo e((string) $exam['title']); ?>" required>
                    </div>
                </div>

                <div class="mt-4 pb-4 border-bottom">
                    <h2 class="h6 text-uppercase fw-bold mb-2">Schedule & Buffers</h2>
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
                </div>

                <div class="card border border-primary-subtle shadow-sm bg-primary-subtle mt-4 mb-4">
                    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                        <div>
                            <h2 class="h5 mb-1">Student roster</h2>
                            <p class="text-muted mb-0">Manage the optional student roster and access mode for this exam.</p>
                        </div>
                        <a class="btn btn-outline-primary btn-sm" href="exam_students.php?id=<?php echo (int) $exam['id']; ?>">Open student roster</a>
                    </div>
                </div>

                <div class="mt-4 pb-4 border-bottom">
                    <h2 class="h6 text-uppercase fw-bold mb-2">Required Documents</h2>
                    <div id="document-list" class="d-grid gap-2">
                        <?php foreach ($documents as $doc): ?>
                            <div class="border rounded p-3">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label">Document title</label>
                                        <input class="form-control" type="text" name="documents[<?php echo (int) $doc['id']; ?>][title]" value="<?php echo e($doc['title']); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Student note</label>
                                        <input class="form-control" type="text" name="documents[<?php echo (int) $doc['id']; ?>][note]" value="<?php echo e((string) ($doc['student_note'] ?? '')); ?>" placeholder="Optional note for students">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Allowed file types</label>
                                        <input class="form-control" type="text" name="documents[<?php echo (int) $doc['id']; ?>][types]" value="<?php echo e((string) ($doc['allowed_file_types'] ?? '')); ?>" placeholder="pdf, docx">
                                        <div class="form-text">Comma-separated extensions.</div>
                                    </div>
                                    <div class="col-12 d-flex flex-wrap gap-3 align-items-center">
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" name="documents[<?php echo (int) $doc['id']; ?>][require]" value="1" id="require-existing-<?php echo (int) $doc['id']; ?>" <?php echo !empty($doc['require_file_type']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="require-existing-<?php echo (int) $doc['id']; ?>">Require these file types</label>
                                        </div>
                                        <button class="btn btn-outline-danger btn-sm ms-auto delete-doc-btn" type="button" data-doc-id="<?php echo (int) $doc['id']; ?>">Delete document</button>
                                    </div>
                                </div>
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
                    <h2 class="h6 text-uppercase fw-bold mb-2">Add New Documents</h2>
                    <div id="new-document-list" class="d-grid gap-2">
                        <div class="border rounded p-3">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label">Document title</label>
                                    <input class="form-control" type="text" name="new_documents_title[]" placeholder="Activity">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Student note</label>
                                    <input class="form-control" type="text" name="new_documents_note[]" placeholder="Make sure to convert to PDF first">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Allowed file types</label>
                                    <input class="form-control" type="text" name="new_documents_types[]" placeholder="pdf, docx">
                                    <div class="form-text">Comma-separated extensions.</div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" name="new_documents_require[0]" value="1" id="require-new-0">
                                        <label class="form-check-label" for="require-new-0">Require these file types</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm mt-2" type="button" id="add-document">Add another document</button>
                </div>

                <div class="mt-4 pb-4 border-bottom">
                    <h2 class="h6 text-uppercase fw-bold mb-2">Submission Naming</h2>
                    <label class="form-label">Submitted Document Naming Convention</label>
                    <input class="form-control" type="text" name="file_name_template" id="edit-file-template" value="<?php echo e((string) ($exam['file_name_template'] ?? '')); ?>" placeholder="{candidate_number}_{document_title}_{original_name}">
                    <div class="form-text">Example: {candidate_number}_{document_title}_{original_name}</div>
                    <div class="form-text d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{exam_id}">{exam_id}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{exam_title}">{exam_title}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{student_firstname}">{student_firstname}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{student_surname}">{student_surname}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{student_firstname_initial}">{student_firstname_initial}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{student_surname_initial}">{student_surname_initial}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{candidate_number}">{candidate_number}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{document_title}">{document_title}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{original_name}">{original_name}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-file-template" data-token="{submission_id}">{submission_id}</button>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label">Folder Naming Convention</label>
                    <input class="form-control" type="text" name="folder_name_template" id="edit-folder-template" value="<?php echo e((string) ($exam['folder_name_template'] ?? '')); ?>" placeholder="{candidate_number}_{student_surname}">
                    <div class="form-text">Example: {candidate_number}_{student_surname}</div>
                    <div class="form-text d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-folder-template" data-token="{exam_id}">{exam_id}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-folder-template" data-token="{exam_title}">{exam_title}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-folder-template" data-token="{student_firstname}">{student_firstname}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-folder-template" data-token="{student_surname}">{student_surname}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-folder-template" data-token="{student_firstname_initial}">{student_firstname_initial}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-folder-template" data-token="{student_surname_initial}">{student_surname_initial}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-folder-template" data-token="{candidate_number}">{candidate_number}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="edit-folder-template" data-token="{submission_id}">{submission_id}</button>
                    </div>
                </div>

                <div class="mt-4 pb-4 border-bottom">
                    <h2 class="h6 text-uppercase fw-bold mb-2">Exam Materials</h2>
                    <?php if (count($examFiles) === 0): ?>
                        <p class="text-muted mb-2">No exam materials uploaded.</p>
                    <?php else: ?>
                        <div class="table-responsive mb-2">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Material file</th>
                                        <th>Size</th>
                                        <th>Delete</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($examFiles as $file): ?>
                                        <tr>
                                            <td>
                                                <input class="form-control form-control-sm" type="text" name="exam_files[<?php echo (int) $file['id']; ?>][title]" value="<?php echo e($file['title'] !== '' ? $file['title'] : $file['original_name']); ?>" required>
                                            </td>
                                            <td class="text-muted"><?php echo e($file['original_name']); ?></td>
                                            <td class="text-muted"><?php echo e(format_bytes((int) $file['file_size'])); ?></td>
                                            <td>
                                                <input class="form-check-input" type="checkbox" name="delete_exam_files[]" value="<?php echo (int) $file['id']; ?>">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div class="mt-3">
                        <h3 class="h6 text-uppercase fw-bold mb-2">Add New Exam Materials</h3>
                        <div id="new-exam-files-list" class="d-grid gap-2">
                            <div class="border rounded p-3">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Material title</label>
                                        <input class="form-control" type="text" name="new_exam_files_title[]" placeholder="Exam Paper 1">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Material file</label>
                                        <input class="form-control" type="file" name="new_exam_files_file[]">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button class="btn btn-outline-secondary btn-sm mt-2" type="button" id="add-exam-file">Add another material</button>
                        <div class="form-text">Students will see the title instead of the filename.</div>
                    </div>
                </div>

                <div class="mt-4">
                    <h2 class="h6 text-uppercase fw-bold mb-2">Access Settings</h2>
                    <label class="form-label">Exam Access Password</label>
                    <input class="form-control" type="password" name="exam_password" autocomplete="new-password" placeholder="Set a new password">
                    <div class="form-text">
                        <?php echo !empty($exam['access_password_hash']) ? 'A password is currently set for this exam.' : 'No password is currently set.'; ?>
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="clear_exam_password" value="1" id="clear-exam-password">
                        <label class="form-check-label" for="clear-exam-password">Clear existing password</label>
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
    const deleteDocumentsInput = document.getElementById('delete-documents');
    const forceDelete = document.getElementById('force-delete');
    const confirmDeleteDocs = document.getElementById('confirmDeleteDocs');
    const deleteConfirmModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    const deleteDocs = new Set();
    const newExamFileList = document.getElementById('new-exam-files-list');
    const addExamFileButton = document.getElementById('add-exam-file');

    let newDocIndex = 1;

    addButton.addEventListener('click', () => {
        const wrapper = document.createElement('div');
        wrapper.className = 'border rounded p-3';
        wrapper.innerHTML = `
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label">Document title</label>
                    <input class="form-control" type="text" name="new_documents_title[]" placeholder="Activity">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Student note</label>
                    <input class="form-control" type="text" name="new_documents_note[]" placeholder="Make sure to convert to PDF first">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Allowed file types</label>
                    <input class="form-control" type="text" name="new_documents_types[]" placeholder="pdf, docx">
                    <div class="form-text">Comma-separated extensions.</div>
                </div>
                <div class="col-12">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="new_documents_require[${newDocIndex}]" value="1" id="require-new-${newDocIndex}">
                        <label class="form-check-label" for="require-new-${newDocIndex}">Require these file types</label>
                    </div>
                </div>
            </div>
        `;
        documentList.appendChild(wrapper);
        newDocIndex += 1;
    });

    if (addExamFileButton && newExamFileList) {
        addExamFileButton.addEventListener('click', () => {
            const wrapper = document.createElement('div');
            wrapper.className = 'border rounded p-3';
            wrapper.innerHTML = `
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">Material title</label>
                        <input class="form-control" type="text" name="new_exam_files_title[]" placeholder="Exam Paper 1">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Material file</label>
                        <input class="form-control" type="file" name="new_exam_files_file[]">
                    </div>
                </div>
            `;
            newExamFileList.appendChild(wrapper);
        });
    }

    document.querySelectorAll('.delete-doc-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const docId = button.dataset.docId;
            if (!docId) {
                return;
            }
            deleteDocs.add(docId);
            button.closest('.border')?.classList.add('opacity-50');
            button.disabled = true;
            button.textContent = 'Marked for deletion';
        });
    });

    form.addEventListener('submit', (event) => {
        if (deleteDocumentsInput) {
            deleteDocumentsInput.value = Array.from(deleteDocs).join(',');
        }

        if (!forceDelete || !forceDelete.checked) {
            return;
        }

        if (deleteConfirmed.value === '1') {
            return;
        }

        if (deleteDocs.size === 0) {
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
<?php require __DIR__ . '/../footer.php'; ?>
