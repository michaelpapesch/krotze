@extends('layouts.site')

@section('title', 'Admin dashboard — Krotze')

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-10">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-bold text-white">Admin dashboard</h1>
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('admin.profile') }}"
                   class="rounded-lg border border-white/20 px-4 py-1.5 text-sm hover:bg-white/10">👤 Profile</a>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button class="rounded-lg border border-white/20 px-4 py-1.5 text-sm hover:bg-white/10">Log out</button>
                </form>
            </div>
        </div>

        @unless (auth()->user()->twoFactorEnabled())
            {{-- Not forced, but this panel can destroy every channel on the server. --}}
            <p class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm text-amber-200 break-words">
                <span class="min-w-0 flex-1">
                    Two-factor authentication is off. A password is the only thing in front of an account
                    that can delete every channel on this server.
                </span>
                <a href="{{ route('admin.profile') }}"
                   class="shrink-0 rounded-lg bg-amber-400/20 px-3 py-1.5 font-semibold hover:bg-amber-400/30">Set it up</a>
            </p>
        @endunless

        @if (session('status'))
            <p class="mt-4 rounded-lg bg-emerald-500/15 border border-emerald-500/30 px-4 py-2 text-emerald-300">
                {{ session('status') }}
            </p>
        @endif

        @if ($errors->any())
            <p class="mt-4 rounded-lg bg-red-500/15 border border-red-500/30 px-4 py-2 text-red-300">
                {{ $errors->first() }}
            </p>
        @endif

        <div class="mt-8 grid gap-4 grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ([
                ['Users', $stats['users']],
                ['Channels', $stats['channels']],
                ['Private chats', $stats['privateChats']],
                ['Messages', $stats['messages']],
                ['Uploads', number_format($stats['uploadBytes'] / 1048576, 1).' MB'],
            ] as [$label, $value])
                <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                    <p class="text-xs uppercase tracking-wide text-zinc-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold text-white break-words">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        {{-- Retention has two drivers; this says which one is doing the work. --}}
        <div class="mt-4 rounded-xl border p-4 text-sm break-words
                    {{ $schedulerAlive ? 'border-emerald-500/25 bg-emerald-500/10' : 'border-amber-400/30 bg-amber-400/10' }}">
            @if ($schedulerAlive)
                <p class="font-semibold text-emerald-300">⏱ Scheduler is running</p>
                <p class="mt-0.5 text-zinc-300">
                    Last tick {{ $schedulerLastRun->diffForHumans() }}. Channel retention, upload expiry
                    and abandoned-identity cleanup run on schedule, whether or not anybody is using the app.
                </p>
            @else
                <p class="font-semibold text-amber-200">⏱ Scheduler is not running</p>
                <p class="mt-0.5 text-zinc-300">
                    @if ($schedulerLastRun)
                        Last seen {{ $schedulerLastRun->diffForHumans() }}.
                    @else
                        It has never reported in.
                    @endif
                    Retention still happens — a lazy sweep runs at most once an hour, paid for by whoever
                    polls the API first — but on a quiet server nothing is cleaned up until somebody
                    shows up. To drive it properly, add this to cron:
                </p>
                <code class="mt-2 block break-all rounded-lg border border-white/15 bg-black/30 px-3 py-2 text-xs text-violet-300">* * * * * cd {{ base_path() }} &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code>
            @endif
        </div>

        <h2 class="mt-10 text-lg font-semibold text-white">Registrations</h2>
        <div class="mt-4 rounded-xl border border-white/10 bg-white/5 p-5">
            <form method="POST" action="{{ route('admin.settings') }}" id="reg-settings">
                @csrf
                <label class="flex cursor-pointer items-start gap-3 text-sm">
                    <input type="checkbox" name="open_registrations" id="open-registrations" value="1"
                           class="mt-0.5 accent-violet-500" @checked($openRegistrations)>
                    <span>
                        <span class="font-semibold text-white">Open registrations</span>
                        <span class="mt-0.5 block text-zinc-400">
                            Every anonymous visitor of the landing page can register and start chatting right away.
                        </span>
                    </span>
                </label>

                <div id="closed-options" class="mt-5 space-y-5 border-l-2 border-white/10 pl-4 {{ $openRegistrations ? 'hidden' : '' }}">
                    <label class="flex cursor-pointer items-start gap-3 text-sm">
                        <input type="checkbox" name="manual_approval" value="1"
                               class="mt-0.5 accent-violet-500" @checked($manualApproval)>
                        <span>
                            <span class="font-semibold text-white">Manually approve every registration</span>
                            <span class="mt-0.5 block text-zinc-400">
                                Everybody who registers through the invite link waits here until you approve them.
                            </span>
                        </span>
                    </label>

                </div>

                <label class="mt-6 flex cursor-pointer items-start gap-3 border-t border-white/10 pt-5 text-sm">
                    <input type="checkbox" name="upload_autodelete" id="upload-autodelete" value="1"
                           class="mt-0.5 accent-violet-500" @checked($uploadAutodelete)>
                    <span>
                        <span class="font-semibold text-white">Automatically delete uploads after</span>
                        <input type="number" name="upload_retention_days" min="1" max="365" value="{{ $uploadRetentionDays }}"
                               id="upload-retention-days"
                               class="mx-1 w-20 rounded-lg border border-white/15 bg-white/5 px-2 py-1 text-white focus:border-violet-500 focus:outline-none"
                               onclick="event.stopPropagation()">
                        <span class="font-semibold text-white">days</span>
                        <span class="mt-0.5 block text-zinc-400">
                            Files, previews and their messages are removed everywhere once they reach this age.
                            Unticked, uploads only disappear with their channel.
                        </span>
                    </span>
                </label>

                <label class="mt-4 flex cursor-pointer items-start gap-3 text-sm">
                    <input type="checkbox" name="upload_view_limit" id="upload-view-limit" value="1"
                           class="mt-0.5 accent-violet-500" @checked($uploadViewLimit)>
                    <span>
                        <span class="font-semibold text-white">Clients can view uploads only for</span>
                        <input type="number" name="upload_view_minutes" min="1" max="60" value="{{ $uploadViewMinutes }}"
                               id="upload-view-minutes"
                               class="mx-1 w-20 rounded-lg border border-white/15 bg-white/5 px-2 py-1 text-white focus:border-violet-500 focus:outline-none"
                               onclick="event.stopPropagation()">
                        <span class="font-semibold text-white">minutes</span>
                        <span class="mt-0.5 block text-zinc-400">
                            The countdown starts the first time a member opens the file; afterwards their
                            personal link stops resolving. Unticked, their link lives as long as the upload does.
                        </span>
                    </span>
                </label>

                <label class="mt-6 flex cursor-pointer items-start gap-3 border-t border-white/10 pt-5 text-sm {{ $pushConfigured ? '' : 'opacity-60' }}">
                    <input type="checkbox" name="push_enabled" value="1"
                           class="mt-0.5 accent-violet-500"
                           @checked($pushEnabled) @disabled(! $pushConfigured)>
                    <span>
                        <span class="font-semibold text-white">Send push notifications</span>
                        <span class="mt-0.5 block text-zinc-400">
                            @if ($pushConfigured)
                                Reaches members whose app is closed. Payloads are encrypted for the
                                recipient's device, so the push service relays text it cannot read.
                                Each device chooses what it wants to hear about.
                            @else
                                No VAPID keypair is configured, so push is unavailable. Run
                                <code class="text-violet-300">php artisan push:vapid</code> and put the
                                two keys in this server's <code class="text-violet-300">.env</code>.
                            @endif
                        </span>
                    </span>
                </label>

                <div class="mt-6 flex flex-wrap gap-3">
                    <button class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">
                        Save settings
                    </button>
                </div>
            </form>

            {{-- Outside the settings form, and always visible: the link is worth
                 having in hand before registrations are closed, not only after. --}}
            <div id="invite-block" class="mt-6 rounded-lg border border-white/10 bg-black/20 p-4">
                <p class="text-sm font-semibold text-white">Registration invite link</p>
                <p class="mt-0.5 text-sm text-zinc-400 break-words">
                    @if ($openRegistrations)
                        Registrations are open, so nobody needs this yet — anybody can join straight from
                        the landing page. It becomes the only way in the moment you close them.
                    @else
                        This is the only way onto the server. Share it with the people you want to let in.
                    @endif
                </p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <code id="invite-url"
                          class="min-w-0 flex-1 break-all rounded-lg border border-white/15 bg-black/30 px-3 py-2 text-xs text-violet-300">{{ $inviteUrl }}</code>
                    <button type="button" data-copy="#invite-url"
                            class="shrink-0 rounded-lg border border-white/20 px-3 py-2 text-xs hover:bg-white/10">📋 Copy</button>
                </div>

                {{-- Its own form: HTML cannot nest one inside the settings form. --}}
                <form method="POST" action="{{ route('admin.invite.rotate') }}" class="mt-3"
                      onsubmit="return confirm('Recreate the invite link? Everybody holding the current link loses access immediately.')">
                    @csrf
                    <button class="rounded-lg border border-amber-400/40 px-4 py-2 text-sm text-amber-300 hover:bg-amber-400/10">
                        ♻ Recreate invite link
                    </button>
                    <span class="ml-2 text-xs text-zinc-500">Invalidates every link handed out so far.</span>
                </form>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-base font-semibold text-white">
                Pending registrations
                <span class="ml-1 rounded-full bg-amber-500 px-2 py-0.5 text-xs font-bold text-black">{{ $pendingTotal }}</span>
                @if ($deniedTotal)
                    <span class="ml-2 text-xs font-normal text-zinc-500">{{ $deniedTotal }} denied</span>
                @endif
            </h3>
            <form method="GET" action="{{ route('admin.dashboard') }}" class="flex items-center gap-2">
                <input type="search" name="q" value="{{ $search }}" placeholder="Search username…"
                       class="w-48 rounded-lg border border-white/15 bg-white/5 px-3 py-1.5 text-sm text-white focus:border-violet-500 focus:outline-none">
                <button class="rounded-lg border border-white/20 px-3 py-1.5 text-sm hover:bg-white/10">Search</button>
                @if ($search !== '')
                    <a href="{{ route('admin.dashboard') }}" class="text-sm text-zinc-400 hover:text-white">Clear</a>
                @endif
            </form>
        </div>

        <form method="POST" action="{{ route('admin.registrations.decide') }}" class="mt-3">
            @csrf
            <div class="overflow-x-auto rounded-xl border border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-white/5 text-left text-zinc-400">
                        <tr>
                            <th class="px-4 py-2 w-10">
                                <input type="checkbox" id="check-all" class="accent-violet-500" title="Check all">
                            </th>
                            <th class="px-4 py-2">Username</th>
                            <th class="px-4 py-2">Devices</th>
                            <th class="px-4 py-2">Registered</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @forelse ($pending as $u)
                            <tr>
                                <td class="px-4 py-2">
                                    <input type="checkbox" name="ids[]" value="{{ $u->id }}" class="reg-check accent-violet-500">
                                </td>
                                <td class="px-4 py-2 text-white break-words max-w-[16rem]">
                                    {{ $u->username ?? '—' }}
                                    @unless ($u->username)
                                        <span class="ml-1 text-xs text-zinc-500">(no name chosen yet)</span>
                                    @endunless
                                </td>
                                <td class="px-4 py-2">{{ $u->devices_count }}</td>
                                <td class="px-4 py-2 whitespace-nowrap">{{ $u->created_at?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-zinc-500">
                                    {{ $search !== '' ? 'No pending registration matches that search.' : 'No registrations are waiting.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($pending->total())
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-zinc-500">
                        Showing {{ $pending->firstItem() }}–{{ $pending->lastItem() }} of {{ $pending->total() }}
                    </p>
                    <div class="flex gap-2">
                        @if ($pending->onFirstPage())
                            <span class="rounded-lg border border-white/10 px-3 py-1.5 text-sm text-zinc-600">← Previous</span>
                        @else
                            <a href="{{ $pending->previousPageUrl() }}"
                               class="rounded-lg border border-white/20 px-3 py-1.5 text-sm hover:bg-white/10">← Previous</a>
                        @endif
                        <span class="px-1 py-1.5 text-sm text-zinc-500">{{ $pending->currentPage() }} / {{ $pending->lastPage() }}</span>
                        @if ($pending->hasMorePages())
                            <a href="{{ $pending->nextPageUrl() }}"
                               class="rounded-lg border border-white/20 px-3 py-1.5 text-sm hover:bg-white/10">Next →</a>
                        @else
                            <span class="rounded-lg border border-white/10 px-3 py-1.5 text-sm text-zinc-600">Next →</span>
                        @endif
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap gap-3">
                    <button name="action" value="approve"
                            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                        Approve selected
                    </button>
                    <button name="action" value="deny"
                            class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500">
                        Deny selected
                    </button>
                </div>
            @endif
        </form>

        <h2 class="mt-10 text-lg font-semibold text-white">Channels</h2>
        <div class="mt-4 overflow-x-auto rounded-xl border border-white/10">
            <table class="w-full text-sm">
                <thead class="bg-white/5 text-left text-zinc-400">
                    <tr>
                        <th class="px-4 py-2">Name</th>
                        <th class="px-4 py-2">Type</th>
                        <th class="px-4 py-2">Members</th>
                        <th class="px-4 py-2">Messages</th>
                        <th class="px-4 py-2">Retention</th>
                        <th class="px-4 py-2">Last activity</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @forelse ($channels as $ch)
                        <tr>
                            <td class="px-4 py-2 text-white break-words max-w-[16rem]">{{ $ch->name ?? '(private)' }}</td>
                            <td class="px-4 py-2">{{ $ch->type }}</td>
                            <td class="px-4 py-2">{{ $ch->members_count }}</td>
                            <td class="px-4 py-2">{{ $ch->messages_count }}</td>
                            <td class="px-4 py-2">{{ $ch->retention_days }}d</td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ $ch->last_activity_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">
                                <form method="POST" action="{{ route('admin.channels.destroy', $ch) }}"
                                      onsubmit="return confirm('Delete this channel and ALL of its data?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="rounded-md bg-red-600/80 px-3 py-1 text-xs font-semibold text-white hover:bg-red-500">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-zinc-500">No channels yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // "Manually approve" and the invite link only exist while registrations
        // are closed — mirror that in the form instead of explaining it.
        const openBox = document.getElementById('open-registrations');
        const closedOptions = document.getElementById('closed-options');
        openBox.addEventListener('change', () => closedOptions.classList.toggle('hidden', openBox.checked));

        // A number is only meaningful while its switch is on.
        for (const [boxId, fieldId] of [
            ['upload-autodelete', 'upload-retention-days'],
            ['upload-view-limit', 'upload-view-minutes'],
        ]) {
            const box = document.getElementById(boxId);
            const field = document.getElementById(fieldId);
            const sync = () => { field.disabled = !box.checked; };
            box.addEventListener('change', sync);
            sync();
        }

        // Any button carrying data-copy="<selector>" copies that element's text.
        // Delegated, so links rendered later get the behaviour for free.
        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-copy]');
            if (! button) return;

            const source = document.querySelector(button.dataset.copy);
            if (! source) return;

            const original = button.textContent;
            try {
                await navigator.clipboard.writeText(source.textContent.trim());
                button.textContent = '✓ Copied';
            } catch {
                // The clipboard API needs a secure context, and an admin panel
                // reached over plain http is a real case — select the text so
                // Ctrl/Cmd+C still works.
                const range = document.createRange();
                range.selectNodeContents(source);
                getSelection().removeAllRanges();
                getSelection().addRange(range);
                button.textContent = 'Press Ctrl+C';
            }
            setTimeout(() => { button.textContent = original; }, 2000);
        });

        const checkAll = document.getElementById('check-all');
        const boxes = () => document.querySelectorAll('.reg-check');
        checkAll?.addEventListener('change', () => boxes().forEach((b) => { b.checked = checkAll.checked; }));
        boxes().forEach((b) => b.addEventListener('change', () => {
            const all = [...boxes()];
            checkAll.checked = all.every((x) => x.checked);
            checkAll.indeterminate = !checkAll.checked && all.some((x) => x.checked);
        }));
    </script>
@endsection
