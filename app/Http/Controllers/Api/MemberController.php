<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\ChatNotification;
use App\Models\ChatUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class MemberController extends Controller
{
    /** Public info for a join link (no auth needed to see the channel name). */
    public function joinInfo(string $token)
    {
        $channel = Channel::where('invite_token', $token)->firstOrFail();

        return response()->json([
            'name' => $channel->name,
            'members' => $channel->members()->where('status', 'approved')->count(),
            'join_mode' => $channel->join_mode,
        ]);
    }

    /**
     * Apply for membership under your username. Approval channels: the owner
     * must approve. Open channels (embeds): approved immediately, IP-throttled.
     */
    public function joinApply(Request $request, string $token)
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');

        if (! $user->username) {
            return response()->json(['error' => 'Choose a username first.'], 422);
        }

        $channel = Channel::where('invite_token', $token)->firstOrFail();

        $existing = $channel->members()->where('user_id', $user->id)->first();
        if ($existing) {
            return response()->json([
                'uuid' => $channel->uuid,
                'status' => $existing->status,
            ]);
        }

        $open = $channel->join_mode === 'open';

        if ($open) {
            $key = 'open-join:'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, 6)) {
                return response()->json(['error' => 'Too many join attempts. Please wait a minute.'], 429);
            }
            RateLimiter::hit($key, 60);
        }

        $member = ChannelMember::create([
            'channel_id' => $channel->id,
            'user_id' => $user->id,
            'status' => $open ? 'approved' : 'pending',
        ]);

        if ($open) {
            $channel->touchActivity();
        } elseif ($channel->owner_id) {
            ChatNotification::send($channel->owner_id, 'join_request', [
                'channel_uuid' => $channel->uuid,
                'channel_name' => $channel->name,
                'member_id' => $member->id,
                'username' => $user->username,
            ]);
        }

        return response()->json(['uuid' => $channel->uuid, 'status' => $member->status], 201);
    }

    /** Owner approves or denies a pending join request. */
    public function decide(Request $request, int $memberId)
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');

        $member = ChannelMember::with('channel')->findOrFail($memberId);
        $channel = $member->channel;

        abort_unless($channel && $channel->owner_id === $user->id, 403);
        abort_unless($member->status === 'pending', 422);

        $approve = $request->boolean('approve');

        ChatNotification::send($member->user_id, $approve ? 'join_approved' : 'join_denied', [
            'channel_uuid' => $channel->uuid,
            'channel_name' => $channel->name,
        ]);

        if ($approve) {
            $member->update(['status' => 'approved']);
            $channel->touchActivity();
        } else {
            $member->delete();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Owner decides several pending join requests at once.
     * Body: { accept: [member ids], deny: [member ids] }.
     */
    public function decideBulk(Request $request, string $uuid)
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');
        $channel = Channel::where('uuid', $uuid)->firstOrFail();
        abort_unless($channel->owner_id === $user->id, 403);

        $accept = collect($request->input('accept', []))->map(fn ($v) => (int) $v)->all();
        $deny = collect($request->input('deny', []))->map(fn ($v) => (int) $v)->all();

        $pending = $channel->members()->where('status', 'pending')->get()->keyBy('id');

        $decided = [];
        foreach ($accept as $id) {
            $m = $pending->get($id);
            if (! $m) {
                continue;
            }
            $m->update(['status' => 'approved']);
            ChatNotification::send($m->user_id, 'join_approved', [
                'channel_uuid' => $channel->uuid,
                'channel_name' => $channel->name,
            ]);
            $decided[] = $id;
        }
        foreach ($deny as $id) {
            $m = $pending->get($id);
            if (! $m || in_array($id, $decided, true)) {
                continue;
            }
            ChatNotification::send($m->user_id, 'join_denied', [
                'channel_uuid' => $channel->uuid,
                'channel_name' => $channel->name,
            ]);
            $m->delete();
            $decided[] = $id;
        }

        // Clear the owner's join_request cards for everything just decided.
        if ($decided) {
            $user->notifications()
                ->whereNull('read_at')
                ->where('type', 'join_request')
                ->get()
                ->filter(fn ($n) => in_array((int) ($n->data['member_id'] ?? 0), $decided, true))
                ->each->update(['read_at' => now()]);
        }
        if (count(array_intersect($accept, $decided))) {
            $channel->touchActivity();
        }

        return response()->json(['ok' => true, 'decided' => count($decided)]);
    }

    /** Owner removes a member; optionally deletes all of that member's files. */
    public function remove(Request $request, string $uuid, int $memberId)
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');
        $channel = Channel::where('uuid', $uuid)->firstOrFail();

        abort_unless($channel->owner_id === $user->id, 403);

        $member = $channel->members()->where('id', $memberId)->firstOrFail();
        if ($member->user_id === $user->id) {
            return response()->json(['error' => 'You cannot remove yourself.'], 422);
        }

        if ($request->boolean('delete_files')) {
            $files = $channel->messages()
                ->where('user_id', $member->user_id)
                ->where('kind', '!=', 'text')
                ->get();
            foreach ($files as $msg) {
                $msg->deleteStoredFile();
                $msg->delete();
            }
        }

        ChatNotification::send($member->user_id, 'member_removed', [
            'channel_name' => $channel->name,
        ]);

        $member->delete();
        $channel->touchActivity();

        return response()->json(['ok' => true]);
    }
}
