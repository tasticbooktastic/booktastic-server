<?php
namespace Booktastic\Iznik;

function jobs() {
    # This proxies a request on to adview to avoid CORS issues.
    global $dbhr, $dbhm;

    $link = Utils::presdef('link', $_REQUEST, NULL);
    $lat = Utils::presfloat('lat', $_REQUEST, NULL);
    $lng = Utils::presfloat('lng', $_REQUEST, NULL);
    $id = Utils::presint('id', $_REQUEST, NULL);
    $category = Utils::presdef('category', $_REQUEST, NULL);

    $me = Session::whoAmI($dbhr, $dbhm);

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $ret = [
                'ret' => 2,
                'status' => 'Invalid parameters'
            ];

            if (!$lat && !$lng && $me) {
                # Default to our own location.
                list ($lat, $lng, $loc) = $me->getLatLng();
            }

            if ($id) {
                $j = new Jobs($dbhr, $dbhm);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'jobs' => $j->get($id)
                ];
            } else if ($lat || $lng) {
                $j = new Jobs($dbhr, $dbhm);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'jobs' => $j->query($lat, $lng, 50, $category)
                ];
            }
            break;
        }

        case 'POST': {
            $j = new Jobs($dbhr, $dbhm);
            $j->recordClick($id, $link, $me ? $me->getId() : NULL);

            $ret = [ 'ret' => 0, 'status' => 'Success' ];
            break;
        }
    }

    return($ret);
}
