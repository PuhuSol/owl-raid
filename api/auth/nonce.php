<?php
// public_html/owlraid/api/auth/nonce.php

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

// Find auth.php
$possiblePaths = [
    dirname(__DIR__, 4) . '/app/auth.php',
    dirname(__DIR__, 3) . '/../../app/auth.php',
    $_SERVER['DOCUMENT_ROOT'] . '/../app/auth.php',
    '/home/' . get_current_user() . '/app/auth.php',
];

$authPath = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $authPath = $path;
        break;
    }
}

if (!$authPath) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'auth.php not found',
        'tried'   => $possiblePaths,
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? null,
        'current_dir'   => __DIR__
    ]);
    exit;
}

require_once $authPath;

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['wallet']) || !is_string($input['wallet'])) {
        throw new Exception('Wallet address is required');
    }

    $wallet = trim($input['wallet']);

    // Basic Solana wallet validation
    if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        throw new Exception('Invalid wallet address');
    }

    $nonce = createNonce($wallet);

    echo json_encode([
        'success' => true,
        'nonce'   => $nonce,
        'message' => 'Please sign this message with your wallet'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}