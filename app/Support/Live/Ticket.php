<?php

namespace App\Support\Live;

use App\Models\Channel;
use App\Models\ChatDevice;

/**
 * The pass a client shows to the live server.
 *
 * A WebSocket cannot carry the per-request signature every HTTP call has, so
 * a member first asks the (signed) HTTP API for a ticket and presents that as
 * its first WebSocket message. The ticket is an HMAC over "this device, this
 * channel, until then" keyed from APP_KEY: the live server can check it
 * without any shared state, it expires within a minute, and it is useless for
 * anything but joining that one channel's live room.
 */
class Ticket
{
    public const TTL_SECONDS = 60;

    public static function issue(Channel $channel, ChatDevice $device): string
    {
        $payload = json_encode([
            'c' => $channel->id,
            'u' => $device->user_id,
            'd' => $device->id,
            'e' => now()->getTimestamp() + self::TTL_SECONDS,
        ]);

        return self::b64($payload).'.'.self::b64(self::mac($payload));
    }

    /** @return array{c: int, u: int, d: int, e: int}|null */
    public static function verify(string $ticket): ?array
    {
        $parts = explode('.', $ticket);
        if (count($parts) !== 2) {
            return null;
        }

        $payload = self::unb64($parts[0]);
        $mac = self::unb64($parts[1]);
        if ($payload === null || $mac === null || ! hash_equals(self::mac($payload), $mac)) {
            return null;
        }

        $data = json_decode($payload, true);
        if (! is_array($data) || ! isset($data['c'], $data['u'], $data['d'], $data['e']) || $data['e'] < now()->getTimestamp()) {
            return null;
        }

        return $data;
    }

    private static function mac(string $payload): string
    {
        return hash_hmac('sha256', $payload, hash_hmac('sha256', 'krotze-live-ticket', (string) config('app.key'), true), true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $text): ?string
    {
        $raw = base64_decode(strtr($text, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
