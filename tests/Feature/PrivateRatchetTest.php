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
 * Server side of the Double Ratchet in private conversations: devices publish
 * a prekey next to their identity key, conversation partners see both, and
 * envelopes (one ciphertext per device) may be larger than plain text.
 */
class PrivateRatchetTest extends TestCase
{
    use RefreshDatabase;
    use SignsChatRequests;

    private function identity(): array
    {
        $user = ChatUser::create([
            'username' => 'user-'.Str::random(6), 'status' => ChatUser::APPROVED, 'last_seen_at' => now(),
        ]);
        $device = ChatDevice::issue($user);

        return [$user, $device, $device->credentials()];
    }

    private function key(): string
    {
        return base64_encode(random_bytes(32));
    }

    private function privateChannel(ChatUser $a, ChatUser $b): Channel
    {
        $channel = Channel::create([
            'uuid' => (string) Str::uuid(), 'type' => 'private', 'retention_days' => 365, 'last_activity_at' => now(),
        ]);
        foreach ([$a, $b] as $u) {
            ChannelMember::create(['channel_id' => $channel->id, 'user_id' => $u->id, 'status' => 'approved']);
        }

        return $channel;
    }

    public function test_a_device_publishes_a_prekey_and_partners_see_it(): void
    {
        [$a, $deviceA, $credA] = $this->identity();
        [$b, , $credB] = $this->identity();
        $channel = $this->privateChannel($a, $b);

        $identity = $this->key();
        $prekey = $this->key();
        $this->signed($credA, 'POST', '/api/profile/devices/key', [
            'public_key' => $identity, 'prekey' => $prekey, 'prekey_id' => 3,
        ])->assertOk();
        $this->assertSame([$prekey, 3], [$deviceA->fresh()->prekey, $deviceA->fresh()->prekey_id]);

        $listed = collect($this->signed($credB, 'GET', "/api/channels/{$channel->uuid}")->json('devices'))
            ->firstWhere('id', $deviceA->public_id);
        $this->assertSame(['key' => $identity, 'prekey' => $prekey, 'prekey_id' => 3],
            array_intersect_key($listed, array_flip(['key', 'prekey', 'prekey_id'])));

        // An older client re-registering only its identity key keeps the prekey.
        $this->signed($credA, 'POST', '/api/profile/devices/key', ['public_key' => $identity])->assertOk();
        $this->assertSame($prekey, $deviceA->fresh()->prekey);

        // Prekey and id come together, and must look like keys.
        $this->signed($credA, 'POST', '/api/profile/devices/key', ['public_key' => $identity, 'prekey' => $prekey])
            ->assertStatus(422);
        $this->signed($credA, 'POST', '/api/profile/devices/key', ['public_key' => $identity, 'prekey' => 'x', 'prekey_id' => 1])
            ->assertStatus(422);
    }

    /** Multipart uploads sign the hash of an empty body. */
    private function upload(array $cred, string $path, array $fields)
    {
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $sig = hash_hmac('sha256', implode("\n", ['POST', $path, $ts, $nonce, hash('sha256', '')]), $cred['secret']);

        return $this->withHeaders([
            'X-Chat-Device' => $cred['device_id'], 'X-Chat-Ts' => $ts, 'X-Chat-Nonce' => $nonce, 'X-Chat-Sig' => $sig,
            'Accept' => 'application/json',
        ])->post($path, $fields);
    }

    public function test_sealed_uploads_are_stored_opaque_and_plain_ones_still_work(): void
    {
        Storage::fake('uploads');
        [$a, , $credA] = $this->identity();
        [$b] = $this->identity();
        $channel = $this->privateChannel($a, $b);
        $path = "/api/channels/{$channel->uuid}/messages";

        $sealed = random_bytes(300);
        $envelope = '{"v":3,"s":"x","to":{"d":{"h":"{}","c":"'.str_repeat('B', 20000).'"}}}';
        $res = $this->upload($credA, $path, [
            'file' => UploadedFile::fake()->createWithContent('sealed.bin', $sealed),
            'thumb' => UploadedFile::fake()->createWithContent('preview.bin', random_bytes(50)),
            'kind' => 'video',
            'encrypted' => '1',
            'body' => $envelope,
        ])->assertCreated();

        $message = Message::find($res->json('message.id'));
        $this->assertTrue($message->encrypted);
        $this->assertSame('video', $message->kind);
        $this->assertNull($message->file_name);
        $this->assertSame($envelope, $message->body);
        $this->assertSame($sealed, Storage::disk('uploads')->get($message->file_path));
        $this->assertNull($message->preview()['excerpt']);

        // An older client still uploads in the clear.
        $this->upload($credA, $path, ['file' => UploadedFile::fake()->image('photo.png', 20, 20)])
            ->assertCreated()->assertJsonPath('message.encrypted', false)->assertJsonPath('message.file_name', 'photo.png');
    }

    public function test_ratchet_envelopes_may_be_large_but_not_unbounded(): void
    {
        [$a, , $credA] = $this->identity();
        [$b] = $this->identity();
        $channel = $this->privateChannel($a, $b);

        $big = '{"v":3,"s":"x","to":{"d":{"h":"{}","c":"'.str_repeat('A', 150000).'"}}}';
        $this->signed($credA, 'POST', "/api/channels/{$channel->uuid}/messages", ['body' => $big, 'encrypted' => true])
            ->assertCreated();

        $tooBig = str_repeat('A', 262145);
        $this->signed($credA, 'POST', "/api/channels/{$channel->uuid}/messages", ['body' => $tooBig, 'encrypted' => true])
            ->assertStatus(422);
        $this->signed($credA, 'POST', "/api/channels/{$channel->uuid}/messages", ['body' => str_repeat('a', 5001)])
            ->assertStatus(422);
    }
}
