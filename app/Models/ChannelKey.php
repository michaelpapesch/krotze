<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One device's wrapped copy of a channel key for one epoch. `wrap` is the
 * client's sealed box (JSON); the server can neither open nor check it.
 */
class ChannelKey extends Model
{
    protected $fillable = ['channel_id', 'epoch', 'chat_device_id', 'wrap'];

    protected $casts = ['epoch' => 'integer'];

    public function device()
    {
        return $this->belongsTo(ChatDevice::class, 'chat_device_id');
    }
}
