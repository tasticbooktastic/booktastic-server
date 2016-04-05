<?php
$scriptstart = microtime(true);

$_SERVER['REQUEST_METHOD'] = strtoupper($_SERVER['REQUEST_METHOD']);
$_REQUEST['type'] = $_SERVER['REQUEST_METHOD'];

if (array_key_exists('HTTP_X_HTTP_METHOD_OVERRIDE', $_SERVER)) {
    # Used by Backbone's emulateHTTP to work around servers which don't handle verbs like PATCH very well.
    #
    # We use this because when we issue a PATCH we don't seem to be able to get the body parameters.
    $_REQUEST['type'] = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'];
    #error_log("Request method override to {$_REQUEST['type']}");
}

require_once('../../include/misc/apiheaders.php');
require_once('../../include/config.php');

if (file_exists(IZNIK_BASE . '/http/maintenance_on.html')) {
    // @codeCoverageIgnoreStart - hard to test exit
    echo json_encode(array('ret' => 111, 'status' => 'Down for maintenance'));
    exit(0);
    // @codeCoverageIgnoreEnd
}

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;
require_once(IZNIK_BASE . '/include/session/Session.php');
require_once(IZNIK_BASE . '/include/session/Yahoo.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/dashboard/Dashboard.php');
require_once(IZNIK_BASE . '/include/message/MessageCollection.php');
require_once(IZNIK_BASE . '/include/message/Item.php');
require_once(IZNIK_BASE . '/include/user/MembershipCollection.php');
require_once(IZNIK_BASE . '/include/user/Notifications.php');
require_once(IZNIK_BASE . '/include/chat/Rooms.php');
require_once(IZNIK_BASE . '/include/chat/Messages.php');
require_once(IZNIK_BASE . '/include/misc/Supporters.php');
require_once(IZNIK_BASE . '/include/mail/MailRouter.php');
require_once(IZNIK_BASE . '/include/misc/plugin.php');
require_once(IZNIK_BASE . '/include/misc/Image.php');
require_once(IZNIK_BASE . '/include/misc/Search.php');
require_once(IZNIK_BASE . '/include/config/ModConfig.php');
require_once(IZNIK_BASE . '/include/config/StdMessage.php');
require_once(IZNIK_BASE . '/include/config/BulkOp.php');
require_once(IZNIK_BASE . '/lib/UploadHandler.php');
require_once(IZNIK_BASE . '/include/misc/CustomUploadHandler.php');

# Include each API call
require_once(IZNIK_BASE . '/http/api/session.php');
require_once(IZNIK_BASE . '/http/api/modconfig.php');
require_once(IZNIK_BASE . '/http/api/stdmsg.php');
require_once(IZNIK_BASE . '/http/api/bulkop.php');
require_once(IZNIK_BASE . '/http/api/comment.php');
require_once(IZNIK_BASE . '/http/api/dashboard.php');
require_once(IZNIK_BASE . '/http/api/messages.php');
require_once(IZNIK_BASE . '/http/api/message.php');
require_once(IZNIK_BASE . '/http/api/item.php');
require_once(IZNIK_BASE . '/http/api/memberships.php');
require_once(IZNIK_BASE . '/http/api/spammers.php');
require_once(IZNIK_BASE . '/http/api/supporters.php');
require_once(IZNIK_BASE . '/http/api/group.php');
require_once(IZNIK_BASE . '/http/api/groups.php');
require_once(IZNIK_BASE . '/http/api/plugin.php');
require_once(IZNIK_BASE . '/http/api/user.php');
require_once(IZNIK_BASE . '/http/api/chatrooms.php');
require_once(IZNIK_BASE . '/http/api/chatmessages.php');
require_once(IZNIK_BASE . '/http/api/locations.php');
require_once(IZNIK_BASE . '/http/api/image.php');
require_once(IZNIK_BASE . '/http/api/upload.php');

$includetime = microtime(true) - $scriptstart;

# All API calls come through here.
#error_log("Request " . var_export($_REQUEST, TRUE));
#error_log("Server " . var_export($_SERVER, TRUE));

if (array_key_exists('model', $_REQUEST)) {
    # Used by Backbone's emulateJSON to work around servers which don't handle requests encoded as
    # application/json.
    $_REQUEST = array_merge($_REQUEST, json_decode($_REQUEST['model'], true));
    unset($_REQUEST['model']);
}

$call = pres('call', $_REQUEST);

if ($_REQUEST['type'] == 'OPTIONS') {
    # We don't bother returning different values for different calls.
    http_response_code(204);
    @header('Allow: POST, GET, DELETE, PUT');
    @header('Access-Control-Allow-Methods:  POST, GET, DELETE, PUT');
} else {
    # Actual API calls
    $ret = array('ret' => 1000, 'status' => 'Invalid API call');
    $t = microtime(true);
    $whoamitime = microtime(true) - $t;

    # We wrap the whole request in a retry handler.  This is so that we can deal with errors caused by
    # conflicts within the Percona cluster.
    $apicallretries = 0;

    do {
        # Duplicate POST protection
        if ((DUPLICATE_POST_PROTECTION > 0) && array_key_exists('REQUEST_METHOD', $_SERVER) && ($_REQUEST['type'] == 'POST')) {
            $req = $_SERVER['REQUEST_URI'] . serialize($_REQUEST);

            # Repeat logins are OK.
            #
            # So are correlations, which are repeatable without ill effects.
            if (($call != 'session') && ($call != 'correlate') &&
                array_key_exists('POSTLASTTIME', $_SESSION)) {
                $ago = time() - $_SESSION['POSTLASTTIME'];

                if (($ago < DUPLICATE_POST_PROTECTION) && ($req == $_SESSION['POSTLASTDATA'])) {
                    $ret = array('ret' => 999, 'text' => 'Duplicate request - rejected.', 'data' => $_REQUEST);
                    echo json_encode($ret);
                    break;
                }
            }

            $_SESSION['POSTLASTTIME'] = time();
            $_SESSION['POSTLASTDATA'] = $req;
        }

        try {
            # Each call is inside a file with a suitable name.
            #
            # call_user_func doesn't scale well on multicores with HHVM, so we need can't figure out the function from
            # the call name - use a switch instead.
            switch ($call) {
                case 'dashboard':
                    $ret = dashboard();
                    break;
                case 'exception':
                    # For UT
                    throw new Exception();
                case 'image':
                    $ret = image();
                    break;
                case 'upload':
                    $ret = upload();
                    break;
                case 'messages':
                    $ret = messages();
                    break;
                case 'message':
                    $ret = message();
                    break;
                case 'item':
                    $ret = item();
                    break;
                case 'memberships':
                    $ret = memberships();
                    break;
                case 'spammers':
                    $ret = spammers();
                    break;
                case 'plugin':
                    $ret = plugin();
                    break;
                case 'session':
                    $ret = session();
                    break;
                case 'supporters':
                    $ret = supporters();
                    break;
                case 'group':
                    $ret = group();
                    break;
                case 'groups':
                    $ret = groups();
                    break;
                case 'locations':
                    $ret = locations();
                    break;
                case 'modconfig':
                    $ret = modconfig();
                    break;
                case 'stdmsg':
                    $ret = stdmsg();
                    break;
                case 'bulkop':
                    $ret = bulkop();
                    break;
                case 'comment':
                    $ret = comment();
                    break;
                case 'user':
                    $ret = user();
                    break;
                case 'chatrooms':
                    $ret = chatrooms();
                    break;
                case 'chatmessages':
                    $ret = chatmessages();
                    break;
                case 'echo':
                    $ret = array_merge($_REQUEST, $_SERVER);
                    break;
                case 'DBexceptionWork':
                    # For UT
                    if ($apicallretries < 2) {
                        error_log("Fail DBException $apicallretries");
                        throw new DBException();
                    }

                    break;
                case 'DBexceptionFail':
                    # For UT
                    throw new DBException();
            }

            # If we get here, everything worked.
            if ($call == 'upload') {
                # Output is handled within the lib.
            } else if (pres('img', $ret)) {
                # This is an image we want to output.  Can cache forever - if an image changes it would get a new id
                @header('Content-Type: image/jpeg');
                @header('Content-Length: ' . strlen($ret['img']));
                @header('Cache-Control: max-age=315360000');
                print $ret['img'];
            } else {
                # This is a normal API call.  Add profiling info.
                $ret['call'] = $call;
                $ret['type'] = $_REQUEST['type'];
                $ret['session'] = session_id();
                $ret['duration'] = (microtime(true) - $scriptstart);
                $ret['cpucost'] = getCpuUsage();
                $ret['dbwaittime'] = $dbhr->getWaitTime() + $dbhm->getWaitTime();
                $ret['includetime'] = $includetime;
                $ret['whoamitime'] = $whoamitime;

                filterResult($ret);
                $str = json_encode($ret);
                echo $str;
            }

            if ($apicallretries > 0) {
                error_log("API call $call worked after $apicallretries");
            }

            if (BROWSERTRACKING && ($call != 'event_save') && (presdef('type', $_REQUEST, NULL) != 'GET')) {
                # Save off the API call and result, except for the (very frequent) event tracking calls.  Don't
                # save GET calls as they don't change the DB and there are a lot of them.
                #
                # Beanstalk has a limit on the size of job that it accepts; no point trying to log absurdly large
                # API requests.
                $req = json_encode($_REQUEST);
                $rsp = json_encode($ret);

                if (strlen($req) + strlen($rsp) > 180000) {
                    $req = substr($req, 0, 1000);
                    $rsp = substr($rsp, 0, 1000);
                }

                $sql = "INSERT INTO logs_api (`userid`, `ip`, `session`, `request`, `response`) VALUES (" . presdef('id', $_SESSION, 'NULL') . ", '" . presdef('REMOTE_ADDR', $_SERVER, '') . "', " . $dbhr->quote(session_id()) .
                    ", " . $dbhr->quote($req) . ", " . $dbhr->quote($rsp) . ");";
                $dbhm->background($sql);
            }

            break;
        } catch (Exception $e) {
            # This is our retry handler - see apiheaders.
            if ($e instanceof DBException) {
                # This is a DBException.  We want to retry, which means we just go round the loop
                # again.
                error_log("DB Exception try $apicallretries," . $e->getMessage() . ", " . $e->getTraceAsString());
                $apicallretries++;

                if ($apicallretries >= API_RETRIES) {
                    echo json_encode(array('ret' => 997, 'status' => 'DB operation failed after retry', 'exception' => $e->getMessage()));
                }
            } else {
                # Something else.
                error_log("Uncaught exception at " . $e->getFile() . " line " . $e->getLine() . " " . $e->getMessage());
                echo json_encode(array('ret' => 998, 'status' => 'Unexpected error', 'exception' => $e->getMessage()));
                break;
            }

            # Make sure the duplicate POST detection doesn't throw us.
            unset($_SESSION['POSTLASTTIME']);
        }
    } while ($apicallretries < API_RETRIES);
}
