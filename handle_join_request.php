<?php
// public_html/owlraid/handle_join_request.php

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

    $wallet    = trim((string)($input['wallet'] ?? ''));
    $requestId = (int)($input['request_id'] ?? 0);
    $action    = trim((string)($input['action'] ?? ''));

    if ($wallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        throw new Exception('Invalid wallet');
    }
    if ($requestId < 1) {
        throw new Exception('Invalid request_id');
    }
    if (!in_array($action, ['accept', 'reject'], true)) {
        throw new Exception('Invalid action');
    }

    $db = getDB();
    $db->beginTransaction();

    // Bu wallet leader veya officer mi?
    $stmt = $db->prepare("
        SELECT gm.guild_id, gm.role
        FROM guild_members gm
        WHERE gm.wallet = ? AND gm.role IN ('leader', 'officer')
        LIMIT 1
    ");
    $stmt->execute([$wallet]);
    $me = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$me) {
        $db->rollBack();
        throw new Exception('Not allowed');
    }

    $stmt = $db->prepare("
        SELECT id, guild_id, wallet, status
        FROM guild_join_requests
        WHERE id = ? AND status = 'pending'
        LIMIT 1 FOR UPDATE
    ");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req || (int)$req['guild_id'] !== (int)$me['guild_id']) {
        $db->rollBack();
        throw new Exception('Request not found');
    }

    if ($action === 'reject') {
        $stmt = $db->prepare("UPDATE guild_join_requests SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$requestId]);
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Request rejected']);
        exit;
    }

    // accept
    $stmt = $db->prepare('SELECT id FROM guild_members WHERE wallet = ? LIMIT 1');
    $stmt->execute([$req['wallet']]);
    if ($stmt->fetch()) {
        $stmt = $db->prepare("UPDATE guild_join_requests SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$requestId]);
        $db->commit();
        throw new Exception('Player already in a guild');
    }

    $stmt = $db->prepare('SELECT member_limit FROM guilds WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([(int)$me['guild_id']]);
    $guild = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('SELECT COUNT(*) FROM guild_members WHERE guild_id = ?');
    $stmt->execute([(int)$me['guild_id']]);
    $count = (int)$stmt->fetchColumn();
    if ($count >= (int)$guild['member_limit']) {
        $db->rollBack();
        throw new Exception('Guild is full');
    }

    $stmt = $db->prepare("
        INSERT INTO guild_members (guild_id, wallet, role, contribution)
        VALUES (?, ?, 'member', 0)
    ");
    $stmt->execute([(int)$me['guild_id'], $req['wallet']]);

    $stmt = $db->prepare("UPDATE guild_join_requests SET status = 'accepted' WHERE id = ?");
    $stmt->execute([$requestId]);

    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Request accepted']);

} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}