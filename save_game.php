<?php
// public_html/owlraid/save_game.php

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

    if (!is_array($data)) {
        $data = [];
    }

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

    /*
    |--------------------------------------------------------------------------
    | SESSION WALLET
    |--------------------------------------------------------------------------
    */

    $wallet = assertSameWallet($wallet);

    /*
    |--------------------------------------------------------------------------
    | PAYMENT CHECK
    |--------------------------------------------------------------------------
    */

    $db = getDB();

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

    /*
    |--------------------------------------------------------------------------
    | LOAD SERVER STATE
    |--------------------------------------------------------------------------
    */

    $db->beginTransaction();

    $game = loadGameState($db, $wallet);

    /*
    |--------------------------------------------------------------------------
    | SELECTED SKIN
    |--------------------------------------------------------------------------
    |
    | Client sadece seçili skin ID'sini gönderebilir.
    | Diğer game verileri kesinlikle client'tan alınmaz.
    |
    */

    if (array_key_exists('selectedSkin', $data)) {

        $selectedSkin = filter_var(
            $data['selectedSkin'],
            FILTER_VALIDATE_INT
        );

        if ($selectedSkin === false || $selectedSkin < 0) {
            throw new RuntimeException('Invalid skin');
        }

        /*
         * Burada maksimum ID'yi mevcut skin listenize göre
         * daha sonra daraltabiliriz.
         *
         * Şimdilik negatif ve geçersiz değerleri engelliyoruz.
         */
        $game['selectedSkin'] = $selectedSkin;
    }

    /*
    |--------------------------------------------------------------------------
    | SERVER ACHIEVEMENTS
    |--------------------------------------------------------------------------
    */

    updateServerAchievements($game);

    /*
    |--------------------------------------------------------------------------
    | SERVER SAVE
    |--------------------------------------------------------------------------
    */

    $game['lastSave'] = time();

    saveGameState(
        $db,
        $wallet,
        $game
    );

    $db->commit();

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

        $logValue = (int)(
            $game['totalEarned'] ?? 0
        );

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
            (?, 'save', ?, 'server', ?)
        ");

        $stmtLog->execute([
            $wallet,
            $logValue,
            $ip
        ]);

    } catch (Exception $logErr) {
        // Log hatası oyunu bozmasın.
    }

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
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