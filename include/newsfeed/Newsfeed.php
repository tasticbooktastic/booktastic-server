<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/misc/Log.php');
require_once(IZNIK_BASE . '/include/misc/Location.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/message/Message.php');
require_once(IZNIK_BASE . '/include/group/CommunityEvent.php');
require_once(IZNIK_BASE . '/include/group/Volunteering.php');
require_once(IZNIK_BASE . '/include/user/Notifications.php');
require_once(IZNIK_BASE . '/include/user/Story.php');
require_once(IZNIK_BASE . '/include/misc/Preview.php');
require_once(IZNIK_BASE . '/include/spam/Spam.php');
require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');
require_once(IZNIK_BASE . '/mailtemplates/newsfeed/digest.php');

class Newsfeed extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'timestamp', 'type', 'userid', 'imageid', 'msgid', 'replyto', 'groupid', 'eventid', 'storyid', 'volunteeringid', 'publicityid', 'message', 'position', 'deleted');

    /** @var  $log Log */
    private $log;
    var $feed;

    # See also in ChatMessage.
    private $urlPattern = '#(?i)\b(((?:(?:http|https):(?:/{1,3}|[a-z0-9%])|www\d{0,3}[.]|[a-z0-9.\-]+[.][a-z]{2,4}/)(?:[^\s()<>]+|\(([^\s()<>]+|(\([^\s()<>]+\)))*\))+(?:\(([^\s()<>]+|(\([^\s()<>]+\)))*\)|[^\s`!()\[\]{};:\'".,<>?«»“”‘’]))|(\.com\/))#m';

    const DISTANCE = 15000;

    const TYPE_MESSAGE = 'Message';
    const TYPE_COMMUNITY_EVENT = 'CommunityEvent';
    const TYPE_VOLUNTEER_OPPORTUNITY = 'VolunteerOpportunity';
    const TYPE_CENTRAL_PUBLICITY = 'CentralPublicity';
    const TYPE_ALERT = 'Alert';
    const TYPE_STORY = 'Story';
    const TYPE_REFER_TO_WANTED = 'ReferToWanted';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->log = new Log($dbhr, $dbhm);

        $this->fetch($dbhr, $dbhm, $id, 'newsfeed', 'feed', $this->publicatts);
    }

    public function create($type, $userid = NULL, $message = NULL, $imageid = NULL, $msgid = NULL, $replyto = NULL, $groupid = NULL, $eventid = NULL, $volunteeringid = NULL, $publicityid = NULL, $storyid = NULL) {
        $id = NULL;

        $s = new Spam($this->dbhr, $this->dbhm);
        $hidden = $s->checkReferToSpammer($message) ? 'NOW()' : 'NULL';

        $u = User::get($this->dbhr, $this->dbhm, $userid);
        list($lat, $lng) = $userid ? $u->getLatLng() : [ NULL, NULL ];
#        error_log("Create at $lat, $lng");

        if ($lat || $lng || $type == Newsfeed::TYPE_CENTRAL_PUBLICITY || $type == Newsfeed::TYPE_ALERT || $type == Newsfeed::TYPE_REFER_TO_WANTED) {
            # Only put it in the newsfeed if we have a location, otherwise we wouldn't show it.
            $pos = ($lat || $lng) ? "GeomFromText('POINT($lng $lat)')" : "GeomFromText('POINT(-2.5209 53.9450)')";

            $this->dbhm->preExec("INSERT INTO newsfeed (`type`, userid, imageid, msgid, replyto, groupid, eventid, volunteeringid, publicityid, storyid, message, position, hidden) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, $pos, $hidden);", [
                $type,
                $userid,
                $imageid,
                $msgid,
                $replyto,
                $groupid,
                $eventid,
                $volunteeringid,
                $publicityid,
                $storyid,
                $message
            ]);

            $id = $this->dbhm->lastInsertId();

            if ($id) {
                $this->fetch($this->dbhm, $this->dbhm, $id, 'newsfeed', 'feed', $this->publicatts);

                if ($replyto && !$hidden) {
                    # Bump the thread.
                    $this->dbhm->preExec("UPDATE newsfeed SET timestamp = NOW() WHERE id = ?;", [ $replyto ]);

                    $origs = $this->dbhr->preQuery("SELECT * FROM newsfeed WHERE id = ?;", [ $replyto ]);
                    foreach ($origs as $orig) {
                        # Comment on thread.  We want to notify the original poster and anyone else who
                        # has commented on this thread.
                        $n = new Notifications($this->dbhr, $this->dbhm);

                        if ($orig['userid']) {
                            # Some posts don't have a userid, e.g. central publicity.
                            $n->add($userid, $orig['userid'], Notifications::TYPE_COMMENT_ON_YOUR_POST, $id);
                        }

                        $sql = $orig['userid'] ? "SELECT DISTINCT userid FROM newsfeed WHERE replyto = $replyto AND userid != {$orig['userid']} UNION SELECT DISTINCT userid FROM newsfeed_likes WHERE newsfeedid = $replyto AND userid != {$orig['userid']};" : "SELECT DISTINCT userid FROM newsfeed WHERE replyto = $replyto UNION SELECT DISTINCT userid FROM newsfeed_likes WHERE newsfeedid = $replyto;";
                        $commenters = $this->dbhr->preQuery($sql);

                        foreach ($commenters as $commenter) {
                            $rc = $n->add($userid, $commenter['userid'], Notifications::TYPE_COMMENT_ON_COMMENT, $id);
                        }
                    }

                    # We might have notifications which refer to this thread.  But there's no need to show them
                    # now.
                    $this->dbhm->preExec("UPDATE users_notifications SET seen = 1 WHERE touser = ? AND (newsfeedid = ? OR newsfeedid IN (SELECT id FROM newsfeed WHERE replyto = ?));", [
                        $userid,
                        $replyto,
                        $replyto
                    ]);
                }
            }
        }

        return($id);
    }

    public function getPublic($lovelist = FALSE) {
        $atts = parent::getPublic();
        $users = [];

        $this->fillIn($atts, $users, TRUE);

        foreach ($users as $user) {
            if ($user['id'] == presdef('userid', $atts, NULL)) {
                $atts['user'] = $user;
                unset($atts['userid']);
            }
        }

        if (pres('replies', $atts)) {
            foreach ($atts['replies'] as &$reply) {
                $u = User::get($this->dbhr, $this->dbhm, $reply['userid']);
                $ctx = NULL;
                $reply['user'] = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE);
            }
        }

        if ($lovelist) {
            $atts['lovelist'] = [];
            $loves = $this->dbhr->preQuery("SELECT * FROM newsfeed_likes WHERE newsfeedid = ?;", [
                $this->id
            ]);

            foreach ($loves as $love) {
                $u = User::get($this->dbhr, $this->dbhm, $love['userid']);
                $ctx = NULL;
                $uatts = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE);
                $uatts['publiclocation'] = $u->getPublicLocation();
                $atts['lovelist'][] = $uatts;
            }
        }

        return($atts);
    }

    private function fillIn(&$entry, &$users, $checkreplies = TRUE) {
        unset($entry['position']);

        $use = !presdef('reviewrequired', $entry, FALSE) && !presdef('deleted', $entry, FALSE);

        #error_log("Use $use for type {$entry['type']} from " . presdef('reviewrequired', $entry, FALSE) . "," . presdef('deleted', $entry, FALSE));

        if ($use) {
            if (preg_match_all($this->urlPattern, $entry['message'], $matches)) {
                foreach ($matches as $val) {
                    foreach ($val as $url) {
                        $p = new Preview($this->dbhr, $this->dbhm);
                        $id = $p->get($url);

                        if ($id) {
                            $entry['preview'] = $p->getPublic();
                        }

                        break 2;
                    }
                }
            }

            if ($entry['userid'] && !array_key_exists($entry['userid'], $users)) {
                $u = User::get($this->dbhr, $this->dbhm, $entry['userid']);
                $uctx = NULL;
                $users[$entry['userid']] = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE);
                $users[$entry['userid']]['publiclocation'] = $u->getPublicLocation();

                if ($users[$entry['userid']]['profile']['default']) {
                    # We always want to show an avatar for the newsfeed, but we don't have one.  This won't cause
                    # a flood of updates since the newsfeed is fetched gradually.
                    $u->ensureAvatar($users[$entry['userid']]);
                }
            }

            if (pres('msgid', $entry)) {
                $m = new Message($this->dbhr, $this->dbhm, $entry['msgid']);
                $entry['refmsg'] = $m->getPublic(FALSE, FALSE);
            }

            if (pres('eventid', $entry)) {
                $e = new CommunityEvent($this->dbhr, $this->dbhm, $entry['eventid']);
                $use = FALSE;
                #error_log("Consider event " . $e->getPrivate('pending') . ", " . $e->getPrivate('deleted'));
                if (!$e->getPrivate('pending') && !$e->getPrivate('deleted')) {
                    $use = TRUE;
                    $entry['communityevent'] = $e->getPublic();
                }
            }

            if (pres('volunteeringid', $entry)) {
                $v = new Volunteering($this->dbhr, $this->dbhm, $entry['volunteeringid']);
                $use = FALSE;
                #error_log("Consider volunteering " . $v->getPrivate('pending') . ", " . $v->getPrivate('deleted'));
                if (!$v->getPrivate('pending') && !$v->getPrivate('deleted')) {
                    $use = TRUE;
                    $entry['volunteering'] = $v->getPublic();
                }
            }

            if (pres('publicityid', $entry)) {
                $pubs = $this->dbhr->preQuery("SELECT postid, data FROM groups_facebook_toshare WHERE id = ?;", [ $entry['publicityid'] ]);

                if (preg_match('/(.*)_(.*)/', $pubs[0]['postid'], $matches)) {
                    # Create the iframe version of the Facebook plugin.
                    $pageid = $matches[1];
                    $postid = $matches[2];

                    $data = json_decode($pubs[0]['data'], TRUE);

                    $entry['publicity'] = [
                        'id' => $entry['publicityid'],
                        'postid' => $pubs[0]['postid'],
                        'iframe' => '<iframe class="completefull" src="https://www.facebook.com/plugins/post.php?href=https%3A%2F%2Fwww.facebook.com%2F' . $pageid . '%2Fposts%2F' . $postid . '%2F&width=auto&show_text=true&appId=' . FBGRAFFITIAPP_ID . '&height=500" width="500" height="500" style="border:none;overflow:hidden" scrolling="no" frameborder="0" allowTransparency="true"></iframe>',
                        'full_picture' => presdef('full_picture', $data, NULL),
                        'message' => presdef('message', $data, NULL),
                        'type' => presdef('type', $data, NULL)
                    ];
                }
            }

            if (pres('storyid', $entry)) {
                $s = new Story($this->dbhr, $this->dbhm, $entry['storyid']);
                $use = FALSE;
                #error_log("Consider story " . $s->getPrivate('reviewed') . ", " . $s->getPrivate('public'));
                if ($s->getPrivate('reviewed') && $s->getPrivate('public') && $s->getId()) {
                    $use = TRUE;
                    $entry['story'] = $s->getPublic();
                }
            }


            $entry['timestamp'] = ISODate($entry['timestamp']);

            if (pres('added', $entry)) {
                $entry['added'] = ISODate($entry['added']);
            }

            $me = whoAmI($this->dbhr, $this->dbhm);

            $likes = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM newsfeed_likes WHERE newsfeedid = ?;", [
                $entry['id']
            ], FALSE, FALSE);

            $entry['loves'] = $likes[0]['count'];
            $entry['loved'] = FALSE;

            if ($me) {
                $likes = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM newsfeed_likes WHERE newsfeedid = ? AND userid = ?;", [
                    $entry['id'],
                    $me->getId()
                ], FALSE, FALSE);
                $entry['loved'] = $likes[0]['count'] > 0;
            }

            $entry['replies'] = [];

            if ($checkreplies) {
                # Don't cache replies - might be lots and might change frequently.
                $replies = $this->dbhr->preQuery("SELECT * FROM newsfeed WHERE replyto = ? ORDER BY id ASC;", [
                    $entry['id']
                ], FALSE);
                
                $last = NULL;

                foreach ($replies as &$reply) {
                    # Replies only one deep at present.
                    $this->fillIn($reply, $users, FALSE);

                    if ($reply['visible'] &&
                        $last['userid'] == $reply['userid'] &&
                        $last['type'] == $reply['type'] &&
                        $last['message'] == $reply['message']) {
                        # Suppress duplicates.
                        $reply['visible'] = FALSE;
                    }
                    
                    $entry['replies'][] = $reply;
                    $last = $reply;
                }
            }
        }

        $entry['visible'] = $use;
        return($use);
    }

    public function getNearbyDistance($userid, $max = 204800) {
        $u = User::get($this->dbhr, $this->dbhm, $userid);

        # We want to calculate a distance which includes at least some other people who have posted a message.
        # Start at fairly close and keep doubling until we reach that, or get too far away.
        list ($lat, $lng) = $u->getLatLng();
        $dist = 800;
        $limit = 10;

        do {
            $dist *= 2;

            # To use the spatial index we need to have a box.
            # TODO This doesn't work if the box spans the equator.  For us it only does for testing.
            $ne = GreatCircle::getPositionByDistance($dist, 45, $lat, $lng);
            $sw = GreatCircle::getPositionByDistance($dist, 225, $lat, $lng);

            $box = "GeomFromText('POLYGON(({$sw['lng']} {$sw['lat']}, {$sw['lng']} {$ne['lat']}, {$ne['lng']} {$ne['lat']}, {$ne['lng']} {$sw['lat']}, {$sw['lng']} {$sw['lat']}))')";

            $sql = "SELECT DISTINCT userid FROM newsfeed WHERE MBRContains($box, position) AND replyto IS NULL LIMIT $limit;";
            $others = $this->dbhr->preQuery($sql);
            #error_log("Found " . count($others) . " at $dist from $lat, $lng for $userid using $sql");
        } while ($dist < $max && count($others) < $limit);

        return($dist);
    }

    public function getFeed($userid, $dist = Newsfeed::DISTANCE, $types, &$ctx, $fillin = TRUE) {
        $u = User::get($this->dbhr, $this->dbhm, $userid);
        $users = [];
        $items = [];

        # We want the newsfeed items which are close to us.  Use the location in settings, or failing that the
        # last location they've posted from.
        list ($lat, $lng) = $u->getLatLng();

        # To use the spatial index we need to have a box.
        $ne = GreatCircle::getPositionByDistance($dist, 45, $lat, $lng);
        $sw = GreatCircle::getPositionByDistance($dist, 225, $lat, $lng);

        $box = "GeomFromText('POLYGON(({$sw['lng']} {$sw['lat']}, {$sw['lng']} {$ne['lat']}, {$ne['lng']} {$ne['lat']}, {$ne['lng']} {$sw['lat']}, {$sw['lng']} {$sw['lat']}))')";

        # We return most recent first.
        $tq = pres('timestamp', $ctx) ? ("newsfeed.timestamp < " . $this->dbhr->quote($ctx['timestamp'])) : 'newsfeed.id > 0';
        $first = $dist ? "(MBRContains($box, position) OR `type` IN ('CentralPublicity', 'Alert')) AND $tq" : $tq;
        $typeq = $types ? (" AND `type` IN ('" . implode("','", $types) . "') ") : '';

        $sql = "SELECT " . implode(',', $this->publicatts) . ", hidden FROM newsfeed WHERE $first AND replyto IS NULL $typeq ORDER BY timestamp DESC LIMIT 5;";
        #error_log($sql);
        $entries = $this->dbhr->preQuery($sql);
        $last = NULL;

        $me = whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        foreach ($entries as &$entry) {
            $hidden = $entry['hidden'];

            # Don't use hidden entries unless they are ours.  This means that to a spammer it looks like their posts
            # are there but nobody else sees them.
            if (!$hidden || $myid == $entry['userid']) {
                unset($entry['hidden']);

                if ($fillin) {
                    $this->fillIn($entry, $users);

                    # We return invisible entries - they are filtered on the client, and it makes the paging work.
                    if ($entry['visible'] &&
                        $last['userid'] == $entry['userid'] &&
                        $last['type'] == $entry['type'] &&
                        $last['message'] == $entry['message']) {
                        # Suppress duplicates.
                        $entry['visible'] = FALSE;
                    }
                }

                $items[] = $entry;
            }

            $ctx = [
                'timestamp' => ISODate($entry['timestamp']),
                'distance' => $dist
            ];

            $last = $entry;
        }

        return([$users, $items]);
    }

    public function referToWanted() {
        # Create a kind of comment and notify the poster.
        $id = $this->create(Newsfeed::TYPE_REFER_TO_WANTED, NULL, NULL, NULL, NULL, $this->id);
        $n = new Notifications($this->dbhr, $this->dbhm);
        $n->add(NULL, $this->feed['userid'], Notifications::TYPE_COMMENT_ON_YOUR_POST, $this->id);
    }

    public function like() {
        $me = whoAmI($this->dbhr, $this->dbhm);
        if ($me) {
            $this->dbhm->preExec("INSERT IGNORE INTO newsfeed_likes (newsfeedid, userid) VALUES (?,?);", [
                $this->id,
                $me->getId()
            ]);

            # We want to notify the original poster.  The type depends on whether this was the start of a thread or
            # a comment on it.
            $n = new Notifications($this->dbhr, $this->dbhm);
            $n->add($me->getId(), $this->feed['userid'], $this->feed['replyto'] ? Notifications::TYPE_LOVED_COMMENT : Notifications::TYPE_LOVED_POST, $this->id);
        }
    }

    public function unlike() {
        $me = whoAmI($this->dbhr, $this->dbhm);
        if ($me) {
            $this->dbhm->preExec("DELETE FROM newsfeed_likes WHERE newsfeedid = ? AND userid = ?;", [
                $this->id,
                $me->getId()
            ]);
        }
    }

    public function delete() {
        $me = whoAmI($this->dbhr, $this->dbhm);
        if ($me) {
            $this->dbhm->preExec("UPDATE newsfeed SET deleted = NOW(), deletedby = ? WHERE id = ?;", [
                $me->getId(),
                $this->id
            ]);
        }
    }

    public function seen($userid) {
        $this->dbhm->preExec("REPLACE INTO newsfeed_users (userid, newsfeedid) VALUES (?, ?);", [
            $userid,
            $this->id
        ]);
    }

    public function getUnseen($userid) {
        # Find the last one we saw.
        $seens = $this->dbhr->preQuery("SELECT * FROM newsfeed_users WHERE userid = ?;", [
            $userid
        ]);

        $lastseen = 0;
        foreach ($seens as $seen) {
            $lastseen = $seen['newsfeedid'];
        }

        # Get the first few user-posted messages.  This keeps the unseen count low - if it gets too high
        # it puts people off.
        $ctx = NULL;
        list ($users, $feeds) = $this->getFeed($userid, $this->getNearbyDistance($userid), [ Newsfeed::TYPE_MESSAGE ], $ctx, FALSE);
        $count = 0;
        foreach ($feeds as $feed) {
            if ($feed['id'] > $lastseen && $feed['userid'] != $userid) {
                $count++;
            }
        }

        return($count);
    }

    public function report($reason) {
        $me = whoAmI($this->dbhr, $this->dbhm);
        if ($me) {
            $this->dbhm->preExec("UPDATE newsfeed SET reviewrequired = 1 WHERE id = ?;", [
                $this->id
            ]);

            $this->dbhm->preExec("INSERT INTO newsfeed_reports (userid, newsfeedid, reason) VALUES (?, ?, ?);", [
                $me->getId(),
                $this->id,
                $reason
            ]);
        }
    }

    private function snip(&$msg) {
        if ($msg) {
            $msg = str_replace("\n", ' ', $msg);
            if (strlen($msg) > 117) {
                $msg = substr($msg, 0, strpos(wordwrap($msg, 120), "\n")) . '...';
            }
        }
    }

    public function sendIt($mailer, $message) {
        $mailer->send($message);
    }

    public function digest($userid) {
        # We send a mail with unseen user-generated posts from quite nearby.
        $u = User::get($this->dbhr, $this->dbhm, $userid);
        $count = 0;

        $latlng = $u->getLatLng(FALSE);

        if ($latlng[0] || $latlng[1]) {
            # We have a location for them.
            # Find the last one we saw.
            $seens = $this->dbhr->preQuery("SELECT * FROM newsfeed_users WHERE userid = ?;", [
                $userid
            ]);

            $lastseen = 0;
            foreach ($seens as $seen) {
                $lastseen = $seen['newsfeedid'];
            }

            # Get the first few user-posted messages.
            $ctx = NULL;
            list ($users, $feeds) = $this->getFeed($userid, $this->getNearbyDistance($userid, 8046), [ Newsfeed::TYPE_MESSAGE ], $ctx, FALSE);
            $summ = '';
            $max = 0;

            $oldest = ISODate(date("Y-m-d H:i:s", strtotime("midnight 7 days ago")));

            foreach ($feeds as $feed) {
                if ($feed['userid'] != $userid && $feed['id'] > $lastseen && $feed['timestamp'] > $oldest) {
                    $count++;

                    $str = $feed['message'];
                    $this->snip($str);
                    $u = User::get($this->dbhr, $this->dbhm, $feed['userid']);
                    $summ .= $u->getName() . " posted '$str'\n\n";
                    $max = max($max, $feed['id']);
                }
            }

            if ($max) {
                $this->dbhm->preExec("REPLACE INTO newsfeed_users (userid, newsfeedid) VALUES (?, ?);", [
                    $userid,
                    $max
                ]);
            }

            if ($count > 0) {
                # Got some to send
                $u = new User($this->dbhr, $this->dbhm, $userid);
                if ($u->sendOurMails() && $u->getSetting('notificationmails', TRUE)) {
                    $url = $u->loginLink(USER_SITE, $userid, '/newsfeed', 'newsfeeddigest');
                    $noemail = 'notificationmailsoff-' . $userid . "@" . USER_DOMAIN;

                    $html = notification_digest($url, $noemail, $u->getName(), $u->getEmailPreferred(), nl2br($summ));

                    $message = Swift_Message::newInstance()
                        ->setSubject("Freeglers near you are talking - $count new post" . ($count != 1 ? 's' : ''))
                        ->setFrom([NOREPLY_ADDR => 'Freegle'])
                        ->setReturnPath($u->getBounce())
                        ->setTo([ $u->getEmailPreferred() => $u->getName() ])
                        ->setBody("Recent posts from nearby freeglers:\r\n\r\n$summ\r\n\r\nPlease click here to read them: $url")
                        ->addPart($html, 'text/html');

                    error_log("..." . $u->getEmailPreferred() . " send $count");
                    list ($transport, $mailer) = getMailer();
                    $this->sendIt($mailer, $message);
                }
            }
        }

        return($count);
    }
}