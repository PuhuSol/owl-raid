<?php
// public_html/owlraid/submit_fight.php

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

const ARENA_CP_BAND = 0.15;
const ARENA_CP_WIDEN = 0.10;
const ARENA_CP_MAX_WIDEN = 0.50;

function arenaCpBounds(float $cp, float $band): array
{
    $cp = max(1.0, $cp);
    return [
        'min' => (int)floor($cp * (1 - $band)),
        'max' => (int)ceil($cp * (1 + $band)),
    ];
}

function arenaCpAllowed(float $myCp, float $oppCp): bool
{
    $band = ARENA_CP_BAND;
    while ($band <= ARENA_CP_MAX_WIDEN + 0.0001) {
        $b = arenaCpBounds($myCp, $band);
        if ($oppCp >= $b['min'] && $oppCp <= $b['max']) {
            return true;
        }
        $band += ARENA_CP_WIDEN;
    }
    return false;
}

function invLevel(array $game, string $type): int
{
    return max(0, (int)($game['inventory'][$type]['level'] ?? 0));
}

function getFullCombatStatsPhp(array $game): array
{
    $w = invLevel($game, 'weapon');
    $a = invLevel($game, 'armor');
    $s = invLevel($game, 'shield');
    $h = invLevel($game, 'helmet');
    $critChance = (float)($game['critChance'] ?? 0);

    $attack  = 8 + ($w * 4);
    $hp      = 80 + ($a * 18);
    $defense = (int)floor(($a * 2.5) + ($s * 5) + ($h * 3.5));
    $crit    = min(40.0, max(0.0, 8 + ($w * 0.6) + ($critChance * 50)));
    $block   = min(20.0, $s * 1.0);

    $eq = $game['gear']['equipped'] ?? [];
    if (is_array($eq)) {
        foreach ($eq as $item) {
            if (!is_array($item) || empty($item['bonuses'])) {
                continue;
            }
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

    $power = (int)floor(($attack * 3) + ($hp * 0.4) + ($defense * 2.5) + ($crit * 4) + ($block * 6));

    return [
        'attack'      => $attack,
        'hp'          => $hp,
        'defense'     => $defense,
        'crit'        => $crit,
        'block'       => $block,
        'power'       => $power,
        'prestige'    => (int)($game['prestigeLevel'] ?? 0),
        'totalEarned' => (float)($game['totalEarned'] ?? 0),
    ];
}

function calculateDamagePhp(array $attacker, array $defender): array
{
    $atk = (float)$attacker['attack'];
    $def = (float)$defender['defense'];
    $atkPower = max(1.0, (float)($attacker['power'] ?? 1));
    $defPower = max(1.0, (float)($defender['power'] ?? 1));
    $powerRatio = max(0.85, min(1.35, $atkPower / $defPower));

    $isCrit = (mt_rand() / mt_getrandmax()) < (((float)$attacker['crit']) / 100);
    $isBlock = (mt_rand() / mt_getrandmax()) < (((float)$defender['block']) / 100);

    $dmg = max(1, ($atk - ($def * 0.35)) * $powerRatio);
    if ($isCrit) $dmg *= 1.5;
    if ($isBlock) $dmg *= 0.5;
    $dmg *= (0.90 + (mt_rand() / mt_getrandmax()) * 0.20);

    return [
        'damage' => (int)max(1, round($dmg)),
        'crit'   => $isCrit,
        'block'  => $isBlock,
    ];
}

function simulateFightPhp(array $player, array $enemy): array
{
    $php = (int)$player['hp'];
    $ehp = (int)$enemy['hp'];
    $log = [];
    $turn = 1;

    $playerFirst = ((float)$player['power'] > (float)$enemy['power'])
        || (
            (float)$player['power'] === (float)$enemy['power']
            && (float)$player['attack'] >= (float)$enemy['attack']
        );

    if ($playerFirst) {
        $hit = calculateDamagePhp($player, $enemy);
        $ehp = max(0, $ehp - $hit['damage']);
        $log[] = ['turn' => $turn, 'attacker' => 'You', 'damage' => $hit['damage'], 'crit' => $hit['crit'], 'block' => $hit['block'], 'remaining' => $ehp];
        if ($ehp <= 0) {
            return ['winner' => 'player', 'result' => 'win', 'log' => $log];
        }
    }

    while ($php > 0 && $ehp > 0 && $turn <= 80) {
        $eHit = calculateDamagePhp($enemy, $player);
        $php = max(0, $php - $eHit['damage']);
        $log[] = ['turn' => $turn, 'attacker' => 'Enemy', 'damage' => $eHit['damage'], 'crit' => $eHit['crit'], 'block' => $eHit['block'], 'remaining' => $php];
        if ($php <= 0) {
            break;
        }

        $pHit = calculateDamagePhp($player, $enemy);
        $ehp = max(0, $ehp - $pHit['damage']);
        $log[] = ['turn' => $turn, 'attacker' => 'You', 'damage' => $pHit['damage'], 'crit' => $pHit['crit'], 'block' => $pHit['block'], 'remaining' => $ehp];
        $turn++;
    }

    if ($php <= 0 && $ehp <= 0) {
        $winner = $playerFirst ? 'player' : 'enemy';
    } elseif ($php <= 0) {
        $winner = 'enemy';
    } elseif ($ehp <= 0) {
        $winner = 'player';
    } else {
        $winner = ($php === $ehp)
            ? ($playerFirst ? 'player' : 'enemy')
            : ($php > $ehp ? 'player' : 'enemy');
    }

    return [
        'winner' => $winner,
        'result' => ($winner === 'player') ? 'win' : 'loss',
        'log'    => $log,
    ];
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
        exit;
    }

    $attackerWallet = trim((string)($data['attacker'] ?? ''));
    $defenderWallet = trim((string)($data['defender'] ?? ''));
    $matchToken     = trim((string)($data['match_token'] ?? ''));

    if (
        $attackerWallet === '' ||
        $defenderWallet === '' ||
        !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $attackerWallet) ||
        !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $defenderWallet) ||
        !preg_match('/^[a-f0-9]{64}$/', $matchToken)
    ) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
        exit;
    }

    if ($attackerWallet === $defenderWallet) {
        echo json_encode(['success' => false, 'error' => 'Invalid opponent']);
        exit;
    }

    $attackerWallet = assertSameWallet($attackerWallet);

    $db = getDB();
    $db->beginTransaction();

    $mq = $db->prepare('SELECT * FROM fight_matches WHERE token = ? LIMIT 1 FOR UPDATE');
    $mq->execute([$matchToken]);
    $match = $mq->fetch(PDO::FETCH_ASSOC);

    if (
        !$match
        || $match['used_at'] !== null
        || $match['attacker'] !== $attackerWallet
        || $match['defender'] !== $defenderWallet
        || new DateTimeImmutable($match['expires_at']) < new DateTimeImmutable()
    ) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Match expired or invalid']);
        exit;
    }

    $stmt = $db->prepare('SELECT game_data FROM game_saves WHERE wallet = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$attackerWallet]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Attacker not found']);
        exit;
    }
    $attackerGame = json_decode($row['game_data'] ?? '{}', true);
    if (!is_array($attackerGame)) {
        $attackerGame = [];
    }

    $stmt->execute([$defenderWallet]);
    $row2 = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row2) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Defender not found']);
        exit;
    }
    $defenderGame = json_decode($row2['game_data'] ?? '{}', true);
    if (!is_array($defenderGame)) {
        $defenderGame = [];
    }

    $playerStats = getFullCombatStatsPhp($attackerGame);
    $enemyStats  = getFullCombatStatsPhp($defenderGame);

    if (!arenaCpAllowed((float)$playerStats['power'], (float)$enemyStats['power'])) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'cp_lock']);
        exit;
    }

    $today = date('Y-m-d');
    $arenaInfo = $attackerGame['arenaInfo'] ?? ['count' => 0, 'date' => ''];
    if (($arenaInfo['date'] ?? '') !== $today) {
        $arenaInfo = ['count' => 0, 'date' => $today];
    }

    $boostUntil = (int)($attackerGame['arenaBoostUntil'] ?? 0);
    $hasBoost = $boostUntil > time();
    $dailyLimit = $hasBoost ? 10 : 5;

    if ((int)$arenaInfo['count'] >= $dailyLimit) {
        $db->rollBack();
        echo json_encode([
            'success' => false,
            'error'   => "Daily arena limit reached ($dailyLimit/$dailyLimit)",
        ]);
        exit;
    }

    $fight  = simulateFightPhp($playerStats, $enemyStats);
    $result = ($fight['result'] ?? '') === 'win' ? 'win' : 'loss';

    $featherGain = 0;
    if ($result === 'win') {
        $opponentTotal = (float)($defenderGame['totalEarned'] ?? 0);
        $bonus = (int)floor($opponentTotal * 0.001);
        $featherGain = max(100, min(1000, 100 + $bonus));
        $attackerGame['feathers']    = (float)($attackerGame['feathers'] ?? 0) + $featherGain;
        $attackerGame['totalEarned'] = (float)($attackerGame['totalEarned'] ?? 0) + $featherGain;
    }

    $arenaInfo['count'] = (int)$arenaInfo['count'] + 1;
    $arenaInfo['date']  = $today;
    $attackerGame['arenaInfo'] = $arenaInfo;

    $db->prepare('UPDATE fight_matches SET used_at = NOW() WHERE id = ?')->execute([$match['id']]);

    $updateStmt = $db->prepare('UPDATE game_saves SET game_data = ?, last_save = NOW() WHERE wallet = ?');
    $updateStmt->execute([
        json_encode($attackerGame, JSON_UNESCAPED_UNICODE),
        $attackerWallet,
    ]);

    $db->commit();

    echo json_encode([
        'success'            => true,
        'result'             => $result,
        'log'                => $fight['log'],
        'featherLoss'        => $featherGain,
        'remaining'          => $dailyLimit - $arenaInfo['count'],
        'hasBoost'           => $hasBoost,
        'attacker_stats'     => $playerStats,
        'defender_stats'     => $enemyStats,
        'attacker_equipped'  => $attackerGame['gear']['equipped'] ?? null,
        'defender_equipped'  => $defenderGame['gear']['equipped'] ?? null,
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