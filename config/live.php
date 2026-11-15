<?php

/*
 * Live streams (voice, camera, screen sharing).
 *
 * Media is relayed by a small WebSocket server that ships with the app:
 * `php artisan live:serve`. Browsers send their encoded audio/video to it and
 * it fans the frames out to everybody who has the channel open — clients never
 * connect to each other. The feature is off until LIVE_URL is set, and hides
 * itself whenever the server process is not running.
 */

return [

    // The public address browsers connect to, e.g. wss://example.com/live
    // (behind a reverse proxy) or wss://example.com:8090 (direct TLS, below).
    // Pages served over https can only reach wss:// addresses.
    'url' => env('LIVE_URL'),

    // Where the server process listens.
    'host' => env('LIVE_HOST', '127.0.0.1'),
    'port' => (int) env('LIVE_PORT', 8090),

    // Optional: terminate TLS in the live server itself (PEM files) when no
    // reverse proxy can forward WebSocket upgrades to it.
    'tls_cert' => env('LIVE_TLS_CERT'),
    'tls_key' => env('LIVE_TLS_KEY'),

    // Hard limits enforced by the relay.
    'max_connections' => (int) env('LIVE_MAX_CONNECTIONS', 300),
    'max_frame_bytes' => 1024 * 1024,           // one encoded video frame
    'max_bytes_per_10s' => 6 * 1024 * 1024,     // what one streamer may send (~4.8 Mbit/s)

];
