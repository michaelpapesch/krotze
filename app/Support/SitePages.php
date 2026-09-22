<?php

namespace App\Support;

/**
 * Registry of the public static site: which pages exist, in which languages,
 * and the absolute URL of each — the single source for routes, canonical and
 * hreflang links, the sitemap and llms.txt, so they cannot drift apart.
 *
 * English lives at the bare URLs (and is the x-default), every other locale
 * under /<code>/… (config/app.php site_locales).
 */
class SitePages
{
    /** Page slugs ('' is the landing page) in navigation order. */
    public const PAGES = ['', 'about', 'faq', 'contact', 'privacy', 'terms', 'imprint'];

    /** Lang-file key holding a page's title/description ('' → meta). */
    public static function key(string $page): string
    {
        return $page === '' ? 'meta' : $page;
    }

    /** @return list<string> locale codes, English first */
    public static function locales(): array
    {
        return array_keys(config('app.site_locales'));
    }

    /** Root path of a locale: '' for English, '/de' etc. otherwise. */
    public static function prefix(string $locale): string
    {
        return $locale === 'en' ? '' : '/'.$locale;
    }

    /** Relative path of a page in a locale ('/', '/de', '/de/faq'). */
    public static function path(string $locale, string $page): string
    {
        $prefix = self::prefix($locale);

        return $page === '' ? ($prefix ?: '/') : $prefix.'/'.$page;
    }

    /**
     * Absolute URL built from APP_URL (never from the request host, so a hit
     * via www. or plain http cannot leak into canonical/hreflang/sitemap).
     */
    public static function url(string $locale, string $page): string
    {
        return self::absolute(self::path($locale, $page));
    }

    public static function absolute(string $path): string
    {
        return rtrim(config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * Last modification of a page: the newest of its view, its layout and the
     * locale's copy. Git checkout only touches files that changed, so the
     * mtimes on the host reflect real content changes.
     */
    public static function lastmod(string $locale, string $page): string
    {
        $view = $page === '' ? 'landing' : 'pages/'.$page;
        $times = array_filter(array_map(
            fn (string $f) => is_file($f) ? filemtime($f) : null,
            [
                resource_path("views/{$view}.blade.php"),
                resource_path('views/layouts/site.blade.php'),
                lang_path("{$locale}/site.php"),
            ]
        ));

        return date('Y-m-d', $times ? max($times) : time());
    }
}
