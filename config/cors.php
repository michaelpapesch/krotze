<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | The API is deliberately open to every origin: requests carry no cookie
    | and are authenticated by a per-request HMAC signature instead, so an
    | origin restriction would protect nothing. Being open is what lets one
    | Krotze client (installed from krotze.com, say) hold identities on
    | several servers at once — see docs/API.md.
    |
    | `Date` is exposed so a cross-origin client can still correct its clock
    | from the response (the same-origin path always could).
    |
    */

    // u/* too: encrypted uploads are fetched with fetch() and decrypted in
    // the browser, also from servers other than the one the page came from.
    // Those are capability URLs, sent without any credential.
    'paths' => ['api/*', 'u/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Date'],

    'max_age' => 0,

    'supports_credentials' => false,

];
