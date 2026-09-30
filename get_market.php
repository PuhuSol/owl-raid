<?php
declare(strict_types=1);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://puhucoin.com');
header('Access-Control-Allow-Credentials: true');

$possiblePaths = [
    dirname(__DIR__, 2) . '/app/config.php',
    dirname(__DIR__, 1) . '/../app/config.php',
    $_SERVER['DOCUMENT_ROOT'] . '/../app/config.php',
    '/home/' . get_current_user() . '/app/config.php',
];
$configPath = null;
foreach ($possiblePaths as $path) { if (file_exists($path)) { $configPath = $path; break; } }
if (!$configPath) { http_response_code(500); echo json_encode(['success' => false]); exit; }
require_once $configPath;
require_once dirname($configPath) . '/database.php';
require_once __DIR__ . '/require_session.php';

$db = getDB();
$rows = $db->query("
    SELECT
        m.id,
        m.seller_wallet,
        m.item_json,
        m.currency,
        m.price_raw,
        m.created_at,
        s.username
    FROM gear_market m
    LEFT JOIN game_saves s ON s.wallet = m.seller_wallet
    WHERE m.sold_at IS NULL
    ORDER BY m.created_at DESC
    LIMIT 80
")->fetchAll(PDO::FETCH_ASSOC);

$out = [];
foreach ($rows as $r) {
    $item = json_decode($r['item_json'] ?? '{}', true);
    $raw = (int)$r['price_raw'];
    $out[] = [
        'id' => (int)$r['id'],
        'seller' => $r['seller_wallet'],
        'username' => $r['username'] ?: null,
        'currency' => $r['currency'],
        'price_raw' => $raw,
        'price' => $raw / 1_000_000,
        'item' => is_array($item) ? $item : null,
        'created_at' => $r['created_at'],
    ];
}
echo json_encode(['success' => true, 'listings' => $out]);