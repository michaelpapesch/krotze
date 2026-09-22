<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccountTest extends TestCase
{
    use RefreshDatabase;

    /** Passes the strength rules, so tests exercise the real validator. */
    private const GOOD_PASSWORD = 'Tr0ub4dor&3-horse-staple';

    private function admin(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'seeded-password',
            'password_changed_at' => now(),
        ], $attributes));
    }

    /* --------------------- forced first-run password ---------------------- */

    public function test_a_never_changed_password_locks_the_rest_of_the_panel(): void
    {
        $admin = $this->admin(['password_changed_at' => null]);

        $this->actingAs($admin)->get('/admin')->assertRedirect(route('admin.profile'));
        $this->actingAs($admin)->post('/admin/settings')->assertRedirect(route('admin.profile'));

        // …but the profile page itself stays reachable, or there would be no
        // way to resolve it.
        $this->actingAs($admin)->get('/admin/profile')
            ->assertOk()
            ->assertSee('Choose a password before you go on.');
    }

    public function test_the_first_change_needs_no_current_password_and_unlocks_the_panel(): void
    {
        $admin = $this->admin(['password_changed_at' => null]);

        $this->actingAs($admin)->post('/admin/profile/password', [
            'password' => self::GOOD_PASSWORD,
            'password_confirmation' => self::GOOD_PASSWORD,
        ])->assertRedirect(route('admin.profile'));

        $admin->refresh();
        $this->assertNotNull($admin->password_changed_at);
        $this->assertTrue(Hash::check(self::GOOD_PASSWORD, $admin->password));

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_a_later_change_does_need_the_current_password(): void
    {
        $admin = $this->admin(['password' => 'current-password']);

        $this->actingAs($admin)->post('/admin/profile/password', [
            'current_password' => 'wrong',
            'password' => self::GOOD_PASSWORD,
            'password_confirmation' => self::GOOD_PASSWORD,
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('current-password', $admin->fresh()->password));
    }

    public function test_weak_or_reused_passwords_are_refused(): void
    {
        $admin = $this->admin(['password' => 'current-password']);

        $this->actingAs($admin)->post('/admin/profile/password', [
            'current_password' => 'current-password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->actingAs($admin)->post('/admin/profile/password', [
            'current_password' => 'current-password',
            'password' => self::GOOD_PASSWORD,
            'password_confirmation' => 'different-'.self::GOOD_PASSWORD,
        ])->assertSessionHasErrors('password');
    }

    /* ------------------------------- e-mail -------------------------------- */

    public function test_changing_the_email_is_confirmed_with_the_password(): void
    {
        $admin = $this->admin(['password' => 'current-password']);

        $this->actingAs($admin)->post('/admin/profile/email', [
            'email' => 'new@example.test',
            'current_password' => 'wrong',
        ])->assertSessionHasErrors('current_password');
        $this->assertSame('admin@example.test', $admin->fresh()->email);

        $this->actingAs($admin)->post('/admin/profile/email', [
            'email' => 'new@example.test',
            'current_password' => 'current-password',
        ])->assertSessionHasNoErrors();
        $this->assertSame('new@example.test', $admin->fresh()->email);
    }

    /* ----------------------------- two-factor ------------------------------ */

    public function test_enrolment_only_takes_effect_once_a_code_confirms_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/profile/two-factor')->assertRedirect(route('admin.profile'));

        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret);
        // An abandoned setup must not lock anybody out, so login is untouched
        // until the code proves the authenticator works.
        $this->assertFalse($admin->twoFactorEnabled());

        $this->actingAs($admin)->post('/admin/profile/two-factor/confirm', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertFalse($admin->fresh()->twoFactorEnabled());

        $code = Totp::at($admin->two_factor_secret, intdiv(time(), Totp::PERIOD));
        $response = $this->actingAs($admin)->post('/admin/profile/two-factor/confirm', ['code' => $code]);

        $response->assertRedirect(route('admin.profile'));
        $this->assertTrue($admin->fresh()->twoFactorEnabled());
        $this->assertCount(User::RECOVERY_CODE_COUNT, $response->getSession()->get('recovery_codes'));
    }

    public function test_login_stops_at_the_challenge_when_two_factor_is_on(): void
    {
        $admin = $this->enrolled();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'current-password'])
            ->assertRedirect(route('admin.2fa.challenge'));

        // The password alone must not authenticate anybody.
        $this->assertGuest();

        $this->get('/admin')->assertRedirect(route('admin.login'));
    }

    public function test_the_challenge_accepts_a_valid_code(): void
    {
        $admin = $this->enrolled();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'current-password']);

        $this->post('/admin/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $code = Totp::at($admin->two_factor_secret, intdiv(time(), Totp::PERIOD));
        $this->post('/admin/two-factor', ['code' => $code])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_recovery_code_works_once(): void
    {
        $admin = $this->enrolled();
        $codes = $admin->regenerateRecoveryCodes();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'current-password']);
        $this->post('/admin/two-factor', ['code' => $codes[0]])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);

        // Spent: it is gone from the account and will not work again.
        $this->assertCount(User::RECOVERY_CODE_COUNT - 1, $admin->fresh()->two_factor_recovery_codes);

        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'current-password']);
        $this->post('/admin/two-factor', ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_the_challenge_page_is_not_reachable_without_passing_the_password(): void
    {
        $this->enrolled();

        $this->get('/admin/two-factor')->assertRedirect(route('admin.login'));
        $this->post('/admin/two-factor', ['code' => '000000'])->assertRedirect(route('admin.login'));
    }

    public function test_turning_two_factor_off_is_confirmed_with_the_password(): void
    {
        $admin = $this->enrolled();

        $this->actingAs($admin)->delete('/admin/profile/two-factor', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        $this->assertTrue($admin->fresh()->twoFactorEnabled());

        $this->actingAs($admin)->delete('/admin/profile/two-factor', ['current_password' => 'current-password'])
            ->assertSessionHasNoErrors();

        $admin->refresh();
        $this->assertFalse($admin->twoFactorEnabled());
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_recovery_codes);
    }

    public function test_the_secret_and_recovery_codes_are_encrypted_at_rest(): void
    {
        $admin = $this->enrolled();
        $codes = $admin->regenerateRecoveryCodes();

        $row = \Illuminate\Support\Facades\DB::table('users')->where('id', $admin->id)->first();

        // A database dump must not hand anybody working codes.
        $this->assertNotSame($admin->two_factor_secret, $row->two_factor_secret);
        $this->assertStringNotContainsString($admin->two_factor_secret, $row->two_factor_secret);
        $this->assertStringNotContainsString($codes[0], (string) $row->two_factor_recovery_codes);
    }

    public function test_the_dashboard_nudges_until_two_factor_is_on(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin')->assertSee('Two-factor authentication is off');

        $this->enrolled($admin);
        $this->actingAs($admin->fresh())->get('/admin')->assertDontSee('Two-factor authentication is off');
    }

    /* ------------------------------- seeding ------------------------------- */

    public function test_seeding_never_overwrites_a_password_an_admin_chose(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@krotze.com');
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $seeded = User::where('email', $email)->firstOrFail();
        $this->assertTrue($seeded->mustChangePassword(), 'A seeded password counts as never chosen.');

        $seeded->forceFill(['password' => self::GOOD_PASSWORD, 'password_changed_at' => now()])->save();

        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertTrue(Hash::check(self::GOOD_PASSWORD, User::where('email', $email)->first()->password));
    }

    /** An admin with two-factor confirmed and a known password. */
    private function enrolled(?User $admin = null): User
    {
        $admin ??= $this->admin(['password' => 'current-password']);
        $secret = $admin->startTwoFactorEnrolment();
        $admin->confirmTwoFactor(Totp::at($secret, intdiv(time(), Totp::PERIOD)));

        return $admin->refresh();
    }
}
