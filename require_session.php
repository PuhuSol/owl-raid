<?php
declare(strict_types=1);

$authCandidates = [
    dirname(__DIR__, 2) . '/app/auth.php',
    dirname(__DIR__, 1) . '/../app/auth.php',
    $_SERVER['DOCUMENT_ROOT'] . '/../app/auth.php',
    '/home/' . get_current_user() . '/app/auth.php',
];

$authPath = null;
foreach ($authCandidates as $path) {
    if (file_exists($path)) {
        $authPath = $path;
        break;
    }
}

if (!$authPath) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'auth.php not found']);
    exit;
}

require_once $authPath;

function requireSessionWallet(): string
{
    $wallet = getLoggedInWallet();
    if ($wallet === null || $wallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    return $wallet;
}

function assertSameWallet(string $provided): string
{
    $sessionWallet = requireSessionWallet();
    $provided = trim($provided);
    if ($provided !== '' && $provided !== $sessionWallet) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Wallet mismatch']);
        exit;
    }
    return $sessionWallet;
}