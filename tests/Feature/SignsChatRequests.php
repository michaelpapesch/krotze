<?php

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;

/**
 * The chat API carries no credential — every call is signed with the device
 * secret handed out by /api/session. This mirrors what resources/js/app.js
 * does, so tests can talk to the API the way a real client does.
 */
trait SignsChatRequests
{
    /** @param  array{device_id: string, secret: string}  $cred */
    protected function signed(array $cred, string $method, string $path, array $body = []): TestResponse
    {
        // Laravel's test client JSON-encodes the payload for every method, so
        // the signature covers that same string — a browser omits the body on
        // a GET and signs the hash of '' instead; either way both ends agree.
        $payload = json_encode($body);
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = implode("\n", [
            $method, $path, $ts, $nonce, hash('sha256', $payload),
        ]);

        return $this->withHeaders([
            'X-Chat-Device' => $cred['device_id'],
            'X-Chat-Ts' => $ts,
            'X-Chat-Nonce' => $nonce,
            'X-Chat-Sig' => hash_hmac('sha256', $canonical, $cred['secret']),
            'Accept' => 'application/json',
        ])->json($method, $path, $body);
    }
}
