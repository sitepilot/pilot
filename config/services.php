<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | Credentials for the external services the toolkit talks to.
    |
    */

    'cloudflare' => [
        'token' => env('CLOUDFLARE_API_TOKEN'),
        'default_zone' => env('CLOUDFLARE_DEFAULT_ZONE', 'sitepilot.cloud'),
    ],

    'openprovider' => [
        'username' => env('OPENPROVIDER_USERNAME'),
        'password' => env('OPENPROVIDER_PASSWORD'),
        'default_ns_group' => env('OPENPROVIDER_DEFAULT_NS_GROUP', 'sitepilot-net'),
    ],

];
