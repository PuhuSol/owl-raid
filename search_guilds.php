<?php
// public_html/owlraid/search_guilds.php

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
    $q = trim($_GET['q'] ?? '');

    $db = getDB();

    if ($q === '') {
        // Boş arama: son / dolu guild'ler
        $stmt = $db->query("
            SELECT g.id, g.name, g.tag, g.join_type, g.level, g.member_limit,
                   (SELECT COUNT(*) FROM guild_members gm WHERE gm.guild_id = g.id) AS member_count
            FROM guilds g
            ORDER BY g.level DESC, g.total_contribution DESC
            LIMIT 20
        ");
        $guilds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $like = '%' . $q . '%';
        $stmt = $db->prepare("
            SELECT g.id, g.name, g.tag, g.join_type, g.level, g.member_limit,
                   (SELECT COUNT(*) FROM guild_members gm WHERE gm.guild_id = g.id) AS member_count
            FROM guilds g
            WHERE g.name LIKE ? OR g.tag LIKE ?
            ORDER BY g.level DESC, g.total_contribution DESC
            LIMIT 20
        ");
        $stmt->execute([$like, $like]);
        $guilds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($guilds as &$g) {
        $g['id'] = (int)$g['id'];
        $g['level'] = (int)$g['level'];
        $g['member_limit'] = (int)$g['member_limit'];
        $g['member_count'] = (int)$g['member_count'];
    }
    unset($g);

    echo json_encode(['success' => true, 'guilds' => $guilds]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error',
    ]);
}