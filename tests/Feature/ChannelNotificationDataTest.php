<?php

namespace Tests\Feature;

use App\Models\ChannelMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the client needs from /api/state to raise a notification for a chat it
 * does not currently have open: the newest message, and whether it is muted.
 */
class ChannelNotificationDataTest extends TestCase
{
    use RefreshDatabase;
    use SignsChatRequests;

    public function test_state_carries_the_newest_message_and_the_mute_flag(): void
    {
        $owner = $this->register();
        $uuid = $this->signed($owner, 'POST', '/api/channels', ['name' => 'Lobby'])->json('uuid');

        $this->signed($owner, 'POST', "/api/channels/{$uuid}/messages", ['body' => 'first'])->assertStatus(201);
        $this->signed($owner, 'POST', "/api/channels/{$uuid}/messages", ['body' => 'newest one'])->assertStatus(201);

        $channel = $this->signed($owner, 'GET', '/api/state')->assertOk()->json('channels.0');

        $this->assertSame('newest one', $channel['last_message']['excerpt']);
        $this->assertSame($owner['user_id'], $channel['last_message']['user_id']);
        $this->assertFalse($channel['muted']);

        $this->signed($owner, 'POST', "/api/channels/{$uuid}/mute", ['muted' => true])->assertOk();

        $this->assertTrue($this->signed($owner, 'GET', '/api/state')->json('channels.0.muted'));
        $this->assertTrue(ChannelMember::first()->muted);
    }

    public function test_a_file_message_previews_as_its_file_name(): void
    {
        $owner = $this->register();
        $uuid = $this->signed($owner, 'POST', '/api/channels', ['name' => 'Lobby'])->json('uuid');

        \App\Models\Message::create([
            'channel_id' => \App\Models\Channel::where('uuid', $uuid)->value('id'),
            'user_id' => $owner['user_id'],
            'author_alias' => 'owner',
            'kind' => 'image',
            'file_path' => 'uploads/x/y.png',
            'file_name' => 'holiday.png',
        ]);

        $preview = $this->signed($owner, 'GET', '/api/state')->json('channels.0.last_message');

        $this->assertSame('holiday.png', $preview['excerpt']);
    }

    /** Register a device and give it the username every channel action needs. */
    private function register(): array
    {
        $cred = $this->postJson('/api/session', ['device_name' => 'Test'])->json();
        $this->signed($cred, 'POST', '/api/profile/username', ['username' => 'owner-'.$cred['user_id']]);

        return $cred;
    }
}
