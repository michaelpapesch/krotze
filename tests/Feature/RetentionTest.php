<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\ChatDevice;
use App\Models\ChatUser;
use App\Models\Message;
use App\Models\Setting;
use App\Support\Retention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $attributes */
    private function identity(array $attributes = []): ChatUser
    {
        $age = $attributes['age'] ?? now()->subDays(3);
        unset($attributes['age']);

        $user = ChatUser::create(array_merge([
            'username' => null,
            'status' => ChatUser::APPROVED,
            'last_seen_at' => $age,
        ], $attributes));

        // created_at is not fillable, and the rule reads both clocks.
        $user->forceFill(['created_at' => $age])->save();

        return $user->refresh();
    }

    /* ---------------------- what gets swept away ------------------------- */

    public function test_an_identity_that_never_got_a_username_is_dropped_after_a_day(): void
    {
        $abandoned = $this->identity();

        Retention::prune();

        $this->assertNull(ChatUser::find($abandoned->id));
    }

    public function test_a_pending_registration_with_no_username_is_dropped_too(): void
    {
        // Nothing for an admin to decide about: the row carries no name, so it
        // is only clutter in the pending list.
        $pending = $this->identity(['status' => ChatUser::PENDING]);

        Retention::prune();

        $this->assertNull(ChatUser::find($pending->id));
    }

    public function test_devices_go_with_the_identity(): void
    {
        $abandoned = $this->identity();
        ChatDevice::issue($abandoned, 'Throwaway');

        $this->assertSame(1, ChatDevice::count());

        Retention::prune();

        $this->assertSame(0, ChatDevice::count());
    }

    /* ------------------------- what survives ----------------------------- */

    public function test_an_identity_younger_than_a_day_is_left_alone(): void
    {
        $fresh = $this->identity(['age' => now()->subHours(2)]);

        Retention::prune();

        $this->assertNotNull(ChatUser::find($fresh->id));
    }

    public function test_a_brand_new_identity_without_a_last_seen_time_is_left_alone(): void
    {
        // last_seen_at alone would have deleted this immediately, however new
        // it is; created_at is what stops that.
        $fresh = ChatUser::create(['username' => null, 'status' => ChatUser::APPROVED]);
        $fresh->forceFill(['last_seen_at' => null])->save();

        Retention::prune();

        $this->assertNotNull(ChatUser::find($fresh->id));
    }

    public function test_an_identity_with_a_username_is_kept_however_idle(): void
    {
        $named = $this->identity(['username' => 'ghost', 'age' => now()->subYear()]);

        Retention::prune();

        $this->assertNotNull(ChatUser::find($named->id));
    }

    public function test_a_pending_registration_that_chose_a_name_waits_for_the_admin(): void
    {
        $waiting = $this->identity(['username' => 'patient', 'status' => ChatUser::PENDING]);

        Retention::prune();

        $this->assertNotNull(ChatUser::find($waiting->id), 'The admin still has somebody to approve.');
    }

    public function test_a_denied_identity_is_kept_so_the_denial_sticks(): void
    {
        // Deleting the row would simply let that person register again.
        $denied = $this->identity(['status' => ChatUser::DENIED, 'age' => now()->subYear()]);

        Retention::prune();

        $this->assertNotNull(ChatUser::find($denied->id));
    }

    public function test_an_identity_that_joined_something_is_kept(): void
    {
        $member = $this->identity();
        $channel = Channel::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Somewhere',
            'type' => 'group', 'last_activity_at' => now(),
        ]);
        ChannelMember::create(['channel_id' => $channel->id, 'user_id' => $member->id, 'status' => 'approved']);

        Retention::prune();

        $this->assertNotNull(ChatUser::find($member->id));
    }

    /* ------------------- the rest of the sweep still runs ----------------- */

    public function test_the_sweep_reports_what_it_removed(): void
    {
        $this->identity();

        $removed = Retention::prune();

        $this->assertSame(
            ['channels', 'uploads', 'identities', 'tombstones', 'cache'],
            array_keys($removed),
        );
        $this->assertSame(1, $removed['identities']);
    }

    public function test_inactive_channels_and_stale_tombstones_still_go(): void
    {
        $channel = Channel::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Silent', 'type' => 'group',
            'retention_days' => 7, 'last_activity_at' => now()->subDays(30),
        ]);

        $live = Channel::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Busy', 'type' => 'group',
            'retention_days' => 7, 'last_activity_at' => now(),
        ]);
        $tombstone = Message::create([
            'channel_id' => $live->id, 'author_alias' => 'someone', 'kind' => 'image',
        ]);
        $tombstone->forceFill(['file_deleted_at' => now()->subHours(3), 'file_deleted_by' => 'someone'])->save();

        $removed = Retention::prune();

        $this->assertNull(Channel::find($channel->id));
        $this->assertNotNull(Channel::find($live->id));
        $this->assertNull(Message::find($tombstone->id));
        $this->assertSame(1, $removed['channels']);
        $this->assertSame(1, $removed['tombstones']);
    }

    public function test_expired_uploads_go_unless_the_admin_switched_that_off(): void
    {
        $channel = Channel::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Files', 'type' => 'group',
            'retention_days' => 365, 'last_activity_at' => now(),
        ]);
        $old = Message::create([
            'channel_id' => $channel->id, 'author_alias' => 'someone',
            'kind' => 'image', 'file_path' => 'uploads/x/y.png',
        ]);
        $old->forceFill(['created_at' => now()->subDays(5)])->save();

        Setting::put('upload_autodelete', false);
        Retention::prune();
        $this->assertNotNull(Message::find($old->id), 'Auto-deletion is off.');

        Setting::put('upload_autodelete', true);
        Setting::put('upload_retention_days', '1');
        Retention::prune();
        $this->assertNull(Message::find($old->id));
    }

    public function test_the_artisan_command_runs_the_same_rules(): void
    {
        $abandoned = $this->identity();

        $this->artisan('channels:cleanup')
            ->expectsOutputToContain('identities')
            ->assertSuccessful();

        // The command used to prune only channels and cache, so an abandoned
        // identity survived it.
        $this->assertNull(ChatUser::find($abandoned->id));
    }
}
