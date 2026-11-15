<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A live voice / camera / screen stream in a channel.
 *
 * Media travels browser → live server → browsers over WebSockets (see
 * App\Support\Live\Server); clients never connect to each other. This row is
 * written by the live server and exists so that the ordinary HTTP side can
 * announce the stream through `/api/state` and end it (role withdrawn, member
 * removed) by deleting it — the live server notices within a couple of seconds.
 */
class Stream extends Model
{
    /** The live server refreshes `last_seen_at` every tick; older rows are dead. */
    public const ACTIVE_SECONDS = 15;

    /** How many members may stream in one channel at the same time. */
    public const MAX_PER_CHANNEL = 4;

    protected $fillable = ['channel_id', 'user_id', 'device_id', 'audio', 'video', 'last_seen_at'];

    protected $casts = ['audio' => 'boolean', 'last_seen_at' => 'datetime'];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function user()
    {
        return $this->belongsTo(ChatUser::class, 'user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('last_seen_at', '>=', now()->subSeconds(self::ACTIVE_SECONDS));
    }

    /** The shape clients see in `/api/state`. */
    public function summary(?ChatDevice $viewer = null): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'username' => $this->user?->displayName() ?? 'anonymous',
            'audio' => $this->audio,
            'video' => $this->video,
            'mine' => $viewer !== null && $this->device_id === $viewer->id,
        ];
    }

    /** End whatever a user is streaming in a channel (role lost, removed, left). */
    public static function endFor(int $channelId, int $userId): void
    {
        self::where('channel_id', $channelId)->where('user_id', $userId)->delete();
    }

    /** Rows left behind by a live server that died without cleaning up. */
    public static function pruneStale(): int
    {
        return self::where('last_seen_at', '<', now()->subSeconds(self::ACTIVE_SECONDS))->delete();
    }
}
