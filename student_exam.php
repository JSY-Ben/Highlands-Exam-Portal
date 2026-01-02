<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$examId = (int) ($_GET['id'] ?? 0);
$now = new DateTimeImmutable('now');
$replaceRequested = ($_GET['replace'] ?? '') === '1';

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
    $pageTitle = 'Exam Unavailable';
    $brandHref = 'index.php';
    $brandText = 'Exams Submission Portal';
    $logoPath = 'logo.png';
    $cssPath = 'style.css';
    $navActions = '';
    require __DIR__ . '/header.php';
    ?>
    <main class="container py-5">
        <div class="alert alert-warning shadow-sm">
            <h1 class="h5 mb-2">Exam unavailable</h1>
            <p class="mb-3"><?php echo e($statusMessage); ?></p>
            <a class="btn btn-outline-secondary btn-sm" href="index.php">Back to active exams</a>
        </div>
    </main>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

$requiresPassword = !empty($exam['access_password_hash'] ?? '');
$accessKey = 'exam_access_' . $examId;
$hasAccess = !$requiresPassword || !empty($_SESSION[$accessKey]);
$accessError = '';

if ($requiresPassword && !$hasAccess && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = trim((string) ($_POST['access_password'] ?? ''));
    $storedHash = (string) ($exam['access_password_hash'] ?? '');
    if ($password === '' || $storedHash === '' || !password_verify($password, $storedHash)) {
        $accessError = 'Incorrect password. Please try again.';
    } else {
        $_SESSION[$accessKey] = true;
        header('Location: student_exam.php?id=' . $examId);
        exit;
    }
}

if ($requiresPassword && !$hasAccess) {
    $pageTitle = 'Enter Exam Password';
    $brandHref = 'index.php';
    $brandText = 'Exams Submission Portal';
    $logoPath = 'logo.png';
    $cssPath = 'style.css';
    $navActions = '';
    require __DIR__ . '/header.php';
    ?>
    <main class="container py-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h5 mb-3">Password required</h1>
                <p class="text-muted">Enter the exam password to continue.</p>

                <?php if ($accessError !== ''): ?>
                    <div class="alert alert-danger"><?php echo e($accessError); ?></div>
                <?php endif; ?>

                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Exam password</label>
                        <input class="form-control" type="password" name="access_password" autocomplete="current-password" required>
                    </div>
                    <button class="btn btn-primary" type="submit">Continue</button>
                    <a class="btn btn-outline-secondary" href="index.php">Back</a>
                </form>
            </div>
        </div>
    </main>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

$rosterEnabled = !empty($exam['student_roster_enabled']);
$rosterMode = ($exam['student_roster_mode'] ?? '') === 'password' ? 'password' : 'menu';
$students = [];
if ($rosterEnabled) {
    if ($rosterMode === 'menu') {
        $stmt = db()->prepare(
            'SELECT * FROM exam_students WHERE exam_id = ? ORDER BY student_last_name ASC, student_first_name ASC, candidate_number ASC, id ASC'
        );
    } else {
        $stmt = db()->prepare('SELECT * FROM exam_students WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
    }
    $stmt->execute([$examId]);
    $students = $stmt->fetchAll();
}

if ($rosterEnabled && count($students) === 0) {
    $pageTitle = 'Exam Roster Missing';
    $brandHref = 'index.php';
    $brandText = 'Exams Submission Portal';
    $logoPath = 'logo.png';
    $cssPath = 'style.css';
    $navActions = '';
    require __DIR__ . '/header.php';
    ?>
    <main class="container py-5">
        <div class="alert alert-warning shadow-sm">
            <h1 class="h5 mb-2">No student list configured</h1>
            <p class="mb-3">This exam requires a student register, but no students have been added yet.</p>
            <a class="btn btn-outline-secondary btn-sm" href="index.php">Back to active exams</a>
        </div>
    </main>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

$rosterStudent = null;
if (!$rosterEnabled) {
    $rosterMode = '';
}
$prefill = null;
if ($replaceRequested) {
    $sessionKey = 'pending_submission_' . $examId;
    if (!$rosterEnabled && isset($_SESSION[$sessionKey]) && is_array($_SESSION[$sessionKey])) {
        $prefill = $_SESSION[$sessionKey];
    }
}
if ($rosterEnabled && $rosterMode === 'password') {
    $rosterSessionKey = 'exam_roster_student_' . $examId;
    $studentId = (int) ($_SESSION[$rosterSessionKey] ?? 0);
    if ($studentId > 0) {
        $stmt = db()->prepare('SELECT * FROM exam_students WHERE id = ? AND exam_id = ?');
        $stmt->execute([$studentId, $examId]);
        $rosterStudent = $stmt->fetch();
    }

    if (!$rosterStudent && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $studentPassword = trim((string) ($_POST['student_password'] ?? ''));
        if ($studentPassword === '') {
            $accessError = 'Student password required.';
        } else {
            $stmt = db()->prepare('SELECT * FROM exam_students WHERE exam_id = ? AND access_password = ? LIMIT 1');
            $stmt->execute([$examId, $studentPassword]);
            $rosterStudent = $stmt->fetch();
            if ($rosterStudent) {
                $_SESSION[$rosterSessionKey] = (int) $rosterStudent['id'];
                header('Location: student_exam.php?id=' . $examId);
                exit;
            }
            $accessError = 'Invalid student password.';
        }
    }

    if (!$rosterStudent) {
        $pageTitle = 'Enter Student Password';
        $brandHref = 'index.php';
        $brandText = 'Exams Submission Portal';
        $logoPath = 'logo.png';
        $cssPath = 'style.css';
        $navActions = '';
        require __DIR__ . '/header.php';
        ?>
        <main class="container py-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h1 class="h5 mb-3">Student password required</h1>
                    <p class="text-muted">Enter the student password to continue.</p>

                    <?php if ($accessError !== ''): ?>
                        <div class="alert alert-danger"><?php echo e($accessError); ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">Student password</label>
                            <input class="form-control" type="password" name="student_password" autocomplete="current-password" required>
                        </div>
                        <button class="btn btn-primary" type="submit">Continue</button>
                        <a class="btn btn-outline-secondary" href="index.php">Back</a>
                    </form>
                </div>
            </div>
        </main>
        <?php
        require __DIR__ . '/footer.php';
        exit;
    }
}

$replaceRequired = $replaceRequested;
if ($rosterEnabled && $rosterMode === 'password' && $rosterStudent) {
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM submissions
         WHERE exam_id = ?
           AND TRIM(candidate_number) = ?
           AND TRIM(student_first_name) = ?
           AND TRIM(student_last_name) = ?'
    );
    $stmt->execute([
        $examId,
        trim((string) $rosterStudent['candidate_number']),
        trim((string) $rosterStudent['student_first_name']),
        trim((string) $rosterStudent['student_last_name']),
    ]);
    if ((int) $stmt->fetchColumn() > 0) {
        $replaceRequired = true;
    }
}

$stmt = db()->prepare('SELECT * FROM exam_documents WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$examId]);
$documents = $stmt->fetchAll();

$prefillTokens = [];
$prefillMeta = [];
if ($replaceRequested) {
    $nameKey = 'pending_upload_names_' . $examId;
    if (isset($_SESSION[$nameKey]) && is_array($_SESSION[$nameKey])) {
        foreach ($_SESSION[$nameKey] as $docId => $name) {
            $prefillMeta[(int) $docId] = [
                'original_name' => (string) $name,
            ];
        }
    }
}
if ($replaceRequested) {
    $tokenKey = 'pending_upload_tokens_' . $examId;
    if (isset($_SESSION[$tokenKey]) && is_array($_SESSION[$tokenKey])) {
        $prefillTokens = $_SESSION[$tokenKey];
    }
}
if ($replaceRequested && count($prefillTokens) > 0 && count($prefillMeta) === 0) {
    $config = require __DIR__ . '/config.php';
    $uploadsDir = rtrim($config['uploads_dir'], '/');
    $tmpDir = $uploadsDir . '/tmp';
    foreach ($prefillTokens as $docId => $token) {
        $token = (string) $token;
        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            continue;
        }
        $metaPath = $tmpDir . '/' . $token . '.json';
        if (!is_file($metaPath)) {
            continue;
        }
        $metaRaw = file_get_contents($metaPath);
        $meta = $metaRaw !== false ? json_decode($metaRaw, true) : null;
        if (!is_array($meta)) {
            continue;
        }
        if ((int) ($meta['exam_id'] ?? 0) !== $examId || (int) ($meta['doc_id'] ?? 0) !== (int) $docId) {
            continue;
        }
        $prefillMeta[(int) $docId] = [
            'original_name' => (string) ($meta['original_name'] ?? ''),
        ];
    }
}
$pageTitle = 'Submit Files - ' . $exam['title'];
$brandHref = 'index.php';
$brandText = 'Exams Submission Portal';
$logoPath = 'logo.png';
$cssPath = 'style.css';
$navActions = '';
require __DIR__ . '/header.php';
?>
<main class="container py-4">
    <div class="mb-4">
        <h1 class="h3">Submit for <?php echo e($exam['title']); ?></h1>
        <p class="text-muted">Upload all required files before the submission window ends.</p>
    </div>

    <form class="card shadow-sm" action="submit.php" method="post" enctype="multipart/form-data">
        <div class="card-body">
            <input type="hidden" name="exam_id" value="<?php echo (int) $exam['id']; ?>">

            <div class="alert alert-danger<?php echo $replaceRequired ? '' : ' d-none'; ?>" id="replace-warning">
                A submission has already been received for this student. If you continue, your previous submission will be replaced.
            </div>

            <?php if ($rosterEnabled): ?>
                <?php if ($rosterMode === 'password'): ?>
                    <div class="alert alert-info">
                        <?php
                        $label = trim(($rosterStudent['student_first_name'] ?? '') . ' ' . ($rosterStudent['student_last_name'] ?? ''));
                        $candidate = (string) ($rosterStudent['candidate_number'] ?? '');
                        ?>
                        Submitting as <strong><?php echo e($label); ?></strong><?php echo $candidate !== '' ? ' (' . e($candidate) . ')' : ''; ?>.
                    </div>
                <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label">Select Your Name</label>
                        <select class="form-select" name="student_id" required>
                            <option value="">Choose your name</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo (int) $student['id']; ?>">
                                    <?php
                                    $label = trim($student['student_last_name'] . ', ' . $student['student_first_name']);
                                    $label .= $student['candidate_number'] !== '' ? ' (' . $student['candidate_number'] . ')' : '';
                                    echo e($label);
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">First Name</label>
                        <input class="form-control" type="text" name="student_first_name" value="<?php echo e((string) ($prefill['student_first_name'] ?? '')); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Surname</label>
                        <input class="form-control" type="text" name="student_last_name" value="<?php echo e((string) ($prefill['student_last_name'] ?? '')); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Candidate Number</label>
                        <input class="form-control" type="text" name="candidate_number" value="<?php echo e((string) ($prefill['candidate_number'] ?? '')); ?>" required>
                    </div>
                </div>
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label">Note to Examiner</label>
                <textarea class="form-control" name="examiner_note" rows="3" placeholder="Please put anything you wish the examiner to know about this submission here"><?php echo e((string) ($prefill['examiner_note'] ?? '')); ?></textarea>
            </div>

            <div class="mb-3">
                <h2 class="h6">Required Documents</h2>
                <div class="row g-3">
                    <?php foreach ($documents as $doc): ?>
                        <div class="col-md-6">
                            <div class="card shadow-sm h-100">
                                <div class="card-body">
                                    <label class="form-label fw-semibold"><?php echo e($doc['title']); ?></label>
                            <?php
                            $accept = '';
                            if (!empty($doc['require_file_type'])) {
                                $accept = build_accept_attribute($doc['allowed_file_types'] ?? '');
                            }
                            ?>
                            <?php
                            $prefillToken = (string) ($prefillTokens[$doc['id']] ?? '');
                            $prefillInfo = $prefillMeta[(int) $doc['id']] ?? null;
                            ?>
                            <input class="form-control file-input" type="file" data-doc-id="<?php echo (int) $doc['id']; ?>" name="file_<?php echo (int) $doc['id']; ?>" <?php echo $accept !== '' ? 'accept="' . e($accept) . '"' : ''; ?>>
                            <input type="hidden" name="uploaded_token_<?php echo (int) $doc['id']; ?>" id="uploaded-token-<?php echo (int) $doc['id']; ?>" value="<?php echo e($prefillToken); ?>">
                            <div class="progress mt-2<?php echo $prefillToken !== '' ? '' : ' d-none'; ?>" id="progress-<?php echo (int) $doc['id']; ?>">
                                <div class="progress-bar" role="progressbar" style="width: <?php echo $prefillToken !== '' ? '100%' : '0%'; ?>"><?php echo $prefillToken !== '' ? '100%' : '0%'; ?></div>
                            </div>
                            <div class="form-text text-success<?php echo $prefillToken !== '' ? '' : ' d-none'; ?>" id="status-<?php echo (int) $doc['id']; ?>">Upload complete.</div>
                            <?php if ($prefillToken !== ''): ?>
                                <?php if (is_array($prefillInfo) && $prefillInfo['original_name'] !== ''): ?>
                                    <div class="form-text text-muted">File already uploaded: <?php echo e($prefillInfo['original_name']); ?></div>
                                <?php else: ?>
                                    <div class="form-text text-muted">File already uploaded and ready for resubmission.</div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="form-text text-danger d-none" id="error-<?php echo (int) $doc['id']; ?>"></div>
                            <button class="btn btn-outline-danger btn-sm mt-2<?php echo $prefillToken !== '' ? '' : ' d-none'; ?> remove-upload" type="button" data-doc-id="<?php echo (int) $doc['id']; ?>">Remove uploaded file</button>
                            <?php
                            $noteText = !empty($doc['student_note']) ? $doc['student_note'] : '';
                            $typesText = (!empty($doc['require_file_type']) && !empty($doc['allowed_file_types']))
                                ? $doc['allowed_file_types']
                                : '';
                            ?>
                            <?php if ($noteText !== '' || $typesText !== ''): ?>
                                <div class="alert border py-2 mb-2 d-flex flex-wrap gap-3 align-items-center doc-note-banner">
                                    <?php if ($noteText !== ''): ?>
                                        <div><strong>Note:</strong> <?php echo e($noteText); ?></div>
                                    <?php endif; ?>
                                    <?php if ($typesText !== ''): ?>
                                        <div><strong>Required file types:</strong> <?php echo e($typesText); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="alert alert-warning border d-flex align-items-start gap-2 mb-3">
                <input class="form-check-input mt-1" type="checkbox" name="confirm_final" value="1" id="confirm-final" required>
                <label class="form-check-label fw-semibold" for="confirm-final">
                    I confirm this is my final submission.
                </label>
            </div>
            <div class="alert alert-danger border d-flex align-items-start gap-2 mb-3<?php echo $replaceRequired ? '' : ' d-none'; ?>" id="replace-confirm-wrapper">
                <input class="form-check-input mt-1" type="checkbox" name="replace_confirmed" value="1" id="replace-confirmed" <?php echo $replaceRequired ? 'required' : ''; ?>>
                <label class="form-check-label fw-semibold" for="replace-confirmed">
                    I understand my previous submission will be replaced.
                </label>
            </div>
            <input type="hidden" name="missing_confirmed" id="missing-confirmed" value="0">

            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Submit Files</button>
            </div>
        </div>
    </form>
    <form class="mt-3" action="cancel_submission.php" method="post">
        <input type="hidden" name="exam_id" value="<?php echo (int) $exam['id']; ?>">
        <button class="btn btn-outline-secondary" type="submit">Cancel submission</button>
    </form>
    <?php $maxUpload = upload_max_file_size(); ?>
    <p class="text-muted small mt-4 mb-0">
        Maximum file size per upload: <?php echo e(format_bytes($maxUpload)); ?>.
    </p>
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

<div class="modal fade" id="noFilesModal" tabindex="-1" aria-labelledby="noFilesLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="noFilesLabel">No files selected</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Please upload at least one file before submitting.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const form = document.querySelector('form');
    const missingConfirmed = document.getElementById('missing-confirmed');
    const missingFilesModal = new bootstrap.Modal(document.getElementById('missingFilesModal'));
    const noFilesModal = new bootstrap.Modal(document.getElementById('noFilesModal'));
    const missingFilesList = document.getElementById('missingFilesList');
    const confirmMissingFiles = document.getElementById('confirmMissingFiles');
    const replaceWarning = document.getElementById('replace-warning');
    const replaceConfirmWrapper = document.getElementById('replace-confirm-wrapper');
    const replaceConfirm = document.getElementById('replace-confirmed');
    const studentSelect = document.querySelector('select[name="student_id"]');
    let pendingSubmit = false;

    form.addEventListener('submit', (event) => {
        if (pendingSubmit) {
            return;
        }

        const fileInputs = Array.from(form.querySelectorAll('input[type="file"]'));
        const tokenInputs = Array.from(form.querySelectorAll('input[type="hidden"][id^="uploaded-token-"]'));
        const anyFileSelected = fileInputs.some((input) => input.files && input.files.length > 0);
        const anyToken = tokenInputs.some((input) => input.value.trim() !== '');

        if (!anyFileSelected && !anyToken) {
            event.preventDefault();
            noFilesModal.show();
            return;
        }

        const missing = fileInputs.filter((input) => {
            const docId = input.dataset.docId;
            const tokenInput = docId ? document.getElementById(`uploaded-token-${docId}`) : null;
            const hasToken = tokenInput && tokenInput.value.trim() !== '';
            return (!input.files || input.files.length === 0) && !hasToken;
        });

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

    const toggleReplaceWarning = (show) => {
        if (!replaceWarning || !replaceConfirmWrapper || !replaceConfirm) {
            return;
        }
        replaceWarning.classList.toggle('d-none', !show);
        replaceConfirmWrapper.classList.toggle('d-none', !show);
        replaceConfirm.required = show;
        if (!show) {
            replaceConfirm.checked = false;
        }
    };

    const checkExistingSubmission = async (studentId) => {
        if (!studentId) {
            toggleReplaceWarning(false);
            return;
        }
        try {
            const response = await fetch(`check_submission.php?exam_id=${encodeURIComponent(examId)}&student_id=${encodeURIComponent(studentId)}`);
            if (!response.ok) {
                toggleReplaceWarning(false);
                return;
            }
            const data = await response.json();
            toggleReplaceWarning(Boolean(data?.has_submission));
        } catch (e) {
            toggleReplaceWarning(false);
        }
    };

    if (studentSelect) {
        studentSelect.addEventListener('change', (event) => {
            const value = event.target.value;
            checkExistingSubmission(value);
        });
    }

    const examId = <?php echo (int) $exam['id']; ?>;
    const uploadInputs = document.querySelectorAll('.file-input');
    const pendingUploads = new Set();
    const removeButtons = document.querySelectorAll('.remove-upload');

    uploadInputs.forEach((input) => {
        input.addEventListener('change', () => {
            const docId = input.dataset.docId;
            if (!docId || !input.files || input.files.length === 0) {
                return;
            }

            const file = input.files[0];
            const progress = document.getElementById(`progress-${docId}`);
            const bar = progress?.querySelector('.progress-bar');
            const status = document.getElementById(`status-${docId}`);
            const error = document.getElementById(`error-${docId}`);
            const tokenInput = document.getElementById(`uploaded-token-${docId}`);
            const removeButton = document.querySelector(`.remove-upload[data-doc-id="${docId}"]`);

            if (progress) {
                progress.classList.remove('d-none');
            }
            if (status) {
                status.classList.add('d-none');
            }
            if (error) {
                error.classList.add('d-none');
                error.textContent = '';
            }
            if (bar) {
                bar.style.width = '0%';
                bar.textContent = '0%';
            }
            if (removeButton) {
                removeButton.classList.add('d-none');
            }

            const formData = new FormData();
            formData.append('exam_id', examId);
            formData.append('doc_id', docId);
            formData.append('file', file);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'upload_temp.php');
            pendingUploads.add(docId);

            xhr.upload.addEventListener('progress', (event) => {
                if (!event.lengthComputable || !bar) {
                    return;
                }
                const percent = Math.round((event.loaded / event.total) * 100);
                bar.style.width = `${percent}%`;
                bar.textContent = `${percent}%`;
            });

            xhr.addEventListener('load', () => {
                if (!tokenInput) {
                    return;
                }
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.token) {
                            tokenInput.value = response.token;
                            if (status) {
                                status.classList.remove('d-none');
                            }
                            if (bar) {
                                bar.style.width = '100%';
                                bar.textContent = '100%';
                            }
                            if (removeButton) {
                                removeButton.classList.remove('d-none');
                            }
                            pendingUploads.delete(docId);
                            return;
                        }
                    } catch (e) {
                        // fall through
                    }
                }
                tokenInput.value = '';
                if (error) {
                    error.textContent = 'Upload failed. Please try again.';
                    error.classList.remove('d-none');
                }
                pendingUploads.delete(docId);
            });

            xhr.addEventListener('error', () => {
                if (error) {
                    error.textContent = 'Upload failed. Please try again.';
                    error.classList.remove('d-none');
                }
                pendingUploads.delete(docId);
            });

            xhr.send(formData);
        });
    });

    removeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const docId = button.dataset.docId;
            const tokenInput = document.getElementById(`uploaded-token-${docId}`);
            const progress = document.getElementById(`progress-${docId}`);
            const bar = progress?.querySelector('.progress-bar');
            const status = document.getElementById(`status-${docId}`);
            const error = document.getElementById(`error-${docId}`);
            const input = document.querySelector(`.file-input[data-doc-id="${docId}"]`);

            const token = tokenInput?.value || '';
            if (token !== '') {
                const formData = new FormData();
                formData.append('token', token);
                fetch('delete_temp.php', { method: 'POST', body: formData }).catch(() => {});
            }

            if (tokenInput) {
                tokenInput.value = '';
            }
            if (input) {
                input.value = '';
            }
            if (bar) {
                bar.style.width = '0%';
                bar.textContent = '0%';
            }
            if (progress) {
                progress.classList.add('d-none');
            }
            if (status) {
                status.classList.add('d-none');
            }
            if (error) {
                error.classList.add('d-none');
            }
            button.classList.add('d-none');
        });
    });

    form.addEventListener('submit', (event) => {
        if (pendingUploads.size > 0) {
            event.preventDefault();
            alert('Please wait for all uploads to finish before submitting.');
        }
    });
</script>
<?php require __DIR__ . '/footer.php'; ?>
