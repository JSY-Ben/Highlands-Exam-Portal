<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$config = require __DIR__ . '/config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');

$examId = (int) ($_POST['exam_id'] ?? 0);
$studentFirstName = trim((string) ($_POST['student_first_name'] ?? ''));
$studentLastName = trim((string) ($_POST['student_last_name'] ?? ''));
$candidateNumber = trim((string) ($_POST['candidate_number'] ?? ''));
$examinerNote = trim((string) ($_POST['examiner_note'] ?? ''));
$confirmed = isset($_POST['confirm_final']);
$replaceConfirmed = isset($_POST['replace_confirmed']);

if ($examId <= 0 || !$confirmed) {
    http_response_code(422);
    echo 'Missing required fields.';
    exit;
}

$studentName = trim($studentFirstName . ' ' . $studentLastName);
$firstInitial = $studentFirstName !== '' ? substr($studentFirstName, 0, 1) : '';
$lastInitial = $studentLastName !== '' ? substr($studentLastName, 0, 1) : '';

$now = new DateTimeImmutable('now');

$stmt = db()->prepare('SELECT * FROM exams WHERE id = ?');
$stmt->execute([$examId]);
$exam = $stmt->fetch();

if (!$exam || !exam_is_active($exam, $now)) {
    http_response_code(403);
    echo 'Exam not accepting submissions.';
    exit;
}

$rosterEnabled = !empty($exam['student_roster_enabled']);
$rosterMode = ($exam['student_roster_mode'] ?? '') === 'password' ? 'password' : 'menu';
if ($rosterEnabled) {
    if ($rosterMode === 'password') {
        $rosterSessionKey = 'exam_roster_student_' . $examId;
        $studentId = (int) ($_SESSION[$rosterSessionKey] ?? 0);
        if ($studentId <= 0) {
            http_response_code(403);
            echo 'Student password required.';
            exit;
        }
        $stmt = db()->prepare('SELECT * FROM exam_students WHERE id = ? AND exam_id = ?');
        $stmt->execute([$studentId, $examId]);
        $student = $stmt->fetch();
        if (!$student) {
            http_response_code(403);
            echo 'Student password required.';
            exit;
        }
        $studentFirstName = trim((string) $student['student_first_name']);
        $studentLastName = trim((string) $student['student_last_name']);
        $candidateNumber = trim((string) $student['candidate_number']);
    } else {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        if ($studentId <= 0) {
            http_response_code(422);
            echo 'Student selection required.';
            exit;
        }
        $stmt = db()->prepare('SELECT * FROM exam_students WHERE id = ? AND exam_id = ?');
        $stmt->execute([$studentId, $examId]);
        $student = $stmt->fetch();
        if (!$student) {
            http_response_code(422);
            echo 'Invalid student selection.';
            exit;
        }
        $studentFirstName = trim((string) $student['student_first_name']);
        $studentLastName = trim((string) $student['student_last_name']);
        $candidateNumber = trim((string) $student['candidate_number']);
    }
}

$studentFirstName = trim($studentFirstName);
$studentLastName = trim($studentLastName);
$candidateNumber = trim($candidateNumber);

if ($studentFirstName === '' || $studentLastName === '' || $candidateNumber === '') {
    http_response_code(422);
    echo 'Missing required fields.';
    exit;
}

$requiresPassword = !empty($exam['access_password_hash'] ?? '');
if ($requiresPassword) {
    $accessKey = 'exam_access_' . $examId;
    if (empty($_SESSION[$accessKey])) {
        http_response_code(403);
        echo 'Exam password required.';
        exit;
    }
}

$stmt = db()->prepare('SELECT * FROM exam_documents WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$examId]);
$documents = $stmt->fetchAll();

if (count($documents) === 0) {
    http_response_code(400);
    echo 'No documents configured for this exam.';
    exit;
}

$tmpDir = $uploadsDir . '/tmp';
$tokens = [];
foreach ($documents as $doc) {
    $tokenKey = 'uploaded_token_' . $doc['id'];
    $tokenValue = trim((string) ($_POST[$tokenKey] ?? ''));
    if ($tokenValue !== '' && preg_match('/^[a-f0-9]{32}$/', $tokenValue)) {
        $tokens[$doc['id']] = $tokenValue;
    }
}
if ($replaceConfirmed) {
    $pendingTokensKey = 'pending_upload_tokens_' . $examId;
    if (isset($_SESSION[$pendingTokensKey]) && is_array($_SESSION[$pendingTokensKey])) {
        foreach ($_SESSION[$pendingTokensKey] as $docId => $token) {
            $docId = (int) $docId;
            if (isset($tokens[$docId])) {
                continue;
            }
            $token = trim((string) $token);
            if ($token !== '' && preg_match('/^[a-f0-9]{32}$/', $token)) {
                $tokens[$docId] = $token;
            }
        }
    }
}

$existingSubmissions = [];
$stmt = db()->prepare(
    'SELECT id FROM submissions
     WHERE exam_id = ?
       AND TRIM(candidate_number) = ?
       AND TRIM(student_first_name) = ?
       AND TRIM(student_last_name) = ?
     ORDER BY submitted_at DESC'
);
$stmt->execute([$examId, $candidateNumber, $studentFirstName, $studentLastName]);
$existingSubmissions = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (count($existingSubmissions) > 0 && !$replaceConfirmed) {
    foreach ($documents as $doc) {
        $docId = (int) $doc['id'];
        if (isset($tokens[$docId])) {
            continue;
        }
        $key = 'file_' . $docId;
        if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
            continue;
        }

        if (!empty($doc['require_file_type'])) {
            $allowedTypes = parse_allowed_file_types($doc['allowed_file_types'] ?? '');
            if (count($allowedTypes) > 0) {
                $originalName = (string) $_FILES[$key]['name'];
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if ($ext === '' || !in_array($ext, $allowedTypes, true)) {
                    http_response_code(422);
                    echo 'Invalid file type for: ' . e($doc['title']);
                    exit;
                }
            }
        }

        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true)) {
            http_response_code(500);
            echo 'Unable to prepare upload directory.';
            exit;
        }

        $token = bin2hex(random_bytes(16));
        $tmpFile = $tmpDir . '/' . $token . '.upload';
        $tmpMeta = $tmpDir . '/' . $token . '.json';

        if (!move_uploaded_file($_FILES[$key]['tmp_name'], $tmpFile)) {
            http_response_code(500);
            echo 'Upload failed.';
            exit;
        }

        $metadata = [
            'exam_id' => $examId,
            'doc_id' => $docId,
            'original_name' => (string) $_FILES[$key]['name'],
            'file_size' => (int) $_FILES[$key]['size'],
            'uploaded_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ];

        file_put_contents($tmpMeta, json_encode($metadata, JSON_PRETTY_PRINT));
        $tokens[$docId] = $token;
    }

    if (!$rosterEnabled) {
        $sessionKey = 'pending_submission_' . $examId;
        $_SESSION[$sessionKey] = [
            'student_first_name' => $studentFirstName,
            'student_last_name' => $studentLastName,
            'candidate_number' => $candidateNumber,
            'examiner_note' => $examinerNote,
        ];
    }
    $tokenKey = 'pending_upload_tokens_' . $examId;
    $sessionTokens = $_SESSION[$tokenKey] ?? [];
    if (!is_array($sessionTokens)) {
        $sessionTokens = [];
    }
    if (count($sessionTokens) > 0) {
        $tokens = $tokens + $sessionTokens;
    }
    $_SESSION[$tokenKey] = $tokens;
    if (count($tokens) > 0) {
        $nameKey = 'pending_upload_names_' . $examId;
        $tmpDir = $uploadsDir . '/tmp';
        $names = [];
        foreach ($tokens as $docId => $token) {
            $metaPath = $tmpDir . '/' . $token . '.json';
            if (!is_file($metaPath)) {
                continue;
            }
            $metaRaw = file_get_contents($metaPath);
            $meta = $metaRaw !== false ? json_decode($metaRaw, true) : null;
            if (!is_array($meta)) {
                continue;
            }
            $originalName = (string) ($meta['original_name'] ?? '');
            if ($originalName !== '') {
                $names[(int) $docId] = $originalName;
            }
        }
        if (count($names) > 0) {
            $_SESSION[$nameKey] = $names;
        }
    }
    header('Location: student_exam.php?id=' . $examId . '&replace=1');
    exit;
}

$fileTypeErrors = [];
foreach ($documents as $doc) {
    $key = 'file_' . $doc['id'];
    if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
        if (isset($tokens[$doc['id']])) {
            continue;
        }
        continue;
    }

    if (!empty($doc['require_file_type'])) {
        $allowedTypes = parse_allowed_file_types($doc['allowed_file_types'] ?? '');
        if (count($allowedTypes) > 0) {
            $originalName = (string) $_FILES[$key]['name'];
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if ($ext === '' || !in_array($ext, $allowedTypes, true)) {
                $fileTypeErrors[] = $doc['title'];
            }
        }
    }
}

if (count($fileTypeErrors) > 0) {
    http_response_code(422);
    echo 'Invalid file type for: ' . e(implode(', ', $fileTypeErrors));
    exit;
}

$uploadedCount = 0;
foreach ($documents as $doc) {
    $key = 'file_' . $doc['id'];
    if (!isset($_FILES[$key])) {
        if (isset($tokens[$doc['id']])) {
            $uploadedCount++;
        }
        continue;
    }

    $error = $_FILES[$key]['error'];
    if ($error === UPLOAD_ERR_OK) {
        $uploadedCount++;
        continue;
    }

    if ($error === UPLOAD_ERR_NO_FILE) {
        if (isset($tokens[$doc['id']])) {
            $uploadedCount++;
        }
        continue;
    }

    if ($error !== UPLOAD_ERR_NO_FILE) {
        http_response_code(422);
        echo 'Problem uploading file: ' . e($doc['title']);
        exit;
    }
}

if ($uploadedCount === 0 && $replaceConfirmed) {
    $pendingTokensKey = 'pending_upload_tokens_' . $examId;
    if (isset($_SESSION[$pendingTokensKey]) && is_array($_SESSION[$pendingTokensKey])) {
        foreach ($_SESSION[$pendingTokensKey] as $docId => $token) {
            $docId = (int) $docId;
            if (isset($tokens[$docId])) {
                continue;
            }
            $token = trim((string) $token);
            if ($token !== '' && preg_match('/^[a-f0-9]{32}$/', $token)) {
                $tokens[$docId] = $token;
                $uploadedCount++;
            }
        }
    }
}

if ($uploadedCount === 0) {
    http_response_code(422);
    echo 'Please upload at least one file.';
    exit;
}

$pdo = db();
$pdo->beginTransaction();

try {
    if (count($existingSubmissions) > 0) {
        $placeholders = implode(',', array_fill(0, count($existingSubmissions), '?'));
        $stmt = $pdo->prepare("SELECT stored_path FROM submission_files WHERE submission_id IN ($placeholders)");
        $stmt->execute($existingSubmissions);
        $storedPaths = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $deleteStmt = $pdo->prepare("DELETE FROM submissions WHERE id IN ($placeholders)");
        $deleteStmt->execute($existingSubmissions);

        $folders = [];
        foreach ($storedPaths as $path) {
            $fullPath = $uploadsDir . '/' . ltrim((string) $path, '/');
            $realPath = realpath($fullPath);
            if ($realPath && strpos($realPath, realpath($uploadsDir)) === 0 && is_file($realPath)) {
                @unlink($realPath);
                $folders[] = dirname($realPath);
            }
        }

        foreach (array_unique($folders) as $folder) {
            if (is_dir($folder)) {
                @rmdir($folder);
            }
        }
    }

$stmt = $pdo->prepare('INSERT INTO submissions (exam_id, student_name, student_first_name, student_last_name, candidate_number, examiner_note, submitted_at, ip_address, host_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $hostName = trim((string) ($_SERVER['REMOTE_HOST'] ?? ''));
    if ($hostName === '' && $ipAddress !== '' && $ipAddress !== 'unknown') {
        $resolved = @gethostbyaddr($ipAddress);
        if ($resolved && $resolved !== $ipAddress) {
            $hostName = $resolved;
        }
    }
    $stmt->execute([
        $examId,
        $studentName,
        $studentFirstName,
        $studentLastName,
        $candidateNumber,
        $examinerNote !== '' ? $examinerNote : null,
        now_utc_string(),
        $ipAddress,
        $hostName !== '' ? $hostName : null,
    ]);

    $pendingKey = 'pending_submission_' . $examId;
    if (isset($_SESSION[$pendingKey])) {
        unset($_SESSION[$pendingKey]);
    }
    $pendingTokensKey = 'pending_upload_tokens_' . $examId;
    if (isset($_SESSION[$pendingTokensKey])) {
        unset($_SESSION[$pendingTokensKey]);
    }
    $pendingNamesKey = 'pending_upload_names_' . $examId;
    if (isset($_SESSION[$pendingNamesKey])) {
        unset($_SESSION[$pendingNamesKey]);
    }

    $submissionId = (int) $pdo->lastInsertId();
    $examFolder = $uploadsDir . '/exam_' . $examId;
    $submissionFolder = $examFolder . '/submission_' . $submissionId;

    if (!is_dir($submissionFolder) && !mkdir($submissionFolder, 0755, true)) {
        throw new RuntimeException('Unable to create upload directory.');
    }

    $insertFile = $pdo->prepare(
        'INSERT INTO submission_files (submission_id, exam_document_id, original_name, stored_name, stored_path, file_size) VALUES (?, ?, ?, ?, ?, ?)'
    );

    foreach ($documents as $doc) {
        $key = 'file_' . $doc['id'];
        if (isset($tokens[$doc['id']])) {
            $token = $tokens[$doc['id']];
            $metaPath = $tmpDir . '/' . $token . '.json';
            $tmpPath = $tmpDir . '/' . $token . '.upload';

            if (!is_file($metaPath) || !is_file($tmpPath)) {
                throw new RuntimeException('Missing uploaded file.');
            }

            $metaRaw = file_get_contents($metaPath);
            $meta = $metaRaw !== false ? json_decode($metaRaw, true) : null;
            if (!is_array($meta) || (int) ($meta['exam_id'] ?? 0) !== $examId || (int) ($meta['doc_id'] ?? 0) !== (int) $doc['id']) {
                throw new RuntimeException('Upload mismatch.');
            }

            $originalName = (string) ($meta['original_name'] ?? 'upload');
            $fileSize = (int) ($meta['file_size'] ?? 0);
            $examIdentifier = $exam['exam_code'] ?? $exam['id'];
            $baseName = apply_name_template(
                $exam['file_name_template'] ?? '',
                [
                    'exam_id' => $examIdentifier,
                    'exam_title' => $exam['title'],
                    'student_firstname' => $studentFirstName,
                    'student_surname' => $studentLastName,
                    'student_firstname_initial' => $firstInitial,
                    'student_surname_initial' => $lastInitial,
                    'candidate_number' => $candidateNumber,
                    'document_title' => $doc['title'],
                    'original_name' => $originalName,
                    'submission_id' => (string) $submissionId,
                ],
                $originalName
            );
            $baseName = ensure_original_extension($baseName, $originalName);
            $baseName = sanitize_name_component($baseName);
            $storedName = uniqid('file_', true) . '_' . $baseName;
            $storedPath = $submissionFolder . '/' . $storedName;

            if (!@rename($tmpPath, $storedPath)) {
                if (!@copy($tmpPath, $storedPath)) {
                    throw new RuntimeException('Failed to store upload.');
                }
                @unlink($tmpPath);
            }

            @unlink($metaPath);
            $relativePath = str_replace($uploadsDir . '/', '', $storedPath);

            $insertFile->execute([
                $submissionId,
                $doc['id'],
                $originalName,
                $storedName,
                $relativePath,
                $fileSize,
            ]);
            continue;
        }
        if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
            continue;
        }

        $file = $_FILES[$key];
        $originalName = $file['name'];
        $examIdentifier = $exam['exam_code'] ?? $exam['id'];
        $baseName = apply_name_template(
            $exam['file_name_template'] ?? '',
            [
                'exam_id' => $examIdentifier,
                'exam_title' => $exam['title'],
                'student_firstname' => $studentFirstName,
                'student_surname' => $studentLastName,
                'student_firstname_initial' => $firstInitial,
                'student_surname_initial' => $lastInitial,
                'candidate_number' => $candidateNumber,
                'document_title' => $doc['title'],
                'original_name' => $originalName,
                'submission_id' => (string) $submissionId,
            ],
            $originalName
        );
        $baseName = ensure_original_extension($baseName, $originalName);
        $baseName = sanitize_name_component($baseName);
        $storedName = uniqid('file_', true) . '_' . $baseName;
        $storedPath = $submissionFolder . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
            throw new RuntimeException('Failed to store upload.');
        }

        $relativePath = str_replace($uploadsDir . '/', '', $storedPath);

        $insertFile->execute([
            $submissionId,
            $doc['id'],
            $originalName,
            $storedName,
            $relativePath,
            (int) $file['size'],
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();

    if (isset($submissionFolder) && is_dir($submissionFolder)) {
        $files = glob($submissionFolder . '/*');
        if (is_array($files)) {
            foreach ($files as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        rmdir($submissionFolder);
    }

    http_response_code(500);
    echo 'Upload failed. Please try again.';
    exit;
}
$pageTitle = 'Submission Received';
$brandHref = 'index.php';
$brandText = 'Exams Submission Portal';
$logoPath = 'logo.png';
$cssPath = 'style.css';
$navActions = '';
require __DIR__ . '/header.php';
?>
<main class="container py-5">
    <div class="alert alert-success shadow-sm">
        <h1 class="h4 mb-2">Submission received</h1>
        <p class="mb-0">Your files have been submitted successfully.</p>
    </div>
</main>
<?php require __DIR__ . '/footer.php'; ?>
