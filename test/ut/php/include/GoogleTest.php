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
class GoogleTest extends IznikTestCase {
    private $dbhr, $dbhm;
    public $people;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function client() {
        return($this);
    }

    public function get() {
        $me = new \Google_Service_Plus_Person();
        $me->setId($this->googleId);
        $name = new \Google_Service_Plus_PersonName();
        $name->setFormatted($this->googleName);
        $name->setFamilyName($this->googleLastName);
        $name->setGivenName($this->googleFirstName);
        $me->setName($name);
        $email = new \Google_Service_Plus_PersonEmails();
        $email->setType('account');
        $email->setValue($this->googleEmail);
        $me->setEmails([$email]);
        return($me);
    }
    
    public function authenticate() {
    }

    public function getAccessToken() {
        return([
            'access_token' => $this->accessToken
        ]);
    }
    
    public function testBasic() {
        $g = new Google($this->dbhr, $this->dbhm, TRUE);
        list($session, $ret) = $g->login(1);
        $this->assertEquals(2, $ret['ret']);

        # Basic successful login
        $mock = $this->getMockBuilder('Booktastic\Iznik\Google')
            ->setConstructorArgs([$this->dbhr, $this->dbhm, FALSE])
            ->setMethods(array('getClient', 'getUserDetails'))
            ->getMock();

        $mock->method('getClient')->willReturn($this);
        $mock->method('getUserDetails')->willReturn($this);
        $this->people = $this;

        $this->accessToken = json_encode([ 'access_token' => '1234' ]);
        $this->id = 1;
        $this->given_name = 'Test';
        $this->family_name = 'User';
        $this->name = 'Test User';
        $this->email = 'test@test.com';

        list($session, $ret) = $mock->login(1);
        $this->log("Returned " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $logins = $me->getLogins();
        $this->log("Logins " . var_export($logins, TRUE));
        $this->assertEquals(1, $logins[0]['uid']);

        # Log in again with a different email, triggering a merge.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, "Test User2");
        $u->addEmail('test2@test.com');

        $this->email = 'test2@test.com';
        list($session, $ret) = $mock->login(1);
        $this->assertEquals(0, $ret['ret']);
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $emails = $me->getEmails();
        $this->log("Emails " . var_export($emails, TRUE));
        $this->assertEquals(2, count($emails));

        # Now delete an email, and log in again - should trigger an add of the email
        $me->removeEmail('test2@test.com');
        list($session, $ret) = $mock->login(1);
        $this->assertEquals(0, $ret['ret']);
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $emails = $me->getEmails();
        $this->log("Emails " . var_export($emails, TRUE));
        $this->assertEquals(2, count($emails));

        # Now delete the google login, and log in again - should trigger an add of the google id.
        $this->assertEquals(1, $me->removeLogin('Google', 1));
        list($session, $ret) = $mock->login(1);
        $this->assertEquals(0, $ret['ret']);
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $emails = $me->getEmails();
        $this->log("Emails " . var_export($emails, TRUE));
        $this->assertEquals(2, count($emails));
        $logins = $me->getLogins();
        $this->log("Logins " . var_export($logins, TRUE));
        $this->assertEquals(1, count($logins));
        $this->assertEquals(1, $logins[0]['uid']);

        }
}

