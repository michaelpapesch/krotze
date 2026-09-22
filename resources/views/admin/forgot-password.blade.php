@extends('layouts.site')

@section('title', 'Reset admin password — Krotze')

@section('content')
    <div class="mx-auto max-w-sm px-4 py-16">
        <h1 class="text-center text-2xl font-bold text-white">Reset your password</h1>
        <p class="mt-2 text-center text-sm text-zinc-400 break-words">
            Enter the address you sign in with and we will send a link to choose a new password.
        </p>

        @if (session('status'))
            <p class="mt-6 rounded-lg border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-sm text-emerald-300 break-words">
                {{ session('status') }}
            </p>
        @endif

        <form method="POST" action="{{ route('admin.password.email') }}" class="mt-8 space-y-4">
            @csrf
            <div>
                <label for="email" class="block text-sm text-zinc-400">E-mail</label>
                <input id="email" name="email" type="email" required autofocus value="{{ old('email') }}"
                       class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
            </div>
            @error('email')
                <p class="text-sm text-red-400 break-words">{{ $message }}</p>
            @enderror
            <button class="w-full rounded-lg bg-violet-600 py-2.5 font-semibold text-white hover:bg-violet-500">
                Send reset link
            </button>
        </form>

        <p class="mt-6 text-center text-sm">
            <a href="{{ route('admin.login') }}" class="text-zinc-400 hover:text-white">← Back to sign in</a>
        </p>

        <p class="mt-6 text-center text-xs text-zinc-500 break-words">
            With two-factor authentication on, resetting the password is not enough on its own —
            you will still be asked for a code.
        </p>
    </div>
@endsection
