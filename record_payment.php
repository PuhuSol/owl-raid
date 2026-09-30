<?php
// public_html/owlraid/record_payment.php

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
const MIN_USDC_UNITS = 5000000; // 5 USDC, 6 decimals

function tokenUnitsFromBalance(?array $bal): int
{
    if (!$bal) {
        return 0;
    }
    $amt = $bal['uiTokenAmount']['amount'] ?? '0';
    return (int)$amt;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $wallet    = trim($data['wallet'] ?? '');
    $currency  = strtoupper(trim($data['currency'] ?? ''));
    $amount    = floatval($data['amount'] ?? 0);
    $signature = trim($data['signature'] ?? '');
    $ref       = strtolower(trim($data['ref'] ?? ''));

    if (
        $wallet === '' ||
        $signature === '' ||
        !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet) ||
        !preg_match('/^[1-9A-HJ-NP-Za-km-z]{64,128}$/', $signature)
    ) {
        echo json_encode(['success' => false, 'error' => 'Missing or invalid params']);
        exit;
    }

    if ($currency !== 'USDC') {
        echo json_encode(['success' => false, 'error' => 'Only USDC payments are accepted']);
        exit;
    }
    
    $wallet = assertSameWallet($wallet);

    if (!defined('HELIUS_API_KEY') || HELIUS_API_KEY === '') {
        echo json_encode(['success' => false, 'error' => 'Helius API key not configured']);
        exit;
    }

    if (!defined('TREASURY_WALLET') || TREASURY_WALLET === '') {
        echo json_encode(['success' => false, 'error' => 'Treasury wallet not configured']);
        exit;
    }

    $treasury = TREASURY_WALLET;
    $heliusKey = HELIUS_API_KEY;

    $db = getDB();

    $stmt = $db->prepare("SELECT wallet FROM paid_wallets WHERE tx_signature = ? LIMIT 1");
    $stmt->execute([$signature]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Signature already used']);
        exit;
    }

    $stmt = $db->prepare("SELECT wallet FROM paid_wallets WHERE wallet = ? LIMIT 1");
    $stmt->execute([$wallet]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => true, 'message' => 'Already paid']);
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
    $keys = $tx['transaction']['message']['accountKeys'] ?? [];
    foreach ($keys as $k) {
        if (is_array($k)) {
            $accountKeys[] = (string)($k['pubkey'] ?? '');
        } else {
            $accountKeys[] = (string)$k;
        }
    }
    if (!in_array($wallet, $accountKeys, true)) {
        echo json_encode(['success' => false, 'error' => 'Wallet is not a signer/account in this transaction']);
        exit;
    }

    $pre  = $tx['meta']['preTokenBalances'] ?? [];
    $post = $tx['meta']['postTokenBalances'] ?? [];

    $indexBalances = static function (array $list): array {
        $out = [];
        foreach ($list as $b) {
            if (($b['mint'] ?? '') !== USDC_MINT) {
                continue;
            }
            $owner = (string)($b['owner'] ?? '');
            if ($owner === '') {
                continue;
            }
            $out[$owner] = $b;
        }
        return $out;
    };

    $preByOwner  = $indexBalances(is_array($pre) ? $pre : []);
    $postByOwner = $indexBalances(is_array($post) ? $post : []);

    $treasuryGain = tokenUnitsFromBalance($postByOwner[$treasury] ?? null)
        - tokenUnitsFromBalance($preByOwner[$treasury] ?? null);

    $walletLoss = tokenUnitsFromBalance($preByOwner[$wallet] ?? null)
        - tokenUnitsFromBalance($postByOwner[$wallet] ?? null);

    if ($treasuryGain < MIN_USDC_UNITS || $walletLoss < MIN_USDC_UNITS) {
        echo json_encode([
            'success' => false,
            'error'   => 'Valid 5 USDC transfer to treasury not found'
        ]);
        exit;
    }

    $paidAmount = $treasuryGain / 1_000_000;

    $stmt = $db->prepare("
        INSERT INTO paid_wallets (wallet, currency, amount, tx_signature, paid_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            paid_at = NOW(),
            amount = VALUES(amount),
            tx_signature = VALUES(tx_signature),
            currency = VALUES(currency)
    ");
    $stmt->execute([$wallet, 'USDC', $paidAmount, $signature]);

    if ($ref !== '') {
        $stmt = $db->prepare("SELECT wallet FROM game_saves WHERE username = ? LIMIT 1");
        $stmt->execute([$ref]);
        $referrer = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($referrer && $referrer['wallet'] !== $wallet) {
            $stmt = $db->prepare("SELECT referred_by FROM game_saves WHERE wallet = ? LIMIT 1");
            $stmt->execute([$wallet]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing || empty($existing['referred_by'])) {
                $stmt = $db->prepare("
                    INSERT INTO game_saves (wallet, referred_by, game_data, last_save)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        referred_by = IF(referred_by IS NULL OR referred_by = '', VALUES(referred_by), referred_by)
                ");
                $stmt->execute([$wallet, $referrer['wallet'], json_encode(new stdClass())]);
            }
        }
    }

    echo json_encode([
        'success'    => true,
        'usdc'       => $paidAmount,
        'usdc_units' => $treasuryGain
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error'
    ]);
}