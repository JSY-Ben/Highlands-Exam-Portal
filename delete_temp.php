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

foreach ($_SESSION as $key => $value) {
    if (strpos($key, 'pending_upload_tokens_') !== 0 || !is_array($value)) {
        continue;
    }
    foreach ($value as $docId => $storedToken) {
        if ((string) $storedToken !== $token) {
            continue;
        }
        unset($_SESSION[$key][$docId]);
        $examSuffix = substr($key, strlen('pending_upload_tokens_'));
        $nameKey = 'pending_upload_names_' . $examSuffix;
        if (isset($_SESSION[$nameKey]) && is_array($_SESSION[$nameKey])) {
            unset($_SESSION[$nameKey][$docId]);
        }
    }
}

echo json_encode(['status' => 'ok']);
