<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Web Push, hand-rolled: VAPID application-server identification (RFC 8292)
 * and aes128gcm payload encryption (RFC 8291 over RFC 8188).
 *
 * There is no composer package here on purpose — the project deliberately runs
 * on the Laravel skeleton alone. Everything below is PHP's openssl and hash
 * extensions plus a few well-known DER constants.
 *
 * What the push service sees is an opaque blob: the payload is encrypted with a
 * key derived from the subscriber's own public key and auth secret, so Google,
 * Mozilla and Microsoft relay ciphertext they cannot read. What they do learn is
 * that *a* message was sent to *that* subscription, and how big it was.
 */
class WebPush
{
    /** P-256 uncompressed public point: 0x04 || X(32) || Y(32). */
    private const POINT_LENGTH = 65;

    /**
     * DER prefix for a SubjectPublicKeyInfo wrapping an uncompressed P-256
     * point — SEQUENCE { SEQUENCE { id-ecPublicKey, prime256v1 }, BIT STRING }.
     */
    private const SPKI_PREFIX = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
        ."\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

    /** SEC1 ECPrivateKey DER, split around the 32-byte private scalar. */
    private const EC_KEY_PREFIX = "\x30\x77\x02\x01\x01\x04\x20";

    private const EC_KEY_MIDDLE = "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\xa1\x44\x03\x42\x00";

    /**
     * The record size advertised in the payload header. Push services accept at
     * most 4096 bytes of body, so this is both the ceiling and the one record
     * we ever send.
     */
    private const RECORD_SIZE = 4096;

    /** Largest plaintext that still fits: record minus GCM tag and delimiter. */
    public const MAX_PAYLOAD = self::RECORD_SIZE - 16 - 1 - 86;

    public static function configured(): bool
    {
        return (bool) (config('push.public_key') && config('push.private_key'));
    }

    public static function publicKey(): ?string
    {
        return config('push.public_key') ?: null;
    }

    /* ------------------------------ delivery ------------------------------ */

    /** @var (callable(array): array)|null Tests only — replaces the HTTP layer. */
    private static $transport = null;

    /**
     * Tests only: swap the network out. The callable receives the prepared
     * requests (`key => ['url', 'headers', 'body']`) and returns
     * `key => ['status' => int, 'body' => string]`.
     */
    public static function fakeTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * Deliver one payload to many subscriptions at once.
     *
     * @param  iterable<object>  $subscriptions
     * @return array<string, int> endpoint hash => HTTP status (0 when the request itself failed)
     */
    public static function send(iterable $subscriptions, array $payload): array
    {
        $deliveries = [];
        foreach ($subscriptions as $subscription) {
            $deliveries[] = [$subscription, $payload];
        }

        return self::sendMany($deliveries);
    }

    /**
     * Deliver many (subscription, payload) pairs in one round of requests.
     *
     * Browser subscriptions each get their own encrypted request. Relay
     * subscriptions — devices whose real subscription lives on another Krotze
     * server — are grouped by that server and sent as one plain JSON request
     * per server, which is what turns a message to a fifty-member channel into
     * one call instead of fifty.
     *
     * @param  array<int, array{0: object, 1: array}>  $deliveries
     * @return array<string, int> endpoint hash => HTTP status (0 when the request itself failed)
     */
    public static function sendMany(array $deliveries): array
    {
        $requests = [];
        $relayGroups = [];

        foreach ($deliveries as [$subscription, $payload]) {
            $key = self::keyOf($subscription);

            if (! empty($subscription->relay)) {
                $relayGroups[$subscription->endpoint]['rows'][$key] = $subscription->relay_token;
                $relayGroups[$subscription->endpoint]['items'][] = ['token' => $subscription->relay_token]
                    + array_intersect_key($payload, array_flip(['title', 'body', 'tag', 'uuid']));

                continue;
            }

            if (! self::configured()) {
                continue;
            }

            try {
                $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $body = self::encrypt($subscription->p256dh, $subscription->auth, $json);
                $headers = [
                    'Authorization: '.self::vapidHeader($subscription->endpoint),
                    'Content-Encoding: aes128gcm',
                    'Content-Type: application/octet-stream',
                    'TTL: '.config('push.ttl'),
                    'Urgency: normal',
                ];
            } catch (\Throwable $e) {
                // A single unusable subscription must not stop the others.
                Log::warning('Web Push encryption failed', [
                    'endpoint' => $subscription->endpoint, 'error' => $e->getMessage(),
                ]);

                continue;
            }

            $requests[$key] = ['url' => $subscription->endpoint, 'headers' => $headers, 'body' => $body];
        }

        foreach ($relayGroups as $endpoint => $group) {
            $requests['relay:'.$endpoint] = [
                'url' => $endpoint,
                'headers' => ['Content-Type: application/json', 'Accept: application/json'],
                'body' => json_encode(['items' => $group['items']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        if (! $requests) {
            return [];
        }

        $responses = self::$transport ? (self::$transport)($requests) : self::transport($requests);

        $results = [];
        foreach ($requests as $key => $request) {
            $status = (int) ($responses[$key]['status'] ?? 0);
            if (! str_starts_with($key, 'relay:')) {
                $results[$key] = $status;

                continue;
            }

            // The relay answers per token; anything but an accepted request
            // is reported for every row it carried.
            $endpoint = substr($key, 6);
            $perToken = json_decode((string) ($responses[$key]['body'] ?? ''), true)['results'] ?? [];
            foreach ($relayGroups[$endpoint]['rows'] as $hash => $token) {
                $results[$hash] = $status >= 200 && $status < 300
                    ? self::relayStatus($perToken[$token] ?? null)
                    : $status;
            }
        }

        return $results;
    }

    /**
     * What a relay said about one item, as the HTTP status a push service
     * would have used for the same thing — so the caller can reap dead rows
     * without knowing which kind of subscription it is looking at.
     */
    private static function relayStatus(?string $outcome): int
    {
        return match ($outcome) {
            'ok' => 200,
            'gone' => 410,
            'throttled' => 429,
            'no_subscription' => 412,
            default => 0,
        };
    }

    private static function keyOf(object $subscription): string
    {
        return $subscription->endpoint_hash ?? hash('sha256', $subscription->endpoint);
    }

    /**
     * Fire every request at once and collect the answers.
     *
     * @param  array<string, array{url: string, headers: array, body: string}>  $requests
     * @return array<string, array{status: int, body: string}>
     */
    private static function transport(array $requests): array
    {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($requests as $key => $request) {
            $ch = curl_init($request['url']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $request['body'],
                CURLOPT_HTTPHEADER => $request['headers'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => config('push.timeout'),
                CURLOPT_CONNECTTIMEOUT => config('push.timeout'),
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $responses = [];
        foreach ($handles as $key => $ch) {
            $responses[$key] = [
                'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'body' => (string) curl_multi_getcontent($ch),
            ];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);

        return $responses;
    }

    /* ------------------------------- VAPID -------------------------------- */

    /**
     * The `Authorization: vapid t=<jwt>,k=<key>` header proving this server is
     * the one the subscription was created for.
     */
    public static function vapidHeader(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $audience = $parts['scheme'].'://'.$parts['host'];

        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64(json_encode([
            'aud' => $audience,
            // RFC 8292 caps this at 24 hours; half that leaves room for a
            // device whose clock runs fast.
            'exp' => time() + 43200,
            'sub' => config('push.subject'),
        ]));

        $signature = self::signEs256($header.'.'.$claims);

        return 'vapid t='.$header.'.'.$claims.'.'.self::b64($signature)
            .',k='.config('push.public_key');
    }

    /** ES256 over the JWS signing input, in the raw r||s form JWS requires. */
    private static function signEs256(string $input): string
    {
        $key = self::privateKey();
        $der = '';
        if (! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the VAPID token.');
        }

        return self::derToRaw($der);
    }

    /**
     * openssl_sign emits DER — SEQUENCE { INTEGER r, INTEGER s } — but JWS wants
     * the two integers as fixed 32-byte big-endian values back to back. DER
     * trims leading zeros and prepends one to keep values positive, so both
     * have to be normalised.
     */
    private static function derToRaw(string $der): string
    {
        $offset = 2;
        if (ord($der[1]) > 0x80) {
            $offset += ord($der[1]) - 0x80; // long-form length
        }

        $read = function () use ($der, &$offset): string {
            if ($der[$offset] !== "\x02") {
                throw new RuntimeException('Malformed ECDSA signature.');
            }
            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);
            $offset += 2 + $length;

            return str_pad(ltrim($value, "\x00"), 32, "\x00", STR_PAD_LEFT);
        };

        return $read().$read();
    }

    /* ---------------------------- encryption ------------------------------ */

    /**
     * Encrypt a payload for one subscription (RFC 8291).
     *
     * @param  string  $p256dh  subscriber public key, base64url, 65 raw bytes
     * @param  string  $auth  subscriber auth secret, base64url, 16 raw bytes
     * @param  array|null  $fixed  ['scalar', 'point', 'salt'] — pins the values
     *                             that are otherwise random, so the RFC 8291
     *                             test vector can be reproduced. Tests only.
     */
    public static function encrypt(string $p256dh, string $auth, string $plaintext, ?array $fixed = null): string
    {
        $uaPublic = self::unb64($p256dh);
        $authSecret = self::unb64($auth);

        if (strlen($uaPublic) !== self::POINT_LENGTH || strlen($authSecret) < 16) {
            throw new RuntimeException('Malformed push subscription keys.');
        }
        if (strlen($plaintext) > self::MAX_PAYLOAD) {
            throw new RuntimeException('Push payload is too large.');
        }

        // A fresh ephemeral keypair per message: the shared secret, and with it
        // the content key, is never reused across two notifications.
        if ($fixed) {
            $asPublic = $fixed['point'];
            $ephemeral = self::ecPrivateKey($fixed['scalar'], $asPublic);
        } else {
            $ephemeral = self::newKey();
            $details = openssl_pkey_get_details($ephemeral);
            $asPublic = "\x04"
                .str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
                .str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        }

        // No length argument: P-256 agreement is 32 bytes, and passing one is
        // deprecated in PHP 8.4.
        $shared = openssl_pkey_derive(self::publicKeyResource($uaPublic), $ephemeral);
        if ($shared === false) {
            throw new RuntimeException('ECDH key agreement failed.');
        }

        // The auth secret is the salt that binds the derivation to this
        // subscriber; both public keys go into the info so neither side's key
        // can be swapped without changing the result.
        $ikm = self::hkdf($authSecret, $shared, "WebPush: info\x00".$uaPublic.$asPublic, 32);

        $salt = $fixed['salt'] ?? random_bytes(16);
        $cek = self::hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = self::hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext."\x02", // 0x02 marks this as the last record
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );
        if ($ciphertext === false) {
            throw new RuntimeException('Payload encryption failed.');
        }

        // RFC 8188 header: salt, record size, key id length, key id.
        return $salt
            .pack('N', self::RECORD_SIZE)
            .chr(self::POINT_LENGTH)
            .$asPublic
            .$ciphertext
            .$tag;
    }

    /** HKDF-SHA256 (RFC 5869): extract, then a single expand round. */
    private static function hkdf(string $salt, string $ikm, string $info, int $length): string
    {
        $prk = hash_hmac('sha256', $ikm, $salt, true);

        return substr(hash_hmac('sha256', $info."\x01", $prk, true), 0, $length);
    }

    /* ------------------------------- keys --------------------------------- */

    /** Generate a P-256 keypair, honouring the shipped OpenSSL config. */
    public static function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'config' => config('push.openssl_config'),
        ]);

        if (! $key) {
            // Silent failure here would mean every notification quietly
            // vanishing, so say exactly what went wrong.
            $errors = [];
            while (($e = openssl_error_string()) !== false) {
                $errors[] = $e;
            }
            throw new RuntimeException(
                'Could not generate an EC keypair. OpenSSL said: '.implode('; ', $errors)
                .' (config: '.config('push.openssl_config').')',
            );
        }

        return $key;
    }

    /**
     * Rebuild this server's signing key from the raw 32-byte scalar in the
     * config. Stored that way — rather than as a PEM — so the value is a single
     * line of base64url, interchangeable with every other Web Push tool.
     */
    private static function privateKey(): OpenSSLAsymmetricKey
    {
        $scalar = self::unb64((string) config('push.private_key'));
        $point = self::unb64((string) config('push.public_key'));

        if (strlen($scalar) !== 32 || strlen($point) !== self::POINT_LENGTH) {
            throw new RuntimeException('The configured VAPID keypair is malformed.');
        }

        return self::ecPrivateKey($scalar, $point);
    }

    /**
     * Assemble a usable EC key from the raw scalar and point. PHP can only load
     * PEM, so the SEC1 ECPrivateKey structure is built by hand around the two —
     * its layout for P-256 is fixed, which is what the constants above encode.
     */
    private static function ecPrivateKey(string $scalar, string $point): OpenSSLAsymmetricKey
    {
        $der = self::EC_KEY_PREFIX.$scalar.self::EC_KEY_MIDDLE.$point;
        $key = openssl_pkey_get_private(self::pem($der, 'EC PRIVATE KEY'));

        if (! $key) {
            throw new RuntimeException('The VAPID private key could not be read.');
        }

        return $key;
    }

    /** Wrap a raw uncompressed point as a public key OpenSSL will accept. */
    private static function publicKeyResource(string $point): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_public(self::pem(self::SPKI_PREFIX.$point, 'PUBLIC KEY'));

        if (! $key) {
            throw new RuntimeException('The subscription public key could not be read.');
        }

        return $key;
    }

    private static function pem(string $der, string $label): string
    {
        return "-----BEGIN {$label}-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END {$label}-----\n";
    }

    /** A fresh VAPID keypair, in the encoding config/push.php expects. */
    public static function generateVapidKeys(): array
    {
        $key = self::newKey();
        $details = openssl_pkey_get_details($key);

        $point = "\x04"
            .str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            .str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        return [
            'public' => self::b64($point),
            'private' => self::b64(str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT)),
        ];
    }

    /* ----------------------------- encoding ------------------------------- */

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function unb64(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'), false);
    }
}
