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

function calcCombatPowerFromGame(array $game): int
{
    $inv = $game['inventory'] ?? [];
    $w = (int)($inv['weapon']['level'] ?? 0);
    $a = (int)($inv['armor']['level'] ?? 0);
    $s = (int)($inv['shield']['level'] ?? 0);
    $h = (int)($inv['helmet']['level'] ?? 0);

    $attack  = 8 + ($w * 4);
    $hp      = 80 + ($a * 18);
    $defense = (int)floor(($a * 2.5) + ($s * 5) + ($h * 3.5));
    $crit    = min(40.0, 8 + ($w * 0.6) + ((float)($game['critChance'] ?? 0) * 50));
    $block   = min(20.0, $s * 1.0);

    $eq = $game['gear']['equipped'] ?? [];
    if (is_array($eq)) {
        foreach ($eq as $item) {
            if (!is_array($item) || empty($item['bonuses'])) continue;
            $b = $item['bonuses'];
            $attack  += (int)($b['attack'] ?? 0);
            $hp      += (int)($b['hp'] ?? 0);
            $defense += (int)($b['defense'] ?? 0);
            $crit    += (float)($b['crit'] ?? 0);
            $block   += (float)($b['block'] ?? 0);
        }
    }
    $crit  = max(0.0, min(40.0, $crit));
    $block = max(0.0, min(25.0, $block));

    return (int)floor(($attack * 3) + ($hp * 0.4) + ($defense * 2.5) + ($crit * 4) + ($block * 6));
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $wallet = trim((string)($data['wallet'] ?? ''));
    if ($wallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
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

    $stmt = $db->prepare('SELECT game_data FROM game_saves WHERE wallet = ? LIMIT 1');
    $stmt->execute([$wallet]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'No save']);
        exit;
    }

    $game = json_decode($row['game_data'] ?? '{}', true);
    if (!is_array($game)) $game = [];

    $totalEarned = (int)floor((float)($game['totalEarned'] ?? 0));
    $prestige    = (int)($game['prestigeLevel'] ?? 0);
    $feathers    = (int)floor((float)($game['feathers'] ?? 0));
    $cp          = calcCombatPowerFromGame($game);

    try {
        $stmt = $db->prepare('
            INSERT INTO owl_raid_leaderboard (wallet, total_earned, prestige_level, feathers, combat_power)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_earned = VALUES(total_earned),
                prestige_level = VALUES(prestige_level),
                feathers = VALUES(feathers),
                combat_power = VALUES(combat_power),
                last_update = CURRENT_TIMESTAMP
        ');
        $stmt->execute([$wallet, $totalEarned, $prestige, $feathers, $cp]);
    } catch (Exception $e) {
        $stmt = $db->prepare('
            INSERT INTO owl_raid_leaderboard (wallet, total_earned, prestige_level, feathers)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_earned = VALUES(total_earned),
                prestige_level = VALUES(prestige_level),
                feathers = VALUES(feathers),
                last_update = CURRENT_TIMESTAMP
        ');
        $stmt->execute([$wallet, $totalEarned, $prestige, $feathers]);
    }

    echo json_encode(['success' => true, 'combat_power' => $cp]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error'
    ]);
}