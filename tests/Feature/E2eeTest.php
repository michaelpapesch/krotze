<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\ChatDevice;
use App\Models\ChatUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class E2eeTest extends TestCase
{
    use RefreshDatabase;
    use SignsChatRequests;

    /** @return array{0: ChatUser, 1: ChatDevice, 2: array{device_id: string, secret: string}} */
    private function identity(): array
    {
        $user = ChatUser::create([
            'username' => 'user-'.Str::random(6),
            'status' => ChatUser::APPROVED,
            'last_seen_at' => now(),
        ]);
        $device = ChatDevice::issue($user);

        return [$user, $device, $device->credentials()];
    }

    private function privateChannel(ChatUser $a, ChatUser $b): Channel
    {
        $channel = Channel::create([
            'uuid' => (string) Str::uuid(),
            'type' => 'private',
            'retention_days' => 365,
            'last_activity_at' => now(),
        ]);
        foreach ([$a, $b] as $user) {
            ChannelMember::create([
                'channel_id' => $channel->id, 'user_id' => $user->id, 'status' => 'approved',
            ]);
        }

        return $channel;
    }

    private function fakeKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    public function test_device_registers_and_replaces_its_public_key(): void
    {
        [, $device, $cred] = $this->identity();

        $key = $this->fakeKey();
        $this->signed($cred, 'POST', '/api/profile/devices/key', ['public_key' => $key])->assertOk();
        $this->assertSame($key, $device->fresh()->public_key);

        $replacement = $this->fakeKey();
        $this->signed($cred, 'POST', '/api/profile/devices/key', ['public_key' => $replacement])->assertOk();
        $this->assertSame($replacement, $device->fresh()->public_key);

        $this->signed($cred, 'POST', '/api/profile/devices/key', ['public_key' => 'not-a-key'])
            ->assertStatus(422);
    }

    public function test_private_channel_detail_lists_keyed_devices_of_both_sides(): void
    {
        [$a, $deviceA, $credA] = $this->identity();
        [$b, $deviceB, $credB] = $this->identity();
        $channel = $this->privateChannel($a, $b);

        $keyA = $this->fakeKey();
        $keyB = $this->fakeKey();
        $this->signed($credA, 'POST', '/api/profile/devices/key', ['public_key' => $keyA])->assertOk();
        $this->signed($credB, 'POST', '/api/profile/devices/key', ['public_key' => $keyB])->assertOk();

        $devices = collect($this->signed($credA, 'GET', "/api/channels/{$channel->uuid}")->json('devices'));
        $this->assertCount(2, $devices);
        $this->assertSame($keyA, $devices->firstWhere('id', $deviceA->public_id)['key']);
        $this->assertSame($keyB, $devices->firstWhere('id', $deviceB->public_id)['key']);

        // A keyless device is not a wrap target.
        ChatDevice::issue($b);
        $this->assertCount(2, $this->signed($credA, 'GET', "/api/channels/{$channel->uuid}")->json('devices'));
    }

    public function test_group_channel_detail_has_no_device_list(): void
    {
        [$owner, , $cred] = $this->identity();
        $channel = Channel::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Group', 'type' => 'group',
            'owner_id' => $owner->id, 'invite_token' => Str::random(40), 'last_activity_at' => now(),
        ]);
        ChannelMember::create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'status' => 'approved']);

        $this->signed($cred, 'GET', "/api/channels/{$channel->uuid}")
            ->assertOk()->assertJson(['devices' => null]);
    }

    public function test_encrypted_messages_are_accepted_in_private_channels_only(): void
    {
        [$a, , $credA] = $this->identity();
        [$b] = $this->identity();
        $channel = $this->privateChannel($a, $b);

        $envelope = json_encode(['v' => 1, 'n' => 'x', 'ct' => str_repeat('A', 8000), 'eph' => 'y', 'keys' => []]);
        $res = $this->signed($credA, 'POST', "/api/channels/{$channel->uuid}/messages", [
            'body' => $envelope, 'encrypted' => true,
        ]);
        $res->assertStatus(201)->assertJsonPath('message.encrypted', true);

        // The stored body is the envelope, untouched.
        $this->assertSame($envelope, $channel->messages()->first()->body);

        // Group channels refuse the flag outright.
        $group = Channel::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Group', 'type' => 'group',
            'owner_id' => $a->id, 'invite_token' => Str::random(40), 'last_activity_at' => now(),
        ]);
        ChannelMember::create(['channel_id' => $group->id, 'user_id' => $a->id, 'status' => 'approved']);
        $this->signed($credA, 'POST', "/api/channels/{$group->uuid}/messages", [
            'body' => $envelope, 'encrypted' => true,
        ])->assertStatus(422);
    }

    public function test_previews_of_encrypted_messages_carry_no_text(): void
    {
        [$a, , $credA] = $this->identity();
        [$b, , $credB] = $this->identity();
        $channel = $this->privateChannel($a, $b);

        $this->signed($credA, 'POST', "/api/channels/{$channel->uuid}/messages", [
            'body' => json_encode(['v' => 1, 'ct' => 'secret-ciphertext', 'keys' => []]),
            'encrypted' => true,
        ])->assertStatus(201);

        $state = $this->signed($credB, 'GET', '/api/state');
        $last = collect($state->json('channels'))->firstWhere('uuid', $channel->uuid)['last_message'];
        $this->assertTrue($last['encrypted']);
        $this->assertNull($last['excerpt']);

        // Reply quotes reveal nothing either.
        $first = $channel->messages()->first();
        $reply = $this->signed($credB, 'POST', "/api/channels/{$channel->uuid}/messages", [
            'body' => 'plaintext follow-up', 'reply_to' => $first->id,
        ]);
        $reply->assertStatus(201)
            ->assertJsonPath('message.reply_to.encrypted', true)
            ->assertJsonPath('message.reply_to.excerpt', null);
    }

    public function test_plaintext_messages_keep_their_5000_char_limit(): void
    {
        [$a, , $credA] = $this->identity();
        [$b] = $this->identity();
        $channel = $this->privateChannel($a, $b);

        $this->signed($credA, 'POST', "/api/channels/{$channel->uuid}/messages", [
            'body' => str_repeat('x', 5001),
        ])->assertStatus(422);
    }
}
