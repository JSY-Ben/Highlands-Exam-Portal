<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $examId = (int) ($_POST['exam_id'] ?? 0);
        if ($examId > 0) {
            $stmt = db()->prepare('DELETE FROM exams WHERE id = ?');
            $stmt->execute([$examId]);
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'complete') {
        $examId = (int) ($_POST['exam_id'] ?? 0);
        if ($examId > 0) {
            $stmt = db()->prepare('UPDATE exams SET is_completed = 1, completed_at = ? WHERE id = ?');
            $stmt->execute([now_utc_string(), $examId]);
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'reopen') {
        $examId = (int) ($_POST['exam_id'] ?? 0);
        if ($examId > 0) {
            $stmt = db()->prepare('UPDATE exams SET is_completed = 0, completed_at = NULL WHERE id = ?');
            $stmt->execute([$examId]);
        }
        header('Location: index.php');
        exit;
    }

    $examCode = trim((string) ($_POST['exam_code'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $startTime = trim((string) ($_POST['start_time'] ?? ''));
    $endTime = trim((string) ($_POST['end_time'] ?? ''));
    $bufferPre = (int) ($_POST['buffer_pre_minutes'] ?? 0);
    $bufferPost = (int) ($_POST['buffer_post_minutes'] ?? 0);
    $documents = $_POST['documents'] ?? [];
    $fileNameTemplate = trim((string) ($_POST['file_name_template'] ?? ''));
    $folderNameTemplate = trim((string) ($_POST['folder_name_template'] ?? ''));

    $documents = array_values(array_filter(array_map('trim', (array) $documents)));

    if ($examCode === '' || $title === '' || $startTime === '' || $endTime === '') {
        $errors[] = 'Exam ID, title, start time, and end time are required.';
    }

    $startDt = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $startTime);
    $endDt = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $endTime);

    if (!$startDt || !$endDt) {
        $errors[] = 'Invalid start or end time.';
    }

    if (count($documents) === 0) {
        $errors[] = 'At least one document is required.';
    }

    if (count($errors) === 0) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO exams (exam_code, title, start_time, end_time, buffer_pre_minutes, buffer_post_minutes, file_name_template, folder_name_template, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
                now_utc_string(),
            ]);

            $examId = (int) $pdo->lastInsertId();

            $insertDoc = $pdo->prepare('INSERT INTO exam_documents (exam_id, title, sort_order) VALUES (?, ?, ?)');
            foreach ($documents as $index => $docTitle) {
                $insertDoc->execute([$examId, $docTitle, $index + 1]);
            }

            $pdo->commit();
            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Failed to create exam.';
        }
    }
}

$stmt = db()->query('SELECT * FROM exams ORDER BY start_time DESC');
$exams = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff - Exam Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/flatly/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg bg-white border-bottom">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="../index.php">Exam Submission Portal</a>
        <span class="navbar-text">Staff</span>
    </div>
</nav>

<main class="container py-4">
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h1 class="h4">Create Exam</h1>

                    <?php if (count($errors) > 0): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($errors as $error): ?>
                                <div><?php echo e($error); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">Exam ID</label>
                            <input class="form-control" type="text" name="exam_code" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Exam Title</label>
                            <input class="form-control" type="text" name="title" required>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Start Date</label>
                                <input class="form-control" type="date" id="start-date" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Start Time</label>
                                <div class="input-group">
                                    <input class="form-control" type="text" id="start-time" placeholder="hh:mm" required>
                                    <select class="form-select" id="start-ampm">
                                        <option value="AM">AM</option>
                                        <option value="PM">PM</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label">End Date</label>
                                <input class="form-control" type="date" id="end-date" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Time</label>
                                <div class="input-group">
                                    <input class="form-control" type="text" id="end-time" placeholder="hh:mm" required>
                                    <select class="form-select" id="end-ampm">
                                        <option value="AM">AM</option>
                                        <option value="PM">PM</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="start_time" id="start-time-hidden" required>
                        <input type="hidden" name="end_time" id="end-time-hidden" required>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label">Pre-Buffer (minutes)</label>
                                <input class="form-control" type="number" name="buffer_pre_minutes" min="0" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Post-Buffer (minutes)</label>
                                <input class="form-control" type="number" name="buffer_post_minutes" min="0" value="0">
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">Required Documents</label>
                            <div id="document-list" class="d-grid gap-2">
                                <input class="form-control" type="text" name="documents[]" placeholder="Activity 1" required>
                            </div>
                            <button class="btn btn-outline-secondary btn-sm mt-2" type="button" id="add-document">Add another document</button>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">File name template</label>
                            <input class="form-control" type="text" name="file_name_template" id="create-file-template" placeholder="{candidate_number}_{document_title}_{original_name}">
                            <div class="form-text d-flex flex-wrap gap-2">
                                <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{exam_id}">{exam_id}</button>
                                <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{exam_title}">{exam_title}</button>
                                <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{student_name}">{student_name}</button>
                                <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{candidate_number}">{candidate_number}</button>
                                <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{document_title}">{document_title}</button>
                                <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{original_name}">{original_name}</button>
                                <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{submission_id}">{submission_id}</button>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">Folder name template</label>
                            <input class="form-control" type="text" name="folder_name_template" id="create-folder-template" placeholder="{candidate_number}_{student_name}">
                            <div class="form-text d-flex flex-wrap gap-2">
                                <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-folder-template" data-token="{exam_id}">{exam_id}</button>
                                <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-folder-template" data-token="{exam_title}">{exam_title}</button>
                                <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-folder-template" data-token="{student_name}">{student_name}</button>
                                <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-folder-template" data-token="{candidate_number}">{candidate_number}</button>
                                <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-folder-template" data-token="{submission_id}">{submission_id}</button>
                            </div>
                        </div>

                        <button class="btn btn-primary mt-3" type="submit">Create Exam</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h4">Existing Exams</h2>

                    <?php if (count($exams) === 0): ?>
                        <p class="text-muted mb-0">No exams created yet.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($exams as $exam): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-semibold"><?php echo e($exam['title']); ?></div>
                                            <small class="text-muted"><?php echo e(format_datetime_display($exam['start_time'])); ?> to <?php echo e(format_datetime_display($exam['end_time'])); ?></small>
                                            <?php if (!empty($exam['is_completed'])): ?>
                                                <span class="badge text-bg-success ms-2">Completed</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <a class="btn btn-outline-secondary btn-sm" href="exam.php?id=<?php echo (int) $exam['id']; ?>">View</a>
                                            <?php if (empty($exam['is_completed'])): ?>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="complete">
                                                    <input type="hidden" name="exam_id" value="<?php echo (int) $exam['id']; ?>">
                                                    <button class="btn btn-outline-success btn-sm" type="submit">Mark Completed</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="reopen">
                                                    <input type="hidden" name="exam_id" value="<?php echo (int) $exam['id']; ?>">
                                                    <button class="btn btn-outline-primary btn-sm" type="submit">Reopen</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="post" onsubmit="return confirm('Delete this exam and all submissions?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="exam_id" value="<?php echo (int) $exam['id']; ?>">
                                                <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    const addButton = document.getElementById('add-document');
    const documentList = document.getElementById('document-list');
    const form = document.querySelector('form');
    const startDate = document.getElementById('start-date');
    const startTime = document.getElementById('start-time');
    const startAmpm = document.getElementById('start-ampm');
    const endDate = document.getElementById('end-date');
    const endTime = document.getElementById('end-time');
    const endAmpm = document.getElementById('end-ampm');
    const startHidden = document.getElementById('start-time-hidden');
    const endHidden = document.getElementById('end-time-hidden');

    addButton.addEventListener('click', () => {
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'documents[]';
        input.placeholder = 'Activity';
        input.className = 'form-control';
        documentList.appendChild(input);
    });

    const to24Hour = (value, period) => {
        const parts = value.split(':');
        if (parts.length !== 2) {
            return null;
        }
        let hours = parseInt(parts[0], 10);
        const minutes = parseInt(parts[1], 10);
        if (Number.isNaN(hours) || Number.isNaN(minutes)) {
            return null;
        }
        if (hours < 1 || hours > 12 || minutes < 0 || minutes > 59) {
            return null;
        }
        if (period === 'AM') {
            hours = hours === 12 ? 0 : hours;
        } else {
            hours = hours === 12 ? 12 : hours + 12;
        }
        return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
    };

    form.addEventListener('submit', (event) => {
        const start = to24Hour(startTime.value.trim(), startAmpm.value);
        const end = to24Hour(endTime.value.trim(), endAmpm.value);

        if (!startDate.value || !endDate.value || !start || !end) {
            event.preventDefault();
            alert('Please enter valid start and end dates and times (hh:mm AM/PM).');
            return;
        }

        startHidden.value = `${startDate.value}T${start}`;
        endHidden.value = `${endDate.value}T${end}`;
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
