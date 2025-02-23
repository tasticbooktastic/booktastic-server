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
class membershipsAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");
        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM users WHERE yahooid LIKE '-testid%';");
        $dbhm->preExec("DELETE FROM users_emails WHERE backwards LIKE 'moctset%';");

        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $this->group->create('testgroup', Group::GROUP_FREEGLE);
        $this->group->setPrivate('onhere', TRUE);

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->assertNotNull($this->uid);
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->user->addEmail('test@test.com');
        $this->user->addMembership($this->groupid);
        $this->user->setMembershipAtt($this->groupid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $this->uid2 = $u->create(NULL, NULL, 'Test User');
        $this->assertNotNull($this->uid);
        $this->user2 = User::get($this->dbhr, $this->dbhm, $this->uid2);
        $this->user2->addEmail('tes2t@test.com');
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertGreaterThan(0, $this->user2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($this->user->login('testpw'));
    }

    public function testAdd() {
        # Should be able to add (i.e. join) as a non-member or a member.
        $_SESSION['id'] = $this->uid2;

        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'role' => 'Member'
        ]);
        $this->assertEquals(0, $ret['ret']);
        self::assertEquals(MembershipCollection::APPROVED, $ret['addedto']);

        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'role' => 'Member',
            'email' => 'test2@test.com'
        ]);
        $this->assertEquals(0, $ret['ret']);
        self::assertEquals(MembershipCollection::APPROVED, $ret['addedto']);

        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'role' => 'Member',
            'email' => 'test2@test.com',
            'dup' => 1
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(MembershipCollection::APPROVED, $ret['addedto']);
        $this->assertNotNull($this->user2->isApprovedMember($this->groupid));
    }

    public function testAddAsMod() {
        # Should be able to add (i.e. join) as a non-member or a member.
        $_SESSION['id'] = $this->uid;
        $this->user->addMembership($this->groupid, User::ROLE_MODERATOR);

        # Ban the member - the add should override.
        $this->user2->addMembership($this->groupid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED);
        $this->assertNotNull($this->user2->isApprovedMember($this->groupid));
        $this->user2->removeMembership($this->groupid, TRUE);

        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'role' => 'Member',
            'email' => 'test2@test.com'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(MembershipCollection::APPROVED, $ret['addedto']);
        $this->assertNotNull($this->user2->isApprovedMember($this->groupid));

        # Ban them again.
        $ret = $this->call('memberships', 'DELETE', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertNull($this->user2->isApprovedMember($this->groupid));

        # Unban them
        $ret = $this->call('memberships', 'POST', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'action' => 'Unban'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertNull($this->user2->isApprovedMember($this->groupid));

        # They should be able to join.
        # Should be able to add (i.e. join) as a non-member or a member.
        $_SESSION['id'] = $this->uid2;

        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'role' => 'Member'
        ]);
        $this->assertEquals(0, $ret['ret']);
        self::assertEquals(MembershipCollection::APPROVED, $ret['addedto']);
    }

    public function testJoinAndSee() {
        # Check that if we join a group we can see messages on it immediately.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('22 Aug 2015', '22 Aug 2035', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('sourceheader', Message::PLATFORM);

        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $this->log("Approved id $id");

        # We have moderator role on our own message.
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);

        self::assertEquals('Moderator', $ret['message']['myrole']);

        # Set this message so that it's not from us, and then remove our membership.  Then our role should be
        # non-member.
        $m->setPrivate('fromuser', NULL);
        $this->user->removeMembership($this->groupid);

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);

        self::assertEquals('Non-member', $ret['message']['myrole']);

        # Join
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid,
            'role' => 'Member'
        ]);
        $this->assertEquals(0, $ret['ret']);
        self::assertEquals(MembershipCollection::APPROVED, $ret['addedto']);

        }

    public function testRemove() {
        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'role' => 'Member',
            'email' => 'test2@test.com'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Shouldn't be able to remove as non-member or member
        $this->user->removeMembership($this->groupid);
        $ret = $this->call('memberships', 'DELETE', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MEMBER));
        $ret = $this->call('memberships', 'DELETE', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_OWNER));
        $ret = $this->call('memberships', 'DELETE', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'dedup' => true
        ]);
        $this->assertEquals(0, $ret['ret']);

        }

    public function testGet() {
        # Shouldn't be able to get as non-member or member
        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid
        ]);
        $this->log("Got memberships " . var_export($ret, TRUE));
        $this->assertEquals(2, $ret['ret']);

        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MEMBER));
        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Should be able to get the minimal set of membership info for unsubscribe.
        $ret = $this->call('memberships', 'GET', [
            'email' => 'test@test.com'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['memberships']));
        $this->assertEquals($this->groupid, $ret['memberships'][0]['id']);
        $ret = $this->call('memberships', 'GET', [
            'email' => 'invalid@test.com'
        ]);
        $this->assertEquals(3, $ret['ret']);

        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));
        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['members']));
        $this->assertEquals($this->uid, $ret['members'][0]['userid']);

        # Sleep for background logging
        $this->waitBackground();

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'userid' => $this->uid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($this->uid, $ret['member']['userid']);

        $ctx = NULL;
        $logs = [ $this->uid => [ 'id' => $this->uid ] ];
        $u = new User($this->dbhr, $this->dbhm);
        $u->getPublicLogs($u, $logs, FALSE, $ctx, FALSE, TRUE);
        $log = $this->findLog(Log::TYPE_GROUP, Log::SUBTYPE_JOINED, $logs[$this->uid]['logs']);
        $this->assertEquals($this->groupid, $log['group']['id']);

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'search' => 'test@'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['members']));
        $this->assertEquals($this->uid, $ret['members'][0]['userid']);

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'search' => 'Test U'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['members']));
        $this->assertEquals($this->uid, $ret['members'][0]['userid']);

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'search' => 'wibble'
        ]);
        $this->log("wibble search " . var_export($ret, true));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['members']));

        }

    public function testDemote() {
        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));
        $this->assertEquals(1, $this->user2->addMembership($this->groupid, User::ROLE_MEMBER));

        $this->assertEquals(User::ROLE_MODERATOR, $this->user->getRoleForGroup($this->groupid));

        # Demote ourselves - should work
        $ret = $this->call('memberships', 'PATCH', [
            'groupid' => $this->groupid,
            'userid' => $this->uid,
            'role' => 'Member'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $this->assertEquals(User::ROLE_MEMBER, $this->user->getRoleForGroup($this->groupid));

        # Try again - should fail as we're not a mod now.
        $ret = $this->call('memberships', 'PATCH', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'role' => 'Member'
        ]);
        $this->assertEquals(2, $ret['ret']);

        }

    public function testJoinNotDemote() {
        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));

        # Join ourselves - should work
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid,
            'role' => 'Member',
            'manual' => true
        ]);
        $this->assertEquals(0, $ret['ret']);

        $this->assertEquals(User::ROLE_MODERATOR, $this->user->getRoleForGroup($this->groupid));
    }

    public function reasonProvider() {
        return [
            [ TRUE ],
            [ FALSE ],
        ];
    }
    /**
     * @dataProvider reasonProvider
     */
    public function testJoinReason($reason) {
        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));

        # Join ourselves - should work
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');

        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $uid,
            'role' => 'Member',
            'manual' => $reason
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Sleep for background logging
        $this->waitBackground();

        $ctx = NULL;
        $logs = [ $uid => [ 'id' => $uid ] ];
        $this->user->getPublicLogs($u, $logs, FALSE, $ctx, FALSE, TRUE);
        $log = $this->findLog(Log::TYPE_GROUP, Log::SUBTYPE_JOINED, $logs[$uid]['logs']);

        $reasons = NULL;

        if ($reason !== NULL) {
            $reasons = $reason ? 'Manual' : 'Auto';
        }

        $this->assertEquals($reasons, $log['text']);
    }

    public function testSettings() {
        # Shouldn't be able to set as a different member.
        $settings = [ 'test' => true ];

        $ret = $this->call('memberships', 'PATCH', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'settings' => $settings
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MEMBER));

        $ret = $this->call('memberships', 'PATCH', [
            'groupid' => $this->groupid,
            'userid' => $this->uid,
            'settings' => $settings
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'userid' => $this->uid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($settings, $ret['member']['settings']);

        $ret = $this->call('memberships', 'PATCH', [
            'groupid' => $this->groupid,
            'userid' => $this->uid,
            'emailfrequency' => 8,
            'eventsallowed' => 0,
            'volunteeringallowed' => 0,
            'ourpostingstatus' => 'DEFAULT'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'userid' => $this->uid
        ]);
        $this->log(var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(8, $ret['member']['emailfrequency']);
        $this->assertEquals(0, $ret['member']['eventsallowed']);
        $this->assertEquals(0, $ret['member']['volunteeringallowed']);
        self::assertEquals('DEFAULT', $ret['member']['ourpostingstatus']);
        
        $this->assertEquals(1, $this->user2->addMembership($this->groupid, User::ROLE_MEMBER));
        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));

        $ret = $this->call('memberships', 'PATCH', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'settings' => $settings
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($settings, $ret['member']['settings']);

        # Set a config
        $c = new ModConfig($this->dbhr, $this->dbhm);
        $cid = $c->create('testconfig');
        $this->assertNotNull($cid);
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

        $this->assertEquals($cid, $ret['member']['settings']['configid']);

        }

    public function testMembers() {
        # Not logged in - shouldn't see members list
        $ret = $this->call('memberships', 'PATCH', [
            'groupid' => $this->groupid,
            'members' => TRUE
        ]);
        $this->assertEquals(2, $ret['ret']);
        $this->assertFalse(Utils::pres('members', $ret));

        # Member - shouldn't see members list
        $this->log("Login as " . $this->user->getId());
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('memberships', 'PATCH', [
            'groupid' => $this->groupid,
            'members' => TRUE
        ]);
        $this->assertEquals(2, $ret['ret']);
        $this->assertFalse(Utils::pres('members', $ret));

        # Mod - should see members list
        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_OWNER));
    }

    public function testPendingMembers() {
        $this->assertTrue($this->user->login('testpw'));
        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_OWNER));

        $ret = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::APPROVED
        ]);
        $this->log("Returned " . var_export($ret, TRUE));

        $this->assertEquals(1, count($ret['members']));
    }

    public function testDelete() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->assertNotNull($uid);
        $this->assertTrue($u->addMembership($this->groupid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED));

        # Shouldn't be able to do this as a non-member.
        $ret = $this->call('memberships', 'POST', [
            'userid' => $uid,
            'groupid' => $this->groupid,
            'action' => 'Delete',
            'subject' => "Test",
            'body' => "Test"
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Shouldn't be able to do this as a member
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('memberships', 'POST', [
            'userid' => $uid,
            'groupid' => $this->groupid,
            'action' => 'Delete',
            'body' => "Test",
            'dup' => 1
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->assertTrue($this->user->addMembership($this->groupid, User::ROLE_MODERATOR, NULL, MembershipCollection::APPROVED));

        # Should work as a moderator, and will not be pending any more.
        $ret = $this->call('memberships', 'POST', [
            'userid' => $uid,
            'groupid' => $this->groupid,
            'action' => 'Delete',
            'dup' => 2
        ]);
        $this->assertEquals(0, $ret['ret']);

        }

    public function testUnsubscribe() {
        $this->assertTrue($this->user->addMembership($this->groupid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED));

        # For invalid user
        $ret = $this->call('memberships', 'DELETE', [
            'email' => 'invalid@test.com',
            'groupid' => $this->groupid
        ]);
        $this->assertEquals(3, $ret['ret']);

        # For invalid group
        $ret = $this->call('memberships', 'DELETE', [
            'email' => 'test@test.com',
            'groupid' => $this->groupid + 1
        ]);
        $this->assertEquals(4, $ret['ret']);

        # For mod
        $this->assertTrue($this->user->addMembership($this->groupid, User::ROLE_MODERATOR, NULL, MembershipCollection::APPROVED));
        $ret = $this->call('memberships', 'DELETE', [
            'email' => 'test@test.com',
            'groupid' => $this->groupid
        ]);
        $this->assertEquals(4, $ret['ret']);

        # Success
        $this->assertTrue($this->user->addMembership($this->groupid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED));
        $ret = $this->call('memberships', 'DELETE', [
            'email' => 'test@test.com',
            'groupid' => $this->groupid
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

    public function testFilter() {
        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('memberships', 'GET', [
            'id' => $this->groupid,
            'members' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);

        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addMembership($this->groupid);

        $ret = $this->call('memberships', 'GET', [
            'id' => $this->groupid,
            'members' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(2, count($ret['members']));

        $ret = $this->call('memberships', 'GET', [
            'id' => $this->groupid,
            'filter' => Group::FILTER_NONE,
            'members' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(2, count($ret['members']));

        $ret = $this->call('memberships', 'GET', [
            'id' => $this->groupid,
            'filter' => Group::FILTER_WITHCOMMENTS,
            'members' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['members']));

        $ret = $this->call('memberships', 'GET', [
            'id' => $this->groupid,
            'filter' => Group::FILTER_WITHCOMMENTS,
            'members' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['members']));

        $u->addComment($this->groupid, 'Test comment');

        $ret = $this->call('memberships', 'GET', [
            'id' => $this->groupid,
            'filter' => Group::FILTER_WITHCOMMENTS,
            'members' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['members']));

        }

    function testHappiness() {
        # Create the sending user
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user $uid");
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $this->assertEquals(0, $u->addEmail('test@test.com'));

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Subject: Basic test', 'Subject: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($origid, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->assertNotNull($origid);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Now mark the message as complete
        $this->log("Mark $origid as TAKEN");
        $m = new Message($this->dbhr, $this->dbhm, $origid);
        $m->mark(Message::OUTCOME_TAKEN, "Thanks", User::HAPPY, $uid);
        $this->waitBackground();

        # Should show as unreviewed, but can't get as member.
        $ret = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::HAPPINESS,
            'groupid' => $this->groupid
        ]);

        $this->assertEquals(2, $ret['ret']);

        # Should get as mod.
        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::HAPPINESS,
            'groupid' => $this->groupid,
            'context' => [
                'reviewed' => 0,
                'timestamp' => '2050-01-01',
                'id' => PHP_INT_MAX
            ]
        ]);

        $this->assertEquals(1, count($ret['members']));
        $this->assertEquals(0, $ret['members'][0]['reviewed']);

        # Happiness count should show in dashboard.
        $ret3 = $this->call('dashboard', 'GET', [
            'components' => [ Dashboard::COMPONENTS_HAPPINESS ],
            'group' => $this->groupid
        ]);
        $this->assertEquals(1, count($ret3['components']['Happiness']));
        $this->assertEquals(1, $ret3['components']['Happiness'][0]['count']);

        $ret3 = $this->call('dashboard', 'GET', [
            'components' => [ Dashboard::COMPONENTS_HAPPINESS ]
        ]);
        $this->assertGreaterThanOrEqual(1, $ret3['components']['Happiness'][0]['count']);

        # Test filter.
        $ret2 = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::HAPPINESS,
            'groupid' => $this->groupid,
            'filter' => User::HAPPY
        ]);

        $this->assertEquals(1, count($ret2['members']));
        $this->assertEquals(0, $ret2['members'][0]['reviewed']);

        $ret2 = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::HAPPINESS,
            'groupid' => $this->groupid,
            'filter' => User::UNHAPPY
        ]);

        $this->assertEquals(0, count($ret2['members']));

        $params = [
            'userid' => $ret['members'][0]['user']['id'],
            'happinessid' => $ret['members'][0]['id'],
            'action' => 'HappinessReviewed',
            'groupid' => $this->groupid,
            'dup' => TRUE
        ];
        $ret = $this->call('memberships', 'POST', $params);

        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::HAPPINESS,
            'groupid' => $this->groupid
        ]);

        $this->assertEquals(1, count($ret['members']));
        $this->assertEquals(1, $ret['members'][0]['reviewed']);
    }

    public function testNearby() {
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::NEARBY
        ]);

        $this->assertEquals(2, $ret['ret']);

        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));
        $this->user->setSetting('mylocation', [
            'lng' => 179.15,
            'lat' => 8.5
        ]);
        $this->user->setPrivate('lastaccess', date("Y-m-d H:i:s"));

        $n = new Nearby($this->dbhr, $this->dbhm);
        $n->updateLocations();

        $ret = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::NEARBY
        ]);

        $this->assertEquals(0, $ret['ret']);

        $found = FALSE;

        foreach ($ret['members'] as $member) {
            if ($member['userid'] == $this->user->getId()) {
                $found = TRUE;
            }
        }

        $this->assertTrue($found);
    }

    public function unreadProvider() {
        return([
            [ TRUE ],
            [ FALSE ]
        ]);
    }

    /**
     * @param $unread
     * @dataProvider unreadProvider
     */

    public function testChatUnread($chatread) {
        # Create two mods on the group
        $this->user2->addMembership($this->groupid, User::ROLE_MODERATOR);
        $othermod = User::get($this->dbhr, $this->dbhm);
        $othermoduid = $othermod->create(NULL, NULL, 'Test User');
        $othermod->addMembership($this->groupid, User::ROLE_MODERATOR);

        # Create a ModConfig.
        $c = new ModConfig($this->dbhr, $this->dbhm);
        $cid = $c->create('Test');
        $c->useOnGroup($this->uid2, $this->groupid);
        error_log("Created config $cid");
        $c->setPrivate('chatread', $chatread);

        $s = new StdMessage($this->dbhr, $this->dbhm);
        $sid = $s->create('Test', $cid);
        $s = new StdMessage($this->dbhr, $this->dbhm, $sid);
        $s->setPrivate('action', 'Leave Approved Member');

        # Send them a mail.
        $_SESSION['id'] = $this->uid2;

        $ret = $this->call('memberships', 'POST', [
            'stdmsgid' => $sid,
            'userid' => $this->uid,
            'groupid' => $this->groupid,
            'subject' => 'Yo',
            'body' => 'Dudette',
            'action' => 'Leave Approved Member'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Sending mod should not see this as unread, but other mod depends on param.
        $cr = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $cr->createUser2Mod($this->uid, $this->groupid);
        $this->assertEquals(0, $cr->unseenCountForUser($this->uid2));
        $this->assertEquals($chatread ? 0 : 1, $cr->unseenCountForUser($othermoduid));
    }

    public function testSearchBanned() {
        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));
        $this->assertEquals(1, $this->user2->addMembership($this->groupid));
        $this->assertTrue($this->user->login('testpw'));

        // Search for the member - should find.
        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'search' => 'tes2t@test.com'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['members']));
        $this->assertEquals($this->user2->getId(), $ret['members'][0]['userid']);
        $this->assertEquals(MembershipCollection::APPROVED, $ret['members'][0]['collection']);

        // Ban them and search again - should still find.
        $this->user2->removeMembership($this->groupid, TRUE);

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'search' => 'tes2t@test.com'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['members']));
        $this->assertEquals($this->user2->getId(), $ret['members'][0]['userid']);
        $this->assertEquals(MembershipCollection::BANNED, $ret['members'][0]['collection']);
    }

    public function testBanNonMember() {
        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));
        $this->assertTrue($this->user->login('testpw'));

        // Search for the member - should find.
        $ret = $this->call('memberships', 'DELETE', [
            'groupid' => $this->groupid,
            'userid' => $this->user2->getId(),
            'ban' => TRUE
        ]);

        $this->assertEquals(0, $ret['ret']);

        // Try to join - should fail.
        $this->assertTrue($this->user2->login('testpw'));
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid2,
            'role' => 'Member'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertNull($this->user2->isApprovedMember($this->groupid));
        $this->assertTrue($this->user2->isBanned($this->groupid));
    }

    public function testJoinPartner() {
        $key = Utils::randstr(64);
        $id = $this->dbhm->preExec("INSERT INTO partners_keys (`partner`, `key`, `domain`) VALUES ('UT', ?, ?);", [$key, 'partner.com']);
        $this->assertNotNull($id);

        // Create user with email test@partner.com
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test@partner.com');
        $uid2 = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test@partner2.com');

        // Without key = should fail.
        $GLOBALS['sessionPrepared'] = FALSE;
        $_SESSION['id'] = NULL;
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'email' => 'test@partner.com',
        ]);
        $this->assertEquals(2, $ret['ret']);

        // Without key = should fail.
        $GLOBALS['sessionPrepared'] = FALSE;
        $_SESSION['id'] = NULL;
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'email' => 'test@partner.com',
            'partner' => '1234'
        ]);
        $this->assertEquals(2, $ret['ret']);

        // Valid key but wrong domain = should fail
        $GLOBALS['sessionPrepared'] = FALSE;
        $_SESSION['id'] = NULL;
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'email' => 'test@partner2.com',
            'partner' => $key
        ]);
        $this->assertEquals(2, $ret['ret']);

        // Valid key, valid domain = should work
        $GLOBALS['sessionPrepared'] = FALSE;
        $_SESSION['id'] = NULL;
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'email' => 'test@partner.com',
            'partner' => $key
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue(array_key_exists('fduserid', $ret));

        // Leave
        $GLOBALS['sessionPrepared'] = FALSE;
        $_SESSION['id'] = NULL;
        $ret = $this->call('memberships', 'DELETE', [
            'groupid' => $this->groupid,
            'email' => 'test@partner.com',
            'partner' => $key
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue(array_key_exists('fduserid', $ret));
    }

    public function testJoinNewMemberPartner() {
        $key = Utils::randstr(64);
        $id = $this->dbhm->preExec("INSERT INTO partners_keys (`partner`, `key`, `domain`) VALUES ('UT', ?, ?);", [$key, 'partner.com']);
        $this->assertNotNull($id);

        $GLOBALS['sessionPrepared'] = FALSE;
        $_SESSION['id'] = NULL;
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $this->groupid,
            'email' => 'test@partner.com',
            'partner' => $key
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue(array_key_exists('fduserid', $ret));
    }

    public function testInvalidGroup() {
        $key = Utils::randstr(64);
        $id = $this->dbhm->preExec("INSERT INTO partners_keys (`partner`, `key`, `domain`) VALUES ('UT', ?, ?);", [$key, 'partner.com']);
        $this->assertNotNull($id);

        // Create user with email test@partner.com
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test@partner.com');
        $uid2 = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test@partner2.com');

        // Invalid groupid
        $GLOBALS['sessionPrepared'] = FALSE;
        $_SESSION['id'] = NULL;
        $groups = $this->dbhr->preQuery("SELECT MAX(id) AS maxid FROM `groups`;");
        $invalid = $groups[0]['maxid'] + 1;
        $ret = $this->call('memberships', 'PUT', [
            'groupid' => $invalid,
            'email' => 'test@partner.com',
            'partner' => $key
        ]);
        $this->assertEquals(5, $ret['ret']);
    }

//
//    public function testEH() {
//        $_SESSION['id'] = 420;
//
//        $ret = $this->call('memberships', 'POST', [
//
//            "action" => "Leave Approved Member","userid" => 37462787,"groupid" => 21662,"subject" => "Testing 2","stdmsgid" => 158574,"body" => "Testing again","modtools" => true
//        ]);
//    }
}

