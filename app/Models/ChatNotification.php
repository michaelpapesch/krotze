<?php

namespace App\Models;

use App\Support\PushSender;
use Illuminate\Database\Eloquent\Model;

class ChatNotification extends Model
{
    protected $fillable = ['user_id', 'type', 'data', 'read_at'];

    protected $casts = ['data' => 'array', 'read_at' => 'datetime'];

    /**
     * Every notification in the app is created here, which makes this the one
     * place Web Push has to hook into to cover all of them. The push itself is
     * deferred until after the response — see PushSender.
     */
    public static function send(int $userId, string $type, array $data = []): self
    {
        $notification = static::create(['user_id' => $userId, 'type' => $type, 'data' => $data]);

        PushSender::forNotification($notification);

        return $notification;
    }
}
