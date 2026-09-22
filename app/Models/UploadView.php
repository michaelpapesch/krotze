<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class UploadView extends Model
{
    protected $fillable = ['message_id', 'user_id', 'token', 'first_viewed_at'];

    protected $casts = ['first_viewed_at' => 'datetime'];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * When this member's access to the upload dies — the admin decides how long
     * a first look lasts, or switches the limit off so the token survives as
     * long as the upload itself does.
     */
    public function viewExpiresAt(): ?Carbon
    {
        $minutes = Setting::uploadViewMinutes();

        return $minutes === null || $this->first_viewed_at === null
            ? null
            : $this->first_viewed_at->copy()->addMinutes($minutes);
    }

    public function viewWindowExpired(): bool
    {
        $expires = $this->viewExpiresAt();

        return $expires !== null && $expires->isPast();
    }
}
