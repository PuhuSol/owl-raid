<?php
declare(strict_types=1);

$possiblePaths = [
    dirname(__DIR__, 2) . '/app/config.php',
    dirname(__DIR__, 1) . '/../app/config.php',
    $_SERVER['DOCUMENT_ROOT'] . '/../app/config.php',
    '/home/' . get_current_user() . '/app/config.php',
];
$configPath = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) { $configPath = $path; break; }
}
if (!$configPath) exit;
require_once $configPath;
require_once dirname($configPath) . '/database.php';
require_once __DIR__ . '/telegram_notify.php';

$db = getDB();
$token = telegramEnv('TELEGRAM_BOT_TOKEN');
if ($token === '') exit;

$stmt = $db->query("
    SELECT id, chat_id, message_id
    FROM telegram_market_posts
    WHERE deleted_at IS NULL
      AND created_at <= (NOW() - INTERVAL 15 MINUTE)
    LIMIT 50
");
$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

foreach ($rows as $row) {
    $url = 'https://api.telegram.org/bot' . $token . '/deleteMessage';
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query([
                'chat_id'    => $row['chat_id'],
                'message_id' => $row['message_id'],
            ]),
            'timeout' => 8,
        ],
    ]);
    @file_get_contents($url, false, $ctx);

    $up = $db->prepare('UPDATE telegram_market_posts SET deleted_at = NOW() WHERE id = ?');
    $up->execute([(int)$row['id']]);
}

echo "ok\n";