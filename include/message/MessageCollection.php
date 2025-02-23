<?php
namespace Booktastic\Iznik;



class MessageCollection
{
    # These match the collection enumeration.
    const INCOMING = 'Incoming';
    const APPROVED = 'Approved';
    const PENDING = 'Pending';
    const EDITS = 'Edit';
    const SPAM = 'Spam';
    const DRAFT = 'Draft';
    const REJECTED = 'Rejected'; # Rejected by mod; user can see and resend.
    const ALLUSER = 'AllUser';
    const CHAT = 'Chat'; # Chat message
    const VIEWED = 'Viewed';
    const OWNPOSTS = 120;

    // To members we only show posts upto this age.
    const RECENTPOSTS = "Midnight 31 days ago";

    /** @var  $dbhr LoggedPDO */
    public $dbhr;
    /** @var  $dbhm LoggedPDO */
    public $dbhm;

    private $collection;

    private $userlist = [];
    private $locationlist = [];

    private $allUser = FALSE;

    /**
     * @return null
     */
    public function getCollection()
    {
        return $this->collection;
    }

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $collection = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        switch ($collection) {
            case MessageCollection::APPROVED:
            case MessageCollection::PENDING:
            case MessageCollection::EDITS:
            case MessageCollection::DRAFT:
            case MessageCollection::REJECTED:
            case MessageCollection::VIEWED:
                $this->collection = [$collection];
                break;
            case MessageCollection::ALLUSER:
                # The ones users should be able to see, e.g. on My Posts.
                $this->allUser = TRUE;

                $this->collection = [
                    MessageCollection::DRAFT,
                    MessageCollection::APPROVED,
                    MessageCollection::PENDING,
                    MessageCollection::REJECTED,
                ];
                break;
            default:
                $this->collection = [];
        }
    }

    function countMyPostsOpen() {
        $ret = 0;

        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        if ($me) {
            // messages_spatial is fast and fairly up to date.
            $counts = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM messages INNER JOIN messages_spatial ON messages_spatial.msgid = messages.id WHERE fromuser = ? AND successful = 0;", [
                $me->getId()
            ]);

            $ret = $counts[0]['count'];
        }

        return $ret;
    }

    function get(&$ctx, $limit, $groupids, $userids = NULL, $types = NULL, $age = NULL, $hasoutcome = NULL, $summary = FALSE)
    {
        $backstop = 1000;
        $limit = intval($limit);

        do {
            $tofill = [];
            $me = Session::whoAmI($this->dbhr, $this->dbhm);

            # At the moment we only support ordering by arrival DESC.  Note that arrival can either be when this
            # message arrived for the very first time, or when it was reposted.
            $date = (is_null($ctx) || !Utils::pres('Date', $ctx)) ? NULL : $this->dbhr->quote(date("Y-m-d H:i:s", intval($ctx['Date'])));
            $dateq = !$date ? ' 1=1 ' : (" (messages_groups.arrival < $date OR (messages_groups.arrival = $date AND messages_groups.msgid < " . $this->dbhr->quote($ctx['id']) . ")) ");

            if (is_null($ctx) && in_array(MessageCollection::DRAFT, $this->collection)) {
                # Draft messages are handled differently, as they're not attached to any group.  Only show
                # recent drafts - if they've not completed within a reasonable time they're probably stuck.
                # Only return these on the first fetch of a sequence.  No point returning them multiple times.
                $mysqltime = date("Y-m-d", strtotime("Midnight 7 days ago"));
                $oldest = " AND timestamp >= '$mysqltime' ";

                $userids = $userids ? $userids : ($me ? [$me->getId()] : NULL);

                $summjoin = $summary ? ", messages.subject, (SELECT messages_outcomes.id FROM messages_outcomes WHERE msgid = messages.id ORDER BY id DESC LIMIT 1) AS outcomeid": '';

                $sql = $userids ? ("SELECT messages_drafts.msgid AS id, 1 AS isdraft, messages.availablenow, messages.availableinitially, messages.lat, messages.lng, messages.arrival, messages.type AS type, fromuser $summjoin FROM messages_drafts LEFT JOIN messages_groups ON messages_groups.msgid = messages_drafts.msgid INNER JOIN messages ON messages_drafts.msgid = messages.id WHERE (session = ? OR userid IN (" . implode(',', $userids) . ")) AND messages_groups.msgid IS NULL $oldest ORDER BY messages.id DESC LIMIT $limit;") : "SELECT messages_drafts.msgid AS id, messages.type AS type, fromuser $summjoin FROM messages_drafts LEFT JOIN messages_groups ON messages_groups.msgid = messages_drafts.msgid INNER JOIN messages ON messages_drafts.msgid = messages.id  WHERE session = ? AND messages_groups.msgid IS NULL $oldest ORDER BY messages.id DESC LIMIT $limit;";
                $tofill = $this->dbhr->preQuery($sql, [
                    session_id()
                ]);

                foreach ($tofill as &$fill) {
                    $fill['groupid'] = NULL;
                    $fill['replycount'] = 0;
                    $fill['collection'] = MessageCollection::DRAFT;
                }
            } else if (in_array(MessageCollection::VIEWED, $this->collection)) {
                # We want to return the most recent messages we have viewed.  We don't support this query in
                # combination with others, and we return an abbreviated set of message info.
                $msgs = [];

                if ($me) {
                    $start = date('Y-m-d', strtotime("30 days ago"));
                    $sql = "SELECT messages.id, messages.availablenow, messages.availableinitially, messages.lat, messages.lng, messages.arrival, messages.type, messages.subject, messages_likes.timestamp AS viewedat, messages_likes.count, (SELECT messages_outcomes.id FROM messages_outcomes WHERE msgid = messages.id ORDER BY id DESC LIMIT 1) AS outcomeid FROM messages_likes INNER JOIN messages ON messages.id = messages_likes.msgid WHERE userid = ? AND messages_likes.type = 'View' AND messages_likes.timestamp >= '$start' HAVING outcomeid IS NULL ORDER BY messages_likes.timestamp DESC LIMIT 5;";
                    $msgs = $this->dbhr->preQuery($sql, [
                        $me->getId()
                    ]);

                    foreach ($msgs as &$msg) {
                        $msg['arrival'] = Utils::ISODate($msg['arrival']);
                        $msg['viewedat'] = Utils::ISODate($msg['viewedat']);
                    }
                }

                return([ [], $msgs ]);
            }

            $collection = array_filter($this->collection, function ($val) {
                return ($val != MessageCollection::DRAFT);
            });

            if (in_array(MessageCollection::EDITS, $this->collection) && count($groupids)) {
                # Edit messages are also handled differently.  We want to show any edits which are pending review
                # for messages on groups which you're a mod on.  Only show recent edits - if they're not reviewed
                # within a reasonable time then just assume they're ok.
                #
                # See also Group.
                $me = $me ? $me : Session::whoAmI($this->dbhr, $this->dbhm);

                if ($me && $me->isModerator()) {
                    $mysqltime = date("Y-m-d", strtotime("Midnight 7 days ago"));

                    $summjoin = $summary ? ", messages.subject, (SELECT messages_outcomes.id FROM messages_outcomes WHERE msgid = messages.id ORDER BY id DESC LIMIT 1) AS outcomeid": '';
                    $groupq = "AND messages_groups.groupid IN (" . implode(',', $groupids) . ") ";

                    $sql = "SELECT DISTINCT messages.id AS id, 0 AS isdraft, messages.availablenow, messages.availableinitially, messages.lat, messages.lng, messages_groups.arrival, messages.type, fromuser $summjoin FROM messages_edits INNER JOIN messages_groups ON messages_edits.msgid = messages_groups.msgid INNER JOIN messages ON messages_groups.msgid = messages.id WHERE messages_edits.timestamp > '$mysqltime' AND messages_edits.reviewrequired = 1 AND messages_groups.deleted = 0 $groupq AND $dateq ORDER BY messages_edits.msgid, messages_edits.timestamp ASC;";
                    $tofill2 = $this->dbhr->preQuery($sql);

                    $ctx = ['Date' => NULL, 'id' => PHP_INT_MAX];

                    foreach ($tofill2 as &$fill) {
                        $fill['collection'] = MessageCollection::EDITS;
                        $thisepoch = strtotime($fill['arrival']);

                        if (is_null($ctx['Date']) || $thisepoch < $ctx['Date']) {
                            $ctx['Date'] = $thisepoch;
                        }

                        $ctx['id'] = min($fill['id'], $ctx['id']);
                    }

                    $tofill = array_merge($tofill, $tofill2);
                }
            }

            $collection = array_filter($collection, function ($val) {
                return ($val != MessageCollection::EDITS);
            });

            if (count($collection) > 0) {
                $typeq = $types ? (" AND `type` IN (" . implode(',', $types) . ") ") : '';
                $oldest = '';

                if (!is_null($age)) {
                    $mysqltime = date("Y-m-d", strtotime("Midnight $age days ago"));
                    $oldest = " AND messages_groups.arrival >= '$mysqltime' ";
                } else if (!Session::modtools()) {
                    # No point showing old messages on FD, and this keeps the query fast.
                    $mysqltime = date("Y-m-d", strtotime("Midnight 90 days ago"));
                    $oldest = " AND messages_groups.arrival >= '$mysqltime' ";
                }

                # We might be looking for posts with no outcome.
                $outcomeq1 = !is_null($hasoutcome) ? " LEFT JOIN messages_outcomes ON messages_outcomes.id = messages.id " : '';
                $outcomeq2 = !is_null($hasoutcome) ? " HAVING outcomeid IS NULL " : '';
                $outcomeq3 = !is_null($hasoutcome) ? ", messages_outcomes.id AS outcomeid" : '';

                # We might be getting a summary, in which case we want to get lots of information in the same query
                # for performance reasons.
                # TODO This doesn't work for messages on multiple groups.
                $summjoin = '';

                if ($summary) {
                    $summjoin = ", messages_groups.msgtype AS type, messages.source, messages.fromuser, messages.subject, messages.textbody,
                (SELECT groupid FROM messages_groups WHERE msgid = messages.id) AS groupid,
                (SELECT COALESCE(namefull, nameshort) FROM `groups` WHERE groups.id = messages_groups.groupid) AS namedisplay,
                (SELECT COUNT(DISTINCT userid) FROM chat_messages WHERE refmsgid = messages.id AND reviewrejected = 0 AND reviewrequired = 0 AND processingsuccessful = 1 AND chat_messages.userid != messages.fromuser AND chat_messages.type = 'Interested') AS replycount,                  
                (SELECT messages_attachments.id FROM messages_attachments WHERE msgid = messages.id ORDER BY messages_attachments.id LIMIT 1) AS attachmentid, 
                (SELECT messages_outcomes.id FROM messages_outcomes WHERE msgid = messages.id ORDER BY id DESC LIMIT 1) AS outcomeid";
                }

                # We may have some groups to filter by.
                $groupq = $groupids ? (" AND groupid IN (" . implode(',', $groupids) . ") ") : '';

                # We have a complicated set of different queries we can do.  This is because we want to make sure that
                # the query is as fast as possible, which means:
                # - access as few tables as we need to
                # - use multicolumn indexes
                $collectionq = " AND collection IN ('" . implode("','", $collection) . "') ";

                if ($userids) {
                    # We only query on a small set of userids, so it's more efficient to get the list of messages from them
                    # first.  This is quicker if we use the arrival in messages, to avoid getting all the messages ever,
                    # so add a buffer to allow for reposts.
                    $bufferdate = date("Y-m-d", ($date ? strtotime($date) : time()) - 365 * 24 * 60 * 60);
                    $seltab = "(SELECT id, availablenow, availableinitially, arrival, lat, lng, " . ($summary ? 'subject, ' : '') . "fromuser, deleted, `type`, textbody, source FROM messages WHERE fromuser IN (" . implode(',', $userids) . ")) messages";
                    $sql = "SELECT 0 AS isdraft, messages_groups.msgid AS id, messages.availablenow, messages.availableinitially, messages.lat, messages.lng, messages_groups.groupid, messages_groups.arrival, messages_groups.collection $outcomeq3 $summjoin FROM messages_groups INNER JOIN $seltab ON messages_groups.msgid = messages.id AND messages.deleted IS NULL $outcomeq1 WHERE messages.arrival >= '$bufferdate' AND $dateq $oldest $typeq $groupq $collectionq AND messages_groups.deleted = 0 AND messages.fromuser IS NOT NULL $outcomeq2 ORDER BY messages_groups.arrival DESC, messages_groups.msgid DESC LIMIT $limit";
                } else if (count($groupids) > 0) {
                    # The messages_groups table has a multi-column index which makes it quick to find the relevant messages.
                    $typeq = $types ? (" AND `msgtype` IN (" . implode(',', $types) . ") ") : '';
                    $sql = "SELECT 0 AS isdraft, messages_groups.msgid as id, messages.availablenow, messages.availableinitially, messages.lat, messages.lng, messages_groups.groupid, messages_groups.arrival, messages_groups.collection $summjoin FROM messages_groups INNER JOIN messages ON messages.id = messages_groups.msgid $outcomeq1 WHERE $dateq $oldest $groupq $collectionq AND messages_groups.deleted = 0 AND messages.fromuser IS NOT NULL $typeq $outcomeq2 ORDER BY arrival DESC, messages_groups.msgid DESC LIMIT $limit;";
                } else {
                    # We are not searching within a specific group, so we have no choice but to do a larger join.
                    $sql = "SELECT 0 AS isdraft, messages_groups.msgid AS id, messages.availablenow, messages.availableinitially, messages.lat, messages.lng, messages_groups.groupid, messages_groups.arrival, messages_groups.collection $summjoin FROM messages_groups INNER JOIN messages ON messages_groups.msgid = messages.id AND messages.deleted IS NULL $outcomeq1 WHERE $dateq $oldest $typeq $collectionq AND messages_groups.deleted = 0 AND messages.fromuser IS NOT NULL ORDER BY messages_groups.arrival DESC, messages_groups.msgid $outcomeq2 DESC LIMIT $limit";
                }

                #error_log("Get list $sql");
                #file_put_contents('/tmp/sql', $sql);
                $msglist = $this->dbhr->preQuery($sql);

                # Get an array of the basic info.  Save off context for next time.
                $ctx = ['Date' => NULL, 'id' => 0];

                foreach ($msglist as $msg) {
                    $tofill[] = $msg;
                    $thisepoch = strtotime($msg['arrival']);

                    if (is_null($ctx['Date']) || $thisepoch < $ctx['Date']) {
                        # The messages are returned in order of date, then id.  This logic here matches the ordering
                        # in the SQL above.
                        $ctx['Date'] = $thisepoch;
                        $ctx['id'] = Utils::pres('id', $ctx) ? max($msg['id'], $ctx['id']) : $msg['id'];
                    }
                }
            }

            list($groups, $msgs) = $this->fillIn($tofill, $limit, NULL, $summary);
            #error_log("Filled in " . count($msgs) . " from " . count($tofill));

            # We might have excluded all the messages we found; if so, keep going.
            $backstop--;
        } while (count($tofill) > 0 && count($msgs) == 0 && $backstop > 0);

        return ([$groups, $msgs]);
    }

    public function fillIn($msglist, $limit, $messagetype, $summary)
    {
        $msgs = [];

        # We need to do a little tweaking of msglist to get it ready to pass to getPublics.
        foreach ($msglist as &$msg) {
            if ($summary) {
                if (Utils::pres('groupid', $msg)) {
                    # TODO If we support messages on multiple groups then this needs reworking.
                    $msg['groups'] = ([
                        [
                            'groupid' => $msg['groupid'],
                            'namedisplay' => $msg['namedisplay'],
                            'arrival' => Utils::ISODate($msg['arrival']),
                            'collection' => $msg['collection']
                        ]
                    ]);
                }

                if (Utils::pres('outcomeid', $msg)) {
                    $msg['outcomes'] = [$msg['outcomeid']];
                }

                if (Utils::pres('attachmentid', $msg)) {
                    $a = new Attachment($this->dbhr, $this->dbhm, $msg['attachmentid'], Attachment::TYPE_MESSAGE);

                    $msg['attachments'] = [
                        [
                            'id' => $msg['attachmentid'],
                            'path' => $a->getpath(false, $msg['attachmentid']),
                            'paththumb' => $a->getpath(true, $msg['attachmentid'])
                        ]
                    ];
                }
            }
        }

        if (!$summary) {
            # In the summary case we have fetched the message attributes we need.  Otherwise we need to get them now.
            # This logic is similar to Message::_construct.
            #
            # getPublics will filter them based on what we are allowed to see.
            $msgids = array_filter(array_column($msglist, 'id'));

            if (count($msgids)) {
                $sql = "SELECT messages.*, messages_deadlines.FOP, CASE WHEN messages_drafts.msgid IS NOT NULL AND messages_groups.msgid IS NULL THEN 1 ELSE 0 END AS isdraft, messages_items.itemid AS itemid, items.name AS itemname, items.weight AS itemweight FROM messages LEFT JOIN messages_groups ON messages_groups.msgid = messages.id LEFT JOIN messages_deadlines ON messages_deadlines.msgid = messages.id LEFT JOIN users ON users.id = messages.fromuser LEFT JOIN messages_drafts ON messages_drafts.msgid = messages.id LEFT JOIN messages_items ON messages_items.msgid = messages.id LEFT JOIN items ON items.id = messages_items.itemid WHERE messages.id IN (" . implode(',', $msgids) . ");";
                $vals = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);
                foreach ($vals as $val) {
                    foreach ($msglist as &$msg) {
                        if ($msg['id'] == $val['id']) {
                            $msg = array_merge($msg, $val);

                            if ($msg['source'] == Message::PLATFORM && $msg['type'] == Message::TYPE_OFFER && is_null($msg['FOP'])) {
                                $msg['FOP'] = 1;
                            }
                        }
                    }
                }
            }
        }

        $m = new Message($this->dbhr, $this->dbhm);
        $publics = $m->getPublics($msglist, Session::modtools(), TRUE, FALSE, $this->userlist, $this->locationlist, $summary);
        $cansees = NULL;

        foreach ($publics as &$public) {
            $type = $public['type'];

            if (!$messagetype || $type == $messagetype) {
                if (is_null($cansees)) {
                    $cansees = $m->canSees($publics);
                }

                $cansee = $cansees[$public['id']];

                $coll = Utils::presdef('collection', $msg, MessageCollection::APPROVED);

                if ($cansee && $coll != MessageCollection::DRAFT) {
                    # Make sure we only return this if it's on a group.
                    $cansee = count($public['groups']) > 0;
                }

                if ($cansee) {
                    $role = $public['myrole'];

                    switch ($coll) {
                        case MessageCollection::DRAFT:
                            if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) {
                                # Only visible to moderators or owners, or self (which returns a role of moderator).
                                $n = $public;
                                unset($n['message']);
                                $msgs[] = $n;
                            }
                            break;
                        case MessageCollection::APPROVED:
                            $n = $public;
                            unset($n['message']);
                            $n['matchedon'] = Utils::presdef('matchedon', $msg, NULL);
                            $msgs[] = $n;
                            $limit--;
                            break;
                        case MessageCollection::PENDING:
                        case MessageCollection::REJECTED:
                        case MessageCollection::EDITS:
                            if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) {
                                # Only visible to moderators or owners
                                $n = $public;
                                unset($n['message']);
                                $n['matchedon'] = Utils::presdef('matchedon', $msg, NULL);
                                $msgs[] = $n;
                                $limit--;
                            }
                            break;
                    }
                }
            }

            if ($limit <= 0) {
                break;
            }
        }

        # Get groups.
        $groupids = [];
        foreach ($msgs as $msg) {
            if (Utils::pres('groups', $msg)) {
                foreach ($msg['groups'] as $group) {
                    $groupids[] = $group['groupid'];
                }
            }
        }

        $groupids = array_unique($groupids);
        $groups = [];

        foreach ($groupids as $groupid) {
            $g = Group::get($this->dbhr, $this->dbhm, $groupid);
            $groups[$groupid] = $g->getPublic();
        }

        $msgids = array_filter(array_column($msglist, 'id'));

        if (count($msgids)) {
            # Add any user microvolunteering comments.
            $sql = "SELECT * FROM microactions WHERE msgid IN (" . implode(',', $msgids) . ");";
            $vals = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);
            foreach ($msgs as &$msg) {
                $msg['microvolunteering'] = [];
                foreach ($vals as $val) {
                    if ($msg['id'] == $val['msgid']) {
                        $msg['microvolunteering'][] = $val;
                    }
                }
            }
        }

        return ([$groups, $msgs]);
    }

    function getRecentMessages($type = Group::GROUP_FREEGLE)
    {
        $groupq = $type ? " AND groups.type = '$type' " : "";
        $mysqltime = date("Y-m-d H:i:s", strtotime('30 minutes ago'));
        $messages = $this->dbhr->preQuery("SELECT messages.id, messages_groups.arrival, messages_groups.groupid, messages.subject FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid INNER JOIN `groups` ON messages_groups.groupid = groups.id INNER JOIN users ON messages.fromuser = users.id WHERE messages_groups.arrival > ? AND collection = ? $groupq ORDER BY messages_groups.arrival ASC;", [
            $mysqltime,
            MessageCollection::APPROVED
        ]);

        $ret = [];

        $last = NULL;
        foreach ($messages as $message) {
            $g = Group::get($this->dbhr, $this->dbhm, $message['groupid']);
            $namedisplay = $g->getPrivate('namefull') ? $g->getPrivate('namefull') : $g->getPrivate('nameshort');
            $arrival = strtotime($message['arrival']);
            $delta = !is_null($last) ? ($arrival - $last) : 0;
            $last = $arrival;

            $ret[] = [
                'id' => $message['id'],
                'message' => [
                    'id' => $message['id'],
                    'subject' => $message['subject'],
                    'arrival' => Utils::ISODate($message['arrival']),
                    'delta' => $delta,
                ],
                'group' => [
                    'id' => $g->getId(),
                    'nameshort' => $g->getPrivate('nameshort'),
                    'namedisplay' => $namedisplay,
                    'lat' => $g->getPrivate('lat'),
                    'lng' => $g->getPrivate('lng')
                ]
            ];
        }

        return ($ret);
    }

    function getChanges($since)
    {
        $mysqltime = date("Y-m-d H:i:s", strtotime($since));

        # We want messages which have been deleted, had an outcome, or been edited.
        $changes = $this->dbhm->preQuery("SELECT id, deleted AS timestamp, 'Deleted' AS `type` FROM messages WHERE deleted > ? 
UNION SELECT msgid AS id, timestamp, outcome AS `type` FROM messages_outcomes WHERE timestamp > ? 
UNION SELECT messages_edits.msgid AS id, timestamp, 'Edited' AS `type` FROM messages_edits INNER JOIN messages_groups ON messages_groups.msgid = messages_edits.msgid AND collection = ? WHERE timestamp > ?
UNION SELECT msgid AS id, promisedat AS timestamp, 'Promised' AS `type` FROM messages_promises WHERE promisedat > ?
UNION SELECT msgid AS id, timestamp, 'Reneged' AS `type` FROM messages_reneged WHERE timestamp > ?
UNION SELECT msgid AS id, arrival AS timestamp, 'ApprovedOrReposted' AS `type` FROM messages_groups WHERE messages_groups.arrival > ? AND messages_groups.collection = ?;", [
            $mysqltime,
            $mysqltime,
            MessageCollection::APPROVED,
            $mysqltime,
            $mysqltime,
            $mysqltime,
            $mysqltime,
            MessageCollection::APPROVED,
        ]);

        foreach ($changes as &$change) {
            $change['timestamp'] = Utils::ISODate($change['timestamp']);
        }

        return ($changes);
    }

    function getInBounds($swlat, $swlng, $nelat, $nelng, $groupid) {
        # If we are passed coordinates which are a point, we get a DB error.  Ensure we don't.
        if ($swlat == $nelat) {
            $swlat -= 0.000001;
            $nelat += 0.000001;
        }

        if ($swlng == $nelng) {
            $swlng -= 0.000001;
            $nelng += 0.000001;
        }

        if ($groupid) {
            $sql = "SELECT ST_Y(point) AS lat, ST_X(point) AS lng, messages_spatial.msgid AS id, messages_spatial.successful, messages_spatial.promised, messages_spatial.groupid, messages_spatial.msgtype AS type, messages_spatial.arrival FROM messages_spatial WHERE messages_spatial.groupid = $groupid ORDER BY messages_spatial.arrival DESC, messages_spatial.msgid DESC;";
        } else {
            $sql = "SELECT ST_Y(point) AS lat, ST_X(point) AS lng, messages_spatial.msgid AS id, messages_spatial.successful, messages_spatial.promised, messages_spatial.groupid, messages_spatial.msgtype AS type, messages_spatial.arrival FROM messages_spatial INNER JOIN messages_groups ON messages_groups.msgid = messages_spatial.msgid WHERE ST_Contains(ST_GeomFromText('POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))', {$this->dbhr->SRID()}), point) ORDER BY messages_spatial.arrival DESC, messages_spatial.msgid DESC;";
        }

        $msgs = $this->dbhr->preQuery($sql);

        # Blur them.
        foreach ($msgs as &$msg) {
            list ($msg['lat'], $msg['lng']) = Utils::blur($msg['lat'], $msg['lng'], Utils::BLUR_USER);
            $msg['arrival'] = Utils::ISODate($msg['arrival']);
        }

        return $msgs;
    }

    function getByGroups($groupids, &$ctx, $limit) {
        $msgs = [];

        $ctxq = Utils::presdef('arrival', $ctx, NULL) ? (" AND (messages_spatial.arrival < " . $this->dbhr->quote($ctx['arrival']) . " OR messages_spatial.msgid < " . intval($ctx['msgid']) . ")") : '';
        $limitq = $limit ? (" LIMIT " . intval($limit)) : "";
        $ctx = $ctx ? $ctx : [];

        if (count($groupids)) {
            $sql = "SELECT ST_Y(point) AS lat, ST_X(point) AS lng, messages_spatial.msgid AS id, messages_spatial.successful, messages_spatial.promised, messages_spatial.groupid, messages_spatial.msgtype AS type, messages_spatial.arrival FROM messages_spatial WHERE messages_spatial.groupid IN (" . implode(
                    ',',
                    $groupids
                ) . ") $ctxq ORDER BY messages_spatial.arrival DESC, messages_spatial.msgid DESC $limitq;";

            $msgs = $this->dbhr->preQuery($sql);

            # Blur them.
            foreach ($msgs as &$msg) {
                list ($msg['lat'], $msg['lng']) = Utils::blur($msg['lat'], $msg['lng'], Utils::BLUR_USER);
                $msg['arrival'] = Utils::ISODate($msg['arrival']);
                $ctx['msgid'] = $msg['id'];
                $ctx['arrival'] = $msg['arrival'];
            }
        }

        return $msgs;
    }

    function getByIsochrones($userid, $groupid, &$ctx, $limit) {
        $i = new Isochrone($this->dbhr, $this->dbhm);

        # Ensure we have an isochrone to search with.
        $isochrones = $i->list($userid);

        if (!count($isochrones)) {
            # No existing one - create a default one.
            $i->create($userid, NULL, Isochrone::DEFAULT_TIME, NULL, NULL);
        }

        $groupq = $groupid ? " AND messages_spatial.groupid = $groupid" : '';
        $ctxq = Utils::presdef('arrival', $ctx, NULL) ? (" AND (messages_spatial.arrival < " . $this->dbhr->quote($ctx['arrival']) . " OR messages_spatial.msgid < " . intval($ctx['msgid']) . ")") : '';
        $limitq = $limit ? (" LIMIT " . intval($limit)) : "";
        $ctx = $ctx ? $ctx : [];

        # Find any messages within any of the user's isochrones.
        $sql = "SELECT ST_Y(point) AS lat, ST_X(point) AS lng, messages_spatial.msgid AS id, messages_spatial.successful, messages_spatial.promised, messages_spatial.groupid, messages_spatial.msgtype AS type, messages_spatial.arrival
         FROM messages_spatial
         INNER JOIN isochrones ON ST_Contains(isochrones.polygon, point)
         INNER JOIN isochrones_users ON isochrones.id = isochrones_users.isochroneid
        WHERE isochrones_users.userid = ? $ctxq $groupq ORDER BY messages_spatial.arrival DESC, messages_spatial.msgid DESC $limitq;";

        $msgs = $this->dbhr->preQuery($sql, [
            $userid
        ]);

        # Some groups may have postvisibility set, which should restrict whether or not the messages should be visible.
        $groupids = array_filter(array_unique(array_column($msgs, 'groupid')));

        if (count($groupids)) {
            # Find groups we're allowed to show.
            $u = User::get($this->dbhr, $this->dbhm, $userid);
            list ($lat, $lng, $loc) = $u->getLatLng($userid);
            $visibles = $this->dbhr->preQuery("SELECT id FROM `groups` WHERE id IN (" . implode(',', $groupids) . ") AND (postvisibility IS NULL OR ST_Contains(postvisibility, ST_GeomFromText('POINT($lng $lat)', {$this->dbhr->SRID()})));");
            $visibleids = array_column($visibles, 'id');

            # Need to use array_values else we can return an object, rather than an array as the client expects.
            $msgs = array_values(array_filter($msgs, function ($msg) use ($visibleids) {
                return in_array($msg['groupid'], $visibleids);
            }));
        }

        # Blur them.
        foreach ($msgs as &$msg) {
            list ($msg['lat'], $msg['lng']) = Utils::blur($msg['lat'], $msg['lng'], Utils::BLUR_USER);
            $msg['arrival'] = Utils::ISODate($msg['arrival']);
            $ctx['msgid'] = $msg['id'];
            $ctx['arrival'] = $msg['arrival'];
        }

        return $msgs;
    }

}