<?php
// public_html/owlraid/move_gear.php

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
require_once __DIR__ . '/require_session.php';

const BAG_CAP = 8;
const DEPO_CAP = 24;

function gearList(array &$game, string $key): array
{
    if (!isset($game['gear']) || !is_array($game['gear'])) {
        $game['gear'] = [];
    }
    if (!isset($game['gear'][$key]) || !is_array($game['gear'][$key])) {
        $game['gear'][$key] = [];
    }
    $game['gear'][$key] = array_values($game['gear'][$key]);
    return $game['gear'][$key];
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
        exit;
    }

    $wallet = trim((string)($data['wallet'] ?? ''));
    $from   = trim((string)($data['from'] ?? ''));
    $to     = trim((string)($data['to'] ?? ''));
    $index  = (int)($data['index'] ?? -1);

    if ($wallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        echo json_encode(['success' => false, 'error' => 'Invalid wallet']);
        exit;
    }
    $wallet = assertSameWallet($wallet);

    if (!in_array($from, ['bag', 'depo'], true) || !in_array($to, ['bag', 'depo'], true) || $from === $to) {
        echo json_encode(['success' => false, 'error' => 'Invalid move']);
        exit;
    }

    $db = getDB();
    $db->beginTransaction();

    $stmt = $db->prepare('SELECT game_data FROM game_saves WHERE wallet = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$wallet]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Player not found']);
        exit;
    }

    $game = json_decode($row['game_data'] ?? '{}', true);
    if (!is_array($game)) {
        $game = [];
    }

    $fromList = gearList($game, $from);
    $toList   = gearList($game, $to);

    if ($index < 0 || $index >= count($fromList) || !is_array($fromList[$index])) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Item not found']);
        exit;
    }

    $cap = $to === 'bag' ? BAG_CAP : DEPO_CAP;
    if (count($toList) >= $cap) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $to === 'bag' ? 'Bag full' : 'Depo full']);
        exit;
    }

    $item = $fromList[$index];
    array_splice($fromList, $index, 1);
    $toList[] = $item;

    $game['gear'][$from] = array_values($fromList);
    $game['gear'][$to]   = array_values($toList);

    $upd = $db->prepare('UPDATE game_saves SET game_data = ?, last_save = NOW() WHERE wallet = ?');
    $upd->execute([json_encode($game, JSON_UNESCAPED_UNICODE), $wallet]);
    $db->commit();

    echo json_encode([
        'success' => true,
        'gear'    => $game['gear'],
    ]);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}