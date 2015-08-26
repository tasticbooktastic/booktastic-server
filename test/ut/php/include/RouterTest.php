<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTest.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class RouterTest extends IznikTest {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    protected function tearDown() {
        parent::tearDown ();
    }

    public function __construct() {
    }

    public function testSpam() {
        error_log(__METHOD__);

        $msg = file_get_contents('msgs/spam');
        $m = new IncomingMessage($this->dbhr, $this->dbhm);
        $m->parse(IncomingMessage::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $id = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $r->route();

        error_log(__METHOD__ . " end");
    }

    public function testHam() {
        error_log(__METHOD__);

        $msg = file_get_contents('msgs/basic');
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->received(IncomingMessage::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_GROUP, $rc);

        error_log(__METHOD__ . " end");
    }

    public function testSpamIP() {
        error_log(__METHOD__);

        # Sorry, Cameroon folk.
        $msg = file_get_contents('msgs/cameroon');

        $m = new IncomingMessage($this->dbhr, $this->dbhm);
        $m->parse(IncomingMessage::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $id = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        # This should have stored the IP in the message.
        error_log("Message ID $id");
        $m = new IncomingMessage($this->dbhm, $this->dbhm, $id);
        assertEquals('41.205.16.153', $m->getFromIP());

        error_log(__METHOD__ . " end");
    }

    public function testFailSpam() {
        error_log(__METHOD__);

        $msg = file_get_contents('msgs/spam');
        $r = new MailRouter($this->dbhr, $this->dbhm);

        # Make the attempt to move the message fail.
        $mock = $this->getMockBuilder('IncomingMessage')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('delete'))
            ->getMock();
        $mock->method('delete')->willReturn(false);
        $r->setMsg($mock);

        $r->received(IncomingMessage::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::FAILURE, $rc);

        # Make the spam check itself fail
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $mock = $this->getMockBuilder('spamc')
            ->disableOriginalConstructor()
            ->setMethods(array('filter'))
            ->getMock();
        $mock->method('filter')->willReturn(false);
        $r->setSpam($mock);

        $r->received(IncomingMessage::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::FAILURE, $rc);

        error_log(__METHOD__ . " end");
    }

    public function testFailHam() {
        error_log(__METHOD__);

        $msg = file_get_contents('msgs/basic');
        $r = new MailRouter($this->dbhr, $this->dbhm);

        # Make the attempt to move the message fail.
        $mock = $this->getMockBuilder('IncomingMessage')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('delete'))
            ->getMock();
        $mock->method('delete')->willReturn(false);
        $r->setMsg($mock);

        $r->received(IncomingMessage::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::FAILURE, $rc);

        # Make the spam check itself fail
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $mock = $this->getMockBuilder('spamc')
            ->disableOriginalConstructor()
            ->setMethods(array('filter'))
            ->getMock();
        $mock->method('filter')->willReturn(false);
        $r->setSpam($mock);

        $r->received(IncomingMessage::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::FAILURE, $rc);

        error_log(__METHOD__ . " end");
    }
}

