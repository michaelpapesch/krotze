<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Server-wide switches, edited in the admin panel. There are a handful of rows
 * and they are read many times per request (upload expiry is asked once per
 * message), so the whole table is cached as one entry. Writing a setting drops
 * that entry from the shared cache, so long-lived processes — queue workers,
 * the scheduler — pick the change up as well.
 */
class Setting extends Model
{
    private const CACHE_KEY = 'settings.all';

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = Cache::rememberForever(
            self::CACHE_KEY,
            fn () => self::query()->pluck('value', 'key')->all(),
        );

        return $all[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        return $value === null ? $default : $value === '1';
    }

    public static function put(string $key, string|bool|null $value): void
    {
        $value = is_bool($value) ? ($value ? '1' : '0') : $value;

        self::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    /** Registrations are open to anyone who finds the site. */
    public static function registrationsOpen(): bool
    {
        return self::bool('open_registrations', true);
    }

    /**
     * Every new identity waits for an admin decision. Only meaningful while
     * registrations are closed — an open server approves on sight.
     */
    public static function needsApproval(): bool
    {
        return ! self::registrationsOpen() && self::bool('manual_approval');
    }

    /** How long an upload survives, in days — null when uploads are kept. */
    public static function uploadRetentionDays(): ?int
    {
        if (! self::bool('upload_autodelete', true)) {
            return null;
        }

        return max(1, (int) self::get('upload_retention_days', '1'));
    }

    /**
     * How long a member may keep looking at an upload after opening it, in
     * minutes — null when opening one does not start a countdown at all.
     */
    public static function uploadViewMinutes(): ?int
    {
        if (! self::bool('upload_view_limit', true)) {
            return null;
        }

        return min(60, max(1, (int) self::get('upload_view_minutes', '5')));
    }

    /** The one link that lets somebody register while registrations are closed. */
    public static function inviteToken(): string
    {
        $token = self::get('registration_invite_token');
        if (! $token) {
            $token = Str::random(40);
            self::put('registration_invite_token', $token);
        }

        return $token;
    }

    public static function rotateInviteToken(): string
    {
        $token = Str::random(40);
        self::put('registration_invite_token', $token);

        return $token;
    }

    public static function inviteUrl(): string
    {
        return url('/register/'.self::inviteToken());
    }
}
