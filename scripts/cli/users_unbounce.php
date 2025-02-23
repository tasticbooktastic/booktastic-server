<?php

namespace Booktastic\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('d:');

if (count($opts) < 1) {
    echo "Usage: php users_unbounce.php -d <domain>\n";
} else {
    $domain = $opts['d'];

    $u = new User($dbhr, $dbhm);

    $sql = "SELECT users_emails.id, userid FROM users_emails INNER JOIN users ON users_emails.userid = users.id WHERE backwards LIKE " . $dbhr->quote(strrev($domain) . "%") . " AND users.bouncing = 1;";
    $users = $dbhr->preQuery($sql);

    error_log("Found " . count($users));

    foreach ($users as $user) {
        $u = new User($dbhr, $dbhm, $user['userid']);
        $u->unbounce($user['id'], TRUE);
    }
}
