@extends('layouts.site')

@section('title', __('site.privacy.title'))
@section('description', __('site.privacy.description'))

@push('jsonld')
    @include('partials.breadcrumb-jsonld', ['pageTitle' => __('site.privacy.h1')])
@endpush

@section('content')
    <article class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold text-white">{{ __('site.privacy.h1') }}</h1>

        <div class="mt-6 space-y-6 text-zinc-400 break-words">
            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.privacy.store_title') }}</h2>
                <p class="mt-2">
                    {{ __('site.privacy.store_text') }}
                </p>
            </section>
            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.privacy.auto_title') }}</h2>
                <p class="mt-2">
                    {{ __('site.privacy.auto_text') }}
                </p>
            </section>
            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.privacy.manual_title') }}</h2>
                <p class="mt-2">
                    {{ __('site.privacy.manual_text') }}
                </p>
            </section>
            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.privacy.cookies_title') }}</h2>
                <p class="mt-2">
                    {{ __('site.privacy.cookies_text') }}
                </p>
            </section>
            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.privacy.logs_title') }}</h2>
                <p class="mt-2">
                    {{ __('site.privacy.logs_text') }}
                </p>
            </section>
            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.privacy.contact_title') }}</h2>
                <p class="mt-2">
                    {{ __('site.privacy.contact_before') }}
                    <a href="mailto:support@krotze.com" class="text-violet-400 hover:underline break-all">support@krotze.com</a>.
                    {{ __('site.privacy.contact_after') }}
                    <a href="{{ app()->getLocale() === 'en' ? '' : '/'.app()->getLocale() }}/imprint" class="text-violet-400 hover:underline">{{ __('site.privacy.contact_imprint') }}</a>{{ in_array(__('site.privacy.contact_end'), ['.', '。'], true) ? '' : ' ' }}{{ __('site.privacy.contact_end') }}
                </p>
            </section>
        </div>
    </article>
@endsection
