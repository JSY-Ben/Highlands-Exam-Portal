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

$stmt = db()->prepare('SELECT * FROM exam_documents WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$examId]);
$documents = $stmt->fetchAll();
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

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">First Name</label>
                    <input class="form-control" type="text" name="student_first_name" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Surname</label>
                    <input class="form-control" type="text" name="student_last_name" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Candidate Number</label>
                    <input class="form-control" type="text" name="candidate_number" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Note to Examiner</label>
                <textarea class="form-control" name="examiner_note" rows="3" placeholder="Please put anything you wish the examiner to know about this submission here"></textarea>
            </div>

            <div class="mb-3">
                <h2 class="h6">Required Documents</h2>
                <div class="row g-3">
                    <?php foreach ($documents as $doc): ?>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo e($doc['title']); ?></label>
                            <?php
                            $accept = '';
                            if (!empty($doc['require_file_type'])) {
                                $accept = build_accept_attribute($doc['allowed_file_types'] ?? '');
                            }
                            ?>
                            <input class="form-control file-input" type="file" data-doc-id="<?php echo (int) $doc['id']; ?>" name="file_<?php echo (int) $doc['id']; ?>" <?php echo $accept !== '' ? 'accept="' . e($accept) . '"' : ''; ?>>
                            <input type="hidden" name="uploaded_token_<?php echo (int) $doc['id']; ?>" id="uploaded-token-<?php echo (int) $doc['id']; ?>" value="">
                            <div class="progress mt-2 d-none" id="progress-<?php echo (int) $doc['id']; ?>">
                                <div class="progress-bar" role="progressbar" style="width: 0%">0%</div>
                            </div>
                            <div class="form-text text-success d-none" id="status-<?php echo (int) $doc['id']; ?>">Upload complete.</div>
                            <div class="form-text text-danger d-none" id="error-<?php echo (int) $doc['id']; ?>"></div>
                            <button class="btn btn-outline-danger btn-sm mt-2 d-none remove-upload" type="button" data-doc-id="<?php echo (int) $doc['id']; ?>">Remove uploaded file</button>
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
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="alert alert-warning border d-flex align-items-start gap-2 mb-3">
                <input class="form-check-input mt-1" type="checkbox" name="confirm_final" value="1" id="confirm-final" required>
                <label class="form-check-label fw-semibold" for="confirm-final">
                    I confirm this is my final submission.
                </label>
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
