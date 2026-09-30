<?php

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

/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
*/

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
require_once __DIR__ . '/require_session.php';
require_once __DIR__ . '/game_state.php';


try {

    /*
    |--------------------------------------------------------------------------
    | INPUT
    |--------------------------------------------------------------------------
    */

    $data = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($data)) {
        $data = [];
    }

    $wallet = trim(
        (string)($data['wallet'] ?? '')
    );

    if ($wallet === '') {
        echo json_encode([
            'success' => false,
            'error' => 'Wallet required'
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | WALLET
    |--------------------------------------------------------------------------
    */

    $wallet = assertSameWallet($wallet);

    /*
    |--------------------------------------------------------------------------
    | DATABASE
    |--------------------------------------------------------------------------
    */

    $db = getDB();

    /*
    |--------------------------------------------------------------------------
    | PAYMENT
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT wallet
        FROM paid_wallets
        WHERE wallet = ?
        LIMIT 1
    ");

    $stmt->execute([
        $wallet
    ]);

    if (!$stmt->fetch()) {

        echo json_encode([
            'success' => false,
            'error' => 'Payment required'
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | GEAR DEFINITIONS
    |--------------------------------------------------------------------------
    */

    $GEAR_DEFS = [

        'rusty_sword' => [
            'name' => 'Rusty Sword',
            'slot' => 'weapon',
            'rarity' => 'common',
            'bonuses' => [
                'attack' => 3,
                'hp' => 0,
                'defense' => 0,
                'crit' => 0.4,
                'block' => 0
            ]
        ],

        'iron_armor' => [
            'name' => 'Iron Armor',
            'slot' => 'armor',
            'rarity' => 'common',
            'bonuses' => [
                'attack' => 0,
                'hp' => 12,
                'defense' => 3,
                'crit' => 0,
                'block' => 0
            ]
        ],

        'wood_shield' => [
            'name' => 'Wood Shield',
            'slot' => 'shield',
            'rarity' => 'common',
            'bonuses' => [
                'attack' => 0,
                'hp' => 0,
                'defense' => 4,
                'crit' => 0,
                'block' => 1
            ]
        ],

        'leather_helm' => [
            'name' => 'Leather Helm',
            'slot' => 'helmet',
            'rarity' => 'common',
            'bonuses' => [
                'attack' => 0,
                'hp' => 6,
                'defense' => 2,
                'crit' => 0,
                'block' => 0
            ]
        ],

        'copper_ear' => [
            'name' => 'Copper Earring',
            'slot' => 'earring',
            'rarity' => 'common',
            'bonuses' => [
                'attack' => 1,
                'hp' => 0,
                'defense' => 0,
                'crit' => 0.3,
                'block' => 0
            ]
        ],

        'bone_neck' => [
            'name' => 'Bone Necklace',
            'slot' => 'necklace',
            'rarity' => 'common',
            'bonuses' => [
                'attack' => 0,
                'hp' => 8,
                'defense' => 0,
                'crit' => 0,
                'block' => 0
            ]
        ],

        'cloth_band' => [
            'name' => 'Cloth Band',
            'slot' => 'bracelet',
            'rarity' => 'common',
            'bonuses' => [
                'attack' => 1,
                'hp' => 0,
                'defense' => 1,
                'crit' => 0,
                'block' => 0
            ]
        ],

        'worn_boots' => [
            'name' => 'Worn Boots',
            'slot' => 'boots',
            'rarity' => 'common',
            'bonuses' => [
                'attack' => 0,
                'hp' => 4,
                'defense' => 1,
                'crit' => 0,
                'block' => 0.3
            ]
        ],

        'steel_sword' => [
            'name' => 'Steel Sword',
            'slot' => 'weapon',
            'rarity' => 'rare',
            'bonuses' => [
                'attack' => 6,
                'hp' => 0,
                'defense' => 0,
                'crit' => 0.8,
                'block' => 0
            ]
        ],

        'steel_armor' => [
            'name' => 'Steel Armor',
            'slot' => 'armor',
            'rarity' => 'rare',
            'bonuses' => [
                'attack' => 0,
                'hp' => 20,
                'defense' => 5,
                'crit' => 0,
                'block' => 0
            ]
        ],

        'iron_shield' => [
            'name' => 'Iron Shield',
            'slot' => 'shield',
            'rarity' => 'rare',
            'bonuses' => [
                'attack' => 0,
                'hp' => 4,
                'defense' => 6,
                'crit' => 0,
                'block' => 1.5
            ]
        ],

        'iron_helm' => [
            'name' => 'Iron Helm',
            'slot' => 'helmet',
            'rarity' => 'rare',
            'bonuses' => [
                'attack' => 0,
                'hp' => 10,
                'defense' => 4,
                'crit' => 0,
                'block' => 0
            ]
        ],

        'silver_ear' => [
            'name' => 'Silver Earring',
            'slot' => 'earring',
            'rarity' => 'rare',
            'bonuses' => [
                'attack' => 2,
                'hp' => 0,
                'defense' => 0,
                'crit' => 0.6,
                'block' => 0
            ]
        ],

        'wolf_neck' => [
            'name' => 'Wolf Necklace',
            'slot' => 'necklace',
            'rarity' => 'rare',
            'bonuses' => [
                'attack' => 2,
                'hp' => 10,
                'defense' => 0,
                'crit' => 0.3,
                'block' => 0
            ]
        ],

        'iron_band' => [
            'name' => 'Iron Band',
            'slot' => 'bracelet',
            'rarity' => 'rare',
            'bonuses' => [
                'attack' => 2,
                'hp' => 0,
                'defense' => 2,
                'crit' => 0.2,
                'block' => 0
            ]
        ],

        'hunter_boots' => [
            'name' => 'Hunter Boots',
            'slot' => 'boots',
            'rarity' => 'rare',
            'bonuses' => [
                'attack' => 0,
                'hp' => 8,
                'defense' => 2,
                'crit' => 0,
                'block' => 0.6
            ]
        ],

        'rune_blade' => [
            'name' => 'Rune Blade',
            'slot' => 'weapon',
            'rarity' => 'epic',
            'bonuses' => [
                'attack' => 10,
                'hp' => 0,
                'defense' => 0,
                'crit' => 1.2,
                'block' => 0
            ]
        ],

        'rune_plate' => [
            'name' => 'Rune Plate',
            'slot' => 'armor',
            'rarity' => 'epic',
            'bonuses' => [
                'attack' => 0,
                'hp' => 32,
                'defense' => 8,
                'crit' => 0,
                'block' => 0
            ]
        ],

        'tower_shield' => [
            'name' => 'Tower Shield',
            'slot' => 'shield',
            'rarity' => 'epic',
            'bonuses' => [
                'attack' => 0,
                'hp' => 8,
                'defense' => 10,
                'crit' => 0,
                'block' => 2.5
            ]
        ],

        'knight_helm' => [
            'name' => 'Knight Helm',
            'slot' => 'helmet',
            'rarity' => 'epic',
            'bonuses' => [
                'attack' => 2,
                'hp' => 16,
                'defense' => 6,
                'crit' => 0,
                'block' => 0
            ]
        ],

        'moon_ear' => [
            'name' => 'Moon Earring',
            'slot' => 'earring',
            'rarity' => 'epic',
            'bonuses' => [
                'attack' => 4,
                'hp' => 0,
                'defense' => 0,
                'crit' => 1.0,
                'block' => 0
            ]
        ],

        'fang_neck' => [
            'name' => 'Fang Necklace',
            'slot' => 'necklace',
            'rarity' => 'epic',
            'bonuses' => [
                'attack' => 3,
                'hp' => 16,
                'defense' => 2,
                'crit' => 0.5,
                'block' => 0
            ]
        ],

        'battle_band' => [
            'name' => 'Battle Band',
            'slot' => 'bracelet',
            'rarity' => 'epic',
            'bonuses' => [
                'attack' => 3,
                'hp' => 4,
                'defense' => 3,
                'crit' => 0.4,
                'block' => 0.5
            ]
        ],

        'war_boots' => [
            'name' => 'War Boots',
            'slot' => 'boots',
            'rarity' => 'epic',
            'bonuses' => [
                'attack' => 1,
                'hp' => 12,
                'defense' => 3,
                'crit' => 0,
                'block' => 1.0
            ]
        ]
    ];

    /*
    |--------------------------------------------------------------------------
    | TRANSACTION
    |--------------------------------------------------------------------------
    */

    $db->beginTransaction();

    try {

        /*
        |--------------------------------------------------------------------------
        | LOAD LOCKED GAME
        |--------------------------------------------------------------------------
        */

        $game = loadGameState(
            $db,
            $wallet
        );

        /*
        |--------------------------------------------------------------------------
        | NORMALIZE GEAR
        |--------------------------------------------------------------------------
        */

        if (
            !isset($game['gear']) ||
            !is_array($game['gear'])
        ) {
            $game['gear'] = [];
        }

        if (
            !isset($game['gear']['bag']) ||
            !is_array($game['gear']['bag'])
        ) {
            $game['gear']['bag'] = [];
        }

        if (
            !isset($game['gear']['equipped']) ||
            !is_array($game['gear']['equipped'])
        ) {
            $game['gear']['equipped'] = [
                'weapon' => null,
                'armor' => null,
                'shield' => null,
                'helmet' => null,
                'earring' => null,
                'necklace' => null,
                'bracelet' => null,
                'boots' => null
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | SERVER STATE
        |--------------------------------------------------------------------------
        */

        if (
            !isset($game['_server']) ||
            !is_array($game['_server'])
        ) {
            $game['_server'] = [];
        }

        $now = microtime(true);

        $lastDropCheck = (float)(
            $game['_server']['lastGearDropCheck'] ?? 0
        );

        /*
        |--------------------------------------------------------------------------
        | RATE LIMIT
        |--------------------------------------------------------------------------
        */

        if (
            $lastDropCheck > 0 &&
            ($now - $lastDropCheck) < 0.120
        ) {
            $db->rollBack();

            echo json_encode([
                'success' => false,
                'error' => 'Too fast'
            ]);

            exit;
        }

        $game['_server']['lastGearDropCheck'] = $now;

        /*
|--------------------------------------------------------------------------
| STORAGE LIMIT
|--------------------------------------------------------------------------
|
| Bag doluysa drop iptal edilmez.
| Öncelik:
|   1) Bag
|   2) Depo
|
| İkisi de doluysa drop alınamaz.
*/

$bagCount = count($game['gear']['bag']);

$depoCount = isset($game['gear']['depo']) && is_array($game['gear']['depo'])
    ? count($game['gear']['depo'])
    : 0;

if ($bagCount >= 8 && $depoCount >= 24) {

    saveGameState(
        $db,
        $wallet,
        $game
    );

    $db->commit();

    echo json_encode([
        'success' => true,
        'dropped' => false,
        'reason' => 'storage_full',
        'game' => $game
    ]);

    exit;
}

        /*
        |--------------------------------------------------------------------------
        | DROP CHANCE
        |--------------------------------------------------------------------------
        */

        $prestigeLevel = max(
            0,
            (int)($game['prestigeLevel'] ?? 0)
        );

        $chance = min(
            0.10,
            0.010 + ($prestigeLevel * 0.0150)
        );

        $dropRoll =
            mt_rand(0, 1000000) / 1000000;

        if ($dropRoll >= $chance) {

            saveGameState(
                $db,
                $wallet,
                $game
            );

            $db->commit();

            echo json_encode([
                'success' => true,
                'dropped' => false,
                'game' => $game
            ]);

            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | RARITY
        |--------------------------------------------------------------------------
        */

        $rarityRoll =
            mt_rand(0, 1000000) / 1000000;

        if ($rarityRoll < 0.01) {

            $rarity = 'epic';

        } elseif ($rarityRoll < 0.13) {

            $rarity = 'rare';

        } else {

            $rarity = 'common';
        }

        /*
        |--------------------------------------------------------------------------
        | RARITY POOL
        |--------------------------------------------------------------------------
        */

        $pool = [];

        foreach ($GEAR_DEFS as $defId => $def) {

            if ($def['rarity'] === $rarity) {
                $pool[] = $defId;
            }
        }

        if (!$pool) {
            throw new RuntimeException(
                'Gear pool unavailable'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | RANDOM ITEM
        |--------------------------------------------------------------------------
        */

        $defId = $pool[
            mt_rand(
                0,
                count($pool) - 1
            )
        ];

        $def = $GEAR_DEFS[$defId];

        /*
        |--------------------------------------------------------------------------
        | UNIQUE ID
        |--------------------------------------------------------------------------
        */

        $itemId =
            'g' .
            str_replace(
                '.',
                '',
                (string)$now
            ) .
            '_' .
            bin2hex(
                random_bytes(5)
            );

        /*
        |--------------------------------------------------------------------------
        | ITEM
        |--------------------------------------------------------------------------
        */

        $item = [
            'id' => $itemId,
            'defId' => $defId,
            'name' => $def['name'],
            'slot' => $def['slot'],
            'rarity' => $def['rarity'],
            'bonuses' => $def['bonuses']
        ];

        /*
|--------------------------------------------------------------------------
| ADD SERVER-SIDE
|--------------------------------------------------------------------------
|
| Bag'de yer varsa bag'e.
| Bag doluysa depo'ya.
*/

$destination = 'bag';

if (count($game['gear']['bag']) < 8) {

    $game['gear']['bag'][] = $item;

} elseif (
    isset($game['gear']['depo']) &&
    is_array($game['gear']['depo']) &&
    count($game['gear']['depo']) < 24
) {

    $game['gear']['depo'][] = $item;
    $destination = 'depo';

} else {

    throw new RuntimeException(
        'Gear storage full'
    );
}

        /*
        |--------------------------------------------------------------------------
        | SAVE
        |--------------------------------------------------------------------------
        */

        $game['lastSave'] = time();

        updateServerAchievements($game);
        
        addGameActivity(
    $db,
    $wallet,
    'item_drop',
    $item['name'] . '|' . ($item['rarity'] ?? 'common')
);

        saveGameState(
            $db,
            $wallet,
            $game
        );

        $db->commit();

        /*
        |--------------------------------------------------------------------------
        | RESPONSE
        |--------------------------------------------------------------------------
        */

        echo json_encode([
    'success' => true,
    'dropped' => true,
    'item' => $item,
    'destination' => $destination,
    'game' => $game
]);

    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $e;
    }

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' =>
            defined('APP_DEBUG') && APP_DEBUG
                ? $e->getMessage()
                : 'Server error'
    ]);
}