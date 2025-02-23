<?php
namespace Booktastic\Iznik;


require_once(IZNIK_BASE . '/mailtemplates/digest/volunteeringoff.php');

class VolunteeringDigest
{
    /** @var  $dbhr LoggedPDO */
    private $dbhr;
    /** @var  $dbhm LoggedPDO */
    private $dbhm;

    private $errorlog;

    function __construct($dbhr, $dbhm, $errorlog = FALSE)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->log = new Log($this->dbhr, $this->dbhm);
        $this->errorlog = $errorlog;
    }

    # Split out for UT to override
    public function sendOne($mailer, $message) {
        $mailer->send($message);
    }

    public function off($uid, $groupid) {
        $u = User::get($this->dbhr, $this->dbhm, $uid);

        if ($u->getId() == $uid && $u->isApprovedMember($groupid)) {
            $u->setMembershipAtt($groupid, 'volunteeringallowed', 0);
            $g = Group::get($this->dbhr, $this->dbhm, $groupid);

            # We can receive messages for emails from the old system where the group id is no longer valid.
            if ($g->getId() == $groupid) {
                $groupname = $g->getPublic()['namedisplay'];

                $this->log->log([
                    'type' => Log::TYPE_USER,
                    'subtype' => Log::SUBTYPE_VOLUNTEERSOFF,
                    'user' => $uid,
                    'groupid' => $groupid
                ]);

                $email = $u->getEmailPreferred();
                if ($email) {
                    list ($transport, $mailer) = Mail::getMailer();
                    $html = volunteering_off(USER_SITE, USERLOGO, $groupname);

                    $message = \Swift_Message::newInstance()
                        ->setSubject("Email Change Confirmation")
                        ->setFrom([NOREPLY_ADDR => SITE_NAME])
                        ->setReturnPath($u->getBounce())
                        ->setTo([ $email => $u->getName() ])
                        ->setBody("We've turned your volunteering emails off on $groupname.");

                    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                    # Outlook.
                    $htmlPart = \Swift_MimePart::newInstance();
                    $htmlPart->setCharset('utf-8');
                    $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                    $htmlPart->setContentType('text/html');
                    $htmlPart->setBody($html);
                    $message->attach($htmlPart);

                    Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::VOLUNTEERING_OFF, $u->getId());

                    $this->sendOne($mailer, $message);
                }
            }
        }
    }

    public function send($groupid, $ccto = NULL) {
        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
        $twig = new \Twig_Environment($loader);

        $g = Group::get($this->dbhr, $this->dbhm, $groupid);
        $gatts = $g->getPublic();
        $sent = 0;

        if ($this->errorlog) { error_log("#$groupid " . $g->getPrivate('nameshort')); }

        # We want to send all outstanding volunteer opportunities for this group, or with no group.
        #
        # Don't test the groupid in the query, as we want to include ones with no group, and if we add an IS NULL
        # clause then we'll get all of them.
        $sql = "SELECT DISTINCT volunteering.id, volunteering_groups.groupid FROM volunteering LEFT JOIN volunteering_groups ON volunteering_groups.volunteeringid = volunteering.id WHERE pending = 0 AND deleted = 0 AND expired = 0 ORDER BY volunteering.id DESC;";
        #error_log("Look for groups to process $sql");
        $volunteerings = $this->dbhr->preQuery($sql);

        if ($this->errorlog) { error_log("Consider " . count($volunteerings) . " volunteerings"); }

        $textsumm = '';

        $tz1 = new \DateTimeZone('UTC');
        $tz2 = new \DateTimeZone('Europe/London');

        $twigvols = [];

        if (count($volunteerings) > 0) {
            $count = 0;

            foreach ($volunteerings as $volunteering) {
                if (!$volunteering['groupid'] || $volunteering['groupid'] ==  $groupid) {
                    $count++;
                    if ($this->errorlog) { error_log("Start group $groupid"); }

                    $e = new Volunteering($this->dbhr, $this->dbhm, $volunteering['id']);
                    $atts = $e->getPublic();
                    $textsumm .= $atts['title'] . " at " . $atts['location'] . " - for details see https://" . USER_SITE . "/volunteering/{$atts['id']}&src=voldigest\r\n\r\n";

                    $twigvols[] = $atts;
                }
            }

            #error_log("Found " . count($twigvols));

            if ($count) {
                $html = $twig->render('digest/volunteering.html', [
                    # Per-message fields for expansion now.
                    'volunteerings' => $twigvols,
                    'groupname' => $gatts['namedisplay'],

                    # Per-recipient fields for later Swift expansion
                    'settings' => '{{settings}}',
                    'unsubscribe' => '{{unsubscribe}}',
                    'email' => '{{email}}',
                    'noemail' => '{{noemail}}',
                    'visit' => '{{visit}}'
                ]);

                $tosend = [
                    'subject' => '[' . $gatts['namedisplay'] . "] Volunteer Opportunity Roundup",
                    'from' => $g->getAutoEmail(),
                    'fromname' => $gatts['namedisplay'],
                    'replyto' => $g->getModsEmail(),
                    'replytoname' => $gatts['namedisplay'],
                    'html' => $html,
                    'text' => $textsumm
                ];

                # Now find the users we want to send to on this group for this frequency.  We build up an array of
                # the substitutions we need.
                $replacements = [];

                $sql = "SELECT userid FROM memberships WHERE groupid = ? AND volunteeringallowed = 1 ORDER BY userid ASC;";
                $users = $this->dbhr->preQuery($sql, [ $groupid, ]);

                if ($this->errorlog) { error_log("Consider " . count($users) . " users "); }
                foreach ($users as $user) {
                    $u = User::get($this->dbhr, $this->dbhm, $user['userid']);
                    if ($this->errorlog) {
                        error_log("Consider user {$user['userid']}");
                    }

                    # We are only interested in sending opportunities to users for whom we have a preferred address -
                    # otherwise where would we send them?
                    $email = $u->getEmailPreferred();
                    #$email = 'activate@liveintent.com';
                    #$email = 'edward@ehibbert.org.uk';

                    $jobads = $u->getJobAds();

                    if ($this->errorlog) { error_log("Preferred $email, send " . $u->sendOurMails($g)); }

                    if ($email && $u->sendOurMails($g)) {
                        if ($this->errorlog) { error_log("Send to them"); }

                        $replacements[$email] = [
                            '{{uid}}' => $u->getId(),
                            '{{toname}}' => $u->getName(),
                            '{{settings}}' => $u->loginLink(USER_SITE, $u->getId(), '/settings', User::SRC_DIGEST),
                            '{{unsubscribe}}' => $u->loginLink(USER_SITE, $u->getId(), '/unsubscribe', User::SRC_VOLUNTEERING_DIGEST),
                            '{{email}}' => $email,
                            '{{noemail}}' => 'volunteeringoff-' . $user['userid'] . "-$groupid@" . USER_DOMAIN,
                            '{{post}}' => "https://" . USER_SITE . "/volunteering",
                            '{{visit}}' => "https://" . USER_SITE . "/browse",
                            '{{jobads}}' => $jobads['jobs'],
                            '{{joblocation}}' => $jobads['location']
                        ];
                    }
                }

                if (count($replacements) > 0) {
                    error_log("#$groupid {$gatts['nameshort']} to " . count($replacements) . " users");

                    # Now send.  We use a failover transport so that if we fail to send, we'll queue it for later
                    # rather than lose it.
                    list ($transport, $mailer) = Mail::getMailer();

                    # We're decorating using the information we collected earlier.  However the decorator doesn't
                    # cope with sending to multiple recipients properly (headers just get decorated with the first
                    # recipient) so we create a message for each recipient.
                    $decorator = new \Swift_Plugins_DecoratorPlugin($replacements);
                    $mailer->registerPlugin($decorator);

                    # We don't want to send too many mails before we reconnect.  This plugin breaks it up.
                    $mailer->registerPlugin(new \Swift_Plugins_AntiFloodPlugin(900));

                    $_SERVER['SERVER_NAME'] = USER_DOMAIN;

                    foreach ($replacements as $email => $rep) {
                        $message = \Swift_Message::newInstance()
                            ->setSubject($tosend['subject'])
                            ->setFrom([$tosend['from'] => $tosend['fromname']])
                            ->setReturnPath($u->getBounce())
                            ->setReplyTo($tosend['replyto'], $tosend['replytoname'])
                            ->setBody($tosend['text']);

                        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                        # Outlook.
                        $htmlPart = \Swift_MimePart::newInstance();
                        $htmlPart->setCharset('utf-8');
                        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                        $htmlPart->setContentType('text/html');
                        $htmlPart->setBody($tosend['html']);
                        $message->attach($htmlPart);

                        Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::VOLUNTEERING, $rep['{{uid}}']);

                        try {
                            $message->setTo([ $email => $rep['{{toname}}'] ]);
                            #error_log("...$email");
                            $this->sendOne($mailer, $message);
                            $sent++;
                        } catch (\Exception $e) {
                            error_log($email . " skipped with " . $e->getMessage());
                        }
                    }
                }
            }
        }

        $this->dbhm->preExec("UPDATE `groups` SET lastvolunteeringroundup = NOW() WHERE id = ?;", [ $groupid ]);
        Group::clearCache($groupid);

        return($sent);
    }
}