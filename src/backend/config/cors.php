<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| Without this file, Laravel's HandleCors middleware resolves `paths` to an
| empty array and adds no CORS headers to any response — which silently
| breaks every browser-based call to this API, since the frontend (Vite
| dev server) and this backend run on different origins/ports. Auth here
| is a Bearer token in a header, not a cookie, so a permissive origin list
| carries none of the CSRF risk it would with cookie-based auth.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', '*'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
