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
require_once __DIR__ . '/require_session.php';


/*
|--------------------------------------------------------------------------
| Combat helpers
|--------------------------------------------------------------------------
*/

function invLevel(array $game, string $type): int
{
    return max(
        0,
        (int)($game['inventory'][$type]['level'] ?? 0)
    );
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

    $defense = (int)floor(
        ($a * 2.5)
        + ($s * 5)
        + ($h * 3.5)
    );

    $crit = min(
        40.0,
        max(
            0.0,
            8 + ($w * 0.6) + ($critChance * 50)
        )
    );

    $block = min(
        20.0,
        $s * 1.0
    );


    /*
     * Equipped gear bonusları
     */
    $eq = $game['gear']['equipped'] ?? [];

    if (is_array($eq)) {

        foreach ($eq as $item) {

            if (
                !is_array($item)
                || empty($item['bonuses'])
            ) {
                continue;
            }

            $b = $item['bonuses'];

            $attack += (int)($b['attack'] ?? 0);
            $hp += (int)($b['hp'] ?? 0);
            $defense += (int)($b['defense'] ?? 0);
            $crit += (float)($b['crit'] ?? 0);
            $block += (float)($b['block'] ?? 0);
        }
    }


    $crit = max(
        0.0,
        min(40.0, $crit)
    );

    $block = max(
        0.0,
        min(25.0, $block)
    );


    /*
     * Combat Power
     */
    $power = (int)floor(
        ($attack * 3)
        + ($hp * 0.4)
        + ($defense * 2.5)
        + ($crit * 4)
        + ($block * 6)
    );


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


/*
|--------------------------------------------------------------------------
| World Boss damage
|--------------------------------------------------------------------------
*/

function calculateWorldBossDamage(array $playerStats): array
{
    $attack = (float)$playerStats['attack'];

    /*
     * Boss'un savunması.
     *
     * Şimdilik sabit.
     * Daha sonra boss seviyesine/fazına göre
     * dinamik hale getirebiliriz.
     */
    $bossDefense = 100.0;


    /*
     * Crit
     */
    $isCritical =
        (mt_rand() / mt_getrandmax())
        < ((float)$playerStats['crit'] / 100);


    /*
     * Temel hasar
     */
    $damage = max(
        1,
        $attack - ($bossDefense * 0.35)
    );


    /*
     * Küçük random varyasyon
     */
    $damage *= (
        0.90
        + (
            (mt_rand() / mt_getrandmax())
            * 0.20
        )
    );


    /*
     * Critical
     */
    if ($isCritical) {
        $damage *= 1.5;
    }


    return [
        'damage' => (int)max(
            1,
            round($damage)
        ),

        'critical' => $isCritical
    ];
}


try {

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {

        echo json_encode([
            'success' => false,
            'error' => 'Invalid data'
        ]);

        exit;
    }


    $wallet = trim(
        (string)($input['wallet'] ?? '')
    );


    if (
        $wallet === ''
        || !preg_match(
            '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/',
            $wallet
        )
    ) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'error' => 'Invalid wallet'
        ]);

        exit;
    }


    /*
     * Aktif oturumdaki wallet ile eşleşmesini sağla
     */
    $wallet = assertSameWallet($wallet);


    $db = getDB();

    $db->beginTransaction();


    /*
     * Aktif boss'u kilitle
     */
    $stmt = $db->query("
        SELECT *
        FROM world_boss
        WHERE status = 'active'
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");

    $boss = $stmt->fetch(PDO::FETCH_ASSOC);


    if (!$boss) {

        $db->rollBack();

        echo json_encode([
            'success' => false,
            'error' => 'No active World Boss'
        ]);

        exit;
    }


    $bossId = (int)$boss['id'];


    /*
     * Boss hala canlı mı?
     */
    $currentHp = (int)$boss['current_hp'];

    if ($currentHp <= 0) {

        $db->rollBack();

        echo json_encode([
            'success' => false,
            'error' => 'World Boss is dead'
        ]);

        exit;
    }


    /*
     * Oyuncunun raid kaydı
     */
    $stmt = $db->prepare("
        SELECT *
        FROM world_boss_players
        WHERE boss_id = ?
          AND wallet = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $bossId,
        $wallet
    ]);

    $player = $stmt->fetch(PDO::FETCH_ASSOC);


    if (!$player) {

        $db->rollBack();

        echo json_encode([
            'success' => false,
            'error' => 'Join the World Boss first'
        ]);

        exit;
    }
    
    /*
 * Oyuncu öldüyse artık saldırı yapamaz
 */
if (
    (int)$player['is_alive'] !== 1 ||
    (int)$player['current_hp'] <= 0
) {
    $db->rollBack();

    echo json_encode([
        'success' => false,
        'error' => 'player_dead'
    ]);

    exit;
}


    /*
     * Oyuncunun game_data'sını çek
     */
    $stmt = $db->prepare("
        SELECT game_data
        FROM game_saves
        WHERE wallet = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $wallet
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);


    if (!$row) {

        $db->rollBack();

        echo json_encode([
            'success' => false,
            'error' => 'Player not found'
        ]);

        exit;
    }


    $game = json_decode(
        $row['game_data'] ?? '{}',
        true
    );


    if (!is_array($game)) {
        $game = [];
    }


    /*
 * Saldırı cooldown
 * Her saldırı arasında 3 saniye
 */
$lastAttackAt = $player['last_attack_at'] ?? null;

if ($lastAttackAt !== null) {

    $lastAttackTime = strtotime($lastAttackAt);
    $currentTime = time();

    $elapsed = $currentTime - $lastAttackTime;

    if ($elapsed < 1) {

        $remaining = 1 - $elapsed;

        $db->rollBack();

        echo json_encode([
            'success' => false,
            'error' => 'attack_cooldown',
            'remaining' => $remaining
        ]);

        exit;
    }
}


/*
 * Gerçek combat statları
 */
$playerStats = getFullCombatStatsPhp(
    $game
);


    /*
     * Gerçek hasar
     */
    $hit = calculateWorldBossDamage(
        $playerStats
    );


    $damage = min(
        $hit['damage'],
        $currentHp
    );


    $isCritical = $hit['critical'];


    /*
     * Yeni boss HP
     */
    $newHp = $currentHp - $damage;


    /*
     * Oyuncu damage/ranking bilgisi
     */
    $stmt = $db->prepare("
        UPDATE world_boss_players
        SET
            damage = damage + ?,
            attacks = attacks + 1,
            last_attack_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    $stmt->execute([
        $damage,
        (int)$player['id']
    ]);


    /*
     * Saldırıyı kaydet
     */
    $stmt = $db->prepare("
        INSERT INTO world_boss_attacks
        (
            boss_id,
            wallet,
            damage,
            is_critical
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?
        )
    ");

    $stmt->execute([
        $bossId,
        $wallet,
        $damage,
        $isCritical ? 1 : 0
    ]);

$previousBossPhase = 1;

$bossMaxHpForPhase = (int)$boss['max_hp'];

if ($bossMaxHpForPhase > 0) {

    if ($currentHp <= ($bossMaxHpForPhase * 0.25)) {
        $previousBossPhase = 4;
    } elseif ($currentHp <= ($bossMaxHpForPhase * 0.50)) {
        $previousBossPhase = 3;
    } elseif ($currentHp <= ($bossMaxHpForPhase * 0.75)) {
        $previousBossPhase = 2;
    }
}
    /*
 * Boss öldü mü?
 */
$bossDead = $newHp <= 0;

/*
 * World Boss karşı saldırısı
 */
$bossAttackDamage = 0;
$bossAttackCritical = false;
$bossAttackHeavy = false;
$bossPhase = 1;
$bossAttackSkill = false;
$bossSkillName = null;
$newPlayerHp = (int)$player['current_hp'];
$playerAlive = (int)$player['is_alive'] === 1;

if (!$bossDead && $playerAlive) {

    /*
     * Boss Phase sistemi
     */
    $bossMaxHp = (int)$boss['max_hp'];
    $bossCurrentHp = (int)$newHp;

    if (
        $bossMaxHp > 0 &&
        $bossCurrentHp <= ($bossMaxHp * 0.25)
    ) {
        $bossPhase = 4;
    } elseif (
        $bossMaxHp > 0 &&
        $bossCurrentHp <= ($bossMaxHp * 0.50)
    ) {
        $bossPhase = 3;
    } elseif (
        $bossMaxHp > 0 &&
        $bossCurrentHp <= ($bossMaxHp * 0.75)
    ) {
        $bossPhase = 2;
    }
    
    $bossPhaseChanged = $bossPhase > $previousBossPhase;

    /*
     * Boss saldırı gücü
     */
    $bossAttackPower = 35.0;

    if ($bossPhase >= 3) {
        $bossAttackPower *= 1.35;
    }

    if ($bossPhase >= 4) {
        $bossAttackPower *= 1.50;
    }

    /*
     * Heavy Strike ihtimali
     */
    $heavyStrikeChance = 15;

    if ($bossPhase >= 4) {
        $heavyStrikeChance = 45;
    } elseif ($bossPhase >= 3) {
        $heavyStrikeChance = 35;
    } elseif ($bossPhase >= 2) {
        $heavyStrikeChance = 25;
    }

    $bossAttackHeavy = random_int(1, 100) <= $heavyStrikeChance;

if ($bossAttackHeavy) {
    $bossAttackPower *= 2.0;
}

/*
 * Boss Skill
 */
$bossSkillChance = 20;

if ($bossPhase >= 4) {
    $bossSkillChance = 50;
} elseif ($bossPhase >= 3) {
    $bossSkillChance = 40;
} elseif ($bossPhase >= 2) {
    $bossSkillChance = 30;
}

$bossAttackSkill =
    random_int(1, 100) <= $bossSkillChance;

if ($bossAttackSkill) {

    /*
     * Boss Skill Pool
     */
    $skillRoll = random_int(1, 100);

    if ($skillRoll <= 45) {

        $bossSkillName = 'OWL DEVASTATION';
        $bossAttackPower *= 2.5;

    } elseif ($skillRoll <= 75) {

        $bossSkillName = 'SHADOW TALON';
        $bossAttackPower *= 2.0;

    } else {

        $bossSkillName = 'ANCIENT SCREAM';
        $bossAttackPower *= 3.0;
    }

    $bossAttackHeavy = false;
}

    /*
     * Oyuncu savunmasına göre hasar
     */
    $defense = max(
        0,
        (float)$playerStats['defense']
    );

    $bossAttackDamage = $bossAttackPower
        * (100 / (100 + $defense));

    /*
     * Random varyasyon
     */
    $bossAttackDamage *= (
        0.90
        + (
            (mt_rand() / mt_getrandmax())
            * 0.20
        )
    );

    $bossAttackDamage = max(
        1,
        (int)round($bossAttackDamage)
    );

    /*
     * Boss kritik vuruş
     */
    $bossCriticalChance = 10;

    if ($bossPhase >= 4) {
        $bossCriticalChance = 20;
    }

    $bossAttackCritical =
        random_int(1, 100) <= $bossCriticalChance;

    if ($bossAttackCritical) {
        $bossAttackDamage = (int)round(
            $bossAttackDamage * 1.5
        );
    }

    /*
     * Oyuncunun kalan HP'sinden fazla hasar verme
     */
    $bossAttackDamage = min(
        $bossAttackDamage,
        (int)$player['current_hp']
    );

    $newPlayerHp = max(
        0,
        (int)$player['current_hp'] - $bossAttackDamage
    );

    $playerAlive = $newPlayerHp > 0;

    /*
     * Oyuncu HP güncelle
     */
    $stmt = $db->prepare("
        UPDATE world_boss_players
        SET
            current_hp = ?,
            is_alive = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $newPlayerHp,
        $playerAlive ? 1 : 0,
        (int)$player['id']
    ]);
}


    if ($bossDead) {

        $newHp = 0;


        $stmt = $db->prepare("
            UPDATE world_boss
SET
    current_hp = 0,
    status = 'dead',
    ended_at = CURRENT_TIMESTAMP,
    ended_reason = 'defeated'
WHERE id = ?
        ");

        $stmt->execute([
            $bossId
        ]);

    } else {

        $stmt = $db->prepare("
            UPDATE world_boss
            SET current_hp = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $newHp,
            $bossId
        ]);
    }


    $db->commit();
    /*
 * World Boss LIVE ACTIVITY
 */
if ($bossDead) {

    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM world_boss_players
        WHERE boss_id = ?
          AND attacks > 0
    ");

    $stmt->execute([
        $bossId
    ]);

    $raiderCount = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("
        INSERT INTO game_activity_feed
        (
            wallet,
            event_type,
            event_text
        )
        VALUES
        (
            ?,
            ?,
            ?
        )
    ");

    $stmt->execute([
        $wallet,
        'world_boss',
        "defeated {$boss['boss_name']} — {$raiderCount} raiders joined!"
    ]);
}


    /*
     * Sonuç
     */
    echo json_encode([
        'success' => true,

        'damage' => $damage,

        'critical' => $isCritical,

        'boss' => [
            'id' => $bossId,
            'name' => $boss['boss_name'],
            'max_hp' => (int)$boss['max_hp'],
            'current_hp' => $newHp,
            'status' => $bossDead
                ? 'dead'
                : 'active'
        ],

        'player' => [
    'attack' => $playerStats['attack'],
    'defense' => $playerStats['defense'],
    'crit' => $playerStats['crit'],
    'power' => $playerStats['power'],
    'max_hp' => (int)$player['max_hp'],
    'current_hp' => $newPlayerHp,
    'is_alive' => $playerAlive
],

'boss_attack' => [
    'damage' => $bossAttackDamage,
    'critical' => $bossAttackCritical,
    'heavy' => $bossAttackHeavy,
    'skill' => $bossAttackSkill,
    'skill_name' => $bossSkillName,
    'phase' => $bossPhase
]
    ]);

} catch (Throwable $e) {

    if (
        isset($db)
        && $db instanceof PDO
        && $db->inTransaction()
    ) {
        $db->rollBack();
    }


    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' =>
            defined('APP_DEBUG') && APP_DEBUG
                ? $e->getMessage()
                : 'Server error'
    ]);
}