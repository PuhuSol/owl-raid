<?php
// public_html/owlraid/join_guild.php

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
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('Invalid JSON');
    }

    $wallet  = trim((string)($input['wallet'] ?? ''));
    $guildId = (int)($input['guild_id'] ?? 0);

    if ($wallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        throw new Exception('Invalid wallet');
    }
    if ($guildId < 1) {
        throw new Exception('Invalid guild_id');
    }

    $db = getDB();
    $db->beginTransaction();

    $stmt = $db->prepare('SELECT wallet FROM paid_wallets WHERE wallet = ? LIMIT 1');
    $stmt->execute([$wallet]);
    if (!$stmt->fetch()) {
        $db->rollBack();
        throw new Exception('Payment required');
    }

    $stmt = $db->prepare('SELECT id FROM guild_members WHERE wallet = ? LIMIT 1');
    $stmt->execute([$wallet]);
    if ($stmt->fetch()) {
        $db->rollBack();
        throw new Exception('Already in a guild');
    }

    $stmt = $db->prepare('SELECT id, join_type, member_limit FROM guilds WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$guildId]);
    $guild = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$guild) {
        $db->rollBack();
        throw new Exception('Guild not found');
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM guild_members WHERE guild_id = ?');
    $stmt->execute([$guildId]);
    $count = (int)$stmt->fetchColumn();
    if ($count >= (int)$guild['member_limit']) {
        $db->rollBack();
        throw new Exception('Guild is full');
    }

    if ($guild['join_type'] === 'open') {
        $stmt = $db->prepare("
            INSERT INTO guild_members (guild_id, wallet, role, contribution)
            VALUES (?, ?, 'member', 0)
        ");
        $stmt->execute([$guildId, $wallet]);

        // Eski pending varsa temizle
        $stmt = $db->prepare("DELETE FROM guild_join_requests WHERE guild_id = ? AND wallet = ?");
        $stmt->execute([$guildId, $wallet]);

        $db->commit();
        echo json_encode([
            'success' => true,
            'joined'  => true,
            'message' => 'Joined guild',
        ]);
        exit;
    }

    // approval
    $stmt = $db->prepare("
        SELECT id FROM guild_join_requests
        WHERE guild_id = ? AND wallet = ? AND status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$guildId, $wallet]);
    if ($stmt->fetch()) {
        $db->rollBack();
        throw new Exception('Request already pending');
    }

    $stmt = $db->prepare("
        INSERT INTO guild_join_requests (guild_id, wallet, status)
        VALUES (?, ?, 'pending')
    ");
    $stmt->execute([$guildId, $wallet]);

    $db->commit();
    echo json_encode([
        'success' => true,
        'joined'  => false,
        'message' => 'Join request sent',
    ]);

} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}