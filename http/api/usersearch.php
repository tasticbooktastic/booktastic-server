<?php
namespace Booktastic\Iznik;

function usersearch() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    $id = (Utils::presint('id', $_REQUEST, NULL));
    $s = new UserSearch($dbhr, $dbhm, $id);
    $me = Session::whoAmI($dbhr, $dbhm);
    $myid = Session::whoAmId($dbhr, $dbhm);

    $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

    if ($myid) {
        switch ($_REQUEST['type']) {
            case 'GET': {
                if (!$id) {
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'usersearches' => $s->listSearches($myid)
                    ];
                } else {
                    $ret = [ 'ret' => 2, 'status' => 'Permission denied'];

                    # Can see our own, or all if we have permissions.
                    if ($s->getPrivate('userid') == $myid || $me->isAdminOrSupport()) {
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'usersearch' => $s->getPublic()
                        ];
                    }
                }

                break;
            }

            case 'DELETE': {
                if ($s->getPrivate('userid') == $myid || $me->isAdminOrSupport()) {
                    $s->markDeleted();

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success'
                    ];
                }
            }
        }
    }

    return($ret);
}
