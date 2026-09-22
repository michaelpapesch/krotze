@extends('layouts.site')

@section('title', __('site.faq.title'))
@section('description', __('site.faq.description'))

@push('jsonld')
    @include('partials.breadcrumb-jsonld', ['pageTitle' => __('site.faq.h1')])
    @php
    $faqJsonld = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'inLanguage' => app()->getLocale(),
        'mainEntity' => collect(__('site.faq.items'))->map(fn ($item) => [
            '@type' => 'Question',
            'name' => $item['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
        ])->values()->all(),
    ];
    @endphp
    <script type="application/ld+json">{!! json_encode($faqJsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

@section('content')
    <article class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold text-white">{{ __('site.faq.h1') }}</h1>

        <div class="mt-8 space-y-6">
            @foreach (__('site.faq.items') as $item)
                <details class="rounded-xl border border-white/10 bg-white/5 p-5 group">
                    <summary class="cursor-pointer font-semibold text-white list-none flex justify-between gap-4">
                        <span class="break-words">{{ $item['q'] }}</span>
                        <span class="text-zinc-500 group-open:rotate-45 transition-transform">+</span>
                    </summary>
                    <p class="mt-3 text-zinc-400 break-words">{{ $item['a'] }}</p>
                </details>
            @endforeach
        </div>
    </article>
@endsection
