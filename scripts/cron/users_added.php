<?php

namespace Booktastic\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Make sure that the added date of a user reflects the earliest added date on their groups.
$users = $dbhr->preQuery("SELECT id, added FROM users;");
$total = count($users);
$count = 0;

function correctAdded($dbhr, $dbhm, $user) {
    $mins = $dbhr->preQuery("SELECT MIN(added) AS minadd FROM memberships WHERE userid = ?;", [
        $user['id']
    ]);

    foreach ($mins as $min) {
        if ($min['minadd'] && (!$user['added'] || strtotime($min['minadd']) < strtotime($user['added']))) {
            # We have a group membership and either no added info or we now know that the user is older.
            error_log("{$user['id']} Older min membership {$min['minadd']}");
            $dbhm->preExec("UPDATE users SET added = ? WHERE id = ?;", [
                $min['minadd'],
                $user['id']
            ], FALSE);
        } else {
            # No memberships.  Check the first log.
            $logs = $dbhr->preQuery("SELECT MIN(timestamp) AS d FROM logs WHERE user = ?;", [
                $user['id']
            ]);

            foreach ($logs as $log) {
                if ($log['d'] && (!$user['added'] || strtotime($log['d']) < strtotime($user['added']))) {
                    error_log("{$user['id']} Older log {$min['minadd']}");
                    $dbhm->preExec("UPDATE users SET added = ? WHERE id = ?;", [
                        $log['d'],
                        $user['id']
                    ], FALSE);
                }
            }
        }
    }
}

foreach ($users as $user) {
    correctAdded($dbhr, $dbhm, $user);
    $count++;

    if ($count % 1000 === 0) {
        error_log(date("Y-m-d H:i:s", time()) . "...$count / $total");
        gc_collect_cycles();
    }
}