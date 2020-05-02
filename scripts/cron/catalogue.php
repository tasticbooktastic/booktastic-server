<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Log.php');
require_once(IZNIK_BASE . '/include/booktastic/Catalogue.php');

$lockh = lockScript(basename(__FILE__));


do {
    $queue = $dbhr->preQuery("SELECT id FROM booktastic_ocr WHERE processed = 0;");

    foreach ($queue as $q) {
        $id = $q['id'];
        $c = new Catalogue($dbhr, $dbhm);
        $c->process($id);
        error_log("Completed $id");
    }

    sleep(1);
} while (true);
