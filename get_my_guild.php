<?php
// public_html/owlraid/get_my_guild.php

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
    $wallet = trim($_GET['wallet'] ?? '');

    if ($wallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        echo json_encode(['success' => false, 'error' => 'Invalid wallet']);
        exit;
    }

    $db = getDB();

    $stmt = $db->prepare("
        SELECT g.id, g.name, g.tag, g.join_type, g.level, g.xp,
               g.total_contribution, g.member_limit, g.leader_wallet,
               gm.role, gm.contribution AS my_contribution
        FROM guild_members gm
        INNER JOIN guilds g ON g.id = gm.guild_id
        WHERE gm.wallet = ?
        LIMIT 1
    ");
    $stmt->execute([$wallet]);
    $guild = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$guild) {
        echo json_encode(['success' => true, 'guild' => null]);
        exit;
    }

    $stmt = $db->prepare("
        SELECT gm.wallet, gm.role, gm.contribution, gm.joined_at,
               gs.username
        FROM guild_members gm
        LEFT JOIN game_saves gs ON gs.wallet = gm.wallet
        WHERE gm.guild_id = ?
        ORDER BY FIELD(gm.role, 'leader', 'officer', 'member'),
                 gm.contribution DESC
    ");
    $stmt->execute([(int)$guild['id']]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pending = [];
    if (in_array($guild['role'], ['leader', 'officer'], true)) {
        $stmt = $db->prepare("
            SELECT r.id, r.wallet, r.created_at, gs.username
            FROM guild_join_requests r
            LEFT JOIN game_saves gs ON gs.wallet = r.wallet
            WHERE r.guild_id = ? AND r.status = 'pending'
            ORDER BY r.created_at ASC
        ");
        $stmt->execute([(int)$guild['id']]);
        $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $level  = max(1, (int)$guild['level']);
    $xp     = (int)$guild['xp'];
    $xpNeed = 5000 * $level * $level;
    $xpPct  = $xpNeed > 0 ? min(100, round($xp / $xpNeed * 100, 1)) : 0;

    echo json_encode([
        'success' => true,
        'guild'   => [
            'id'                 => (int)$guild['id'],
            'name'               => $guild['name'],
            'tag'                => $guild['tag'],
            'join_type'          => $guild['join_type'],
            'level'              => $level,
            'xp'                 => $xp,
            'xp_pct'             => $xpPct,
            'xp_need'            => $xpNeed,
            'total_contribution' => (int)$guild['total_contribution'],
            'member_limit'       => (int)$guild['member_limit'],
            'member_count'       => count($members),
            'role'               => $guild['role'],
            'my_contribution'    => (int)$guild['my_contribution'],
            'members'            => $members,
            'pending'            => $pending,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error',
    ]);
}