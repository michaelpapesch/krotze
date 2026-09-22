<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatDevice;
use App\Models\ChatUser;
use App\Models\PushRelay;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Support\PushSender;
use App\Support\RelayUrl;
use App\Support\WebPush;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PushController extends Controller
{
    /** Notifications one relay token may deliver per minute. */
    private const RELAY_PER_TOKEN = 30;

    /** Notifications one address may deliver per minute, across all tokens. */
    private const RELAY_PER_IP = 600;

    /**
     * The server's VAPID public key, which a client passes to
     * pushManager.subscribe() as `applicationServerKey`. Public by definition —
     * it is handed to every browser that subscribes — so this needs no auth,
     * and answering it unsigned lets a client find out whether push is worth
     * attempting before it has an identity.
     *
     * `relay` says whether this server can deliver through another server's
     * relay instead — that needs no keypair of its own, only the switch.
     */
    public function key()
    {
        $switchedOn = Setting::bool('push_enabled', true);
        $enabled = WebPush::configured() && $switchedOn;

        return response()->json([
            'enabled' => $enabled,
            'public_key' => $enabled ? WebPush::publicKey() : null,
            'relay' => $switchedOn,
        ]);
    }

    /**
     * Register (or refresh) this device's push subscription. Browsers hand out
     * a new endpoint whenever they feel like it, so this is called on every
     * launch and has to be idempotent.
     *
     * Two shapes: a browser subscription (`endpoint` + `keys`), or a relay
     * (`relay_url` + `relay_token`) when the device's real subscription lives
     * on another Krotze server and that server agreed to forward for us.
     */
    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => 'required_without:relay_url|url|max:2000',
            'keys' => 'required_with:endpoint|array',
            'keys.p256dh' => 'required_with:endpoint|string|max:128',
            'keys.auth' => 'required_with:endpoint|string|max:32',
            'relay_url' => 'required_without:endpoint|url|max:2000',
            'relay_token' => 'required_with:relay_url|string|size:48|alpha_num',
            'notify_messages' => 'sometimes|boolean',
            'notify_chat_requests' => 'sometimes|boolean',
            'notify_join_requests' => 'sometimes|boolean',
            'hide_message_text' => 'sometimes|boolean',
        ]);

        $relay = isset($data['relay_url']);
        if ($relay && ($problem = RelayUrl::problem($data['relay_url']))) {
            return response()->json(['error' => $problem], 422);
        }

        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');
        /** @var ChatDevice $device */
        $device = $request->attributes->get('chatDevice');

        $hash = $relay
            ? PushSubscription::relayHash($data['relay_url'], $data['relay_token'])
            : PushSubscription::hash($data['endpoint']);

        // One subscription per device: if this device had a different endpoint
        // before, that one is stale and should not keep receiving.
        PushSubscription::where('device_id', $device->id)
            ->where('endpoint_hash', '!=', $hash)
            ->delete();

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint_hash' => $hash],
            array_merge([
                'user_id' => $user->id,
                'device_id' => $device->id,
                'endpoint' => $relay ? $data['relay_url'] : $data['endpoint'],
                'p256dh' => $relay ? null : $data['keys']['p256dh'],
                'auth' => $relay ? null : $data['keys']['auth'],
                'relay' => $relay,
                'relay_token' => $relay ? $data['relay_token'] : null,
                'last_failed_at' => null,
            ], $this->preferences($data)),
        );

        // A freshly created row carries only what was written; the preference
        // columns fall back to their database defaults, so re-read before
        // reporting what this device is actually subscribed to.
        return response()->json(['ok' => true, 'preferences' => $this->expose($subscription->refresh())], 201);
    }

    /** Change what this device wants to be notified about. */
    public function update(Request $request)
    {
        $data = $request->validate([
            'notify_messages' => 'sometimes|boolean',
            'notify_chat_requests' => 'sometimes|boolean',
            'notify_join_requests' => 'sometimes|boolean',
            'hide_message_text' => 'sometimes|boolean',
        ]);

        $subscription = $this->current($request);
        if (! $subscription) {
            return response()->json(['error' => 'This device has no push subscription.'], 404);
        }

        $subscription->update($this->preferences($data));

        return response()->json(['ok' => true, 'preferences' => $this->expose($subscription)]);
    }

    /** Stop pushing to this device. */
    public function unsubscribe(Request $request)
    {
        PushSubscription::where('device_id', $request->attributes->get('chatDevice')->id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Prove to somebody that notifications work on this device. */
    public function test(Request $request)
    {
        $subscription = $this->current($request);
        if (! $subscription) {
            return response()->json(['error' => 'This device has no push subscription.'], 404);
        }
        if (! Setting::bool('push_enabled', true) || (! $subscription->relay && ! WebPush::configured())) {
            return response()->json(['error' => 'Push notifications are switched off on this server.'], 422);
        }

        if (! PushSender::test($subscription)) {
            return response()->json([
                'error' => 'The push service rejected the notification. The subscription may have expired — reload and try again.',
            ], 502);
        }

        return response()->json(['ok' => true]);
    }

    /* ------------------------------ relays ------------------------------- */

    /**
     * Let another Krotze server notify this device through our subscription.
     * The token is shown once; we keep its hash. Asking again for the same
     * origin rotates the token, which is also how a client repairs a relay
     * the other side lost.
     */
    public function relayCreate(Request $request)
    {
        $origin = RelayUrl::origin((string) $request->input('origin', ''));
        if (! $origin) {
            return response()->json(['error' => 'origin must be scheme://host[:port] with nothing after it.'], 422);
        }

        /** @var ChatDevice $device */
        $device = $request->attributes->get('chatDevice');
        $token = Str::random(48);

        PushRelay::updateOrCreate(
            ['device_id' => $device->id, 'origin' => $origin],
            ['token_hash' => PushRelay::hash($token), 'last_used_at' => null],
        );

        return response()->json([
            'relay_url' => url(RelayUrl::PATH),
            'relay_token' => $token,
            'origin' => $origin,
        ], 201);
    }

    /** Withdraw a foreign server's permission to notify this device. */
    public function relayDelete(Request $request)
    {
        $origin = RelayUrl::origin((string) $request->input('origin', ''));
        if (! $origin) {
            return response()->json(['error' => 'origin must be scheme://host[:port] with nothing after it.'], 422);
        }

        PushRelay::where('device_id', $request->attributes->get('chatDevice')->id)
            ->where('origin', $origin)
            ->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * A foreign server has notifications for devices that gave it relay
     * tokens — many at once, since one message fans out to a whole channel.
     *
     * Unsigned by nature (the caller has no identity here); each token is the
     * authorisation for its own item. The cheap checks come first and the
     * answer goes out before anything is delivered, so a flood costs this
     * server lookups, not connections held open to a push service.
     */
    public function relayDeliver(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:'.config('push.relay_max_items'),
            'items.*.token' => 'required|string|size:48|alpha_num',
            'items.*.title' => 'nullable|string|max:120',
            'items.*.body' => 'nullable|string|max:400',
            'items.*.tag' => 'nullable|string|max:120',
            'items.*.uuid' => 'nullable|string|max:64',
        ]);

        $ipKey = 'relay-ip:'.$request->ip();
        $deliverable = Setting::bool('push_enabled', true) && WebPush::configured();
        $results = [];
        $deliveries = [];
        $used = [];

        foreach ($data['items'] as $item) {
            $token = $item['token'];
            $tokenKey = 'relay:'.PushRelay::hash($token);

            if (RateLimiter::tooManyAttempts($ipKey, self::RELAY_PER_IP)
                || RateLimiter::tooManyAttempts($tokenKey, self::RELAY_PER_TOKEN)) {
                $results[$token] = 'throttled';

                continue;
            }
            RateLimiter::hit($ipKey, 60);
            RateLimiter::hit($tokenKey, 60);

            $relay = PushRelay::where('token_hash', PushRelay::hash($token))->first();
            if (! $relay) {
                $results[$token] = 'gone';

                continue;
            }

            $subscription = $deliverable
                ? PushSubscription::where('device_id', $relay->device_id)->where('relay', false)->first()
                : null;
            if (! $subscription) {
                $results[$token] = 'no_subscription';

                continue;
            }

            $used[] = $relay->id;
            $deliveries[] = [$subscription, [
                'title' => $item['title'] ?? 'Krotze',
                'body' => $item['body'] ?? '',
                'tag' => $item['tag'] ?? null,
                'uuid' => $item['uuid'] ?? null,
                // The one thing we add, and it comes from our own row — the
                // caller cannot claim to be a server it is not.
                'origin' => $relay->origin,
            ]];
            $results[$token] = 'ok';
        }

        if ($used) {
            PushRelay::whereIn('id', $used)->update(['last_used_at' => now()]);
        }
        if ($deliveries) {
            PushSender::dispatch(fn () => PushSender::reap(WebPush::sendMany($deliveries)));
        }

        return response()->json(['results' => $results], 202);
    }

    /* ----------------------------- internals ----------------------------- */

    private function current(Request $request): ?PushSubscription
    {
        return PushSubscription::where('device_id', $request->attributes->get('chatDevice')->id)->first();
    }

    /** Only the preference keys that were actually sent. */
    private function preferences(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'notify_messages', 'notify_chat_requests', 'notify_join_requests', 'hide_message_text',
        ]));
    }

    private function expose(PushSubscription $s): array
    {
        return [
            'notify_messages' => $s->notify_messages,
            'notify_chat_requests' => $s->notify_chat_requests,
            'notify_join_requests' => $s->notify_join_requests,
            'hide_message_text' => $s->hide_message_text,
        ];
    }
}
