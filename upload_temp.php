<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

header('Content-Type: application/json');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$config = require __DIR__ . '/config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');
$tmpDir = $uploadsDir . '/tmp';

$examId = (int) ($_POST['exam_id'] ?? 0);
$docId = (int) ($_POST['doc_id'] ?? 0);

if ($examId <= 0 || $docId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

$now = new DateTimeImmutable('now');
$stmt = db()->prepare('SELECT * FROM exams WHERE id = ?');
$stmt->execute([$examId]);
$exam = $stmt->fetch();

if (!$exam || !exam_is_active($exam, $now)) {
    http_response_code(403);
    echo json_encode(['error' => 'Exam not accepting submissions.']);
    exit;
}

$requiresPassword = !empty($exam['access_password_hash'] ?? '');
if ($requiresPassword) {
    $accessKey = 'exam_access_' . $examId;
    if (empty($_SESSION[$accessKey])) {
        http_response_code(403);
        echo json_encode(['error' => 'Exam password required.']);
        exit;
    }
}

$rosterEnabled = !empty($exam['student_roster_enabled']);
$rosterMode = ($exam['student_roster_mode'] ?? '') === 'password' ? 'password' : 'menu';
if ($rosterEnabled && $rosterMode === 'password') {
    $rosterSessionKey = 'exam_roster_student_' . $examId;
    if (empty($_SESSION[$rosterSessionKey])) {
        http_response_code(403);
        echo json_encode(['error' => 'Student password required.']);
        exit;
    }
}

$stmt = db()->prepare('SELECT * FROM exam_documents WHERE id = ? AND exam_id = ?');
$stmt->execute([$docId, $examId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    echo json_encode(['error' => 'Document not found.']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['error' => 'No file uploaded.']);
    exit;
}

$originalName = (string) $_FILES['file']['name'];
if (!empty($doc['require_file_type'])) {
    $allowed = parse_allowed_file_types($doc['allowed_file_types'] ?? '');
    if (count($allowed) > 0) {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid file type.']);
            exit;
        }
    }
}

if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to prepare upload directory.']);
    exit;
}

$token = bin2hex(random_bytes(16));
$tmpFile = $tmpDir . '/' . $token . '.upload';
$tmpMeta = $tmpDir . '/' . $token . '.json';

if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmpFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Upload failed.']);
    exit;
}

$metadata = [
    'exam_id' => $examId,
    'doc_id' => $docId,
    'original_name' => $originalName,
    'file_size' => (int) $_FILES['file']['size'],
    'uploaded_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
];

file_put_contents($tmpMeta, json_encode($metadata, JSON_PRETTY_PRINT));

$_SESSION['pending_upload_tokens_' . $examId][$docId] = $token;
$_SESSION['pending_upload_names_' . $examId][$docId] = $originalName;

echo json_encode([
    'token' => $token,
    'original_name' => $originalName,
]);
