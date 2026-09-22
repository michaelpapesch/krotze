<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $channelName ? $channelName.' — Krotze' : 'Krotze Chat' }}</title>
    <script>
        window.KROTZE_EMBED = {
            token: @json($inviteToken),
            available: @json($available),
            name: @json($channelName),
            registrationsOpen: @json($registrationsOpen),
        };
    </script>
    @vite(['resources/css/app.css', 'resources/js/embed.js'])
</head>
<body class="h-full overflow-hidden bg-[#0c0a14] text-zinc-200 font-sans antialiased">
    <div id="embed" class="h-full flex flex-col"></div>
    <noscript class="p-6 block text-center text-sm">This chat needs JavaScript to run.</noscript>
</body>
</html>
