<?php

namespace Tests\Feature;

use App\Models\ChatUser;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebPushTest extends TestCase
{
    use RefreshDatabase;
    use SignsChatRequests;

    /** A syntactically valid subscription; the endpoint is never contacted here. */
    private const SUBSCRIPTION = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/exampleEndpointToken',
        'keys' => [
            'p256dh' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
            'auth' => 'BTBZMqHH6r4Tts7J_aSIgg',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway keypair, so the suite never depends on the .env of
        // whoever is running it.
        $keys = WebPush::generateVapidKeys();
        config(['push.public_key' => $keys['public'], 'push.private_key' => $keys['private']]);
    }

    public function test_the_public_key_is_served_without_credentials(): void
    {
        $this->getJson('/api/push/key')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('public_key', config('push.public_key'));
    }

    public function test_push_reports_itself_off_when_no_keypair_is_configured(): void
    {
        config(['push.public_key' => null, 'push.private_key' => null]);

        $this->getJson('/api/push/key')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('public_key', null);
    }

    public function test_push_reports_itself_off_when_the_admin_switched_it_off(): void
    {
        Setting::put('push_enabled', false);

        $this->getJson('/api/push/key')->assertOk()->assertJsonPath('enabled', false);
    }

    public function test_a_device_can_subscribe_update_and_unsubscribe(): void
    {
        $cred = $this->register();

        $this->signed($cred, 'POST', '/api/push/subscribe', self::SUBSCRIPTION + ['hide_message_text' => true])
            ->assertStatus(201)
            ->assertJsonPath('preferences.notify_messages', true)
            ->assertJsonPath('preferences.hide_message_text', true);

        $subscription = PushSubscription::firstOrFail();
        $this->assertSame($cred['user_id'], $subscription->user_id);
        $this->assertSame(self::SUBSCRIPTION['endpoint'], $subscription->endpoint);

        $this->signed($cred, 'PATCH', '/api/push/subscription', ['notify_messages' => false])
            ->assertOk()
            ->assertJsonPath('preferences.notify_messages', false)
            // Untouched fields keep their value.
            ->assertJsonPath('preferences.hide_message_text', true);

        $this->signed($cred, 'DELETE', '/api/push/subscription')->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_subscribing_again_replaces_this_devices_previous_endpoint(): void
    {
        $cred = $this->register();

        $this->signed($cred, 'POST', '/api/push/subscribe', self::SUBSCRIPTION)->assertStatus(201);

        $rotated = self::SUBSCRIPTION;
        $rotated['endpoint'] = 'https://fcm.googleapis.com/fcm/send/aDifferentToken';
        $this->signed($cred, 'POST', '/api/push/subscribe', $rotated)->assertStatus(201);

        // One row, pointing at the new endpoint — a browser that rotates its
        // endpoint must not leave a dead one behind receiving nothing.
        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($rotated['endpoint'], PushSubscription::first()->endpoint);
    }

    public function test_revoking_a_device_takes_its_subscription_with_it(): void
    {
        $first = $this->register();
        $this->signed($first, 'POST', '/api/push/subscribe', self::SUBSCRIPTION)->assertStatus(201);

        // Pair a second device, then have it revoke the first.
        $code = $this->signed($first, 'POST', '/api/profile/devices/pair')->assertOk()->json('code');
        $second = $this->postJson('/api/devices/claim', ['code' => $code, 'device_name' => 'Second'])
            ->assertStatus(201)->json();

        $deviceId = collect($this->signed($second, 'GET', '/api/profile')->json('devices'))
            ->firstWhere('current', false)['id'];

        $this->signed($second, 'DELETE', "/api/profile/devices/{$deviceId}")->assertOk();

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_updating_without_a_subscription_says_so(): void
    {
        $cred = $this->register();

        $this->signed($cred, 'PATCH', '/api/push/subscription', ['notify_messages' => false])->assertStatus(404);
        $this->signed($cred, 'POST', '/api/push/test')->assertStatus(404);
    }

    public function test_a_malformed_subscription_is_refused(): void
    {
        $cred = $this->register();

        $this->signed($cred, 'POST', '/api/push/subscribe', ['endpoint' => 'not-a-url'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    }

    public function test_subscriptions_are_never_echoed_back_with_their_keys(): void
    {
        $cred = $this->register();
        $this->signed($cred, 'POST', '/api/push/subscribe', self::SUBSCRIPTION)->assertStatus(201);

        // p256dh and auth are what let anybody encrypt for that device; they go
        // in and never come out.
        $body = $this->signed($cred, 'PATCH', '/api/push/subscription', [])->assertOk()->content();
        $this->assertStringNotContainsString(self::SUBSCRIPTION['keys']['p256dh'], $body);
        $this->assertStringNotContainsString(self::SUBSCRIPTION['keys']['auth'], $body);
    }

    /* ---------------------------- the crypto ------------------------------ */

    public function test_encryption_matches_the_rfc_8291_test_vector(): void
    {
        // RFC 8291 section 5, with the ephemeral key and salt pinned to the
        // values the RFC uses. The subscriber's private key lets the test
        // decrypt from the other side, which is what a browser does.
        $uaPrivate = WebPush::unb64('q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94');
        $uaPublic = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
        $auth = 'BTBZMqHH6r4Tts7J_aSIgg';
        $plaintext = 'When I grow up, I want to be a watermelon';

        $body = WebPush::encrypt($uaPublic, $auth, $plaintext, [
            'scalar' => WebPush::unb64('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'),
            'point' => WebPush::unb64('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8'),
            'salt' => WebPush::unb64('DGv6ra1nlYgDCS1FRnbzlw'),
        ]);

        // RFC 8188 header: salt(16) + record size(4) + key id length(1) + key id.
        $this->assertSame(144, strlen($body), 'Header plus padded ciphertext plus GCM tag.');
        $this->assertSame('DGv6ra1nlYgDCS1FRnbzlw', WebPush::b64(substr($body, 0, 16)));
        $this->assertSame(4096, unpack('N', substr($body, 16, 4))[1]);
        $this->assertSame(65, ord($body[20]));

        $this->assertSame($plaintext, $this->decryptAsSubscriber($body, $uaPrivate, WebPush::unb64($uaPublic), $auth));
    }

    public function test_a_random_payload_round_trips(): void
    {
        // Same check without the pinned values, so the ephemeral keypair path
        // is exercised too.
        $ua = WebPush::generateVapidKeys(); // any P-256 keypair will do
        $auth = WebPush::b64(random_bytes(16));
        $plaintext = json_encode(['title' => 'Lobby · alice', 'body' => 'see you at eight']);

        $body = WebPush::encrypt($ua['public'], $auth, $plaintext);

        $this->assertSame($plaintext, $this->decryptAsSubscriber(
            $body, WebPush::unb64($ua['private']), WebPush::unb64($ua['public']), $auth,
        ));
    }

    public function test_an_oversized_payload_is_refused(): void
    {
        $ua = WebPush::generateVapidKeys();

        $this->expectExceptionMessage('too large');
        WebPush::encrypt($ua['public'], WebPush::b64(random_bytes(16)), str_repeat('x', 5000));
    }

    public function test_the_vapid_header_is_a_signed_es256_token_for_the_endpoint(): void
    {
        $header = WebPush::vapidHeader('https://updates.push.services.mozilla.com/wpush/v2/abc');

        $this->assertStringStartsWith('vapid t=', $header);
        [$token, $key] = explode(',k=', substr($header, strlen('vapid t=')));
        [$head, $claims, $signature] = explode('.', $token);

        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(WebPush::unb64($head), true));

        $payload = json_decode(WebPush::unb64($claims), true);
        // The audience is the push service's origin and nothing more — a token
        // minted for one service must not be usable at another.
        $this->assertSame('https://updates.push.services.mozilla.com', $payload['aud']);
        $this->assertGreaterThan(time(), $payload['exp']);
        $this->assertLessThanOrEqual(time() + 86400, $payload['exp']);
        $this->assertStringStartsWith('mailto:', $payload['sub']);

        $this->assertSame(64, strlen(WebPush::unb64($signature)), 'JWS wants raw r||s, not DER.');
        $this->assertSame(config('push.public_key'), $key);
    }

    /* ------------------------------ helpers ------------------------------- */

    /**
     * Decrypt a push body the way the subscribing browser would: from its own
     * private key and the ephemeral key carried in the header. Written from the
     * RFC rather than by reusing WebPush's internals, so agreement means more
     * than a symmetrical mistake.
     */
    private function decryptAsSubscriber(string $body, string $uaPrivate, string $uaPublic, string $auth): string
    {
        $salt = substr($body, 0, 16);
        $asPublic = substr($body, 21, ord($body[20]));
        $sealed = substr($body, 21 + ord($body[20]));

        $der = "\x30\x77\x02\x01\x01\x04\x20".$uaPrivate
            ."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\xa1\x44\x03\x42\x00".$uaPublic;
        $key = openssl_pkey_get_private($this->pem($der, 'EC PRIVATE KEY'));

        $spki = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
            ."\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00".$asPublic;
        $shared = openssl_pkey_derive(openssl_pkey_get_public($this->pem($spki, 'PUBLIC KEY')), $key);

        $hkdf = function (string $salt, string $ikm, string $info, int $length) {
            return substr(hash_hmac('sha256', $info."\x01", hash_hmac('sha256', $ikm, $salt, true), true), 0, $length);
        };

        $ikm = $hkdf(WebPush::unb64($auth), $shared, "WebPush: info\x00".$uaPublic.$asPublic, 32);
        $cek = $hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = $hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

        $plaintext = openssl_decrypt(
            substr($sealed, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($sealed, -16),
        );

        $this->assertNotFalse($plaintext, 'The GCM tag did not verify.');

        return rtrim($plaintext, "\x02");
    }

    private function pem(string $der, string $label): string
    {
        return "-----BEGIN {$label}-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END {$label}-----\n";
    }

    private function register(): array
    {
        $cred = $this->postJson('/api/session', ['device_name' => 'Test'])->json();
        $this->signed($cred, 'POST', '/api/profile/username', ['username' => 'pusher-'.$cred['user_id']]);

        return $cred;
    }
}
