<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

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
    echo json_encode([
        'success' => false,
        'error' => 'Config not found'
    ]);
    exit;
}

require_once $configPath;
require_once dirname($configPath) . '/database.php';

try {

    $db = getDb();

    /*
     * Türkiye saati
     */
    $timezone = new DateTimeZone('Europe/Istanbul');
    $now = new DateTime('now', $timezone);

    /*
     * Mevcut aktif boss
     */
    $stmt = $db->query("
        SELECT *
        FROM world_boss
        WHERE status = 'active'
        ORDER BY id DESC
        LIMIT 1
    ");

    $boss = $stmt->fetch(PDO::FETCH_ASSOC);

    /*
 * Aktif boss yoksa son ölen bossu bul.
 * Böylece son bossun ranking'i yeni boss gelene kadar
 * ekranda kalabilir.
 */
if (!$boss) {

    $stmt = $db->query("
        SELECT *
        FROM world_boss
        WHERE status = 'dead'
        ORDER BY id DESC
        LIMIT 1
    ");

    $boss = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$boss) {

        echo json_encode([
            'success' => true,
            'active' => false,
            'message' => 'No World Boss found'
        ]);

        exit;
    }
}

    /*
     * Boss oyuncu sıralaması
     */
    $stmt = $db->prepare("
    SELECT
        p.wallet,
        p.damage,
        p.attacks,
        p.is_alive,

        COALESCE(
            NULLIF(gs.username, ''),
            LEFT(p.wallet, 8)
        ) AS username,

        COALESCE(g.name, '') AS guild_name,
        COALESCE(g.tag, '') AS guild_tag

    FROM world_boss_players p

    LEFT JOIN game_saves gs
        ON gs.wallet = p.wallet

    LEFT JOIN guild_members gm
        ON gm.wallet = p.wallet

    LEFT JOIN guilds g
        ON g.id = gm.guild_id

    WHERE p.boss_id = ?

    ORDER BY
        p.damage DESC,
        p.attacks DESC,
        p.id ASC

    LIMIT 100
");

    $stmt->execute([
        (int)$boss['id']
    ]);

    $ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Sıra numaralarını ekle
     */
    foreach ($ranking as $index => &$player) {
    $player['rank'] = $index + 1;
    $player['damage'] = (int)$player['damage'];
    $player['attacks'] = (int)$player['attacks'];
    $player['is_alive'] = (int)$player['is_alive'] === 1;
}

    unset($player);

    /*
     * Toplam raid damage
     */
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(damage), 0) AS total_damage,
            COUNT(*) AS player_count
        FROM world_boss_players
        WHERE boss_id = ?
    ");

    $stmt->execute([
        (int)$boss['id']
    ]);

    $raidStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    /*
 * Oyuncunun World Boss HP bilgisi
 */
$player = null;

if (!empty($_SESSION['wallet'])) {

    $stmt = $db->prepare("
        SELECT
            max_hp,
            current_hp,
            is_alive
        FROM world_boss_players
        WHERE boss_id = ?
          AND wallet = ?
        LIMIT 1
    ");

    $stmt->execute([
        (int)$boss['id'],
        $_SESSION['wallet']
    ]);

    $playerData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($playerData) {
        $player = [
            'max_hp' => (int)$playerData['max_hp'],
            'current_hp' => (int)$playerData['current_hp'],
            'is_alive' => (int)$playerData['is_alive'] === 1
        ];
    }
}

    echo json_encode([
        'success' => true,
        'active' => true,

        'boss' => [
            'id' => (int)$boss['id'],
            'name' => $boss['boss_name'],
            'max_hp' => (int)$boss['max_hp'],
            'current_hp' => (int)$boss['current_hp'],
            'status' => $boss['status'],
            'started_at' => $boss['started_at'],
            
        ],

        'raid' => [
            'player_count' => (int)$raidStats['player_count'],
            'total_damage' => (int)$raidStats['total_damage'],
        ],
        
        'player' => $player,

        'ranking' => $ranking
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Server error'
    ]);
}