<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VAPID identity
    |--------------------------------------------------------------------------
    |
    | The keypair this server identifies itself with to the browsers' push
    | services (RFC 8292). Generate one with `php artisan push:vapid`; both
    | halves are base64url, the public key being the 65-byte uncompressed P-256
    | point a client passes as `applicationServerKey`, the private key the raw
    | 32-byte scalar — the same encoding every other Web Push library uses.
    |
    | Without a keypair, push is simply off: the client falls back to raising
    | notifications from its poll loop, exactly as it did before.
    |
    */

    'public_key' => env('VAPID_PUBLIC_KEY'),

    'private_key' => env('VAPID_PRIVATE_KEY'),

    /*
    | Contact address for the push service, sent as the JWT's `sub` claim. Push
    | services use it to reach the operator about a misbehaving application
    | server, so it must be a mailto: or https: URI.
    */

    'subject' => env('VAPID_SUBJECT') ?: 'mailto:'.env('ABUSE_EMAIL', 'abuse@krotze.com'),

    /*
    |--------------------------------------------------------------------------
    | OpenSSL configuration file
    |--------------------------------------------------------------------------
    |
    | Shipped with the app because hosts without an openssl.cnf cannot generate
    | EC keys at all. See the comments in resources/openssl.cnf.
    |
    */

    'openssl_config' => env('OPENSSL_CONF_PATH', resource_path('openssl.cnf')),

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | `ttl` is how long the push service should keep trying to deliver while the
    | device is offline. `timeout` bounds each individual HTTP request — pushes
    | are sent after the response but still inside the request's PHP process
    | (this host cannot keep a queue worker alive), so it has to stay small.
    |
    */

    'ttl' => (int) env('PUSH_TTL', 43200), // 12 hours

    'timeout' => (int) env('PUSH_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Relaying for other servers
    |--------------------------------------------------------------------------
    |
    | A client that holds identities on several Krotze servers can only have
    | one browser push subscription, bound to the server it was installed from.
    | Every other server delivers through that one: it POSTs to the home
    | server's relay URL, which forwards the notification to the device.
    |
    | Because that means this server sends HTTP requests to URLs clients hand
    | it, the target has to be an https URL on a public address by default.
    | Both switches exist for running two instances on one machine while
    | developing; leave them off in production.
    |
    */

    'relay_allow_insecure' => (bool) env('PUSH_RELAY_ALLOW_INSECURE', false),

    'relay_allow_private' => (bool) env('PUSH_RELAY_ALLOW_PRIVATE', false),

    // Notifications one relay request may carry; a channel with more members
    // than this on one home server is delivered in several requests.
    'relay_max_items' => 100,

];
