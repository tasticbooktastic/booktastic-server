<?php
namespace Booktastic\Iznik;

function request() {
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);
    $myid = Session::whoAmId($dbhr, $dbhm);

    $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

    if ($myid) {
        $id = (Utils::presint('id', $_REQUEST, NULL));
        $r = new Request($dbhr, $dbhm, $id);
        $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

        switch ($_REQUEST['type']) {
            case 'GET': {
                $outstanding = array_key_exists('outstanding', $_REQUEST) ? filter_var($_REQUEST['outstanding'], FILTER_VALIDATE_BOOLEAN) : FALSE;
                $recent = array_key_exists('recent', $_REQUEST) ? filter_var($_REQUEST['recent'], FILTER_VALIDATE_BOOLEAN) : FALSE;
                $recentid = Utils::presint('recentid', $_REQUEST, NULL);

                if ($id) {
                    $ret = ['ret' => 3, 'status' => 'Access denied'];

                    # We can see those if they are our own, or we're a mod for that user.
                    if ($r->getPrivate('userid') == $myid || $me->moderatorForUser($r->getPrivate('userid')) || $me->isAdmin()) {
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'request' => $r->getPublic()
                        ];
                    }
                } else if ($outstanding) {
                    # List outstanding requests.
                    $ret = [
                        'status' => 'Success',
                        'ret' => 0,
                        'requests' => $me->hasPermission(User::PERM_BUSINESS_CARDS) ? $r->listOutstanding() : []
                    ];
                } else if ($recent) {
                    # List recently completed requests.
                    $ret = [
                        'status' => 'Success',
                        'ret' => 0,
                        'recent' => $me->hasPermission(User::PERM_BUSINESS_CARDS) ? $r->listRecent($recentid) : []
                    ];
                } else {
                    # List all for this user.
                    $ret = [
                        'status' => 'Success',
                        'ret' => 0,
                        'requests' => $r->listForUser($myid)
                    ];
                }
                break;
            }

            case 'PUT':
                $id = $r->create($myid,
                    Utils::presdef('reqtype', $_REQUEST, NULL),
                    (Utils::presint('addressid', $_REQUEST, NULL)),
                    Utils::presdef('to', $_REQUEST, NULL));

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'id' => $id
                ];
                break;

            case 'POST':
                $action = Utils::presdef('action', $_REQUEST, NULL);
                switch ($action) {
                    case 'Completed': $r->completed($myid); break;
                }

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'id' => $id
                ];
                break;

            case 'PATCH': {
                $ret = ['ret' => 1, 'status' => 'Not logged in'];

                if ($r->getPrivate('userid') == $myid || $me->moderatorForUser($r->getPrivate('userid')) || $me->isAdmin()) {
                    $r->setAttributes($_REQUEST);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                }
                break;
            }

            case 'DELETE': {
                $ret = ['ret' => 1, 'status' => 'Not logged in'];

                if ($r->getPrivate('userid') == $myid || $me->hasPermission(User::PERM_BUSINESS_CARDS)) {
                    $r->delete();

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                }
                break;
            }
        }
    }

    return($ret);
}
