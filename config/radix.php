<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo sign-in
    |--------------------------------------------------------------------------
    |
    | For demos we don't want people managing credentials. The sign-in screen
    | asks only for a name plus one shared password, and the API hands back a
    | normal Sanctum token - so every other endpoint behaves exactly as it does
    | under real auth. Turn `enabled` off to require real email/password logins.
    |
    */

    'demo_login' => [
        'enabled' => (bool) env('DEMO_LOGIN_ENABLED', true),
        'password' => env('DEMO_LOGIN_PASSWORD', 'Radix123'),

        // An unrecognised name creates a profile on the spot, so a new person at
        // the demo can sign in and immediately land on their New Joiner Quest.
        'auto_create' => (bool) env('DEMO_LOGIN_AUTO_CREATE', true),
    ],

];
