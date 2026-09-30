<?php
// public_html/owlraid/buy_arena_boost.php

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
    echo json_encode(['success' => false, 'error' => 'Config not found']);
    exit;
}

require_once $configPath;
require_once dirname($configPath) . '/database.php';
require_once __DIR__ . '/require_session.php';

const USDC_MINT = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';
const BOOST_USDC_UNITS = 1000000; // 1 USDC

function boostTokenUnits(?array $bal): int
{
    if (!$bal) {
        return 0;
    }
    return (int)($bal['uiTokenAmount']['amount'] ?? '0');
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $wallet    = trim($data['wallet'] ?? '');
    $signature = trim($data['signature'] ?? '');
    $currency  = strtoupper(trim($data['currency'] ?? 'USDC'));

    if (
        $wallet === '' ||
        $signature === '' ||
        $currency !== 'USDC' ||
        !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet) ||
        !preg_match('/^[1-9A-HJ-NP-Za-km-z]{64,128}$/', $signature)
    ) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
        exit;
    }
    
    $wallet = assertSameWallet($wallet);

    if (!defined('HELIUS_API_KEY') || HELIUS_API_KEY === '') {
        echo json_encode(['success' => false, 'error' => 'Helius API key not configured']);
        exit;
    }

    if (!defined('TREASURY_WALLET') || TREASURY_WALLET === '') {
        echo json_encode(['success' => false, 'error' => 'Treasury not configured']);
        exit;
    }

    $treasury = TREASURY_WALLET;
    $heliusKey = HELIUS_API_KEY;
    $db = getDB();

    $stmt = $db->prepare("SELECT wallet FROM paid_wallets WHERE wallet = ? LIMIT 1");
    $stmt->execute([$wallet]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Payment required']);
        exit;
    }

    $stmt = $db->prepare("SELECT wallet FROM paid_wallets WHERE tx_signature = ? LIMIT 1");
    $stmt->execute([$signature]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Signature already used']);
        exit;
    }

    $stmt = $db->prepare("SELECT id FROM arena_boost_payments WHERE tx_signature = ? LIMIT 1");
    $stmt->execute([$signature]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Signature already used']);
        exit;
    }

    $url = "https://mainnet.helius-rpc.com/?api-key=" . urlencode($heliusKey);
    $payload = [
        'jsonrpc' => '2.0',
        'id'      => 1,
        'method'  => 'getTransaction',
        'params'  => [
            $signature,
            [
                'encoding' => 'jsonParsed',
                'maxSupportedTransactionVersion' => 0,
                'commitment' => 'confirmed'
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 20
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        echo json_encode(['success' => false, 'error' => 'Helius request failed']);
        exit;
    }

    $result = json_decode($response, true);
    $tx = $result['result'] ?? null;

    if (!$tx || ($tx['meta']['err'] ?? null) !== null) {
        echo json_encode(['success' => false, 'error' => 'Transaction failed or not found']);
        exit;
    }

    $accountKeys = [];
    foreach ($tx['transaction']['message']['accountKeys'] ?? [] as $k) {
        $accountKeys[] = is_array($k) ? (string)($k['pubkey'] ?? '') : (string)$k;
    }
    if (!in_array($wallet, $accountKeys, true)) {
        echo json_encode(['success' => false, 'error' => 'Wallet not in transaction']);
        exit;
    }

    $indexBalances = static function (array $list): array {
        $out = [];
        foreach ($list as $b) {
            if (($b['mint'] ?? '') !== USDC_MINT) {
                continue;
            }
            $owner = (string)($b['owner'] ?? '');
            if ($owner !== '') {
                $out[$owner] = $b;
            }
        }
        return $out;
    };

    $preBy  = $indexBalances($tx['meta']['preTokenBalances'] ?? []);
    $postBy = $indexBalances($tx['meta']['postTokenBalances'] ?? []);

    $treasuryGain = boostTokenUnits($postBy[$treasury] ?? null) - boostTokenUnits($preBy[$treasury] ?? null);
    $walletLoss   = boostTokenUnits($preBy[$wallet] ?? null) - boostTokenUnits($postBy[$wallet] ?? null);

    if ($treasuryGain < BOOST_USDC_UNITS || $walletLoss < BOOST_USDC_UNITS) {
        echo json_encode(['success' => false, 'error' => 'Valid 1 USDC transfer to treasury not found']);
        exit;
    }

    $db->beginTransaction();

    $stmt = $db->prepare("SELECT game_data FROM game_saves WHERE wallet = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$wallet]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Game not found']);
        exit;
    }

    $game = json_decode($row['game_data'] ?? '{}', true);
    if (!is_array($game)) {
        $game = [];
    }

    $now = time();
    $currentBoost = intval($game['arenaBoostUntil'] ?? 0);
    $base = $currentBoost > $now ? $currentBoost : $now;
    $game['arenaBoostUntil'] = $base + (7 * 24 * 60 * 60);

    $ins = $db->prepare("INSERT INTO arena_boost_payments (wallet, tx_signature, amount) VALUES (?, ?, ?)");
    $ins->execute([$wallet, $signature, $treasuryGain / 1_000_000]);

    $stmt = $db->prepare("UPDATE game_saves SET game_data = ?, last_save = NOW() WHERE wallet = ?");
    $stmt->execute([json_encode($game, JSON_UNESCAPED_UNICODE), $wallet]);

    $db->commit();

    echo json_encode([
        'success'         => true,
        'arenaBoostUntil' => $game['arenaBoostUntil'],
        'usdc'            => $treasuryGain / 1_000_000
    ]);

} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error'
    ]);
}