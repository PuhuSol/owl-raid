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

    $wallet = trim($data['wallet'] ?? '');

    if (
        $wallet === '' ||
        !preg_match(
            '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/',
            $wallet
        )
    ) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid wallet'
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

    $stmt->execute([$wallet]);

    if (!$stmt->fetch()) {
        echo json_encode([
            'success' => false,
            'error' => 'Payment required'
        ]);
        exit;
    }

    $db->beginTransaction();

    $game = loadGameState(
        $db,
        $wallet
    );

    $level = max(
        0,
        (int)($game['prestigeLevel'] ?? 0)
    );

    $feathers = max(
        0.0,
        (float)($game['feathers'] ?? 0)
    );

    /*
     * Frontend:
     * 100000 * Math.pow(3, game.prestigeLevel)
     */

    $required = 100000 * pow(
        3,
        $level
    );

    if ($feathers < $required) {

        $db->rollBack();

        echo json_encode([
            'success' => false,
            'error' => 'Not enough feathers',
            'required' => $required,
            'feathers' => $feathers
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | PRESTIGE
    |--------------------------------------------------------------------------
    */

    $newLevel = $level + 1;

    $game['prestigeLevel'] = $newLevel;
    
    addGameActivity(
    $db,
    $wallet,
    'prestige',
    'Prestige ' . $newLevel
);

    $game['prestigeMult'] =
        1 + ($newLevel * 0.35);

    $game['feathers'] = 0;

    $game['clickPower'] = 1;
    $game['clickMult'] = 1;
    $game['critChance'] = 0;

    $game['auto1'] = 0;
    $game['auto2'] = 0;
    $game['auto3'] = 0;
    $game['auto4'] = 0;
    $game['auto5'] = 0;

    $game['boostEnd'] = 0;
    $game['boostMult'] = 1;

    updateServerAchievements($game);

    $game['lastSave'] = time();

    saveGameState(
        $db,
        $wallet,
        $game
    );

    $db->commit();

    echo json_encode([
        'success' => true,
        'game' => $game,
        'prestigeLevel' => $newLevel,
        'prestigeMult' => $game['prestigeMult']
    ]);

} catch (Exception $e) {

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