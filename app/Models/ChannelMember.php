<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelMember extends Model
{
    protected $fillable = [
        'channel_id', 'user_id', 'status', 'pinned', 'hidden', 'muted', 'last_read_message_id',
    ];

    protected $casts = ['pinned' => 'boolean', 'hidden' => 'boolean', 'muted' => 'boolean'];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function user()
    {
        return $this->belongsTo(ChatUser::class, 'user_id');
    }

    /** The member's global username (identity is no longer per channel). */
    public function username(): string
    {
        return $this->user?->displayName() ?? 'anonymous';
    }
}
