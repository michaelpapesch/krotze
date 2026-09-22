@extends('layouts.site')

@section('title', 'Admin login — Krotze')

@section('content')
    <div class="mx-auto max-w-sm px-4 py-16">
        <h1 class="text-2xl font-bold text-white text-center">Admin login</h1>

        @if (session('status'))
            <p class="mt-6 rounded-lg border border-emerald-500/30 bg-emerald-500/15 px-4 py-3 text-sm text-emerald-300 break-words">
                {{ session('status') }}
            </p>
        @endif

        <form method="POST" action="/admin/login" class="mt-8 space-y-4">
            @csrf
            <div>
                <label for="email" class="block text-sm text-zinc-400">E-mail</label>
                <input id="email" name="email" type="email" required autofocus value="{{ old('email') }}"
                       class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
            </div>
            <div>
                <label for="password" class="block text-sm text-zinc-400">Password</label>
                <input id="password" name="password" type="password" required
                       class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
            </div>
            @error('email')
                <p class="text-sm text-red-400 break-words">{{ $message }}</p>
            @enderror
            <button class="w-full rounded-lg bg-violet-600 py-2.5 font-semibold text-white hover:bg-violet-500">
                Sign in
            </button>
        </form>

        <p class="mt-6 text-center text-sm">
            <a href="{{ route('admin.password.request') }}" class="text-zinc-400 hover:text-white">Forgot password?</a>
        </p>
    </div>
@endsection
