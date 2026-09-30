<?php
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

function gearPrice(?array $item): int
{
    $r = (string)($item['rarity'] ?? 'common');
    if ($r === 'legendary') return 2500;
    if ($r === 'epic') return 800;
    if ($r === 'rare') return 200;
    return 50;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
        exit;
    }

    $wallet = trim((string)($data['wallet'] ?? ''));
    $itemId = trim((string)($data['itemId'] ?? ''));
    $source = trim((string)($data['source'] ?? 'bag'));

    if ($source !== 'depo') {
        $source = 'bag';
    }

    if ($wallet === '' || $itemId === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
        exit;
    }
    $wallet = assertSameWallet($wallet);

    $db = getDB();
    $stmt = $db->prepare('SELECT wallet FROM paid_wallets WHERE wallet = ? LIMIT 1');
    $stmt->execute([$wallet]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Payment required']);
        exit;
    }

    $db->beginTransaction();
    $stmt = $db->prepare('SELECT game_data FROM game_saves WHERE wallet = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$wallet]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'No save']);
        exit;
    }

    $game = json_decode($row['game_data'] ?? '{}', true);
    if (!is_array($game)) {
        $game = [];
    }
    if (!isset($game['gear']) || !is_array($game['gear'])) {
        $game['gear'] = ['equipped' => [], 'bag' => [], 'depo' => []];
    }
    if (!isset($game['gear']['bag']) || !is_array($game['gear']['bag'])) {
        $game['gear']['bag'] = [];
    }
    if (!isset($game['gear']['depo']) || !is_array($game['gear']['depo'])) {
        $game['gear']['depo'] = [];
    }

    $list = $game['gear'][$source];
    $idx = -1;
    foreach ($list as $i => $it) {
        if (is_array($it) && (string)($it['id'] ?? '') === $itemId) {
            $idx = (int)$i;
            break;
        }
    }
    if ($idx < 0) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $source === 'depo' ? 'Item not in depo' : 'Item not in bag']);
        exit;
    }

    $item = $list[$idx];
    $price = gearPrice(is_array($item) ? $item : null);
    array_splice($list, $idx, 1);
    $game['gear'][$source] = array_values($list);

    $game['feathers'] = floatval($game['feathers'] ?? 0) + $price;
    $game['totalEarned'] = floatval($game['totalEarned'] ?? 0) + $price;

    $stmt = $db->prepare('UPDATE game_saves SET game_data = ?, last_save = NOW() WHERE wallet = ?');
    $stmt->execute([json_encode($game, JSON_UNESCAPED_UNICODE), $wallet]);
    $db->commit();

    echo json_encode([
        'success'     => true,
        'price'       => $price,
        'feathers'    => $game['feathers'],
        'totalEarned' => $game['totalEarned'],
        'itemId'      => $itemId,
        'source'      => $source,
        'gear'        => $game['gear'],
    ]);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error',
    ]);
}