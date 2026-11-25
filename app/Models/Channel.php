<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Channel extends Model
{
    protected $fillable = [
        'uuid', 'name', 'type', 'owner_id', 'invite_token',
        'retention_days', 'last_activity_at', 'join_mode',
        'allow_images', 'allow_videos', 'allow_audio', 'allow_zip', 'restrict_delete', 'e2ee',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'allow_images' => 'boolean',
        'allow_videos' => 'boolean',
        'allow_audio' => 'boolean',
        'allow_zip' => 'boolean',
        'restrict_delete' => 'boolean',
        'e2ee' => 'boolean',
        'key_epoch' => 'integer',
    ];

    public function members()
    {
        return $this->hasMany(ChannelMember::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function streams()
    {
        return $this->hasMany(Stream::class);
    }

    public function keys()
    {
        return $this->hasMany(ChannelKey::class);
    }

    public function owner()
    {
        return $this->belongsTo(ChatUser::class, 'owner_id');
    }

    /**
     * Fingerprint of the member roster (ids, statuses, roles, usernames). Clients poll
     * it and reload the member list the moment it changes, so joins, leaves and
     * renames show up without a page reload.
     */
    public static function membersFingerprint(iterable $members): string
    {
        return md5(collect($members)
            ->sortBy('id')
            ->map(fn ($m) => $m->id.':'.$m->status.':'.$m->role.':'.($m->user?->username ?? ''))
            ->implode('|'));
    }

    /**
     * The devices a channel key must be wrapped for: every device of every
     * approved member that has registered a box key. Applicants are not in
     * it — they get the key of the first epoch after their approval.
     */
    public function keyDevices()
    {
        return ChatDevice::whereIn('user_id', $this->members()->where('status', 'approved')->select('user_id'))
            ->whereNotNull('public_key')
            ->orderBy('id')
            ->get();
    }

    /** Fingerprint of a device set: ids and keys, so a re-keyed device counts as a change too. */
    public static function devicesHash(iterable $devices): string
    {
        return hash('sha256', collect($devices)
            ->sortBy('public_id')
            ->map(fn ($d) => $d->public_id.':'.$d->public_key)
            ->implode('|'));
    }

    /**
     * True when the next encrypted message needs a new epoch first: there is
     * none yet, or the member devices changed since the current one was made.
     */
    public function needsNewKey(): bool
    {
        return $this->e2ee && ($this->key_epoch === null
            || $this->key_devices_hash !== self::devicesHash($this->keyDevices()));
    }

    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now()])->saveQuietly();
    }

    /** Completely remove the channel, its messages, members and uploaded files. */
    public function destroyCompletely(): void
    {
        Storage::disk('uploads')->deleteDirectory('uploads/'.$this->uuid);
        UploadView::whereIn('message_id', $this->messages()->pluck('id'))->delete();
        $this->messages()->delete();
        $this->streams()->delete();
        $this->keys()->delete();
        $this->members()->delete();
        $this->delete();
    }
}
