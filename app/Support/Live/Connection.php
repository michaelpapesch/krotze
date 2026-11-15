<?php

namespace App\Support\Live;

/** One client socket of the live server and everything known about it. */
class Connection
{
    /** Bytes received but not yet parsed. */
    public string $in = '';

    /** Bytes waiting for the socket to accept them. */
    public string $out = '';

    public bool $upgraded = false;

    public bool $closing = false;

    /** Set once the ticket was accepted. */
    public ?int $channelId = null;

    public ?int $userId = null;

    public ?int $deviceId = null;

    public string $username = '';

    /** Id of the stream this connection is sending, if any. */
    public ?int $streamId = null;

    /** A fragmented message being reassembled. */
    public ?int $fragmentOpcode = null;

    public string $fragment = '';

    /**
     * Per stream: true while this viewer has to wait for a keyframe (it just
     * joined, or frames were dropped because its connection is too slow).
     *
     * @var array<int, bool>
     */
    public array $waitingForKey = [];

    /** Upload accounting for the streamer bitrate cap. */
    public int $windowStart = 0;

    public int $windowBytes = 0;

    public float $connectedAt;

    /** @param  resource  $socket */
    public function __construct(public int $id, public $socket)
    {
        $this->connectedAt = microtime(true);
    }
}
