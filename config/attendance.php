<?php
// Attendance anti-cheat settings
return [
    // Enforce client clock sanity (prevents desktop time cheating)
    'enforce_client_clock' => true,
    // Max allowed skew between client JS time and server UTC (minutes)
    'max_client_skew_minutes' => 5,

    // If true, only allow clock in/out from IPs starting with one of these prefixes
    'enforce_office_network' => false,
    // Examples: '192.168.1.', '10.0.0.'; leave empty to allow any network
    'allowed_ip_prefixes' => [
        // '192.168.1.',
        // '10.0.0.',
    ],

    // If true, require employee to be within the geofence (any office location below)
    'enforce_geofence' => false,
    // One or more office locations {lat, lng, radius_m}
    'office_locations' => [
        // ['name' => 'HQ', 'lat' => 14.5995, 'lng' => 120.9842, 'radius_m' => 200],
    ],
];
