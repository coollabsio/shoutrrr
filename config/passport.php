<?php

use App\Http\Middleware\ForceOAuthAuthorizationFullPage;

return [

    /*
    |--------------------------------------------------------------------------
    | Passport Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Passport will use when
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'web',

    'middleware' => [
        ForceOAuthAuthorizationFullPage::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption Keys
    |--------------------------------------------------------------------------
    |
    | Passport uses encryption keys while generating secure access tokens for
    | your application. By default, the keys are stored as local files but
    | can be set via environment variables when that is more convenient.
    |
    */

    'private_key' => env('PASSPORT_PRIVATE_KEY'),

    'public_key' => env('PASSPORT_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Automatic Key Generation
    |--------------------------------------------------------------------------
    |
    | When no keys are configured above and none exist on disk, Shoutrrr
    | generates the RSA keypair automatically the first time an API key is
    | issued. Set this to false to require the keys be provisioned out of band
    | (via `passport:keys` or the env vars above); issuing a key without keys
    | present will then fail loudly instead of writing new ones.
    |
    */

    'auto_generate_keys' => env('PASSPORT_AUTO_GENERATE_KEYS', true),

    /*
    |--------------------------------------------------------------------------
    | Passport Database Connection
    |--------------------------------------------------------------------------
    |
    | By default, Passport's models will utilize your application's default
    | database connection. If you wish to use a different connection you
    | may specify the configured name of the database connection here.
    |
    */

    'connection' => env('PASSPORT_CONNECTION'),

];
