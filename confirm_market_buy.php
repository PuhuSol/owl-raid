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

const USDC_MINT = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';
const BAG_CAP = 8;
const DEPO_CAP = 24;

function tokenUnitsFromBalance(?array $bal): int
{
    if (!$bal) return 0;
    return (int)($bal['uiTokenAmount']['amount'] ?? '0');
}

function heliusTx(string $signature): ?array
{
    $url = 'https://mainnet.helius-rpc.com/?api-key=' . urlencode(HELIUS_API_KEY);
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'getTransaction',
        'params' => [$signature, [
            'encoding' => 'jsonParsed',
            'maxSupportedTransactionVersion' => 0,
            'commitment' => 'confirmed',
        ]],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 || !$response) return null;
    $result = json_decode($response, true);
    return $result['result'] ?? null;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) { echo json_encode(['success' => false, 'error' => 'Invalid data']); exit; }

    $wallet = trim((string)($data['wallet'] ?? ''));
    $listingId = (int)($data['listingId'] ?? 0);
    $signature = trim((string)($data['signature'] ?? ''));

    if (
        $wallet === '' || $listingId < 1 || $signature === '' ||
        !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet) ||
        !preg_match('/^[1-9A-HJ-NP-Za-km-z]{64,128}$/', $signature)
    ) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']); exit;
    }
    $wallet = assertSameWallet($wallet);

    if (!defined('HELIUS_API_KEY') || HELIUS_API_KEY === '') {
        echo json_encode(['success' => false, 'error' => 'Helius API key not configured']); exit;
    }

    $db = getDB();
    $db->beginTransaction();

    $used = $db->prepare('SELECT id FROM gear_market WHERE pay_sig = ? LIMIT 1');
    $used->execute([$signature]);
    if ($used->fetch()) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Signature already used']); exit;
    }

    $lq = $db->prepare('SELECT * FROM gear_market WHERE id = ? LIMIT 1 FOR UPDATE');
    $lq->execute([$listingId]);
    $listing = $lq->fetch(PDO::FETCH_ASSOC);
    if (!$listing || $listing['sold_at'] !== null) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Listing unavailable']); exit;
    }

    if (($listing['currency'] ?? '') !== 'USDC') {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Only USDC listings']); exit;
    }

    $seller = (string)$listing['seller_wallet'];
    if ($seller === $wallet) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Cannot buy own listing']); exit;
    }

    $tx = heliusTx($signature);
    if (!$tx || ($tx['meta']['err'] ?? null) !== null) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Transaction failed or not found']); exit;
    }

    $accountKeys = [];
    foreach ($tx['transaction']['message']['accountKeys'] ?? [] as $k) {
        $accountKeys[] = is_array($k) ? (string)($k['pubkey'] ?? '') : (string)$k;
    }
    if (!in_array($wallet, $accountKeys, true)) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Buyer wallet not in transaction']); exit;
    }

    $index = static function (array $list): array {
        $out = [];
        foreach ($list as $b) {
            if (($b['mint'] ?? '') !== USDC_MINT) continue;
            $owner = (string)($b['owner'] ?? '');
            if ($owner !== '') $out[$owner] = $b;
        }
        return $out;
    };
    $pre = $index($tx['meta']['preTokenBalances'] ?? []);
    $post = $index($tx['meta']['postTokenBalances'] ?? []);
    $need = (int)$listing['price_raw'];
    $sellerGain = tokenUnitsFromBalance($post[$seller] ?? null) - tokenUnitsFromBalance($pre[$seller] ?? null);
    $buyerLoss  = tokenUnitsFromBalance($pre[$wallet] ?? null) - tokenUnitsFromBalance($post[$wallet] ?? null);

    if ($sellerGain < $need || $buyerLoss < $need) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Payment amount mismatch']); exit;
    }

    $item = json_decode($listing['item_json'] ?? '{}', true);
    if (!is_array($item) || empty($item['id'])) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Broken listing']); exit;
    }

    $stmt = $db->prepare('SELECT game_data FROM game_saves WHERE wallet = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$wallet]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $db->rollBack(); echo json_encode(['success' => false, 'error' => 'Buyer save missing']); exit; }
    $game = json_decode($row['game_data'] ?? '{}', true);
    if (!is_array($game)) $game = [];
    if (!isset($game['gear']) || !is_array($game['gear'])) $game['gear'] = [];
    if (!isset($game['gear']['bag']) || !is_array($game['gear']['bag'])) $game['gear']['bag'] = [];
    if (!isset($game['gear']['depo']) || !is_array($game['gear']['depo'])) $game['gear']['depo'] = [];

    if (count($game['gear']['bag']) < BAG_CAP) {
        $game['gear']['bag'][] = $item;
    } elseif (count($game['gear']['depo']) < DEPO_CAP) {
        $game['gear']['depo'][] = $item;
    } else {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Bag and stash full']); exit;
    }

    $db->prepare('UPDATE gear_market SET sold_at = NOW(), buyer_wallet = ?, pay_sig = ? WHERE id = ?')
        ->execute([$wallet, $signature, $listingId]);

    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    $db->prepare('INSERT INTO gear_market_log
        (action, listing_id, seller_wallet, buyer_wallet, item_json, price_raw, currency, pay_sig, extra, ip, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute(['buy', $listingId, $seller, $wallet, $listing['item_json'], $need, 'USDC', $signature, null, $ip]);

    $db->prepare('UPDATE game_saves SET game_data = ?, last_save = NOW() WHERE wallet = ?')
        ->execute([json_encode($game, JSON_UNESCAPED_UNICODE), $wallet]);
    $db->commit();

    echo json_encode(['success' => true, 'gear' => $game['gear']]);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}