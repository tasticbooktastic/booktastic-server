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
class chatRoomsAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    protected function setUp() : void
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

//        $this->dbhr->errorLog = TRUE;
//        $this->dbhm->errorLog = TRUE;

        $dbhm->preExec("DELETE FROM chat_rooms WHERE name = 'test';");

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->assertNotNull($this->uid);
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->assertEquals($this->uid, $this->user->getId());
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_FREEGLE);
        $this->assertNotNull($this->groupid);

        $this->user->addEmail('test@test.com');
        $this->user->addMembership($this->groupid);
        $this->user->setMembershipAtt($this->groupid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $_SESSION['id'] = NULL;
    }

    public function testUser2User()
    {
        # Logged out - no rooms
        $ret = $this->call('chatrooms', 'GET', []);
        $this->assertEquals(1, $ret['ret']);
        $this->assertFalse(Utils::pres('chatrooms', $ret));

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');

        $c = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $c->createConversation($this->uid, $uid);

        # Just because it exists, doesn't mean we should be able to see it.
        $ret = $this->call('chatrooms', 'GET', []);
        $this->assertEquals(1, $ret['ret']);
        $this->assertFalse(Utils::pres('chatrooms', $ret));

        $this->assertTrue($this->user->login('testpw'));

        # Now we're talking.
        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2USER ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatrooms']));
        $this->log("Got chat room " . var_export($ret, TRUE));
        $this->assertEquals($rid, $ret['chatrooms'][0]['id']);
        $this->assertEquals('Test User', $ret['chatrooms'][0]['name']);

        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2USER ],
            'summary' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatrooms']));
        $this->assertEquals($rid, $ret['chatrooms'][0]['id']);
        $this->assertEquals('Test User', $ret['chatrooms'][0]['name']);

        $ret = $this->call('chatrooms', 'GET', [
            'id' => $rid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($rid, $ret['chatroom']['id']);

        $ret = $this->call('chatrooms', 'POST', [
            'id' => $rid,
            'lastmsgseen' => 2,
        ]);

        $ret = $this->call('chatrooms', 'GET', [
            'id' => $rid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($rid, $ret['chatroom']['id']);

        }

    public function testMod2Mod()
    {
        # Logged out - no rooms
        $ret = $this->call('chatrooms', 'GET', []);
        $this->assertEquals(1, $ret['ret']);
        $this->assertFalse(Utils::pres('chatrooms', $ret));

        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $c->createGroupChat('test', $this->groupid);

        # Just because it exists, doesn't mean we should be able to see it.
        $ret = $this->call('chatrooms', 'GET', []);
        $this->assertEquals(1, $ret['ret']);
        $this->assertFalse(Utils::pres('chatrooms', $ret));

        $this->assertTrue($this->user->login('testpw'));

        # Still not, even logged in.
        $ret = $this->call('chatrooms', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertFalse(Utils::pres('chatrooms', $ret));

        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));

        # Now we're talking.
        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_MOD2MOD ]
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Two rooms - one we've creted, and the automatic mod chat.
        $this->assertEquals(2, count($ret['chatrooms']));
        $this->assertTrue($rid == $ret['chatrooms'][0]['id'] || $rid == $ret['chatrooms'][1]['id']);
        $ratts = $rid == $ret['chatrooms'][0]['id'] ? $ret['chatrooms'][0] : $ret['chatrooms'][1];
        $this->assertEquals('testgroup Mods', $ratts['name']);

        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_MOD2MOD ],
            'summary' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(2, count($ret['chatrooms']));
        $this->assertTrue($rid == $ret['chatrooms'][0]['id'] || $rid == $ret['chatrooms'][1]['id']);
        $ratts = $rid == $ret['chatrooms'][0]['id'] ? $ret['chatrooms'][0] : $ret['chatrooms'][1];
        $this->assertEquals('testgroup Mods', $ratts['name']);

        $ret = $this->call('chatrooms', 'GET', [
            'id' => $rid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($rid, $ret['chatroom']['id']);

        # Roster
        $ret = $this->call('chatrooms', 'POST', [
            'id' => $rid,
            'lastmsgseen' => 1
        ]);
        $this->log(var_export($ret, TRUE));
        $this->assertEquals($this->uid, $ret['roster'][0]['userid']);
        $this->assertEquals('Test User', $ret['roster'][0]['user']['fullname']);
        $this->assertEquals('Online', $ret['roster'][0]['status']);

        $ret = $this->call('chatrooms', 'POST', [
            'id' => $rid,
            'lastmsgseen' => 1
        ]);
        $this->log(var_export($ret, TRUE));
        $this->assertEquals($this->uid, $ret['roster'][0]['userid']);
        $this->assertEquals('Test User', $ret['roster'][0]['user']['fullname']);

        }

    public function testUser2Mod()
    {
        $this->assertTrue($this->user->login('testpw'));

        # Create a support room from this user to the group mods
        $this->user->addMembership($this->groupid);

        error_log("Create chat {$this->groupid}");
        $ret = $this->call('chatrooms', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid,
            'chattype' => ChatRoom::TYPE_USER2MOD
        ]);
        $this->assertEquals(0, $ret['ret']);
        $rid = $ret['id'];
        $this->log("Created User2Mod $rid");
        $this->assertNotNull($rid);
        $this->assertFalse(Utils::pres('chatrooms', $ret));

        # Now we're talking.
        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2USER, ChatRoom::TYPE_MOD2MOD ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['chatrooms']));

        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2MOD ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatrooms']));
        $this->assertEquals($rid, $ret['chatrooms'][0]['id']);

        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2MOD ],
            'summary' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatrooms']));
        $this->assertEquals($rid, $ret['chatrooms'][0]['id']);

        # Now create a group mod
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u->addMembership($this->groupid);
        $this->assertTrue($u->login('testpw'));

        # Shouldn't see it before we promote.
        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2MOD ]
        ]);
        $this->log(var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['chatrooms']));

        # Now promote.
        $u->setRole(USer::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2MOD ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatrooms']));
        $this->assertEquals($rid, $ret['chatrooms'][0]['id']);

        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2MOD ],
            'summary' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatrooms']));
        $this->assertEquals($rid, $ret['chatrooms'][0]['id']);
    }

    public function testAllSeen()
    {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->assertNotNull($uid);
        $this->assertNotNull($this->uid);

        # Create an unseen message
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $c->createConversation($this->uid, $uid);
        $this->log("Created room $rid");
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($mid, $banned) = $m->create($rid, $uid, 'Test');
        $this->log("Created message $mid");

        $this->assertTrue($this->user->login('testpw'));

        # Check it's unseen
        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2USER ],
            'summary' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatrooms']));
        $this->assertEquals($rid, $ret['chatrooms'][0]['id']);
        $this->assertEquals(1, $ret['chatrooms'][0]['unseen']);

        $ret = $this->call('chatrooms', 'GET', [
            'count' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['count']);

        # Mark all seen - twice for coverage;
        for ($i = 0; $i < 2; $i++) {
            $ret = $this->call('chatrooms', 'POST', [
                'action' => 'AllSeen'
            ]);

            $ret = $this->call('chatrooms', 'GET', [
                'chattypes' => [ ChatRoom::TYPE_USER2USER ]
            ]);
            $this->log("Should be no unseen " . var_export($ret, TRUE));
            $this->assertEquals(0, $ret['ret']);
            $this->assertEquals($rid, $ret['chatrooms'][0]['id']);
            $this->assertEquals(0, $ret['chatrooms'][0]['unseen']);
        }

        $ret = $this->call('chatrooms', 'GET', [
            'count' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, $ret['count']);

        # Mark as unseen.
        $ret = $this->call('chatrooms', 'POST', [
            'id' => $rid,
            'lastmsgseen' => null
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2USER ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($rid, $ret['chatrooms'][0]['id']);
        $this->assertEquals(1, $ret['chatrooms'][0]['unseen']);
    }

    public function testNudge() {
        $u = User::get($this->dbhr, $this->dbhr);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->assertNotNull($uid);
        $this->assertNotNull($this->uid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        # Create an unseen message
        $c = new ChatRoom($this->dbhr, $this->dbhr);
        list ($rid, $blocked) = $c->createConversation($this->uid, $uid);
        $this->log("Created room $rid between {$this->uid} and $uid");

        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('chatrooms', 'POST', [
            'id' => $rid,
            'action' => 'Nudge'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $nudges = $c->nudges($uid);
        $this->assertEquals($uid, $nudges[0]['touser']);

        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['user']['info']['nudges']['sent']);
        $this->assertEquals(0, $ret['user']['info']['nudges']['responded']);

        # Now reply - should mark the nudge as handled
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('chatmessages', 'POST', [ 'roomid' => $rid, 'message' => 'Test' ]);
        $this->assertEquals(0, $ret['ret']);

        $this->waitBackground();

        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['user']['info']['nudges']['sent']);
        $this->assertEquals(1, $ret['user']['info']['nudges']['responded']);

    }

    public function testInvalidId() {
        $u = User::get($this->dbhr, $this->dbhr);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->assertNotNull($uid);
        $this->assertNotNull($this->uid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        # Create an unseen message
        $c = new ChatRoom($this->dbhr, $this->dbhr);
        list ($rid, $blocked) = $c->createConversation($this->uid, $uid);
        $this->log("Created room $rid between {$this->uid} and $uid");

        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('chatrooms', 'GET', [
            'id' => -$rid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertFalse(array_key_exists('chatroom', $ret));
    }

    public function testBanned() {
        $u1 = User::get($this->dbhr, $this->dbhr);
        $uid1 = $u1->create(NULL, NULL, 'Test User');
        $this->assertNotNull($uid1);
        $this->assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u2 = User::get($this->dbhr, $this->dbhr);
        $uid2 = $u2->create(NULL, NULL, 'Test User');
        $this->assertNotNull($uid2);
        $this->assertGreaterThan(0, $u2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        # Ban u1
        $gid = $this->groupid;
        $u1->addMembership($gid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED);
        $u1->removeMembership($gid, TRUE);

        # u2 a member on group
        $u2->addMembership($gid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED);

        # Put a message on the group.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($msgid, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # u1 should not be able to open a chat to u2 as they are banned on all groups in common.
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $r->createConversation($uid1, $uid2);
        $this->assertNull($rid);
        $this->assertTrue($blocked);

        # Test a different case with other group memberships.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid2 = $g->create('testgroup2', Group::GROUP_FREEGLE);
        $u1->addMembership($gid2);
        list ($rid, $blocked) = $r->createConversation($uid1, $uid2);
        $this->assertNull($rid);
        $this->assertTrue($blocked);

        # Ban should show in support tools.
        $u1->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        $this->assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u1->login('testpw'));
        $ret = $this->call('user', 'GET', [
            'search' => $u1->getId()
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['users'][0]['bans']));
    }

    public function testMark() {
        $u1 = User::get($this->dbhr, $this->dbhr);
        $uid1 = $u1->create(NULL, NULL, 'Test User');
        $u1->addEmail('test3@test.com');
        $this->assertNotNull($uid1);
        $this->assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u2 = User::get($this->dbhr, $this->dbhr);
        $uid2 = $u2->create(NULL, NULL, 'Test User');
        $this->assertNotNull($uid2);
        $this->assertGreaterThan(0, $u2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $gid = $this->groupid;
        $u1->addMembership($gid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED);
        $u2->addMembership($gid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED);

        # Put a message on the group.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('test@test.com', 'test3@test.com', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($msgid, $failok) = $r->received(Message::EMAIL, 'test3@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $msgid);
        $this->assertEquals($uid1, $m->getFromuser());
        $m->approve($gid);

        # u2 logs in and replies to message.
        $u2->login('testpw');
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $uid1,
            'chattype' => ChatRoom::TYPE_USER2USER
        ]);
        $this->assertEquals(0, $ret['ret']);
        $rid = $ret['id'];
        $this->assertNotNull($rid);

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $rid,
            'message' => 'Test',
            'refmsgid' => $msgid
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Reply should show in snippet.
        $ret = $this->call('chatrooms', 'GET', [
            'id' => $rid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('Test', $ret['chatroom']['snippet']);

        # u1 logs in and marked message as taken.
        $u1->login('testpw');
        $ret = $this->call('message', 'POST', [
            'id' => $msgid,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_TAKEN
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->waitBackground();

        # Taken should show in snippet.
        $ret = $this->call('chatrooms', 'GET', [
            'id' => $rid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('Item marked as TAKEN', $ret['chatroom']['snippet']);
    }


    public function testReferToSupport() {
        $this->assertTrue($this->user->login('testpw'));

        # Create a support room from this user to the group mods
        $this->user->addMembership($this->groupid);

        $ret = $this->call('chatrooms', 'PUT', [
            'groupid' => $this->groupid,
            'userid' => $this->uid,
            'chattype' => ChatRoom::TYPE_USER2MOD
        ]);
        $this->assertEquals(0, $ret['ret']);
        $rid = $ret['id'];
        $this->log("Created User2Mod $rid");
        $this->assertNotNull($rid);

        # Now create a group mod
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u->addEmail('test2@test.com');
        $u->addMembership($this->groupid);
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('chatrooms', 'POST', [
            'id' => $rid,
            'action' => ChatRoom::ACTION_REFER_TO_SUPPORT,
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

//
//    public function testEH() {
//        $_SESSION['id'] = 35822275;
//        $ret = $this->call('chatrooms', 'GET', [
//            'chattypes' => [
//                ChatRoom::TYPE_USER2MOD,
//                ChatRoom::TYPE_MOD2MOD,
//            ],
//            'summary' => TRUE,
//            'modtools'=> TRUE
//        ]);
//        $this->assertEquals(0, $ret['ret']);
//        $this->log("Took {$ret['duration']} DB {$ret['dbwaittime']}");
//    }
}
