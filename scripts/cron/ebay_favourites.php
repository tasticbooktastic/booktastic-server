<?php

namespace Booktastic\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$ebay = file_get_contents('http://www.ebay.co.uk/egw/ebay-for-charity/charity-profile/Freegle/74430');
$ebayrival = file_get_contents('http://www.ebay.co.uk/egw/ebay-for-charity/charity-profile/Prince-Fluffy-Kareem/85221');

if (preg_match('/0"\>(.*?) Favourites</', $ebay, $matches) && preg_match('/padding\:0"\>(.*?) Favourites</', $ebayrival, $matchesrival)) {
    $count = str_replace(',', '', $matches[1]);
    $countrival = str_replace(',', '', $matchesrival[1]);
    error_log("Favourites $count $countrival");
    $dbhm->preExec("INSERT INTO ebay_favourites (count, rival) VALUES (?,?);", [
        $count,
        $countrival
    ]);
}