<?php
// public_html/owlraid/set_guild_role.php

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

const MAX_OFFICERS = 3;

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('Invalid JSON');
    }

    $wallet       = trim((string)($input['wallet'] ?? ''));
    $targetWallet = trim((string)($input['target_wallet'] ?? ''));
    $newRole      = trim((string)($input['role'] ?? ''));

    if ($wallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        throw new Exception('Invalid wallet');
    }
    if ($targetWallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $targetWallet)) {
        throw new Exception('Invalid target wallet');
    }
    if (!in_array($newRole, ['officer', 'member'], true)) {
        throw new Exception('Role must be officer or member');
    }
    if (strcasecmp($wallet, $targetWallet) === 0) {
        throw new Exception('Cannot change your own role');
    }

    $db = getDB();
    $db->beginTransaction();

    // Only leader
    $stmt = $db->prepare("
        SELECT guild_id, role
        FROM guild_members
        WHERE wallet = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$wallet]);
    $actor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$actor || $actor['role'] !== 'leader') {
        $db->rollBack();
        throw new Exception('Only the leader can change roles');
    }

    $guildId = (int)$actor['guild_id'];

    $stmt = $db->prepare("
        SELECT wallet, role
        FROM guild_members
        WHERE guild_id = ? AND wallet = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$guildId, $targetWallet]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        $db->rollBack();
        throw new Exception('Player not in your guild');
    }
    if ($target['role'] === 'leader') {
        $db->rollBack();
        throw new Exception('Cannot change leader role');
    }
    if ($target['role'] === $newRole) {
        $db->rollBack();
        throw new Exception('Already ' . $newRole);
    }

    if ($newRole === 'officer') {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM guild_members
            WHERE guild_id = ? AND role = 'officer'
        ");
        $stmt->execute([$guildId]);
        $officerCount = (int)$stmt->fetchColumn();
        if ($officerCount >= MAX_OFFICERS) {
            $db->rollBack();
            throw new Exception('Max ' . MAX_OFFICERS . ' officers');
        }
    }

    $stmt = $db->prepare("
        UPDATE guild_members SET role = ? WHERE guild_id = ? AND wallet = ?
    ");
    $stmt->execute([$newRole, $guildId, $targetWallet]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Role updated',
        'role'    => $newRole,
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