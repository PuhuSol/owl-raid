<?php
// Helius RPC proxy - keeps API key off the frontend

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://puhucoin.com');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$possiblePaths = [
    dirname(__DIR__, 2) . '/app/config.php',
    dirname(__DIR__, 1) . '/../app/config.php',
    $_SERVER['DOCUMENT_ROOT'] . '/../app/config.php',
    '/home/' . get_current_user() . '/app/config.php',
];

$configPath = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $configPath = $path;
        break;
    }
}

if (!$configPath) {
    http_response_code(500);
    echo json_encode(['error' => 'Config not found']);
    exit;
}

require_once $configPath;

if (!defined('HELIUS_API_KEY') || HELIUS_API_KEY === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Helius API key not configured']);
    exit;
}

$input = file_get_contents('php://input');
if ($input === false || trim($input) === '') {
    http_response_code(400);
    echo json_encode([
        'jsonrpc' => '2.0',
        'error'   => ['code' => -32600, 'message' => 'Invalid request'],
        'id'      => null
    ]);
    exit;
}

// Optional: basic size limit
if (strlen($input) > 100000) {
    http_response_code(413);
    echo json_encode(['error' => 'Payload too large']);
    exit;
}

$url = 'https://mainnet.helius-rpc.com/?api-key=' . urlencode(HELIUS_API_KEY);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $input,
    CURLOPT_TIMEOUT        => 25
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($httpCode ?: 200);
echo $response !== false && $response !== ''
    ? $response
    : json_encode(['error' => 'Empty response from Helius']);