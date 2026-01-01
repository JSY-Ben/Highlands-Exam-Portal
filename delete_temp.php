<?php

declare(strict_types=1);

require __DIR__ . '/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');
$tmpDir = $uploadsDir . '/tmp';

$token = trim((string) ($_POST['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid token.']);
    exit;
}

$tmpFile = $tmpDir . '/' . $token . '.upload';
$tmpMeta = $tmpDir . '/' . $token . '.json';

if (is_file($tmpFile)) {
    @unlink($tmpFile);
}
if (is_file($tmpMeta)) {
    @unlink($tmpMeta);
}

foreach (array_keys($_SESSION) as $key) {
    if (strpos($key, 'pending_upload_tokens_') !== 0 && strpos($key, 'pending_upload_names_') !== 0) {
        continue;
    }
    if (!is_array($_SESSION[$key])) {
        continue;
    }
    foreach ($_SESSION[$key] as $docId => $value) {
        if ((string) $value === $token) {
            unset($_SESSION[$key][$docId]);
        }
    }
}

echo json_encode(['status' => 'ok']);
