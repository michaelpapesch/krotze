<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
    <meta name="theme-color" content="#0c0a14">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Krotze Chat</title>
    <meta name="description" content="Krotze — anonymous, private group chats. No registration, no tracking.">
    {{-- The app shell has no crawlable content (the landing page is the
         indexable entry point); join/register links share this view and are
         per-invite URLs. Keep it out of the index, but leave the Open Graph
         tags so shared invite links get a preview. --}}
    <meta name="robots" content="noindex">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Krotze">
    <meta property="og:title" content="Krotze — Anonymous Chat">
    <meta property="og:description" content="Anonymous, private group chats. No registration, no tracking. Create a channel, share a QR code, chat.">
    <meta property="og:image" content="{{ url('/img/og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ url('/img/og-image.png') }}">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/img/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/img/icon-192.png">
    <script>
        window.KROTZE_VERSION = @json(config('app.version'));
        window.KROTZE_ABUSE = @json(config('app.abuse_email'));
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full overflow-hidden bg-[#0c0a14] text-zinc-200 font-sans antialiased">
    {{-- Height comes from #app in app.css (visual-viewport driven), not from h-full. --}}
    <div id="app" class="fixed inset-x-0 top-0 flex flex-col"></div>
    <noscript class="p-6 block text-center">Krotze needs JavaScript to run.</noscript>
</body>
</html>
