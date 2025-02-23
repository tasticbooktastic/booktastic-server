<?php
namespace Booktastic\Iznik;

function invitation()
{
    global $dbhr, $dbhm;

    $me = Session::whoAmI($dbhr, $dbhm);

    $ret = ['ret' => 100, 'status' => 'Unknown verb'];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];
            if ($me) {
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'invitations' => $me->listInvitations()
                ];
            }
            break;
        }

        case 'PUT': {
            $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];
            $email = Utils::presdef('email', $_REQUEST, NULL);

            if ($me && $email) {
                $me->invite($email);

                # Whether or not it worked, say it did.  This is so that if we have someone abusing the feature,
                # they can't tell that we've noticed.
                $ret = [ 'ret' => 0, 'status' => 'Success' ];
            }
            break;
        }

        case 'PATCH': {
            $id = (Utils::presint('id', $_REQUEST, NULL));
            $outcome = Utils::presdef('outcome', $_REQUEST, User::INVITE_ACCEPTED);

            if ($id) {
                $u = new User($dbhr, $dbhm);
                $u->inviteOutcome($id, $outcome);
                $ret = [ 'ret' => 0, 'status' => 'Success' ];
            }
            break;
        }
    }

    return($ret);
}