<?php
# Rescale large images in message_attachments

namespace Booktastic\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php chat_message_checkspam.php -i <chat message id>\n";
} else {
    $id = Utils::presdef('i', $opts, null);
}

$messages = $dbhr->preQuery("SELECT * FROM chat_messages WHERE id = ?", [
    $id
]);

$m = new ChatMessage($dbhr, $dbhm);

foreach ($messages as $message) {
    $reason = $m->checkSpam($message['message']);

    error_log("Reason? $reason");
}