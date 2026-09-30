<?php
// public_html/owlraid/start_guild_war.php

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

const WAR_COOLDOWN_SECONDS = 60;      // 60 dk
const SAME_PAIR_SECONDS    = 43200;     // 12 saat
const WAR_WIN_POINTS       = 1000;
const CP_BAND_RATIOS       = [0.25, 0.40, 0.60]; // kademeli genişleme
const ROSTER_SIZE          = 5;

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

function calcCombatPowerFromGame(?array $game): int
{
    if (!is_array($game)) {
        $game = [];
    }
    $inv = $game['inventory'] ?? [];
    $w = (int)($inv['weapon']['level'] ?? 0);
    $a = (int)($inv['armor']['level'] ?? 0);
    $s = (int)($inv['shield']['level'] ?? 0);
    $h = (int)($inv['helmet']['level'] ?? 0);
    $critChance = (float)($game['critChance'] ?? 0);

    $attack  = 8 + ($w * 4);
    $hp      = 80 + ($a * 18);
    $defense = (int)floor(($a * 2.5) + ($s * 5) + ($h * 3.5));
    $crit    = 8 + ($w * 0.6) + ($critChance * 50);
    $block   = min(20.0, $s * 1.0);

    $eq = $game['gear']['equipped'] ?? [];
    if (is_array($eq)) {
        foreach ($eq as $item) {
            if (!is_array($item) || empty($item['bonuses'])) {
                continue;
            }
            $b = $item['bonuses'];
            $attack  += (int)($b['attack'] ?? 0);
            $hp      += (int)($b['hp'] ?? 0);
            $defense += (int)($b['defense'] ?? 0);
            $crit    += (float)($b['crit'] ?? 0);
            $block   += (float)($b['block'] ?? 0);
        }
    }

    $crit  = max(0.0, min(40.0, $crit));
    $block = max(0.0, min(25.0, $block));

    return (int)floor(($attack * 3) + ($hp * 0.4) + ($defense * 2.5) + ($crit * 4) + ($block * 6));
}

/**
 * @return array{total_cp:int, members:list<array{wallet:string,username:?string,cp:int}>}
 */
function loadGuildCombatRoster(PDO $db, int $guildId): array
{
    $stmt = $db->prepare("
        SELECT gm.wallet, gs.username, gs.game_data
        FROM guild_members gm
        LEFT JOIN game_saves gs ON gs.wallet = gm.wallet
        WHERE gm.guild_id = ?
    ");
    $stmt->execute([$guildId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $members = [];
    $total = 0;

    foreach ($rows as $row) {
        $game = json_decode($row['game_data'] ?? '{}', true);
        if (!is_array($game)) {
            $game = [];
        }
        $cp = calcCombatPowerFromGame($game);
        $username = trim((string)($row['username'] ?? ''));
        if ($username === '') {
            $username = trim((string)($game['username'] ?? ''));
        }
        if ($username === '' || strtolower($username) === 'null') {
            $username = null;
        }

        $members[] = [
            'wallet'   => $row['wallet'],
            'username' => $username,
            'cp'       => $cp,
        ];
        $total += $cp;
    }

    return ['total_cp' => $total, 'members' => $members];
}

function pickShowRoster(array $members, int $size = 5): array
{
    if (count($members) === 0) {
        return [];
    }
    shuffle($members);
    return array_slice($members, 0, min($size, count($members)));
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('Invalid JSON');
    }

    $wallet = trim((string)($input['wallet'] ?? ''));
    if ($wallet === '' || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $wallet)) {
        throw new Exception('Invalid wallet');
    }
    $wallet = assertSameWallet($wallet);

    $db = getDB();
    $db->beginTransaction();

    // Leader + guild lock
    $stmt = $db->prepare("
        SELECT gm.guild_id, gm.role, g.name, g.tag, g.war_points, g.last_war_at
        FROM guild_members gm
        INNER JOIN guilds g ON g.id = gm.guild_id
        WHERE gm.wallet = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$wallet]);
    $me = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$me) {
        $db->rollBack();
        throw new Exception('Not in a guild');
    }
    if ($me['role'] !== 'leader') {
        $db->rollBack();
        throw new Exception('Only the leader can start a guild war');
    }

    $attackerId = (int)$me['guild_id'];

    // 30 min cooldown
    if (!empty($me['last_war_at'])) {
        $last = strtotime($me['last_war_at']);
        $elapsed = time() - $last;
        if ($elapsed < WAR_COOLDOWN_SECONDS) {
            $db->rollBack();
            $left = WAR_COOLDOWN_SECONDS - $elapsed;
            throw new Exception('War cooldown: ' . ceil($left / 60) . ' min left');
        }
    }

    $attackerData = loadGuildCombatRoster($db, $attackerId);
    $attackerCp = $attackerData['total_cp'];
    if ($attackerCp < 1) {
        $db->rollBack();
        throw new Exception('Your guild combat power is too low');
    }

    // All other guilds + CP
    $stmt = $db->query("SELECT id, name, tag FROM guilds");
    $allGuilds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $candidates = [];
    foreach ($allGuilds as $g) {
        $gid = (int)$g['id'];
        if ($gid === $attackerId) {
            continue;
        }
        $data = loadGuildCombatRoster($db, $gid);
        if ($data['total_cp'] < 1 || count($data['members']) < 1) {
            continue;
        }
        $candidates[] = [
            'id'      => $gid,
            'name'    => $g['name'],
            'tag'     => $g['tag'],
            'total_cp'=> $data['total_cp'],
            'members' => $data['members'],
            'diff'    => abs($data['total_cp'] - $attackerCp),
        ];
    }

    if (count($candidates) === 0) {
        $db->rollBack();
        throw new Exception('No opponent guilds available');
    }

    // 12h same pair filter
    $pairOk = [];
    foreach ($candidates as $c) {
        $stmt = $db->prepare("
            SELECT id FROM guild_wars
            WHERE created_at >= (NOW() - INTERVAL 12 HOUR)
              AND (
                    (attacker_guild_id = ? AND defender_guild_id = ?)
                 OR (attacker_guild_id = ? AND defender_guild_id = ?)
              )
            LIMIT 1
        ");
        $stmt->execute([$attackerId, $c['id'], $c['id'], $attackerId]);
        if (!$stmt->fetch()) {
            $pairOk[] = $c;
        }
    }

    if (count($pairOk) === 0) {
        $db->rollBack();
        throw new Exception('No opponents available (pair cooldown)');
    }

    // CP band: widen until we have someone
    $pool = [];
    foreach (CP_BAND_RATIOS as $ratio) {
        $maxDiff = (int)max(1, floor($attackerCp * $ratio));
        $pool = array_values(array_filter($pairOk, static function ($c) use ($maxDiff) {
            return $c['diff'] <= $maxDiff;
        }));
        if (count($pool) > 0) {
            break;
        }
    }

    // Hâlâ yoksa en yakın 5'ten rastgele (band dışında ama en az kötü)
    if (count($pool) === 0) {
        usort($pairOk, static function ($a, $b) {
            return $a['diff'] <=> $b['diff'];
        });
        $pool = array_slice($pairOk, 0, min(5, count($pairOk)));
    }

    usort($pool, static function ($a, $b) {
        return $a['diff'] <=> $b['diff'];
    });
    $top = array_slice($pool, 0, min(10, count($pool)));
    $defender = $top[random_int(0, count($top) - 1)];

    $defenderCp = (int)$defender['total_cp'];

    // Result from total CP (+ mild randomness)
    $aScore = $attackerCp * (0.92 + (mt_rand(0, 1600) / 10000)); // ~0.92–1.08
    $dScore = $defenderCp * (0.92 + (mt_rand(0, 1600) / 10000));

    if ($aScore > $dScore) {
        $result = 'win';
    } elseif ($aScore < $dScore) {
        $result = 'loss';
    } else {
        $result = (mt_rand(0, 1) === 1) ? 'win' : 'loss';
    }

    $points = ($result === 'win') ? WAR_WIN_POINTS : 0;

    if ($result === 'win') {
        $stmt = $db->prepare("
            UPDATE guilds
            SET war_points = war_points + ?,
                wars_won = wars_won + 1,
                last_war_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$points, $attackerId]);

        $stmt = $db->prepare("
            UPDATE guilds
            SET wars_lost = wars_lost + 1
            WHERE id = ?
        ");
        $stmt->execute([(int)$defender['id']]);
    } else {
        $stmt = $db->prepare("
            UPDATE guilds
            SET wars_lost = wars_lost + 1,
                last_war_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$attackerId]);

        $stmt = $db->prepare("
            UPDATE guilds
            SET war_points = war_points + ?,
                wars_won = wars_won + 1
            WHERE id = ?
        ");
        $stmt->execute([WAR_WIN_POINTS, (int)$defender['id']]);
        // Defender wins → they get the 1000 points
        $points = 0; // awarded to attacker is 0; response will show who got points
    }

    $attackerRoster = pickShowRoster($attackerData['members'], ROSTER_SIZE);
    $defenderRoster = pickShowRoster($defender['members'], ROSTER_SIZE);

    $stmt = $db->prepare("
        INSERT INTO guild_wars (
            attacker_guild_id, defender_guild_id, attacker_leader_wallet,
            attacker_total_cp, defender_total_cp, result, points_awarded,
            attacker_roster, defender_roster
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $attackerId,
        (int)$defender['id'],
        $wallet,
        $attackerCp,
        $defenderCp,
        $result,
        ($result === 'win') ? WAR_WIN_POINTS : 0,
        json_encode($attackerRoster, JSON_UNESCAPED_UNICODE),
        json_encode($defenderRoster, JSON_UNESCAPED_UNICODE),
    ]);
    $warId = (int)$db->lastInsertId();

    $db->commit();

    echo json_encode([
        'success' => true,
        'war_id'  => $warId,
        'result'  => $result, // attacker perspective: win/loss
        'points_awarded_to_attacker' => ($result === 'win') ? WAR_WIN_POINTS : 0,
        'points_awarded_to_defender' => ($result === 'loss') ? WAR_WIN_POINTS : 0,
        'attacker' => [
            'guild_id'  => $attackerId,
            'name'      => $me['name'],
            'tag'       => $me['tag'],
            'total_cp'  => $attackerCp,
            'roster'    => $attackerRoster,
        ],
        'defender' => [
            'guild_id'  => (int)$defender['id'],
            'name'      => $defender['name'],
            'tag'       => $defender['tag'],
            'total_cp'  => $defenderCp,
            'roster'    => $defenderRoster,
        ],
        'cooldown_seconds' => WAR_COOLDOWN_SECONDS,
    ]);

} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}