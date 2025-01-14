<?php

namespace Booktastic\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm, $dbconfig;

$lockh = Utils::lockScript(basename(__FILE__));

$dsn = "mysql:host={$dbconfig['host']};dbname=iznik;charset=utf8";
$at = 0;

# Extract the messages
$msgs = $dbhr->preQuery("SELECT messages_spamham.spamham, messages.message FROM messages_spamham INNER JOIN messages ON messages.id = messages_spamham.msgid WHERE message IS NOT NULL AND LENGTH(message) > 0;");
foreach ($msgs as $msg) {
    $fn = tempnam($msgs['spamham'] == 'Spam' ? '/tmp/sa_train/spam' : '/tmp/sa_train/ham', 'msg');
    error_log($fn);
    file_put_contents($fn, $msg['message']);
}

Utils::unlockScript($lockh);