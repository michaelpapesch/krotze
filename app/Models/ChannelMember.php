<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelMember extends Model
{
    public const ROLE_MEMBER = 'member';

    /** May start live streams, by the owner's leave. */
    public const ROLE_STREAMER = 'streamer';

    protected $fillable = [
        'channel_id', 'user_id', 'status', 'role', 'pinned', 'hidden', 'muted', 'last_read_message_id',
    ];

    protected $attributes = ['role' => self::ROLE_MEMBER];

    protected static function booted(): void
    {
        // Whoever stops being a member stops streaming.
        static::deleting(fn (self $member) => Stream::endFor($member->channel_id, $member->user_id));
    }

    protected $casts = ['pinned' => 'boolean', 'hidden' => 'boolean', 'muted' => 'boolean'];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function user()
    {
        return $this->belongsTo(ChatUser::class, 'user_id');
    }

    /** What the member is in this channel, as clients see it: owner | streamer | member. */
    public function roleIn(Channel $channel): string
    {
        return $this->user_id === $channel->owner_id ? 'owner' : $this->role;
    }

    /**
     * Live streams: the owner always may, members once the owner made them a
     * streamer. A private conversation has no owner — both sides may.
     */
    public function canStream(Channel $channel): bool
    {
        if ($this->status !== 'approved') {
            return false;
        }

        return $channel->type === 'private'
            || $this->user_id === $channel->owner_id
            || $this->role === self::ROLE_STREAMER;
    }

    /** The member's global username (identity is no longer per channel). */
    public function username(): string
    {
        return $this->user?->displayName() ?? 'anonymous';
    }
}
