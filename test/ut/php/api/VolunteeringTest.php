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
class volunteeringAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhm;
        $this->dbhm = $dbhm;

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_REUSE);
        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->user->addMembership($this->groupid);
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid2 = $u->create(NULL, NULL, 'Test User');
        $this->user2 = User::get($this->dbhr, $this->dbhm, $this->uid2);
        $this->user2->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->assertGreaterThan(0, $this->user2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid3 = $u->create(NULL, NULL, 'Test User');
        $this->user3 = User::get($this->dbhr, $this->dbhm, $this->uid2);
        $this->user3->addMembership($this->groupid);
        $this->assertGreaterThan(0, $this->user3->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $dbhm->preExec("DELETE FROM volunteering WHERE title = 'Test vacancy' OR title = 'UTTest';");
    }

    public function testCreate() {
        # Get invalid id
        $ret = $this->call('volunteering', 'GET', [
            'id' => -1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Create when not logged in
        $ret = $this->call('volunteering', 'POST', [
            'title' => 'UTTest'
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Create without mandatories
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('volunteering', 'POST', [
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Create as logged in user.
        $ret = $this->call('volunteering', 'POST', [
            'title' => 'UTTest',
            'location' => 'UTTest',
            'description' => 'UTTest',
            'groupid' => $this->groupid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);
        $this->log("Created event $id");

        # Remove and Add group
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'RemoveGroup'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'AddGroup'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Add date
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'start' => Utils::ISODate('@' . strtotime('next wednesday 2pm')),
            'end' => Utils::ISODate('@' . strtotime('next wednesday 4pm')),
            'action' => 'AddDate'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Shouldn't show for us as pending.
        $ret = $this->call('volunteering', 'GET', [
            'pending' => true
        ]);
        $this->log("Result of get all " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['volunteerings']));

        $ret = $this->call('volunteering', 'GET', [
            'pending' => TRUE,
            'groupid' => $this->groupid
        ]);
        $this->log("Result of get for group " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['volunteerings']));

        # Log in as the mod
        $this->user2->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->assertTrue($this->user2->login('testpw'));

        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['work']['pendingvolunteering']);

        # Edit it
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'title' => 'UTTest2'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals('UTTest2', $ret['volunteering']['title']);

        # Edit it
        $ret = $this->call('volunteering', 'PUT', [
            'id' => $id,
            'title' => 'UTTest3'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals('UTTest3', $ret['volunteering']['title']);
        self::assertFalse(Utils::pres('renewed', $ret['volunteering']));

        $dateid = $ret['volunteering']['dates'][0]['id'];

        # Shouldn't be editable for someone else.
        $this->user3->addMembership($this->groupid, User::ROLE_MEMBER);
        $this->assertTrue($this->user3->login('testpw'));
        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);
        $this->assertFalse($ret['volunteering']['canmodify']);

        # And back as the user
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'RemoveGroup'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'dateid' => $dateid,
            'action' => 'RemoveDate'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Test renew
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'action' => 'Renew'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);
        self::assertNotNull($ret['volunteering']['renewed']);

        # Test expire
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'action' => 'Expire'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(1, $ret['volunteering']['expired']);

        # Add a photo
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_VOLUNTEERING);
        list ($photoid, $uid) = $a->create(NULL, $data);

        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'photoid' => $photoid,
            'action' => 'SetPhoto'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);

        $this->assertEquals($photoid, $ret['volunteering']['photo']['id']);

        $ret = $this->call('volunteering', 'DELETE', [
            'id' => $id
        ]);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);

        $this->log("Get after delete " . var_export($ret, TRUE));
        self::assertEquals(3, $ret['ret']);

    }

    public function testHold() {
        $this->assertTrue($this->user->login('testpw'));
        $this->user->setPrivate('systemrole', User::ROLE_MODERATOR);

        $ret = $this->call('volunteering', 'POST', [
            'title' => 'UTTest',
            'location' => 'UTTest',
            'description' => 'UTTest',
            'groupid' => $this->groupid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);
        $this->log("Created event $id");

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);

        $this->assertFalse(array_key_exists('heldby', $ret['volunteering']));

        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'Hold'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);

        $this->assertEquals($this->user->getId(), $ret['volunteering']['heldby']['id']);

        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'Release'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);

        $this->assertFalse(array_key_exists('heldby', $ret['volunteering']));
    }

    public function testNational() {
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('volunteering', 'POST', [
            'title' => 'UTTest',
            'location' => 'UTTest',
            'description' => 'UTTest',
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);
        $this->log("Created event $id");

        # Add date
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'start' => Utils::ISODate('@' . strtotime('next wednesday 2pm')),
            'end' => Utils::ISODate('@' . strtotime('next wednesday 4pm')),
            'action' => 'AddDate'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Log in as the mod
        $this->user2->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->assertTrue($this->user2->login('testpw'));

        # Shouldn't show as we don't have national permission.
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, $ret['work']['pendingvolunteering']);
    }

    public function testNational2() {
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('volunteering', 'POST', [
            'title' => 'UTTest',
            'location' => 'UTTest',
            'description' => 'UTTest',
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);
        $this->log("Created event $id");

        # Add date
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'start' => Utils::ISODate('@' . strtotime('next wednesday 2pm')),
            'end' => Utils::ISODate('@' . strtotime('next wednesday 4pm')),
            'action' => 'AddDate'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Log in as the mod
        $this->user2->setPrivate('permissions', User::PERM_NATIONAL_VOLUNTEERS . "," . User::PERM_GIFTAID);
        $this->user2->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->assertTrue($this->user2->login('testpw'));

        $ret = $this->call('session', 'GET', [
            'components' => [ 'work' ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['work']['pendingvolunteering']);
        $this->assertEquals(0, $ret['work']['giftaid']);
    }
}

