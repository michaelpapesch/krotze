<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\ChatDevice;
use App\Models\ChatUser;
use App\Models\Stream;
use App\Support\Live\Server;
use App\Support\Live\Ticket;
use App\Support\Live\WebSocket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The HTTP side of live streams — the streamer role, the ticket for the live
 * server, what /api/state announces — and the two pure pieces the live server
 * is built from: the ticket and the WebSocket frame codec.
 */
class LiveStreamTest extends TestCase
{
    use RefreshDatabase;
    use SignsChatRequests;

    /** @return array{0: ChatUser, 1: array{device_id: string, secret: string}} */
    private function identity(): array
    {
        $user = ChatUser::create([
            'username' => 'user-'.Str::random(6),
            'status' => ChatUser::APPROVED,
            'last_seen_at' => now(),
        ]);

        return [$user, ChatDevice::issue($user)->credentials()];
    }

    private function channel(ChatUser $owner, string $type = 'group'): Channel
    {
        $channel = Channel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test channel',
            'type' => $type,
            'owner_id' => $type === 'group' ? $owner->id : null,
            'invite_token' => $type === 'group' ? Str::random(40) : null,
            'last_activity_at' => now(),
        ]);
        $this->join($channel, $owner);

        return $channel;
    }

    private function join(Channel $channel, ChatUser $user, string $status = 'approved'): ChannelMember
    {
        return ChannelMember::create([
            'channel_id' => $channel->id, 'user_id' => $user->id, 'status' => $status,
        ]);
    }

    private function liveServerRunning(): void
    {
        config(['live.url' => 'wss://live.example.test']);
        Cache::put(Server::HEARTBEAT_KEY, time(), 20);
    }

    private function stream(Channel $channel, ChatUser $user, array $attrs = []): Stream
    {
        return Stream::create(array_merge([
            'channel_id' => $channel->id,
            'user_id' => $user->id,
            'device_id' => ChatDevice::where('user_id', $user->id)->value('id'),
            'audio' => true,
            'video' => 'screen',
            'last_seen_at' => now(),
        ], $attrs));
    }

    /* ------------------------------- roles ------------------------------- */

    public function test_owner_can_stream_and_members_cannot_until_made_streamer(): void
    {
        [$owner, $ownerCred] = $this->identity();
        [$guest, $guestCred] = $this->identity();
        $channel = $this->channel($owner);
        $member = $this->join($channel, $guest);

        $this->signed($ownerCred, 'GET', "/api/channels/{$channel->uuid}")
            ->assertOk()->assertJson(['can_stream' => true]);
        $this->signed($guestCred, 'GET', "/api/channels/{$channel->uuid}")
            ->assertOk()->assertJson(['can_stream' => false]);

        $this->signed($ownerCred, 'POST', "/api/channels/{$channel->uuid}/members/{$member->id}/role", ['role' => 'streamer'])
            ->assertOk();

        $detail = $this->signed($guestCred, 'GET', "/api/channels/{$channel->uuid}")->assertOk();
        $detail->assertJson(['can_stream' => true]);
        $roles = collect($detail->json('members'))->pluck('role', 'id');
        $this->assertSame('streamer', $roles[$member->id]);
        $this->assertContains('owner', $roles->all());
    }

    public function test_only_the_owner_assigns_the_role(): void
    {
        [$owner] = $this->identity();
        [$guest, $guestCred] = $this->identity();
        $channel = $this->channel($owner);
        $member = $this->join($channel, $guest);

        $this->signed($guestCred, 'POST', "/api/channels/{$channel->uuid}/members/{$member->id}/role", ['role' => 'streamer'])
            ->assertStatus(403);
        $this->assertSame('member', $member->fresh()->role);
    }

    public function test_the_role_changes_the_roster_fingerprint(): void
    {
        [$owner, $ownerCred] = $this->identity();
        [$guest] = $this->identity();
        $channel = $this->channel($owner);
        $member = $this->join($channel, $guest);

        $before = $this->signed($ownerCred, 'GET', "/api/channels/{$channel->uuid}")->json('members_hash');
        $this->signed($ownerCred, 'POST', "/api/channels/{$channel->uuid}/members/{$member->id}/role", ['role' => 'streamer']);
        $after = $this->signed($ownerCred, 'GET', "/api/channels/{$channel->uuid}")->json('members_hash');

        $this->assertNotSame($before, $after);
    }

    public function test_withdrawing_the_role_ends_a_running_stream(): void
    {
        [$owner, $ownerCred] = $this->identity();
        [$guest] = $this->identity();
        $channel = $this->channel($owner);
        $member = $this->join($channel, $guest);
        $member->update(['role' => 'streamer']);
        $this->stream($channel, $guest);

        $this->signed($ownerCred, 'POST', "/api/channels/{$channel->uuid}/members/{$member->id}/role", ['role' => 'member'])
            ->assertOk();

        $this->assertSame(0, Stream::count());
    }

    public function test_removing_a_member_ends_their_stream(): void
    {
        [$owner, $ownerCred] = $this->identity();
        [$guest] = $this->identity();
        $channel = $this->channel($owner);
        $member = $this->join($channel, $guest);
        $member->update(['role' => 'streamer']);
        $this->stream($channel, $guest);

        $this->signed($ownerCred, 'DELETE', "/api/channels/{$channel->uuid}/members/{$member->id}")->assertOk();

        $this->assertSame(0, Stream::count());
    }

    public function test_both_sides_of_a_private_conversation_may_stream(): void
    {
        [$a] = $this->identity();
        [$b, $bCred] = $this->identity();
        $channel = $this->channel($a, 'private');
        $this->join($channel, $b);

        $this->signed($bCred, 'GET', "/api/channels/{$channel->uuid}")
            ->assertOk()->assertJson(['can_stream' => true]);
    }

    /* ------------------------------- ticket ------------------------------ */

    public function test_a_member_gets_a_ticket_the_live_server_accepts(): void
    {
        $this->liveServerRunning();
        [$owner, $cred] = $this->identity();
        $channel = $this->channel($owner);

        $res = $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/live")->assertOk();

        $this->assertSame('wss://live.example.test', $res->json('url'));
        $claims = Ticket::verify($res->json('ticket'));
        $this->assertSame($channel->id, $claims['c']);
        $this->assertSame($owner->id, $claims['u']);
    }

    public function test_no_ticket_without_approved_membership(): void
    {
        $this->liveServerRunning();
        [$owner] = $this->identity();
        [$stranger, $strangerCred] = $this->identity();
        [$waiting, $waitingCred] = $this->identity();
        $channel = $this->channel($owner);
        $this->join($channel, $waiting, 'pending');

        $this->signed($strangerCred, 'POST', "/api/channels/{$channel->uuid}/live")->assertStatus(404);
        $this->signed($waitingCred, 'POST', "/api/channels/{$channel->uuid}/live")->assertStatus(404);
    }

    public function test_no_ticket_while_the_live_server_is_down(): void
    {
        config(['live.url' => 'wss://live.example.test']); // configured, but no heartbeat
        [$owner, $cred] = $this->identity();
        $channel = $this->channel($owner);

        $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/live")->assertStatus(503);
        $this->signed($cred, 'GET', '/api/state')->assertJson(['live' => false]);
    }

    public function test_a_tampered_or_expired_ticket_is_refused(): void
    {
        [$owner] = $this->identity();
        $channel = $this->channel($owner);
        $device = ChatDevice::where('user_id', $owner->id)->first();

        $ticket = Ticket::issue($channel, $device);
        $this->assertNotNull(Ticket::verify($ticket));

        [$payload, $mac] = explode('.', $ticket);
        $forged = rtrim(strtr(base64_encode(json_encode(['c' => 999, 'u' => 1, 'd' => 1, 'e' => time() + 60])), '+/', '-_'), '=');
        $this->assertNull(Ticket::verify($forged.'.'.$mac));
        $this->assertNull(Ticket::verify($payload));
        $this->assertNull(Ticket::verify('nonsense'));

        $this->travel(Ticket::TTL_SECONDS + 1)->seconds();
        $this->assertNull(Ticket::verify($ticket));
    }

    /* -------------------------------- state ------------------------------ */

    public function test_state_announces_active_streams_to_members(): void
    {
        $this->liveServerRunning();
        [$owner, $ownerCred] = $this->identity();
        [$guest, $guestCred] = $this->identity();
        $channel = $this->channel($owner);
        $this->join($channel, $guest);
        $stream = $this->stream($channel, $owner);

        $mine = $this->signed($ownerCred, 'GET', '/api/state')->assertOk();
        $mine->assertJson(['live' => true]);
        $this->assertSame(
            ['id' => $stream->id, 'user_id' => $owner->id, 'username' => $owner->username,
                'audio' => true, 'video' => 'screen', 'mine' => true],
            $mine->json('channels.0.streams.0'),
        );

        $theirs = $this->signed($guestCred, 'GET', '/api/state')->assertOk();
        $this->assertFalse($theirs->json('channels.0.streams.0.mine'));
        $this->assertFalse($theirs->json('channels.0.can_stream'));
    }

    public function test_a_stream_whose_server_died_is_not_announced(): void
    {
        $this->liveServerRunning();
        [$owner, $cred] = $this->identity();
        $channel = $this->channel($owner);
        $this->stream($channel, $owner, ['last_seen_at' => now()->subSeconds(Stream::ACTIVE_SECONDS + 5)]);

        $this->assertSame([], $this->signed($cred, 'GET', '/api/state')->json('channels.0.streams'));

        $this->stream($channel, $owner, ['last_seen_at' => now()->subSeconds(Stream::ACTIVE_SECONDS + 5)]);
        Stream::pruneStale();
        $this->assertSame(0, Stream::count());
    }

    /* --------------------------- websocket codec ------------------------- */

    /** A frame the way a browser sends it: masked. */
    private function clientFrame(string $payload, int $opcode = WebSocket::BINARY, bool $fin = true): string
    {
        $length = strlen($payload);
        $head = chr(($fin ? 0x80 : 0) | $opcode);
        $head .= match (true) {
            $length < 126 => chr(0x80 | $length),
            $length < 65536 => chr(0x80 | 126).pack('n', $length),
            default => chr(0x80 | 127).pack('J', $length),
        };
        $mask = random_bytes(4);

        return $head.$mask.($payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length));
    }

    public function test_handshake_answers_with_the_rfc_accept_key(): void
    {
        $response = WebSocket::handshake(
            "GET /live HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            ."Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n",
        );

        // The example from RFC 6455 §1.3.
        $this->assertStringContainsString('Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $response);
        $this->assertNull(WebSocket::handshake("GET / HTTP/1.1\r\nHost: x\r\n\r\n"));
    }

    public function test_frames_of_every_length_class_round_trip(): void
    {
        foreach ([0, 5, 125, 126, 65535, 65536, 200000] as $length) {
            $payload = $length ? random_bytes($length) : '';
            $buffer = $this->clientFrame($payload).'rest';

            $frame = WebSocket::decode($buffer, 1 << 20);

            $this->assertSame($payload, $frame['payload'], "length {$length}");
            $this->assertSame(WebSocket::BINARY, $frame['opcode']);
            $this->assertTrue($frame['fin']);
            $this->assertSame('rest', $buffer);
        }
    }

    public function test_an_incomplete_frame_waits_for_more_bytes(): void
    {
        $whole = $this->clientFrame(random_bytes(300), WebSocket::TEXT);
        $buffer = substr($whole, 0, 100);

        $this->assertNull(WebSocket::decode($buffer, 1 << 20));
        $this->assertSame(100, strlen($buffer));
    }

    public function test_oversized_and_unmasked_frames_are_refused(): void
    {
        $big = $this->clientFrame(random_bytes(2000));
        try {
            WebSocket::decode($big, 1000);
            $this->fail('An oversized frame was accepted.');
        } catch (RuntimeException) {
        }

        $unmasked = WebSocket::encode('hello');
        $this->expectException(RuntimeException::class);
        WebSocket::decode($unmasked, 1000);
    }

    public function test_server_frames_carry_their_length_unmasked(): void
    {
        $this->assertSame("\x82\x05hello", WebSocket::encode('hello'));
        $this->assertSame("\x81\x7e\x01\x00", substr(WebSocket::encode(str_repeat('a', 256), WebSocket::TEXT), 0, 4));
    }
}
