<?php

namespace Booktastic\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$opts = getopt('o:i:');
$input = Utils::presdef('i', $opts, NULL);
$output = Utils::presdef('o', $opts, NULL);

do {
    if ($input) {
        # New run - remove all our input/output files.
        $files = glob("$input/*");
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $files = glob("$output/*");
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    } else {
        # Reprocess old outputs.
    }

    # Find unprocessed images.
    $unprocessed = $dbhr->preQuery("SELECT * FROM shelves WHERE processed = 0;");

    if ($input) {
        foreach ($unprocessed as $up) {
            # Images are in /images.  Copy into our input folder.
            exec("cp /images/{$up['externaluid']} $input");
        }

        if (count($unprocessed)) {
            # Execute the image parser, which is in Python.
            $cmd = "cd /root/booktastic-image-parser/;. bin/activate; python3 run.py $input $output";
            exec($cmd);
        }
    }

    # Now we need to update the database with the results.
    $files = glob("$output/*.json");
    foreach ($files as $file) {
        $json = file_get_contents($file);
        $data = json_decode($json, true);
        $externaluid = pathinfo($file)['filename'];

        # We might have multiple shelves with the same image.  We want to update them all.
        $shelves = $dbhr->preQuery("SELECT id FROM shelves WHERE externaluid = ?;", [ $externaluid ]);

        foreach ($shelves as $shelf) {
            $shelfid = $shelf['id'];

            foreach ($data['books'] as $book) {
                $bookid = NULL;

                error_log(json_encode($book));

                $authors = [];

                if (isset($book['author'])) {
                    $authors = [$book['author']];
                } else {
                    if (isset($book['authors'])) {
                        $authors = $book['authors'];
                    }
                }

                $isbn13 = Utils::presdef('isbn13', $book, null);

                if ($isbn13 && count($authors)) {
                    $dbhm->preExec(
                        "INSERT INTO books (title, isbn13) VALUES (?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);",
                        [
                            $book['title'],
                            $isbn13
                        ]
                    );

                    $bookid = $dbhm->lastInsertId();

                    foreach ($authors as $author) {
                        $dbhm->preExec(
                            "INSERT INTO authors (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);",
                            [
                                $author
                            ]
                        );

                        $authorid = $dbhm->lastInsertId();

                        $dbhm->preExec(
                            "INSERT INTO books_authors (bookid, authorid) VALUES (?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);",
                            [
                                $bookid,
                                $authorid
                            ]
                        );
                    }
                }

                if ($bookid) {
                    $dbhm->preExec("INSERT INTO shelves_books (shelfid, bookid) VALUES (?, ?);", [
                        $shelfid,
                        $bookid
                    ]);
                }
            }

            $dbhm->preExec("UPDATE shelves SET processed = 1 WHERE id = ?;", [
                $shelfid
            ]);
        }
    }

    sleep(1);
} while (true && $input);

Utils::unlockScript($lockh);