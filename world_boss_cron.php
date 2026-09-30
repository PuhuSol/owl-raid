<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

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

date_default_timezone_set('Europe/Istanbul');

try {

    $db = getDb();

    $now = new DateTime('now', new DateTimeZone('Europe/Istanbul'));
    
    
    /*
     * Aktif boss var mı?
     */
    $stmt = $db->query("
        SELECT id
        FROM world_boss
        WHERE status = 'active'
        ORDER BY id DESC
        LIMIT 1
    ");

    $activeBoss = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($activeBoss) {

    $stmt = $db->prepare("
        SELECT started_at
        FROM world_boss
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        (int)$activeBoss['id']
    ]);

    $activeBossData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($activeBossData) {

        $dbNow = new DateTime(
    $db->query("SELECT NOW()")->fetchColumn()
);

$startedAt = new DateTime(
    $activeBossData['started_at']
);

$expiresAt = clone $startedAt;
$expiresAt->modify('+4 hours');

if ($dbNow >= $expiresAt) {

            $stmt = $db->prepare("
                UPDATE world_boss
                SET
    status = 'dead',
    current_hp = 0,
    ended_at = CURRENT_TIMESTAMP,
    ended_reason = 'time_expired'
                WHERE id = ?
                  AND status = 'active'
            ");

            $stmt->execute([
    (int)$activeBoss['id']
]);

/*
 * World Boss LIVE ACTIVITY
 */
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
    '',
    'world_boss',
    'Ancient Owl Lord survived the raid — time expired!'
]);

} else {
    exit;
}
    }
}

    /*
     * Bu haftanın Pazar günü 20:00'si
     */
    $sunday = clone $now;

    $dayOfWeek = (int)$sunday->format('N');

    $daysUntilSunday = 7 - $dayOfWeek;

    if ($dayOfWeek === 7) {
        $daysUntilSunday = 0;
    }

    $sunday->modify("+{$daysUntilSunday} days");
    $sunday->setTime(20, 0, 0);

    /*
     * Henüz Pazar 20:00 olmadıysa çık
     */
    if ($now < $sunday) {
        exit;
    }

    /*
     * Bu Pazar için zaten boss oluşturulmuş mu?
     */
    $stmt = $db->prepare("
        SELECT id
        FROM world_boss
        WHERE started_at = ?
        LIMIT 1
    ");

    $stmt->execute([
        $sunday->format('Y-m-d H:i:s')
    ]);

    $existingBoss = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingBoss) {
        exit;
    }

    /*
     * Yeni World Boss
     */
    $bossName = 'Ancient Owl Lord';

    $maxHp = 1000000000;

    $stmt = $db->prepare("
        INSERT INTO world_boss
        (
            boss_name,
            max_hp,
            current_hp,
            status,
            started_at
        )
        VALUES
        (
            ?,
            ?,
            ?,
            'active',
            ?
        )
    ");

    $stmt->execute([
        $bossName,
        $maxHp,
        $maxHp,
        $sunday->format('Y-m-d H:i:s')
    ]);

} catch (Throwable $e) {

    error_log(
        'World Boss Cron Error: ' . $e->getMessage()
    );
}