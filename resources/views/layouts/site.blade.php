@php
    // English lives at the bare URLs, every other language under /<code>/… —
    // compute each language's counterpart of the current page for hreflang,
    // canonical and the language dropdown. The list lives in config/app.php.
    // All absolute URLs (canonical, hreflang, og:*) come from APP_URL via
    // SitePages, never from the request host: a hit on www. or plain http
    // must not leak into them.
    $locales = config('app.site_locales');
    $locale = app()->getLocale();
    $dir = $locales[$locale]['dir'] ?? 'ltr';
    $path = trim(request()->path(), '/');
    $seg = explode('/', $path)[0];
    $basePath = ($seg !== 'en' && isset($locales[$seg]))
        ? trim(substr($path, strlen($seg)), '/') : $path;
    // Only the localized public pages exist in every language; anything else
    // rendered with this layout (the admin area) is English-only, private and
    // must not be indexed or advertise translations it does not have.
    $isSitePage = in_array($basePath, \App\Support\SitePages::PAGES, true);
    $urlFor = fn (string $code) => \App\Support\SitePages::url($code, $basePath);
    $canonical = \App\Support\SitePages::absolute('/'.$path);
    $description = trim($__env->yieldContent('description', __('site.meta.description')));
    $p = $locale === 'en' ? '' : '/'.$locale; // prefix for locale-internal links
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0c0a14">
    <title>@yield('title', __('site.meta.title_default'))</title>
    <meta name="description" content="{{ $description }}">
    @if ($isSitePage)
        <link rel="canonical" href="{{ $canonical }}">
        @foreach ($locales as $code => $meta)
            <link rel="alternate" hreflang="{{ $code }}" href="{{ $urlFor($code) }}">
        @endforeach
        <link rel="alternate" hreflang="x-default" href="{{ $urlFor('en') }}">
    @else
        <meta name="robots" content="noindex">
    @endif
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Krotze">
    <meta property="og:title" content="@yield('title', __('site.meta.title_default'))">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="{{ $locales[$locale]['og'] }}">
    @if ($isSitePage)
        @foreach ($locales as $code => $meta)
            @if ($code !== $locale)
                <meta property="og:locale:alternate" content="{{ $meta['og'] }}">
            @endif
        @endforeach
    @endif
    <meta property="og:image" content="{{ \App\Support\SitePages::absolute('/img/og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="@yield('title', __('site.meta.title_default'))">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ \App\Support\SitePages::absolute('/img/og-image.png') }}">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/img/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/img/icon-192.png">
    @stack('jsonld')
    @vite(['resources/css/app.css', 'resources/js/landing.js'])
</head>
<body class="min-h-full bg-[#0c0a14] text-zinc-200 font-sans antialiased flex flex-col break-words">
    <header class="border-b border-white/10">
        <nav class="mx-auto max-w-5xl px-4 py-3 flex flex-wrap items-center gap-x-6 gap-y-2">
            <a href="{{ $p ?: '/' }}" class="flex items-center gap-2 font-semibold text-lg text-white">
                <img src="/img/icon.svg" alt="" class="h-7 w-7"> Krotze
            </a>
            <div class="ms-auto flex flex-wrap items-center gap-x-5 gap-y-1 text-sm text-zinc-400">
                <a href="{{ $p }}/about" class="hover:text-white">{{ __('site.nav.about') }}</a>
                <a href="/api/docs" class="hover:text-white">{{ __('site.nav.api') }}</a>
                <a href="{{ $p }}/faq" class="hover:text-white">{{ __('site.nav.faq') }}</a>
                <a href="{{ $p }}/contact" class="hover:text-white">{{ __('site.nav.contact') }}</a>
                <a href="/app" class="rounded-full bg-violet-600 px-4 py-1.5 font-medium text-white hover:bg-violet-500">{{ __('site.nav.open_app') }}</a>
                @if ($isSitePage)
                <details class="relative" id="lang-menu">
                    <summary class="flex cursor-pointer list-none items-center gap-1.5 rounded-lg border border-white/15 px-2.5 py-1.5 hover:bg-white/10 [&::-webkit-details-marker]:hidden">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"/>
                            <path d="M3 12h18M12 3c2.5 2.6 3.8 5.7 3.8 9S14.5 18.4 12 21c-2.5-2.6-3.8-5.7-3.8-9S9.5 5.6 12 3z"/>
                        </svg>
                        <span>{{ $locales[$locale]['name'] }}</span>
                        <span class="text-[10px] opacity-60">▾</span>
                    </summary>
                    <div class="absolute end-0 z-40 mt-2 w-36 rounded-xl border border-white/15 bg-[#171522] py-1 shadow-2xl">
                        @foreach ($locales as $code => $meta)
                            <a href="{{ $urlFor($code) }}" hreflang="{{ $code }}" rel="alternate" lang="{{ $code }}" dir="{{ $meta['dir'] }}"
                               class="block px-3 py-1.5 text-sm {{ $code === $locale ? 'font-semibold text-white' : 'text-zinc-300 hover:bg-white/10 hover:text-white' }}">
                                {{ $meta['name'] }}
                            </a>
                        @endforeach
                    </div>
                </details>
                @endif
            </div>
        </nav>
    </header>

    <main class="flex-1 w-full">
        @yield('content')
    </main>

    <footer class="border-t border-white/10 py-6 text-center text-sm text-zinc-500">
        <div class="mx-auto max-w-5xl px-4 flex flex-wrap justify-center gap-x-6 gap-y-2">
            <a href="{{ $p }}/about" class="hover:text-zinc-300">{{ __('site.footer.about') }}</a>
            <a href="{{ $p }}/imprint" class="hover:text-zinc-300">{{ __('site.footer.imprint') }}</a>
            <a href="{{ $p }}/privacy" class="hover:text-zinc-300">{{ __('site.footer.privacy') }}</a>
            <a href="{{ $p }}/terms" class="hover:text-zinc-300">{{ __('site.footer.terms') }}</a>
            <a href="{{ $p }}/faq" class="hover:text-zinc-300">{{ __('site.footer.faq') }}</a>
            <a href="{{ $p }}/contact" class="hover:text-zinc-300">{{ __('site.footer.contact') }}</a>
            <a href="mailto:{{ config('app.abuse_email') }}" class="hover:text-zinc-300">{{ __('site.footer.report_abuse') }}</a>
        </div>
        <p class="mt-3">© {{ date('Y') }} krotze.com — {{ __('site.footer.tagline') }}</p>
    </footer>
</body>
</html>
