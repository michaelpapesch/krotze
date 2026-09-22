<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Request authentication without a bearer credential: the client proves it
 * knows its device secret by signing method, path, timestamp, nonce and body
 * with it. Capturing the traffic yields a signature that is bound to one
 * request and expires — never a token that can be replayed or imported.
 */
class RequestSignature
{
    /** How far a client clock may drift, in seconds. */
    public const MAX_SKEW = 300;

    public static function canonical(Request $request, string $ts, string $nonce): string
    {
        return implode("\n", [
            strtoupper($request->method()),
            $request->getRequestUri(),
            $ts,
            $nonce,
            self::bodyHash($request),
        ]);
    }

    /**
     * PHP consumes a multipart body before we can read it, so uploads sign an
     * empty body. The client applies the identical rule (body instanceof
     * FormData), so both sides always agree.
     */
    public static function bodyHash(Request $request): string
    {
        $isMultipart = str_starts_with((string) $request->header('Content-Type'), 'multipart/form-data');

        return hash('sha256', $isMultipart ? '' : $request->getContent());
    }

    public static function sign(string $secret, string $canonical): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }
}
