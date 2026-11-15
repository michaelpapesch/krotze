<?php

namespace App\Support\Live;

use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\ChatDevice;
use App\Models\Stream;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * The live server: a single-process WebSocket relay for voice, camera and
 * screen streams.
 *
 * A streaming browser sends its encoded frames here and the server fans them
 * out to every other connection in the same channel's room. Browsers never
 * connect to each other — a member's network address is only ever seen by
 * this server. Nothing is decoded, transcoded or stored: frames are opaque
 * bytes that live in memory for as long as it takes to forward them.
 *
 * The protocol (documented for client authors in docs/API.md §9):
 *
 *   text frames    JSON control messages, `t` names the type
 *   binary frames  media. Streamer → server: [u8 kind][u32 ms][payload];
 *                  server → viewer: the same, prefixed with [u32 stream id].
 *                  kind 1 = video keyframe, 2 = video delta, 3 = audio.
 *
 * One process, one select() loop, no threads: every socket is non-blocking
 * and slow viewers are handled by dropping video (never by waiting for them).
 */
class Server
{
    public const KIND_KEY = 1;

    public const KIND_DELTA = 2;

    public const KIND_AUDIO = 3;

    /** Cache key the HTTP side reads to know the relay is up. */
    public const HEARTBEAT_KEY = 'live-server-heartbeat';

    private const HEARTBEAT_TTL = 20;

    /** A viewer with this much unsent data stops getting video until it catches up. */
    private const VIDEO_BACKLOG = 1_500_000;

    /** …and with this much, audio too. Past the last one it is cut off. */
    private const AUDIO_BACKLOG = 3_000_000;

    private const MAX_BACKLOG = 8_000_000;

    /** Seconds a fresh connection gets to upgrade and present its ticket. */
    private const JOIN_DEADLINE = 10;

    /** @var resource */
    private $listener;

    /** @var array<int, Connection> */
    private array $connections = [];

    /** @var array<int, array<int, true>> channel id → connection ids */
    private array $rooms = [];

    /**
     * @var array<int, array{id: int, channel: int, connection: int, user_id: int, username: string,
     *     audio: bool, video: ?string, vconf: ?array, lastKeyRequest: float}>
     */
    private array $streams = [];

    private int $nextId = 1;

    private float $lastTick = 0;

    private float $lastAudit = 0;

    private int $codeStamp;

    private bool $running = true;

    /** @var callable(string): void */
    private $log;

    public function __construct(?callable $log = null)
    {
        $this->log = $log ?? fn (string $line) => null;
        $this->codeStamp = self::codeStamp();
    }

    /** True while a live server is running and reachable by clients. */
    public static function available(): bool
    {
        return (bool) config('live.url') && Cache::has(self::HEARTBEAT_KEY);
    }

    /**
     * Bind the listening socket. Returns false when the port is taken —
     * which, for the scheduler that starts this every minute, simply means
     * "already running".
     */
    public function listen(): bool
    {
        $host = config('live.host');
        $port = config('live.port');
        $cert = config('live.tls_cert');

        $context = stream_context_create($cert ? ['ssl' => [
            'local_cert' => $cert,
            'local_pk' => config('live.tls_key') ?: $cert,
            'verify_peer' => false,
        ]] : []);

        $listener = @stream_socket_server(
            ($cert ? 'tls' : 'tcp')."://{$host}:{$port}", $errno, $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context,
        );
        if (! $listener) {
            return false;
        }

        stream_set_blocking($listener, false);
        $this->listener = $listener;

        // Whatever a previous process was relaying died with it.
        Stream::query()->delete();
        Cache::put(self::HEARTBEAT_KEY, time(), self::HEARTBEAT_TTL);

        return true;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function run(): void
    {
        while ($this->running) {
            $read = [$this->listener];
            $write = [];
            foreach ($this->connections as $c) {
                $read[] = $c->socket;
                if ($c->out !== '') {
                    $write[] = $c->socket;
                }
            }
            $except = null;

            if (@stream_select($read, $write, $except, 0, 250_000) === false) {
                usleep(50_000); // interrupted; never spin
            }

            foreach ($read as $socket) {
                if ($socket === $this->listener) {
                    $this->accept();
                } elseif ($c = $this->bySocket($socket)) {
                    $this->read($c);
                }
            }
            foreach ($write as $socket) {
                if ($c = $this->bySocket($socket)) {
                    $this->flush($c);
                }
            }

            $now = microtime(true);
            if ($now - $this->lastTick >= 2) {
                $this->lastTick = $now;
                $this->tick($now);
            }
        }

        foreach ($this->connections as $c) {
            $this->drop($c);
        }
        Stream::query()->delete();
        Cache::forget(self::HEARTBEAT_KEY);
        fclose($this->listener);
    }

    /* ------------------------------ sockets ------------------------------ */

    private function accept(): void
    {
        // With TLS the handshake happens inside accept; the short timeout
        // keeps one stalling client from holding everybody else up.
        $socket = @stream_socket_accept($this->listener, config('live.tls_cert') ? 2 : 0);
        if (! $socket) {
            return;
        }
        if (count($this->connections) >= config('live.max_connections')) {
            fclose($socket);

            return;
        }

        stream_set_blocking($socket, false);
        $id = $this->nextId++;
        $this->connections[$id] = new Connection($id, $socket);
    }

    private function bySocket($socket): ?Connection
    {
        foreach ($this->connections as $c) {
            if ($c->socket === $socket) {
                return $c;
            }
        }

        return null;
    }

    private function read(Connection $c): void
    {
        $chunk = @fread($c->socket, 262144);
        if ($chunk === '' || $chunk === false) {
            if (feof($c->socket)) {
                $this->drop($c);
            }

            return;
        }
        $c->in .= $chunk;

        try {
            if (! $c->upgraded) {
                $this->upgrade($c);
            }
            while ($c->upgraded && isset($this->connections[$c->id]) && ! $c->closing) {
                $frame = WebSocket::decode($c->in, config('live.max_frame_bytes') + 16);
                if ($frame === null) {
                    break;
                }
                $this->frame($c, $frame);
            }
        } catch (Throwable $e) {
            $this->close($c, 1002, $e instanceof RuntimeException ? $e->getMessage() : 'Protocol error.');
            if (! $e instanceof RuntimeException) {
                ($this->log)('error: '.$e->getMessage());
            }
        }
    }

    private function upgrade(Connection $c): void
    {
        $end = strpos($c->in, "\r\n\r\n");
        if ($end === false) {
            if (strlen($c->in) > 8192) {
                $this->drop($c);
            }

            return;
        }

        $response = WebSocket::handshake(substr($c->in, 0, $end + 4));
        if ($response === null) {
            $c->out .= "HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
            $c->closing = true;
            $this->flush($c);

            return;
        }

        $c->in = substr($c->in, $end + 4);
        $c->upgraded = true;
        $c->out .= $response;
        $this->flush($c);
    }

    private function flush(Connection $c): void
    {
        if ($c->out !== '') {
            $written = @fwrite($c->socket, $c->out);
            if ($written === false) {
                $this->drop($c);

                return;
            }
            if ($written > 0) {
                $c->out = (string) substr($c->out, $written);
            }
        }
        if ($c->closing && $c->out === '') {
            $this->drop($c);
        }
    }

    private function send(Connection $c, string $payload, int $opcode = WebSocket::BINARY): void
    {
        $this->sendRaw($c, WebSocket::encode($payload, $opcode));
    }

    private function sendRaw(Connection $c, string $frame): void
    {
        if ($c->closing) {
            return;
        }
        if (strlen($c->out) > self::MAX_BACKLOG) {
            $this->drop($c);

            return;
        }
        $idle = $c->out === '';
        $c->out .= $frame;
        if ($idle) {
            $this->flush($c); // nothing queued: write straight away
        }
    }

    private function json(Connection $c, array $message): void
    {
        $this->send($c, json_encode($message), WebSocket::TEXT);
    }

    /** Say goodbye properly, then hang up once the frame is out. */
    private function close(Connection $c, int $code, string $reason): void
    {
        if (! isset($this->connections[$c->id]) || $c->closing) {
            return;
        }
        $this->leave($c);
        if ($c->upgraded) {
            $c->out .= WebSocket::encode(pack('n', $code).substr($reason, 0, 100), WebSocket::CLOSE);
        }
        $c->closing = true;
        $this->flush($c);
    }

    private function drop(Connection $c): void
    {
        if (! isset($this->connections[$c->id])) {
            return;
        }
        $this->leave($c);
        unset($this->connections[$c->id]);
        @fclose($c->socket);
    }

    /** Take a connection out of its room, ending its stream. */
    private function leave(Connection $c): void
    {
        if ($c->streamId !== null) {
            $this->endStream($c->streamId);
        }
        $channel = $c->channelId;
        if ($channel !== null) {
            $c->channelId = null;
            unset($this->rooms[$channel][$c->id]);
            if (empty($this->rooms[$channel])) {
                unset($this->rooms[$channel]);
            } else {
                $this->announceViewers($channel);
            }
        }
    }

    /* ------------------------------- frames ------------------------------ */

    /** @param  array{fin: bool, opcode: int, payload: string}  $frame */
    private function frame(Connection $c, array $frame): void
    {
        $opcode = $frame['opcode'];

        if ($opcode === WebSocket::PING) {
            $this->send($c, $frame['payload'], WebSocket::PONG);

            return;
        }
        if ($opcode === WebSocket::PONG) {
            return;
        }
        if ($opcode === WebSocket::CLOSE) {
            $this->close($c, 1000, '');

            return;
        }

        // Reassemble fragmented messages (browsers fragment large sends).
        if ($opcode === 0) {
            if ($c->fragmentOpcode === null) {
                throw new RuntimeException('Unexpected continuation.');
            }
            $c->fragment .= $frame['payload'];
            if (strlen($c->fragment) > config('live.max_frame_bytes') + 16) {
                throw new RuntimeException('Message too large.');
            }
            if (! $frame['fin']) {
                return;
            }
            $opcode = $c->fragmentOpcode;
            $payload = $c->fragment;
            $c->fragmentOpcode = null;
            $c->fragment = '';
        } elseif (! $frame['fin']) {
            $c->fragmentOpcode = $opcode;
            $c->fragment = $frame['payload'];

            return;
        } else {
            $payload = $frame['payload'];
        }

        if ($opcode === WebSocket::BINARY) {
            $this->media($c, $payload);
        } elseif ($opcode === WebSocket::TEXT) {
            $message = json_decode($payload, true);
            if (! is_array($message) || ! is_string($message['t'] ?? null)) {
                throw new RuntimeException('Malformed message.');
            }
            $this->control($c, $message);
        } else {
            throw new RuntimeException('Unknown opcode.');
        }
    }

    private function control(Connection $c, array $m): void
    {
        if ($m['t'] === 'join') {
            $this->join($c, (string) ($m['ticket'] ?? ''));

            return;
        }
        if ($c->channelId === null) {
            throw new RuntimeException('Join first.');
        }

        match ($m['t']) {
            'start', 'update' => $this->publish($c, $m),
            'stop' => $this->stopRequest($c, isset($m['id']) ? (int) $m['id'] : null),
            'keyframe' => $this->requestKeyframe((int) ($m['id'] ?? 0), $c->channelId),
            default => null, // unknown types are ignored, so the protocol can grow
        };
    }

    private function join(Connection $c, string $ticket): void
    {
        if ($c->channelId !== null) {
            return;
        }

        $claims = Ticket::verify($ticket);
        $device = $claims ? ChatDevice::with('user')->find($claims['d']) : null;
        $member = $claims && $device && $device->user_id === $claims['u']
            ? ChannelMember::where('channel_id', $claims['c'])
                ->where('user_id', $claims['u'])
                ->where('status', 'approved')
                ->first()
            : null;
        if (! $member) {
            $this->close($c, 4401, 'Ticket refused.');

            return;
        }

        $c->channelId = $claims['c'];
        $c->userId = $claims['u'];
        $c->deviceId = $claims['d'];
        $c->username = $device->user?->displayName() ?? 'anonymous';
        $this->rooms[$c->channelId][$c->id] = true;

        $streams = [];
        foreach ($this->streams as $s) {
            if ($s['channel'] === $c->channelId) {
                $streams[] = $this->describe($s, $c);
                $c->waitingForKey[$s['id']] = true;
                $this->requestKeyframe($s['id'], $c->channelId);
            }
        }

        $this->json($c, ['t' => 'joined', 'streams' => $streams]);
        $this->announceViewers($c->channelId);
    }

    /** `start` and `update`: what this connection is sending. */
    private function publish(Connection $c, array $m): void
    {
        $audio = (bool) ($m['audio'] ?? false);
        $video = in_array($m['video'] ?? null, ['camera', 'screen'], true) ? $m['video'] : null;
        $vconf = $video ? $this->videoConfig($m['vconf'] ?? null) : null;

        if ($c->streamId === null) {
            if (! $this->mayStream($c)) {
                $this->json($c, ['t' => 'error', 'error' => 'You are not allowed to stream in this channel.']);

                return;
            }

            // One stream per member and channel: a second device takes over.
            foreach ($this->streams as $s) {
                if ($s['channel'] === $c->channelId && $s['user_id'] === $c->userId) {
                    $this->endStream($s['id']);
                }
            }
            $running = count(array_filter($this->streams, fn ($s) => $s['channel'] === $c->channelId));
            if ($running >= Stream::MAX_PER_CHANNEL) {
                $this->json($c, ['t' => 'error', 'error' => 'Too many streams are running in this channel already.']);

                return;
            }

            Stream::endFor($c->channelId, $c->userId);
            $row = Stream::create([
                'channel_id' => $c->channelId,
                'user_id' => $c->userId,
                'device_id' => $c->deviceId,
                'audio' => $audio,
                'video' => $video,
                'last_seen_at' => now(),
            ]);
            Channel::find($c->channelId)?->touchActivity();

            $c->streamId = $row->id;
            $this->streams[$row->id] = [
                'id' => $row->id, 'channel' => $c->channelId, 'connection' => $c->id,
                'user_id' => $c->userId, 'username' => $c->username,
                'audio' => $audio, 'video' => $video, 'vconf' => $vconf, 'lastKeyRequest' => 0.0,
            ];
            foreach ($this->room($c->channelId) as $viewer) {
                $viewer->waitingForKey[$row->id] = true;
            }
        } else {
            $s = &$this->streams[$c->streamId];
            if ($s['audio'] !== $audio || $s['video'] !== $video) {
                Stream::whereKey($s['id'])->update(['audio' => $audio, 'video' => $video]);
            }
            $s['audio'] = $audio;
            $s['video'] = $video;
            $s['vconf'] = $vconf;
            unset($s);
        }

        $stream = $this->streams[$c->streamId];
        foreach ($this->room($c->channelId) as $member) {
            $this->json($member, ['t' => 'stream', 'stream' => $this->describe($stream, $member)]);
        }
        $this->announceViewers($c->channelId);
    }

    /** The decoder configuration viewers need; passed through, but bounded. */
    private function videoConfig(mixed $vconf): ?array
    {
        if (! is_array($vconf)
            || ! is_string($vconf['codec'] ?? null) || ! preg_match('/^[A-Za-z0-9.\-]{2,40}$/', $vconf['codec'])) {
            return null;
        }

        return [
            'codec' => $vconf['codec'],
            'width' => max(0, min(8192, (int) ($vconf['width'] ?? 0))),
            'height' => max(0, min(8192, (int) ($vconf['height'] ?? 0))),
        ];
    }

    /** A streamer stops their own stream; the channel owner may stop anyone's. */
    private function stopRequest(Connection $c, ?int $id): void
    {
        $id ??= $c->streamId;
        $stream = $id !== null ? ($this->streams[$id] ?? null) : null;
        if (! $stream || $stream['channel'] !== $c->channelId) {
            return;
        }
        if ($stream['user_id'] !== $c->userId
            && Channel::whereKey($c->channelId)->value('owner_id') !== $c->userId) {
            return;
        }

        $this->endStream($id);
    }

    private function endStream(int $id): void
    {
        $stream = $this->streams[$id] ?? null;
        if (! $stream) {
            return;
        }
        unset($this->streams[$id]);
        Stream::whereKey($id)->delete();

        if ($source = $this->connections[$stream['connection']] ?? null) {
            $source->streamId = null;
        }
        foreach ($this->room($stream['channel']) as $member) {
            unset($member->waitingForKey[$id]);
            $this->json($member, ['t' => 'ended', 'id' => $id]);
        }
    }

    /** Ask the streamer for a keyframe so a waiting viewer can start decoding. */
    private function requestKeyframe(int $id, int $channel): void
    {
        $stream = $this->streams[$id] ?? null;
        $now = microtime(true);
        if (! $stream || $stream['channel'] !== $channel || $now - $stream['lastKeyRequest'] < 1) {
            return;
        }
        $this->streams[$id]['lastKeyRequest'] = $now;
        if ($source = $this->connections[$stream['connection']] ?? null) {
            $this->json($source, ['t' => 'keyframe']);
        }
    }

    /** A media frame from a streamer: fan it out to the rest of the room. */
    private function media(Connection $c, string $payload): void
    {
        if ($c->streamId === null || strlen($payload) < 5) {
            return; // not (or no longer) streaming — late frames are harmless
        }
        $kind = ord($payload[0]);
        if ($kind < self::KIND_KEY || $kind > self::KIND_AUDIO) {
            return;
        }

        // Bitrate cap, so one streamer cannot turn the relay into a firehose.
        $now = time();
        if ($now - $c->windowStart >= 10) {
            $c->windowStart = $now;
            $c->windowBytes = 0;
        }
        $c->windowBytes += strlen($payload);
        if ($c->windowBytes > config('live.max_bytes_per_10s')) {
            $this->json($c, ['t' => 'error', 'error' => 'Your stream exceeded the bitrate limit and was stopped.']);
            $this->endStream($c->streamId);

            return;
        }

        $id = $c->streamId;
        $frame = WebSocket::encode(pack('N', $id).$payload);

        foreach ($this->room($c->channelId) as $viewer) {
            if ($viewer->id === $c->id) {
                continue;
            }
            $backlog = strlen($viewer->out);

            if ($kind === self::KIND_AUDIO) {
                if ($backlog < self::AUDIO_BACKLOG) {
                    $this->sendRaw($viewer, $frame);
                }

                continue;
            }

            // Video for a viewer that cannot keep up is dropped, and stays
            // dropped until the next keyframe — a delta without the frames
            // before it would only decode to garbage.
            if ($backlog >= self::VIDEO_BACKLOG) {
                $viewer->waitingForKey[$id] = true;
            } elseif ($kind === self::KIND_KEY) {
                $viewer->waitingForKey[$id] = false;
                $this->sendRaw($viewer, $frame);
            } elseif (! ($viewer->waitingForKey[$id] ?? true)) {
                $this->sendRaw($viewer, $frame);
            }
        }
    }

    /* ------------------------------ helpers ------------------------------ */

    /** @return array<int, Connection> */
    private function room(?int $channel): array
    {
        $members = [];
        foreach (array_keys($this->rooms[$channel] ?? []) as $id) {
            if (isset($this->connections[$id])) {
                $members[] = $this->connections[$id];
            }
        }

        return $members;
    }

    private function describe(array $stream, Connection $for): array
    {
        return [
            'id' => $stream['id'],
            'user_id' => $stream['user_id'],
            'username' => $stream['username'],
            'audio' => $stream['audio'],
            'video' => $stream['video'],
            'vconf' => $stream['vconf'],
            'mine' => $stream['connection'] === $for->id,
        ];
    }

    /** Tell each streamer in a room how many others are connected. */
    private function announceViewers(int $channel): void
    {
        $room = $this->room($channel);
        foreach ($room as $member) {
            if ($member->streamId !== null) {
                $this->json($member, ['t' => 'viewers', 'count' => count($room) - 1]);
            }
        }
    }

    private function mayStream(Connection $c): bool
    {
        $channel = Channel::find($c->channelId);
        $member = $channel?->members()->where('user_id', $c->userId)->first();

        return (bool) $member?->canStream($channel);
    }

    /* -------------------------------- tick ------------------------------- */

    private function tick(float $now): void
    {
        try {
            // Streams deleted over HTTP (role withdrawn, member removed,
            // channel destroyed) end here; the rest get their heartbeat.
            if ($this->streams) {
                $alive = Stream::whereIn('id', array_keys($this->streams))->pluck('id')->all();
                foreach (array_diff(array_keys($this->streams), $alive) as $gone) {
                    $this->endStream($gone);
                }
                if ($alive) {
                    Stream::whereIn('id', $alive)->update(['last_seen_at' => now()]);
                }
            }

            foreach ($this->connections as $c) {
                if ($c->channelId === null && $now - $c->connectedAt > self::JOIN_DEADLINE) {
                    $this->drop($c);
                }
            }

            if ($now - $this->lastAudit >= 10) {
                $this->lastAudit = $now;
                $this->audit();
                Cache::put(self::HEARTBEAT_KEY, time(), self::HEARTBEAT_TTL);

                // A deploy replaced the code under us: step aside, whatever
                // supervises this process starts the new version.
                if (self::codeStamp() !== $this->codeStamp) {
                    ($this->log)('code changed, exiting for a restart');
                    $this->running = false;
                }
            }
        } catch (Throwable $e) {
            ($this->log)('tick failed: '.$e->getMessage());
        }
    }

    /**
     * Membership can end while somebody is connected. Every ten seconds each
     * room is checked against the database: who is no longer an approved
     * member is disconnected, who may no longer stream is stopped.
     */
    private function audit(): void
    {
        foreach (array_keys($this->rooms) as $channelId) {
            $channel = Channel::find($channelId);
            $members = $channel
                ? $channel->members()->where('status', 'approved')->get()->keyBy('user_id')
                : collect();

            foreach ($this->room($channelId) as $c) {
                $member = $members->get($c->userId);
                if (! $member) {
                    $this->close($c, 4403, 'No longer a member.');
                } elseif ($c->streamId !== null && ! $member->canStream($channel)) {
                    $this->json($c, ['t' => 'error', 'error' => 'You are no longer allowed to stream in this channel.']);
                    $this->endStream($c->streamId);
                }
            }
        }
    }

    private static function codeStamp(): int
    {
        clearstatcache();

        return (int) @filemtime(config_path('app.php'));
    }
}
