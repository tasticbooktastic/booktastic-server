<?php
namespace Freegle\Iznik;

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}

require_once(UT_DIR . '/../../include/config.php');
require_once(UT_DIR . '/../../include/db.php');

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class addressAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp()
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->deleteLocations("DELETE FROM locations WHERE name LIKE 'TV13%';");
    }

    public function testBasic()
    {
        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user {$this->uid}");
        assertNotNull($this->uid);
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        # Create logged out - should fail
        $ret = $this->call('address', 'PUT', [
            'line1' => 'Test'
        ]);
        assertEquals(1, $ret['ret']);

        # Create logged in
        $l = new Location($this->dbhr, $this->dbhm);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');

        assertTrue($this->user->login('testpw'));

        // This assumes some addresses are loaded, even if they're fake.
        $pafadds = $this->dbhr->preQuery("SELECT id FROM paf_addresses LIMIT 1;");
        self::assertEquals(1, count($pafadds));

        $ret = $this->call('address', 'PUT', [
            'line1' => 'Test',
            'pafid' => $pafadds[0]['id']
        ]);
        assertEquals(0, $ret['ret']);

        $id = $ret['id'];
        assertNotNull($id);

        # Get with id - should work
        $ret = $this->call('address', 'GET', ['id' => $id]);
        $this->log("Got address " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['address']['id']);

        $p = new PAF($this->dbhr, $this->dbhm);
        assertEquals($p->getSingleLine($pafadds[0]['id']), $ret['address']['singleline']);
        assertEquals($p->getFormatted($pafadds[0]['id'], "\n"), $ret['address']['multiline']);

        # List
        $ret = $this->call('address', 'GET', []);
        assertEquals(0, $ret['ret']);
        self::assertEquals(1, count($ret['addresses']));
        assertEquals($id, $ret['addresses'][0]['id']);

        # Edit
        $ret = $this->call('address', 'PATCH', [
            'id' => $id,
            'instructions' => 'Test2'
        ]);
        assertEquals(0, $ret['ret']);
        $ret = $this->call('address', 'GET', ['id' => $id]);
        assertEquals(0, $ret['ret']);
        assertEquals('Test2', $ret['address']['instructions']);

        # Delete
        $ret = $this->call('address', 'DELETE', [
            'id' => $id
        ]);
        assertEquals(0, $ret['ret']);

        }

    public function testPAF()
    {
        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($this->user->login('testpw'));

        $postcode = $this->dbhr->preQuery("SELECT id, postcodeid FROM paf_addresses WHERE postcodeid IS NOT NULL LIMIT 1;");

        $this->log("Get for postcode {$postcode[0]['postcodeid']}");

        $ret = $this->call('address', 'GET', [
            'postcodeid' => $postcode[0]['postcodeid']
        ]);
        $this->log("Got " . var_export($ret, TRUE));
        $found = FALSE;
        foreach ($ret['addresses'] as $address) {
            if ($address['id'] == $postcode[0]['id']) {
                $found = TRUE;
                $this->log($address['singleline']);
            }
        }

        assertTrue($found);

        }
}
