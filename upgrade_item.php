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
    | INPUT
    |--------------------------------------------------------------------------
    */

    $data = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($data)) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid request'
        ]);
        exit;
    }

    $type = trim(
        (string)($data['type'] ?? '')
    );

    $allowedTypes = [
        'weapon',
        'armor',
        'shield',
        'helmet'
    ];

    if (!in_array($type, $allowedTypes, true)) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid upgrade type'
        ]);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | SESSION WALLET
    |--------------------------------------------------------------------------
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
    | loadGameState() locks the player's row with FOR UPDATE.
    */

    $game = loadGameState(
        $db,
        $wallet
    );

    /*
    |--------------------------------------------------------------------------
    | NORMALIZE INVENTORY
    |--------------------------------------------------------------------------
    */

    if (
        !isset($game['inventory']) ||
        !is_array($game['inventory'])
    ) {
        $game['inventory'] = [];
    }

    $inventoryDefaults = [
        'weapon' => ['level' => 0],
        'armor'  => ['level' => 0],
        'shield' => ['level' => 0],
        'helmet' => ['level' => 0],
    ];

    foreach ($inventoryDefaults as $key => $default) {

        if (
            !isset($game['inventory'][$key]) ||
            !is_array($game['inventory'][$key])
        ) {
            $game['inventory'][$key] = $default;
        }

        $level = (int)(
            $game['inventory'][$key]['level'] ?? 0
        );

        /*
        | Never allow negative levels.
        */

        $game['inventory'][$key]['level'] =
            max(0, $level);
    }

    /*
    |--------------------------------------------------------------------------
    | CURRENT LEVEL
    |--------------------------------------------------------------------------
    */

    $level = (int)(
        $game['inventory'][$type]['level'] ?? 0
    );

    /*
    |--------------------------------------------------------------------------
    | UPGRADE COST
    |--------------------------------------------------------------------------
    */

    $baseCosts = [
        'weapon' => 100,
        'armor'  => 130,
        'shield' => 90,
        'helmet' => 80,
    ];

    $cost = (int)floor(
        $baseCosts[$type]
        * pow(1.48, $level)
    );

    if ($cost < 1) {
        throw new RuntimeException(
            'Invalid upgrade cost'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FEATHER BALANCE
    |--------------------------------------------------------------------------
    */

    $feathers = (float)(
        $game['feathers'] ?? 0
    );

    if (!is_finite($feathers)) {
        $feathers = 0.0;
        $game['feathers'] = 0.0;
    }

    if ($feathers < $cost) {

        $db->rollBack();

        echo json_encode([
            'success' => false,
            'error' => 'Not enough feathers',
            'cost' => $cost,
            'feathers' => $feathers,
            'level' => $level
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | APPLY UPGRADE
    |--------------------------------------------------------------------------
    */

    $game['feathers'] =
        $feathers - $cost;

    $game['inventory'][$type]['level'] =
        $level + 1;

    /*
    |--------------------------------------------------------------------------
    | SERVER TIMESTAMP
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | ACTION LOG
    |--------------------------------------------------------------------------
    */

    try {

        $ip =
            $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? null;

        $stmtLog = $db->prepare("
            INSERT INTO owl_raid_actions
            (
                wallet,
                action_type,
                value,
                extra,
                ip
            )
            VALUES
            (?, 'item_upgrade', ?, ?, ?)
        ");

        $stmtLog->execute([
            $wallet,
            $cost,
            $type . ':level_' . $level . '_to_' . ($level + 1),
            $ip
        ]);

    } catch (Throwable $logErr) {
        /*
        | Logging failure must not break the upgrade.
        */
    }

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $db->commit();

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'type' => $type,
        'previousLevel' => $level,
        'level' => $level + 1,
        'cost' => $cost,
        'feathers' => $game['feathers'],
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