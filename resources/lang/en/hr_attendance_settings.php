<?php

return [
    'title' => 'Attendance Settings',
    'authoritative_help' => 'These branch coordinates, radius, accuracy, and policy are enforced by the server for self-service attendance.',
    'empty' => 'There are no active branches in the current company.',
    'fields' => ['map_url' => 'Google Maps location URL'],
    'placeholders' => ['map_url' => 'Paste the place URL from Google Maps'],
    'map_url_help' => 'Paste a Google Maps URL or use this device location; latitude and longitude are filled automatically.',
    'actions' => ['capture' => 'Use My Current Location', 'resolve_url' => 'Extract Coordinates'],
    'messages' => ['updated' => 'Attendance settings were updated.', 'location_unavailable' => 'The browser could not obtain the current location.', 'locating' => 'Detecting the current location…', 'resolving' => 'Reading the map URL…', 'resolved' => 'Latitude and longitude were filled automatically.', 'resolve_failed' => 'Coordinates could not be extracted from this URL.'],
    'validation' => ['google_maps_url' => 'Enter a valid Google Maps location URL.', 'coordinates_not_found' => 'Latitude and longitude could not be found in this map URL.'],
];
