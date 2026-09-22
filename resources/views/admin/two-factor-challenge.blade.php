@extends('layouts.site')

@section('title', 'Two-factor authentication — Krotze')

@section('content')
    <div class="mx-auto max-w-sm px-4 py-16">
        <h1 class="text-center text-2xl font-bold text-white">Two-factor authentication</h1>
        <p class="mt-2 text-center text-sm text-zinc-400 break-words">
            Enter the six-digit code from your authenticator app, or one of your recovery codes.
        </p>

        <form method="POST" action="{{ route('admin.2fa.challenge') }}" class="mt-8 space-y-4">
            @csrf
            <div>
                <label for="code" class="block text-sm text-zinc-400">Code</label>
                <input id="code" name="code" required autofocus autocomplete="one-time-code" inputmode="numeric"
                       placeholder="000000"
                       class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-center font-mono text-lg tracking-widest text-white focus:border-violet-500 focus:outline-none">
            </div>
            @error('code')
                <p class="text-sm text-red-400 break-words">{{ $message }}</p>
            @enderror
            <button class="w-full rounded-lg bg-violet-600 py-2.5 font-semibold text-white hover:bg-violet-500">
                Sign in
            </button>
        </form>

        <p class="mt-6 text-center text-xs text-zinc-500 break-words">
            A recovery code works once. If you have lost both your authenticator and your recovery codes,
            the only way back in is clearing <code>two_factor_secret</code> for your row in the
            <code>users</code> table.
        </p>
    </div>
@endsection
