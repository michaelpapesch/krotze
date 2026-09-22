<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Totp;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'password_changed_at'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** How many one-time codes are handed out when 2FA is switched on. */
    public const RECOVERY_CODE_COUNT = 8;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            // A database dump must not hand anybody the ability to generate
            // valid codes, or to spend a recovery code.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    /**
     * Has this account ever had its password set by a person? A seeded password
     * that nobody has touched is the case the forced first-run change exists to
     * catch, so "never" is treated as "must change now".
     */
    public function mustChangePassword(): bool
    {
        return $this->password_changed_at === null;
    }

    public function twoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Begin enrolment: a secret is stored but not yet confirmed, so login is
     * unaffected until a code proves the authenticator actually works.
     */
    public function startTwoFactorEnrolment(): string
    {
        $secret = Totp::generateSecret();

        $this->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return $secret;
    }

    /** Finish enrolment if the code checks out, returning the recovery codes. */
    public function confirmTwoFactor(string $code): ?array
    {
        if (! $this->two_factor_secret || ! Totp::verify($this->two_factor_secret, $code)) {
            return null;
        }

        $codes = self::freshRecoveryCodes();
        $this->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes,
        ])->save();

        return $codes;
    }

    public function disableTwoFactor(): void
    {
        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * Accept a six-digit code, or spend one recovery code.
     *
     * A recovery code works exactly once — it is removed on use, so a code read
     * off a screenshot or a printout cannot be replayed.
     */
    public function verifyTwoFactor(string $input): bool
    {
        if (! $this->twoFactorEnabled()) {
            return false;
        }

        if (Totp::verify($this->two_factor_secret, $input)) {
            return true;
        }

        $normalised = strtolower(trim($input));
        $remaining = array_values(array_filter(
            $this->two_factor_recovery_codes ?? [],
            fn ($code) => ! hash_equals(strtolower($code), $normalised),
        ));

        if (count($remaining) === count($this->two_factor_recovery_codes ?? [])) {
            return false;
        }

        $this->forceFill(['two_factor_recovery_codes' => $remaining])->save();

        return true;
    }

    /** Replace the recovery codes, e.g. after spending several. */
    public function regenerateRecoveryCodes(): array
    {
        $codes = self::freshRecoveryCodes();
        $this->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    /** @return list<string> */
    private static function freshRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();
    }
}
