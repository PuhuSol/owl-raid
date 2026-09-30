<?php
// public_html/owlraid/get_leaderboard.php

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://puhucoin.com');
header('Access-Control-Allow-Credentials: true');

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

/**
 * Frontend getCombatPower ile aynı formül
 */
function calcCombatPowerFromGame(?array $game): int
{
    if (!is_array($game)) {
        $game = [];
    }

    $inv = $game['inventory'] ?? [];
    $w = (int)($inv['weapon']['level'] ?? 0);
    $a = (int)($inv['armor']['level'] ?? 0);
    $s = (int)($inv['shield']['level'] ?? 0);
    $h = (int)($inv['helmet']['level'] ?? 0);
    $critChance = (float)($game['critChance'] ?? 0);

    $attack  = 8 + ($w * 4);
    $hp      = 80 + ($a * 18);
    $defense = (int)floor(($a * 2.5) + ($s * 5) + ($h * 3.5));
    $crit    = 8 + ($w * 0.6) + ($critChance * 50);
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

    return (int)floor(($attack * 3) + ($hp * 0.4) + ($defense * 2.5) + ($crit * 4) + ($block * 6));
}

try {
    $db = getDB();

    // Username olan herkesi al (limit sonra CP'ye göre)
    $stmt = $db->query("
        SELECT
            gs.wallet,
            gs.username AS col_username,
            gs.game_data,
            g.tag AS guild_tag,
            g.name AS guild_name
        FROM game_saves gs
        LEFT JOIN guild_members gm ON gm.wallet = gs.wallet
        LEFT JOIN guilds g ON g.id = gm.guild_id
        WHERE
            (
                (gs.username IS NOT NULL AND gs.username != '')
                OR
                (
                    JSON_EXTRACT(gs.game_data, '$.username') IS NOT NULL
                    AND JSON_UNQUOTE(JSON_EXTRACT(gs.game_data, '$.username')) != ''
                    AND JSON_UNQUOTE(JSON_EXTRACT(gs.game_data, '$.username')) != 'null'
                )
            )
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];

    foreach ($rows as $row) {
        $game = json_decode($row['game_data'] ?? '{}', true);
        if (!is_array($game)) {
            $game = [];
        }

        $username = trim((string)($row['col_username'] ?? ''));
        if ($username === '') {
            $username = trim((string)($game['username'] ?? ''));
        }
        if ($username === '' || strtolower($username) === 'null') {
            continue;
        }

        $out[] = [
            'wallet'         => $row['wallet'],
            'username'       => $username,
            'combat_power'   => calcCombatPowerFromGame($game),
            'total_earned'   => (float)($game['totalEarned'] ?? 0),
            'prestige_level' => (int)($game['prestigeLevel'] ?? 0),
            'guild_tag'      => $row['guild_tag'] ?: null,
            'guild_name'     => $row['guild_name'] ?: null,
        ];
    }

    usort($out, static function ($a, $b) {
        if ($a['combat_power'] !== $b['combat_power']) {
            return $b['combat_power'] <=> $a['combat_power'];
        }
        // eşitlikte total_earned
        return $b['total_earned'] <=> $a['total_earned'];
    });

    $out = array_slice($out, 0, 20);

    echo json_encode([
        'success' => true,
        'data'    => $out,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error',
    ]);
}