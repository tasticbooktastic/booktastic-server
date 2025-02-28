<?php
namespace Booktastic\Iznik;

function item() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    $me = Session::whoAmI($dbhr, $dbhm);

    $id = (Utils::presint('id', $_REQUEST, NULL));
    $typeahead = Utils::presdef('typeahead', $_REQUEST, NULL);
    $minpop = Utils::presint('minpop', $_REQUEST, 5);
    $i = new Item($dbhr, $dbhm, $id);

    if ($id && $i->getId() || $_REQUEST['type'] == 'POST' || $typeahead) {
        switch ($_REQUEST['type']) {
            case 'GET': {
                if ($typeahead) {
                    # This will be quick as we only look for exact words.
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'items' => $i->typeahead(trim($typeahead), $minpop)
                    ];
                } else {
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'item' => $i->getPublic()
                    ];
                }

                break;
            }

            case 'POST': {
                if (!$me) {
                    $ret = ['ret' => 1, 'status' => 'Not logged in'];
                } else {
                    $name = Utils::presdef('name', $_REQUEST, NULL);
                    $systemrole = $me->getPublic()['systemrole'];

                    if (!$name) {
                        $ret = [
                            'ret' => 3,
                            'status' => 'Must supply name'
                        ];
                    } else if ($systemrole != User::SYSTEMROLE_MODERATOR &&
                        $systemrole != User::SYSTEMROLE_SUPPORT &&
                        $systemrole != User::SYSTEMROLE_ADMIN) {
                        $ret = [
                            'ret' => 4,
                            'status' => 'Don\t have rights to create items'
                        ];
                    } else {
                        $name = trim($name);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success',
                            'id' => $i->create($name)
                        ];
                    }
                }

                break;
            }

            case 'PUT':
            case 'PATCH': {
                if (!$me) {
                    $ret = ['ret' => 1, 'status' => 'Not logged in'];
                } else {
                    $systemrole = $me->getPublic()['systemrole'];
                    if ($systemrole != User::SYSTEMROLE_MODERATOR &&
                        $systemrole != User::SYSTEMROLE_SUPPORT &&
                        $systemrole != User::SYSTEMROLE_ADMIN) {
                        $ret = [
                            'ret' => 4,
                            'status' => 'Don\t have rights to modify items'
                        ];
                    } else {
                        $i->setAttributes($_REQUEST);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                    }
                }
                break;
            }

            case 'DELETE': {
                if (!$me) {
                    $ret = ['ret' => 1, 'status' => 'Not logged in'];
                } else {
                    $systemrole = $me->getPublic()['systemrole'];
                    if ($systemrole != User::SYSTEMROLE_MODERATOR &&
                        $systemrole != User::SYSTEMROLE_SUPPORT &&
                        $systemrole != User::SYSTEMROLE_ADMIN) {
                        $ret = [
                            'ret' => 4,
                            'status' => 'Don\t have rights to modify items'
                        ];
                    } else {
                        $i->delete();
                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                    }
                }
            }
        }
    } else {
        $ret = [
            'ret' => 2,
            'status' => 'Invalid item id'
        ];
    }

    return($ret);
}
