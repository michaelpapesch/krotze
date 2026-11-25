<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\ChannelKey;
use App\Models\ChatDevice;
use App\Models\ChatUser;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Channel keys of end-to-end encrypted group channels (see the migration
 * 2026_11_25_000000_add_channel_e2ee for the scheme). The server keeps the
 * wraps and the epoch counter; it never sees a key.
 */
class ChannelKeyController extends Controller
{
    /**
     * This device's wrapped keys for every epoch it was given, plus what a
     * client needs to start the next epoch: the current one, whether a new
     * one is due, and the devices it would have to be wrapped for.
     */
    public function show(Request $request, string $uuid)
    {
        [, $channel, $device] = $this->resolve($request, $uuid);

        return response()->json($this->summary($channel, $device));
    }

    /**
     * Start epoch `epoch` (exactly the current one + 1) with a wrap for every
     * device of `devices` in GET — no more, no fewer. Two members racing to
     * do it: the first wins, the second gets 409 and the fresh state.
     */
    public function store(Request $request, string $uuid)
    {
        [, $channel, $device] = $this->resolve($request, $uuid);

        $data = $request->validate([
            'epoch' => 'required|integer|min:1',
            'wraps' => 'required|array|min:1|max:1000',
            'wraps.*' => 'required|string|max:512',
        ]);

        // SQLite ignores the row lock; there the unique index on
        // (channel, epoch, device) is what turns a lost race into a conflict.
        try {
            $conflict = DB::transaction(function () use ($channel, $data) {
                $channel = Channel::whereKey($channel->id)->lockForUpdate()->first();
                if ((int) $data['epoch'] !== ($channel->key_epoch ?? 0) + 1) {
                    return 'Somebody else started a new channel key first.';
                }

                $devices = $channel->keyDevices();
                $wanted = $devices->pluck('public_id')->sort()->values()->all();
                $given = collect(array_keys($data['wraps']))->map(fn ($k) => (string) $k)->sort()->values()->all();
                if ($wanted !== $given) {
                    return 'The member devices changed. Wrap the key for the current list.';
                }

                $now = now();
                ChannelKey::insert($devices->map(fn (ChatDevice $d) => [
                    'channel_id' => $channel->id,
                    'epoch' => $data['epoch'],
                    'chat_device_id' => $d->id,
                    'wrap' => $data['wraps'][$d->public_id],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());

                $channel->forceFill([
                    'key_epoch' => $data['epoch'],
                    'key_devices_hash' => Channel::devicesHash($devices),
                ])->save();

                return null;
            });
        } catch (QueryException) {
            $conflict = 'Somebody else started a new channel key first.';
        }

        $channel->refresh();
        if ($conflict) {
            return response()->json(['error' => $conflict] + $this->summary($channel, $device), 409);
        }

        return response()->json($this->summary($channel, $device), 201);
    }

    private function summary(Channel $channel, ChatDevice $device): array
    {
        $devices = $channel->keyDevices();

        return [
            'epoch' => $channel->key_epoch,
            'rekey' => $channel->key_epoch === null
                || $channel->key_devices_hash !== Channel::devicesHash($devices),
            'devices' => $devices->map(fn (ChatDevice $d) => [
                'id' => $d->public_id,
                'user_id' => $d->user_id,
                'key' => $d->public_key,
            ])->values(),
            'keys' => ChannelKey::where('channel_id', $channel->id)
                ->where('chat_device_id', $device->id)
                ->orderBy('epoch')
                ->get(['epoch', 'wrap'])
                ->map(fn (ChannelKey $k) => ['epoch' => $k->epoch, 'wrap' => $k->wrap])
                ->values(),
        ];
    }

    /** @return array{0: ChatUser, 1: Channel, 2: ChatDevice} */
    private function resolve(Request $request, string $uuid): array
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');
        $channel = Channel::where('uuid', $uuid)->firstOrFail();
        $channel->members()->where('user_id', $user->id)->where('status', 'approved')->firstOrFail();
        abort_unless($channel->e2ee, 404);

        return [$user, $channel, $request->attributes->get('chatDevice')];
    }
}
