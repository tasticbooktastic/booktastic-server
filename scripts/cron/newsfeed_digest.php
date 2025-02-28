<?php

namespace Booktastic\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "ilovefreegle.org";

$lockh = Utils::lockScript(basename(__FILE__));

try {
    $n = new Newsfeed($dbhr, $dbhm);
    $groups = $dbhr->preQuery("SELECT * FROM `groups` WHERE type = 'Freegle' AND onhere = 1 AND publish = 1 AND nameshort NOT LIKE '%playground%' ORDER BY RAND();");
    foreach ($groups as $group) {
        $g = new Group($dbhr, $dbhm, $group['id']);

        if ($g->getSetting('newsfeed', TRUE)) {
            error_log("{$group['nameshort']}");

            $membs = $dbhr->preQuery("SELECT DISTINCT userid FROM memberships WHERE groupid = ? AND collection = ?;", [
                $group['id'],
                MembershipCollection::APPROVED
            ]);

            $count = 0;
            foreach ($membs as $memb) {
                try {
                    $count += $n->digest($memb['userid']);
                } catch (\Exception $e) {
                    error_log("Error on {$memb['userid']} - " . $e->getMessage());
                    \Sentry\captureException($e);
                }
            }

            if ($count) {
                error_log("{$group['nameshort']} sent $count");
            }
        } else {
            error_log("{$group['nameshort']} skipped");
        }
    }

    Utils::unlockScript($lockh);
} catch (\Exception $e) {
    \Sentry\captureException($e);
}
