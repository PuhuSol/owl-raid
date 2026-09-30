<?php
// public_html/owlraid/check_quest.php

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://puhucoin.com');
header('Access-Control-Allow-Credentials: true');

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
    echo json_encode(['canSubmit' => false, 'error' => 'Config not found']);
    exit;
}

require_once $configPath;
require_once dirname($configPath) . '/database.php';

try {
    $wallet = trim($_GET['wallet'] ?? '');

    if ($wallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        echo json_encode(['canSubmit' => false, 'error' => 'Invalid wallet']);
        exit;
    }

    $db = getDB();

    $stmt = $db->prepare("
        SELECT submitted_at
        FROM quest_submissions
        WHERE wallet = ?
        ORDER BY submitted_at DESC
        LIMIT 1
    ");
    $stmt->execute([$wallet]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode([
            'canSubmit' => true,
            'remainingMinutes' => 0
        ]);
        exit;
    }

    $lastTime = strtotime($row['submitted_at']);
    $now = time();
    $diffMinutes = ($now - $lastTime) / 60;
    $cooldownMinutes = 240; // 4 hours

    if ($diffMinutes >= $cooldownMinutes) {
        echo json_encode([
            'canSubmit' => true,
            'remainingMinutes' => 0
        ]);
    } else {
        echo json_encode([
            'canSubmit' => false,
            'remainingMinutes' => $cooldownMinutes - $diffMinutes
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'canSubmit' => false,
        'error' => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error'
    ]);
}