@extends('layouts.site')

@push('jsonld')
    @php
    $appJsonld = [
        '@context' => 'https://schema.org',
        '@type' => 'WebApplication',
        'name' => 'Krotze',
        'url' => \App\Support\SitePages::url(app()->getLocale(), ''),
        'description' => __('site.meta.description'),
        'applicationCategory' => 'CommunicationApplication',
        'operatingSystem' => 'Any',
        'browserRequirements' => 'Requires JavaScript',
        'inLanguage' => app()->getLocale(),
        'image' => \App\Support\SitePages::absolute('/img/og-image.png'),
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
        'publisher' => [
            '@type' => 'Organization',
            'name' => '11n Networks',
            'url' => \App\Support\SitePages::absolute('/'),
            'logo' => \App\Support\SitePages::absolute('/img/icon-192.png'),
            'email' => 'support@krotze.com',
        ],
    ];
    @endphp
    <script type="application/ld+json">{!! json_encode($appJsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

@section('content')
    <div class="relative overflow-hidden">
        <img src="/img/header.svg" alt="{{ __('site.meta.title_default') }}"
             class="w-full max-h-[420px] object-cover">
    </div>

    <section class="mx-auto max-w-3xl px-4 py-12 text-center">
        <h1 class="text-3xl sm:text-4xl font-bold text-white break-words">
            {{ __('site.landing.h1') }}
        </h1>
        <p class="mt-4 text-lg text-zinc-400 break-words">
            {{ __('site.landing.intro') }}
        </p>

        <div class="mt-8 flex flex-wrap items-center justify-center gap-4">
            @if ($openRegistrations)
                <a href="/app#create"
                   class="rounded-full bg-violet-600 px-6 py-3 font-semibold text-white hover:bg-violet-500">
                    {{ __('site.landing.create_chat') }}
                </a>
            @endif
            <a href="/app"
               class="rounded-full {{ $openRegistrations ? 'border border-white/20 text-zinc-200 hover:bg-white/10' : 'bg-violet-600 text-white hover:bg-violet-500' }} px-6 py-3 font-semibold">
                {{ __('site.landing.open_app') }}
            </a>
        </div>

        @unless ($openRegistrations)
            <p class="mx-auto mt-6 max-w-md rounded-2xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-zinc-400 break-words">
                {!! __('site.landing.invite_only_html') !!}
            </p>
        @endunless
    </section>

    <section class="mx-auto max-w-3xl px-4 pb-16 text-center">
        <div class="rounded-2xl border border-white/10 bg-white/5 p-8 inline-block">
            <h2 class="text-xl font-semibold text-white">{{ __('site.landing.install_title') }}</h2>
            <p class="mt-2 text-sm text-zinc-400 max-w-sm mx-auto break-words">
                {{ __('site.landing.install_text') }}
            </p>
            <div class="mt-5 flex justify-center">
                <canvas id="install-qr" class="rounded-lg bg-white p-2 max-w-full"></canvas>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-5xl px-4 pb-20 grid gap-6 sm:grid-cols-3 text-sm">
        <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
            <h3 class="font-semibold text-white">{{ __('site.landing.feature1_title') }}</h3>
            <p class="mt-2 text-zinc-400 break-words">{{ __('site.landing.feature1_text') }}</p>
        </div>
        <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
            <h3 class="font-semibold text-white">{{ __('site.landing.feature2_title') }}</h3>
            <p class="mt-2 text-zinc-400 break-words">{{ __('site.landing.feature2_text') }}</p>
        </div>
        <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
            <h3 class="font-semibold text-white">{{ __('site.landing.feature3_title') }}</h3>
            <p class="mt-2 text-zinc-400 break-words">{{ __('site.landing.feature3_text') }}</p>
        </div>
    </section>
@endsection
