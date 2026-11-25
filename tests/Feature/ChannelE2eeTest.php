<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\ChatDevice;
use App\Models\ChatUser;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end encrypted group channels: the server keeps per-device wraps of
 * channel keys it cannot open, and enforces the epoch bookkeeping — a new
 * epoch whenever the member devices change, envelopes only for the current
 * one. That is what limits a newcomer to messages written after they joined.
 */
class ChannelE2eeTest extends TestCase
{
    use RefreshDatabase;
    use SignsChatRequests;

    /** @return array{0: ChatUser, 1: ChatDevice, 2: array{device_id: string, secret: string}} */
    private function identity(bool $keyed = true): array
    {
        $user = ChatUser::create([
            'username' => 'user-'.Str::random(6),
            'status' => ChatUser::APPROVED,
            'last_seen_at' => now(),
        ]);
        $device = ChatDevice::issue($user);
        if ($keyed) {
            $device->forceFill(['public_key' => base64_encode(random_bytes(32))])->save();
        }

        return [$user, $device, $device->credentials()];
    }

    private function channel(ChatUser $owner, array $members = []): Channel
    {
        $channel = Channel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Secret',
            'type' => 'group',
            'owner_id' => $owner->id,
            'invite_token' => Str::random(40),
            'retention_days' => 7,
            'join_mode' => 'approval',
            'allow_images' => true, 'allow_videos' => true, 'allow_audio' => true, 'allow_zip' => true,
            'e2ee' => true,
            'last_activity_at' => now(),
        ]);
        foreach ([$owner, ...$members] as $user) {
            ChannelMember::create(['channel_id' => $channel->id, 'user_id' => $user->id, 'status' => 'approved']);
        }

        return $channel;
    }

    /** Wraps for exactly the devices GET /keys asked for (opaque strings to the server). */
    private function wrapsFor(array $summary): array
    {
        return collect($summary['devices'])->mapWithKeys(fn ($d) => [$d['id'] => 'wrap-for-'.$d['id']])->all();
    }

    private function envelope(int $epoch): string
    {
        return json_encode(['v' => 2, 'e' => $epoch, 'n' => base64_encode(random_bytes(24)), 'ct' => base64_encode(random_bytes(40))]);
    }

    private function startEpoch(array $cred, Channel $channel, ?int $epoch = null)
    {
        $summary = $this->signed($cred, 'GET', "/api/channels/{$channel->uuid}/keys")->assertOk()->json();

        return $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/keys", [
            'epoch' => $epoch ?? ($summary['epoch'] ?? 0) + 1,
            'wraps' => $this->wrapsFor($summary),
        ]);
    }

    public function test_the_first_message_needs_an_epoch_and_then_goes_through(): void
    {
        [$owner, , $cred] = $this->identity();
        [$member] = $this->identity();
        $channel = $this->channel($owner, [$member]);

        $summary = $this->signed($cred, 'GET', "/api/channels/{$channel->uuid}/keys")->assertOk()->json();
        $this->assertNull($summary['epoch']);
        $this->assertTrue($summary['rekey']);
        $this->assertCount(2, $summary['devices']);

        $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/messages", ['body' => $this->envelope(1), 'encrypted' => true])
            ->assertStatus(409)->assertJson(['code' => 'rekey']);

        $this->startEpoch($cred, $channel)->assertCreated()->assertJson(['epoch' => 1, 'rekey' => false]);

        $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/messages", ['body' => $this->envelope(1), 'encrypted' => true])
            ->assertCreated()->assertJsonPath('message.encrypted', true);
    }

    public function test_plain_text_is_refused_and_old_epochs_are_stale(): void
    {
        [$owner, , $cred] = $this->identity();
        $channel = $this->channel($owner);
        $this->startEpoch($cred, $channel)->assertCreated();

        $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/messages", ['body' => 'hello'])
            ->assertStatus(422);
        $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/messages", ['body' => 'hello', 'encrypted' => true])
            ->assertStatus(422);
        $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/messages", ['body' => $this->envelope(7), 'encrypted' => true])
            ->assertStatus(409)->assertJson(['code' => 'stale_key', 'epoch' => 1]);
    }

    public function test_a_newcomer_forces_a_new_epoch_and_gets_only_keys_from_then_on(): void
    {
        [$owner, , $credOwner] = $this->identity();
        [$newcomer, , $credNew] = $this->identity();
        $channel = $this->channel($owner);
        $this->startEpoch($credOwner, $channel)->assertCreated();

        ChannelMember::create(['channel_id' => $channel->id, 'user_id' => $newcomer->id, 'status' => 'approved']);

        // The device set changed: epoch 1 may no longer be used.
        $this->signed($credOwner, 'POST', "/api/channels/{$channel->uuid}/messages", ['body' => $this->envelope(1), 'encrypted' => true])
            ->assertStatus(409)->assertJson(['code' => 'rekey']);

        $this->startEpoch($credOwner, $channel)->assertCreated()->assertJson(['epoch' => 2]);

        $keys = $this->signed($credNew, 'GET', "/api/channels/{$channel->uuid}/keys")->assertOk()->json('keys');
        $this->assertSame([2], array_column($keys, 'epoch'));
        $ownerKeys = $this->signed($credOwner, 'GET', "/api/channels/{$channel->uuid}/keys")->json('keys');
        $this->assertSame([1, 2], array_column($ownerKeys, 'epoch'));
    }

    public function test_leaving_and_new_devices_also_force_a_new_epoch(): void
    {
        [$owner, , $credOwner] = $this->identity();
        [$member, $memberDevice] = $this->identity();
        $channel = $this->channel($owner, [$member]);
        $this->startEpoch($credOwner, $channel)->assertCreated();
        $this->assertFalse($channel->fresh()->needsNewKey());

        // A second device of a member registers its key.
        $extra = ChatDevice::issue($member);
        $extra->forceFill(['public_key' => base64_encode(random_bytes(32))])->save();
        $this->assertTrue($channel->fresh()->needsNewKey());
        $this->startEpoch($credOwner, $channel)->assertCreated();

        $channel->members()->where('user_id', $member->id)->delete();
        $this->assertTrue($channel->fresh()->needsNewKey());
        $summary = $this->signed($credOwner, 'GET', "/api/channels/{$channel->uuid}/keys")->json();
        $this->assertNotContains($memberDevice->public_id, array_column($summary['devices'], 'id'));
    }

    public function test_an_epoch_must_cover_exactly_the_member_devices_and_follow_the_last(): void
    {
        [$owner, , $cred] = $this->identity();
        [$member, $memberDevice] = $this->identity();
        $channel = $this->channel($owner, [$member]);
        $summary = $this->signed($cred, 'GET', "/api/channels/{$channel->uuid}/keys")->json();

        $missing = $this->wrapsFor($summary);
        unset($missing[$memberDevice->public_id]);
        $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/keys", ['epoch' => 1, 'wraps' => $missing])
            ->assertStatus(409);

        $extra = $this->wrapsFor($summary) + ['not-a-member-device' => 'x'];
        $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/keys", ['epoch' => 1, 'wraps' => $extra])
            ->assertStatus(409);

        $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/keys", ['epoch' => 3, 'wraps' => $this->wrapsFor($summary)])
            ->assertStatus(409)->assertJsonStructure(['error', 'epoch', 'devices', 'keys']);

        $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/keys", ['epoch' => 1, 'wraps' => $this->wrapsFor($summary)])
            ->assertCreated();
        $this->signed($cred, 'POST', "/api/channels/{$channel->uuid}/keys", ['epoch' => 1, 'wraps' => $this->wrapsFor($summary)])
            ->assertStatus(409);
    }

    public function test_devices_without_a_box_key_are_not_wrap_targets(): void
    {
        [$owner, , $cred] = $this->identity();
        [$member] = $this->identity(keyed: false);
        $channel = $this->channel($owner, [$member]);

        $summary = $this->signed($cred, 'GET', "/api/channels/{$channel->uuid}/keys")->json();
        $this->assertCount(1, $summary['devices']);
    }

    public function test_applicants_and_outsiders_get_no_keys(): void
    {
        [$owner] = $this->identity();
        [$applicant, , $credApplicant] = $this->identity();
        [, , $credOutsider] = $this->identity();
        $channel = $this->channel($owner);
        ChannelMember::create(['channel_id' => $channel->id, 'user_id' => $applicant->id, 'status' => 'pending']);

        $this->signed($credApplicant, 'GET', "/api/channels/{$channel->uuid}/keys")->assertNotFound();
        $this->signed($credOutsider, 'GET', "/api/channels/{$channel->uuid}/keys")->assertNotFound();
    }

    public function test_open_channels_cannot_be_encrypted_and_encryption_cannot_be_switched_off(): void
    {
        [$owner, , $cred] = $this->identity();

        $this->signed($cred, 'POST', '/api/channels', ['name' => 'Open', 'join_mode' => 'open', 'e2ee' => true])
            ->assertStatus(422);

        $uuid = $this->signed($cred, 'POST', '/api/channels', ['name' => 'Closed', 'e2ee' => true])
            ->assertCreated()->json('uuid');
        $this->assertTrue(Channel::where('uuid', $uuid)->value('e2ee'));

        $this->signed($cred, 'PATCH', "/api/channels/{$uuid}", ['join_mode' => 'open'])->assertStatus(422);
        $this->signed($cred, 'PATCH', "/api/channels/{$uuid}", ['e2ee' => false])->assertStatus(422);

        // An existing unencrypted channel can be switched on; private chats keep their own scheme.
        $plain = $this->signed($cred, 'POST', '/api/channels', ['name' => 'Plain'])->json('uuid');
        $this->assertFalse(Channel::where('uuid', $plain)->value('e2ee'));
        $this->signed($cred, 'PATCH', "/api/channels/{$plain}", ['e2ee' => true])->assertOk();
        $this->assertTrue(Channel::where('uuid', $plain)->value('e2ee'));

        $state = $this->signed($cred, 'GET', '/api/state')->json('channels');
        $this->assertTrue(collect($state)->firstWhere('uuid', $plain)['e2ee']);
    }

    public function test_encrypted_uploads_are_stored_as_opaque_bytes_and_previews_stay_empty(): void
    {
        Storage::fake('uploads');
        [$owner, , $cred] = $this->identity();
        $channel = $this->channel($owner);
        $this->startEpoch($cred, $channel)->assertCreated();

        $path = "/api/channels/{$channel->uuid}/messages";
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $sig = hash_hmac('sha256', implode("\n", ['POST', $path, $ts, $nonce, hash('sha256', '')]), $cred['secret']);
        $sealed = random_bytes(500);

        $res = $this->withHeaders([
            'X-Chat-Device' => $cred['device_id'], 'X-Chat-Ts' => $ts, 'X-Chat-Nonce' => $nonce, 'X-Chat-Sig' => $sig,
            'Accept' => 'application/json',
        ])->post($path, [
            'file' => UploadedFile::fake()->createWithContent('blob', $sealed),
            'thumb' => UploadedFile::fake()->createWithContent('blob', random_bytes(100)),
            'kind' => 'image',
            'encrypted' => '1',
            'body' => $this->envelope(1),
        ])->assertCreated();

        $message = Message::find($res->json('message.id'));
        $this->assertTrue($message->encrypted);
        $this->assertSame('image', $message->kind);
        $this->assertNull($message->file_name);
        $this->assertSame($sealed, Storage::disk('uploads')->get($message->file_path));
        $this->assertNotNull($message->thumb_path);
        $this->assertNull($message->preview()['excerpt']);
        $this->assertNotNull($res->json('message.thumb_url'));
    }

    public function test_destroying_the_channel_removes_its_keys(): void
    {
        [$owner, , $cred] = $this->identity();
        $channel = $this->channel($owner);
        $this->startEpoch($cred, $channel)->assertCreated();
        $this->assertDatabaseCount('channel_keys', 1);

        $channel->destroyCompletely();
        $this->assertDatabaseCount('channel_keys', 0);
    }
}
