<?php

declare(strict_types=1);

header('Content-Type: application/json');

header('Access-Control-Allow-Origin: https://puhucoin.com');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
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

    echo json_encode([
        'success' => false,
        'error' => 'Config not found'
    ]);

    exit;
}

require_once $configPath;
require_once dirname($configPath) . '/database.php';
require_once __DIR__ . '/require_session.php';
require_once __DIR__ . '/game_state.php';

try {

    $data = json_decode(
        file_get_contents('php://input'),
        true
    );

    $wallet = trim(
        (string)($data['wallet'] ?? '')
    );

    if ($wallet === '') {
        echo json_encode([
            'success' => false,
            'error' => 'Wallet required'
        ]);

        exit;
    }

    $wallet = assertSameWallet($wallet);

    $db = getDB();

    /*
    |--------------------------------------------------------------------------
    | PAYMENT
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT wallet
        FROM paid_wallets
        WHERE wallet = ?
        LIMIT 1
    ");

    $stmt->execute([
        $wallet
    ]);

    if (!$stmt->fetch()) {
        echo json_encode([
            'success' => false,
            'error' => 'Payment required'
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | LOCK
    |--------------------------------------------------------------------------
    */

    $db->beginTransaction();

    $game = loadGameState(
        $db,
        $wallet
    );

    /*
    |--------------------------------------------------------------------------
    | OLD RANK
    |--------------------------------------------------------------------------
    */

    $oldTotalEarned = (float)(
        $game['totalEarned'] ?? 0
    );

    $oldPrestigeLevel = (int)(
        $game['prestigeLevel'] ?? 0
    );

    $oldRank = getPlayerRankServer(
        $oldTotalEarned,
        $oldPrestigeLevel
    );

    $now = microtime(true);

    if (
        !isset($game['_server']) ||
        !is_array($game['_server'])
    ) {
        $game['_server'] = [];
    }

    $lastClickAt = (float)(
        $game['_server']['lastClickAt'] ?? 0
    );

    $windowStart = (float)(
        $game['_server']['clickWindowStart'] ?? $now
    );

    $windowCount = (int)(
        $game['_server']['clickWindowCount'] ?? 0
    );

    /*
     * Minimum 100 ms between clicks.
     * Allows approximately 10 clicks per second.
     */

    if (
        $lastClickAt > 0 &&
        ($now - $lastClickAt) < 0.100
    ) {
        $db->rollBack();

        echo json_encode([
            'success' => false,
            'error' => 'Too fast'
        ]);

        exit;
    }

    /*
     * Maximum 10 clicks per second.
     */

    if (($now - $windowStart) >= 1.0) {
        $windowStart = $now;
        $windowCount = 0;
    }

    if ($windowCount >= 10) {
        $db->rollBack();

        echo json_encode([
            'success' => false,
            'error' => 'Rate limit'
        ]);

        exit;
    }

    $windowCount++;

    /*
    |--------------------------------------------------------------------------
    | AUTO INCOME
    |--------------------------------------------------------------------------
    */

    $lastIncomeAt = (float)(
        $game['_server']['lastIncomeAt'] ?? $now
    );

    $elapsed = max(
        0,
        min(
            86400,
            $now - $lastIncomeAt
        )
    );

    $autoGain = 0.0;

    if ($elapsed > 0) {

        $hps = calculateHPS($game);

        $autoGain = $hps * $elapsed;

        if ($autoGain > 0) {

            $game['feathers'] =
                (float)($game['feathers'] ?? 0)
                + $autoGain;

            $game['totalEarned'] =
                (float)($game['totalEarned'] ?? 0)
                + $autoGain;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CLICK
    |--------------------------------------------------------------------------
    */

    $power = calculateClickPower($game);

    $critChance = calculateCritChance($game);

    $isCrit = (
        mt_rand(0, 1000000) / 1000000
    ) < $critChance;

    if ($isCrit) {

        $critMult = max(
            1.0,
            (float)($game['critMult'] ?? 2)
        );

        $power *= $critMult;
    }

    $game['feathers'] =
        (float)($game['feathers'] ?? 0)
        + $power;

    $game['totalEarned'] =
        (float)($game['totalEarned'] ?? 0)
        + $power;

    /*
    |--------------------------------------------------------------------------
    | RANK ACTIVITY
    |--------------------------------------------------------------------------
    */

    $newTotalEarned = (float)(
        $game['totalEarned'] ?? 0
    );

    $newRank = getPlayerRankServer(
        $newTotalEarned,
        (int)($game['prestigeLevel'] ?? 0)
    );

    if ($newRank['name'] !== $oldRank['name']) {

        addGameActivity(
            $db,
            $wallet,
            'rank',
            $newRank['name']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SERVER STATE
    |--------------------------------------------------------------------------
    */

    $game['_server']['lastClickAt'] = $now;

    $game['_server']['clickWindowStart'] =
        $windowStart;

    $game['_server']['clickWindowCount'] =
        $windowCount;

    $game['_server']['lastIncomeAt'] =
        $now;

    $game['lastSave'] = time();

    updateServerAchievements($game);

    saveGameState(
        $db,
        $wallet,
        $game
    );

    $db->commit();

    echo json_encode([
        'success' => true,
        'gain' => $power,
        'autoGain' => $autoGain,
        'crit' => $isCrit,
        'game' => $game
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);

    exit;
}