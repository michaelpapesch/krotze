<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\ChatDevice;
use App\Models\ChatUser;
use App\Models\Message;
use App\Models\UploadView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Uploads must be reachable through their capability URLs and nothing else.
 * The classic way to break that is a `public/storage` symlink — somebody runs
 * `php artisan storage:link` out of habit — so three things are pinned here:
 * uploads are not on the `public` disk, the command has nothing to link, and
 * the web server refuses /storage outright.
 */
class UploadIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** Uploads are multipart: the signature covers the hash of an empty body. */
    private function upload(array $cred, string $path, UploadedFile $file)
    {
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = implode("\n", ['POST', $path, $ts, $nonce, hash('sha256', '')]);

        return $this->withHeaders([
            'X-Chat-Device' => $cred['device_id'],
            'X-Chat-Ts' => $ts,
            'X-Chat-Nonce' => $nonce,
            'X-Chat-Sig' => hash_hmac('sha256', $canonical, $cred['secret']),
            'Accept' => 'application/json',
        ])->post($path, ['file' => $file]);
    }

    public function test_an_upload_lands_on_the_private_disk_and_is_served_by_its_token(): void
    {
        Storage::fake('uploads');
        Storage::fake('public');

        $user = ChatUser::create(['username' => 'up-'.Str::random(6), 'status' => ChatUser::APPROVED, 'last_seen_at' => now()]);
        $cred = ChatDevice::issue($user)->credentials();
        $channel = Channel::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Uploads', 'type' => 'group',
            'owner_id' => $user->id, 'invite_token' => Str::random(40), 'last_activity_at' => now(),
        ]);
        ChannelMember::create(['channel_id' => $channel->id, 'user_id' => $user->id, 'status' => 'approved']);

        $this->upload($cred, "/api/channels/{$channel->uuid}/messages", UploadedFile::fake()->image('photo.png', 40, 30))
            ->assertSuccessful();

        $message = Message::whereNotNull('file_path')->firstOrFail();

        // On the private disk, thumbnail included — and nothing where a
        // storage symlink could reach it.
        Storage::disk('uploads')->assertExists($message->file_path);
        Storage::disk('uploads')->assertExists($message->thumb_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertStringStartsWith('uploads/'.$channel->uuid.'/', $message->file_path);

        // The capability URL still serves it.
        $token = UploadView::where('message_id', $message->id)->where('user_id', $user->id)->value('token');
        $this->assertNotNull($token);
        $this->get('/u/'.$token)->assertOk();
        $this->get('/u/'.$token.'/thumb')->assertOk();
    }

    public function test_the_uploads_disk_is_outside_everything_storage_link_publishes(): void
    {
        $uploads = realpath(dirname(Storage::disk('uploads')->path('x'))) ?: Storage::disk('uploads')->path('');
        $public = storage_path('app'.DIRECTORY_SEPARATOR.'public');

        $this->assertStringStartsNotWith($public, $uploads);
        $this->assertStringStartsNotWith(public_path(), $uploads);

        // `php artisan storage:link` walks this list — it must stay empty.
        $this->assertSame([], config('filesystems.links'));
    }

    public function test_storage_link_creates_no_symlink(): void
    {
        $this->artisan('storage:link')->assertSuccessful();

        $this->assertFalse(is_link(public_path('storage')) || file_exists(public_path('storage')));
    }

    public function test_the_web_server_is_told_to_refuse_the_storage_path(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));

        $this->assertMatchesRegularExpression('/^\s*RewriteRule \^storage\(\/\|\$\) - \[F,L\]\s*$/m', $htaccess);
        // …and before the front controller, or it would never be reached.
        $this->assertLessThan(strpos($htaccess, 'RewriteRule ^ index.php'), strpos($htaccess, 'RewriteRule ^storage'));
    }

    public function test_a_capability_url_dies_when_its_holder_stops_being_a_member(): void
    {
        Storage::fake('uploads');

        $owner = ChatUser::create(['username' => 'own-'.Str::random(6), 'status' => ChatUser::APPROVED, 'last_seen_at' => now()]);
        $guest = ChatUser::create(['username' => 'gst-'.Str::random(6), 'status' => ChatUser::APPROVED, 'last_seen_at' => now()]);
        $channel = Channel::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Uploads', 'type' => 'group',
            'owner_id' => $owner->id, 'invite_token' => Str::random(40), 'last_activity_at' => now(),
        ]);
        ChannelMember::create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'status' => 'approved']);
        $membership = ChannelMember::create(['channel_id' => $channel->id, 'user_id' => $guest->id, 'status' => 'approved']);

        $this->upload(ChatDevice::issue($owner)->credentials(), "/api/channels/{$channel->uuid}/messages",
            UploadedFile::fake()->image('photo.png', 40, 30))->assertSuccessful();
        $message = Message::whereNotNull('file_path')->firstOrFail();

        $view = UploadView::create(['message_id' => $message->id, 'user_id' => $guest->id, 'token' => Str::random(48)]);
        $this->get('/u/'.$view->token.'/thumb')->assertOk();

        $membership->delete();

        $this->get('/u/'.$view->token.'/thumb')->assertNotFound();
        $this->get('/u/'.$view->token)->assertNotFound();
        $this->assertNull(UploadView::find($view->id));
        // The file itself is untouched — the owner's link still works.
        $ownerToken = UploadView::where('message_id', $message->id)->where('user_id', $owner->id)->value('token');
        $this->get('/u/'.$ownerToken.'/thumb')->assertOk();
    }
}
