<?php

namespace Booktastic\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:a:r:i:p:');

if (count($opts) < 1) {
    echo "Usage: php user_email.php (-e <email to find> or -i <user id>) (-a <email to add> -r <email to remove> -p <primary>\n";
} else {
    $uid = Utils::presdef('i', $opts, NULL);
    $find = Utils::presdef('e', $opts, NULL);
    $add = Utils::presdef('a', $opts, NULL);
    $remove = Utils::presdef('r', $opts, NULL);
    $primary = Utils::presdef('p', $opts, 0);
    $u = User::get($dbhr, $dbhm);
    $uid = $uid ? $uid : $u->findByEmail($find);

    if ($uid) {
        error_log("Found user $uid");
        $u = User::get($dbhr, $dbhm, $uid);

        if ($add) {
            $rc = $u->addEmail($add, $primary, $primary);
            error_log("Added email $add id $rc");
        }

        if ($remove) {
            error_log("Removed email $remove");
            $u->removeEmail($remove);
        }
    } else {
        error_log("Couldn't find user for $find");
    }
}
