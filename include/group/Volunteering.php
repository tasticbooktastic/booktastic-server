<?php
namespace Booktastic\Iznik;


require_once(IZNIK_BASE . '/mailtemplates/volunteerrenew.php');

class Volunteering extends Entity
{
    /** @var  $dbhm LoggedPDO */
    public $publicatts = [ 'id', 'userid', 'pending', 'title', 'location', 'online', 'contactname', 'contactphone', 'contactemail', 'contacturl', 'description', 'added', 'askedtorenew', 'renewed', 'timecommitment', 'expired', 'heldby', 'externalid' ];
    public $settableatts = [ 'userid', 'pending', 'title', 'location', 'online', 'contactname', 'contactphone', 'contactemail', 'contacturl', 'description', 'added', 'renewed', 'timecommitment', 'externalid' ];
    var $volunteering;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $fetched = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'volunteering', 'volunteering', $this->publicatts, $fetched);
    }

    public function create($userid, $title, $online, $location, $contactname, $contactphone, $contactemail, $contacturl, $description, $timecommitment, $externalid = NULL) {
        $id = NULL;

        $rc = $this->dbhm->preExec("INSERT INTO volunteering (`userid`, `pending`, `title`, `online`, `location`, `contactname`, `contactphone`, `contactemail`, `contacturl`, `description`, `timecommitment`, `externalid`) VALUES (?,1,?,?,?,?,?,?,?,?,?,?);", [
            $userid, $title, is_null($online) ? FALSE : $online, $location, $contactname, $contactphone, $contactemail, $contacturl, $description, $timecommitment, $externalid
        ]);

        if ($rc) {
            $id = $this->dbhm->lastInsertId();
            $this->fetch($this->dbhm, $this->dbhm, $id, 'volunteering', 'volunteering', $this->publicatts);
        }

        return($id);
    }

    public function addDate($start, $end, $applyby) {
        $this->dbhm->preExec("INSERT INTO volunteering_dates (volunteeringid, start, end, applyby) VALUES (?, ?, ?, ?);" , [
            $this->id,
            $start,
            $end,
            $applyby
        ]);
        return($this->dbhm->lastInsertId());
    }

    public function removeDate($id) {
        $this->dbhm->preExec("DELETE FROM volunteering_dates WHERE id = ?;" , [
            $id
        ]);
    }

    public function addGroup($groupid) {
        # IGNORE as we have a unique key on volunteering/group.
        $this->dbhm->preExec("INSERT IGNORE INTO volunteering_groups (volunteeringid, groupid) VALUES (?, ?);" , [
            $this->id,
            $groupid
        ]);

        # Create now so that we can pass the groupid.
        $n = new Newsfeed($this->dbhr, $this->dbhm);
        $fid = $n->create(Newsfeed::TYPE_VOLUNTEER_OPPORTUNITY, $this->volunteering['userid'], NULL, NULL, NULL, NULL, $groupid, NULL, $this->id, NULL);

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $n->notifyGroupMods($groupid);
    }

    public function removeGroup($id) {
        $this->dbhm->preExec("DELETE FROM volunteering_groups WHERE volunteeringid = ? AND groupid = ?;" , [
            $this->id,
            $id
        ]);
    }

    public function listForUser($userid, $pending, $systemwide, &$ctx) {
        $ret = [];
        $pendingq = $pending ? " AND pending = 1 " : " AND pending = 0 ";
        $roleq = $pending ? " AND role IN ('Owner', 'Moderator') " : '';
        $ctxq = $ctx ? (" AND volunteering.id < '" . intval($ctx['id']) . "' ") : '';

        $mysqltime = date("Y-m-d H:i:s", time());

        # Get the ones for our group.
        $sql = "SELECT volunteering.*, volunteering_dates.end, volunteering_groups.groupid FROM volunteering INNER JOIN volunteering_groups ON volunteering_groups.volunteeringid = volunteering.id AND groupid IN (SELECT groupid FROM memberships WHERE userid = ? $roleq) LEFT JOIN volunteering_dates ON volunteering_dates.volunteeringid = volunteering.id WHERE (applyby IS NULL OR applyby >= ?) AND (end IS NULL OR end >= ?) AND deleted = 0 AND expired = 0 $pendingq $ctxq ORDER BY id DESC LIMIT 20;";
        $volunteerings = $this->dbhr->preQuery($sql, [
            $userid,
            $mysqltime,
            $mysqltime
        ]);

        if ($systemwide) {
            # Get the national ones, for display or approval.
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            if (!$pending || ($me && $me->hasPermission(User::PERM_NATIONAL_VOLUNTEERS))) {
                $sql = "SELECT NULL AS groupid, volunteering.*, volunteering_dates.end, volunteering_dates.applyby FROM volunteering LEFT JOIN volunteering_groups ON volunteering_groups.volunteeringid = volunteering.id AND deleted = 0 AND expired = 0 LEFT JOIN volunteering_dates ON volunteering_dates.volunteeringid = volunteering.id WHERE groupid IS NULL AND deleted = 0 AND expired = 0 $pendingq $ctxq ORDER BY id DESC LIMIT 20;";
                $volunteerings = array_merge($volunteerings, $this->dbhr->preQuery($sql));

                # Sort, as we have added national ones at the end.
                usort($volunteerings, function($a, $b) {
                    return($b['id'] - $a['id']);
                });
            }
        }

        $u = User::get($this->dbhr, $this->dbhm, $userid);

        foreach ($volunteerings as $volunteering) {
            if ((!$volunteering['pending'] || is_null($volunteering['groupid']) || $u->activeModForGroup($volunteering['groupid'])) &&
                (!Utils::pres('applyby', $volunteering) || time() < strtotime($volunteering['applyby'])) &&
                (!Utils::pres('end', $volunteering) || time() < strtotime($volunteering['end']))
            ) {
                $ctx['id'] = $volunteering['id'];
                $v = new Volunteering($this->dbhr, $this->dbhm, $volunteering['id'], $volunteering);
                $atts = $v->getPublic();
                $atts['canmodify'] = $v->canModify($userid);

                $ret[] = $atts;
            }
        }

        return($ret);
    }

    public function listForGroup($pending, $groupid = NULL, &$ctx) {
        $ret = [];
        $myid = Session::whoAmId($this->dbhr, $this->dbhm);

        # We can only see pending volunteerings if we're an owner/mod.
        # We might be called for a specific groupid; if not then use logged in user's groups.
        $pendingq = $pending ? " pending = 1 " : " pending = 0 ";
        $roleq = $pending ? (" AND groupid IN (SELECT groupid FROM memberships WHERE userid = " . intval($myid) . " AND role IN ('Owner', 'Moderator')) ") : '';
        $groupq = $groupid ? (" AND groupid = " . intval($groupid)) : (" AND groupid IN (SELECT groupid FROM memberships WHERE userid = " . intval($myid) . ") ");
        $ctxq = $ctx ? (" AND volunteering.id < '" . intval($ctx['id']) . "' ") : '';

        $sql = "SELECT volunteering.*, volunteering_dates.applyby, volunteering_dates.end FROM volunteering INNER JOIN volunteering_groups ON volunteering_groups.volunteeringid = volunteering.id $groupq $roleq AND deleted = 0 AND expired = 0 LEFT JOIN volunteering_dates ON volunteering_dates.volunteeringid = volunteering.id WHERE $pendingq $ctxq ORDER BY id DESC LIMIT 20;";
        $volunteerings = $this->dbhr->preQuery($sql);

        $myid = Session::whoAmId($this->dbhr, $this->dbhm);

        foreach ($volunteerings as $volunteering) {
            if ((!Utils::pres('applyby', $volunteering) || time() < strtotime($volunteering['applyby'])) &&
                (!Utils::pres('end', $volunteering) || time() < strtotime($volunteering['end']))
            ) {
                $ctx['id'] = $volunteering['id'];
                $e = new Volunteering($this->dbhr, $this->dbhm, $volunteering['id'], $volunteering);
                $atts = $e->getPublic();

                $atts['canmodify'] = $e->canModify($myid);

                $ret[] = $atts;
            }
        }

        return($ret);
    }

    public function getPublic() {
        $atts = parent::getPublic();
        $atts['groups'] = [];

        $atts['dates'] = $this->dbhr->preQuery("SELECT * FROM volunteering_dates WHERE volunteeringid = ? ORDER BY end ASC", [ $this->id ]);

        foreach ($atts['dates'] as &$date) {
            $date['start'] = Utils::ISODate($date['start']);
            $date['end'] = Utils::ISODate($date['end']);
        }

        if ($atts['userid']) {
            $u = User::get($this->dbhr, $this->dbhm, $atts['userid']);
            $atts['user'] = $u->getPublic(NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE);
        }

        $groups = $this->dbhr->preQuery("SELECT * FROM volunteering_groups WHERE volunteeringid = ?", [ $this->id ]);

        foreach ($groups as $group) {
            $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);
            $atts['groups'][] = $g->getPublic(TRUE);

            if (Session::modtools() && $atts['userid']) {
                $atts['groups'][count($atts['groups']) - 1]['ourPostingStatus'] = $u->getMembershipAtt($group['groupid'], 'ourPostingStatus');
            }
        }

        unset($atts['userid']);

        if ($atts['heldby']) {
            $u = User::get($this->dbhr, $this->dbhm, $atts['heldby']);
            $atts['heldby'] = $u->getPublic(NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE);
        }

        $atts['renewed'] = Utils::pres('renewed', $atts) ? Utils::ISODate($atts['renewed']) : NULL;

        $photos = $this->dbhr->preQuery("SELECT id FROM volunteering_images WHERE opportunityid = ?;", [ $this->id ]);
        foreach ($photos as $photo) {
            $a = new Attachment($this->dbhr, $this->dbhm, $photo['id'], Attachment::TYPE_VOLUNTEERING);

            $atts['photo'] = [
                'id' => $photo['id'],
                'path' => $a->getPath(FALSE),
                'paththumb' => $a->getPath(TRUE)
            ];
        }

        # Ensure leading 0 not stripped.
        $atts['contactphone'] = Utils::pres('contactphone', $atts) ? "{$atts['contactphone']} " : NULL;
        $atts['url'] = 'https://' . USER_SITE . '/volunteering/' . $atts['id'];

        if (strlen($atts['contacturl']) && strpos($atts['contacturl'], 'http') === FALSE) {
            $atts['contacturl'] = 'https://' . $atts['contacturl'];
        }

        return($atts);
    }

    public function setPhoto($photoid) {
        $this->dbhm->preExec("UPDATE volunteering_images SET opportunityid = ? WHERE id = ?;", [ $this->id, $photoid ]);
    }

    public function canModify($userid) {
        # We can modify volunteerings which we created, or where we are a mod on any of the groups on which this volunteering
        # appears, or if we're support/admin.
        #error_log("Check user {$this->volunteering['userid']}, $userid");
        $canmodify = FALSE;

        if ($userid) {
            $u = User::get($this->dbhr, $this->dbhm, $userid);
            $canmodify = Utils::presdef('userid', $this->volunteering, NULL) == $userid || ($u && $u->isAdminOrSupport());

            #error_log("Can mod? $canmodify");
            if (!$canmodify) {
                $groups = $this->dbhr->preQuery("SELECT * FROM volunteering_groups WHERE volunteeringid = ?;", [ $this->id ]);
                #error_log("SELECT * FROM volunteering_groups WHERE volunteeringid = {$this->id};");
                foreach ($groups as $group) {
                    #error_log("Check for group {$group['groupid']} " . $u->isAdminOrSupport() . ", " . $u->isModOrOwner($group['groupid']));
                    if ($u->isAdminOrSupport() || $u->isModOrOwner($group['groupid'])) {
                        #error_log("Can");
                        $canmodify = TRUE;
                    }
                }
            }
        }

        return($canmodify);
    }

    public function expire($id = NULL) {
        $idq = $id ? " AND volunteering.id = $id " : '';

        # If an opportunity has any dates, then check that at least one is in the future, otherwise it's expired.
        $ids = $this->dbhr->preQuery("SELECT DISTINCT volunteeringid FROM volunteering INNER JOIN volunteering_dates ON volunteering.id = volunteering_dates.volunteeringid WHERE end <= NOW() AND expired = 0 $idq;");

        foreach ($ids as $id) {
            $futures = $this->dbhr->preQuery("SELECT volunteeringid FROM volunteering_dates WHERE volunteeringid = ? AND end > NOW();", [
                $id['volunteeringid']
            ]);

            if (count($futures) === 0) {
                $this->dbhm->preExec("UPDATE volunteering SET expired = 1 WHERE id = ?;", [
                    $id['volunteeringid']
                ]);
            }
        }

        # If an opportunity has no dates, and it is older than 31 days, then check that it has been renewed within the
        # last 31 days.  We send out renewal reminders 7 days beforehand.
        $mysqltime = date("Y-m-d H:i:s", strtotime("Midnight 31 days ago"));
        $ids = $this->dbhr->preQuery("SELECT DISTINCT volunteering.id FROM volunteering LEFT JOIN volunteering_dates ON volunteering.id = volunteering_dates.volunteeringid WHERE `end` IS NULL AND expired = 0 AND added < ? AND (renewed IS NULL OR renewed < ?) $idq;", [
            $mysqltime,
            $mysqltime
        ]);

        foreach ($ids as $id) {
            $this->dbhm->preExec("UPDATE volunteering SET expired = 1 WHERE id = ?;", [
                $id['id']
            ]);
        }
    }

    public function delete() {
        $myid = Session::whoAmId($this->dbhr, $this->dbhm);
        $this->dbhm->preExec("UPDATE volunteering SET deleted = 1, deletedby = ? WHERE id = ?;", [ $myid, $this->id ]);
    }

    # Split out for UT to override
    public function sendMail($mailer, $message) {
        $mailer->send($message);
    }

    public function askRenew($id = NULL) {
        $count = 0;

        # For opportunities with no dates, we want to mail people to ask them if they are still active.
        $idq = $id ? " AND volunteering.id = $id " : '';

        $mysqltime = date("Y-m-d H:i:s", strtotime("Midnight 24 days ago"));
        $sql = "SELECT DISTINCT volunteering.id FROM volunteering LEFT JOIN volunteering_dates ON volunteering.id = volunteering_dates.volunteeringid WHERE `end` IS NULL AND deleted = 0 AND expired = 0 AND added < ? AND (renewed IS NULL OR renewed < ?) $idq;";
        $ids = $this->dbhr->preQuery($sql, [
            $mysqltime,
            $mysqltime
        ]);

        list ($transport, $mailer) = Mail::getMailer();

        foreach ($ids as $id) {
            $v = new Volunteering($this->dbhr, $this->dbhm, $id['id']);
            $u = new User($this->dbhr, $this->dbhm, $v->getPrivate('userid'));
            $atts = $v->getPublic();
            $groupname = SITE_NAME;

            foreach ($atts['groups'] as $group) {
                $groupname = $group['namedisplay'];
            }

            if ($u->getId()) {
                # The user is still around.
                $url = $u->loginLink(USER_SITE, $u->getId(), '/volunteering/' . $id['id'], User::SRC_VOLUNTEERING_DIGEST);
                $html = volunteering_renew(USER_SITE, USERLOGO, $v->getPrivate('title'), $url, $groupname);

                $message = \Swift_Message::newInstance()
                    ->setSubject("Regarding: " . $v->getPrivate('title'))
                    ->setFrom([NOREPLY_ADDR => SITE_NAME])
                    ->setReturnPath($u->getBounce())
                    ->setTo([ $u->getEmailPreferred() => $u->getName() ])
                    ->setBody("Please can you let us know whether this volunteer opportunity is still active?  If we don't hear from you, we'll stop showing it soon.  Please let us know at $url");

                # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                # Outlook.
                $htmlPart = \Swift_MimePart::newInstance();
                $htmlPart->setCharset('utf-8');
                $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                $htmlPart->setContentType('text/html');
                $htmlPart->setBody($html);
                $message->attach($htmlPart);

                Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::VOLUNTEERING, $u->getId());

                $this->sendMail($mailer, $message);
                error_log($v->getId() . " " . $v->getPrivate('title'));
                $count++;
            }
        }

        return($count);
    }
    
    public function systemWideCount() {
        $sqltime = date("Y-m-d H:i:s", time());

        $count = $this->dbhr->preQuery("SELECT COUNT(DISTINCT volunteering.id) AS count FROM volunteering LEFT JOIN volunteering_dates ON volunteering_dates.volunteeringid = volunteering.id LEFT JOIN volunteering_groups ON volunteering.id = volunteering_groups.volunteeringid WHERE volunteering_groups.groupid IS NULL AND volunteering.pending = 1 AND volunteering.deleted = 0 AND volunteering.expired = 0 AND (applyby IS NULL OR applyby >= ?) AND (end IS NULL OR end >= ?);", [
            $sqltime,
            $sqltime
        ])[0]['count'];

        return($count);
    }
}

