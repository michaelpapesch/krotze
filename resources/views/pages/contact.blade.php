@extends('layouts.site')

@section('title', __('site.contact.title'))
@section('description', __('site.contact.description'))

@push('jsonld')
    @include('partials.breadcrumb-jsonld', ['pageTitle' => __('site.contact.h1')])
    @php
    $contactJsonld = [
        '@context' => 'https://schema.org',
        '@type' => 'ContactPage',
        'name' => __('site.contact.title'),
        'url' => \App\Support\SitePages::absolute(request()->path()),
        'inLanguage' => app()->getLocale(),
        'about' => ['@type' => 'Organization', 'name' => '11n Networks', 'email' => 'support@krotze.com'],
    ];
    @endphp
    <script type="application/ld+json">{!! json_encode($contactJsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

@section('content')
    <article class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold text-white">{{ __('site.contact.h1') }}</h1>
        <div class="mt-6 space-y-4 text-zinc-400 break-words">
            <p>
                {{ __('site.contact.intro') }}
            </p>
            <p>
                {{ __('site.contact.email_label') }}
                <a href="mailto:support@krotze.com" class="text-violet-400 hover:underline break-all">support@krotze.com</a>
            </p>
            <p>
                {{ __('site.contact.no_data') }}
            </p>
            <p class="text-sm text-zinc-500">
                {{ __('site.contact.abuse_priority') }}
            </p>
        </div>
    </article>
@endsection
