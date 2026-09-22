<?php

namespace Tests\Feature;

use App\Models\ChatDevice;
use App\Models\ChatUser;
use App\Models\PushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceRemovalTest extends TestCase
{
    use RefreshDatabase;
    use SignsChatRequests;

    public function test_a_device_can_sign_itself_out(): void
    {
        $cred = $this->register();

        $id = collect($this->signed($cred, 'GET', '/api/profile')->json('devices'))
            ->firstWhere('current', true)['id'];

        $this->signed($cred, 'DELETE', "/api/profile/devices/{$id}")
            ->assertOk()
            // The client needs to tell "somebody else was signed out" apart from
            // "you just signed yourself out", so it knows to wipe local state.
            ->assertJsonPath('was_current', true);

        $this->assertSame(0, ChatDevice::count());

        // The credentials really are dead: the next signed call is refused, and
        // the client treats device_unknown as "start over".
        $this->signed($cred, 'GET', '/api/state')
            ->assertStatus(401)
            ->assertJsonPath('reason', 'device_unknown');
    }

    public function test_signing_a_device_out_leaves_the_identity_and_its_data_intact(): void
    {
        $cred = $this->register();
        $uuid = $this->signed($cred, 'POST', '/api/channels', ['name' => 'Keepsake'])->json('uuid');
        $this->signed($cred, 'POST', "/api/channels/{$uuid}/messages", ['body' => 'still here'])->assertStatus(201);

        $id = collect($this->signed($cred, 'GET', '/api/profile')->json('devices'))
            ->firstWhere('current', true)['id'];
        $this->signed($cred, 'DELETE', "/api/profile/devices/{$id}")->assertOk();

        // Nothing is deleted — an exported identity can still reach all of it.
        $user = ChatUser::find($cred['user_id']);
        $this->assertNotNull($user);
        $this->assertSame(1, $user->memberships()->count());
        $this->assertSame('still here', \App\Models\Message::first()->body);
    }

    public function test_removing_a_device_reports_that_it_was_not_the_current_one(): void
    {
        $first = $this->register();

        $code = $this->signed($first, 'POST', '/api/profile/devices/pair')->json('code');
        $second = $this->postJson('/api/devices/claim', ['code' => $code, 'device_name' => 'Second'])->json();

        $otherId = collect($this->signed($second, 'GET', '/api/profile')->json('devices'))
            ->firstWhere('current', false)['id'];

        $this->signed($second, 'DELETE', "/api/profile/devices/{$otherId}")
            ->assertOk()
            ->assertJsonPath('was_current', false);

        // The caller keeps working; only the other device is gone.
        $this->signed($second, 'GET', '/api/state')->assertOk();
        $this->assertSame(1, ChatDevice::count());
    }

    public function test_a_signed_out_device_stops_being_pushed_to(): void
    {
        $cred = $this->register();

        $this->signed($cred, 'POST', '/api/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/token',
            'keys' => [
                'p256dh' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
                'auth' => 'BTBZMqHH6r4Tts7J_aSIgg',
            ],
        ])->assertStatus(201);

        $id = collect($this->signed($cred, 'GET', '/api/profile')->json('devices'))
            ->firstWhere('current', true)['id'];
        $this->signed($cred, 'DELETE', "/api/profile/devices/{$id}")->assertOk();

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_a_device_belonging_to_somebody_else_cannot_be_removed(): void
    {
        $mine = $this->register();
        $theirs = $this->register();

        $theirDeviceId = collect($this->signed($theirs, 'GET', '/api/profile')->json('devices'))
            ->firstWhere('current', true)['id'];

        $this->signed($mine, 'DELETE', "/api/profile/devices/{$theirDeviceId}")->assertStatus(404);

        $this->assertSame(2, ChatDevice::count());
    }

    private function register(): array
    {
        $cred = $this->postJson('/api/session', ['device_name' => 'Test'])->json();
        $this->signed($cred, 'POST', '/api/profile/username', ['username' => 'dev-'.$cred['user_id']]);

        return $cred;
    }
}
