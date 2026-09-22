<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A device that may act as a ChatUser. Authentication is a per-request HMAC
 * signature made with `secret`; the secret itself crosses the network exactly
 * once — in the response that registers the device — and never again.
 */
class ChatDevice extends Model
{
    protected $fillable = ['user_id', 'public_id', 'secret', 'name', 'last_seen_at'];

    protected $casts = [
        'secret' => 'encrypted',
        'last_seen_at' => 'datetime',
    ];

    protected $hidden = ['secret'];

    public function user()
    {
        return $this->belongsTo(ChatUser::class, 'user_id');
    }

    /** Register a new device for this user and return it with its fresh secret. */
    public static function issue(ChatUser $user, ?string $name = null): self
    {
        return self::create([
            'user_id' => $user->id,
            'public_id' => Str::random(32),
            'secret' => bin2hex(random_bytes(32)),
            'name' => self::cleanName($name),
            'last_seen_at' => now(),
        ]);
    }

    public static function cleanName(?string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));

        return $name !== '' ? mb_substr($name, 0, 60) : 'Unnamed device';
    }

    /** What a freshly registered device needs to sign its future requests. */
    public function credentials(): array
    {
        return [
            'device_id' => $this->public_id,
            'secret' => $this->secret,
            'user_id' => $this->user_id,
            'username' => $this->user?->username,
            'status' => $this->user?->status,
        ];
    }
}
