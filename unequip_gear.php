<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
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
| CONFIG DISCOVERY
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
    ],
];


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function normalizeGear(array &$game): void
{
    if (!isset($game['gear']) || !is_array($game['gear'])) {
        $game['gear'] = [];
    }

    $defaults = [
        'weapon' => null,
        'armor' => null,
        'shield' => null,
        'helmet' => null,
        'earring' => null,
        'necklace' => null,
        'bracelet' => null,
        'boots' => null,
    ];

    if (
        !isset($game['gear']['equipped']) ||
        !is_array($game['gear']['equipped'])
    ) {
        $game['gear']['equipped'] = [];
    }

    $game['gear']['equipped'] = array_merge(
        $defaults,
        $game['gear']['equipped']
    );

    if (!isset($game['gear']['bag']) || !is_array($game['gear']['bag'])) {
        $game['gear']['bag'] = [];
    }

    if (!isset($game['gear']['depo']) || !is_array($game['gear']['depo'])) {
        $game['gear']['depo'] = [];
    }
}


function invLevel(array $game, string $type): int
{
    return max(
        0,
        (int)($game['inventory'][$type]['level'] ?? 0)
    );
}


function getCombatPower(array $game): int
{
    $w = invLevel($game, 'weapon');
    $a = invLevel($game, 'armor');
    $s = invLevel($game, 'shield');
    $h = invLevel($game, 'helmet');

    $critChance = (float)($game['critChance'] ?? 0);

    $attack  = 8 + ($w * 4);
    $hp      = 80 + ($a * 18);

    $defense = (int)floor(
        ($a * 2.5) +
        ($s * 5) +
        ($h * 3.5)
    );

    $crit = min(
        40.0,
        max(
            0.0,
            8 +
            ($w * 0.6) +
            ($critChance * 50)
        )
    );

    $block = min(
        20.0,
        $s * 1.0
    );

    $equipped =
        $game['gear']['equipped'] ?? [];

    if (is_array($equipped)) {

        foreach ($equipped as $item) {

            if (
                !is_array($item) ||
                empty($item['bonuses']) ||
                !is_array($item['bonuses'])
            ) {
                continue;
            }

            $b = $item['bonuses'];

            $attack += (int)($b['attack'] ?? 0);
            $hp += (int)($b['hp'] ?? 0);
            $defense += (int)($b['defense'] ?? 0);
            $crit += (float)($b['crit'] ?? 0);
            $block += (float)($b['block'] ?? 0);
        }
    }

    $crit = max(
        0.0,
        min(40.0, $crit)
    );

    $block = max(
        0.0,
        min(25.0, $block)
    );

    return (int)floor(
        ($attack * 3) +
        ($hp * 0.4) +
        ($defense * 2.5) +
        ($crit * 4) +
        ($block * 6)
    );
}


function saveGameStateLocal(
    PDO $db,
    string $wallet,
    array $game
): void {

    $json = json_encode(
        $game,
        JSON_UNESCAPED_UNICODE |
        JSON_THROW_ON_ERROR
    );

    $stmt = $db->prepare(
        "UPDATE game_saves
         SET game_data = ?, last_save = NOW()
         WHERE wallet = ?
         LIMIT 1"
    );

    $stmt->execute([
        $json,
        $wallet
    ]);
}


/*
|--------------------------------------------------------------------------
| REQUEST
|--------------------------------------------------------------------------
*/

try {

    $data = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($data)) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid data'
        ]);
        exit;
    }

    $wallet = trim(
        (string)($data['wallet'] ?? '')
    );

    $slot = trim(
        (string)($data['slot'] ?? '')
    );

    $allowedSlots = [
        'weapon',
        'armor',
        'shield',
        'helmet',
        'earring',
        'necklace',
        'bracelet',
        'boots'
    ];

    if (
        $wallet === '' ||
        !preg_match(
            '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/',
            $wallet
        )
    ) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid wallet'
        ]);
        exit;
    }

    if (!in_array($slot, $allowedSlots, true)) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid slot'
        ]);
        exit;
    }


    /*
     * Session wallet check.
     */
    $wallet = assertSameWallet($wallet);


    /*
     * Paid wallet check.
     */
    $db = getDB();

    $paidStmt = $db->prepare(
        "SELECT 1
         FROM paid_wallets
         WHERE wallet = ?
         LIMIT 1"
    );

    $paidStmt->execute([
        $wallet
    ]);

    if (!$paidStmt->fetchColumn()) {
        echo json_encode([
            'success' => false,
            'error' => 'Paid wallet required'
        ]);
        exit;
    }


    /*
     * Transaction + row lock.
     */
    $db->beginTransaction();

    $stmt = $db->prepare(
        "SELECT game_data
         FROM game_saves
         WHERE wallet = ?
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->execute([
        $wallet
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException(
            'Game save not found'
        );
    }

    $game = json_decode(
        $row['game_data'] ?? '{}',
        true
    );

    if (!is_array($game)) {
        throw new RuntimeException(
            'Invalid game state'
        );
    }


    normalizeGear($game);


    /*
     * Nothing equipped.
     */
    $equipped =
        $game['gear']['equipped'][$slot] ?? null;

    if (
        !is_array($equipped) ||
        empty($equipped['id'])
    ) {
        throw new RuntimeException(
            'Nothing equipped in this slot'
        );
    }


    /*
     * Bag capacity.
     */
    if (
        count($game['gear']['bag']) >= 8
    ) {
        throw new RuntimeException(
            'Bag full — move an item to stash first'
        );
    }


    /*
     * Validate the equipped item using
     * the SERVER definition.
     */
    $defId =
        (string)($equipped['defId'] ?? '');

    if (
        $defId === '' ||
        !isset($GEAR_DEFS[$defId])
    ) {
        throw new RuntimeException(
            'Invalid equipped item'
        );
    }

    $def =
        $GEAR_DEFS[$defId];


    /*
     * Make a trusted copy.
     */
    $trustedItem = [
        'id' => (string)$equipped['id'],
        'defId' => $defId,
        'name' => $def['name'],
        'slot' => $def['slot'],
        'rarity' => $def['rarity'],
        'bonuses' => $def['bonuses'],
    ];


    /*
     * Extra protection:
     * item must actually belong to requested slot.
     */
    if ($def['slot'] !== $slot) {
        throw new RuntimeException(
            'Gear slot mismatch'
        );
    }


    /*
     * Move equipped item back to bag.
     */
    $game['gear']['bag'][] =
        $trustedItem;


    /*
     * Clear equipped slot.
     */
    $game['gear']['equipped'][$slot] =
        null;


    /*
     * Server timestamp.
     */
    $game['lastSave'] = time();


    /*
     * Server achievements.
     */
    if (function_exists('updateServerAchievements')) {
        updateServerAchievements($game);
    }


    /*
     * Save.
     */
    saveGameStateLocal(
        $db,
        $wallet,
        $game
    );


    /*
     * Recalculate server combat power.
     */
    $combatPower =
        getCombatPower($game);


    $db->commit();


    echo json_encode([
        'success' => true,
        'game' => $game,
        'gear' => $game['gear'],
        'combat_power' => $combatPower
    ]);

} catch (Throwable $e) {

    if (
        isset($db) &&
        $db instanceof PDO &&
        $db->inTransaction()
    ) {
        $db->rollBack();
    }

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}