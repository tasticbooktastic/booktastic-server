<?php
namespace Booktastic\Iznik;


require_once(IZNIK_BASE . '/mailtemplates/digest/newsletter.php');
require_once(IZNIK_BASE . '/mailtemplates/digest/newsletterarticle.php');
require_once(IZNIK_BASE . '/mailtemplates/digest/newslettersoff.php');

class Newsletter extends Entity
{
    var $log, $newsletter;

    const TYPE_HEADER = 'Header';
    const TYPE_ARTICLE = 'Article';

    public $publicatts = [ 'id', 'groupid', 'subject', 'textbody', 'created', 'completed', 'uptouser' ];
    public $settableatts = [ 'groupid', 'subject', 'textbody' ];

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'newsletters', 'newsletter', $this->publicatts);
        $this->log = new Log($this->dbhr, $this->dbhm);
    }

    public function create($groupid, $subject, $textbody) {
        $id = NULL;

        $rc = $this->dbhm->preExec("INSERT INTO newsletters (`groupid`, `subject`, `textbody`) VALUES (?,?,?);", [
            $groupid, $subject, $textbody
        ]);

        if ($rc) {
            $id = $this->dbhm->lastInsertId();
            $this->fetch($this->dbhm, $this->dbhm, $id, 'newsletters', 'newsletter', $this->publicatts);
        }

        return($id);
    }

    public function addArticle($type, $position, $html, $photo) {
        $id = NULL;
        $rc = $this->dbhm->preExec("INSERT INTO newsletters_articles (newsletterid, type, position, html, photoid) VALUES (?,?,?,?,?);", [
            $this->id,
            $type,
            $position,
            $html,
            $photo
        ]);

        if ($rc) {
            $id = $this->dbhm->lastInsertId();
        }

        return($id);
    }

    # Split out for UT to override
    public function sendOne($mailer, $message) {
        $mailer->send($message);
    }

    public function off($uid) {
        $u = User::get($this->dbhr, $this->dbhm, $uid);

        if ($u->getId() == $uid) {
            if ($u->getPrivate('newslettersallowed')) {
                $u->setPrivate('newslettersallowed', 0);

                $this->log->log([
                    'type' => Log::TYPE_USER,
                    'subtype' => Log::SUBTYPE_NEWSLETTERSOFF,
                    'user' => $uid
                ]);

                $email = $u->getEmailPreferred();
                if ($email) {
                    list ($transport, $mailer) = Mail::getMailer();
                    $html = newsletters_off(USER_SITE, USERLOGO);

                    $message = \Swift_Message::newInstance()
                        ->setSubject("Email Change Confirmation")
                        ->setFrom([NOREPLY_ADDR => SITE_NAME])
                        ->setReturnPath($u->getBounce())
                        ->setTo([$email => $u->getName()])
                        ->setBody("We've turned your newsletters off.");

                    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                    # Outlook.
                    $htmlPart = \Swift_MimePart::newInstance();
                    $htmlPart->setCharset('utf-8');
                    $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                    $htmlPart->setContentType('text/html');
                    $htmlPart->setBody($html);
                    $message->attach($htmlPart);

                    Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::NEWSLETTER_OFF, $u->getId());

                    $this->sendOne($mailer, $message);
                }
            }
        }
    }

    public function send($groupid, $uid = NULL, $grouptype = Group::GROUP_FREEGLE, $html = NULL) {
        # This might be to a specific group or all groups, so the mail we construct varies a bit based on that.
        $g = NULL;
        $gatts = NULL;
        if ($groupid) {
            $g = Group::get($this->dbhr, $this->dbhm, $groupid);
            $gatts = $g->getPublic();
        }

        $sent = 0;

        # The HTML for the newsletter might already be supplied in full (e.g. for stories).
        if (!$html) {
            # Construct the HTML from the articles.
            $html = '';

            $articles = $this->dbhr->preQuery("SELECT * FROM newsletters_articles WHERE newsletterid = ? ORDER BY position ASC;", [ $this->id ]);

            if (count($articles) > 0) {
                foreach ($articles as &$article) {
                    $photo = Utils::pres('photoid', $article);

                    if ($photo) {
                        $a = new Attachment($this->dbhr, $this->dbhm, $photo, Attachment::TYPE_NEWSLETTER);
                        $article['photo'] = $a->getPublic();

                        $article['photo']['path'] .= '?w=' . $article['width'];
                    }

                    $html .= newsletter_article($article);
                }

                $html = newsletter(USER_SITE, SITE_NAME, $html);
            }
        }

        if ($html) {
            $tosend = [
                'subject' => $this->newsletter['subject'],
                'from' => $g ? $g->getAutoEmail() : NOREPLY_ADDR,
                'replyto' => $g ? $g->getModsEmail() : NOREPLY_ADDR,
                'fromname' => $g ? $gatts['namedisplay'] : SITE_NAME,
                'html' => $html,
                'text' => $this->newsletter['textbody']
            ];

            # Now find the users that we want to send to:
            # - an override to a single user
            # - users on a group
            # - all users on a group type where the group hasn't disabled newsletters
            $startfrom = Utils::presdef('uptouser', $this->newsletter, 0);
            $sql = $uid ? "SELECT DISTINCT userid FROM memberships WHERE userid = $uid;" : ($groupid ? "SELECT DISTINCT userid FROM memberships INNER JOIN users ON users.id = memberships.userid WHERE groupid = $groupid AND newslettersallowed = 1 AND userid > $startfrom ORDER BY userid ASC;" : "SELECT DISTINCT userid FROM users INNER JOIN memberships ON memberships.userid = users.id INNER JOIN `groups` ON groups.id = memberships.groupid AND type = '$grouptype' WHERE LOCATE('\"newsletter\":0', groups.settings) = 0 AND newslettersallowed = 1 AND users.id AND groups.publish = 1 > $startfrom ORDER BY users.id ASC;");
            $replacements = [];

            error_log("Query for users");
            $users = $this->dbhr->preQuery($sql);
            error_log("Queried, now scan " . count($users));
            $scan = 0;

            foreach ($users as $user) {
                $u = User::get($this->dbhr, $this->dbhm, $user['userid']);

                if (!$u->getPrivate('bouncing') && $u->sendOurMails()) {
                    # We are only interested in sending events to users for whom we have a preferred address -
                    # otherwise where would we send them?
                    $email = $u->getEmailPreferred();

                    if ($email) {
                        $replacements[$email] = [
                            '{{id}}' => $user['userid'],
                            '{{toname}}' => $u->getName(),
                            '{{unsubscribe}}' => $u->loginLink(USER_SITE, $u->getId(), '/unsubscribe', User::SRC_NEWSLETTER),
                            '{{email}}' => $email,
                            '{{settings}}' => $u->loginLink(USER_SITE, $u->getId(), '/settings', User::SRC_NEWSLETTER),
                            '{{noemail}}' => 'newslettersoff-' . $user['userid'] . "@" . USER_DOMAIN
                        ];
                    }
                }

                if ($scan % 1000 === 0) {
                    $pc = round(100 * $scan / count($users));
                    error_log("...$scan ($pc%)");
                }

                $scan++;
            }

            if (count($replacements) > 0) {
                # Now send.  We use a failover transport so that if we fail to send, we'll queue it for later
                # rather than lose it.
                /* @var Swift_MailTransport $transport
                 * @var Swift_Mailer $mailer */
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
                    $bounce = "bounce-{$rep['{{id}}']}-" . time() . "@" . USER_DOMAIN;
                    $message = \Swift_Message::newInstance()
                        ->setSubject($tosend['subject'] . ' ' . User::encodeId($rep['{{id}}']))
                        ->setFrom([$tosend['from'] => $tosend['fromname']])
                        ->setReplyTo($tosend['replyto'], $tosend['fromname'])
                        ->setReturnPath($bounce)
                        ->setBody($tosend['text']);

                    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                    # Outlook.
                    $htmlPart = \Swift_MimePart::newInstance();
                    $htmlPart->setCharset('utf-8');
                    $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                    $htmlPart->setContentType('text/html');
                    $htmlPart->setBody($tosend['html']);
                    $message->attach($htmlPart);

                    $headers = $message->getHeaders();
                    $headers->addTextHeader('X-Iznik-Newsletter', $this->id);

                    Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::NEWSLETTER, $rep['{{id}}']);

                    try {
                        $message->addTo($email);
                        $this->sendOne($mailer, $message);

                        if ($sent % 1000 === 0) {
                            $pc = round(100 * $sent / count($replacements));
                            error_log("...$sent ($pc%)");

                            if (!$uid) {
                                # Save where we're upto so that if we crash or restart we don't duplicate for too many
                                # users.
                                $this->dbhm->preExec("UPDATE newsletters SET uptouser = ? WHERE id = ?;", [
                                    $rep['{{id}}'],
                                    $this->id
                                ]);
                            }
                        }

                        $sent++;

                        if ($sent % 7 === 0) {
                            # This is set so that sending a newsletter takes several days, to avoid disrupting our
                            # normal mailing by flooding the system with these mails.
                            sleep(2);
                        }
                    } catch (\Exception $e) {
                        error_log($email . " skipped with " . $e->getMessage());
                    }
                }
            }

            if (!$uid) {
                $this->dbhm->preExec("UPDATE newsletters SET completed = NOW() WHERE id = ?;", [ $this->id ]);
            }
        }

        return($sent);
    }
}