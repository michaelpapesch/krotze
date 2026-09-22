<?php

namespace App\Http\Middleware;

use App\Models\ChatDevice;
use App\Models\ChatUser;
use App\Support\RequestSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ChatAuth
{
    /**
     * What an identity awaiting an admin decision may still do: watch its own
     * status and claim the username the admin will see in the pending list.
     * Everything else is closed until somebody approves it.
     */
    private const PENDING_ALLOWED = ['api/state', 'api/profile', 'api/profile/username'];

    public function handle(Request $request, Closure $next)
    {
        $publicId = (string) $request->header('X-Chat-Device');
        $ts = (string) $request->header('X-Chat-Ts');
        $nonce = strtolower((string) $request->header('X-Chat-Nonce'));
        $sig = strtolower((string) $request->header('X-Chat-Sig'));

        if ($publicId === '' || $ts === '' || $nonce === '' || $sig === '') {
            return $this->deny('unsigned');
        }
        if (! ctype_digit($ts) || abs(time() - (int) $ts) > RequestSignature::MAX_SKEW) {
            return $this->deny('stale');
        }
        if (! ctype_xdigit($nonce) || strlen($nonce) < 16 || strlen($nonce) > 64) {
            return $this->deny('bad_nonce');
        }

        $device = ChatDevice::with('user')->where('public_id', $publicId)->first();
        if (! $device || ! $device->user) {
            return $this->deny('device_unknown');
        }

        $expected = RequestSignature::sign(
            $device->secret,
            RequestSignature::canonical($request, $ts, $nonce),
        );
        if (! hash_equals($expected, $sig)) {
            return $this->deny('bad_signature');
        }

        // A nonce is good exactly once, so a captured request cannot be replayed
        // even inside the clock-skew window.
        if (! Cache::add('chat-nonce:'.$device->id.':'.$nonce, 1, RequestSignature::MAX_SKEW * 2)) {
            return $this->deny('replay');
        }

        $user = $device->user;

        if ($user->status === ChatUser::DENIED) {
            return $this->deny('registration_denied', 403);
        }
        if ($user->status === ChatUser::PENDING && ! in_array($request->path(), self::PENDING_ALLOWED, true)) {
            return $this->deny('registration_pending', 403);
        }

        if (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinutes(5))) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinutes(5))) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        $request->attributes->set('chatUser', $user);
        $request->attributes->set('chatDevice', $device);

        return $next($request);
    }

    private function deny(string $reason, int $status = 401)
    {
        $body = ['error' => 'unauthenticated', 'reason' => $reason];

        // A client served from another origin cannot read the Date header
        // (CORS keeps it hidden), so a stale answer says what time it is here
        // and the client can correct its clock either way.
        if ($reason === 'stale') {
            $body['server_time'] = time();
        }

        return response()->json($body, $status);
    }
}
