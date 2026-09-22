<?php

namespace Tests\Feature;

use App\Models\PushRelay;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Support\PushSender;
use App\Support\RelayUrl;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Push for a client that lives on several servers: the home server mints
 * relay tokens, the foreign server subscribes with them and delivers through
 * the home server in bulk.
 */
class PushRelayTest extends TestCase
{
    use RefreshDatabase;
    use SignsChatRequests;

    private const SUBSCRIPTION = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/exampleEndpointToken',
        'keys' => [
            'p256dh' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
            'auth' => 'BTBZMqHH6r4Tts7J_aSIgg',
        ],
    ];

    private const RELAY_URL = 'https://home.example/api/push/relay/deliver';

    /** @var array<int, array> every request the fake transport saw */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $keys = WebPush::generateVapidKeys();
        config([
            'push.public_key' => $keys['public'],
            'push.private_key' => $keys['private'],
            'push.relay_allow_private' => true,
            'push.relay_allow_insecure' => true,
        ]);

        WebPush::fakeTransport(function (array $requests) {
            $this->sent[] = $requests;

            return array_map(fn () => ['status' => 201, 'body' => ''], $requests);
        });
    }

    protected function tearDown(): void
    {
        WebPush::fakeTransport(null);
        parent::tearDown();
    }

    private function device(string $name = 'Test'): array
    {
        return $this->postJson('/api/session', ['device_name' => $name])->assertStatus(201)->json();
    }

    /* ------------------------------ home side ------------------------------ */

    public function test_a_device_can_mint_rotate_and_withdraw_a_relay(): void
    {
        $cred = $this->device();

        $first = $this->signed($cred, 'POST', '/api/push/relay', ['origin' => 'https://Other.Example:443/'])
            ->assertStatus(201)
            ->assertJsonPath('relay_url', url(RelayUrl::PATH))
            ->assertJsonPath('origin', 'https://other.example')
            ->json();
        $this->assertSame(48, strlen($first['relay_token']));
        $this->assertDatabaseHas('push_relays', ['token_hash' => PushRelay::hash($first['relay_token'])]);

        // Same origin again: one row, new token, old one dead.
        $second = $this->signed($cred, 'POST', '/api/push/relay', ['origin' => 'https://other.example'])
            ->assertStatus(201)->json();
        $this->assertNotSame($first['relay_token'], $second['relay_token']);
        $this->assertSame(1, PushRelay::count());
        $this->assertDatabaseMissing('push_relays', ['token_hash' => PushRelay::hash($first['relay_token'])]);

        $this->signed($cred, 'DELETE', '/api/push/relay', ['origin' => 'https://other.example'])->assertOk();
        $this->assertSame(0, PushRelay::count());
    }

    public function test_a_relay_origin_must_be_bare(): void
    {
        $cred = $this->device();

        foreach (['https://other.example/app', 'other.example', 'ftp://x', 'https://u:p@other.example', 'https://other.example?x'] as $bad) {
            $this->signed($cred, 'POST', '/api/push/relay', ['origin' => $bad])->assertStatus(422);
        }
    }

    public function test_relayed_items_are_delivered_through_the_devices_subscription_with_their_origin(): void
    {
        $cred = $this->device();
        $this->signed($cred, 'POST', '/api/push/subscribe', self::SUBSCRIPTION)->assertStatus(201);
        $token = $this->signed($cred, 'POST', '/api/push/relay', ['origin' => 'https://other.example'])->json('relay_token');

        $this->postJson('/api/push/relay/deliver', ['items' => [
            ['token' => $token, 'title' => 'Lobby · alice', 'body' => 'hi', 'tag' => 'chan-1', 'uuid' => 'abc',
                // An impostor field: the origin comes from our row, never the caller.
                'origin' => 'https://evil.example'],
            ['token' => str_repeat('x', 48), 'title' => 'nobody'],
        ]])
            ->assertStatus(202)
            ->assertJsonPath('results.'.$token, 'ok')
            ->assertJsonPath('results.'.str_repeat('x', 48), 'gone');

        $this->assertNotNull(PushRelay::first()->last_used_at);

        // Delivery ran after the response, straight to the browser endpoint.
        $this->assertCount(1, $this->sent);
        $request = array_values($this->sent[0])[0];
        $this->assertSame(self::SUBSCRIPTION['endpoint'], $request['url']);
        $this->assertStringContainsString('aes128gcm', implode("\n", $request['headers']));
    }

    public function test_without_a_browser_subscription_the_relay_says_so_and_keeps_the_token(): void
    {
        $cred = $this->device();
        $token = $this->signed($cred, 'POST', '/api/push/relay', ['origin' => 'https://other.example'])->json('relay_token');

        $this->postJson('/api/push/relay/deliver', ['items' => [['token' => $token]]])
            ->assertStatus(202)
            ->assertJsonPath('results.'.$token, 'no_subscription');

        $this->assertSame(1, PushRelay::count());
        $this->assertCount(0, $this->sent);
    }

    public function test_push_switched_off_at_home_means_no_subscription(): void
    {
        $cred = $this->device();
        $this->signed($cred, 'POST', '/api/push/subscribe', self::SUBSCRIPTION)->assertStatus(201);
        $token = $this->signed($cred, 'POST', '/api/push/relay', ['origin' => 'https://other.example'])->json('relay_token');
        Setting::put('push_enabled', false);

        $this->postJson('/api/push/relay/deliver', ['items' => [['token' => $token]]])
            ->assertJsonPath('results.'.$token, 'no_subscription');
    }

    public function test_one_token_is_throttled_after_thirty_items_a_minute(): void
    {
        RateLimiter::clear('relay-ip:127.0.0.1');
        $cred = $this->device();
        $this->signed($cred, 'POST', '/api/push/subscribe', self::SUBSCRIPTION)->assertStatus(201);
        $token = $this->signed($cred, 'POST', '/api/push/relay', ['origin' => 'https://other.example'])->json('relay_token');

        $results = $this->postJson('/api/push/relay/deliver', [
            'items' => array_fill(0, 31, ['token' => $token, 'title' => 't']),
        ])->assertStatus(202)->json('results');

        // The results are keyed per token, so the last verdict wins: throttled.
        $this->assertSame('throttled', $results[$token]);
        $this->assertSame(30, RateLimiter::attempts('relay:'.PushRelay::hash($token)));
    }

    public function test_the_item_cap_and_shapes_are_enforced(): void
    {
        $this->postJson('/api/push/relay/deliver', ['items' => []])->assertStatus(422);
        $this->postJson('/api/push/relay/deliver', ['items' => [['token' => 'short']]])->assertStatus(422);
        $this->postJson('/api/push/relay/deliver', [
            'items' => array_fill(0, 101, ['token' => str_repeat('a', 48)]),
        ])->assertStatus(422);
        $this->postJson('/api/push/relay/deliver', [
            'items' => [['token' => str_repeat('a', 48), 'body' => str_repeat('x', 401)]],
        ])->assertStatus(422);
    }

    public function test_revoking_the_device_takes_its_relays_with_it(): void
    {
        $cred = $this->device();
        $this->signed($cred, 'POST', '/api/push/relay', ['origin' => 'https://other.example'])->assertStatus(201);

        $this->signed($cred, 'DELETE', '/api/me')->assertOk();

        $this->assertSame(0, PushRelay::count());
    }

    /* ----------------------------- foreign side ----------------------------- */

    public function test_a_device_can_subscribe_through_a_relay(): void
    {
        $cred = $this->device();
        $token = str_repeat('R', 48);

        $this->signed($cred, 'POST', '/api/push/subscribe', [
            'relay_url' => self::RELAY_URL, 'relay_token' => $token, 'hide_message_text' => true,
        ])
            ->assertStatus(201)
            ->assertJsonPath('preferences.hide_message_text', true);

        $row = PushSubscription::first();
        $this->assertTrue($row->relay);
        $this->assertSame(self::RELAY_URL, $row->endpoint);
        $this->assertSame($token, $row->relay_token);
        $this->assertNull($row->p256dh);
        $this->assertSame(PushSubscription::relayHash(self::RELAY_URL, $token), $row->endpoint_hash);

        // Switching to a browser subscription retires the relay row.
        $this->signed($cred, 'POST', '/api/push/subscribe', self::SUBSCRIPTION)->assertStatus(201);
        $this->assertSame(1, PushSubscription::count());
        $this->assertFalse(PushSubscription::first()->relay);
    }

    public function test_two_devices_may_share_one_home_server(): void
    {
        $a = $this->device('A');
        $b = $this->device('B');

        $this->signed($a, 'POST', '/api/push/subscribe', ['relay_url' => self::RELAY_URL, 'relay_token' => str_repeat('A', 48)])->assertStatus(201);
        $this->signed($b, 'POST', '/api/push/subscribe', ['relay_url' => self::RELAY_URL, 'relay_token' => str_repeat('B', 48)])->assertStatus(201);

        $this->assertSame(2, PushSubscription::count());
    }

    public function test_an_incomplete_shape_is_refused(): void
    {
        $cred = $this->device();

        $this->signed($cred, 'POST', '/api/push/subscribe', [])->assertStatus(422);
        $this->signed($cred, 'POST', '/api/push/subscribe', ['relay_url' => self::RELAY_URL])->assertStatus(422);
        $this->signed($cred, 'POST', '/api/push/subscribe', ['relay_url' => self::RELAY_URL, 'relay_token' => 'short'])->assertStatus(422);
        $this->signed($cred, 'POST', '/api/push/subscribe', ['endpoint' => self::SUBSCRIPTION['endpoint']])->assertStatus(422);
    }

    public function test_relay_urls_are_checked_before_this_server_agrees_to_post_to_them(): void
    {
        config(['push.relay_allow_private' => false, 'push.relay_allow_insecure' => false]);

        // A public address literal: names are resolved, and one that does not
        // resolve counts as private (we could not say where it leads).
        $this->assertNull(RelayUrl::problem('https://93.184.216.34/api/push/relay/deliver'));
        $this->assertNotNull(RelayUrl::problem('https://does-not-resolve.invalid/api/push/relay/deliver'));
        $this->assertNotNull(RelayUrl::problem('http://93.184.216.34/api/push/relay/deliver'));
        $this->assertNotNull(RelayUrl::problem('https://home.example/api/push/relay/other'));
        $this->assertNotNull(RelayUrl::problem('https://home.example/api/push/relay/deliver?x=1'));
        $this->assertNotNull(RelayUrl::problem('https://u:p@home.example/api/push/relay/deliver'));
        $this->assertNotNull(RelayUrl::problem('https://127.0.0.1/api/push/relay/deliver'));
        $this->assertNotNull(RelayUrl::problem('https://10.1.2.3/api/push/relay/deliver'));
        $this->assertNotNull(RelayUrl::problem('https://[::1]/api/push/relay/deliver'));
        $this->assertNotNull(RelayUrl::problem('https://localhost/api/push/relay/deliver'));
        $this->assertNotNull(RelayUrl::problem('https://printer.local/api/push/relay/deliver'));

        config(['push.relay_allow_private' => true, 'push.relay_allow_insecure' => true]);
        $this->assertNull(RelayUrl::problem('http://127.0.0.1:8000/api/push/relay/deliver'));

        // And the endpoint says why.
        config(['push.relay_allow_private' => false]);
        $cred = $this->device();
        $this->signed($cred, 'POST', '/api/push/subscribe', [
            'relay_url' => 'https://10.0.0.1/api/push/relay/deliver', 'relay_token' => str_repeat('A', 48),
        ])->assertStatus(422)->assertJsonPath('error', 'The relay URL must point at a public address.');
    }

    public function test_relay_rows_are_sent_as_one_request_per_home_server_and_reaped_per_token(): void
    {
        $home = self::RELAY_URL;
        $other = 'https://second.example/api/push/relay/deliver';
        $rows = collect([
            $this->relayRow($home, str_repeat('A', 48)),
            $this->relayRow($home, str_repeat('B', 48)),
            $this->relayRow($other, str_repeat('C', 48)),
        ]);

        WebPush::fakeTransport(function (array $requests) use ($home, $other) {
            $this->sent[] = $requests;
            $this->assertSame(['relay:'.$home, 'relay:'.$other], array_keys($requests));
            $items = json_decode($requests['relay:'.$home]['body'], true)['items'];
            $this->assertCount(2, $items);
            $this->assertSame(['token', 'title', 'body', 'tag', 'uuid'], array_keys($items[0]));

            return [
                'relay:'.$home => ['status' => 202, 'body' => json_encode(['results' => [
                    str_repeat('A', 48) => 'ok', str_repeat('B', 48) => 'gone',
                ]])],
                'relay:'.$other => ['status' => 500, 'body' => ''],
            ];
        });

        $results = WebPush::send($rows, ['title' => 'T', 'body' => 'B', 'tag' => 't', 'uuid' => null, 'secret' => 'not forwarded']);
        $this->assertSame(200, $results[$rows[0]->endpoint_hash]);
        $this->assertSame(410, $results[$rows[1]->endpoint_hash]);
        $this->assertSame(500, $results[$rows[2]->endpoint_hash]);

        PushSender::reap($results);
        $this->assertSame(2, PushSubscription::count());
        $this->assertNull(PushSubscription::find($rows[1]->id));
        $this->assertNotNull(PushSubscription::find($rows[2]->id)->last_failed_at);
    }

    public function test_a_server_without_vapid_keys_still_delivers_relay_rows(): void
    {
        config(['push.public_key' => null, 'push.private_key' => null]);
        $this->getJson('/api/push/key')->assertJsonPath('enabled', false)->assertJsonPath('relay', true);

        $row = $this->relayRow(self::RELAY_URL, str_repeat('A', 48));
        WebPush::fakeTransport(function (array $requests) {
            return ['relay:'.self::RELAY_URL => ['status' => 202, 'body' => json_encode(['results' => [str_repeat('A', 48) => 'ok']])]];
        });

        $this->assertTrue(PushSender::test($row));
    }

    public function test_the_stale_answer_carries_the_servers_time(): void
    {
        $cred = $this->device();

        $this->withHeaders([
            'X-Chat-Device' => $cred['device_id'], 'X-Chat-Ts' => '1000', 'X-Chat-Nonce' => bin2hex(random_bytes(16)), 'X-Chat-Sig' => 'ab',
        ])->getJson('/api/state')
            ->assertStatus(401)
            ->assertJsonPath('reason', 'stale')
            ->assertJsonPath('server_time', fn ($t) => abs($t - time()) < 5);
    }

    private function relayRow(string $url, string $token): PushSubscription
    {
        $cred = $this->device(substr($token, 0, 1));
        $this->signed($cred, 'POST', '/api/push/subscribe', ['relay_url' => $url, 'relay_token' => $token])->assertStatus(201);

        return PushSubscription::where('relay_token', $token)->firstOrFail();
    }
}
