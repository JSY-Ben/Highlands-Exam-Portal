<?php

declare(strict_types=1);

function entra_config(): array
{
    $config = require __DIR__ . '/../config.php';
    if (empty($config['entra'])) {
        throw new RuntimeException('Entra config missing.');
    }
    return $config['entra'];
}

function entra_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function entra_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function entra_base64url_decode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function entra_build_authorize_url(string $returnTo): string
{
    $config = entra_config();

    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));
    $codeVerifier = entra_base64url_encode(random_bytes(32));
    $codeChallenge = entra_base64url_encode(hash('sha256', $codeVerifier, true));

    $_SESSION['entra_state'] = $state;
    $_SESSION['entra_nonce'] = $nonce;
    $_SESSION['entra_code_verifier'] = $codeVerifier;
    $_SESSION['entra_return_to'] = $returnTo;

    $params = [
        'client_id' => $config['client_id'],
        'response_type' => 'code',
        'redirect_uri' => $config['redirect_uri'],
        'response_mode' => 'query',
        'scope' => $config['scope'],
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => 'S256',
    ];

    return sprintf(
        'https://login.microsoftonline.com/%s/oauth2/v2.0/authorize?%s',
        rawurlencode($config['tenant_id']),
        http_build_query($params)
    );
}

function entra_exchange_code(string $code): array
{
    $config = entra_config();
    $tokenUrl = sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', rawurlencode($config['tenant_id']));

    $postData = http_build_query([
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $config['redirect_uri'],
        'code_verifier' => $_SESSION['entra_code_verifier'] ?? '',
        'scope' => $config['scope'],
    ]);

    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new RuntimeException('Token request failed.');
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($status !== 200 || !is_array($data)) {
        throw new RuntimeException('Invalid token response.');
    }

    return $data;
}

function entra_fetch_jwks(): array
{
    $config = entra_config();
    $cacheFile = sys_get_temp_dir() . '/entra_jwks_' . md5($config['tenant_id']) . '.json';

    if (is_file($cacheFile) && filemtime($cacheFile) > time() - 3600) {
        $raw = file_get_contents($cacheFile);
        $cached = $raw ? json_decode($raw, true) : null;
        if (is_array($cached)) {
            return $cached;
        }
    }

    $jwksUrl = sprintf('https://login.microsoftonline.com/%s/discovery/v2.0/keys', rawurlencode($config['tenant_id']));
    $ch = curl_init($jwksUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('Failed to fetch JWKS.');
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
        throw new RuntimeException('Failed to fetch JWKS.');
    }

    file_put_contents($cacheFile, $raw);
    $jwks = json_decode($raw, true);
    if (!is_array($jwks)) {
        throw new RuntimeException('Invalid JWKS response.');
    }

    return $jwks;
}

function entra_asn1_length(int $length): string
{
    if ($length <= 0x7f) {
        return chr($length);
    }
    $temp = ltrim(pack('N', $length), "\x00");
    return chr(0x80 | strlen($temp)) . $temp;
}

function entra_asn1_integer(string $value): string
{
    if (ord($value[0]) > 0x7f) {
        $value = "\x00" . $value;
    }
    return "\x02" . entra_asn1_length(strlen($value)) . $value;
}

function entra_jwk_to_pem(array $key): string
{
    $modulus = entra_base64url_decode($key['n']);
    $exponent = entra_base64url_decode($key['e']);
    $rsaKey = "\x30" . entra_asn1_length(strlen(entra_asn1_integer($modulus)) + strlen(entra_asn1_integer($exponent)))
        . entra_asn1_integer($modulus)
        . entra_asn1_integer($exponent);

    $bitString = "\x03" . entra_asn1_length(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
    $algId = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $spki = "\x30" . entra_asn1_length(strlen($algId) + strlen($bitString)) . $algId . $bitString;

    $pem = "-----BEGIN PUBLIC KEY-----\n";
    $pem .= chunk_split(base64_encode($spki), 64, "\n");
    $pem .= "-----END PUBLIC KEY-----\n";
    return $pem;
}

function entra_verify_id_token(string $jwt): array
{
    $config = entra_config();

    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        throw new RuntimeException('Invalid token format.');
    }

    [$headerB64, $payloadB64, $signatureB64] = $parts;
    $header = json_decode(entra_base64url_decode($headerB64), true);
    $payload = json_decode(entra_base64url_decode($payloadB64), true);

    if (!is_array($header) || !is_array($payload)) {
        throw new RuntimeException('Invalid token encoding.');
    }

    $jwks = entra_fetch_jwks();
    $kid = $header['kid'] ?? '';
    $key = null;
    foreach ($jwks['keys'] ?? [] as $entry) {
        if (($entry['kid'] ?? '') === $kid) {
            $key = $entry;
            break;
        }
    }

    if (!$key || empty($key['n']) || empty($key['e'])) {
        throw new RuntimeException('Signing key not found.');
    }

    $publicKey = openssl_pkey_get_public(entra_jwk_to_pem($key));

    if (!$publicKey) {
        throw new RuntimeException('Invalid signing key.');
    }

    $signature = entra_base64url_decode($signatureB64);
    $data = $headerB64 . '.' . $payloadB64;
    $ok = openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) {
        throw new RuntimeException('Signature verification failed.');
    }

    $issuer = sprintf('https://login.microsoftonline.com/%s/v2.0', $config['tenant_id']);
    if (($payload['iss'] ?? '') !== $issuer) {
        throw new RuntimeException('Invalid issuer.');
    }

    if (($payload['aud'] ?? '') !== $config['client_id']) {
        throw new RuntimeException('Invalid audience.');
    }

    $now = time();
    if (isset($payload['nbf']) && $now < (int) $payload['nbf']) {
        throw new RuntimeException('Token not yet valid.');
    }
    if (isset($payload['exp']) && $now > (int) $payload['exp']) {
        throw new RuntimeException('Token expired.');
    }

    $nonce = $_SESSION['entra_nonce'] ?? '';
    if (($payload['nonce'] ?? '') !== $nonce) {
        throw new RuntimeException('Invalid nonce.');
    }

    return $payload;
}

function entra_current_user(): ?array
{
    return $_SESSION['entra_user'] ?? null;
}

function entra_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
