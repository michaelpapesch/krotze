<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\ChatUser;
use App\Models\Message;
use App\Models\UploadView;
use App\Support\PushSender;
use App\Support\Thumbnail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    private const KIND_BY_MIME = [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'],
        'audio' => ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/x-m4a', 'audio/flac'],
        'video' => ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    /** List messages; ?after=<id> returns only newer ones (used for polling). */
    public function index(Request $request, string $uuid)
    {
        [$user, $channel, $member] = $this->resolve($request, $uuid);

        $after = (int) $request->query('after', 0);

        $query = $channel->messages()->with(['user', 'replyTo.user'])->whereNull('file_deleted_at')->orderBy('id');
        $messages = $after > 0
            ? $query->where('id', '>', $after)->limit(200)->get()
            : $channel->messages()->with(['user', 'replyTo.user'])->whereNull('file_deleted_at')
                ->orderByDesc('id')->limit(100)->get()->reverse()->values();

        $views = $this->viewsFor($messages, $user->id);

        // Fresh file deletions, so other clients can flash the deleter's name
        // at the message's position. Tombstones are pruned by the cleanup.
        $deletions = $after > 0
            ? $channel->messages()
                ->whereNotNull('file_deleted_at')
                ->where('file_deleted_at', '>=', now()->subMinutes(2))
                ->get(['id', 'file_deleted_by'])
                ->map(fn ($m) => ['id' => $m->id, 'by' => $m->file_deleted_by])
                ->values()
            : collect();

        return response()->json([
            'messages' => $messages->map(fn ($m) => $m->toClientArray($views->get($m->id)))->values(),
            'deletions' => $deletions,
        ]);
    }

    /**
     * Personal access records of this member for the given messages. Missing
     * ones are created (with a fresh capability token); tokens whose 5-minute
     * view window has passed are destroyed.
     */
    private function viewsFor($messages, int $userId)
    {
        $fileMessages = $messages->filter(fn ($m) => $m->file_path && ! $m->fileExpired());
        if ($fileMessages->isEmpty()) {
            return collect();
        }

        $views = UploadView::where('user_id', $userId)
            ->whereIn('message_id', $fileMessages->pluck('id'))
            ->get()
            ->keyBy('message_id');

        foreach ($fileMessages as $m) {
            $view = $views->get($m->id);
            if (! $view) {
                $views->put($m->id, UploadView::create([
                    'message_id' => $m->id,
                    'user_id' => $userId,
                    'token' => Str::random(48),
                ]));
            } elseif ($view->token && $view->viewWindowExpired()) {
                $view->update(['token' => null]);
            }
        }

        return $views;
    }

    /** Post a text message or upload a file (image / audio / video / zip). */
    public function store(Request $request, string $uuid)
    {
        [$user, $channel, $member] = $this->resolve($request, $uuid);

        // Open-join channels can be flooded by anyone with the link, so
        // posting is throttled per member (the owner is exempt).
        if ($channel->join_mode === 'open' && $channel->owner_id !== $user->id) {
            $key = 'open-msg:'.$channel->id.':'.$user->id;
            if (RateLimiter::tooManyAttempts($key, 20)) {
                return response()->json(['error' => 'You are sending messages too fast. Please wait a moment.'], 429);
            }
            RateLimiter::hit($key, 60);
        }

        // Quoted message must exist in this channel (silently dropped otherwise).
        $replyToId = ($rid = (int) $request->input('reply_to'))
            ? $channel->messages()->where('id', $rid)->value('id') : null;

        if ($request->hasFile('file')) {
            $request->validate([
                'file' => 'required|file|max:51200', // 50 MB
                'thumb' => 'nullable|file|max:2048', // client-captured video poster
            ]);
            $file = $request->file('file');
            $mime = $file->getMimeType();

            $kind = null;
            foreach (self::KIND_BY_MIME as $k => $mimes) {
                if (in_array($mime, $mimes, true)
                    || ($k === 'zip' && strtolower($file->getClientOriginalExtension()) === 'zip')) {
                    $kind = $k;
                    break;
                }
            }
            if (! $kind) {
                foreach (['image', 'audio', 'video'] as $k) {
                    if (str_starts_with((string) $mime, $k.'/')) {
                        $kind = $k;
                        break;
                    }
                }
            }
            if (! $kind) {
                return response()->json(['error' => 'Only images, audio, video and zip files are allowed.'], 422);
            }

            $allowedFlag = ['image' => 'allow_images', 'video' => 'allow_videos',
                'audio' => 'allow_audio', 'zip' => 'allow_zip'][$kind];
            if (! $channel->{$allowedFlag}) {
                return response()->json(['error' => ucfirst($kind).' uploads are disabled in this channel.'], 422);
            }

            $path = $file->store('uploads/'.$channel->uuid, 'public');

            $thumbPath = null;
            if ($kind === 'image') {
                // Always thumbnail (force): the inline <img> must never hit the
                // full file, since that would start the 5-minute view window.
                $thumbPath = $this->makeThumb(Storage::disk('public')->path($path), $channel->uuid, $path, force: true);
            } elseif ($kind === 'video' && $request->hasFile('thumb')
                && str_starts_with((string) $request->file('thumb')->getMimeType(), 'image/')) {
                // Poster frame captured client-side (no ffmpeg on the host);
                // re-encoding through GD sanitizes whatever the client sent.
                $thumbPath = $this->makeThumb($request->file('thumb')->getRealPath(), $channel->uuid, $path, force: true);
            }

            $message = Message::create([
                'channel_id' => $channel->id,
                'user_id' => $user->id,
                'author_alias' => $user->displayName(),
                'kind' => $kind,
                'reply_to_id' => $replyToId,
                'file_path' => $path,
                'thumb_path' => $thumbPath,
                'file_name' => $file->getClientOriginalName(),
                'file_mime' => $mime,
                'file_size' => $file->getSize(),
            ]);
        } else {
            // End-to-end encrypted bodies (private conversations only) carry a
            // ciphertext envelope with per-device wrapped keys — larger than
            // the text they hide, and opaque to the server by design.
            $encrypted = $request->boolean('encrypted');
            if ($encrypted && $channel->type !== 'private') {
                return response()->json(['error' => 'Only private conversations support end-to-end encryption.'], 422);
            }
            $data = $request->validate([
                'body' => 'required|string|max:'.($encrypted ? 65535 : 5000),
            ]);

            $message = Message::create([
                'channel_id' => $channel->id,
                'user_id' => $user->id,
                'author_alias' => $user->displayName(),
                'kind' => 'text',
                'encrypted' => $encrypted,
                'reply_to_id' => $replyToId,
                'body' => $data['body'],
            ]);
        }

        $channel->touchActivity();
        $member->update(['last_read_message_id' => $message->id]);

        // Reaches members whose app is closed; those with it open still learn
        // about the message from their next poll.
        $message->setRelation('channel', $channel);
        PushSender::forMessage($message);

        $view = $message->file_path ? UploadView::create([
            'message_id' => $message->id,
            'user_id' => $user->id,
            'token' => Str::random(48),
        ]) : null;

        return response()->json(['message' => $message->toClientArray($view)], 201);
    }

    /** Delete a message entirely (its author, or the channel owner). */
    public function destroy(Request $request, string $uuid, int $messageId)
    {
        [$user, $channel] = $this->resolve($request, $uuid);

        $message = $channel->messages()->where('id', $messageId)->firstOrFail();
        abort_unless($message->user_id === $user->id || $channel->owner_id === $user->id, 403);

        $message->deleteStoredFile();
        $message->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Remove a file message (any member may do this). Leaves a short-lived
     * tombstone so other clients can flash who deleted it; the tombstone is
     * pruned by the hourly cleanup and never shown in fresh message loads.
     */
    public function deleteFile(Request $request, string $uuid, int $messageId)
    {
        [$user, $channel, $member] = $this->resolve($request, $uuid);

        $message = $channel->messages()->where('id', $messageId)->firstOrFail();
        abort_if($message->kind === 'text' || $message->file_deleted_at, 422);
        if ($channel->restrict_delete) {
            abort_unless($message->user_id === $user->id || $channel->owner_id === $user->id, 403);
        }

        $message->deleteStoredFile();
        $message->views()->delete();
        $message->forceFill([
            'file_path' => null,
            'thumb_path' => null,
            'file_deleted_by' => $user->displayName(),
            'file_deleted_at' => now(),
        ])->save();

        return response()->json(['ok' => true, 'deleted_by' => $user->displayName()]);
    }

    private function makeThumb(string $srcAbs, string $channelUuid, string $storedPath, bool $force = false): ?string
    {
        $rel = 'uploads/'.$channelUuid.'/thumbs/'.pathinfo($storedPath, PATHINFO_FILENAME).'.webp';

        return Thumbnail::make($srcAbs, Storage::disk('public')->path($rel), force: $force) ? $rel : null;
    }

    /** @return array{0: ChatUser, 1: Channel, 2: ChannelMember} */
    private function resolve(Request $request, string $uuid): array
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');
        $channel = Channel::where('uuid', $uuid)->firstOrFail();
        $member = $channel->members()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->firstOrFail();

        return [$user, $channel, $member];
    }
}
