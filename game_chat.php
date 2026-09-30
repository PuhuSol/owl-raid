<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://puhucoin.com');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function chatResponse(array $data, int $status = 200): never
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

function chatError(string $message, int $status = 400): never
{
    chatResponse([
        'success' => false,
        'error' => $message
    ], $status);
}


/*
|--------------------------------------------------------------------------
| SESSION WALLET
|--------------------------------------------------------------------------
*/

try {

    /*
     * Wallet sadece session'dan doğrulanıyor.
     */
    $input = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $raw = file_get_contents('php://input');

        $input = json_decode(
            $raw,
            true
        );

        if (!is_array($input)) {
            chatError('Invalid JSON');
        }
    }

    /*
     * Client wallet gönderse bile gerçek wallet:
     * assertSameWallet() tarafından belirleniyor.
     */
    $requestedWallet = trim(
        (string)($input['wallet'] ?? $_GET['wallet'] ?? '')
    );

    if (
        $requestedWallet === '' ||
        !preg_match(
            '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/',
            $requestedWallet
        )
    ) {
        chatError('Invalid wallet');
    }

    $wallet = assertSameWallet($requestedWallet);

    $db = getDB();


    /*
    |--------------------------------------------------------------------------
    | ACTION
    |--------------------------------------------------------------------------
    */

    $action = $_GET['action'] ?? '';
    
    /*
|--------------------------------------------------------------------------
| INCOMING PRIVATE MESSAGES
|--------------------------------------------------------------------------
*/

if ($action === 'incoming') {

    $afterId = max(
        0,
        (int)($_GET['after_id'] ?? 0)
    );

    $limit = (int)(
        $_GET['limit'] ?? 20
    );

    $limit = max(
        1,
        min(20, $limit)
    );

    $stmt = $db->prepare("
        SELECT
            c.id,
            c.sender_wallet,
            c.receiver_wallet,
            c.message,
            c.created_at,
            COALESCE(
                NULLIF(gs.username, ''),
                LEFT(c.sender_wallet, 8)
            ) AS username
        FROM game_chat_messages c

        LEFT JOIN game_saves gs
            ON gs.wallet = c.sender_wallet

        WHERE c.channel_type = 'private'
          AND c.receiver_wallet = ?
          AND c.id > ?

        ORDER BY c.id ASC
        LIMIT {$limit}
    ");

    $stmt->execute([
        $wallet,
        $afterId
    ]);

    $rows = $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );

    chatResponse([
        'success' => true,
        'messages' => $rows
    ]);
}

/*
|--------------------------------------------------------------------------
| ACTIVITY FEED
|--------------------------------------------------------------------------
*/

if ($action === 'activity') {

    $afterId = max(
        0,
        (int)($_GET['after_id'] ?? 0)
    );

    $limit = (int)(
        $_GET['limit'] ?? 30
    );

    $limit = max(
        1,
        min(30, $limit)
    );

    $stmt = $db->prepare("
        SELECT
            a.id,
            a.wallet,
            a.event_type,
            a.event_text,
            CASE
    WHEN a.event_type = 'item_drop'
         AND a.event_text LIKE '%|%'
    THEN SUBSTRING_INDEX(a.event_text, '|', -1)
    ELSE NULL
END AS item_rarity,
            a.created_at,
            COALESCE(
                NULLIF(gs.username, ''),
                LEFT(a.wallet, 8)
            ) AS username
        FROM game_activity_feed a

        LEFT JOIN game_saves gs
            ON gs.wallet = a.wallet

        WHERE a.id > ?

        ORDER BY a.id ASC

        LIMIT {$limit}
    ");

    $stmt->execute([
        $afterId
    ]);

    $rows = $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );

    chatResponse([
        'success' => true,
        'activities' => $rows
    ]);
}

    /*
    |--------------------------------------------------------------------------
    | LIST
    |--------------------------------------------------------------------------
    */

    if ($action === 'list') {

        $channel = trim(
            (string)($_GET['channel'] ?? 'global')
        );

        if (
            !in_array(
                $channel,
                ['global', 'guild', 'private'],
                true
            )
        ) {
            chatError('Invalid channel');
        }


        $limit = (int)(
            $_GET['limit'] ?? 50
        );

        $limit = max(
            1,
            min(50, $limit)
        );


        /*
         * Son görülen mesaj ID'si.
         *
         * Böylece her polling'de bütün chat'i
         * tekrar çekmek zorunda kalmayız.
         */
        $afterId = max(
            0,
            (int)($_GET['after_id'] ?? 0)
        );


        /*
         |--------------------------------------------------------------------------
         | GLOBAL
         |--------------------------------------------------------------------------
         */

        if ($channel === 'global') {

            if ($afterId > 0) {

                $stmt = $db->prepare("
                    SELECT
                        c.id,
                        c.channel_type,
                        c.sender_wallet,
                        c.receiver_wallet,
                        c.guild_id,
                        c.message,
                        c.created_at,
                        COALESCE(
    NULLIF(gs.username, ''),
    LEFT(c.sender_wallet, 8)
) AS username
                    FROM game_chat_messages c

                    LEFT JOIN game_saves gs
                        ON gs.wallet = c.sender_wallet

                    WHERE c.channel_type = 'global'
                      AND c.id > ?

                    ORDER BY c.id ASC
                    LIMIT {$limit}
                ");

                $stmt->execute([
                    $afterId
                ]);

            } else {

                $stmt = $db->prepare("
                    SELECT
                        c.id,
                        c.channel_type,
                        c.sender_wallet,
                        c.receiver_wallet,
                        c.guild_id,
                        c.message,
                        c.created_at,
                        COALESCE(
    NULLIF(gs.username, ''),
    LEFT(c.sender_wallet, 8)
) AS username
                    FROM game_chat_messages c

                    LEFT JOIN game_saves gs
                        ON gs.wallet = c.sender_wallet

                    WHERE c.channel_type = 'global'

                    ORDER BY c.id DESC
                    LIMIT {$limit}
                ");

                $stmt->execute();

                $rows = $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                );

                $rows = array_reverse($rows);

                chatResponse([
                    'success' => true,
                    'messages' => $rows
                ]);
            }
        }


        /*
         |--------------------------------------------------------------------------
         | GUILD
         |--------------------------------------------------------------------------
         */

        if ($channel === 'guild') {

            /*
             * Guild ID CLIENT'TAN ALINMIYOR.
             *
             * Oyuncunun gerçek guild'i DB'den bulunuyor.
             */
            $stmt = $db->prepare("
                SELECT guild_id
                FROM guild_members
                WHERE wallet = ?
                LIMIT 1
            ");

            $stmt->execute([
                $wallet
            ]);

            $guildId = $stmt->fetchColumn();

            if (!$guildId) {
                chatError(
                    'You are not in a guild'
                );
            }

            $guildId = (int)$guildId;


            if ($afterId > 0) {

                $stmt = $db->prepare("
                    SELECT
                        c.id,
                        c.channel_type,
                        c.sender_wallet,
                        c.receiver_wallet,
                        c.guild_id,
                        c.message,
                        c.created_at,
                        COALESCE(
    NULLIF(gs.username, ''),
    LEFT(c.sender_wallet, 8)
) AS username
                    FROM game_chat_messages c

                    LEFT JOIN game_saves gs
                        ON gs.wallet = c.sender_wallet

                    WHERE c.channel_type = 'guild'
                      AND c.guild_id = ?
                      AND c.id > ?

                    ORDER BY c.id ASC
                    LIMIT {$limit}
                ");

                $stmt->execute([
                    $guildId,
                    $afterId
                ]);

            } else {

                $stmt = $db->prepare("
                    SELECT
                        c.id,
                        c.channel_type,
                        c.sender_wallet,
                        c.receiver_wallet,
                        c.guild_id,
                        c.message,
                        c.created_at,
                        COALESCE(
    NULLIF(gs.username, ''),
    LEFT(c.sender_wallet, 8)
) AS username
                    FROM game_chat_messages c

                    LEFT JOIN game_saves gs
                        ON gs.wallet = c.sender_wallet

                    
                    WHERE c.channel_type = 'guild'
                      AND c.guild_id = ?

                    ORDER BY c.id DESC
                    LIMIT {$limit}
                ");

                $stmt->execute([
                    $guildId
                ]);

                $rows = $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                );

                $rows = array_reverse($rows);

                chatResponse([
                    'success' => true,
                    'messages' => $rows
                ]);
            }
        }


        /*
         |--------------------------------------------------------------------------
         | PRIVATE
         |--------------------------------------------------------------------------
         */

        if ($channel === 'private') {

            $otherWallet = trim(
                (string)($_GET['with_wallet'] ?? '')
            );

            if (
                $otherWallet === '' ||
                !preg_match(
                    '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/',
                    $otherWallet
                )
            ) {
                chatError('Invalid private recipient');
            }

            if ($otherWallet === $wallet) {
                chatError('Invalid private recipient');
            }


            if ($afterId > 0) {

                $stmt = $db->prepare("
                    SELECT
                        c.id,
                        c.channel_type,
                        c.sender_wallet,
                        c.receiver_wallet,
                        c.guild_id,
                        c.message,
                        c.created_at,
                        COALESCE(
    NULLIF(gs.username, ''),
    LEFT(c.sender_wallet, 8)
) AS username
                    FROM game_chat_messages c

                    LEFT JOIN game_saves gs
                        ON gs.wallet = c.sender_wallet

                    
                    WHERE c.channel_type = 'private'
                      AND c.id > ?
                      AND (
                            (
                                c.sender_wallet = ?
                                AND c.receiver_wallet = ?
                            )
                            OR
                            (
                                c.sender_wallet = ?
                                AND c.receiver_wallet = ?
                            )
                      )

                    ORDER BY c.id ASC
                    LIMIT {$limit}
                ");

                $stmt->execute([
                    $afterId,
                    $wallet,
                    $otherWallet,
                    $otherWallet,
                    $wallet
                ]);

            } else {

                $stmt = $db->prepare("
                    SELECT
                        c.id,
                        c.channel_type,
                        c.sender_wallet,
                        c.receiver_wallet,
                        c.guild_id,
                        c.message,
                        c.created_at,
                        COALESCE(
    NULLIF(gs.username, ''),
    LEFT(c.sender_wallet, 8)
) AS username
                    FROM game_chat_messages c

                    LEFT JOIN game_saves gs
                        ON gs.wallet = c.sender_wallet

                    
                    WHERE c.channel_type = 'private'
                      AND (
                            (
                                c.sender_wallet = ?
                                AND c.receiver_wallet = ?
                            )
                            OR
                            (
                                c.sender_wallet = ?
                                AND c.receiver_wallet = ?
                            )
                      )

                    ORDER BY c.id DESC
                    LIMIT {$limit}
                ");

                $stmt->execute([
                    $wallet,
                    $otherWallet,
                    $otherWallet,
                    $wallet
                ]);

                $rows = $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                );

                $rows = array_reverse($rows);

                chatResponse([
                    'success' => true,
                    'messages' => $rows
                ]);
            }
        }


        /*
         * Incremental sonuçlar
         */
        $rows = $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

        chatResponse([
            'success' => true,
            'messages' => $rows
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | SEND
    |--------------------------------------------------------------------------
    */

    if (
        $action === 'send' &&
        $_SERVER['REQUEST_METHOD'] === 'POST'
    ) {

        $channel = trim(
            (string)($input['channel'] ?? '')
        );

        $message = trim(
            (string)($input['message'] ?? '')
        );


        if (
            !in_array(
                $channel,
                ['global', 'guild', 'private'],
                true
            )
        ) {
            chatError('Invalid channel');
        }


        if (
            $message === '' ||
            mb_strlen($message) > 120
        ) {
            chatError(
                'Message must be 1-120 characters'
            );
        }


        /*
         * Flood protection:
         * aynı wallet maksimum 1 mesaj / 2 saniye.
         */
        $stmt = $db->prepare("
            SELECT id
            FROM game_chat_messages
            WHERE sender_wallet = ?
              AND created_at >= (NOW() - INTERVAL 2 SECOND)
            LIMIT 1
        ");

        $stmt->execute([
            $wallet
        ]);

        if ($stmt->fetch()) {
            chatError(
                'Please wait before sending another message'
            );
        }


        /*
         |--------------------------------------------------------------------------
         | GLOBAL
         |--------------------------------------------------------------------------
         */

        if ($channel === 'global') {

            $stmt = $db->prepare("
                INSERT INTO game_chat_messages
                (
                    channel_type,
                    sender_wallet,
                    receiver_wallet,
                    guild_id,
                    message
                )
                VALUES
                (
                    'global',
                    ?,
                    NULL,
                    NULL,
                    ?
                )
            ");

            $stmt->execute([
                $wallet,
                $message
            ]);

            chatResponse([
                'success' => true,
                'id' => (int)$db->lastInsertId()
            ]);
        }


        /*
         |--------------------------------------------------------------------------
         | GUILD
         |--------------------------------------------------------------------------
         */

        if ($channel === 'guild') {

            /*
             * Guild ID yine CLIENT'tan alınmıyor.
             */
            $stmt = $db->prepare("
                SELECT guild_id
                FROM guild_members
                WHERE wallet = ?
                LIMIT 1
            ");

            $stmt->execute([
                $wallet
            ]);

            $guildId = $stmt->fetchColumn();

            if (!$guildId) {
                chatError(
                    'You are not in a guild'
                );
            }

            $guildId = (int)$guildId;


            $stmt = $db->prepare("
                INSERT INTO game_chat_messages
                (
                    channel_type,
                    sender_wallet,
                    receiver_wallet,
                    guild_id,
                    message
                )
                VALUES
                (
                    'guild',
                    ?,
                    NULL,
                    ?,
                    ?
                )
            ");

            $stmt->execute([
                $wallet,
                $guildId,
                $message
            ]);

            chatResponse([
                'success' => true,
                'id' => (int)$db->lastInsertId()
            ]);
        }


        /*
         |--------------------------------------------------------------------------
         | PRIVATE
         |--------------------------------------------------------------------------
         */

        if ($channel === 'private') {

            $receiver = trim(
                (string)($input['receiver_wallet'] ?? '')
            );

            if (
                $receiver === '' ||
                !preg_match(
                    '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/',
                    $receiver
                )
            ) {
                chatError(
                    'Invalid recipient'
                );
            }


            if ($receiver === $wallet) {
                chatError(
                    'You cannot message yourself'
                );
            }


            /*
             * Receiver gerçekten oyunda var mı?
             */
            $stmt = $db->prepare("
                SELECT wallet
                FROM game_saves
                WHERE wallet = ?
                LIMIT 1
            ");

            $stmt->execute([
                $receiver
            ]);

            if (!$stmt->fetchColumn()) {
                chatError(
                    'Player not found'
                );
            }


            $stmt = $db->prepare("
                INSERT INTO game_chat_messages
                (
                    channel_type,
                    sender_wallet,
                    receiver_wallet,
                    guild_id,
                    message
                )
                VALUES
                (
                    'private',
                    ?,
                    ?,
                    NULL,
                    ?
                )
            ");

            $stmt->execute([
                $wallet,
                $receiver,
                $message
            ]);

            chatResponse([
                'success' => true,
                'id' => (int)$db->lastInsertId()
            ]);
        }
    }


    chatError(
        'Invalid action'
    );

} catch (Throwable $e) {

    error_log(
        'game_chat.php: ' . $e->getMessage()
    );

    chatError(
        'Server error',
        500
    );
}