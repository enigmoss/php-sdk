<?php

declare(strict_types=1);

// Router script for `php -S`: re-checks every request with the API signature algorithm
// ("{ts}\n{project}\n{METHOD}\n{request uri}\n{raw body}", HMAC-SHA256, hex)
// and echoes what arrived on the wire.

$server = static fn(string $key): string => isset($_SERVER[$key]) && is_string($_SERVER[$key]) ? $_SERVER[$key] : '';

$uri = $server('REQUEST_URI');
$method = $server('REQUEST_METHOD');
$body = (string) file_get_contents('php://input');
$project = $server('HTTP_X_PROJECT_UUID');
$timestamp = $server('HTTP_X_TIMESTAMP');
$signature = strtolower($server('HTTP_X_SIGNATURE'));

if (str_starts_with($uri, '/api/v4/redirect')) {
    header('Location: /api/v4/project', true, 302);
    exit;
}

$canonical = $timestamp . "\n" . $project . "\n" . strtoupper($method) . "\n" . $uri . "\n" . $body;
$valid = false;
foreach (['test-payment-secret', 'test-payout-secret'] as $secret) {
    $valid = $valid || hash_equals(hash_hmac('sha256', $canonical, $secret), $signature);
}

header('Content-Type: application/json');
header('X-Request-Id: 01JECHO0000000000000000000');
echo json_encode(['data' => [
    'method' => $method,
    'uri' => $uri,
    'body' => $body,
    'signature_valid' => $valid,
    'content_type' => $server('CONTENT_TYPE') === '' ? null : $server('CONTENT_TYPE'),
    'idempotency_key' => $server('HTTP_IDEMPOTENCY_KEY') === '' ? null : $server('HTTP_IDEMPOTENCY_KEY'),
    'user_agent' => $server('HTTP_USER_AGENT'),
    'fingerprint' => hash('sha256', $method . "\n" . $uri . "\n" . $body),
]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
