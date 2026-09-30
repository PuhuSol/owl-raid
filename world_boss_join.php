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

    $power = (int)floor(
        ($attack * 3) +
        ($hp * 0.4) +
        ($defense * 2.5) +
        ($crit * 4) +
        ($block * 6)
    );

    return [
        'attack'   => $attack,
        'hp'       => $hp,
        'defense'  => $defense,
        'crit'     => $crit,
        'block'    => $block,
        'power'    => $power
    ];
}

try {

    $db = getDb();

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    $wallet = trim((string)($input['wallet'] ?? ''));

    if ($wallet === '') {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'error' => 'Wallet is required'
        ]);

        exit;
    }

    /*
     * Aktif World Boss
     */
    $stmt = $db->query("
        SELECT *
        FROM world_boss
        WHERE status = 'active'
        ORDER BY id DESC
        LIMIT 1
    ");

    $boss = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$boss) {

        echo json_encode([
            'success' => false,
            'error' => 'No active World Boss'
        ]);

        exit;
    }

    $bossId = (int)$boss['id'];

/*
 * Oyuncunun gerçek combat HP'sini hesapla
 */
$stmt = $db->prepare("
    SELECT game_data
    FROM game_saves
    WHERE wallet = ?
    LIMIT 1
");

$stmt->execute([
    $wallet
]);

$save = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$save) {
    echo json_encode([
        'success' => false,
        'error' => 'Player game data not found'
    ]);
    exit;
}

$game = json_decode(
    $save['game_data'] ?? '{}',
    true
);

if (!is_array($game)) {
    $game = [];
}

$combatStats = getFullCombatStatsPhp($game);

$playerMaxHp = max(
    1,
    (int)$combatStats['hp']
);

    /*
     * Oyuncu zaten raid'e katılmış mı?
     */
    $stmt = $db->prepare("
        SELECT *
        FROM world_boss_players
        WHERE boss_id = ?
          AND wallet = ?
        LIMIT 1
    ");

    $stmt->execute([
        $bossId,
        $wallet
    ]);

    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    /*
     * İlk kez katılıyorsa oluştur
     */
    if (!$player) {

        $stmt = $db->prepare("
    INSERT INTO world_boss_players
    (
        boss_id,
        wallet,
        max_hp,
        current_hp,
        is_alive
    )
    VALUES
    (
        ?,
        ?,
        ?,
        ?,
        1
    )
");

$stmt->execute([
    $bossId,
    $wallet,
    $playerMaxHp,
    $playerMaxHp
]);

        $playerId = (int)$db->lastInsertId();

    } else {

    $playerId = (int)$player['id'];

    /*
     * Oyuncu öldüyse yeniden savaşa girdiğinde
     * HP'sini tamamen yenile.
     *
     * DAMAGE ve ATTACKS değerlerine dokunma.
     */
    if (
        (int)$player['is_alive'] !== 1 ||
        (int)$player['current_hp'] <= 0
    ) {
        $stmt = $db->prepare("
            UPDATE world_boss_players
            SET
                max_hp = ?,
                current_hp = ?,
                is_alive = 1
            WHERE id = ?
        ");

        $stmt->execute([
            $playerMaxHp,
            $playerMaxHp,
            $playerId
        ]);
    }
}

    /*
     * Güncel oyuncu bilgisi
     */
    $stmt = $db->prepare("
        SELECT
    p.wallet,
    p.damage,
    p.attacks,
    p.max_hp,
    p.current_hp,
    p.is_alive,
    COALESCE(
        NULLIF(gs.username, ''),
        LEFT(p.wallet, 8)
    ) AS username
        FROM world_boss_players p
        LEFT JOIN game_saves gs
            ON gs.wallet = p.wallet
        WHERE p.id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $playerId
    ]);

    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'joined' => true,

        'boss' => [
            'id' => $bossId,
            'name' => $boss['boss_name'],
            'max_hp' => (int)$boss['max_hp'],
            'current_hp' => (int)$boss['current_hp'],
            'status' => $boss['status']
        ],

        'player' => [
    'wallet' => $player['wallet'],
    'username' => $player['username'],
    'damage' => (int)$player['damage'],
    'attacks' => (int)$player['attacks'],
    'max_hp' => (int)$player['max_hp'],
    'current_hp' => (int)$player['current_hp'],
    'is_alive' => (int)$player['is_alive']
]
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Server error'
    ]);
}