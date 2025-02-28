<?php

namespace Booktastic\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$users = $dbhr->preQuery("SELECT id FROM users WHERE onholidaytill is not null and onholidaytill > now();");

foreach ($users as $user) {
    $dbhm->preExec("UPDATE users SET onholidaytill = NULL WHERE id = ?", [
        $user['id']
    ]);
}