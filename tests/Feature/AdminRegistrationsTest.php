<?php

namespace Tests\Feature;

use App\Models\ChatUser;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRegistrationsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        // password_changed_at set: an admin who has never chosen a password is
        // held on the profile page until they do (see AdminAuth), which is not
        // what these tests are about.
        return User::firstOrCreate(
            ['email' => 'admin@example.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'password_changed_at' => now()],
        );
    }

    public function test_the_dashboard_lists_and_searches_waiting_registrations(): void
    {
        ChatUser::create(['username' => 'alice', 'status' => ChatUser::PENDING]);
        ChatUser::create(['username' => 'bob', 'status' => ChatUser::PENDING]);
        ChatUser::create(['username' => 'carol', 'status' => ChatUser::APPROVED]);

        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->assertSee('alice')
            ->assertSee('bob')
            ->assertDontSee('>carol', false);

        $this->actingAs($this->admin())
            ->get('/admin?q=ali')
            ->assertOk()
            ->assertSee('alice')
            ->assertDontSee('>bob<', false);
    }

    public function test_selected_registrations_can_be_approved_or_denied_in_bulk(): void
    {
        $ids = collect(['a', 'b', 'c'])
            ->map(fn ($n) => ChatUser::create(['username' => $n, 'status' => ChatUser::PENDING])->id);

        $this->actingAs($this->admin())
            ->post('/admin/registrations', ['action' => 'approve', 'ids' => [$ids[0], $ids[1]]])
            ->assertRedirect();

        $this->assertSame(ChatUser::APPROVED, ChatUser::find($ids[0])->status);
        $this->assertSame(ChatUser::APPROVED, ChatUser::find($ids[1])->status);
        $this->assertSame(ChatUser::PENDING, ChatUser::find($ids[2])->status);

        $this->actingAs($this->admin())
            ->post('/admin/registrations', ['action' => 'deny', 'ids' => [$ids[2]]])
            ->assertRedirect();

        $this->assertSame(ChatUser::DENIED, ChatUser::find($ids[2])->status);
        $this->assertNotNull(ChatUser::find($ids[2])->decided_at);
    }

    public function test_saving_the_settings_form_stores_every_switch(): void
    {
        $this->actingAs($this->admin())->post('/admin/settings', [
            'manual_approval' => '1',
            'upload_autodelete' => '1',
            'upload_retention_days' => '14',
            'upload_view_limit' => '1',
            'upload_view_minutes' => '30',
        ])->assertRedirect();

        $this->assertFalse(Setting::registrationsOpen()); // unchecked box is absent
        $this->assertTrue(Setting::needsApproval());
        $this->assertSame(14, Setting::uploadRetentionDays());
        $this->assertSame(30, Setting::uploadViewMinutes());
    }

    public function test_unchecked_upload_switches_turn_the_limits_off(): void
    {
        $this->actingAs($this->admin())->post('/admin/settings', ['open_registrations' => '1'])
            ->assertRedirect();

        $this->assertTrue(Setting::registrationsOpen());
        $this->assertNull(Setting::uploadRetentionDays());
        $this->assertNull(Setting::uploadViewMinutes());
    }

    public function test_the_minute_limit_is_capped_at_an_hour(): void
    {
        $this->actingAs($this->admin())->post('/admin/settings', [
            'upload_view_limit' => '1',
            'upload_view_minutes' => '600',
        ])->assertSessionHasErrors('upload_view_minutes');
    }

    public function test_recreating_the_invite_replaces_the_link(): void
    {
        $before = Setting::inviteToken();

        $this->actingAs($this->admin())->post('/admin/invite/rotate')->assertRedirect();

        $this->assertNotSame($before, Setting::inviteToken());
    }

    public function test_the_admin_area_needs_a_login(): void
    {
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->post('/admin/settings')->assertRedirect(route('admin.login'));
    }
}
