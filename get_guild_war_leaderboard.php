<?php
// public_html/owlraid/get_guild_war_leaderboard.php

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

try {
    $db = getDB();

    $stmt = $db->query("
        SELECT
            g.id,
            g.name,
            g.tag,
            g.war_points,
            g.wars_won,
            g.wars_lost,
            g.level,
            (SELECT COUNT(*) FROM guild_members gm WHERE gm.guild_id = g.id) AS member_count
        FROM guilds g
        ORDER BY g.war_points DESC, g.wars_won DESC, g.id ASC
        LIMIT 20
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['war_points'] = (int)$r['war_points'];
        $r['wars_won'] = (int)$r['wars_won'];
        $r['wars_lost'] = (int)$r['wars_lost'];
        $r['level'] = (int)$r['level'];
        $r['member_count'] = (int)$r['member_count'];
    }
    unset($r);

    echo json_encode([
        'success' => true,
        'data'    => $rows,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error',
    ]);
}