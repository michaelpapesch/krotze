@extends('layouts.site')

@section('title', 'Choose a new password — Krotze')

@section('content')
    <div class="mx-auto max-w-sm px-4 py-16">
        <h1 class="text-center text-2xl font-bold text-white">Choose a new password</h1>

        <form method="POST" action="{{ route('admin.password.update') }}" class="mt-8 space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div>
                <label for="email" class="block text-sm text-zinc-400">E-mail</label>
                <input id="email" name="email" type="email" required value="{{ old('email', $email) }}"
                       class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
            </div>
            <div>
                <label for="password" class="block text-sm text-zinc-400">New password</label>
                <input id="password" name="password" type="password" required autofocus autocomplete="new-password"
                       class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
                <p class="mt-1 text-xs text-zinc-500">At least 12 characters, with letters, numbers and symbols.</p>
            </div>
            <div>
                <label for="password_confirmation" class="block text-sm text-zinc-400">Repeat new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                       class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
            </div>
            @error('email')<p class="text-sm text-red-400 break-words">{{ $message }}</p>@enderror
            @error('password')<p class="text-sm text-red-400 break-words">{{ $message }}</p>@enderror
            <button class="w-full rounded-lg bg-violet-600 py-2.5 font-semibold text-white hover:bg-violet-500">
                Set new password
            </button>
        </form>

        <p class="mt-6 text-center text-xs text-zinc-500 break-words">
            Setting a new password signs out every browser that was signed in with the old one.
        </p>
    </div>
@endsection
