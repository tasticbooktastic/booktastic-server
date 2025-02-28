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
class requestAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->deleteLocations("DELETE FROM locations WHERE name LIKE 'TV13%';");
    }

    public function testBasic() {
        // This assumes some addresses are loaded, even if they're fake.
        $pafadds = $this->dbhr->preQuery("SELECT id FROM paf_addresses LIMIT 1;");
        self::assertEquals(1, count($pafadds));
        $pafid = $pafadds[0]['id'];

        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        # Create logged out - should fail
        $ret = $this->call('request', 'PUT', [
            'reqtype' => Request::TYPE_BUSINESS_CARDS
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Create logged in
        $l = new Location($this->dbhr, $this->dbhm);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('address', 'PUT', [
            'line1' => 'Test',
            'pafid' => $pafid
        ]);
        $this->assertEquals(0, $ret['ret']);

        $aid = $ret['id'];
        $this->assertNotNull($aid);

        $ret = $this->call('request', 'PUT', [
            'reqtype' => Request::TYPE_BUSINESS_CARDS,
            'addressid' => $aid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        # Get with id - should work
        $ret = $this->call('request', 'GET', [ 'id' => $id ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['request']['id']);
        $this->assertEquals(Request::TYPE_BUSINESS_CARDS, $ret['request']['type']);
        self::assertEquals($aid, $ret['request']['address']['id']);

        # Mark as paid.
        $this->dbhm->preExec("UPDATE users_requests SET paid = 1 WHERE id = ?;", [
            $id
        ]);

        # List
        $ret = $this->call('request', 'GET', []);
        $this->log("List " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        self::assertEquals(1, count($ret['requests']));

        # List outstanding - without permission
        $ret = $this->call('request', 'GET', [
            'outstanding' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        self::assertEquals(0, count($ret['requests']));

        # List outstanding - with permission
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created {$this->uid}");
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->user->setPrivate('permissions', User::PERM_BUSINESS_CARDS);
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('request', 'GET', [
            'outstanding' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        self::assertEquals(1, count($ret['requests']));

        # Not recently complete yet
        $ret = $this->call('request', 'GET', [
            'recent' => TRUE,
            'recentid' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        self::assertEquals(0, count($ret['recent']));

        # Complete it
        $ret = $this->call('request', 'POST', [
            'action' => 'Completed',
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Now recently complete
        $ret = $this->call('request', 'GET', [
            'recent' => TRUE,
            'recentid' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        self::assertEquals(1, count($ret['recent']));


        # Delete
        $ret = $this->call('request', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);

        }
}

