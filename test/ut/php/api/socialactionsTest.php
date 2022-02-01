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
class socialactionsAPITest extends IznikAPITestCase
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
    }

    public function testBasic()
    {
        if (getenv('FACEBOOK_PAGEACCESS_TOKEN')) {
            $this->scrapePosts();

            # Log in as a mod of the Playground group, which has a Facebook page.
            $u = User::get($this->dbhr, $this->dbhm);
            $uid = $u->create('Test', 'User', 'Test User');
            assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

            $g = Group::get($this->dbhr, $this->dbhm);
            $gid = $g->findByShortName('FreeglePlayground');

            $u->addMembership($gid, User::ROLE_MODERATOR);

            $ids = GroupFacebook::listForGroup($this->dbhr, $this->dbhm, $gid);
            $fbs = GroupFacebook::listForGroups($this->dbhr, $this->dbhm, [ $gid ]);

            assertEquals(1, count($fbs));

            foreach ($ids as $id) {
                $found = FALSE;

                foreach ($fbs[$gid] as $fb) {
                    if ($fb['uid'] == $id) {
                        $found = TRUE;
                    }
                }

                assertTrue($found);
            }

            foreach ($ids as $uid) {
                # Delete the last share so that there will be at least one.
                $this->dbhm->preExec("DELETE FROM groups_facebook_shares WHERE groupid = $gid ORDER BY date DESC LIMIT 1;");
                $this->dbhm->preExec("UPDATE groups_facebook SET valid = 1 WHERE groupid = $gid");

                $u->addMembership($gid, User::ROLE_MODERATOR);

                assertTrue($u->login('testpw'));

                # Now we're talking.
                $orig = $this->call('socialactions', 'GET', []);
                assertEquals(0, $orig['ret']);
                assertGreaterThan(0, count($orig['socialactions']));

                $ret = $this->call('socialactions', 'POST', [
                    'id' => $orig['socialactions'][0]['id'],
                    'uid' => $uid
                ]);

                assertEquals(0, $ret['ret']);

                # Shouldn't show in list of groups now.
                $ret = $this->call('socialactions', 'GET', []);
                $this->log("Shouldn't show in " . var_export($ret, TRUE));
                assertEquals(0, $ret['ret']);

                assertTrue(count($ret['socialactions']) == 0 || $ret['socialactions'][0]['id'] != $orig['socialactions'][0]['id']);

                # Force a failure for coverage.
                $tokens = $this->dbhr->preQuery("SELECT * FROM groups_facebook WHERE groupid = $gid;");
                $this->dbhm->preExec("UPDATE groups_facebook SET token = 'a' WHERE groupid = $gid");

                $ret = $this->call('socialactions', 'POST', [
                    'id' => $orig['socialactions'][0]['id'],
                    'uid' => $uid,
                    'dedup' => TRUE
                ]);

                $this->dbhm->preExec("UPDATE groups_facebook SET token = '{$tokens[0]['token']}' WHERE groupid = $gid");

                assertEquals(2, $ret['ret']);

                # Get again for coverage.
                $ret = $this->call('socialactions', 'GET', []);
                assertEquals(0, $ret['ret']);
            }
        }

        assertTrue(TRUE);
    }

    private function scrapePosts() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->findByShortName('FreeglePlayground');

        $token = getenv('FREEGLEPLAYGROUND_TOKEN');

        if ($token ) {
            # Running on Travis - set up the token.
            $this->dbhm->preExec(
                "INSERT INTO groups_facebook (groupid, name, type, id, token, authdate) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE token = ?;",
                [
                    $gid,
                    'FreeglePlayground',
                    'Page',
                    getenv('FREEGLEPLAYGROUND_PAGEID'),
                    $token,
                    $token
                ]
            );
        }

        # Get some posts to share.
        $this->dbhm->preExec("DELETE FROM groups_facebook_toshare WHERE 1;");
        $f = new GroupFacebook($this->dbhr, $this->dbhm);
        $f->getPostsToShare(134117207097, "last week", getenv('FACEBOOK_PAGEACCESS_TOKEN'));
    }

    public function testHide()
    {
        if (getenv('FACEBOOK_PAGEACCESS_TOKEN')) {
            $this->scrapePosts();

            $g = Group::get($this->dbhr, $this->dbhm);
            $gid = $g->findByShortName('FreeglePlayground');

            # Log in as a mod of the Playground group, which has a Facebook page.
            $u = User::get($this->dbhr, $this->dbhm);
            $uid = $u->create('Test', 'User', 'Test User');
            assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, null, 'testpw'));

            $u->addMembership($gid, User::ROLE_MODERATOR);

            $ids = GroupFacebook::listForGroup($this->dbhr, $this->dbhm, $gid);
            assertGreaterThan(0, count($ids));

            foreach ($ids as $uid) {
                # Delete the last share so that there will be at least one.
                $this->dbhm->preExec(
                    "DELETE FROM groups_facebook_shares WHERE groupid = $gid ORDER BY date DESC LIMIT 1;"
                );
                $this->dbhm->preExec("UPDATE groups_facebook SET valid = 1 WHERE groupid = $gid");

                $u->addMembership($gid, User::ROLE_MODERATOR);

                assertTrue($u->login('testpw'));

                # Now we're talking.
                $orig = $this->call('socialactions', 'GET', []);
                assertEquals(0, $orig['ret']);
                assertGreaterThan(0, count($orig['socialactions']));

                $ret = $this->call(
                    'socialactions',
                    'POST',
                    [
                        'id' => $orig['socialactions'][0]['id'],
                        'uid' => $uid,
                        'action' => 'Hide'
                    ]
                );

                assertEquals(0, $ret['ret']);

                # Shouldn't show in list of groups now.
                $ret = $this->call('socialactions', 'GET', []);
                assertEquals(0, $ret['ret']);

                assertTrue(
                    count(
                        $ret['socialactions']
                    ) == 0 || $ret['socialactions'][0]['id'] != $orig['socialactions'][0]['id']
                );
            }
        }

        assertTrue(TRUE);
    }

    /**
     * @dataProvider trueFalseProvider
     */
    public function testPopular($share) {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');
        $u->addMembership($gid, User::ROLE_MODERATOR);
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $u->addEmail('test@test.com');

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $msg = str_replace("Hey", "Hey {{username}}", $msg);

        $r = new MailRouter($this->dbhm, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        assertEquals([], $g->getPopularMessages($gid));

        # No views - no popular messages.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->approve($gid);
        $m->like($m->getFromuser(), Message::LIKE_VIEW);
        $this->waitBackground();
        $g->findPopularMessages();
        $popid = $g->getPopularMessages($gid)[0]['msgid'];
        assertEquals($id, $popid);

        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        # Should show as sharable.
        $ret = $this->call('socialactions', 'GET', []);

        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['popularposts'][0]['msgid']);
        assertEquals($gid, $ret['popularposts'][0]['groupid']);

        if ($share) {
            $ret = $this->call('socialactions', 'POST', [
                'action' => GroupFacebook::ACTION_DO_POPULAR,
                'msgid' => $id,
                'groupid' => $gid
            ]);
        } else {
            $ret = $this->call('socialactions', 'POST', [
                'action' => GroupFacebook::ACTION_HIDE_POPULAR,
                'msgid' => $id,
                'groupid' => $gid
            ]);
        }

        assertEquals(0, $ret['ret']);

        # Should no longer show as sharable.
        $ret = $this->call('socialactions', 'GET', []);
        assertEquals([], $ret['popularposts']);
    }
}
