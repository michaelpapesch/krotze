@extends('layouts.site')

@section('title', __('site.terms.title'))
@section('description', __('site.terms.description'))

@push('jsonld')
    @include('partials.breadcrumb-jsonld', ['pageTitle' => __('site.terms.h1')])
@endpush

@section('content')
    <article class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold text-white">{{ __('site.terms.h1') }}</h1>

        <div class="mt-6 space-y-6 text-zinc-400 break-words">
            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.terms.accept_title') }}</h2>
                <p class="mt-2">
                    {{ __('site.terms.accept_text') }}
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.terms.prohibited_title') }}</h2>
                <p class="mt-2">
                    {{ __('site.terms.prohibited_text') }}
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.terms.enforcement_title') }}</h2>
                <p class="mt-2">
                    {{ __('site.terms.enforcement_text') }}
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.terms.report_title') }}</h2>
                <p class="mt-2">
                    {{ __('site.terms.report_before') }}
                    <a href="mailto:{{ config('app.abuse_email') }}" class="text-violet-400 hover:text-violet-300">{{ config('app.abuse_email') }}</a>.
                    {{ __('site.terms.report_after') }}
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.terms.ephemeral_title') }}</h2>
                <p class="mt-2">
                    {{ __('site.terms.ephemeral_text') }}
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.terms.warranty_title') }}</h2>
                <p class="mt-2">
                    {{ __('site.terms.warranty_text') }}
                </p>
            </section>
        </div>
    </article>
@endsection
