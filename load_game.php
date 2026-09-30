<?php
// public_html/owlraid/load_game.php

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
    echo json_encode(['success' => false, 'error' => 'Config not found']);
    exit;
}

require_once $configPath;
require_once dirname($configPath) . '/database.php';
require_once __DIR__ . '/require_session.php';

try {
    $wallet = trim($_GET['wallet'] ?? '');

    if ($wallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        echo json_encode(['success' => false, 'error' => 'Invalid wallet']);
        exit;
    }
    $wallet = assertSameWallet($wallet);

    $db = getDB();

    // 1. Payment check
    $stmt = $db->prepare("SELECT wallet FROM paid_wallets WHERE wallet = ? LIMIT 1");
    $stmt->execute([$wallet]);
    $paid = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paid) {
        echo json_encode([
            'success' => false,
            'error'   => 'Payment required',
            'paid'    => false
        ]);
        exit;
    }

    // 2. Load save
    $stmt = $db->prepare("SELECT game_data, last_save, username, referred_by FROM game_saves WHERE wallet = ? LIMIT 1");
    $stmt->execute([$wallet]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $game = json_decode($row['game_data'] ?? '{}', true);
        if (!is_array($game)) {
            $game = [];
        }

        $game['username']    = $row['username'] ?? null;
        $game['referred_by'] = $row['referred_by'] ?? null;

        // Ensure inventory exists
        if (!isset($game['inventory']) || !is_array($game['inventory'])) {
            $game['inventory'] = [
                'weapon' => ['level' => 0],
                'armor'  => ['level' => 0],
                'shield' => ['level' => 0],
                'helmet' => ['level' => 0]
            ];
        }

        echo json_encode([
            'success'   => true,
            'game'      => $game,
            'last_save' => $row['last_save'],
            'username'  => $row['username'],
            'paid'      => true
        ]);
    } else {
        // Paid but no save yet
        echo json_encode([
            'success' => true,
            'game'    => null,
            'paid'    => true
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error'
    ]);
}