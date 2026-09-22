<?php

namespace App\Support;

/**
 * Where a foreign server may be told to send push notifications.
 *
 * A relay subscription makes this server POST to a URL a client handed it,
 * which is exactly the shape of a server-side request forgery. So the URL has
 * to look like a Krotze relay endpoint and, unless the operator says
 * otherwise, has to be https and point at a public address.
 */
class RelayUrl
{
    public const PATH = '/api/push/relay/deliver';

    /**
     * Normalise `scheme://host[:port]` — what a client names when it asks for
     * a relay token. Null when the input is anything else (a path, a query,
     * credentials, an unknown scheme).
     */
    public static function origin(string $input): ?string
    {
        $parts = parse_url(trim($input));
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (($parts['path'] ?? '') !== '' && $parts['path'] !== '/') {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.($port && $port !== $default ? ':'.$port : '');
    }

    /** Why this URL may not be used as a relay target — null when it may. */
    public static function problem(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return 'The relay URL is not an absolute URL.';
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'The relay URL must be http(s).';
        }
        if ($scheme !== 'https' && ! config('push.relay_allow_insecure')) {
            return 'The relay URL must use https.';
        }
        if (($parts['path'] ?? '') !== self::PATH) {
            return 'The relay URL must end in '.self::PATH.'.';
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return 'The relay URL must not carry credentials, a query or a fragment.';
        }
        if (! config('push.relay_allow_private') && self::isPrivateHost($parts['host'])) {
            return 'The relay URL must point at a public address.';
        }

        return null;
    }

    /**
     * Loopback, private, link-local and reserved addresses, whether written
     * as an address or as a name that resolves to one. An unresolvable name
     * counts as private: we could not say where it leads.
     */
    public static function isPrivateHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $addresses) {
            return true;
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
        }

        return false;
    }
}
