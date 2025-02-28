<?php

namespace Booktastic\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# This is for updates to the PAF file.  Don't run it for the initial load - it's too slow.

$locs = $dbhr->preQuery("SELECT DISTINCT locations.* FROM users_searches INNER JOIN locations ON locations.id = users_searches.locationid WHERE locationid IS NOT NULL;");
$total = count($locs);

error_log("Check for locations outside group");
$outsides = [];
$count = 0;

// Creates the Document.
$dom = new \DOMDocument('1.0', 'UTF-8');

// Creates the root KML element and appends it to the root document.
$node = $dom->createElementNS('http://earth.google.com/kml/2.1', 'kml');
$parNode = $dom->appendChild($node);

// Creates a KML Document element and append it to the KML element.
$dnode = $dom->createElement('Document');
$docNode = $parNode->appendChild($dnode);

foreach ($locs as $loc) {
    $groups = $dbhr->preQuery("SELECT * FROM `groups` WHERE (poly IS NOT NULL AND ST_Intersects(ST_GeomFromText(poly, {$dbhr->SRID()}), ?)) OR (polyofficial IS NOT NULL AND ST_INTERSECTS(ST_GeomFromText(polyofficial, {$dbhr->SRID()}), ?)) LIMIT 1;", [
        $loc['geometry'],
        $loc['geometry']
    ]);

    if (count($groups) == 0) {
        error_log("{$loc['id']} {$loc['name']}");
        $outsides[] = $loc['id'];

        $node = $dom->createElement('Placemark');
        $placeNode = $docNode->appendChild($node);

        // Creates an id attribute and assign it the value of id column.
        $placeNode->setAttribute('id', 'placemark' . $loc['id']);

        // Create name, and description elements and assigns them the values of the name and address columns from the results.
        $nameNode = $dom->createElement('name',htmlentities($loc['name']));
        $placeNode->appendChild($nameNode);

        // Creates a Point element.
        $pointNode = $dom->createElement('Point');
        $placeNode->appendChild($pointNode);

        // Creates a coordinates element and gives it the value of the lng and lat columns from the results.
        $coorStr = $loc['lng'] . ','  . $loc['lat'];
        $coorNode = $dom->createElement('coordinates', $coorStr);
        $pointNode->appendChild($coorNode);
    }

    $count++;

    if ($count % 1000 === 0) {
        error_log("$count / $total");
    }
}

$kmlOutput = $dom->saveXML();
file_put_contents('/tmp/nogroups.kml', $kmlOutput);
