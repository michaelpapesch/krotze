<?php

namespace App\Support;

/**
 * Time-based one-time passwords (RFC 6238 over RFC 4226), the thing every
 * authenticator app speaks. Twenty lines of HMAC — no composer package, in
 * keeping with the rest of the project.
 */
class Totp
{
    /** Seconds each code is valid for. Thirty is what authenticators assume. */
    public const PERIOD = 30;

    public const DIGITS = 6;

    /**
     * How many periods either side of now are accepted. One step covers a
     * phone clock that drifted by up to half a minute, which is common enough;
     * more than that starts widening the window for no real benefit.
     */
    private const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh shared secret, base32 as authenticator apps expect. */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Is $code valid for $secret right now?
     *
     * Compared with hash_equals against every accepted step, and always across
     * all of them, so the time taken says nothing about which step matched.
     */
    public static function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = intdiv(time(), self::PERIOD);
        $valid = false;
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if (hash_equals(self::at($secret, $counter + $i), $code)) {
                $valid = true;
            }
        }

        return $valid;
    }

    /** The code for a given counter value. */
    public static function at(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }

        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);

        // Dynamic truncation (RFC 4226 §5.3): the low nibble of the last byte
        // picks where to read the 31-bit value from.
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The otpauth:// URI an authenticator scans. The label carries the account
     * so somebody with several servers can tell them apart.
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
    }

    /** Grouped in fours, for anybody typing the secret in by hand. */
    public static function formatSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /* ------------------------------- base32 ------------------------------- */

    public static function base32Encode(string $raw): string
    {
        $bits = '';
        foreach (str_split($raw) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $secret): string
    {
        // Authenticators show the secret in groups, and people paste it back
        // with the spaces and sometimes the padding still attached.
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret));
        if ($secret === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
