<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $examCode = trim((string) ($_POST['exam_code'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $startTime = trim((string) ($_POST['start_time'] ?? ''));
    $endTime = trim((string) ($_POST['end_time'] ?? ''));
    $bufferPre = (int) ($_POST['buffer_pre_minutes'] ?? 0);
    $bufferPost = (int) ($_POST['buffer_post_minutes'] ?? 0);
    $documentsTitle = $_POST['documents_title'] ?? [];
    $documentsNote = $_POST['documents_note'] ?? [];
    $documentsRequire = $_POST['documents_require'] ?? [];
    $documentsTypes = $_POST['documents_types'] ?? [];
    $fileNameTemplate = trim((string) ($_POST['file_name_template'] ?? ''));
    $folderNameTemplate = trim((string) ($_POST['folder_name_template'] ?? ''));

    $documents = [];
    foreach ((array) $documentsTitle as $index => $titleValue) {
        $titleValue = trim((string) $titleValue);
        if ($titleValue === '') {
            continue;
        }
        $noteValue = trim((string) ($documentsNote[$index] ?? ''));
        $requireValue = isset($documentsRequire[$index]) ? 1 : 0;
        $typesValue = trim((string) ($documentsTypes[$index] ?? ''));
        $documents[] = [
            'title' => $titleValue,
            'note' => $noteValue !== '' ? $noteValue : null,
            'require' => $requireValue,
            'types' => $typesValue !== '' ? $typesValue : null,
        ];
    }

    if ($examCode === '' || $title === '' || $startTime === '' || $endTime === '') {
        $errors[] = 'Exam ID, title, start time, and end time are required.';
    }

    $startDt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $startTime);
    $endDt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $endTime);

    if (!$startDt || !$endDt) {
        $errors[] = 'Invalid start or end time.';
    }

    if (count($documents) === 0) {
        $errors[] = 'At least one document is required.';
    }

    foreach ($documents as $doc) {
        if ($doc['require'] && $doc['types'] === null) {
            $errors[] = 'File types are required when file type enforcement is enabled.';
            break;
        }
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

            $insertDoc = $pdo->prepare(
                'INSERT INTO exam_documents (exam_id, title, student_note, require_file_type, allowed_file_types, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($documents as $index => $doc) {
                $insertDoc->execute([
                    $examId,
                    $doc['title'],
                    $doc['note'],
                    $doc['require'],
                    $doc['types'],
                    $index + 1,
                ]);
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
$pageTitle = 'Create Exam - Staff';
$brandHref = 'index.php';
$brandText = 'Staff';
$logoPath = '../logo.png';
$cssPath = '../style.css';
$navActions = '<a class="btn btn-outline-secondary btn-sm" href="../index.php">Student View</a>'
    . '<a class="btn btn-outline-secondary btn-sm" href="index.php">Back to exams</a>';
require __DIR__ . '/../header.php';
?>
<main class="container py-4">
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
                    <input class="form-control" type="text" name="exam_code" data-example="EXAM-2024-01" value="EXAM-2024-01" required>
                    <div class="form-text">Example: EXAM-2024-01</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Exam Title</label>
                    <input class="form-control" type="text" name="title" data-example="Biology Paper 1" value="Biology Paper 1" required>
                    <div class="form-text">Example: Biology Paper 1</div>
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
                        <div class="border rounded p-3">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label">Document title</label>
                                    <input class="form-control" type="text" name="documents_title[]" data-example="Activity 1" value="Activity 1" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Student note</label>
                                    <input class="form-control" type="text" name="documents_note[]" data-example="Make sure to convert to PDF first" value="Make sure to convert to PDF first">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Allowed file types</label>
                                    <input class="form-control" type="text" name="documents_types[]" data-example="pdf, docx" value="pdf, docx">
                                    <div class="form-text">Comma-separated extensions.</div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" name="documents_require[0]" value="1" id="require-0">
                                        <label class="form-check-label" for="require-0">Require these file types</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm mt-2" type="button" id="add-document">Add another document</button>
                </div>

                <div class="mt-3">
                    <label class="form-label">Submitted Document Naming Convention</label>
                    <input class="form-control" type="text" name="file_name_template" id="create-file-template" data-example="{candidate_number}_{document_title}_{original_name}" value="{candidate_number}_{document_title}_{original_name}">
                    <div class="form-text">Example template shown; click to clear.</div>
                    <div class="form-text d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{exam_id}">{exam_id}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{exam_title}">{exam_title}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{student_firstname}">{student_firstname}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{student_surname}">{student_surname}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{student_firstname_initial}">{student_firstname_initial}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{student_surname_initial}">{student_surname_initial}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{candidate_number}">{candidate_number}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{document_title}">{document_title}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{original_name}">{original_name}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-file-template" data-token="{submission_id}">{submission_id}</button>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label">Folder Naming Convention</label>
                    <input class="form-control" type="text" name="folder_name_template" id="create-folder-template" data-example="{candidate_number}_{student_surname}" value="{candidate_number}_{student_surname}">
                    <div class="form-text">Example template shown; click to clear.</div>
                    <div class="form-text d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-folder-template" data-token="{exam_id}">{exam_id}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-folder-template" data-token="{exam_title}">{exam_title}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-folder-template" data-token="{student_firstname}">{student_firstname}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-folder-template" data-token="{student_surname}">{student_surname}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-folder-template" data-token="{student_firstname_initial}">{student_firstname_initial}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-folder-template" data-token="{student_surname_initial}">{student_surname_initial}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-folder-template" data-token="{candidate_number}">{candidate_number}</button>
                        <button class="btn btn-outline-secondary btn-sm token-btn" type="button" data-target="create-folder-template" data-token="{submission_id}">{submission_id}</button>
                    </div>
                </div>

                <button class="btn btn-primary mt-3" type="submit">Create Exam</button>
            </form>
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

    const exampleInputs = new Set();

    const initExampleInput = (input) => {
        if (!input || exampleInputs.has(input)) {
            return;
        }
        exampleInputs.add(input);
        input.classList.add('text-muted');
        input.addEventListener('focus', () => {
            if (input.value === input.dataset.example) {
                input.value = '';
                input.classList.remove('text-muted');
            }
        });
        input.addEventListener('blur', () => {
            if (input.value.trim() === '') {
                input.value = input.dataset.example;
                input.classList.add('text-muted');
            }
        });
    };

    document.querySelectorAll('[data-example]').forEach(initExampleInput);

    let docIndex = 1;

    addButton.addEventListener('click', () => {
        const wrapper = document.createElement('div');
        wrapper.className = 'border rounded p-3';
        wrapper.innerHTML = `
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label">Document title</label>
                    <input class="form-control" type="text" name="documents_title[]" data-example="Activity" value="Activity" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Student note</label>
                    <input class="form-control" type="text" name="documents_note[]" data-example="Make sure to convert to PDF first" value="Make sure to convert to PDF first">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Allowed file types</label>
                    <input class="form-control" type="text" name="documents_types[]" data-example="pdf, docx" value="pdf, docx">
                    <div class="form-text">Comma-separated extensions.</div>
                </div>
                <div class="col-12">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="documents_require[${docIndex}]" value="1" id="require-${docIndex}">
                        <label class="form-check-label" for="require-${docIndex}">Require these file types</label>
                    </div>
                </div>
            </div>
        `;
        documentList.appendChild(wrapper);
        wrapper.querySelectorAll('[data-example]').forEach(initExampleInput);
        docIndex += 1;
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


    form.addEventListener('submit', (event) => {
        let invalid = false;
        exampleInputs.forEach((input) => {
            if (input.value === input.dataset.example) {
                if (input.hasAttribute('required')) {
                    invalid = true;
                }
                input.value = '';
                input.classList.remove('text-muted');
            }
        });
        if (invalid) {
            event.preventDefault();
            alert('Please replace the example text in required fields.');
        }
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
