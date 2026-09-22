<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AdminPasswordReset;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Rese7-my-Passw0rd!';

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'old-password',
            'password_changed_at' => now()->subMonth(),
        ]);
    }

    public function test_the_login_page_links_to_the_reset_flow(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Forgot password?')
            ->assertSee(route('admin.password.request'));
    }

    public function test_a_reset_link_is_sent_to_a_known_address(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->post('/admin/forgot-password', ['email' => $admin->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($admin, AdminPasswordReset::class);
    }

    public function test_an_unknown_address_gets_the_same_answer_and_no_mail(): void
    {
        Notification::fake();
        $this->admin();

        $known = $this->post('/admin/forgot-password', ['email' => 'admin@example.test']);
        $unknown = $this->post('/admin/forgot-password', ['email' => 'nobody@example.test']);

        // Identical wording either way: whether an address is an admin account
        // must not be something a stranger can test with this form.
        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
        Notification::assertCount(1);
    }

    public function test_the_link_sets_a_new_password_and_clears_the_forced_change(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->post('/admin/forgot-password', ['email' => $admin->email]);

        $token = null;
        Notification::assertSentTo($admin, AdminPasswordReset::class, function ($notification) use (&$token) {
            $token = (fn () => $this->token)->call($notification);

            return true;
        });

        $this->get(route('admin.password.reset', ['token' => $token, 'email' => $admin->email]))
            ->assertOk()
            ->assertSee('Choose a new password');

        $this->post('/admin/reset-password', [
            'token' => $token,
            'email' => $admin->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('admin.login'));

        $admin->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $admin->password));
        $this->assertTrue($admin->password_changed_at->isToday());

        // Deliberately not signed in: see below.
        $this->assertGuest();
    }

    public function test_a_reset_does_not_get_past_two_factor(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $secret = $admin->startTwoFactorEnrolment();
        $admin->confirmTwoFactor(Totp::at($secret, intdiv(time(), Totp::PERIOD)));

        $this->post('/admin/forgot-password', ['email' => $admin->email]);
        $token = null;
        Notification::assertSentTo($admin, AdminPasswordReset::class, function ($n) use (&$token) {
            $token = (fn () => $this->token)->call($n);

            return true;
        });

        $this->post('/admin/reset-password', [
            'token' => $token,
            'email' => $admin->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        // Somebody who only controls the mailbox now knows the password — and
        // still cannot reach the panel.
        $this->post('/admin/login', ['email' => $admin->email, 'password' => self::NEW_PASSWORD])
            ->assertRedirect(route('admin.2fa.challenge'));
        $this->assertGuest();
        $this->get('/admin')->assertRedirect(route('admin.login'));
    }

    public function test_a_token_works_only_once(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->post('/admin/forgot-password', ['email' => $admin->email]);
        $token = null;
        Notification::assertSentTo($admin, AdminPasswordReset::class, function ($n) use (&$token) {
            $token = (fn () => $this->token)->call($n);

            return true;
        });

        $payload = [
            'token' => $token,
            'email' => $admin->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];

        $this->post('/admin/reset-password', $payload)->assertRedirect(route('admin.login'));
        $this->post('/admin/reset-password', $payload)->assertSessionHasErrors('email');
    }

    public function test_a_forged_token_is_refused(): void
    {
        $admin = $this->admin();

        $this->post('/admin/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $admin->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('old-password', $admin->fresh()->password));
    }

    public function test_the_new_password_must_meet_the_same_rules(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $this->post('/admin/forgot-password', ['email' => $admin->email]);
        $token = null;
        Notification::assertSentTo($admin, AdminPasswordReset::class, function ($n) use (&$token) {
            $token = (fn () => $this->token)->call($n);

            return true;
        });

        $this->post('/admin/reset-password', [
            'token' => $token,
            'email' => $admin->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('old-password', $admin->fresh()->password));
    }

    public function test_the_mail_carries_a_working_link(): void
    {
        $admin = $this->admin();
        $mail = (new AdminPasswordReset('sample-token'))->toMail($admin);
        $rendered = $mail->render();

        $this->assertStringContainsString(route('admin.password.reset', [
            'token' => 'sample-token', 'email' => $admin->email,
        ]), html_entity_decode($rendered));
        $this->assertStringContainsString('60 minutes', $rendered);
    }
}
