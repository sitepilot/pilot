<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | Defaults for the external services the toolkit talks to. Credentials live
    | in the project's pilot.yml (loaded per command); the literals below are
    | only the fallback applied when pilot.yml omits an optional default.
    |
    */

    'cloudflare' => [
        'token' => null,
        'default_zone' => 'sitepilot.cloud',
    ],

    'openprovider' => [
        'username' => null,
        'password' => null,
        'default_ns_group' => 'sitepilot-net',
    ],

];
