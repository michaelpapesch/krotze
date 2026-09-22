<?php

namespace App\Support;

use App\Models\ChannelMember;
use App\Models\ChatNotification;
use App\Models\Message;
use App\Models\PushSubscription;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Turns things that happen in the app into Web Push notifications.
 *
 * Delivery is deliberately fire-and-forget and happens *after* the response has
 * been flushed (see dispatch()): this host cannot keep a queue worker alive, so
 * the alternative would be making every message send wait on a round trip to
 * Google. Nothing here may throw into the caller.
 */
class PushSender
{
    /**
     * A notification row was just written for somebody. All ten types funnel
     * through ChatNotification::send(), so hooking it there covers every one.
     */
    public static function forNotification(ChatNotification $notification): void
    {
        if (! self::enabled()) {
            return;
        }

        $text = self::describe($notification);
        if ($text === null) {
            return;
        }

        self::dispatch(function () use ($notification, $text) {
            $subscriptions = PushSubscription::where('user_id', $notification->user_id)
                ->get()
                ->filter(fn ($s) => $s->wants($notification->type));

            self::deliver($subscriptions, [
                'title' => 'Krotze',
                'body' => $text,
                // Replaces any earlier card for the same notification instead of
                // stacking a second one.
                'tag' => 'notif-'.$notification->id,
                'uuid' => $notification->data['channel_uuid'] ?? null,
            ]);
        });
    }

    /**
     * A message was posted. Everybody approved in the channel hears about it
     * except the author and anybody who muted it.
     */
    public static function forMessage(Message $message): void
    {
        if (! self::enabled()) {
            return;
        }

        self::dispatch(function () use ($message) {
            $channel = $message->channel;
            if (! $channel) {
                return;
            }

            $recipients = ChannelMember::where('channel_id', $message->channel_id)
                ->where('status', 'approved')
                ->where('muted', false)
                ->where('user_id', '!=', $message->user_id)
                ->pluck('user_id');

            if ($recipients->isEmpty()) {
                return;
            }

            $subscriptions = PushSubscription::whereIn('user_id', $recipients)
                ->where('notify_messages', true)
                ->get();

            if ($subscriptions->isEmpty()) {
                return;
            }

            $preview = $message->preview();
            // A private conversation has no name of its own — the other person
            // *is* the channel, so their name is the title.
            $title = $channel->type === 'private'
                ? $preview['username']
                : $channel->name.' · '.$preview['username'];

            // The preference is per device, so the same message goes out in two
            // shapes: with the text, and without it for anybody who asked.
            $tag = 'chan-'.$channel->uuid;
            self::deliver($subscriptions->where('hide_message_text', false), [
                'title' => $title,
                // E2EE bodies cannot be excerpted — the lock is the preview.
                'body' => $preview['excerpt'] ?? '🔒',
                'tag' => $tag,
                'uuid' => $channel->uuid,
            ]);
            self::deliver($subscriptions->where('hide_message_text', true), [
                'title' => 'Krotze',
                'body' => $channel->type === 'private'
                    ? 'New private message'
                    : 'New message in '.$channel->name,
                'tag' => $tag,
                'uuid' => $channel->uuid,
            ]);
        });
    }

    /** Send one payload to this device, for the "test notification" button. */
    public static function test(PushSubscription $subscription): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $results = WebPush::send([$subscription], [
            'title' => 'Krotze',
            'body' => 'Notifications are working on this device.',
            'tag' => 'push-test',
            'uuid' => null,
        ]);

        $status = $results[$subscription->endpoint_hash] ?? 0;
        self::reap([$subscription->endpoint_hash => $status]);

        return $status >= 200 && $status < 300;
    }

    /* ----------------------------- internals ----------------------------- */

    /**
     * Only the operator's switch: a server without a VAPID keypair can still
     * deliver to relay subscriptions, and WebPush skips the browser ones.
     */
    private static function enabled(): bool
    {
        return Setting::bool('push_enabled', true);
    }

    /**
     * Run the work once the response is on its way out. Laravel executes these
     * in the same process after the client has been served, which is the only
     * form of deferred work available on a host without a queue worker.
     */
    public static function dispatch(callable $work): void
    {
        app()->terminating(function () use ($work) {
            try {
                $work();
            } catch (\Throwable $e) {
                Log::warning('Web Push delivery failed', ['error' => $e->getMessage()]);
            }
        });
    }

    /** @param  iterable<PushSubscription>  $subscriptions */
    private static function deliver(iterable $subscriptions, array $payload): void
    {
        $subscriptions = collect($subscriptions);
        if ($subscriptions->isEmpty()) {
            return;
        }

        self::reap(WebPush::send($subscriptions, $payload));
    }

    /**
     * A push service answering 404 or 410 is telling us the subscription is
     * dead — the browser was uninstalled, or cleared its site data. Keeping it
     * would mean a failing request on every future message. A relay saying
     * "gone" means the same about its token.
     *
     * @param  array<string, int>  $results  endpoint hash => status
     */
    public static function reap(array $results): void
    {
        $gone = [];
        foreach ($results as $hash => $status) {
            if ($status === 404 || $status === 410) {
                $gone[] = $hash;
            } elseif ($status < 200 || $status >= 300) {
                PushSubscription::where('endpoint_hash', $hash)->update(['last_failed_at' => now()]);
                Log::info('Web Push rejected', ['status' => $status]);
            }
        }

        if ($gone) {
            PushSubscription::whereIn('endpoint_hash', $gone)->delete();
        }
    }

    /**
     * The notification text, mirroring what NOTIF_RENDER in resources/js/app.js
     * puts on the in-app card, so a device that sees both reads the same thing.
     */
    private static function describe(ChatNotification $n): ?string
    {
        $d = $n->data ?? [];

        return match ($n->type) {
            'join_request' => "“{$d['username']}” wants to join “{$d['channel_name']}”",
            'private_invite' => "“{$d['from_username']}” wants to start a private chat with you",
            'join_approved' => "You were approved in “{$d['channel_name']}”",
            'join_denied' => "Your request to join “{$d['channel_name']}” was denied.",
            'private_accepted' => "“{$d['from_username']}” accepted your private chat",
            'private_declined' => "“{$d['from_username']}” declined your private chat request.",
            'ownership_received' => "“{$d['from_username']}” transferred ownership of “{$d['channel_name']}” to you.",
            'member_removed' => "You were removed from “{$d['channel_name']}”.",
            'channel_destroyed' => "The channel “{$d['name']}” was destroyed.",
            // channel_moved is plumbing the client handles silently.
            default => null,
        };
    }
}
