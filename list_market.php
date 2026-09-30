<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://puhucoin.com');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$possiblePaths = [
    dirname(__DIR__, 2) . '/app/config.php',
    dirname(__DIR__, 1) . '/../app/config.php',
    $_SERVER['DOCUMENT_ROOT'] . '/../app/config.php',
    '/home/' . get_current_user() . '/app/config.php',
];
$configPath = null;
foreach ($possiblePaths as $path) { if (file_exists($path)) { $configPath = $path; break; } }
if (!$configPath) { http_response_code(500); echo json_encode(['success' => false, 'error' => 'Config not found']); exit; }
require_once $configPath;
require_once dirname($configPath) . '/database.php';
require_once __DIR__ . '/require_session.php';
require_once __DIR__ . '/telegram_notify.php';

const MAX_LISTINGS = 8;
const MIN_USDC_RAW = 1000000;

function marketToRawUsdc($amount): int
{
    return (int)round((float)$amount * 1_000_000);
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) { echo json_encode(['success' => false, 'error' => 'Invalid data']); exit; }

    $wallet = trim((string)($data['wallet'] ?? ''));
    $itemId = trim((string)($data['itemId'] ?? ''));
    $source = trim((string)($data['source'] ?? 'bag')) === 'depo' ? 'depo' : 'bag';
    $currency = 'USDC';
    $priceRaw = marketToRawUsdc($data['price'] ?? 0);

    if ($priceRaw < MIN_USDC_RAW) {
        echo json_encode(['success' => false, 'error' => 'Min 1 USDC']);
        exit;
    }

    if ($wallet === '' || $itemId === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']); exit;
    }
    $wallet = assertSameWallet($wallet);

    $db = getDB();
    $db->beginTransaction();

    $cnt = $db->prepare('SELECT COUNT(*) FROM gear_market WHERE seller_wallet = ? AND sold_at IS NULL');
    $cnt->execute([$wallet]);
    if ((int)$cnt->fetchColumn() >= MAX_LISTINGS) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Max 8 listings']); exit;
    }

    $stmt = $db->prepare('SELECT game_data FROM game_saves WHERE wallet = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$wallet]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $db->rollBack(); echo json_encode(['success' => false, 'error' => 'No save']); exit; }

    $game = json_decode($row['game_data'] ?? '{}', true);
    if (!is_array($game)) $game = [];
    if (!isset($game['gear'][$source]) || !is_array($game['gear'][$source])) {
        $db->rollBack(); echo json_encode(['success' => false, 'error' => 'Item not found']); exit;
    }

    $idx = -1;
    foreach ($game['gear'][$source] as $i => $it) {
        if (is_array($it) && (string)($it['id'] ?? '') === $itemId) { $idx = (int)$i; break; }
    }
    if ($idx < 0) { $db->rollBack(); echo json_encode(['success' => false, 'error' => 'Item not found']); exit; }

    $item = $game['gear'][$source][$idx];
    array_splice($game['gear'][$source], $idx, 1);
    $game['gear'][$source] = array_values($game['gear'][$source]);

    $ins = $db->prepare('INSERT INTO gear_market (seller_wallet, item_json, currency, price_raw, created_at) VALUES (?, ?, ?, ?, NOW())');
    $ins->execute([$wallet, json_encode($item, JSON_UNESCAPED_UNICODE), $currency, $priceRaw]);
    $listingId = (int)$db->lastInsertId();

    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    $db->prepare('INSERT INTO gear_market_log
        (action, listing_id, seller_wallet, buyer_wallet, item_json, price_raw, currency, pay_sig, extra, ip, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute(['list', $listingId, $wallet, null, json_encode($item, JSON_UNESCAPED_UNICODE), $priceRaw, $currency, null, $source, $ip]);

    $db->prepare('UPDATE game_saves SET game_data = ?, last_save = NOW() WHERE wallet = ?')
        ->execute([json_encode($game, JSON_UNESCAPED_UNICODE), $wallet]);
    $db->commit();

    try {
        $sellerName = (string)($game['username'] ?? '');
        $priceUsdc  = $priceRaw / 1_000_000;
        telegramMarketListing($db, $wallet, $item, $priceUsdc, $sellerName, $listingId);
    } catch (Throwable $e) {
    }

    echo json_encode(['success' => true, 'gear' => $game['gear'], 'listingId' => $listingId]);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}