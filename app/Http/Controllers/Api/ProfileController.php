<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatDevice;
use App\Models\ChatUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    /** Pairing codes are read aloud and typed — no easily confused characters. */
    private const PAIR_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const PAIR_TTL_MINUTES = 5;

    /** Username, registered devices, and whether an identity token exists. */
    public function show(Request $request)
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');
        /** @var ChatDevice $current */
        $current = $request->attributes->get('chatDevice');

        return response()->json([
            'username' => $user->username,
            'has_transfer_token' => $user->transfer_hash !== null,
            'devices' => $user->devices()->orderBy('id')->get()->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'current' => $d->id === $current->id,
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                'created_at' => $d->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** Claim a username. Globally unique, so nobody can impersonate anybody. */
    public function updateUsername(Request $request)
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');

        $data = $request->validate([
            'username' => [
                'required', 'string', 'min:2', 'max:40',
                'regex:/^[\p{L}\p{N}][\p{L}\p{N} ._-]*$/u',
                // Compared lower-cased rather than leaning on the column's
                // collation: "alice" and "ALICE" must never be two people,
                // whichever database engine is behind this.
                function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                    $taken = ChatUser::whereRaw('LOWER(username) = ?', [mb_strtolower(trim((string) $value))])
                        ->where('id', '!=', $user->id)
                        ->exists();
                    if ($taken) {
                        $fail('That username is already taken.');
                    }
                },
            ],
        ], [
            'username.regex' => 'Use letters, numbers, spaces, dots, dashes or underscores.',
        ]);

        $user->forceFill(['username' => trim($data['username'])])->save();

        return response()->json(['ok' => true, 'username' => $user->username]);
    }

    /**
     * Register this device's X25519 public key, used by other members to wrap
     * message keys for end-to-end encrypted private conversations. The device
     * generates the pair locally and only ever uploads the public half;
     * re-uploading replaces the key (a device that lost its local key can heal
     * itself — messages wrapped for the old key stay unreadable, correctly).
     */
    public function setDeviceKey(Request $request)
    {
        $data = $request->validate([
            'public_key' => 'required|string|size:44|regex:#^[A-Za-z0-9+/]{43}=$#',
        ]);

        /** @var ChatDevice $device */
        $device = $request->attributes->get('chatDevice');
        $device->forceFill(['public_key' => $data['public_key']])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Revoke a device. Its next request fails signature lookup and that client
     * wipes its local identity; any push subscription goes with it.
     *
     * Removing the device making the call is allowed — signing yourself out of
     * the browser you are holding is a reasonable thing to want. The client
     * warns first, because with no other device registered the only way back
     * into the identity is an exported identity token.
     */
    public function removeDevice(Request $request, int $deviceId)
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');
        /** @var ChatDevice $current */
        $current = $request->attributes->get('chatDevice');

        $device = $user->devices()->findOrFail($deviceId);
        $device->delete();

        return response()->json([
            'ok' => true,
            // Lets the client tell "somebody else was signed out" apart from
            // "you just signed yourself out" without comparing ids again.
            'was_current' => $device->id === $current->id,
        ]);
    }

    /**
     * Start pairing: a short code, valid for a few minutes and good for exactly
     * one device. The new device gets its own secret — this one's is not shared.
     */
    public function pair(Request $request)
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');

        if (! $user->username) {
            return response()->json(['error' => 'Choose a username first.'], 422);
        }

        do {
            $code = collect(range(1, 8))
                ->map(fn () => self::PAIR_ALPHABET[random_int(0, strlen(self::PAIR_ALPHABET) - 1)])
                ->implode('');
        } while (! Cache::add('chat-pair:'.$code, $user->id, now()->addMinutes(self::PAIR_TTL_MINUTES)));

        return response()->json([
            'code' => $code,
            'url' => url('/app#pair='.$code),
            'expires_in' => self::PAIR_TTL_MINUTES * 60,
        ]);
    }

    /** The new device redeems the pairing code and receives its own credentials. */
    public function claim(Request $request)
    {
        $code = strtoupper(trim((string) $request->input('code')));
        $userId = strlen($code) === 8 ? Cache::pull('chat-pair:'.$code) : null;
        $user = $userId ? ChatUser::find($userId) : null;

        if (! $user) {
            return response()->json(['error' => 'That pairing code is invalid or has expired.'], 404);
        }

        $device = ChatDevice::issue($user, $request->input('device_name'));
        $device->setRelation('user', $user);

        return response()->json($device->credentials(), 201);
    }

    /**
     * Mint the identity token used for export. It is stored only as a hash, so
     * a database leak cannot reproduce it, and the client encrypts it with a
     * passphrase before it is ever shown or saved.
     */
    public function createTransfer(Request $request)
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');

        if (! $user->username) {
            return response()->json(['error' => 'Choose a username first.'], 422);
        }

        $token = Str::random(64);
        $user->forceFill(['transfer_hash' => hash('sha256', $token)])->save();

        return response()->json(['token' => $token, 'username' => $user->username]);
    }

    /**
     * Roll every credential of this identity: all devices are dropped, any
     * exported identity token stops working, and the caller is handed a fresh
     * device so it stays signed in. Channels, memberships and messages are
     * untouched — this only changes who can act as the user.
     */
    public function swapToken(Request $request)
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');

        $user->devices()->delete();
        $user->forceFill(['transfer_hash' => null, 'token' => null])->save();

        $device = ChatDevice::issue($user, $request->input('device_name'));
        $device->setRelation('user', $user);

        return response()->json($device->credentials(), 201);
    }

    /** Invalidate the exported identity token. */
    public function revokeTransfer(Request $request)
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');
        $user->forceFill(['transfer_hash' => null])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Import an identity token: registers the importing device under that
     * token's user, so this client continues as that identity.
     */
    public function import(Request $request)
    {
        $token = trim((string) $request->input('token'));
        $user = strlen($token) >= 32
            ? ChatUser::where('transfer_hash', hash('sha256', $token))->first()
            : null;

        if (! $user) {
            return response()->json(['error' => 'That identity token is invalid or was revoked.'], 404);
        }

        $device = ChatDevice::issue($user, $request->input('device_name'));
        $device->setRelation('user', $user);

        return response()->json($device->credentials(), 201);
    }
}
