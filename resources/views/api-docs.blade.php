<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0c0a14">
    <title>API reference — Krotze</title>
    <meta name="description" content="The Krotze HTTP API: registration, channels, messages, uploads and Web Push, for custom clients.">
    <link rel="canonical" href="{{ \App\Support\SitePages::absolute('/api/docs') }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Krotze">
    <meta property="og:title" content="API reference — Krotze">
    <meta property="og:description" content="The Krotze HTTP API: registration, channels, messages, uploads and Web Push, for custom clients.">
    <meta property="og:url" content="{{ \App\Support\SitePages::absolute('/api/docs') }}">
    <meta property="og:image" content="{{ \App\Support\SitePages::absolute('/img/og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="API reference — Krotze">
    <meta name="twitter:description" content="The Krotze HTTP API: registration, channels, messages, uploads and Web Push, for custom clients.">
    <meta name="twitter:image" content="{{ \App\Support\SitePages::absolute('/img/og-image.png') }}">
    <link rel="icon" href="/img/icon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/api-docs.js'])
    <style>
        /* Swagger UI ships a light theme; keep the page chrome dark around it
           rather than fighting the whole widget. */
        body { background: #0c0a14; }
        .swagger-ui { background: #fff; border-radius: 0.75rem; }
        #swagger { padding: 0.5rem 0 2rem; }
    </style>
</head>
<body class="min-h-full bg-[#0c0a14] text-zinc-200 font-sans antialiased">
    <header class="border-b border-white/10">
        <nav class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
            <a href="/" class="flex items-center gap-2 text-lg font-semibold text-white">
                <img src="/img/icon.svg" alt="" class="h-7 w-7"> Krotze
            </a>
            <span class="text-sm text-zinc-500">API reference</span>
            <div class="ml-auto flex flex-wrap items-center gap-x-5 gap-y-1 text-sm text-zinc-400">
                <a href="/api/openapi.json" class="hover:text-white">openapi.json</a>
                <a href="/about" class="hover:text-white">About</a>
                <a href="/faq" class="hover:text-white">FAQ</a>
                <a href="/contact" class="hover:text-white">Contact</a>
                <a href="/app" class="rounded-full bg-violet-600 px-4 py-1.5 font-medium text-white hover:bg-violet-500">Open app</a>
            </div>
        </nav>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8">
        <h1 class="text-2xl font-bold text-white">Build your own Krotze client</h1>
        <p class="mt-2 max-w-3xl text-sm text-zinc-400 break-words">
            Everything the official app does, this API does — registering an identity, joining channels,
            sending messages and files, and receiving Web Push while your client is closed. There is no
            login and no bearer token: each device holds a secret and signs every request with it.
        </p>

        <div id="signing-note" hidden
             class="mt-5 rounded-xl border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm text-amber-200 break-words">
            <b>About “Try it out”:</b> it works for the unsigned endpoints
            (<code>/api/push/key</code>, <code>GET /api/join/{token}</code>, <code>POST /api/session</code>).
            The signed ones need an HMAC computed over the method, path, timestamp, nonce and body, which a
            browser form cannot produce — use the reference client in
            <code>docs/API.md</code> instead.
        </div>

        <div id="swagger" class="mt-6"></div>
    </main>

    <footer class="border-t border-white/10 py-6 text-center text-sm text-zinc-500">
        <div class="mx-auto flex max-w-5xl flex-wrap justify-center gap-x-6 gap-y-2 px-4">
            <a href="/about" class="hover:text-zinc-300">About</a>
            <a href="/imprint" class="hover:text-zinc-300">Imprint</a>
            <a href="/privacy" class="hover:text-zinc-300">Privacy</a>
            <a href="/terms" class="hover:text-zinc-300">Terms</a>
            <a href="/faq" class="hover:text-zinc-300">FAQ</a>
            <a href="/contact" class="hover:text-zinc-300">Contact</a>
            <a href="mailto:{{ config('app.abuse_email') }}" class="hover:text-zinc-300">Report abuse</a>
        </div>
        <p class="mt-3">© {{ date('Y') }} krotze.com — anonymous chat</p>
    </footer>
</body>
</html>
