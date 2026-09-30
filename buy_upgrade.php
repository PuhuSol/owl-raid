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
    $id = trim($data['id'] ?? '');

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

    $allowed = [
        'clickPower',
        'clickMult',
        'critChance',
        'auto1',
        'auto2',
        'auto3',
        'auto4',
        'auto5',
        'boost2',
        'boost5'
    ];

    if (!in_array($id, $allowed, true)) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid upgrade'
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

    $feathers = max(
        0.0,
        (float)($game['feathers'] ?? 0)
    );

    /*
    |--------------------------------------------------------------------------
    | SERVER COST
    |--------------------------------------------------------------------------
    */

    switch ($id) {

        case 'clickPower':

            $level = max(
                1,
                (int)($game['clickPower'] ?? 1)
            );

            $cost = (int)floor(
                50 * pow(1.35, $level - 1)
            );

            break;


        case 'clickMult':

            $value = max(
                1.0,
                (float)($game['clickMult'] ?? 1)
            );

            $cost = (int)floor(
                300 * pow(
                    1.6,
                    ($value - 1) * 4
                )
            );

            break;


        case 'critChance':

            $value = max(
                0.0,
                min(
                    0.5,
                    (float)($game['critChance'] ?? 0)
                )
            );

            $cost = (int)floor(
                800 * pow(
                    1.55,
                    $value * 50
                )
            );

            break;


        case 'auto1':

            $level = max(
                0,
                (int)($game['auto1'] ?? 0)
            );

            $cost = (int)floor(
                100 * pow(1.45, $level)
            );

            break;


        case 'auto2':

            $level = max(
                0,
                (int)($game['auto2'] ?? 0)
            );

            $cost = (int)floor(
                500 * pow(1.50, $level)
            );

            break;


        case 'auto3':

            $level = max(
                0,
                (int)($game['auto3'] ?? 0)
            );

            $cost = (int)floor(
                2500 * pow(1.55, $level)
            );

            break;


        case 'auto4':

            $level = max(
                0,
                (int)($game['auto4'] ?? 0)
            );

            $cost = (int)floor(
                12000 * pow(1.60, $level)
            );

            break;


        case 'auto5':

            $level = max(
                0,
                (int)($game['auto5'] ?? 0)
            );

            $cost = (int)floor(
                60000 * pow(1.65, $level)
            );

            break;


        case 'boost2':

            $cost = calculateBoostCost(
                $game,
                'boost2'
            );

            break;


        case 'boost5':

            $cost = calculateBoostCost(
                $game,
                'boost5'
            );

            break;


        default:

            throw new RuntimeException(
                'Invalid upgrade'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | BALANCE CHECK
    |--------------------------------------------------------------------------
    */

    if ($feathers < $cost) {

        $db->rollBack();

        echo json_encode([
            'success' => false,
            'error' => 'Not enough feathers',
            'cost' => $cost,
            'feathers' => $feathers
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | PURCHASE
    |--------------------------------------------------------------------------
    */

    $game['feathers'] =
        $feathers - $cost;

    switch ($id) {

        case 'clickPower':
            $game['clickPower'] =
                max(1, (int)($game['clickPower'] ?? 1)) + 1;
            break;


        case 'clickMult':
            $game['clickMult'] =
                max(1.0, (float)($game['clickMult'] ?? 1)) + 0.25;
            break;


        case 'critChance':
            $game['critChance'] = min(
                0.5,
                max(
                    0.0,
                    (float)($game['critChance'] ?? 0)
                ) + 0.02
            );
            break;


        case 'auto1':
            $game['auto1'] =
                max(0, (int)($game['auto1'] ?? 0)) + 1;
            break;


        case 'auto2':
            $game['auto2'] =
                max(0, (int)($game['auto2'] ?? 0)) + 1;
            break;


        case 'auto3':
            $game['auto3'] =
                max(0, (int)($game['auto3'] ?? 0)) + 1;
            break;


        case 'auto4':
            $game['auto4'] =
                max(0, (int)($game['auto4'] ?? 0)) + 1;
            break;


        case 'auto5':
            $game['auto5'] =
                max(0, (int)($game['auto5'] ?? 0)) + 1;
            break;


        case 'boost2':

            $game['boostMult'] = 2;
            $game['boostEnd'] = time() + 60;

            $game['boost2Bought'] =
                max(
                    0,
                    (int)($game['boost2Bought'] ?? 0)
                ) + 1;

            if (
                !isset($game['achievements']) ||
                !is_array($game['achievements'])
            ) {
                $game['achievements'] = [];
            }

            $game['achievements']['boostUsed'] = true;

            break;


        case 'boost5':

            $game['boostMult'] = 5;
            $game['boostEnd'] = time() + 30;

            $game['boost5Bought'] =
                max(
                    0,
                    (int)($game['boost5Bought'] ?? 0)
                ) + 1;

            if (
                !isset($game['achievements']) ||
                !is_array($game['achievements'])
            ) {
                $game['achievements'] = [];
            }

            $game['achievements']['boostUsed'] = true;

            break;
    }

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
        'upgrade' => $id,
        'cost' => $cost,
        'game' => $game
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