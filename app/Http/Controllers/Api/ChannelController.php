<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\ChatDevice;
use App\Models\ChatNotification;
use App\Models\ChatUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChannelController extends Controller
{
    /** Create a new group channel; the creator becomes owner and first member. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'retention_days' => 'nullable|integer|min:1|max:365',
            'join_mode' => 'nullable|in:approval,open',
        ]);

        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');

        if (! $user->username) {
            return response()->json(['error' => 'Choose a username first.'], 422);
        }

        $open = ($data['join_mode'] ?? 'approval') === 'open';

        // Open-join channels start with all uploads disabled (anyone with the
        // link can join, so file sharing is opt-in for the owner).
        $channel = Channel::create([
            'uuid' => (string) Str::uuid(),
            'name' => $data['name'],
            'type' => 'group',
            'owner_id' => $user->id,
            'invite_token' => Str::random(40),
            'retention_days' => $data['retention_days'] ?? 7,
            'join_mode' => $open ? 'open' : 'approval',
            'allow_images' => ! $open,
            'allow_videos' => ! $open,
            'allow_audio' => ! $open,
            'allow_zip' => ! $open,
            'last_activity_at' => now(),
        ]);

        ChannelMember::create([
            'channel_id' => $channel->id,
            'user_id' => $user->id,
            'status' => 'approved',
        ]);

        return response()->json([
            'uuid' => $channel->uuid,
            'invite_url' => url('/join/'.$channel->invite_token),
        ], 201);
    }

    /** Channel detail: meta + member list (pending members visible to owner only). */
    public function show(Request $request, string $uuid)
    {
        [$user, $channel, $member] = $this->resolve($request, $uuid);

        $isOwner = $channel->owner_id === $user->id;
        $channel->load('members.user');

        $members = $channel->members
            ->filter(fn ($m) => $m->status !== 'denied' && ($isOwner || $m->status === 'approved'))
            ->map(fn ($m) => [
                'id' => $m->id,
                'username' => $m->username(),
                'status' => $m->status,
                'is_owner' => $m->user_id === $channel->owner_id,
                'is_me' => $m->user_id === $user->id,
                'joined_at' => $m->created_at?->toIso8601String(),
            ])->values();

        // Private conversations are end-to-end encrypted: every participant
        // device that has registered a box key is a wrap target for senders.
        $devices = null;
        if ($channel->type === 'private') {
            $devices = ChatDevice::whereIn('user_id', $channel->members->pluck('user_id'))
                ->whereNotNull('public_key')
                ->get()
                ->map(fn ($d) => [
                    'id' => $d->public_id,
                    'user_id' => $d->user_id,
                    'key' => $d->public_key,
                ])->values();
        }

        return response()->json([
            'uuid' => $channel->uuid,
            'name' => $channel->type === 'private'
                ? ($channel->members->first(fn ($x) => $x->user_id !== $user->id)?->username() ?? 'Private chat')
                : $channel->name,
            'type' => $channel->type,
            'is_owner' => $isOwner,
            'my_member_id' => $member->id,
            'my_status' => $member->status,
            'pinned' => $member->pinned,
            'hidden' => $member->hidden,
            'muted' => $member->muted,
            'retention_days' => $channel->retention_days,
            'join_mode' => $channel->join_mode,
            'allow_images' => $channel->allow_images,
            'allow_videos' => $channel->allow_videos,
            'allow_audio' => $channel->allow_audio,
            'allow_zip' => $channel->allow_zip,
            'restrict_delete' => $channel->restrict_delete,
            'invite_url' => $isOwner && $channel->invite_token
                ? url('/join/'.$channel->invite_token) : null,
            'embed_url' => $isOwner && $channel->invite_token && $channel->join_mode === 'open'
                ? url('/embed/'.$channel->invite_token) : null,
            'members' => $members,
            'members_hash' => Channel::membersFingerprint($channel->members),
            'devices' => $devices,
        ]);
    }

    /** Owner updates settings (name, auto-delete retention). */
    public function update(Request $request, string $uuid)
    {
        [$user, $channel] = $this->resolve($request, $uuid, ownerOnly: true);

        $data = $request->validate([
            'name' => 'sometimes|string|max:60',
            'retention_days' => 'sometimes|integer|min:1|max:365',
            'join_mode' => 'sometimes|in:approval,open',
            'allow_images' => 'sometimes|boolean',
            'allow_videos' => 'sometimes|boolean',
            'allow_audio' => 'sometimes|boolean',
            'allow_zip' => 'sometimes|boolean',
            'restrict_delete' => 'sometimes|boolean',
        ]);

        abort_if(isset($data['join_mode']) && $channel->type !== 'group', 422);

        // Switching to open join turns uploads off unless the request sets
        // them explicitly (the settings dialog always sends the flags).
        if (($data['join_mode'] ?? null) === 'open' && $channel->join_mode !== 'open'
            && ! $request->hasAny(['allow_images', 'allow_videos', 'allow_audio', 'allow_zip'])) {
            $data += ['allow_images' => false, 'allow_videos' => false,
                'allow_audio' => false, 'allow_zip' => false];
        }

        $channel->update($data);

        return response()->json(['ok' => true]);
    }

    /** Owner deletes every upload in the channel (files, thumbs, messages). */
    public function purgeUploads(Request $request, string $uuid)
    {
        [$user, $channel] = $this->resolve($request, $uuid, ownerOnly: true);

        $count = 0;
        foreach ($channel->messages()->whereNotNull('file_path')->get() as $m) {
            $m->deleteStoredFile();
            $m->delete();
            $count++;
        }
        $channel->touchActivity();

        return response()->json(['ok' => true, 'deleted' => $count]);
    }

    /** Hide / unhide a channel in my sidebar (per member, like pinning). */
    public function hide(Request $request, string $uuid)
    {
        [, , $member] = $this->resolve($request, $uuid);
        $member->update(['hidden' => (bool) $request->boolean('hidden')]);

        return response()->json(['ok' => true]);
    }

    /**
     * Mute / unmute a channel. Kept on the membership rather than in the
     * browser so the choice follows the member to every device they use.
     */
    public function mute(Request $request, string $uuid)
    {
        [, , $member] = $this->resolve($request, $uuid);
        $member->update(['muted' => (bool) $request->boolean('muted')]);

        return response()->json(['ok' => true]);
    }

    /** Destroy channel + all data. Group: owner only. Private: either member. */
    public function destroy(Request $request, string $uuid)
    {
        [$user, $channel] = $this->resolve($request, $uuid);

        if ($channel->type === 'group' && $channel->owner_id !== $user->id) {
            abort(403);
        }

        $name = $channel->type === 'private' ? 'Private chat' : $channel->name;
        foreach ($channel->members as $m) {
            if ($m->user_id !== $user->id) {
                ChatNotification::send($m->user_id, 'channel_destroyed', ['name' => $name]);
            }
        }

        $channel->destroyCompletely();

        return response()->json(['ok' => true]);
    }

    /** Transfer ownership to another approved member (group channels). */
    public function transfer(Request $request, string $uuid)
    {
        [$user, $channel] = $this->resolve($request, $uuid, ownerOnly: true);

        $data = $request->validate(['member_id' => 'required|integer']);

        $target = $channel->members()
            ->where('id', $data['member_id'])
            ->where('status', 'approved')
            ->firstOrFail();

        if ($target->user_id === $user->id) {
            return response()->json(['error' => 'You already own this channel.'], 422);
        }

        $channel->update(['owner_id' => $target->user_id]);

        ChatNotification::send($target->user_id, 'ownership_received', [
            'channel_uuid' => $channel->uuid,
            'channel_name' => $channel->name,
            'from_username' => $user->displayName(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Rotate a leaked invite: new invite token AND new channel uuid, so both
     * the join link and the channel URL change. Members in `keep` stay and
     * their clients are redirected via a persistent `channel_moved`
     * notification (delivered on their next poll, so offline clients migrate
     * when they return). Everyone else is removed. The old uuid simply stops
     * resolving — no server-side redirect is left behind.
     */
    public function rotate(Request $request, string $uuid)
    {
        [$user, $channel] = $this->resolve($request, $uuid, ownerOnly: true);
        abort_unless($channel->type === 'group', 422);

        $keep = collect($request->input('keep', []))->map(fn ($v) => (int) $v)->all();

        $oldUuid = $channel->uuid;
        $newUuid = (string) Str::uuid();

        // Uploads live under the channel uuid — move the directory and
        // re-point stored paths before the uuid changes.
        $disk = Storage::disk('public');
        if ($disk->exists('uploads/'.$oldUuid)) {
            rename($disk->path('uploads/'.$oldUuid), $disk->path('uploads/'.$newUuid));
        }
        foreach ($channel->messages()->whereNotNull('file_path')->get() as $msg) {
            $msg->forceFill([
                'file_path' => str_replace($oldUuid, $newUuid, $msg->file_path),
                'thumb_path' => $msg->thumb_path ? str_replace($oldUuid, $newUuid, $msg->thumb_path) : null,
            ])->saveQuietly();
        }

        $removed = $channel->members()
            ->where('user_id', '!=', $user->id)
            ->whereNotIn('id', $keep)
            ->get();
        foreach ($removed as $m) {
            ChatNotification::send($m->user_id, 'member_removed', [
                'channel_name' => $channel->name,
            ]);
            $m->delete();
        }

        foreach ($channel->members()->where('user_id', '!=', $user->id)->get() as $m) {
            ChatNotification::send($m->user_id, 'channel_moved', [
                'old_uuid' => $oldUuid,
                'channel_uuid' => $newUuid,
                'channel_name' => $channel->name,
            ]);
        }

        $channel->update([
            'uuid' => $newUuid,
            'invite_token' => Str::random(40),
        ]);
        $channel->touchActivity();

        return response()->json([
            'uuid' => $newUuid,
            'invite_url' => url('/join/'.$channel->invite_token),
            'removed' => $removed->count(),
        ]);
    }

    /** Pin / unpin a channel in my sidebar. */
    public function pin(Request $request, string $uuid)
    {
        [, , $member] = $this->resolve($request, $uuid);
        $member->update(['pinned' => (bool) $request->boolean('pinned')]);

        return response()->json(['ok' => true]);
    }

    /** Mark messages read up to a message id. */
    public function markRead(Request $request, string $uuid)
    {
        [, , $member] = $this->resolve($request, $uuid);
        $id = (int) $request->input('message_id', 0);
        if ($id > $member->last_read_message_id) {
            $member->update(['last_read_message_id' => $id]);
        }

        return response()->json(['ok' => true]);
    }

    /** Leave a channel; optionally wipe own messages and files first. */
    public function leave(Request $request, string $uuid)
    {
        [$user, $channel, $member] = $this->resolve($request, $uuid);

        if ($channel->owner_id === $user->id && $channel->type === 'group') {
            return response()->json([
                'error' => 'Transfer ownership or destroy the channel first.',
            ], 422);
        }

        if ($request->boolean('delete_own_data')) {
            foreach ($channel->messages()->where('user_id', $user->id)->get() as $msg) {
                $msg->deleteStoredFile();
                $msg->delete();
            }
        }

        $member->delete();
        $channel->touchActivity();

        return response()->json(['ok' => true]);
    }

    /**
     * Start (or reuse) a private conversation with a member of a shared channel.
     * The other side must accept before the chat becomes active.
     */
    public function startPrivate(Request $request, string $uuid)
    {
        [$user, $channel] = $this->resolve($request, $uuid);

        $data = $request->validate(['member_id' => 'required|integer']);

        $target = $channel->members()
            ->where('id', $data['member_id'])
            ->where('status', 'approved')
            ->firstOrFail();

        if ($target->user_id === $user->id) {
            abort(422);
        }

        // Reuse an existing private channel between the two users.
        $existing = Channel::where('type', 'private')
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('members', fn ($q) => $q->where('user_id', $target->user_id))
            ->first();

        if ($existing) {
            return response()->json(['uuid' => $existing->uuid, 'existing' => true]);
        }

        $private = Channel::create([
            'uuid' => (string) Str::uuid(),
            'type' => 'private',
            'retention_days' => 365,
            'last_activity_at' => now(),
        ]);

        ChannelMember::create([
            'channel_id' => $private->id, 'user_id' => $user->id, 'status' => 'approved',
        ]);
        ChannelMember::create([
            'channel_id' => $private->id, 'user_id' => $target->user_id, 'status' => 'pending',
        ]);

        ChatNotification::send($target->user_id, 'private_invite', [
            'channel_uuid' => $private->uuid,
            'from_username' => $user->displayName(),
        ]);

        return response()->json(['uuid' => $private->uuid, 'existing' => false], 201);
    }

    /** Invited side accepts or declines a private conversation. */
    public function respondPrivate(Request $request, string $uuid)
    {
        [$user, $channel, $member] = $this->resolve($request, $uuid);

        abort_unless($channel->type === 'private' && $member->status === 'pending', 422);

        $other = $channel->members->first(fn ($m) => $m->user_id !== $user->id);

        if ($request->boolean('accept')) {
            $member->update(['status' => 'approved']);
            if ($other) {
                ChatNotification::send($other->user_id, 'private_accepted', [
                    'channel_uuid' => $channel->uuid,
                    'from_username' => $user->displayName(),
                ]);
            }

            return response()->json(['ok' => true]);
        }

        if ($other) {
            ChatNotification::send($other->user_id, 'private_declined', [
                'from_username' => $user->displayName(),
            ]);
        }
        $channel->destroyCompletely();

        return response()->json(['ok' => true]);
    }

    /** @return array{0: ChatUser, 1: Channel, 2: ChannelMember} */
    private function resolve(Request $request, string $uuid, bool $ownerOnly = false): array
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');
        $channel = Channel::where('uuid', $uuid)->firstOrFail();
        $member = $channel->members()->where('user_id', $user->id)->firstOrFail();

        if ($ownerOnly && $channel->owner_id !== $user->id) {
            abort(403);
        }

        return [$user, $channel, $member];
    }
}
