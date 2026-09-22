<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Channel extends Model
{
    protected $fillable = [
        'uuid', 'name', 'type', 'owner_id', 'invite_token',
        'retention_days', 'last_activity_at', 'join_mode',
        'allow_images', 'allow_videos', 'allow_audio', 'allow_zip', 'restrict_delete',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'allow_images' => 'boolean',
        'allow_videos' => 'boolean',
        'allow_audio' => 'boolean',
        'allow_zip' => 'boolean',
        'restrict_delete' => 'boolean',
    ];

    public function members()
    {
        return $this->hasMany(ChannelMember::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function owner()
    {
        return $this->belongsTo(ChatUser::class, 'owner_id');
    }

    /**
     * Fingerprint of the member roster (ids, statuses, usernames). Clients poll
     * it and reload the member list the moment it changes, so joins, leaves and
     * renames show up without a page reload.
     */
    public static function membersFingerprint(iterable $members): string
    {
        return md5(collect($members)
            ->sortBy('id')
            ->map(fn ($m) => $m->id.':'.$m->status.':'.($m->user?->username ?? ''))
            ->implode('|'));
    }

    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now()])->saveQuietly();
    }

    /** Completely remove the channel, its messages, members and uploaded files. */
    public function destroyCompletely(): void
    {
        Storage::disk('public')->deleteDirectory('uploads/'.$this->uuid);
        UploadView::whereIn('message_id', $this->messages()->pluck('id'))->delete();
        $this->messages()->delete();
        $this->members()->delete();
        $this->delete();
    }
}
