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

const BAG_CAP = 8;
const DEPO_CAP = 24;

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $wallet = trim((string)($data['wallet'] ?? ''));
    $listingId = (int)($data['listingId'] ?? 0);
    if ($wallet === '' || $listingId < 1 || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']); exit;
    }
    $wallet = assertSameWallet($wallet);

    $db = getDB();
    $db->beginTransaction();
    $lq = $db->prepare('SELECT * FROM gear_market WHERE id = ? LIMIT 1 FOR UPDATE');
    $lq->execute([$listingId]);
    $listing = $lq->fetch(PDO::FETCH_ASSOC);
    if (!$listing || $listing['sold_at'] !== null || $listing['seller_wallet'] !== $wallet) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Cannot cancel']); exit;
    }
    $item = json_decode($listing['item_json'] ?? '{}', true);
    if (!is_array($item)) { $db->rollBack(); echo json_encode(['success' => false, 'error' => 'Broken listing']); exit; }

    $stmt = $db->prepare('SELECT game_data FROM game_saves WHERE wallet = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$wallet]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $db->rollBack(); echo json_encode(['success' => false, 'error' => 'No save']); exit; }
    $game = json_decode($row['game_data'] ?? '{}', true);
    if (!is_array($game)) $game = [];
    if (!isset($game['gear']['bag']) || !is_array($game['gear']['bag'])) $game['gear']['bag'] = [];
    if (!isset($game['gear']['depo']) || !is_array($game['gear']['depo'])) $game['gear']['depo'] = [];

    if (count($game['gear']['bag']) < BAG_CAP) $game['gear']['bag'][] = $item;
    elseif (count($game['gear']['depo']) < DEPO_CAP) $game['gear']['depo'][] = $item;
    else { $db->rollBack(); echo json_encode(['success' => false, 'error' => 'Bag and stash full']); exit; }

    $db->prepare('UPDATE gear_market SET sold_at = NOW(), buyer_wallet = ? WHERE id = ?')->execute(['CANCEL', $listingId]);

    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    $db->prepare('INSERT INTO gear_market_log
        (action, listing_id, seller_wallet, buyer_wallet, item_json, price_raw, currency, pay_sig, extra, ip, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute(['cancel', $listingId, $wallet, null, $listing['item_json'], (int)$listing['price_raw'], $listing['currency'], null, 'CANCEL', $ip]);

    $db->prepare('UPDATE game_saves SET game_data = ?, last_save = NOW() WHERE wallet = ?')
        ->execute([json_encode($game, JSON_UNESCAPED_UNICODE), $wallet]);
    $db->commit();
    echo json_encode(['success' => true, 'gear' => $game['gear']]);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}