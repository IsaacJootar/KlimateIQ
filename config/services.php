<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        // Optional explicit path to a CA certificate bundle. Left unset, the client
        // auto-detects one, which keeps SSL working on a dev machine whose php.ini has no
        // curl.cainfo configured.
        'ca_bundle' => env('OPENAI_CA_BUNDLE'),
    ],

    'termii' => [
        'api_key' => env('TERMII_API_KEY'),
        'sender_id' => env('TERMII_SENDER_ID', 'KlimateIQ'),
        'base_url' => env('TERMII_BASE_URL', 'https://api.ng.termii.com'),
    ],

    'earthdata' => [
        'username' => env('NASA_EARTHDATA_USERNAME'),
        'password' => env('NASA_EARTHDATA_PASSWORD'),
    ],

    'firms' => [
        // NASA FIRMS active-fire API map key — free, instant, from
        // https://firms.modaps.eosdis.nasa.gov/api/map_key/. Feeds the ACTIVE_FIRE
        // confirmation series behind Wildfire Risk. Unset = the ingestion service is a no-op.
        'map_key' => env('FIRMS_MAP_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
