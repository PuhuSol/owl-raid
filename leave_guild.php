<?php
// public_html/owlraid/leave_guild.php

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

    $wallet = trim((string)($input['wallet'] ?? ''));
    if ($wallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        throw new Exception('Invalid wallet');
    }

    $db = getDB();
    $db->beginTransaction();

    $stmt = $db->prepare('SELECT id, guild_id, role FROM guild_members WHERE wallet = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$wallet]);
    $mem = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mem) {
        $db->rollBack();
        throw new Exception('Not in a guild');
    }

    $guildId = (int)$mem['guild_id'];

    if ($mem['role'] === 'leader') {
        $stmt = $db->prepare("
            SELECT wallet FROM guild_members
            WHERE guild_id = ? AND wallet != ?
            ORDER BY FIELD(role, 'officer', 'member'), contribution DESC
            LIMIT 1
        ");
        $stmt->execute([$guildId, $wallet]);
        $next = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($next) {
            $stmt = $db->prepare("UPDATE guild_members SET role = 'leader' WHERE wallet = ?");
            $stmt->execute([$next['wallet']]);
            $stmt = $db->prepare('UPDATE guilds SET leader_wallet = ? WHERE id = ?');
            $stmt->execute([$next['wallet'], $guildId]);

            $stmt = $db->prepare('DELETE FROM guild_members WHERE wallet = ?');
            $stmt->execute([$wallet]);
        } else {
            // Son üye lider → guild sil (CASCADE members + requests)
            $stmt = $db->prepare('DELETE FROM guilds WHERE id = ?');
            $stmt->execute([$guildId]);
        }
    } else {
        $stmt = $db->prepare('DELETE FROM guild_members WHERE wallet = ?');
        $stmt->execute([$wallet]);
    }

    $stmt = $db->prepare("DELETE FROM guild_join_requests WHERE wallet = ? AND status = 'pending'");
    $stmt->execute([$wallet]);

    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Left guild']);

} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}