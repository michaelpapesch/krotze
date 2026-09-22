<?php

namespace Tests\Feature;

use App\Models\ChatUser;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationGateTest extends TestCase
{
    use RefreshDatabase;
    use SignsChatRequests;

    public function test_open_server_registers_anybody(): void
    {
        $res = $this->postJson('/api/session', ['device_name' => 'Test'])->assertStatus(201);

        $this->assertSame(ChatUser::APPROVED, $res->json('status'));
    }

    public function test_closed_server_refuses_registration_without_the_invite(): void
    {
        Setting::put('open_registrations', false);

        $this->postJson('/api/session', ['device_name' => 'Test'])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'registration_closed');

        $this->postJson('/api/session', ['device_name' => 'Test', 'invite' => 'nonsense'])
            ->assertStatus(403);
    }

    public function test_closed_server_accepts_the_current_invite_only(): void
    {
        Setting::put('open_registrations', false);
        $stale = Setting::inviteToken();

        $this->postJson('/api/session', ['device_name' => 'Test', 'invite' => $stale])
            ->assertStatus(201)
            ->assertJsonPath('status', ChatUser::APPROVED);

        // Recreating the link locks out everybody holding the old one.
        Setting::rotateInviteToken();

        $this->postJson('/api/session', ['device_name' => 'Test', 'invite' => $stale])
            ->assertStatus(403);
    }

    public function test_manual_approval_parks_the_identity_until_an_admin_decides(): void
    {
        Setting::put('open_registrations', false);
        Setting::put('manual_approval', true);

        $cred = $this->postJson('/api/session', [
            'device_name' => 'Test',
            'invite' => Setting::inviteToken(),
        ])->assertStatus(201)->json();

        $this->assertSame(ChatUser::PENDING, $cred['status']);

        // It may look at its own state and claim a name…
        $this->signed($cred, 'GET', '/api/state')
            ->assertOk()
            ->assertJsonPath('account_status', ChatUser::PENDING)
            ->assertJsonPath('channels', []);

        $this->signed($cred, 'POST', '/api/profile/username', ['username' => 'waiting-guest'])
            ->assertOk();

        // …but it cannot touch anything else.
        $this->signed($cred, 'POST', '/api/channels', ['name' => 'Nope'])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'registration_pending');

        // The admin approves, and the same device is in.
        ChatUser::where('id', $cred['user_id'])->update(['status' => ChatUser::APPROVED]);

        $this->signed($cred, 'GET', '/api/state')
            ->assertOk()
            ->assertJsonPath('account_status', ChatUser::APPROVED);

        $this->signed($cred, 'POST', '/api/channels', ['name' => 'Yes'])->assertStatus(201);
    }

    public function test_a_denied_identity_is_locked_out_entirely(): void
    {
        $cred = $this->postJson('/api/session', ['device_name' => 'Test'])->json();
        ChatUser::where('id', $cred['user_id'])->update(['status' => ChatUser::DENIED]);

        $this->signed($cred, 'GET', '/api/state')
            ->assertStatus(403)
            ->assertJsonPath('reason', 'registration_denied');
    }

    public function test_the_landing_page_says_so_when_the_server_is_invite_only(): void
    {
        $this->get('/')->assertOk()->assertDontSee('invite-only');

        Setting::put('open_registrations', false);

        $this->get('/')->assertOk()->assertSee('invite-only');
    }
}
