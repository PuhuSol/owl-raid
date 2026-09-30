<?php
// public_html/owlraid/log_action.php

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
    echo json_encode(['success' => false]);
    exit;
}

require_once $configPath;
require_once dirname($configPath) . '/database.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $wallet = trim($data['wallet'] ?? '');
    $action = trim($data['action'] ?? '');
    $value  = intval($data['value'] ?? 0);
    $extra  = substr(trim($data['extra'] ?? ''), 0, 100);

    if (
        $wallet === '' ||
        $action === '' ||
        !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)
    ) {
        echo json_encode(['success' => false]);
        exit;
    }

    // Whitelist actions
    $allowedActions = ['click', 'save', 'score_submit', 'upgrade', 'prestige', 'fight', 'quest'];
    if (!in_array($action, $allowedActions, true)) {
        $action = 'other';
    }

    $db = getDB();
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $db->prepare("
        INSERT INTO owl_raid_actions (wallet, action_type, value, extra, ip)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$wallet, $action, $value, $extra, $ip]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false]);
}