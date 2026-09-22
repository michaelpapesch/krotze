import { sha256, hmacSha256, hex, randomBytes } from './crypto.js';
import { dir, getLang, t } from './i18n.js';

/* ------------------------------------------------------------------ */
/* Krotze — embeddable chat widget (iframe on third-party sites).      */
/* Text-only client for open-join channels: a visitor registers an     */
/* identity, picks a username, joins instantly and chats. Uploads from */
/* the main app show as notes. Requests are signed like app.js does.   */
/* ------------------------------------------------------------------ */

const $root = document.getElementById('embed');
const EMBED = window.KROTZE_EMBED || {};

// Same storage key and shape as the main app: on krotze.com the identity is
// shared; inside a third-party iframe the browser partitions storage anyway.
const IDENTITY_KEY = 'krotze_identity';

const state = {
    identity: null,         // { id, secret }
    clockSkew: 0,
    userId: null,
    username: null,
    uuid: null,             // channel uuid, known after joining
    channelName: EMBED.name,
    messages: [],
    lastMessageId: 0,
};

function safeGet(key) {
    try { return localStorage.getItem(key); } catch { return null; }
}
function safeSet(key, val) {
    try { localStorage.setItem(key, val); } catch { /* storage blocked */ }
}
function safeRemove(key) {
    try { localStorage.removeItem(key); } catch { /* storage blocked */ }
}

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
}

function fmtTime(iso) {
    const d = new Date(iso);
    const today = new Date().toDateString() === d.toDateString();
    return today
        ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        : d.toLocaleDateString([], { day: '2-digit', month: '2-digit' }) + ' ' +
          d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

/* ------------------------- signed API client ----------------------- */

async function api(path, opts = {}) {
    const url = '/api' + path;
    const method = (opts.method || (opts.body ? 'POST' : 'GET')).toUpperCase();
    const body = opts.body ? JSON.stringify(opts.body) : undefined;

    const headers = {};
    if (body) headers['Content-Type'] = 'application/json';

    if (state.identity && !opts.anonymous) {
        const ts = String(Math.floor(Date.now() / 1000) + state.clockSkew);
        const nonce = hex(randomBytes(16));
        const canonical = [method, url, ts, nonce, hex(sha256(body ?? ''))].join('\n');
        headers['X-Chat-Device'] = state.identity.id;
        headers['X-Chat-Ts'] = ts;
        headers['X-Chat-Nonce'] = nonce;
        headers['X-Chat-Sig'] = hex(hmacSha256(state.identity.secret, canonical));
    }

    const res = await fetch(url, { method, headers, body });

    if (res.status === 401) {
        const info = await res.json().catch(() => ({}));
        // Wrong device clock: learn the offset from the server and retry once.
        if (info.reason === 'stale' && !opts._retried) {
            const serverTime = Date.parse(res.headers.get('Date') || '');
            if (serverTime) {
                state.clockSkew = Math.round(serverTime / 1000) - Math.floor(Date.now() / 1000);
                return api(path, { ...opts, _retried: true });
            }
        }
        // This device was revoked: drop the identity and start over.
        if (info.reason === 'device_unknown') {
            safeRemove(IDENTITY_KEY);
            state.identity = null;
            stopPolling();
            boot();
            return new Promise(() => {}); // boot has taken over
        }
        throw Object.assign(new Error('Not authorised.'), { data: info, status: 401 });
    }

    if (res.status === 403) {
        const info = await res.json().catch(() => ({}));
        if (info.reason === 'registration_pending' || info.reason === 'registration_denied') {
            stopPolling();
            renderUnavailable(info.reason === 'registration_pending'
                ? t('This chat server reviews every new identity — yours is still waiting for approval.')
                : t('This chat server has declined your identity.'));
            return new Promise(() => {}); // the gate screen has taken over
        }
        throw Object.assign(new Error(info.error || 'Not allowed.'), { data: info, status: 403 });
    }

    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.error || data.message || 'Request failed'), { data, status: res.status });
    return data;
}

function flash(text) {
    const t = document.createElement('div');
    t.className = 'fixed bottom-14 left-1/2 -translate-x-1/2 z-50 rounded-lg bg-red-600 px-3 py-1.5 text-xs text-white shadow-lg max-w-[90vw] break-words';
    t.textContent = text;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

/* ----------------------------- screens ----------------------------- */

function footerHtml() {
    return `
        <div class="border-t border-white/10 px-3 py-1.5 flex items-center justify-between text-[11px] text-zinc-500">
            <span class="truncate">${t('anonymous · self-destructing')}</span>
            <a href="https://krotze.com" target="_blank" rel="noopener" class="shrink-0 hover:text-zinc-300">${t('powered by')} <b>Krotze</b></a>
        </div>`;
}

function renderCentered(html) {
    $root.innerHTML = `
        <div class="flex-1 flex flex-col items-center justify-center gap-3 p-6 text-center">${html}</div>
        ${footerHtml()}`;
}

function renderUnavailable(text) {
    renderCentered(`
        <p class="text-2xl">💤</p>
        <p class="text-sm text-zinc-400 max-w-xs break-words">${esc(text)}</p>`);
}

/**
 * Pick-a-name gate. Usernames are global and unique on Krotze, so the visitor
 * may have to try again — the server's validation message is shown inline.
 */
function renderNameGate() {
    const suggested = 'Guest-' + hex(randomBytes(3));
    renderCentered(`
        <p class="font-semibold text-white break-words max-w-full"># ${esc(state.channelName)}</p>
        <p class="text-xs text-zinc-500 max-w-60">${t('Pick a username to join the chat — no account needed. Usernames are unique across Krotze.')}</p>
        <form id="gate-form" class="w-full max-w-56 space-y-2">
            <input name="username" required minlength="2" maxlength="40" value="${esc(suggested)}"
                class="w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm text-white text-center focus:border-violet-500 focus:outline-none">
            <p id="gate-error" class="hidden text-xs text-red-400 break-words"></p>
            <button class="w-full rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-500">${t('Join chat')}</button>
        </form>`);
    document.getElementById('gate-form').onsubmit = async (e) => {
        e.preventDefault();
        const err = document.getElementById('gate-error');
        const username = new FormData(e.target).get('username').trim();
        if (!username) return;
        try {
            const res = await api('/profile/username', { body: { username } });
            state.username = res.username;
            await join();
        } catch (ex) {
            err.textContent = ex.data?.errors?.username?.[0] || ex.message;
            err.classList.remove('hidden');
        }
    };
}

async function join() {
    const res = await api(`/join/${EMBED.token}`, { body: {} });
    if (res.status !== 'approved') {
        renderUnavailable(t('This channel now requires owner approval and cannot be joined from an embed.'));
        return;
    }
    state.uuid = res.uuid;
    renderChat();
    await loadMessages(true);
    startPolling();
}

/* ------------------------------ chat ------------------------------- */

function renderChat() {
    $root.innerHTML = `
        <div class="flex items-center gap-2 border-b border-white/10 px-3 py-2">
            <span class="text-zinc-500">#</span>
            <p class="font-semibold text-white text-sm truncate flex-1 min-w-0">${esc(state.channelName)}</p>
            <span class="text-[11px] text-zinc-500 truncate max-w-24">${esc(state.username || '')}</span>
        </div>
        <div id="messages" class="flex-1 min-h-0 overflow-y-auto p-3 space-y-2"></div>
        <form id="composer" class="border-t border-white/10 p-2 flex items-end gap-2">
            <textarea id="msg-input" rows="1" placeholder="${t('Message…')}" maxlength="5000"
                class="flex-1 min-w-0 resize-none rounded-lg border border-white/15 bg-white/5 px-3 py-1.5 text-sm text-white focus:border-violet-500 focus:outline-none"></textarea>
            <button class="rounded-lg bg-violet-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-violet-500 shrink-0">${t('Send')}</button>
        </form>
        ${footerHtml()}`;

    const input = document.getElementById('msg-input');
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            document.getElementById('composer').requestSubmit();
        }
    });
    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 100) + 'px';
    });
    document.getElementById('composer').onsubmit = sendMessage;
}

const KIND_ICON = { image: '🖼', audio: '🎵', video: '🎬', zip: '🗜' };

function messageHtml(m) {
    const mine = m.user_id === state.userId;
    const content = m.kind === 'text'
        ? `<p class="whitespace-pre-wrap break-words text-sm">${esc(m.body)}</p>`
        : `<p class="text-sm italic text-zinc-400">${KIND_ICON[m.kind] || '📎'} ${esc(m.file_name || 'file')} —
               <a href="https://krotze.com/app" target="_blank" rel="noopener" class="underline hover:text-zinc-200">${t('open in Krotze to view')}</a></p>`;
    return `
        <div class="flex ${mine ? 'justify-end' : 'justify-start'}">
            <div class="max-w-[85%] min-w-0">
                <p class="text-[11px] text-zinc-500 mb-0.5 ${mine ? 'text-end' : ''}">
                    ${mine ? '' : esc(m.username) + ' · '}${fmtTime(m.created_at)}
                </p>
                <div class="rounded-2xl px-3 py-1.5 ${mine ? 'bg-violet-600/80 text-white' : 'bg-white/10 text-zinc-100'} break-words overflow-hidden">
                    ${content}
                </div>
            </div>
        </div>`;
}

function renderMessages() {
    const box = document.getElementById('messages');
    if (!box) return;
    const nearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 120;
    box.innerHTML = state.messages.map(messageHtml).join('')
        || `<p class="text-center text-sm text-zinc-500 py-8">${t('No messages yet — say hi!')}</p>`;
    if (nearBottom || !box.dataset.scrolled) {
        box.scrollTop = box.scrollHeight;
        box.dataset.scrolled = '1';
    }
}

async function loadMessages(initial = false) {
    if (!state.uuid) return;
    try {
        const data = await api(`/channels/${state.uuid}/messages?after=${initial ? 0 : state.lastMessageId}`);
        if (initial) state.messages = data.messages;
        else if (data.messages.length) state.messages.push(...data.messages);
        else return;
        if (state.messages.length) {
            state.lastMessageId = state.messages[state.messages.length - 1].id;
            api(`/channels/${state.uuid}/read`, { body: { message_id: state.lastMessageId } }).catch(() => {});
        }
        renderMessages();
    } catch (err) {
        // Channel destroyed / membership revoked: stop and say so.
        if (err.status === 404) {
            stopPolling();
            renderUnavailable(t('This chat no longer exists.'));
        }
    }
}

async function sendMessage(e) {
    e.preventDefault();
    const input = document.getElementById('msg-input');
    const body = input.value.trim();
    if (!body) return;
    input.value = '';
    input.style.height = 'auto';
    try {
        const res = await api(`/channels/${state.uuid}/messages`, { body: { body } });
        state.messages.push(res.message);
        state.lastMessageId = res.message.id;
        renderMessages();
    } catch (err) {
        input.value = body; // don't lose the text on throttle/errors
        flash(err.message);
    }
}

let pollTimer = null;
function startPolling() {
    stopPolling();
    pollTimer = setInterval(() => loadMessages(), 4000);
}
function stopPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
}

/* ------------------------------ boot ------------------------------- */

async function boot() {
    document.documentElement.lang = getLang();
    document.documentElement.dir = dir();
    if (!EMBED.available) {
        renderUnavailable(t('This chat is not available for embedding.'));
        return;
    }
    renderCentered(`<p class="text-sm text-zinc-500">${t('Connecting…')}</p>`);

    try { state.identity = JSON.parse(safeGet(IDENTITY_KEY) || 'null'); } catch { state.identity = null; }

    if (!state.identity?.id || !state.identity?.secret) {
        if (!EMBED.registrationsOpen) {
            renderUnavailable(t('This chat server is invite-only, so new visitors cannot join from here. Register on krotze.com first, then come back.'));
            return;
        }
        try {
            const cred = await api('/session', {
                anonymous: true,
                body: { device_name: 'Website embed' },
            });
            state.identity = { id: cred.device_id, secret: cred.secret };
            state.userId = cred.user_id;
            state.username = cred.username ?? null;
            safeSet(IDENTITY_KEY, JSON.stringify(state.identity));
        } catch (err) {
            renderUnavailable(err.data?.reason === 'registration_closed'
                ? t('This chat server is invite-only, so new visitors cannot join from here.')
                : t('Could not reach the server. Please try again later.'));
            return;
        }
    } else {
        // Returning visitor: learn who we are (username, user id).
        try {
            const s = await api('/state');
            state.userId = s.user_id;
            state.username = s.username ?? null;
        } catch {
            renderUnavailable(t('Could not reach the server. Please try again later.'));
            return;
        }
    }

    if (!state.username) {
        renderNameGate();
        return;
    }
    try {
        await join();
    } catch (err) {
        if (err.status === 429) renderUnavailable(err.message);
        else renderUnavailable(t('Could not join this chat. Please try again later.'));
    }
}

boot();
