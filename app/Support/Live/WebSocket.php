<?php

namespace App\Support\Live;

use RuntimeException;

/**
 * The WebSocket wire format (RFC 6455), just enough of it for the live
 * server: the opening handshake, and reading and writing frames. Hand-rolled
 * like Web Push and TOTP — the project ships no packages beyond the skeleton.
 */
class WebSocket
{
    public const TEXT = 0x1;

    public const BINARY = 0x2;

    public const CLOSE = 0x8;

    public const PING = 0x9;

    public const PONG = 0xA;

    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * Answer an HTTP upgrade request. Returns the response to send, or null
     * when the request is not a WebSocket handshake.
     */
    public static function handshake(string $request): ?string
    {
        if (! preg_match('/^GET \S+ HTTP\/1\.1\r\n/', $request)
            || ! preg_match('/^Upgrade:\s*websocket\s*$/mi', $request)
            || ! preg_match('/^Sec-WebSocket-Key:\s*(\S+)\s*$/mi', $request, $m)) {
            return null;
        }

        return "HTTP/1.1 101 Switching Protocols\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            .'Sec-WebSocket-Accept: '.base64_encode(sha1($m[1].self::GUID, true))."\r\n\r\n";
    }

    /** A complete, unmasked (server → client) frame. */
    public static function encode(string $payload, int $opcode = self::BINARY): string
    {
        $length = strlen($payload);
        $head = chr(0x80 | $opcode);

        if ($length < 126) {
            $head .= chr($length);
        } elseif ($length < 65536) {
            $head .= chr(126).pack('n', $length);
        } else {
            $head .= chr(127).pack('J', $length);
        }

        return $head.$payload;
    }

    /**
     * Take one frame off the front of the buffer. Returns null while the
     * frame is still incomplete; throws on anything a well-behaved browser
     * would never send.
     *
     * @return array{fin: bool, opcode: int, payload: string}|null
     */
    public static function decode(string &$buffer, int $maxPayload): ?array
    {
        $available = strlen($buffer);
        if ($available < 2) {
            return null;
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        if ($first & 0x70) {
            throw new RuntimeException('Reserved bits set.');
        }
        if (! ($second & 0x80)) {
            throw new RuntimeException('Client frames must be masked.');
        }

        $length = $second & 0x7F;
        $offset = 2;
        if ($length === 126) {
            if ($available < 4) {
                return null;
            }
            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if ($available < 10) {
                return null;
            }
            $length = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        if ($length < 0 || $length > $maxPayload) {
            throw new RuntimeException('Frame too large.');
        }
        if ($available < $offset + 4 + $length) {
            return null;
        }

        $mask = substr($buffer, $offset, 4);
        $payload = substr($buffer, $offset + 4, $length);
        if ($length) {
            $payload ^= substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);
        }
        $buffer = substr($buffer, $offset + 4 + $length);

        return ['fin' => (bool) ($first & 0x80), 'opcode' => $first & 0x0F, 'payload' => $payload];
    }
}
