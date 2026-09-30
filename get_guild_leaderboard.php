<?php
// public_html/owlraid/get_guild_leaderboard.php

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

    $stmt = $db->query("
        SELECT
            g.id,
            g.name,
            g.tag,
            g.level,
            g.xp,
            g.total_contribution,
            g.member_limit,
            gm.wallet,
            gs.game_data
        FROM guilds g
        LEFT JOIN guild_members gm ON gm.guild_id = g.id
        LEFT JOIN game_saves gs ON gs.wallet = gm.wallet
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $guilds = [];

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        if (!isset($guilds[$id])) {
            $guilds[$id] = [
                'id'                 => $id,
                'name'               => $row['name'],
                'tag'                => $row['tag'],
                'level'              => (int)$row['level'],
                'xp'                 => (int)$row['xp'],
                'total_contribution' => (int)$row['total_contribution'],
                'member_limit'       => (int)$row['member_limit'],
                'member_count'       => 0,
                'combat_power'       => 0,
            ];
        }

        if (!empty($row['wallet'])) {
            $guilds[$id]['member_count']++;
            $game = json_decode($row['game_data'] ?? '{}', true);
            $guilds[$id]['combat_power'] += calcCombatPowerFromGame(is_array($game) ? $game : []);
        }
    }

    $out = array_values($guilds);

    usort($out, static function ($a, $b) {
        if ($a['combat_power'] !== $b['combat_power']) {
            return $b['combat_power'] <=> $a['combat_power'];
        }
        if ($a['level'] !== $b['level']) {
            return $b['level'] <=> $a['level'];
        }
        return $b['total_contribution'] <=> $a['total_contribution'];
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