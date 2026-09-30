<?php
// public_html/owlraid/submit_quest.php

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

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $wallet    = trim($data['wallet'] ?? '');
    $code      = trim($data['code'] ?? '');
    $tweetUrl  = trim($data['tweet_url'] ?? '');

    if ($wallet === '' || $code === '' || $tweetUrl === '') {
        echo json_encode(['success' => false, 'error' => 'Missing data']);
        exit;
    }

    if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        echo json_encode(['success' => false, 'error' => 'Invalid wallet']);
        exit;
    }

    // Basic URL validation for non-FOLLOW quests
    if ($code !== 'FOLLOW') {
        if (
            strpos($tweetUrl, 'x.com/') === false &&
            strpos($tweetUrl, 'twitter.com/') === false
        ) {
            echo json_encode(['success' => false, 'error' => 'Invalid tweet URL']);
            exit;
        }
    }

    // Limit code length
    $code = substr($code, 0, 32);
    $tweetUrl = substr($tweetUrl, 0, 500);

    $db = getDB();

    if ($code === 'FOLLOW') {
        $stmt = $db->prepare("
            SELECT id FROM quest_submissions
            WHERE wallet = ? AND code = 'FOLLOW' AND status != 'rejected'
            LIMIT 1
        ");
        $stmt->execute([$wallet]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'You already submitted the Follow quest']);
            exit;
        }
    } else {
        $stmt = $db->prepare("
            SELECT submitted_at FROM quest_submissions
            WHERE wallet = ? AND code = ? AND status != 'rejected'
            ORDER BY submitted_at DESC
            LIMIT 1
        ");
        $stmt->execute([$wallet, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $diff = (time() - strtotime($row['submitted_at'])) / 60;
            if ($diff < 240) {
                $remaining = (int)ceil(240 - $diff);
                echo json_encode([
                    'success' => false,
                    'error' => "You must wait $remaining more minutes"
                ]);
                exit;
            }
        }
    }

    $stmt = $db->prepare("
        INSERT INTO quest_submissions (wallet, code, tweet_url, status)
        VALUES (?, ?, ?, 'pending')
    ");
    $stmt->execute([$wallet, $code, $tweetUrl]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error'
    ]);
}