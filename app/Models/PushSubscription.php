<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    protected $fillable = [
        'user_id', 'device_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth',
        'relay', 'relay_token',
        'notify_messages', 'notify_chat_requests', 'notify_join_requests',
        'hide_message_text', 'last_failed_at',
    ];

    protected $casts = [
        'relay' => 'boolean',
        'notify_messages' => 'boolean',
        'notify_chat_requests' => 'boolean',
        'notify_join_requests' => 'boolean',
        'hide_message_text' => 'boolean',
        'last_failed_at' => 'datetime',
    ];

    protected $hidden = ['p256dh', 'auth', 'relay_token'];

    /** Which preference column decides whether a notification type is sent. */
    public const PREFERENCE_FOR_TYPE = [
        'join_request' => 'notify_join_requests',
        'private_invite' => 'notify_chat_requests',
    ];

    public function user()
    {
        return $this->belongsTo(ChatUser::class, 'user_id');
    }

    public function device()
    {
        return $this->belongsTo(ChatDevice::class, 'device_id');
    }

    /** Endpoints are too long to key directly, so they are matched by hash. */
    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * A relay subscription is not a browser endpoint but another Krotze
     * server's relay URL plus the token it issued us. Many devices can share
     * one home server, so the token is part of what makes the row unique.
     */
    public static function relayHash(string $relayUrl, string $token): string
    {
        return self::hash($relayUrl.'|'.$token);
    }

    /**
     * Would this device want to hear about $type? Everything that is not a
     * message, a join request or a chat request — being approved, removed, or a
     * channel disappearing — is rare and always relevant, so it always goes.
     */
    public function wants(string $type): bool
    {
        if ($type === 'message') {
            return $this->notify_messages;
        }

        $column = self::PREFERENCE_FOR_TYPE[$type] ?? null;

        return $column === null || $this->{$column};
    }
}
