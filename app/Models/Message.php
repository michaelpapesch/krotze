<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Message extends Model
{
    protected $fillable = [
        'channel_id', 'user_id', 'author_alias', 'body', 'kind', 'reply_to_id',
        'file_path', 'thumb_path', 'file_name', 'file_mime', 'file_size', 'encrypted',
    ];

    /**
     * Everything a member actually typed or named is encrypted at rest, so the
     * database (and any backup of it) holds ciphertext rather than conversation.
     * The columns are never searched or sorted on, so nothing is lost by it.
     */
    protected $casts = [
        'body' => 'encrypted',
        'file_name' => 'encrypted',
        'file_deleted_at' => 'datetime',
        'encrypted' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(fn (Message $m) => $m->views()->delete());
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function views()
    {
        return $this->hasMany(UploadView::class);
    }

    public function user()
    {
        return $this->belongsTo(ChatUser::class, 'user_id');
    }

    /**
     * Live username where the author still exists, so a rename updates their
     * old messages too; `author_alias` is the snapshot left behind once the
     * account is gone.
     */
    public function authorName(): string
    {
        return $this->user?->username ?: ($this->author_alias ?: 'anonymous');
    }

    /** The moment this upload stops being reachable — null when it never does. */
    public function fileExpiresAt(): ?Carbon
    {
        $days = Setting::uploadRetentionDays();

        return $days === null || ! $this->created_at
            ? null
            : $this->created_at->copy()->addDays($days);
    }

    /**
     * Just enough of a message to put in a notification body or a channel-list
     * preview — used by /api/state and by the push sender, which must agree.
     */
    public function preview(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'username' => $this->authorName(),
            // An end-to-end encrypted body is an opaque envelope — there is
            // nothing to excerpt; the client shows its own placeholder.
            'excerpt' => $this->encrypted
                ? null
                : ($this->kind === 'text'
                    ? Str::limit((string) $this->body, 120)
                    : ((string) $this->file_name ?: ucfirst($this->kind))),
            'encrypted' => (bool) $this->encrypted,
        ];
    }

    public function fileExpired(): bool
    {
        $expires = $this->fileExpiresAt();

        return $this->file_path !== null && $expires !== null && $expires->isPast();
    }

    public function replyTo()
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    public function deleteStoredFile(): void
    {
        if ($this->file_path) {
            Storage::disk('public')->delete($this->file_path);
        }
        if ($this->thumb_path) {
            Storage::disk('public')->delete($this->thumb_path);
        }
    }

    /**
     * $view is the calling member's personal access record for this upload —
     * file/thumb URLs are that member's capability URLs and die with it.
     */
    public function toClientArray(?UploadView $view = null): array
    {
        $usable = $view?->token && ! $this->fileExpired() && ! $view->viewWindowExpired();

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'username' => $this->authorName(),
            'body' => $this->body,
            'kind' => $this->kind,
            'encrypted' => (bool) $this->encrypted,
            'reply_to' => $this->reply_to_id ? [
                'id' => $this->reply_to_id,
                'username' => $this->replyTo?->authorName(),
                'kind' => $this->replyTo?->kind,
                'encrypted' => (bool) $this->replyTo?->encrypted,
                'excerpt' => ($this->replyTo && ! $this->replyTo->encrypted)
                    ? ($this->replyTo->kind === 'text'
                        ? Str::limit((string) $this->replyTo->body, 90)
                        : $this->replyTo->file_name)
                    : null,
            ] : null,
            'file_url' => $usable ? url('/u/'.$view->token) : null,
            'thumb_url' => $usable && $this->thumb_path ? url('/u/'.$view->token.'/thumb') : null,
            'file_name' => $this->file_name,
            'file_mime' => $this->file_mime,
            'file_size' => $this->file_size,
            'file_expires_at' => $this->file_path
                ? $this->fileExpiresAt()?->toIso8601String() : null,
            'view_expires_at' => $view?->viewExpiresAt()?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
