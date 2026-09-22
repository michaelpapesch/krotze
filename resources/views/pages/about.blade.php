@extends('layouts.site')

@section('title', __('site.about.title'))
@section('description', __('site.about.description'))

@push('jsonld')
    @include('partials.breadcrumb-jsonld', ['pageTitle' => __('site.about.h1')])
@endpush

@section('content')
    <article class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold text-white">{{ __('site.about.h1') }}</h1>

        <div class="mt-6 space-y-8 text-zinc-400 break-words">
            <p class="text-lg">
                {{ __('site.about.intro') }}
            </p>

            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.about.rights_title') }}</h2>
                <p class="mt-2">{{ __('site.about.rights_intro') }}</p>
                <ul class="mt-4 space-y-4">
                    @foreach (['right1', 'right2', 'right3'] as $right)
                        <li class="rounded-xl border border-white/10 bg-white/5 p-5">
                            <h3 class="font-semibold text-white">{{ __('site.about.'.$right.'_title') }}</h3>
                            <p class="mt-2">{{ __('site.about.'.$right.'_text') }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.about.democracy_title') }}</h2>
                <div class="mt-4 space-y-4">
                    <div>
                        <h3 class="font-semibold text-zinc-200">{{ __('site.about.surveillance_title') }}</h3>
                        <p class="mt-2">{{ __('site.about.surveillance_text') }}</p>
                    </div>
                    <div>
                        <h3 class="font-semibold text-zinc-200">{{ __('site.about.resistance_title') }}</h3>
                        <p class="mt-2">{{ __('site.about.resistance_text') }}</p>
                    </div>
                </div>
            </section>

            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.about.tension_title') }}</h2>
                <p class="mt-2">{{ __('site.about.tension_intro') }}</p>
                <div class="mt-4 space-y-4">
                    <div>
                        <h3 class="font-semibold text-zinc-200">{{ __('site.about.tension1_title') }}</h3>
                        <p class="mt-2">{{ __('site.about.tension1_text') }}</p>
                    </div>
                    <div>
                        <h3 class="font-semibold text-zinc-200">{{ __('site.about.tension2_title') }}</h3>
                        <p class="mt-2">{{ __('site.about.tension2_text') }}</p>
                    </div>
                </div>
            </section>

            <section>
                <h2 class="text-xl font-semibold text-white">{{ __('site.about.servers_title') }}</h2>
                <p class="mt-2">{{ __('site.about.servers_text1') }}</p>
                <p class="mt-2">{{ __('site.about.servers_text2') }}</p>
            </section>

            <section class="rounded-2xl border border-violet-500/30 bg-violet-500/5 p-6">
                <h2 class="text-xl font-semibold text-white">{{ __('site.about.krotze_title') }}</h2>
                <p class="mt-3">{{ __('site.about.krotze_text1') }}</p>
                <p class="mt-3">{{ __('site.about.krotze_text2') }}</p>
                <div class="mt-5 flex flex-wrap gap-3">
                    <a href="/app" class="rounded-full bg-violet-600 px-5 py-2 text-sm font-semibold text-white hover:bg-violet-500">{{ __('site.nav.open_app') }}</a>
                    <a href="{{ app()->getLocale() === 'en' ? '' : '/'.app()->getLocale() }}/terms" class="rounded-full border border-white/20 px-5 py-2 text-sm text-zinc-200 hover:bg-white/10">{{ __('site.footer.terms') }}</a>
                </div>
            </section>
        </div>
    </article>
@endsection
