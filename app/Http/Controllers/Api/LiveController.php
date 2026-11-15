<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\ChatUser;
use App\Models\Stream;
use App\Support\Live\Server;
use App\Support\Live\Ticket;
use Illuminate\Http\Request;

/**
 * The HTTP side of live streams: who may stream, and the ticket that lets a
 * member into the channel's room on the live server. The streams themselves
 * run over WebSockets — see App\Support\Live\Server.
 */
class LiveController extends Controller
{
    /** Owner grants or withdraws a member's right to stream. */
    public function setRole(Request $request, string $uuid, int $memberId)
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');
        $channel = Channel::where('uuid', $uuid)->firstOrFail();

        abort_unless($channel->owner_id === $user->id, 403);
        abort_unless($channel->type === 'group', 422);

        $data = $request->validate(['role' => 'required|in:member,streamer']);

        $target = $channel->members()
            ->where('id', $memberId)
            ->where('status', 'approved')
            ->firstOrFail();

        if ($target->user_id === $channel->owner_id) {
            return response()->json(['error' => 'The owner can always stream.'], 422);
        }

        $target->update(['role' => $data['role']]);

        // Withdrawing the role ends a stream in progress: the live server
        // sees the row go and stops relaying within a couple of seconds.
        if ($data['role'] !== ChannelMember::ROLE_STREAMER) {
            Stream::endFor($channel->id, $target->user_id);
        }

        return response()->json(['ok' => true]);
    }

    /** A one-minute ticket for this channel's room on the live server. */
    public function ticket(Request $request, string $uuid)
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');
        $channel = Channel::where('uuid', $uuid)->firstOrFail();
        $channel->members()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->firstOrFail();

        if (! Server::available()) {
            return response()->json(['error' => 'Live streams are not available on this server.'], 503);
        }

        return response()->json([
            'url' => config('live.url'),
            'ticket' => Ticket::issue($channel, $request->attributes->get('chatDevice')),
            'expires_in' => Ticket::TTL_SECONDS,
        ]);
    }
}
