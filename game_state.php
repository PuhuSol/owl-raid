<?php

declare(strict_types=1);

require_once __DIR__ . '/require_session.php';

/*
|--------------------------------------------------------------------------
| SERVER AUTHORITATIVE GAME STATE
|--------------------------------------------------------------------------
*/

function loadGameState(PDO $db, string $wallet): array
{
    $stmt = $db->prepare("
        SELECT game_data
        FROM game_saves
        WHERE wallet = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([$wallet]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('Game save not found');
    }

    $game = json_decode($row['game_data'], true);

    if (!is_array($game)) {
        throw new RuntimeException('Invalid game state');
    }

    return $game;
}


function saveGameState(PDO $db, string $wallet, array $game): void
{
    $json = json_encode(
        $game,
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

    $stmt = $db->prepare("
        UPDATE game_saves
        SET game_data = ?, last_save = NOW()
        WHERE wallet = ?
        LIMIT 1
    ");

    $stmt->execute([$json, $wallet]);
}


function getServerWallet(): string
{
    return requireSessionWallet();
}


/*
|--------------------------------------------------------------------------
| ACHIEVEMENTS
|--------------------------------------------------------------------------
*/

function getAchievementMultiplier(array $game): float
{
    $bonus = 1.0;

    $achievements = is_array($game['achievements'] ?? null)
        ? $game['achievements']
        : [];

    $defs = [
        'first'     => 0,
        'hundred'   => 0,
        'thousand'  => 0.05,
        'tenk'      => 0.10,
        'click10'   => 0,
        'auto10'    => 0.05,
        'crit'      => 0,
        'prestige1' => 0.10,
        'prestige3' => 0.15,
        'boost'     => 0,
        'offline'   => 0,
        'million'   => 0.25
    ];

    foreach ($defs as $id => $value) {
        if (!empty($achievements[$id])) {
            $bonus += $value;
        }
    }

    return $bonus;
}


/*
|--------------------------------------------------------------------------
| SKIN BONUSES
|--------------------------------------------------------------------------
*/

function getSkinBonuses(array $game): array
{
    $id = (int)($game['selectedSkin'] ?? 0);

    $skins = [
        0  => ['click' => 1.00, 'hps' => 1.00, 'crit' => 0.00],
        1  => ['click' => 1.03, 'hps' => 1.00, 'crit' => 0.00],
        2  => ['click' => 1.00, 'hps' => 1.03, 'crit' => 0.00],
        3  => ['click' => 1.05, 'hps' => 1.00, 'crit' => 0.00],
        4  => ['click' => 1.00, 'hps' => 1.05, 'crit' => 0.00],
        5  => ['click' => 1.04, 'hps' => 1.04, 'crit' => 0.00],
        6  => ['click' => 1.00, 'hps' => 1.00, 'crit' => 0.03],
        7  => ['click' => 1.07, 'hps' => 1.00, 'crit' => 0.00],
        8  => ['click' => 1.03, 'hps' => 1.03, 'crit' => 0.00],
        9  => ['click' => 1.00, 'hps' => 1.07, 'crit' => 0.00],
        10 => ['click' => 1.08, 'hps' => 1.00, 'crit' => 0.02],
        11 => ['click' => 1.06, 'hps' => 1.06, 'crit' => 0.00],
        12 => ['click' => 1.00, 'hps' => 1.08, 'crit' => 0.02],
        13 => ['click' => 1.08, 'hps' => 1.08, 'crit' => 0.02],
    ];

    return $skins[$id] ?? $skins[0];
}


/*
|--------------------------------------------------------------------------
| HPS
|--------------------------------------------------------------------------
*/

function calculateHPS(array $game): float
{
    $auto1 = max(0, (int)($game['auto1'] ?? 0));
    $auto2 = max(0, (int)($game['auto2'] ?? 0));
    $auto3 = max(0, (int)($game['auto3'] ?? 0));
    $auto4 = max(0, (int)($game['auto4'] ?? 0));
    $auto5 = max(0, (int)($game['auto5'] ?? 0));

    $hps =
        ($auto1 * 0.3) +
        ($auto2 * 1.0) +
        ($auto3 * 4.0) +
        ($auto4 * 12.0) +
        ($auto5 * 35.0);

    $prestigeMult = max(
        1.0,
        (float)($game['prestigeMult'] ?? 1)
    );

    $achievementMult = getAchievementMultiplier($game);
    $skin = getSkinBonuses($game);

    $hps *= $prestigeMult;
    $hps *= $achievementMult;
    $hps *= $skin['hps'];

    $boostEnd = (int)($game['boostEnd'] ?? 0);
    $boostMult = max(1.0, (float)($game['boostMult'] ?? 1));

    if ($boostEnd > time()) {
        $hps *= $boostMult;
    }

    return max(0.0, $hps);
}


/*
|--------------------------------------------------------------------------
| CLICK POWER
|--------------------------------------------------------------------------
*/

function calculateClickPower(array $game): float
{
    $clickPower = max(
        1.0,
        (float)($game['clickPower'] ?? 1)
    );

    $clickMult = max(
        1.0,
        (float)($game['clickMult'] ?? 1)
    );

    $prestigeMult = max(
        1.0,
        (float)($game['prestigeMult'] ?? 1)
    );

    $achievementMult = getAchievementMultiplier($game);
    $skin = getSkinBonuses($game);

    $power =
        $clickPower *
        $clickMult *
        $prestigeMult *
        $achievementMult *
        $skin['click'];

    $boostEnd = (int)($game['boostEnd'] ?? 0);
    $boostMult = max(1.0, (float)($game['boostMult'] ?? 1));

    if ($boostEnd > time()) {
        $power *= $boostMult;
    }

    return max(0.0, $power);
}


/*
|--------------------------------------------------------------------------
| CRIT
|--------------------------------------------------------------------------
*/

function calculateCritChance(array $game): float
{
    $base = max(
        0.0,
        min(0.5, (float)($game['critChance'] ?? 0))
    );

    $skin = getSkinBonuses($game);

    return min(
        0.5,
        max(0.0, $base + $skin['crit'])
    );
}


/*
|--------------------------------------------------------------------------
| BOOST COST
|--------------------------------------------------------------------------
*/

function calculateBoostCost(array $game, string $kind): int
{
    $isX5 = $kind === 'boost5';

    $n = $isX5
        ? max(0, (int)($game['boost5Bought'] ?? 0))
        : max(0, (int)($game['boost2Bought'] ?? 0));

    $base = $isX5 ? 15000 : 2000;
    $growth = $isX5 ? 1.85 : 1.70;

    $click = calculateClickPower($game);
    $hps   = calculateHPS($game);

    $powerTax = (int)floor(
        $click * ($isX5 ? 25 : 12) +
        $hps * ($isX5 ? 90 : 45)
    );

    return max(
        $base,
        (int)floor(
            $base * pow($growth, $n) +
            $powerTax
        )
    );
}


/*
|--------------------------------------------------------------------------
| SERVER ACHIEVEMENT UPDATE
|--------------------------------------------------------------------------
*/

function getPlayerRankServer(
    float $totalEarned,
    int $prestigeLevel
): array
{
    $total = max(0, $totalEarned);
    $prestige = max(0, $prestigeLevel);

    if ($prestige >= 10 || $total >= 1500000000) {
        return [
            'name' => 'General',
            'short' => 'GEN'
        ];
    }

    if ($prestige >= 7 || $total >= 800000000) {
        return [
            'name' => 'Colonel',
            'short' => 'COL'
        ];
    }

    if ($prestige >= 5 || $total >= 400000000) {
        return [
            'name' => 'Lieutenant Colonel',
            'short' => 'LTC'
        ];
    }

    if ($prestige >= 3 || $total >= 200000000) {
        return [
            'name' => 'Major',
            'short' => 'MAJ'
        ];
    }

    if ($total >= 100000000) {
        return [
            'name' => 'Captain',
            'short' => 'CPT'
        ];
    }

    if ($total >= 50000000) {
        return [
            'name' => 'First Lieutenant',
            'short' => '1LT'
        ];
    }

    if ($total >= 25000000) {
        return [
            'name' => 'Second Lieutenant',
            'short' => '2LT'
        ];
    }

    if ($total >= 12000000) {
        return [
            'name' => 'Sergeant Major',
            'short' => 'SGM'
        ];
    }

    if ($total >= 5000000) {
        return [
            'name' => 'First Sergeant',
            'short' => '1SG'
        ];
    }

    if ($total >= 2000000) {
        return [
            'name' => 'Master Sergeant',
            'short' => 'MSG'
        ];
    }

    if ($total >= 750000) {
        return [
            'name' => 'Sergeant First Class',
            'short' => 'SFC'
        ];
    }

    if ($total >= 300000) {
        return [
            'name' => 'Staff Sergeant',
            'short' => 'SSG'
        ];
    }

    if ($total >= 100000) {
        return [
            'name' => 'Sergeant',
            'short' => 'SGT'
        ];
    }

    if ($total >= 25000) {
        return [
            'name' => 'Corporal',
            'short' => 'CPL'
        ];
    }

    if ($total >= 5000) {
        return [
            'name' => 'Private First Class',
            'short' => 'PFC'
        ];
    }

    return [
        'name' => 'Private',
        'short' => 'PVT'
    ];
}

function updateServerAchievements(array &$game): void
{
    if (!isset($game['achievements']) || !is_array($game['achievements'])) {
        $game['achievements'] = [];
    }

    $total = (float)($game['totalEarned'] ?? 0);

    if ($total >= 1) {
        $game['achievements']['first'] = true;
    }

    if ($total >= 100) {
        $game['achievements']['hundred'] = true;
    }

    if ($total >= 1000) {
        $game['achievements']['thousand'] = true;
    }

    if ($total >= 10000) {
        $game['achievements']['tenk'] = true;
    }

    if ((int)($game['clickPower'] ?? 0) >= 10) {
        $game['achievements']['click10'] = true;
    }

    $autos =
        (int)($game['auto1'] ?? 0) +
        (int)($game['auto2'] ?? 0) +
        (int)($game['auto3'] ?? 0) +
        (int)($game['auto4'] ?? 0) +
        (int)($game['auto5'] ?? 0);

    if ($autos >= 10) {
        $game['achievements']['auto10'] = true;
    }

    if ((float)($game['critChance'] ?? 0) >= 0.1) {
        $game['achievements']['crit'] = true;
    }

    if ((int)($game['prestigeLevel'] ?? 0) >= 1) {
        $game['achievements']['prestige1'] = true;
    }

    if ((int)($game['prestigeLevel'] ?? 0) >= 3) {
        $game['achievements']['prestige3'] = true;
    }

    if ($total >= 1000000) {
        $game['achievements']['million'] = true;
    }
}

/*
|--------------------------------------------------------------------------
| GAME ACTIVITY FEED
|--------------------------------------------------------------------------
*/

function addGameActivity(
    PDO $db,
    string $wallet,
    string $eventType,
    string $eventText
): void
{
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
        $eventType,
        $eventText
    ]);
}