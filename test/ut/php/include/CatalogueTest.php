<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/booktastic/Catalogue.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class CatalogueTest extends IznikTestCase
{
    private $dbhr, $dbhm;

    protected function setUp()
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    protected function tearDown()
    {
        parent::tearDown();
    }

    /**
     * @dataProvider libraryData
     */
    public function testLibrary($filename)
    {
        if (!getenv('STANDALONE')) {
            $data = base64_encode(file_get_contents(IZNIK_BASE . $filename . ".jpg"));

            $c = new Catalogue($this->dbhr, $this->dbhm);

            # First get the positions of books in the image.
            //        list ($id, $purportedbooks) = $c->extractPossibleBooks($data, $filename);
            //        assertNotNull($id);

            # Now get the text within each book.
            list ($id, $json2) = $c->ocr($data, $filename);
            assertNotNull($id);

            # Now identify spines.
            list ($spines, $fragments) = $c->process($id);

            error_log("\n\n");
            foreach ($spines as $book) {
                #error_log("Book ". json_encode($book));
                if ($book['author'] && $book['title']) {
                    error_log("{$book['author']} - {$book['title']}");
                }
            }

            $booksfile = @file_get_contents(IZNIK_BASE . $filename . "_books.txt");
            $text = $booksfile ? json_decode($booksfile, TRUE) : [];

            $foundnow = 0;

            foreach ($spines as $book) {
                if ($book['author'] && $book['title']) {
                    $foundnow++;
                }
            }

            $foundthen = 0;

            foreach ($text as $book) {
                if ($book['author'] && $book['title']) {
                    $foundthen++;
                }
            }

            if ($foundthen > $foundnow) {
                error_log("$filename worse");
                foreach ($text as $then) {
                    if ($then['author']) {
                        $found = FALSE;

                        foreach ($spines as $now) {
                            if (!strcasecmp($now['author'], $then['author']) && !strcasecmp($now['title'], $then['title'])) {
                                $found = TRUE;
                            }
                        }

                        if (!$found) {
                            error_log("No longer finding {$then['author']} - {$then['title']}");
                        }
                    }
                }

                error_log(json_encode($spines));

            } else if ($foundthen < $foundnow) {
                error_log("$filename better");

                foreach ($spines as $now) {
                    if ($now['author']) {
                        $found = FALSE;

                        foreach ($text as $then) {
                            if ($now['author'] == $then['author'] && $now['title'] == $then['title']) {
                                $found = TRUE;
                            }
                        }

                        if (!$found) {
                            error_log("Now also finding {$now['author']} - {$now['title']}");
                        }
                    }
                }
                error_log(json_encode($spines));
            }

            assertEquals($foundthen, $foundnow);
        }

        assertTrue(TRUE);
    }

    public function libraryData()
    {
        $ret = [];

        foreach ([
                     'vertical_easy',
                     'liz1',
                     'liz2',
                     'liz3',
                     'liz4',
                     'liz5',
                     'liz7',
                     'liz8',
                     'liz9',
                     'liz10',
                     'liz11',
                     'liz13',
                     'liz14',
                     'liz15',
                     'liz16',
                     'liz17',
                     'liz18',
                     'liz19',
                     'liz20',
                     'liz21',
                     'liz22',
                     'liz23',
                     'liz24',
                     'liz25',
                     'liz26',
                     'liz27',
                     'liz28',
                     'liz29',
                     'liz30',
                     'liz31',
                     'liz33',
                     'liz34',
                     'liz35',
                     'liz36',
                     'liz37',
                     'liz38',
                     'liz39',
                     'liz40',
                     'liz41',
                     'liz43',
                     'liz44',
                     'liz45',
                     'liz46',
                     'liz47',
                     'liz48',
                     'ruth1',
                     'ruth2',
                     'ruth3',
                     'jo1',
                     'carol1',
                     'carol2',
                     'kathryn1',
                     'phil1',
                     'doug1',
                     'doug2',
                     'doug3',
                     'adam1',
                     'adam2',
                     'andy1',
                     'emma1',
                     'suzanne1',
                     'suzanne2',
                     'suzanne3',
                     'suzanne4',
                     'suzanne5',
                     'tom1',
                     'wanda1',
                     'caroline1',
                     'bryson3',
                     'bryson2',
                     'bryson',
                     'chris1',
                     'chris2',
                     'crime1',
                     'crime2',
                     'crime3',
                     'basic_horizontal',
                     'basic_vertical',
                     'gardening',
                     'horizontal_overlap',
                     'horizontal_overlap2',
                 ] as $test) {
            $ret[$test] = ['/test/ut/php/booktastic/' . $test];
        }

        return $ret;
    }

//    public function testVideo()
//    {
//        $data = base64_encode(file_get_contents(IZNIK_BASE . '/test/ut/php/booktastic/video.mp4'));
//
//        # Now get the text within each book.
//        $c = new Catalogue($this->dbhr, $this->dbhm);
//        list ($id, $json2) = $c->ocr($data, 'video', TRUE);
//        assertTrue(TRUE);
//    }
}

