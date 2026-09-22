<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatUser extends Model
{
    public const APPROVED = 'approved';

    public const PENDING = 'pending';

    public const DENIED = 'denied';

    protected $fillable = ['username', 'status', 'token', 'transfer_hash', 'last_seen_at', 'decided_at'];

    protected $casts = ['last_seen_at' => 'datetime', 'decided_at' => 'datetime'];

    protected $hidden = ['token', 'transfer_hash'];

    public function memberships()
    {
        return $this->hasMany(ChannelMember::class, 'user_id');
    }

    public function notifications()
    {
        return $this->hasMany(ChatNotification::class, 'user_id');
    }

    public function devices()
    {
        return $this->hasMany(ChatDevice::class, 'user_id');
    }

    /** Display name for message authors and member lists. */
    public function displayName(): string
    {
        return $this->username ?: 'anonymous';
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }
}
