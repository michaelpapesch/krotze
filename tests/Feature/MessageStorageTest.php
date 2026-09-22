<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChatUser;
use App\Models\Message;
use App\Models\Setting;
use App\Models\UploadView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MessageStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_text_and_file_names_are_ciphertext_in_the_database(): void
    {
        $message = $this->message([
            'body' => 'meet me at the usual place',
            'kind' => 'image',
            'file_name' => 'the-evidence.png',
            'file_path' => 'uploads/x/y.png',
        ]);

        $raw = DB::table('messages')->where('id', $message->id)->first();

        $this->assertNotSame('meet me at the usual place', $raw->body);
        $this->assertStringNotContainsString('usual place', $raw->body);
        $this->assertStringNotContainsString('the-evidence', $raw->file_name);

        // …and the model still hands back what was written.
        $fresh = Message::find($message->id);
        $this->assertSame('meet me at the usual place', $fresh->body);
        $this->assertSame('the-evidence.png', $fresh->file_name);
    }

    public function test_uploads_expire_after_the_configured_number_of_days(): void
    {
        $message = $this->message(['kind' => 'image', 'file_path' => 'uploads/x/y.png']);
        $message->forceFill(['created_at' => now()->subDays(2)])->save();

        Setting::put('upload_retention_days', '1');
        $this->assertTrue($message->fileExpired());

        Setting::put('upload_retention_days', '7');
        $this->assertFalse($message->fileExpired());

        // Switched off entirely, an upload only dies with its channel.
        Setting::put('upload_autodelete', false);
        Setting::put('upload_retention_days', '1');
        $this->assertFalse($message->fileExpired());
        $this->assertNull($message->fileExpiresAt());
    }

    public function test_the_view_window_follows_the_configured_number_of_minutes(): void
    {
        $message = $this->message(['kind' => 'image', 'file_path' => 'uploads/x/y.png']);

        $view = UploadView::create([
            'message_id' => $message->id,
            'user_id' => $message->user_id,
            'token' => Str::random(48),
            'first_viewed_at' => now()->subMinutes(10),
        ]);

        Setting::put('upload_view_minutes', '5');
        $this->assertTrue($view->viewWindowExpired());

        Setting::put('upload_view_minutes', '30');
        $this->assertFalse($view->viewWindowExpired());

        Setting::put('upload_view_limit', false);
        Setting::put('upload_view_minutes', '5');
        $this->assertFalse($view->viewWindowExpired());
        $this->assertNull($view->viewExpiresAt());
    }

    private function message(array $attributes = []): Message
    {
        $user = ChatUser::create(['username' => 'tester-'.Str::random(6), 'last_seen_at' => now()]);
        $channel = Channel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test',
            'type' => 'group',
            'owner_id' => $user->id,
            'last_activity_at' => now(),
        ]);

        return Message::create(array_merge([
            'channel_id' => $channel->id,
            'user_id' => $user->id,
            'author_alias' => $user->username,
            'kind' => 'text',
            'body' => 'hello',
        ], $attributes));
    }
}
