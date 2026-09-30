<?php
// public_html/owlraid/api/auth/verify.php

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

// Locate auth.php
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
        'tried'   => $possiblePaths
    ]);
    exit;
}

require_once $authPath;

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['wallet']) || empty($input['nonce']) || empty($input['signature'])) {
        throw new Exception('Wallet, nonce and signature are required');
    }

    $wallet    = trim($input['wallet']);
    $nonce     = trim($input['nonce']);
    $signature = trim($input['signature']);

    // 1. Verify nonce
    if (!verifyNonce($wallet, $nonce)) {
        throw new Exception('Invalid or expired nonce');
    }

    // 2. Verify Solana signature
    if (!verifySolanaSignature($wallet, $nonce, $signature)) {
        throw new Exception('Invalid signature');
    }

    // 3. Login user
    loginUser($wallet);

    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'wallet'  => $wallet
    ]);

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}