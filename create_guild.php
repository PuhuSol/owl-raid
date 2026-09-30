<?php
// public_html/owlraid/create_guild.php

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

const GUILD_CREATE_COST = 100000;

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

    $wallet   = trim((string)($input['wallet'] ?? ''));
    $name     = trim((string)($input['name'] ?? ''));
    $tag      = strtoupper(trim((string)($input['tag'] ?? '')));
    $joinType = trim((string)($input['join_type'] ?? 'open'));

    if ($wallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        throw new Exception('Invalid wallet');
    }
    if (strlen($name) < 3 || strlen($name) > 24) {
        throw new Exception('Name must be 3-24 characters');
    }
    if (!preg_match('/^[A-Z0-9]{3,5}$/', $tag)) {
        throw new Exception('Tag must be 3-5 letters/numbers');
    }
    if (!in_array($joinType, ['open', 'approval'], true)) {
        throw new Exception('Invalid join_type');
    }

    $db = getDB();
    $db->beginTransaction();

    // Paid?
    $stmt = $db->prepare('SELECT wallet FROM paid_wallets WHERE wallet = ? LIMIT 1');
    $stmt->execute([$wallet]);
    if (!$stmt->fetch()) {
        $db->rollBack();
        throw new Exception('Payment required');
    }

    // Already in a guild?
    $stmt = $db->prepare('SELECT id FROM guild_members WHERE wallet = ? LIMIT 1');
    $stmt->execute([$wallet]);
    if ($stmt->fetch()) {
        $db->rollBack();
        throw new Exception('Already in a guild');
    }

    // Unique name / tag
    $stmt = $db->prepare('SELECT id FROM guilds WHERE tag = ? OR name = ? LIMIT 1');
    $stmt->execute([$tag, $name]);
    if ($stmt->fetch()) {
        $db->rollBack();
        throw new Exception('Name or tag already taken');
    }

    // Game save
    $stmt = $db->prepare('SELECT game_data FROM game_saves WHERE wallet = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$wallet]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $db->rollBack();
        throw new Exception('Game save not found');
    }

    $game = json_decode($row['game_data'] ?? '{}', true);
    if (!is_array($game)) {
        $game = [];
    }

    $feathers = (float)($game['feathers'] ?? 0);
    if ($feathers < GUILD_CREATE_COST) {
        $db->rollBack();
        throw new Exception('Need 100,000 feathers');
    }

    $game['feathers'] = $feathers - GUILD_CREATE_COST;

    $stmt = $db->prepare('UPDATE game_saves SET game_data = ?, last_save = NOW() WHERE wallet = ?');
    $stmt->execute([json_encode($game, JSON_UNESCAPED_UNICODE), $wallet]);

    $stmt = $db->prepare('
        INSERT INTO guilds (name, tag, leader_wallet, join_type, level, xp, total_contribution, member_limit)
        VALUES (?, ?, ?, ?, 1, 0, 0, 20)
    ');
    $stmt->execute([$name, $tag, $wallet, $joinType]);
    $guildId = (int)$db->lastInsertId();

    $stmt = $db->prepare('
        INSERT INTO guild_members (guild_id, wallet, role, contribution)
        VALUES (?, ?, \'leader\', 0)
    ');
    $stmt->execute([$guildId, $wallet]);

    $db->commit();

    echo json_encode([
        'success'  => true,
        'guild_id' => $guildId,
        'feathers' => $game['feathers'],
        'message'  => 'Guild created',
    ]);

} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}