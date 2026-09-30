<?php
// public_html/owlraid/donate_to_guild.php

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
    $amount = (int)($input['amount'] ?? 0);

    if ($wallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        throw new Exception('Invalid wallet');
    }
    if ($amount < 100) {
        throw new Exception('Minimum donate is 100 feathers');
    }

    $db = getDB();
    $db->beginTransaction();

    $stmt = $db->prepare('SELECT guild_id FROM guild_members WHERE wallet = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$wallet]);
    $mem = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mem) {
        $db->rollBack();
        throw new Exception('Not in a guild');
    }
    $guildId = (int)$mem['guild_id'];

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
    if ($feathers < $amount) {
        $db->rollBack();
        throw new Exception('Not enough feathers');
    }

    $game['feathers'] = $feathers - $amount;

    $stmt = $db->prepare('UPDATE game_saves SET game_data = ?, last_save = NOW() WHERE wallet = ?');
    $stmt->execute([json_encode($game, JSON_UNESCAPED_UNICODE), $wallet]);

    $stmt = $db->prepare('UPDATE guild_members SET contribution = contribution + ? WHERE wallet = ?');
    $stmt->execute([$amount, $wallet]);

    $stmt = $db->prepare('
        UPDATE guilds
        SET total_contribution = total_contribution + ?,
            xp = xp + ?
        WHERE id = ?
    ');
    $stmt->execute([$amount, $amount, $guildId]);

    // Level up: need = 5000 * level * level
    $stmt = $db->prepare('SELECT level, xp FROM guilds WHERE id = ? LIMIT 1');
    $stmt->execute([$guildId]);
    $g = $stmt->fetch(PDO::FETCH_ASSOC);
    $level = max(1, (int)$g['level']);
    $xp = (int)$g['xp'];
    while ($level < 50) {
        $need = 5000 * $level * $level;
        if ($xp < $need) {
            break;
        }
        $xp -= $need;
        $level++;
    }
    $stmt = $db->prepare('UPDATE guilds SET level = ?, xp = ? WHERE id = ?');
    $stmt->execute([$level, $xp, $guildId]);

    $db->commit();

    echo json_encode([
        'success'  => true,
        'feathers' => $game['feathers'],
        'donated'  => $amount,
        'level'    => $level,
        'xp'       => $xp,
    ]);

} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}