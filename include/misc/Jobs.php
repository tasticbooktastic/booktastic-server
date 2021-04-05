<?php
namespace Freegle\Iznik;

class Jobs {
    /** @public  $dbhr LoggedPDO */
    public $dbhr;
    /** @public  $dbhm LoggedPDO */
    public $dbhm;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function query($lat, $lng, $limit = 50) {
        # To make efficient use of the spatial index we construct a box around our lat/lng, and search for jobs
        # where the geometry overlaps it.  We keep expanding our box until we fix enough.
        $ambit = 0.02;
        $ret = [];
        $got = [];

        do {
            $swlat = $lat - $ambit;
            $nelat = $lat + $ambit;
            $swlng = $lng - $ambit;
            $nelng = $lng + $ambit;

            $poly = "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";
            $sql = "SELECT ST_Distance(geometry, POINT($lng, $lat)) AS dist, ST_Area(geometry) AS area, jobs.* FROM `jobs`
WHERE ST_Intersects(geometry, GeomFromText('$poly')) 
    AND ST_Area(geometry) / ST_Area(GeomFromText('$poly')) < 2
ORDER BY dist ASC, area ASC, posted_at DESC LIMIT $limit;";
            $jobs = $this->dbhr->preQuery($sql);
            #error_log($sql . " found " . count($jobs));

            foreach ($jobs as $job) {
                if (!array_key_exists($job['id'], $got)) {
                    $got[$job['id']] = TRUE;
                    $ret[] = $job;
                }
            }

            $ambit *= 2;
        } while (count($ret) < $limit && $ambit < 1);

        return $ret;
    }
}