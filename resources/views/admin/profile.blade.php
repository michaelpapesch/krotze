@extends('layouts.site')

@section('title', 'Admin profile — Krotze')

@section('content')
    @php($forced = $admin->mustChangePassword())

    <div class="mx-auto max-w-2xl px-4 py-10">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-bold text-white">Admin profile</h1>
            @unless ($forced)
                <a href="{{ route('admin.dashboard') }}"
                   class="rounded-lg border border-white/20 px-4 py-1.5 text-sm hover:bg-white/10">← Dashboard</a>
            @endunless
        </div>

        @if (session('status'))
            <p class="mt-4 rounded-lg border border-emerald-500/30 bg-emerald-500/15 px-4 py-2 text-emerald-300">
                {{ session('status') }}
            </p>
        @endif

        @if ($forced)
            <p class="mt-4 rounded-lg border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm text-amber-200 break-words">
                <b>Choose a password before you go on.</b> This account is still using the one it was
                seeded with, which is written down in the repository and the deployment notes — anybody
                who has read either can sign in as you. The rest of the panel stays locked until you
                change it.
            </p>
        @endif

        {{-- ------------------------------ password ------------------------------ --}}
        <section class="mt-8 rounded-xl border border-white/10 bg-white/5 p-5">
            <h2 class="text-lg font-semibold text-white">Password</h2>
            @unless ($forced)
                <p class="mt-1 text-sm text-zinc-400">
                    Last changed {{ $admin->password_changed_at->diffForHumans() }}.
                    Changing it signs out every other browser you are signed in on.
                </p>
            @endunless

            <form method="POST" action="{{ route('admin.password') }}" class="mt-4 space-y-3">
                @csrf
                @unless ($forced)
                    <div>
                        <label for="pw-current" class="block text-sm text-zinc-400">Current password</label>
                        <input id="pw-current" name="current_password" type="password" required autocomplete="current-password"
                               class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
                    </div>
                @endunless
                <div>
                    <label for="pw-new" class="block text-sm text-zinc-400">New password</label>
                    <input id="pw-new" name="password" type="password" required autocomplete="new-password" autofocus
                           class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
                    <p class="mt-1 text-xs text-zinc-500">
                        At least 12 characters, with letters, numbers and symbols.
                    </p>
                </div>
                <div>
                    <label for="pw-confirm" class="block text-sm text-zinc-400">Repeat new password</label>
                    <input id="pw-confirm" name="password_confirmation" type="password" required autocomplete="new-password"
                           class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
                </div>
                @error('current_password')<p class="text-sm text-red-400 break-words">{{ $message }}</p>@enderror
                @error('password')<p class="text-sm text-red-400 break-words">{{ $message }}</p>@enderror
                <button class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">
                    {{ $forced ? 'Set my password' : 'Change password' }}
                </button>
            </form>
        </section>

        @unless ($forced)
            {{-- ------------------------------ e-mail ------------------------------ --}}
            <section class="mt-6 rounded-xl border border-white/10 bg-white/5 p-5">
                <h2 class="text-lg font-semibold text-white">E-mail address</h2>
                <p class="mt-1 text-sm text-zinc-400">What you sign in with. Nothing is ever sent to it.</p>

                <form method="POST" action="{{ route('admin.email') }}" class="mt-4 space-y-3">
                    @csrf
                    <div>
                        <label for="email" class="block text-sm text-zinc-400">E-mail</label>
                        <input id="email" name="email" type="email" required value="{{ old('email', $admin->email) }}"
                               class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
                    </div>
                    <div>
                        <label for="em-current" class="block text-sm text-zinc-400">Confirm with your password</label>
                        <input id="em-current" name="current_password" type="password" required autocomplete="current-password"
                               class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
                    </div>
                    @error('email')<p class="text-sm text-red-400 break-words">{{ $message }}</p>@enderror
                    <button class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">
                        Update e-mail
                    </button>
                </form>
            </section>

            {{-- --------------------------- two-factor ----------------------------- --}}
            <section class="mt-6 rounded-xl border border-white/10 bg-white/5 p-5">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 class="text-lg font-semibold text-white">Two-factor authentication</h2>
                    @if ($admin->twoFactorEnabled())
                        <span class="rounded-full bg-emerald-500/20 px-2.5 py-0.5 text-xs font-semibold text-emerald-300">On</span>
                    @else
                        <span class="rounded-full bg-white/10 px-2.5 py-0.5 text-xs font-semibold text-zinc-400">Off</span>
                    @endif
                </div>

                @if ($recoveryCodes)
                    <div class="mt-4 rounded-lg border border-amber-400/30 bg-amber-400/10 p-4">
                        <p class="text-sm font-semibold text-amber-200">Save these recovery codes now.</p>
                        <p class="mt-1 text-xs text-amber-200/80 break-words">
                            Each works once, in place of a code from your authenticator. They are shown this
                            once and cannot be retrieved again — without them, losing your authenticator means
                            losing access to this panel.
                        </p>
                        <ul class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-sm text-amber-100">
                            @foreach ($recoveryCodes as $code)
                                <li>{{ $code }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($enrolment)
                    {{-- Mid-enrolment: secret stored, not yet confirmed, login unaffected. --}}
                    <p class="mt-3 text-sm text-zinc-400 break-words">
                        Scan this with an authenticator app (Aegis, 2FAS, Google Authenticator, 1Password —
                        any of them), then type the six-digit code it shows to finish.
                    </p>
                    <div class="mt-4 flex flex-wrap items-start gap-6">
                        <canvas id="totp-qr" class="rounded-lg bg-white p-2"
                                data-uri="{{ \App\Support\Totp::uri($enrolment, $admin->email, config('app.name')) }}"></canvas>
                        <div class="min-w-0">
                            <p class="text-xs uppercase tracking-wide text-zinc-500">Or enter this key by hand</p>
                            <code class="mt-1 block break-all rounded-lg border border-white/15 bg-black/30 px-3 py-2 font-mono text-sm text-violet-300">{{ \App\Support\Totp::formatSecret($enrolment) }}</code>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.2fa.confirm') }}" class="mt-4 flex flex-wrap items-end gap-3">
                        @csrf
                        <div>
                            <label for="code" class="block text-sm text-zinc-400">Six-digit code</label>
                            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
                                   maxlength="7" required autofocus
                                   class="mt-1 w-40 rounded-lg border border-white/15 bg-white/5 px-3 py-2 font-mono text-lg tracking-widest text-white focus:border-violet-500 focus:outline-none">
                        </div>
                        <button class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">
                            Turn on
                        </button>
                    </form>
                    @error('code')<p class="mt-2 text-sm text-red-400 break-words">{{ $message }}</p>@enderror

                @elseif ($admin->twoFactorEnabled())
                    <p class="mt-1 text-sm text-zinc-400 break-words">
                        Signing in asks for a code from your authenticator.
                        {{ count($admin->two_factor_recovery_codes ?? []) }} recovery
                        code{{ count($admin->two_factor_recovery_codes ?? []) === 1 ? '' : 's' }} left.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <form method="POST" action="{{ route('admin.2fa.recovery') }}"
                              onsubmit="return confirm('Generate new recovery codes? The current ones stop working.')">
                            @csrf
                            <button class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">
                                New recovery codes
                            </button>
                        </form>
                    </div>

                    <form method="POST" action="{{ route('admin.2fa.disable') }}" class="mt-5 border-t border-white/10 pt-4"
                          onsubmit="return confirm('Turn off two-factor authentication?')">
                        @csrf
                        @method('DELETE')
                        <label for="tf-current" class="block text-sm text-zinc-400">Confirm with your password to turn it off</label>
                        <div class="mt-1 flex flex-wrap gap-3">
                            <input id="tf-current" name="current_password" type="password" required autocomplete="current-password"
                                   class="min-w-0 flex-1 rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
                            <button class="rounded-lg border border-red-500/40 px-4 py-2 text-sm text-red-400 hover:bg-red-500/10">
                                Turn off
                            </button>
                        </div>
                        @error('current_password')<p class="mt-2 text-sm text-red-400 break-words">{{ $message }}</p>@enderror
                    </form>

                @else
                    <p class="mt-1 text-sm text-zinc-400 break-words">
                        A second step at sign-in, from an app on your phone. This panel can delete every
                        channel on the server, and a password is the only thing standing in front of it.
                    </p>
                    <form method="POST" action="{{ route('admin.2fa.start') }}" class="mt-4">
                        @csrf
                        <button class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">
                            Set up two-factor authentication
                        </button>
                    </form>
                @endif
            </section>
        @endunless
    </div>
@endsection
