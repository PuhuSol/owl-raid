<?php
// public_html/owlraid/daily_login.php

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

function dailyReward(int $streak): int
{
    if ($streak <= 1) return 100;
    if ($streak === 2) return 150;
    if ($streak === 3) return 200;
    if ($streak === 4) return 250;
    if ($streak === 5) return 300;
    if ($streak === 6) return 400;
    return 600;
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
    $wallet = trim($data['wallet'] ?? '');

    if ($wallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        echo json_encode(['success' => false, 'error' => 'Invalid wallet']);
        exit;
    }

    $wallet = assertSameWallet($wallet);

    $db = getDB();

    $stmt = $db->prepare("SELECT wallet FROM paid_wallets WHERE wallet = ? LIMIT 1");
    $stmt->execute([$wallet]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Payment required']);
        exit;
    }

    $db->beginTransaction();

    $stmt = $db->prepare("SELECT game_data FROM game_saves WHERE wallet = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$wallet]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Game not found']);
        exit;
    }

    $game = json_decode($row['game_data'] ?? '{}', true);
    if (!is_array($game)) {
        $game = [];
    }

    $today = date('Y-m-d');
    $lastLogin = $game['dailyLogin']['lastDate'] ?? '';
    $streak = intval($game['dailyLogin']['streak'] ?? 0);

    if ($lastLogin === $today) {
        $db->rollBack();
        echo json_encode([
            'success' => false,
            'error' => 'Already claimed today',
            'alreadyClaimed' => true,
            'streak' => $streak,
            'nextReward' => dailyReward($streak + 1)
        ]);
        exit;
    }

    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($lastLogin === $yesterday) {
        $streak = $streak + 1;
    } else {
        $streak = 1;
    }

    $reward = dailyReward($streak);

    $game['feathers'] = floatval($game['feathers'] ?? 0) + $reward;
    $game['totalEarned'] = floatval($game['totalEarned'] ?? 0) + $reward;
    $game['dailyLogin'] = [
        'lastDate' => $today,
        'streak' => $streak
    ];

    $stmt = $db->prepare("UPDATE game_saves SET game_data = ?, last_save = NOW() WHERE wallet = ?");
    $stmt->execute([json_encode($game, JSON_UNESCAPED_UNICODE), $wallet]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'reward' => $reward,
        'streak' => $streak,
        'feathers' => $game['feathers'],
        'totalEarned' => $game['totalEarned']
    ]);

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