<?php

namespace Booktastic\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('m:v:');

if (count($opts) < 1) {
    echo "Usage: php volunteering.php (-m mod -v val)\n";
} else {
    $mod = Utils::presdef('m', $opts, 1);
    $val = Utils::presdef('v', $opts, 0);

    $lockh = Utils::lockScript(basename(__FILE__) . "-m$mod-v$val");

    error_log("Start volunteering for groupid % $mod = $val at " . date("Y-m-d H:i:s"));
    $start = time();
    $total = 0;

    $e = new VolunteeringDigest($dbhr, $dbhm, FALSE);

    # Ensure any old ones are marked as so.
    $v = new Volunteering($dbhr, $dbhm);
    $v->askRenew();
    $v->expire();

    # We only send opportunities for Freegle groups.
    #
    # Cron should run this every week, but restrict to not sending them more than every few days to allow us to tweak the time.
    $sql = "SELECT id, nameshort FROM `groups` WHERE `type` = 'Freegle' AND onhere = 1 AND MOD(id, ?) = ? AND publish = 1 AND (lastvolunteeringroundup IS NULL OR DATEDIFF(NOW(), lastvolunteeringroundup) >= 3) ORDER BY LOWER(nameshort) ASC;";
    $groups = $dbhr->preQuery($sql, [$mod, $val]);

    foreach ($groups as $group) {
        try {
            error_log($group['nameshort']);
            $g = Group::get($dbhr, $dbhm, $group['id']);
            if (!$g->getSetting('closed',FALSE) && $g->getSetting('volunteering', 1)) {
                $total += $e->send($group['id']);
            }

            if (file_exists('/tmp/iznik.mail.abort')) {
                exit(0);
            }
        } catch (\Exception $e) {
            \Sentry\captureException($e);
            error_log("Exception " . $e->getMessage() . " on " . $group['nameshort']);
        }
    }

    $duration = time() - $start;

    error_log("Finish volunteering at " . date("Y-m-d H:i:s") . ", sent $total mails in $duration seconds");

    Utils::unlockScript($lockh);
}