<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITest.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/group/Group.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class membershipsAPITest extends IznikAPITest {
    public $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup';");
        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");

        $g = new Group($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_REUSE);
        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = new User($this->dbhr, $this->dbhm, $this->uid);
        $this->user->addEmail('test@test.com');
        $this->uid2 = $u->create(NULL, NULL, 'Test User');
        $this->user2 = new User($this->dbhr, $this->dbhm, $this->uid2);
        $this->user2->addEmail('tes2t@test.com');
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($this->user->login('testpw'));
    }

    protected function tearDown() {
        parent::tearDown ();
    }

    public function __construct() {
    }

    public function testAdd() {
        error_log(__METHOD__);

        # Shouldn't be able to add as non-member or member
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'role' => 'Member',
            'email' => 'test2@test.com'
        ]);
        assertEquals(2, $ret['ret']);

        assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MEMBER));
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'role' => 'Member',
            'email' => 'test2@test.com'
        ]);
        assertEquals(2, $ret['ret']);

        assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'role' => 'Member',
            'email' => 'test2@test.com'
        ]);
        assertEquals(0, $ret['ret']);

        error_log(__METHOD__ . " end");
    }

    public function testRemove() {
        error_log(__METHOD__);

        assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'role' => 'Member',
            'email' => 'test2@test.com'
        ]);
        assertEquals(0, $ret['ret']);

        # Shouldn't be able to remove as non-member or member
        $this->user->removeMembership($this->groupid);
        $ret = $this->call('memberships', 'DELETE', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2
        ]);
        assertEquals(2, $ret['ret']);

        assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MEMBER));
        $ret = $this->call('memberships', 'DELETE', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2
        ]);
        assertEquals(2, $ret['ret']);

        assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_OWNER));
        $ret = $this->call('memberships', 'DELETE', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2
        ]);
        assertEquals(0, $ret['ret']);

        error_log(__METHOD__ . " end");
    }

    public function testGet() {
        error_log(__METHOD__);

        # Shouldn't be able to get as non-member or member
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'role' => 'Member',
            'email' => 'test2@test.com'
        ]);
        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid
        ]);
        assertEquals(2, $ret['ret']);

        assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MEMBER));
        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid
        ]);
        assertEquals(2, $ret['ret']);

        assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));
        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['members']));
        assertEquals($this->uid, $ret['members'][0]['id']);

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'userid' => $this->uid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($this->uid, $ret['member']['id']);

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'search' => 'test@'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['members']));
        assertEquals($this->uid, $ret['members'][0]['id']);

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'search' => 'st U'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['members']));
        assertEquals($this->uid, $ret['members'][0]['id']);

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'search' => 'wibble'
        ]);
        error_log("wibble search " . var_export($ret, true));
        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['members']));

        error_log(__METHOD__ . " end");
    }

    public function testDemote() {
        error_log(__METHOD__);

        assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));
        assertEquals(1, $this->user2->addMembership($this->groupid, User::ROLE_MEMBER));

        assertEquals(User::ROLE_MODERATOR, $this->user->getRole($this->groupid));

        # Demote ourselves - should work
        $ret = $this->call('memberships', 'PATCH', [
            'groupid' => $this->groupid,
            'userid' => $this->uid,
            'role' => 'Member'
        ]);
        assertEquals(0, $ret['ret']);

        assertEquals(User::ROLE_MEMBER, $this->user->getRole($this->groupid));

        # Try again - should fail as we're not a mod now.
        $ret = $this->call('memberships', 'PATCH', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'role' => 'Member'
        ]);
        assertEquals(2, $ret['ret']);

        error_log(__METHOD__ . " end");
    }

    public function testSettings() {
        error_log(__METHOD__);

        # Shouldn't be able to set as a different member.
        $settings = [ 'test' => true ];

        $ret = $this->call('memberships', 'PATCH', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'settings' => $settings
        ]);
        assertEquals(2, $ret['ret']);

        assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MEMBER));

        $ret = $this->call('memberships', 'PATCH', [
            'groupid' => $this->groupid,
            'userid' => $this->uid,
            'settings' => $settings
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'userid' => $this->uid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($settings, $ret['member']['settings']);

        assertEquals(1, $this->user2->addMembership($this->groupid, User::ROLE_MEMBER));
        assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));

        $ret = $this->call('memberships', 'PATCH', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'settings' => $settings
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($settings, $ret['member']['settings']);

        # Set a config
        $c = new ModConfig($this->dbhr, $this->dbhm);
        $cid = $c->create('testconfig');
        assertNotNull($cid);
        $ret = $this->call('memberships', 'PATCH', [
            'groupid' => $this->groupid,
            'userid' => $this->uid,
            'settings' => [
                'configid' => $cid
            ]
        ]);
        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'userid' => $this->uid
        ]);

        var_dump($ret);

        assertEquals($cid, $ret['member']['settings']['configid']);

        error_log(__METHOD__ . " end");
    }
}

