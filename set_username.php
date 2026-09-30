<?php
// public_html/owlraid/set_username.php

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
    echo json_encode(['success' => false, 'error' => 'Config not found']);
    exit;
}

require_once $configPath;
require_once dirname($configPath) . '/database.php';
require_once __DIR__ . '/require_session.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $wallet   = trim($data['wallet'] ?? '');
    $username = trim($data['username'] ?? '');

    if ($wallet === '' || $username === '') {
        echo json_encode(['success' => false, 'error' => 'Missing data']);
        exit;
    }

    if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        echo json_encode(['success' => false, 'error' => 'Invalid wallet']);
        exit;
    }

    $wallet = assertSameWallet($wallet);

    if (strlen($username) < 3 || strlen($username) > 15) {
        echo json_encode(['success' => false, 'error' => 'Username must be 3-15 characters']);
        exit;
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        echo json_encode(['success' => false, 'error' => 'Only letters, numbers and underscore allowed']);
        exit;
    }

    $username = strtolower($username);
    $reserved = ['admin', 'owner', 'puhu', 'mod', 'moderator', 'support', 'system'];
    if (in_array($username, $reserved, true)) {
        echo json_encode(['success' => false, 'error' => 'Username not allowed']);
        exit;
    }

    $db = getDB();

    $stmt = $db->prepare("SELECT wallet FROM paid_wallets WHERE wallet = ? LIMIT 1");
    $stmt->execute([$wallet]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Payment required']);
        exit;
    }

    $db->beginTransaction();

    $stmt = $db->prepare("SELECT username FROM game_saves WHERE wallet = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$wallet]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['username'])) {
        $db->rollBack();
        echo json_encode([
            'success' => false,
            'error' => 'Username already set',
            'current_username' => $row['username']
        ]);
        exit;
    }

    $stmt = $db->prepare("
        SELECT wallet FROM game_saves
        WHERE LOWER(username) = ? AND username IS NOT NULL AND username != ''
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Username already taken']);
        exit;
    }

    if ($row) {
        $stmt = $db->prepare("UPDATE game_saves SET username = ? WHERE wallet = ?");
        $stmt->execute([$username, $wallet]);
    } else {
        $emptyGame = json_encode([
            'feathers' => 0,
            'totalEarned' => 0,
            'clickPower' => 1,
            'clickMult' => 1,
            'critChance' => 0,
            'auto1' => 0, 'auto2' => 0, 'auto3' => 0, 'auto4' => 0, 'auto5' => 0,
            'prestigeLevel' => 0,
            'prestigeMult' => 1,
            'selectedSkin' => 0,
            'achievements' => new stdClass(),
            'inventory' => [
                'weapon' => ['level' => 0],
                'armor'  => ['level' => 0],
                'shield' => ['level' => 0],
                'helmet' => ['level' => 0]
            ],
            'username' => $username
        ]);

        $stmt = $db->prepare("
            INSERT INTO game_saves (wallet, username, game_data, last_save)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$wallet, $username, $emptyGame]);
    }

    $db->commit();

    echo json_encode(['success' => true, 'username' => $username]);

} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error'
    ]);
}