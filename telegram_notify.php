<?php
declare(strict_types=1);

const TG_WALLET_COOLDOWN_SEC = 90;
const TG_WALLET_MAX_PER_HOUR = 4;
const TG_GLOBAL_MAX_PER_MIN  = 8;

function telegramEnv(string $key): string
{
    if (function_exists('env')) {
        return (string)env($key, '');
    }
    $v = getenv($key);
    return $v !== false ? (string)$v : '';
}

function telegramSend(string $text, string $photoUrl = ''): ?int
{
    $token = telegramEnv('TELEGRAM_BOT_TOKEN');
    $chat  = telegramEnv('TELEGRAM_CHANNEL_ID');
    if ($token === '' || $chat === '') {
        return null;
    }

    $isPhoto = $photoUrl !== '';
    $url = 'https://api.telegram.org/bot' . $token . ($isPhoto ? '/sendPhoto' : '/sendMessage');

    $payload = [
        'chat_id'                  => $chat,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => '1',
    ];

    if ($isPhoto) {
        $payload['photo']   = $photoUrl;
        $payload['caption'] = $text;
    } else {
        $payload['text'] = $text;
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($payload),
            'timeout' => 10,
        ],
    ]);

    $res = @file_get_contents($url, false, $ctx);
    if ($res === false && $isPhoto) {
        return telegramSend($text, '');
    }
    if ($res === false) {
        return null;
    }

    $json = json_decode($res, true);
    $mid  = $json['result']['message_id'] ?? null;
    return is_numeric($mid) ? (int)$mid : null;
}

function telegramAllowed(?PDO $db, string $wallet): bool
{
    if (!$db || $wallet === '') {
        return false;
    }

    $q = $db->prepare("
        SELECT created_at
        FROM gear_market_log
        WHERE action = 'list' AND seller_wallet = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $q->execute([$wallet]);
    $last = $q->fetchColumn();
    if ($last && (time() - strtotime((string)$last)) < TG_WALLET_COOLDOWN_SEC) {
        return false;
    }

    $q = $db->prepare("
        SELECT COUNT(*)
        FROM gear_market_log
        WHERE action = 'list' AND seller_wallet = ?
          AND created_at >= (NOW() - INTERVAL 1 HOUR)
    ");
    $q->execute([$wallet]);
    if ((int)$q->fetchColumn() > TG_WALLET_MAX_PER_HOUR) {
        return false;
    }

    $q = $db->query("
        SELECT COUNT(*)
        FROM gear_market_log
        WHERE action = 'list'
          AND created_at >= (NOW() - INTERVAL 1 MINUTE)
    ");
    if ($q && (int)$q->fetchColumn() > TG_GLOBAL_MAX_PER_MIN) {
        return false;
    }

    return true;
}

function telegramMarketListing(
    ?PDO $db,
    string $wallet,
    array $item,
    float $priceUsdc,
    string $sellerName,
    int $listingId = 0
): void {
    if (!telegramAllowed($db, $wallet)) {
        return;
    }

    $name   = htmlspecialchars((string)($item['name'] ?? 'Item'), ENT_QUOTES, 'UTF-8');
    $rarity = strtoupper((string)($item['rarity'] ?? 'common'));
    $slot   = htmlspecialchars((string)($item['slot'] ?? ''), ENT_QUOTES, 'UTF-8');
    $seller = htmlspecialchars($sellerName !== '' ? $sellerName : 'Unknown', ENT_QUOTES, 'UTF-8');
    $price  = rtrim(rtrim(number_format($priceUsdc, 2, '.', ''), '0'), '.');
    $link   = 'https://puhucoin.com/owlraid/';
    $defId  = preg_replace('/[^a-z0-9_]/i', '', (string)($item['defId'] ?? ''));
    $photo  = $defId !== '' ? ('https://puhucoin.com/owlraid/icons/gear/' . $defId . '.png') : '';

    $b = is_array($item['bonuses'] ?? null) ? $item['bonuses'] : [];
    $stats = [];
    if (!empty($b['attack']))  $stats[] = '+' . $b['attack'] . ' ATK';
    if (!empty($b['hp']))      $stats[] = '+' . $b['hp'] . ' HP';
    if (!empty($b['defense'])) $stats[] = '+' . $b['defense'] . ' DEF';
    if (!empty($b['crit']))    $stats[] = '+' . $b['crit'] . ' CRIT';
    if (!empty($b['block']))   $stats[] = '+' . $b['block'] . ' BLK';
    $statLine = $stats ? implode(' · ', $stats) : '—';

    $text =
        "🦉 <b>Owl Raid Market</b>\n" .
        "━━━━━━━━━━━━━━\n" .
        "📦 <b>{$name}</b>\n" .
        "✨ {$rarity} · {$slot}\n" .
        "📊 {$statLine}\n" .
        "💵 <b>{$price} USDC</b>\n" .
        "👤 Seller: {$seller}\n" .
        "━━━━━━━━━━━━━━\n" .
        "<a href=\"{$link}\">Open Market</a>";

    $messageId = telegramSend($text, $photo);
    if (!$db || $messageId === null || $listingId <= 0) {
        return;
    }

    $chat = telegramEnv('TELEGRAM_CHANNEL_ID');
    $ins = $db->prepare('
        INSERT INTO telegram_market_posts (listing_id, chat_id, message_id, created_at)
        VALUES (?, ?, ?, NOW())
    ');
    $ins->execute([$listingId, $chat, $messageId]);
}