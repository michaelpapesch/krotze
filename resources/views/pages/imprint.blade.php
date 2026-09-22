@extends('layouts.site')

@section('title', __('site.imprint.title'))
@section('description', __('site.imprint.description'))

@push('jsonld')
    @include('partials.breadcrumb-jsonld', ['pageTitle' => __('site.imprint.h1')])
@endpush

@section('content')
    <article class="mx-auto max-w-3xl px-4 py-12 prose-invert">
        <h1 class="text-3xl font-bold text-white">{{ __('site.imprint.h1') }}</h1>
        <div class="mt-6 space-y-4 text-zinc-400 break-words">
            <p>{{ __('site.imprint.statute') }}</p>
            <p>
                11n Networks<br>
                Ostergärten 22<br>
                55283 Nierstein, Germany
            </p>
            <p>
                {{ __('site.imprint.email_label') }} <a href="mailto:support@krotze.com" class="text-violet-400 hover:underline break-all">support@krotze.com</a><br>
                {{ __('site.imprint.website_label') }} <a href="https://krotze.com" class="text-violet-400 hover:underline">krotze.com</a>
            </p>
            <p class="text-sm">
                {{ __('site.imprint.responsible') }}
            </p>
        </div>
    </article>
@endsection
