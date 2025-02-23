<?php
namespace Booktastic\Iznik;

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}

require_once(UT_DIR . '/../../include/config.php');
require_once(UT_DIR . '/../../include/db.php');

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class domainAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhm;
        $this->dbhm = $dbhm;

        $dbhm->preExec("REPLACE INTO domains_common (domain, count) VALUES ('test.com', 1);");
    }

    public function testBasic() {
        $ret = $this->call('domains', 'GET', [
            'domain' => 'test.com'
        ]);
        $this->log("Should be no suggestions " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertFalse(array_key_exists('suggestions', $ret));

        $ret = $this->call('domains', 'GET', [
            'domain' => 'tset.com'
        ]);
        $this->log("Should be suggestions " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue(array_key_exists('suggestions', $ret));

        }
}

