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
class IncomingMessageTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.email IN ('test@test.com', 'test2@test.com');");
    }

    public function testBasic() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $t = "TestUser" . microtime(true) . "@test.com";
        $msg = str_replace('From: "Test User" <test@test.com>', 'From: "' . $t . '" <test@test.com>', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->assertEquals('Basic test', $m->getSubject());
        $this->assertEquals($t, $m->getFromname());
        $this->assertEquals('test@test.com', $m->getFromaddr());
        $this->assertEquals('Hey.', $m->getTextbody());
        $this->assertEquals('from@test.com', $m->getEnvelopefrom());
        $this->assertEquals('to@test.com', $m->getEnvelopeto());
        $this->assertStringContainsString("<BODY>Hey.</BODY>", $m->getHtmlbody());
        $this->assertEquals(0, count($m->getParsedAttachments()));
        $this->assertEquals(Message::TYPE_OTHER, $m->getType());
        $this->assertEquals('FDv2', $m->getSourceheader());

        # Save it
        list ($id, $failok) = $m->save();
        $this->assertNotNull($id);

        # Read it back
        unset($m);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals('Basic test', $m->getSubject());
        $this->assertEquals('Basic test', $m->getHeader('subject'));
        $this->assertEquals($t, $m->getFromname());
        $this->assertEquals('test@test.com', $m->getFromaddr());
        $this->assertEquals('Hey.', $m->getTextbody());
        $this->assertEquals('from@test.com', $m->getEnvelopefrom());
        $this->assertEquals('to@test.com', $m->getEnvelopeto());
        $m->delete();

        }

    public function testAttachment() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->assertEquals('MessageMaker', $m->getSourceheader());

        # Check the parsed attachments
        $atts = $m->getParsedAttachments();
        $this->assertEquals(2, count($atts));
        $this->assertEquals('g4g220x194.png', $atts[0]->getFilename());
        $this->assertEquals('image/png', $atts[0]->getContentType());
        $this->assertEquals('g4g160.png', $atts[1]->getFilename());
        $this->assertEquals('image/png', $atts[1]->getContentType());

        # Save it
        list ($id, $failok) = $m->save();
        $this->assertNotNull($id);
        $m->saveAttachments($id);

        # Check the saved attachment.  Only one - other stripped for aspect ratio.
        $atts = $m->getAttachments();
        $this->assertEquals(1, count($atts));

        # Check the returned attachment.  Only one - other stripped for aspect ratio.
        $atts = $m->getPublic();
        $this->assertEquals(1, count($atts['attachments']));

        $m->delete();
    }

    public function testAttachmentDup() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachmentdup');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);

        list ($id, $failok) = $m->save();
        $this->assertNotNull($id);
        $m->saveAttachments($id);

        # Check the returned attachment.  Only one - other stripped for aspect ratio.
        $atts = $m->getPublic();
        $this->assertEquals(1, count($atts['attachments']));

        $m->delete();
    }

    public function testEmbedded() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/inlinephoto');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);

        # Check the parsed inline images.  Should only show one, as dupicate.
        $imgs = $m->getInlineimgs();
        $this->assertEquals(1, count($imgs));

        # Save it and check they show up as attachments
        list ($id, $failok) = $m->save();
        $m->saveAttachments($id);
        $a = new Attachment($this->dbhr, $this->dbhm);
        $atts = $a->getById($id);
        $this->assertEquals(1, count($atts));

        $m->delete();

        # Test invalid embedded image
        $msg = str_replace("https://www.google.co.uk/images/branding/googlelogo/2x/googlelogo_color_272x92dp.png", "http://google.com", $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);

        # Check the parsed inline images - should be none
        $imgs = $m->getInlineimgs();
        $this->assertEquals(0, count($imgs));

        # Save it and check they don't show up as attachments
        list ($id, $failok) = $m->save();
        $a = new Attachment($this->dbhr, $this->dbhm);
        $atts = $a->getById($id);        
        $this->assertEquals(0, count($atts));

        }

    public function testTN() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tn'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->assertEquals('20065945', $m->getTnpostid());

        # Save it
        list ($id, $failok) = $m->save();
        $this->assertNotNull($id);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(55.957570, $m->getPrivate('lat'));
        $this->assertEquals(-3.205330, $m->getPrivate('lng'));

        # The user we have created should have tnuserid set.
        $uid = $m->getFromUser();
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $this->assertEquals(2079027, $u->getPrivate('tnuserid'));

        $m->delete();
    }

    public function testType() {
        $this->assertEquals(Message::TYPE_OFFER, Message::determineType('OFFER: item (location)'));
        $this->assertEquals(Message::TYPE_WANTED, Message::determineType('[Group]WANTED: item'));

        }
}

