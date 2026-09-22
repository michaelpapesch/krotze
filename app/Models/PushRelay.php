<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Permission for one foreign Krotze server to notify one of our devices
 * through our push subscription. The token itself is only ever stored on the
 * foreign server; we keep its hash, exactly like a transfer token.
 */
class PushRelay extends Model
{
    protected $fillable = ['device_id', 'origin', 'token_hash', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public function device()
    {
        return $this->belongsTo(ChatDevice::class, 'device_id');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
