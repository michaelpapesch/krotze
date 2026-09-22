<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\ChatDevice;
use App\Models\ChatUser;
use App\Models\Message;
use App\Models\Setting;
use App\Support\Retention;
use App\Support\Scheduler;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SessionController extends Controller
{
    /**
     * Register this device, minting a brand-new anonymous identity.
     *
     * Whether that is allowed at all depends on the server: with "Open
     * registrations" on, anybody who reaches the app may register; with it off,
     * the caller must present the server's registration invite token, and the
     * admin may additionally require a manual decision before the identity can
     * do anything (see ChatAuth).
     *
     * This response is the only moment a device secret crosses the network;
     * every later request is signed with it instead of carrying it.
     */
    public function start(Request $request)
    {
        // Clients from before signed requests POST {token: …} and read a
        // `token` back. They cannot understand this endpoint's response, so on
        // their next 401 they would call here again — looping, and minting a
        // throwaway identity every time. Refuse them instead; the app shell is
        // served network-first, so reloading picks up the current bundle.
        if (! $request->has('device_name')) {
            return response()->json([
                'error' => 'This version of Krotze is out of date. Please reload the page.',
            ], 426);
        }

        if (! Setting::registrationsOpen()) {
            $invite = (string) $request->input('invite', '');
            if ($invite === '' || ! hash_equals(Setting::inviteToken(), $invite)) {
                return response()->json([
                    'error' => 'This server is invite-only. Ask for an invite link to register.',
                    'reason' => 'registration_closed',
                ], 403);
            }
        }

        $user = ChatUser::create([
            'status' => Setting::needsApproval() ? ChatUser::PENDING : ChatUser::APPROVED,
            'last_seen_at' => now(),
        ]);

        $device = ChatDevice::issue($user, $request->input('device_name'));
        $device->setRelation('user', $user);

        return response()->json($device->credentials(), 201);
    }

    /** Poll endpoint: channel list with unread counts + unread notifications. */
    public function state(Request $request)
    {
        $this->lazyCleanup();

        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');

        // An identity still waiting for (or refused by) the admin has no
        // channels to speak of — it only needs to learn where it stands.
        if (! $user->isApproved()) {
            return response()->json([
                'user_id' => $user->id,
                'username' => $user->username,
                'account_status' => $user->status,
                'channels' => [],
                'notifications' => [],
                'version' => config('app.version'),
            ]);
        }

        $memberships = $user->memberships()
            ->where('status', '!=', 'denied')
            ->with(['channel.members.user', 'channel.owner'])
            ->get()
            ->filter(fn ($m) => $m->channel !== null);

        $latest = $this->latestMessages($memberships->pluck('channel_id')->all());

        $channels = $memberships->map(function ($m) use ($user, $latest) {
            $ch = $m->channel;
            $isOwner = $ch->owner_id === $user->id;

            $name = $ch->name;
            if ($ch->type === 'private') {
                $other = $ch->members->first(fn ($x) => $x->user_id !== $user->id);
                $name = $other?->username() ?? 'Private chat';
            }

            $unread = Message::where('channel_id', $ch->id)
                ->where('id', '>', $m->last_read_message_id)
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
                ->count();

            return [
                'uuid' => $ch->uuid,
                'name' => $name,
                'type' => $ch->type,
                'status' => $m->status,
                'username' => $user->username,
                'is_owner' => $isOwner,
                'pinned' => $m->pinned,
                'hidden' => $m->hidden,
                'muted' => $m->muted,
                'unread' => $m->status === 'approved' ? $unread : 0,
                // Lets the client raise a notification for a message in a
                // channel it does not currently have open.
                'last_message' => $m->status === 'approved'
                    ? $this->messagePreview($latest->get($ch->id)) : null,
                'retention_days' => $ch->retention_days,
                'invite_url' => $isOwner && $ch->invite_token
                    ? url('/join/'.$ch->invite_token) : null,
                'pending_count' => $isOwner
                    ? $ch->members->where('status', 'pending')->count() : 0,
                'member_count' => $ch->members->where('status', 'approved')->count(),
                // Changes as soon as anyone joins, leaves or renames themselves.
                'members_hash' => Channel::membersFingerprint($ch->members),
                'last_activity_at' => $ch->last_activity_at?->toIso8601String(),
            ];
        })->values();

        $notifications = $user->notifications()
            ->whereNull('read_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'data' => $n->data,
                'created_at' => $n->created_at->toIso8601String(),
            ]);

        return response()->json([
            'user_id' => $user->id,
            'username' => $user->username,
            'account_status' => $user->status,
            'channels' => $channels,
            'notifications' => $notifications,
            'upload_retention_days' => Setting::uploadRetentionDays(),
            'upload_view_minutes' => Setting::uploadViewMinutes(),
            'version' => config('app.version'),
        ]);
    }

    /**
     * Newest message of each of the given channels, keyed by channel id — two
     * queries rather than one per channel.
     *
     * @return Collection<int, Message>
     */
    private function latestMessages(array $channelIds)
    {
        if (! $channelIds) {
            return collect();
        }

        $ids = Message::whereIn('channel_id', $channelIds)
            ->whereNull('file_deleted_at')
            ->groupBy('channel_id')
            ->selectRaw('max(id) as id')
            ->pluck('id');

        return Message::with('user')->whereIn('id', $ids)->get()->keyBy('channel_id');
    }

    private function messagePreview(?Message $message): ?array
    {
        return $message?->preview();
    }

    /** Delete everything belonging to this user (messages, files, memberships, account). */
    public function destroyMe(Request $request)
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');

        foreach ($user->memberships()->with('channel')->get() as $m) {
            $ch = $m->channel;
            if (! $ch) {
                continue;
            }
            if ($ch->owner_id === $user->id || $ch->type === 'private') {
                $ch->destroyCompletely();

                continue;
            }
            foreach ($ch->messages()->where('user_id', $user->id)->get() as $msg) {
                $msg->deleteStoredFile();
                $msg->delete();
            }
        }

        $user->delete(); // memberships + notifications cascade

        return response()->json(['ok' => true]);
    }

    /**
     * Retention, paid for by API traffic — the fallback for hosts where nothing
     * runs `schedule:run`. The first poll of each hour would pay for the sweep;
     * everybody else finds the lock taken and moves on.
     *
     * Where cron *is* driving the scheduler, this stands aside: the scheduled
     * run already covers it, and doing the same work twice only makes one
     * unlucky poller wait. The hourly lock is taken first, so the liveness
     * check costs nothing on the other 899 polls in that hour.
     */
    private function lazyCleanup(): void
    {
        if (! Cache::add('channels-cleanup-ran', 1, 3600)) {
            return;
        }
        if (Scheduler::isAlive()) {
            return;
        }

        Retention::prune();
    }
}
