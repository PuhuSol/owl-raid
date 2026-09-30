<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://puhucoin.com');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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
        'error'   => 'Config not found'
    ]);

    exit;
}

require_once $configPath;
require_once dirname($configPath) . '/database.php';
require_once __DIR__ . '/require_session.php';


/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

function playerSearchResponse(array $data, int $status = 200): never
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

function playerSearchError(string $message, int $status = 400): never
{
    playerSearchResponse([
        'success' => false,
        'error'   => $message
    ], $status);
}


/*
|--------------------------------------------------------------------------
| MAIN
|--------------------------------------------------------------------------
*/

try {

    /*
     * İstekten gelen wallet
     */
    $requestedWallet = trim(
        (string)($_GET['wallet'] ?? '')
    );

    if (
        $requestedWallet === '' ||
        !preg_match(
            '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/',
            $requestedWallet
        )
    ) {
        playerSearchError('Invalid wallet');
    }

    /*
     * Wallet session ile doğrulanıyor.
     */
    $wallet = assertSameWallet($requestedWallet);


    /*
     * Arama kelimesi.
     */
    $query = trim(
        (string)($_GET['q'] ?? '')
    );


    /*
     * Çok kısa aramaları DB'ye göndermiyoruz.
     */
    if (mb_strlen($query) < 2) {
        playerSearchResponse([
            'success' => true,
            'players' => []
        ]);
    }


    /*
     * Maksimum 24 karakter.
     */
    $query = mb_substr($query, 0, 24);


    /*
     * LIKE wildcard karakterlerini escape et.
     *
     * ! escape karakteri olarak kullanılıyor.
     */
    $query = str_replace(
        ['!', '%', '_'],
        ['!!', '!%', '!_'],
        $query
    );


    /*
     * Database
     */
    $db = getDB();


    /*
     * Username üzerinden oyuncu arıyoruz.
     *
     * Kendi hesabını sonuçlardan çıkartıyoruz.
     *
     * Wallet frontend'e yalnızca seçilen oyuncuya
     * private chat açabilmek için gönderiliyor.
     */
    $stmt = $db->prepare("
        SELECT
            wallet,
            username
        FROM game_saves
        WHERE wallet <> ?
          AND username IS NOT NULL
          AND username <> ''
          AND username LIKE ? ESCAPE '!'
        ORDER BY username ASC
        LIMIT 10
    ");


    $stmt->execute([
        $wallet,
        '%' . $query . '%'
    ]);


    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $players = [];


    foreach ($rows as $row) {

        $username = trim(
            (string)($row['username'] ?? '')
        );

        $playerWallet = trim(
            (string)($row['wallet'] ?? '')
        );


        if (
            $username === '' ||
            $playerWallet === ''
        ) {
            continue;
        }


        $players[] = [
            'wallet'   => $playerWallet,
            'username' => $username
        ];
    }


    playerSearchResponse([
        'success' => true,
        'players' => $players
    ]);


} catch (Throwable $e) {

    /*
     * Gerçek PHP hatasını server loguna yaz.
     * Kullanıcıya güvenli hata mesajı dön.
     */
    error_log(
        'search_chat_players.php: ' . $e->getMessage()
    );

    playerSearchError(
        'Server error',
        500
    );
}