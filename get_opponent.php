<?php
// public_html/owlraid/get_opponent.php

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://puhucoin.com');
header('Access-Control-Allow-Credentials: true');

function calcCombatPower(array $game): array
{
    $inv = $game['inventory'] ?? [];
    $w = intval($inv['weapon']['level'] ?? 0);
    $a = intval($inv['armor']['level'] ?? 0);
    $s = intval($inv['shield']['level'] ?? 0);
    $h = intval($inv['helmet']['level'] ?? 0);

    $attack  = 8 + ($w * 4);
    $hp      = 80 + ($a * 18);
    $defense = (int)floor(($a * 2.5) + ($s * 5) + ($h * 3.5));
    $crit    = min(40, 8 + ($w * 0.6) + (floatval($game['critChance'] ?? 0) * 50));
    $block   = min(20, $s * 1.0);

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

    $crit  = max(0, min(40, $crit));
    $block = max(0, min(25, $block));

    return [
        'attack'  => $attack,
        'hp'      => $hp,
        'defense' => $defense,
        'crit'    => round($crit, 1),
        'block'   => $block,
        'power'   => (int)floor(($attack * 3) + ($hp * 0.4) + ($defense * 2.5) + ($crit * 4) + ($block * 6))
    ];
}

function getGuildTag(PDO $db, string $wallet): ?string
{
    $stmt = $db->prepare("
        SELECT g.tag
        FROM guild_members gm
        INNER JOIN guilds g ON g.id = gm.guild_id
        WHERE gm.wallet = ?
        LIMIT 1
    ");
    $stmt->execute([$wallet]);
    $tag = $stmt->fetchColumn();
    if ($tag === false || $tag === null || $tag === '') {
        return null;
    }
    return (string)$tag;
}

function arenaCpBounds(float $cp, float $band): array
{
    $cp = max(1.0, $cp);
    return [
        'min' => (int)floor($cp * (1 - $band)),
        'max' => (int)ceil($cp * (1 + $band)),
    ];
}

function opponentPayload(PDO $db, array $row, array $stats, array $gameData): array
{
    $username = $row['username'] ?: ($gameData['username'] ?? 'Unknown');
    return [
        'wallet'    => $row['wallet'],
        'username'  => $username,
        'guild_tag' => getGuildTag($db, $row['wallet']),
        'attack'    => $stats['attack'],
        'hp'        => $stats['hp'],
        'defense'   => $stats['defense'],
        'crit'      => $stats['crit'],
        'block'     => $stats['block'],
        'power'     => $stats['power'],
        'feathers'  => floatval($gameData['feathers'] ?? 0)
    ];
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

try {
    $wallet = trim($_GET['wallet'] ?? '');

    if ($wallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        echo json_encode(['success' => false, 'error' => 'Invalid wallet']);
        exit;
    }

    $wallet = assertSameWallet($wallet);

    $db = getDB();

    $stmt = $db->prepare("SELECT game_data, username FROM game_saves WHERE wallet = ? LIMIT 1");
    $stmt->execute([$wallet]);
    $myRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$myRow) {
        echo json_encode(['success' => false, 'error' => 'Player not found']);
        exit;
    }

    $myGame = json_decode($myRow['game_data'] ?? '{}', true);
    if (!is_array($myGame)) {
        $myGame = [];
    }

    $myStats = calcCombatPower($myGame);
    $myPower = (float)$myStats['power'];

    $stmt = $db->prepare("
        SELECT wallet, game_data, username
        FROM game_saves
        WHERE wallet != ?
          AND last_save >= DATE_SUB(NOW(), INTERVAL 7 DAY)
          AND (
                (username IS NOT NULL AND username != '')
             OR (JSON_EXTRACT(game_data, '$.username') IS NOT NULL
                 AND JSON_UNQUOTE(JSON_EXTRACT(game_data, '$.username')) != ''
                 AND JSON_UNQUOTE(JSON_EXTRACT(game_data, '$.username')) != 'null')
          )
        ORDER BY RAND()
        LIMIT 120
    ");
    $stmt->execute([$wallet]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $matched = null;
    $band = ARENA_CP_BAND;

    while ($band <= ARENA_CP_MAX_WIDEN + 0.0001) {
        $bounds = arenaCpBounds($myPower, $band);
        $pool = [];

        foreach ($candidates as $row) {
            $gameData = json_decode($row['game_data'] ?? '{}', true);
            if (!is_array($gameData)) {
                continue;
            }
            $stats = calcCombatPower($gameData);
            if ($stats['power'] >= $bounds['min'] && $stats['power'] <= $bounds['max']) {
                $pool[] = [$row, $stats, $gameData];
            }
        }

        if ($pool) {
            $pick = $pool[random_int(0, count($pool) - 1)];
            $matched = opponentPayload($db, $pick[0], $pick[1], $pick[2]);
            break;
        }

        $band += ARENA_CP_WIDEN;
    }

    if (!$matched) {
        echo json_encode(['success' => false, 'error' => 'No available opponent']);
        exit;
    }

    $token = bin2hex(random_bytes(32));
    $expires = (new DateTimeImmutable('+60 seconds'))->format('Y-m-d H:i:s');

    $ins = $db->prepare(
        'INSERT INTO fight_matches (token, attacker, defender, expires_at) VALUES (?, ?, ?, ?)'
    );
    $ins->execute([$token, $wallet, $matched['wallet'], $expires]);

    echo json_encode([
        'success'     => true,
        'opponent'    => $matched,
        'match_token' => $token,
        'expires_in'  => 60
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error'
    ]);
}