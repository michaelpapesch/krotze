<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\ChatDevice;
use App\Models\ChatUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OpenJoinTest extends TestCase
{
    use RefreshDatabase;
    use SignsChatRequests;

    /** @return array{0: ChatUser, 1: array{device_id: string, secret: string}} */
    private function identity(?string $username = 'someone'): array
    {
        $user = ChatUser::create([
            'username' => $username ? $username.'-'.Str::random(6) : null,
            'status' => ChatUser::APPROVED,
            'last_seen_at' => now(),
        ]);

        return [$user, ChatDevice::issue($user)->credentials()];
    }

    private function makeChannel(ChatUser $owner, string $joinMode = 'approval', array $attrs = []): Channel
    {
        $channel = Channel::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test channel',
            'type' => 'group',
            'owner_id' => $owner->id,
            'invite_token' => Str::random(40),
            'join_mode' => $joinMode,
            'last_activity_at' => now(),
        ], $attrs));

        ChannelMember::create([
            'channel_id' => $channel->id,
            'user_id' => $owner->id,
            'status' => 'approved',
        ]);

        return $channel;
    }

    public function test_open_channel_join_is_approved_immediately(): void
    {
        [$owner] = $this->identity();
        $channel = $this->makeChannel($owner, 'open');
        [$visitor, $cred] = $this->identity();

        $res = $this->signed($cred, 'POST', "/api/join/{$channel->invite_token}");

        $res->assertStatus(201)->assertJson(['status' => 'approved', 'uuid' => $channel->uuid]);
        $this->assertDatabaseHas('channel_members', [
            'channel_id' => $channel->id,
            'user_id' => $visitor->id,
            'status' => 'approved',
        ]);
        // No approval request lands on the owner's desk.
        $this->assertDatabaseMissing('chat_notifications', ['type' => 'join_request']);
    }

    public function test_approval_channel_join_stays_pending(): void
    {
        [$owner] = $this->identity();
        $channel = $this->makeChannel($owner, 'approval');
        [, $cred] = $this->identity();

        $res = $this->signed($cred, 'POST', "/api/join/{$channel->invite_token}");

        $res->assertStatus(201)->assertJson(['status' => 'pending']);
        $this->assertDatabaseHas('chat_notifications', ['type' => 'join_request']);
    }

    public function test_joining_an_open_channel_still_requires_a_username(): void
    {
        [$owner] = $this->identity();
        $channel = $this->makeChannel($owner, 'open');
        [, $cred] = $this->identity(username: null);

        $this->signed($cred, 'POST', "/api/join/{$channel->invite_token}")
            ->assertStatus(422);
    }

    public function test_open_join_is_throttled_per_ip(): void
    {
        [$owner] = $this->identity();
        $channel = $this->makeChannel($owner, 'open');

        for ($i = 0; $i < 6; $i++) {
            [, $cred] = $this->identity();
            $this->signed($cred, 'POST', "/api/join/{$channel->invite_token}")
                ->assertStatus(201);
        }

        [, $cred] = $this->identity();
        $this->signed($cred, 'POST', "/api/join/{$channel->invite_token}")
            ->assertStatus(429);
    }

    public function test_creating_open_channel_disables_uploads_by_default(): void
    {
        [, $cred] = $this->identity();

        $res = $this->signed($cred, 'POST', '/api/channels', [
            'name' => 'Embed me', 'join_mode' => 'open',
        ]);

        $res->assertStatus(201);
        $channel = Channel::where('uuid', $res->json('uuid'))->firstOrFail();
        $this->assertSame('open', $channel->join_mode);
        $this->assertFalse($channel->allow_images);
        $this->assertFalse($channel->allow_videos);
        $this->assertFalse($channel->allow_audio);
        $this->assertFalse($channel->allow_zip);
    }

    public function test_switching_to_open_join_disables_uploads_unless_explicitly_set(): void
    {
        [$owner, $cred] = $this->identity();
        $channel = $this->makeChannel($owner, 'approval', [
            'allow_images' => true, 'allow_videos' => true,
            'allow_audio' => true, 'allow_zip' => true,
        ]);

        $this->signed($cred, 'PATCH', "/api/channels/{$channel->uuid}", ['join_mode' => 'open'])
            ->assertOk();

        $channel->refresh();
        $this->assertSame('open', $channel->join_mode);
        $this->assertFalse($channel->allow_images);
        $this->assertFalse($channel->allow_zip);
    }

    public function test_messages_in_open_channel_are_throttled_per_member(): void
    {
        [$owner] = $this->identity();
        $channel = $this->makeChannel($owner, 'open');
        [$member, $cred] = $this->identity();
        ChannelMember::create([
            'channel_id' => $channel->id, 'user_id' => $member->id, 'status' => 'approved',
        ]);

        for ($i = 0; $i < 20; $i++) {
            $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/messages", ['body' => "msg {$i}"])
                ->assertStatus(201);
        }
        $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/messages", ['body' => 'one too many'])
            ->assertStatus(429);
    }

    public function test_owner_is_exempt_from_open_channel_message_throttle(): void
    {
        [$owner, $cred] = $this->identity();
        $channel = $this->makeChannel($owner, 'open');

        for ($i = 0; $i < 25; $i++) {
            $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/messages", ['body' => "msg {$i}"])
                ->assertStatus(201);
        }
    }

    public function test_join_info_reports_the_join_mode(): void
    {
        [$owner] = $this->identity();
        $channel = $this->makeChannel($owner, 'open');

        $this->getJson("/api/join/{$channel->invite_token}")
            ->assertOk()
            ->assertJson(['join_mode' => 'open']);
    }

    public function test_embed_route_serves_open_channels_with_frame_ancestors(): void
    {
        [$owner] = $this->identity();
        $channel = $this->makeChannel($owner, 'open');

        $res = $this->get("/embed/{$channel->invite_token}");

        $res->assertOk()
            ->assertHeader('Content-Security-Policy', 'frame-ancestors *')
            ->assertSee('available: true', false);
    }

    public function test_embed_route_marks_approval_channels_unavailable(): void
    {
        [$owner] = $this->identity();
        $channel = $this->makeChannel($owner, 'approval');

        $this->get("/embed/{$channel->invite_token}")
            ->assertOk()
            ->assertSee('available: false', false);
    }

    public function test_embed_url_only_returned_to_owner_of_open_channel(): void
    {
        [$owner, $cred] = $this->identity();
        $open = $this->makeChannel($owner, 'open');
        $approval = $this->makeChannel($owner, 'approval');

        $this->signed($cred, 'GET', "/api/channels/{$open->uuid}")
            ->assertJson(['embed_url' => url('/embed/'.$open->invite_token)]);
        $this->signed($cred, 'GET', "/api/channels/{$approval->uuid}")
            ->assertJson(['embed_url' => null]);
    }
}
