<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('m:v:');

if (count($opts) < 1) {
    echo "Usage: php locations_remap.php -m mod -v val\n";
} else {
    $mod = Utils::presdef('m', $opts, 1);
    $val = Utils::presdef('v', $opts, 0);

    $l = new Location($dbhm, $dbhm);
    $l->remapPostcodes(
        'POLYGON((-6.254905965247417 54.104356879846044,-6.414207723059917 54.062466624301564,-6.622947957434917 54.039892816004915,-6.677879598059917 54.10113605358999,-6.644920613684917 54.178366864089675,-6.743797566809917 54.197652068383526,-6.969017293372417 54.39960400790944,-7.045921590247417 54.40280161112125,-7.194237019934917 54.27791028463141,-7.144798543372417 54.23298491488084,-7.309593465247417 54.104356879846044,-7.446922566809917 54.14298728671506,-7.622178910851744 54.13067477063152,-7.693590043664244 54.18857009247667,-7.836412309289244 54.21748738762677,-7.885850785851744 54.3009125998359,-8.171495317101744 54.45448526526139,-7.984727738976744 54.54380419482457,-7.808946488976744 54.518304417645226,-7.693590043664244 54.623388371326705,-7.968248246789244 54.69011899607596,-7.825425981164244 54.737716653613354,-7.704576371789244 54.71551136667413,-7.512315629601744 54.74405878683378,-7.298082231164244 55.05360847422585,-6.699327348351744 55.273130844293846,-7.243150590539244 56.19474812108136,-8.218582731818401 57.32855059357579,-6.548660856818401 59.75828843668784,-0.659988981818401 61.272039722621926,0.13102664318159896 59.91286058964453,-2.285965544318401 58.079863967222174,-0.967606169318401 57.4469722593327,-1.758621794318401 56.440281875964196,1.273604768181599 53.385570534459546,2.679854768181599 52.56528017527635,2.108565705681599 51.291756817415006,0.614425080681599 50.37589364826085,-2.725418669318401 50.09480489134877,-5.977371794318401 49.64161161091736,-6.372879606818401 50.31980844980074,-5.406082731818401 51.20923853200844,-5.669754606818401 52.56528017527635,-5.274246794318401 53.54254128321132,-6.254905965247417 54.104356879846044))', // UK
        //'POLYGON((-2.559988 53.759016,-2.593938 53.78523,-2.614108 53.821416,-2.624747 53.864689,-2.62879 53.889856,-2.564727 53.958177,-2.531102 53.97334,-2.368627 54.029916,-2.227741 53.977875,-2.194616 53.953262,-2.196151 53.948646,-2.236029 53.898542,-2.319991 53.813304,-2.337982 53.808806,-2.499702 53.769736,-2.530049 53.762864,-2.552936 53.759129,-2.559988 53.759016))', // Ribble Valley
    FALSE,
    $mod,
    $val,
    );
}
