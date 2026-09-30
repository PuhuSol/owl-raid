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

/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
*/

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

    /*
    |--------------------------------------------------------------------------
    | SESSION WALLET
    |--------------------------------------------------------------------------
    |
    | Wallet artık frontend'den alınmıyor.
    | requireSessionWallet() authenticated session'daki wallet'i döndürür.
    |
    */

    $wallet = requireSessionWallet();

    if (
        !is_string($wallet) ||
        trim($wallet) === ''
    ) {
        echo json_encode([
            'success' => false,
            'error' => 'Session wallet required'
        ]);

        exit;
    }

    $wallet = trim($wallet);

    /*
    |--------------------------------------------------------------------------
    | DATABASE
    |--------------------------------------------------------------------------
    */

    $db = getDB();

    /*
    |--------------------------------------------------------------------------
    | PAYMENT CHECK
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
    | TRANSACTION
    |--------------------------------------------------------------------------
    */

    $db->beginTransaction();

    /*
    | loadGameState() uses FOR UPDATE.
    | This prevents concurrent tick requests from
    | awarding the same elapsed income twice.
    */

    $game = loadGameState(
        $db,
        $wallet
    );

    /*
    |--------------------------------------------------------------------------
    | SERVER STATE
    |--------------------------------------------------------------------------
    */

    $now = microtime(true);

    if (
        !isset($game['_server']) ||
        !is_array($game['_server'])
    ) {
        $game['_server'] = [];
    }

    /*
|--------------------------------------------------------------------------
| AUTO INCOME
|--------------------------------------------------------------------------
| Feather generation only works while the game tab is active.
| No offline accumulation.
*/

$tabActive = false;

try {
    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (
        is_array($input) &&
        isset($input['tabActive'])
    ) {
        $tabActive = ($input['tabActive'] === true);
    }
} catch (Throwable $e) {
    $tabActive = false;
}

$lastIncomeAt = (float)(
    $game['_server']['lastIncomeAt'] ?? $now
);

$elapsed = 0.0;

/*
|--------------------------------------------------------------------------
| ACTIVE TAB ONLY
|--------------------------------------------------------------------------
*/

if ($tabActive) {

    $elapsed = max(
        0.0,
        min(
            5.0,
            $now - $lastIncomeAt
        )
    );
}

/*
|--------------------------------------------------------------------------
| HPS
|--------------------------------------------------------------------------
*/

$hps = calculateHPS($game);

$gain = 0.0;

if (
    $tabActive &&
    $elapsed > 0 &&
    $hps > 0
) {

    $gain = $hps * $elapsed;

    if (
        !is_finite($gain) ||
        $gain < 0
    ) {
        $gain = 0.0;
    }

    if ($gain > 0) {

        $game['feathers'] =
            (float)($game['feathers'] ?? 0)
            + $gain;

        $game['totalEarned'] =
            (float)($game['totalEarned'] ?? 0)
            + $gain;
    }
}

    /*
    |--------------------------------------------------------------------------
    | UPDATE SERVER TIMESTAMP
    |--------------------------------------------------------------------------
    */

    $game['_server']['lastIncomeAt'] = $now;

    $game['lastSave'] = time();

    /*
    |--------------------------------------------------------------------------
    | ACHIEVEMENTS
    |--------------------------------------------------------------------------
    */

    updateServerAchievements($game);

    /*
    |--------------------------------------------------------------------------
    | SAVE
    |--------------------------------------------------------------------------
    */

    saveGameState(
        $db,
        $wallet,
        $game
    );

    $db->commit();

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'gain' => $gain,
        'hps' => calculateHPS($game),
        'game' => $game
    ]);

} catch (Throwable $e) {

    if (
        isset($db) &&
        $db instanceof PDO &&
        $db->inTransaction()
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