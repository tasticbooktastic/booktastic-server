<?php

namespace Booktastic\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$keywords = [
'3 piece suite'.
'AC unit',
'air con',
'Air Conditioner',
'Aircon',
'arcade',
'barbeque',
'barrel',
"Bath",
"Bathroom",
"bathtub",
"BBQ",
"bed base",
"bed frame",
"bedstead",
"Bench",
"bibliothèque",
"Bicycle",
"big mirror",
"Bike",
"Blinds",
"Book case",
"Bookcase",
"bouncy castle",
"bric a brac",
"Bric-a-Brac",
"bricks",
"buggy",
"bunk bed",
"Bunkbed",
"Bureau",
"Cabinet",
"canoe",
"Carpet",
"Chair",
"Chaise",
"church pew",
"Cistern ",
"climbing frame",
"console",
"cooker",
"couch",
"CRT",
"cuboard",
"cupboard",
"Curtain",
"decking",
"Dehumidifier",
"Desk",
"Dishwasher ",
"divan",
"Door",
"double bed",
"Drawer",
"Dresser",
"Dryer ",
"dustbin",
"electric hob",
"exercise machine",
"extractor",
"fencing",
"filing cabinet",
"fireplace",
"Firewood",
"fish tank",
"Floor",
"flooring",
"footstool",
"Freezer",
"Fridge",
"frigde",
"futon",
"garden",
"gas hob",
"Gate",
"gazebo",
"Grandfather",
"greenhouse",
"head board",
"headboard",
"heater",
"hedge",
"hot tub",
"hutch",
"ikea",
"industrial fan",
"ironing board",
"Junk",
"kayak",
"keyboard",
"ladder",
"Lamp",
"large mirror",
"lawn mower",
"lawnmower",
"Lino",
"Lounger ",
"luggage",
"mattress",
"Microwave ",
"mirror large",
"Ottoman",
"outdoor",
"oven",
"paddleboard",
"Paint",
"pallet",
"Patio",
"paving",
"Photocopier ",
"Piano",
"planter",
"playground",
"poof",
"Pouffe",
"Pram",
"Printer",
"push chair",
"Pushchair",
"Radiator",
"railway sleeper",
"Recliner",
"refrigerator",
"Rotary Drier ",
"rowing machine",
"running machine",
"scaffold",
"scooter",
"scrap metal",
"seater",
"settee",
"Shelving",
"chimney pot",
"Shower",
"shredder",
"shutter",
"sideboard",
"single bed",
"Sink",
"sofa",
"Stairgate",
"statue",
"stool",
"storage tank",
"stove",
"strimmer",
"stroller",
"suitcase",
"Sunbed",
"Table",
"Tallboy",
"three piece suite",
"Tiles",
"Toilet ",
"trampoline",
"treadmill",
"tree",
"trolley ",
"TV",
"tyre",
"undercounter",
"Underlay",
"Vacuum",
"vending machine",
"Wall unit",
"Wardrobe",
"Washer Drier",
"Washer Dryer",
"Washing Machine ",
"water heater",
"water tank",
"Waterbutt",
"Wheelbarrow ",
"Wheelchair",
"wheelie bin",
"Window",
"windsurf",
"wooden beam",
"work top",
"workstation",
"Worktop",
"z bed",
"zbed",
];

$offers = $dbhr->preQuery("SELECT id, subject FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE messages_groups.arrival >= '2023-03-19' AND collection = 'Approved';");
error_log("Got " . count($offers));

$res = [];

foreach ($offers as $offer) {
    foreach ($keywords as $keyword) {
        if (stripos($offer['subject'], $keyword) !== FALSE) {
            $offer['matches'] = $keyword;
            $res[$offer['id']] = $offer;
        }
    }
}

foreach ($res as $id => $offer) {
    fputcsv(STDOUT, [ $offer['subject'], $offer['matches'],  "https://www.ilovefreegle.org/message/{$offer['id']}"]);
}