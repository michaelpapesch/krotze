import QRCode from 'qrcode';
import jsQR from 'jsqr';
import { base32, hex, hmacSha256, open as unseal, randomBytes, seal, sha256 } from './crypto.js';
import { LANGS, dir, getLang, setLang, t } from './i18n.js';
import { boxPublicKey, decryptEnvelope, encryptFor, safetyNumber } from './e2ee.js';

/* ------------------------------------------------------------------ */
/* Krotze — anonymous chat PWA (vanilla JS single-page app)            */
/* ------------------------------------------------------------------ */

const $app = document.getElementById('app');

// Version of the code this client loaded (injected by the app shell blade).
const APP_VERSION = window.KROTZE_VERSION || 'dev';

const IDENTITY_KEY = 'krotze_identity';
const REG_INVITE_KEY = 'krotze_reg_invite';
const NOTIFY_KEY = 'krotze_notify';
const SERVERS_KEY = 'krotze_servers';

/**
 * Colours a server can be tagged with — ten hues far enough apart to tell at
 * a glance even when two servers are named alike. Applied as inline styles,
 * since Tailwind cannot see a class name that is only picked at runtime.
 */
const SERVER_PALETTE = [
    '#f87171', '#fb923c', '#facc15', '#4ade80', '#2dd4bf',
    '#38bdf8', '#818cf8', '#e879f9', '#f472b6', '#e4e4e7',
];

/**
 * Which events raise a notification, per device. Kept here as well as on the
 * server: this copy governs the notifications the poll loop raises itself, the
 * server's copy governs the ones it pushes. They are written together.
 */
const NOTIFY_DEFAULTS = {
    // Master switch for this device. Off means no push subscription and no
    // locally raised notifications — the way to silence one browser without
    // signing it out of the account.
    enabled: true,
    messages: true,
    chat_requests: true,
    join_requests: true,
    hide_message_text: false,
};

/**
 * Is this a home-screen install, or a browser tab?
 *
 * It decides where a notification tap lands: a notification belongs to the
 * browser whose service worker created the subscription, so one enabled in a
 * tab opens that browser and never the installed app.
 */
function isStandalone() {
    return window.matchMedia?.('(display-mode: standalone)').matches
        || window.navigator.standalone === true; // iOS
}

function isMobile() {
    return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
}

function notifySettings() {
    try {
        return { ...NOTIFY_DEFAULTS, ...JSON.parse(localStorage.getItem(NOTIFY_KEY) || '{}') };
    } catch { return { ...NOTIFY_DEFAULTS }; }
}

function setNotifySetting(key, on) {
    localStorage.setItem(NOTIFY_KEY, JSON.stringify({ ...notifySettings(), [key]: !!on }));
    // The server decides what to push; tell it about the change too.
    syncPushPreferences();
}

/** The preference payload the push API speaks. */
function pushPreferences() {
    const n = notifySettings();
    return {
        notify_messages: n.messages,
        notify_chat_requests: n.chat_requests,
        notify_join_requests: n.join_requests,
        hide_message_text: n.hide_message_text,
    };
}

/**
 * This device can hold an identity on several Krotze servers at once. Every
 * server is an entry in `store.servers` (see loadServers()); the one the page
 * was loaded from is the *home* server — it owns the service worker and the
 * push subscription and cannot be removed. Channels from all of them are
 * merged into one list, each carrying the server it lives on.
 */
const store = {
    servers: [],            // every server this device has an identity on
    server: null,           // the server the UI is acting on: the open channel's, else home
    channels: [],           // merged from every server; each entry carries .server and .key
    notifications: [],      // likewise
    current: null,          // { server, uuid, key } of the open channel
    detail: null,           // detail of open channel (members, meta), with .server
    messages: [],
    lastMessageId: 0,
    replyTo: null,          // message object being quoted in the composer
    sidebarOpen: false,
    membersOpen: false,
    stickToBottom: true,    // follow new messages unless the user scrolled up
    pushActive: false,      // server-sent notifications are reaching this device
    // Newest message id we have already announced per channel, so a channel is
    // only ever notified about once — seeded silently on the first poll.
    notifiedMessageIds: {},
    shownNotifIds: new Set(), // filled by loadServers()

    // The identity and account facts of the active server, so the code that
    // renders "the" open channel keeps reading them the way it always did.
    get identity() { return this.server?.identity ?? null; },
    get userId() { return this.server?.state.userId ?? null; },
    get username() { return this.server?.state.username ?? null; },
    get uploadRetentionDays() { return this.server?.state.uploadRetentionDays ?? null; },
    get uploadViewMinutes() { return this.server?.state.uploadViewMinutes ?? null; },
};

/* ---------------------------- helpers ----------------------------- */

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
}

function fmtSize(bytes) {
    if (!bytes) return '';
    if (bytes > 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes > 1024) return Math.round(bytes / 1024) + ' KB';
    return bytes + ' B';
}

function fmtTime(iso) {
    const d = new Date(iso);
    const today = new Date().toDateString() === d.toDateString();
    return today
        ? d.toLocaleTimeString(getLang(), { hour: '2-digit', minute: '2-digit' })
        : d.toLocaleDateString(getLang(), { day: '2-digit', month: '2-digit' }) + ' ' +
          d.toLocaleTimeString(getLang(), { hour: '2-digit', minute: '2-digit' });
}

/**
 * Every authenticated call is signed rather than carrying a credential: the
 * device secret stays in local storage and only a per-request HMAC goes over
 * the wire. Whoever records the traffic gets signatures that are bound to one
 * method, path, body and nonce, and expire within minutes — nothing that can
 * be replayed or imported as an identity.
 */
async function api(path, opts = {}) {
    // Which server: the one the caller named, else the one the UI is acting
    // on. The signed string is the same path on every server — it names no
    // host — so only the fetch target differs.
    const server = opts.server || store.server || homeServer();
    const url = '/api' + path;
    const method = (opts.method || (opts.body ? 'POST' : 'GET')).toUpperCase();
    const isForm = opts.body instanceof FormData;
    const body = isForm ? opts.body : opts.body ? JSON.stringify(opts.body) : undefined;

    const headers = {};
    if (opts.body && !isForm) headers['Content-Type'] = 'application/json';

    if (server.identity && !opts.anonymous) {
        // A multipart body is consumed by PHP before it can be hashed, so
        // uploads sign an empty body — the server applies the identical rule.
        const ts = String(Math.floor(Date.now() / 1000) + (server.clockSkew || 0));
        const nonce = hex(randomBytes(16));
        const canonical = [method, url, ts, nonce, hex(sha256(isForm ? '' : body ?? ''))].join('\n');
        headers['X-Chat-Device'] = server.identity.id;
        headers['X-Chat-Ts'] = ts;
        headers['X-Chat-Nonce'] = nonce;
        headers['X-Chat-Sig'] = hex(hmacSha256(server.identity.secret, canonical));
    }

    // A dead server must not stall the callers that poll every one of them.
    const controller = opts.timeout ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), opts.timeout) : null;
    let res;
    try {
        res = await fetch((server.home ? '' : server.origin) + url, { method, headers, body, signal: controller?.signal });
    } catch {
        throw Object.assign(new Error(t('Could not reach :server', { server: serverLabel(server) })), { network: true, server });
    } finally {
        clearTimeout(timer);
    }

    if (res.status === 401) {
        const info = await res.json().catch(() => ({}));
        // A wrong device clock invalidates every signature — learn the offset
        // from the server (the body, or its Date header) and sign again.
        if (info.reason === 'stale' && !opts._retried) {
            const serverTime = info.server_time ? info.server_time * 1000 : Date.parse(res.headers.get('Date') || '');
            if (serverTime) {
                server.clockSkew = Math.round(serverTime / 1000) - Math.floor(Date.now() / 1000);
                return api(path, { ...opts, server, _retried: true });
            }
        }
        if (info.reason === 'device_unknown') {
            if (server.home) {
                forgetIdentity();
                return new Promise(() => {}); // page is reloading
            }
            // Another server dropped this device: that server goes dark in the
            // list, everything else carries on.
            markServerRevoked(server);
        }
        throw Object.assign(new Error('Not authorised.'), { data: info, status: 401, server });
    }

    // The admin has not (yet) let this identity in. Nothing it can call will
    // work, so put up the waiting screen instead of failing call by call — on
    // the home server; a foreign server just shows its state in the list.
    if (res.status === 403) {
        const info = await res.json().catch(() => ({}));
        if (info.reason === 'registration_pending' || info.reason === 'registration_denied') {
            server.status = info.reason === 'registration_pending' ? 'pending' : 'denied';
            saveServers();
            if (server.home) {
                renderAccountGate();
                return new Promise(() => {}); // the gate screen has taken over
            }
            renderChannelList();
        }
        throw Object.assign(new Error(info.error || 'Not allowed.'), { data: info, status: 403, server });
    }

    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.error || data.message || 'Request failed'), { data, status: res.status });
    return data;
}

/* ----------------------------- servers ----------------------------- */

/**
 * A server entry as it is persisted. Runtime fields (state, channels, …) are
 * added by initRuntime() and stripped again by saveServers().
 */
function newServerEntry(origin, extra = {}) {
    return {
        id: serverIdFor(origin),   // stable local id, derived from the origin
        origin,                    // scheme://host[:port]
        home: false,               // the server this page was loaded from
        nick: null,                // local nickname; the hostname is shown otherwise
        suffix: null,              // short tag shown next to every channel — see assignSuffixes()
        color: null,               // index into SERVER_PALETTE — see assignColors()
        identity: null,            // { id, secret } — the secret never leaves this device
        boxkeySent: null,          // the E2EE public key this server already knows
        status: 'ok',              // ok | pending | denied | revoked
        addedAt: new Date().toISOString(),
        ...extra,
    };
}

function initRuntime(s) {
    s.state = { userId: null, username: null, uploadRetentionDays: null, uploadViewMinutes: null, version: null };
    s.clockSkew = 0;       // seconds to add to our clock to match that server's
    s.channels = [];
    s.notifications = [];
    s.reachable = true;
    s.polling = false;
    s.pushRelay = false;   // that server delivers push through the home server
    s.status ||= 'ok';
    return s;
}

/** Twelve base32 characters of the origin's hash — unique enough, and stable. */
function serverIdFor(origin) {
    return base32(sha256(origin)).slice(0, 12).toLowerCase();
}

/**
 * Read the server list, or build it from the single-server keys an earlier
 * version left behind: that identity becomes the home server's. Ids that used
 * to be plain numbers or uuids become "<server>:<id>" so two servers can never
 * be confused about a channel or a notification.
 */
function loadServers() {
    let list = [];
    try { list = JSON.parse(localStorage.getItem(SERVERS_KEY) || '[]'); } catch { /* start over */ }
    list = (Array.isArray(list) ? list : []).filter((s) => s && typeof s.origin === 'string' && s.id);
    for (const s of list) s.home = s.origin === location.origin;

    let home = list.find((s) => s.home);
    if (!home) {
        let identity = null;
        try { identity = JSON.parse(localStorage.getItem(IDENTITY_KEY) || 'null'); } catch { /* none */ }
        home = newServerEntry(location.origin, {
            home: true,
            identity: identity?.id && identity?.secret ? { id: identity.id, secret: identity.secret } : null,
            boxkeySent: localStorage.getItem('krotze_boxkey_sent'),
        });
        list.unshift(home);
    }
    list.forEach(initRuntime);
    store.servers = list;
    store.server = home;
    assignSuffixes();
    assignColors();
    saveServers();

    const last = localStorage.getItem('krotze_last_channel');
    if (last && !last.includes(':')) localStorage.setItem('krotze_last_channel', chanKey(home, last));
    let shown = [];
    try { shown = JSON.parse(localStorage.getItem('krotze_shown_notifs') || '[]'); } catch { /* none */ }
    store.shownNotifIds = new Set(shown.map((x) => (typeof x === 'number' ? notifKey(home, x) : x)));
}

function saveServers() {
    const persisted = store.servers.map(({ id, origin, home, nick, suffix, color, identity, boxkeySent, status, addedAt }) =>
        ({ id, origin, home, nick, suffix, color, identity, boxkeySent, status, addedAt }));
    localStorage.setItem(SERVERS_KEY, JSON.stringify(persisted));
    // Mirror the home identity where the previous version kept it, so a
    // rolled-back build still finds it.
    const home = homeServer();
    if (home?.identity) localStorage.setItem(IDENTITY_KEY, JSON.stringify(home.identity));
    else localStorage.removeItem(IDENTITY_KEY);
}

function homeServer() {
    return store.servers.find((s) => s.home);
}

function serverById(id) {
    return store.servers.find((s) => s.id === id) || null;
}

function serverByOrigin(origin) {
    const o = normalizeOrigin(origin);
    return o ? store.servers.find((s) => s.origin === o) || null : null;
}

/** `chat.example.org`, `https://chat.example.org/`, `HTTP://X:8000/app` → an origin, or null. */
function normalizeOrigin(input) {
    let s = String(input || '').trim();
    if (!s) return null;
    if (!/^https?:\/\//i.test(s)) s = 'https://' + s;
    try {
        const u = new URL(s);
        return /^https?:$/.test(u.protocol) && u.hostname ? u.origin : null;
    } catch { return null; }
}

function hostOf(url) {
    try { return new URL(url).host; } catch { return String(url); }
}

/** What a server is called here: the nickname, else its hostname. */
function serverLabel(s) {
    return s?.nick || hostOf(s?.origin || '');
}

/**
 * The short tag every channel shows: the first characters of the server id,
 * extended while any two tags could be mistaken for each other. Servers with
 * almost the same name still end up with visibly different tags.
 */
function assignSuffixes() {
    const len = {};
    for (const s of store.servers) len[s.id] = 4;
    for (let guard = 0; guard < 12; guard++) {
        let clash = false;
        for (const a of store.servers) {
            for (const b of store.servers) {
                if (a === b) continue;
                const ta = a.id.slice(0, len[a.id]);
                const tb = b.id.slice(0, len[b.id]);
                if (ta.startsWith(tb) || tb.startsWith(ta)) {
                    len[a.id] = Math.min(12, len[a.id] + 1);
                    len[b.id] = Math.min(12, len[b.id] + 1);
                    clash = true;
                }
            }
        }
        if (!clash) break;
    }
    for (const s of store.servers) s.suffix = s.id.slice(0, len[s.id]).toUpperCase();
}

/**
 * A colour per server, derived from its id so every device tends to agree,
 * but never shared by two servers on one device: a collision walks on to the
 * next free colour. A colour once chosen (or picked by hand) stays.
 */
function assignColors() {
    const used = new Set(store.servers.filter((s) => Number.isInteger(s.color)).map((s) => s.color));
    for (const s of store.servers) {
        if (Number.isInteger(s.color)) continue;
        let i = [...s.id].reduce((sum, c) => sum + c.charCodeAt(0), 0) % SERVER_PALETTE.length;
        for (let n = 0; n < SERVER_PALETTE.length && used.has(i); n++) i = (i + 1) % SERVER_PALETTE.length;
        s.color = i;
        used.add(i);
    }
}

function chanKey(server, uuid) {
    return server.id + ':' + uuid;
}

function notifKey(server, id) {
    return server.id + ':' + id;
}

/** A channel by uuid — on the named origin when given, else on any server. */
function findChannelByUuid(uuid, origin = null) {
    if (origin) {
        const s = serverByOrigin(origin);
        return s ? s.channels.find((c) => c.uuid === uuid) || null : null;
    }
    return store.channels.find((c) => c.uuid === uuid) || null;
}

function getLastChannel() {
    const raw = localStorage.getItem('krotze_last_channel') || '';
    const i = raw.indexOf(':');
    if (i < 0) return null;
    const server = serverById(raw.slice(0, i));
    return server ? { server, uuid: raw.slice(i + 1) } : null;
}

function setLastChannel(server, uuid) {
    localStorage.setItem('krotze_last_channel', chanKey(server, uuid));
}

function clearLastChannel() {
    localStorage.removeItem('krotze_last_channel');
}

function mergeServers() {
    store.channels = store.servers.flatMap((s) => s.channels || []);
    store.notifications = store.servers.flatMap((s) => s.notifications || []);
}

/** The tag rendered next to a channel; only worth showing with two or more servers. */
function serverChipHtml(server, { always = false } = {}) {
    if (!server || (!always && store.servers.length < 2)) return '';
    const c = SERVER_PALETTE[server.color] || SERVER_PALETTE[0];
    return `<span class="shrink-0 rounded px-1 font-mono text-[10px] font-semibold leading-4" dir="ltr"
        style="color:${c};border:1px solid ${c}66;background:${c}1a"
        title="${esc(serverLabel(server))} · ${esc(server.origin)}">●${esc(server.suffix)}</span>`;
}

/** Why a server is not simply working right now, or null. */
function serverProblem(s) {
    if (s.status === 'revoked') return t('Removed from this server');
    if (s.status === 'pending') return t('Waiting for approval on :server', { server: serverLabel(s) });
    if (s.status === 'denied') return t('Registration denied on :server', { server: serverLabel(s) });
    if (s.reachable === false) return t('Unreachable');
    return null;
}

function usableServer(s) {
    return !!s.identity && s.status === 'ok';
}

/* ---------------------------- identity ----------------------------- */

function saveIdentity(cred, server = homeServer()) {
    server.identity = { id: cred.device_id, secret: cred.secret };
    server.state.userId = cred.user_id;
    server.state.username = cred.username ?? null;
    server.status = cred.status && cred.status !== 'approved' ? cred.status : 'ok';
    saveServers();
}

/**
 * Drop the dead home credentials and restart. Called both when this device was
 * revoked from somewhere else — the default message — and when the user signed
 * it out here, which deserves wording that does not read like a surprise. The
 * identities on other servers stay.
 */
function forgetIdentity(message = null) {
    const home = homeServer();
    home.identity = null;
    home.boxkeySent = null;
    home.status = 'ok';
    saveServers();
    clearLastChannel();
    if (message) {
        sessionStorage.setItem('krotze_flash', message);
    } else {
        sessionStorage.setItem('krotze_revoked', '1');
    }
    location.reload();
}

/** A foreign server no longer knows this device: keep the entry, drop the rest. */
function markServerRevoked(server) {
    if (server.status === 'revoked') return;
    server.status = 'revoked';
    server.identity = null;
    server.pushRelay = false;
    server.channels = [];
    server.notifications = [];
    saveServers();
    if (store.current?.server === server) closeChannel();
    mergeServers();
    renderChannelList();
    renderNotifications();
    updateTitleBadge();
    toastError({ message: t('This device was removed from :server.', { server: serverLabel(server) }) });
}

/** Back to the empty main pane; the active server falls back to home. */
function closeChannel() {
    store.current = null;
    store.detail = null;
    store.server = homeServer();
    clearLastChannel();
    renderEmpty();
    renderChannelList();
}

function deviceName() {
    const ua = navigator.userAgent;
    const os = /Android/i.test(ua) ? 'Android'
        : /iPhone|iPad|iPod/i.test(ua) ? 'iOS'
        : /Mac OS X/i.test(ua) ? 'Mac'
        : /Windows/i.test(ua) ? 'Windows'
        : /Linux/i.test(ua) ? 'Linux' : 'Device';
    const browser = /Edg\//.test(ua) ? 'Edge'
        : /OPR\//.test(ua) ? 'Opera'
        : /Firefox\//.test(ua) ? 'Firefox'
        : /Chrome\//.test(ua) ? 'Chrome'
        : /Safari\//.test(ua) ? 'Safari' : 'Browser';
    return `${browser} on ${os}`;
}

/**
 * Register this device unless it already has an identity. On a server with
 * closed registrations that only works with the admin's invite token, which we
 * pick up from a /register/<token> link and keep until it has been used.
 */
async function ensureIdentity() {
    const home = homeServer();
    if (home.identity?.id && home.identity?.secret) return;

    const cred = await api('/session', {
        server: home,
        anonymous: true,
        body: {
            device_name: deviceName(),
            invite: localStorage.getItem(REG_INVITE_KEY) || null,
        },
    });
    localStorage.removeItem(REG_INVITE_KEY);
    saveIdentity(cred, home);
}

/* ------------------------ registration gate ------------------------ */

/**
 * Full-screen stand-in for the app while this identity cannot use it: either
 * the server refuses new registrations altogether, or an admin still has to
 * decide about this one. The poll keeps running, so an approval lands here
 * within a few seconds without anybody reloading anything.
 */
function renderGateScreen({ icon, title, text, action = '' }) {
    $app.innerHTML = `
        <div class="flex h-full min-h-0 flex-col items-center justify-center gap-4 p-8 text-center">
            <img src="/img/icon.svg" alt="" class="h-16 w-16 opacity-70">
            <div class="text-4xl">${icon}</div>
            <h1 class="text-xl font-bold text-white break-words">${esc(title)}</h1>
            <p class="max-w-sm text-sm text-zinc-400 break-words">${esc(text)}</p>
            <div id="gate-action" class="pt-2">${action}</div>
            <a href="/" class="pt-4 text-xs text-zinc-500 hover:text-zinc-300">${t('← Back to krotze.com')}</a>
        </div>`;
}

function renderAccountGate() {
    // Only the home server gates the whole app; a foreign server that is still
    // waiting simply says so in the list. Called from every poll — only touch
    // the DOM when something changed, so the screen does not flicker.
    const home = homeServer();
    const username = home.state.username;
    const signature = home.status + ':' + (username || '');
    if ($app.dataset.gate === signature) return;
    $app.dataset.gate = signature;

    if (home.status === 'pending') {
        renderGateScreen({
            icon: '⏳',
            title: t('Waiting for approval'),
            text: username
                ? t('You registered as “:name”. An admin of this server has to approve you before you can chat. This page updates itself the moment that happens.', { name: username })
                : t('An admin of this server has to approve your registration before you can chat. Pick the name they will see below.'),
            action: username
                ? ''
                : `<button id="gate-username" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">${t('Choose your username')}</button>`,
        });
        document.getElementById('gate-username')?.addEventListener('click', () => usernameDialog({ required: true, server: home }));
        return;
    }

    renderGateScreen({
        icon: '🚫',
        title: t('Registration denied'),
        text: t('An admin of this server declined your registration. If you think that is a mistake, ask whoever invited you.'),
    });
}

function renderRegistrationClosed(message) {
    renderGateScreen({
        icon: '✉️',
        title: t('This server is invite-only'),
        text: message || t('New registrations are closed. You need an invite link from an admin of this server to join.'),
    });
}

/* ----------------------------- modals ----------------------------- */

function modal(contentHtml, { onOpen, dismissible = true } = {}) {
    const wrap = document.createElement('div');
    wrap.className = 'fixed inset-0 z-50 flex items-center justify-center p-4';
    wrap.innerHTML = `
        <div class="absolute inset-0 bg-black/70" data-close></div>
        <div class="relative w-full max-w-md max-h-[90vh] overflow-y-auto rounded-2xl border border-white/10 bg-[#171522] p-6 shadow-2xl break-words">
            ${contentHtml}
        </div>`;
    if (dismissible) {
        wrap.querySelector('[data-close]').addEventListener('click', () => wrap.remove());
    }
    document.body.appendChild(wrap);
    onOpen?.(wrap);
    return wrap;
}

function confirmDialog({ title, text, confirmLabel = t('Confirm'), danger = true, checkbox = null }) {
    return new Promise((resolve) => {
        const m = modal(`
            <h2 class="text-lg font-bold text-white">${esc(title)}</h2>
            <p class="mt-2 text-sm text-zinc-400">${esc(text)}</p>
            ${checkbox ? `
                <label class="mt-4 flex items-start gap-2 text-sm text-zinc-300 cursor-pointer">
                    <input type="checkbox" id="cd-check" class="mt-0.5 accent-violet-500">
                    <span>${esc(checkbox)}</span>
                </label>` : ''}
            <div class="mt-6 flex justify-end gap-3">
                <button id="cd-cancel" class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Cancel')}</button>
                <button id="cd-ok" class="rounded-lg px-4 py-2 text-sm font-semibold text-white ${danger ? 'bg-red-600 hover:bg-red-500' : 'bg-violet-600 hover:bg-violet-500'}">${esc(confirmLabel)}</button>
            </div>`);
        m.querySelector('#cd-cancel').onclick = () => { m.remove(); resolve(null); };
        m.querySelector('#cd-ok').onclick = () => {
            const checked = m.querySelector('#cd-check')?.checked ?? false;
            m.remove();
            resolve({ checked });
        };
    });
}

function toastError(err) {
    const t = document.createElement('div');
    t.className = 'fixed bottom-4 left-1/2 -translate-x-1/2 z-[60] rounded-lg bg-red-600 px-4 py-2 text-sm text-white shadow-lg max-w-[90vw] break-words';
    t.textContent = err?.message || String(err);
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

function toastInfo(text) {
    const t = document.createElement('div');
    t.className = 'fixed bottom-4 left-1/2 -translate-x-1/2 z-[60] rounded-lg bg-violet-600 px-4 py-2 text-sm text-white shadow-lg max-w-[90vw] break-words';
    t.textContent = text;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

/* -------------------------- notifications ------------------------- */

const NOTIF_RENDER = {
    join_request: (n) => ({
        text: t('“:name” wants to join “:channel”', { name: n.data.username, channel: n.data.channel_name }),
        actions: [
            { label: t('Approve'), style: 'ok', run: () => decideJoin(n, true) },
            { label: t('Deny'), style: 'danger', run: () => decideJoin(n, false) },
        ],
    }),
    private_invite: (n) => ({
        text: t('“:name” wants to start a private chat with you', { name: n.data.from_username }),
        actions: [
            { label: t('Accept'), style: 'ok', run: () => respondPrivate(n, true) },
            { label: t('Decline'), style: 'danger', run: () => respondPrivate(n, false) },
        ],
    }),
    join_approved: (n) => ({
        text: t('You were approved in “:channel”', { channel: n.data.channel_name }),
        actions: [{ label: t('Open'), style: 'ok', run: async () => { await markNotifRead(n); refreshState({ only: n.server }).then(() => openChannel(n.data.channel_uuid, n.server)); } }],
    }),
    join_denied: (n) => ({ text: t('Your request to join “:channel” was denied.', { channel: n.data.channel_name }) }),
    private_accepted: (n) => ({
        text: t('“:name” accepted your private chat', { name: n.data.from_username }),
        actions: [{ label: t('Open'), style: 'ok', run: async () => { await markNotifRead(n); refreshState({ only: n.server }).then(() => openChannel(n.data.channel_uuid, n.server)); } }],
    }),
    private_declined: (n) => ({ text: t('“:name” declined your private chat request.', { name: n.data.from_username }) }),
    ownership_received: (n) => ({ text: t('“:name” transferred ownership of “:channel” to you.', { name: n.data.from_username, channel: n.data.channel_name }) }),
    member_removed: (n) => ({ text: t('You were removed from “:channel”.', { channel: n.data.channel_name }) }),
    channel_destroyed: (n) => ({ text: t('The channel “:channel” was destroyed.', { channel: n.data.name }) }),
};

/**
 * Which notification setting silences which card. Types that are not listed —
 * approvals, removals, a destroyed channel — always raise a notification;
 * they are rare and always relevant.
 */
const NOTIF_FOR_TYPE = {
    join_request: 'join_requests',
    private_invite: 'chat_requests',
};

async function markNotifRead(n) {
    try { await api('/notifications/read', { server: n.server, body: { id: n.id } }); } catch { /* ignore */ }
    n.server.notifications = n.server.notifications.filter((x) => x.id !== n.id);
    store.notifications = store.notifications.filter((x) => x.key !== n.key);
    renderNotifications();
}

async function decideJoin(n, approve) {
    try {
        await api(`/members/${n.data.member_id}/decision`, { server: n.server, body: { approve } });
    } catch { /* request may be stale */ }
    await markNotifRead(n);
    refreshState({ only: n.server });
}

async function respondPrivate(n, accept) {
    try {
        await api(`/channels/${n.data.channel_uuid}/private-response`, { server: n.server, body: { accept } });
        await markNotifRead(n);
        await refreshState({ only: n.server });
        if (accept) openChannel(n.data.channel_uuid, n.server);
    } catch (e) {
        toastError(e);
        await markNotifRead(n);
    }
}

/** What a system notification is headed with — the server, once there are several. */
function notifTitle(server) {
    return store.servers.length > 1 ? 'Krotze · ' + serverLabel(server) : 'Krotze';
}

function renderNotifications() {
    let host = document.getElementById('notif-host');
    if (!host) {
        host = document.createElement('div');
        host.id = 'notif-host';
        host.className = 'fixed top-3 end-3 z-[55] flex flex-col gap-2 w-[min(22rem,calc(100vw-1.5rem))]';
        document.body.appendChild(host);
    }
    host.innerHTML = '';
    // Collapse many join requests for the same channel into one reviewable card.
    const joinGroups = {};
    const groupKey = (n) => chanKey(n.server, n.data.channel_uuid);
    for (const n of store.notifications) {
        if (n.type === 'join_request') (joinGroups[groupKey(n)] ||= []).push(n);
    }
    const aggregated = new Set();
    for (const n of store.notifications) {
        let def;
        if (n.type === 'join_request' && joinGroups[groupKey(n)]?.length > 1) {
            if (aggregated.has(groupKey(n))) continue;
            aggregated.add(groupKey(n));
            const g = joinGroups[groupKey(n)];
            def = {
                text: t(':count people want to join “:channel”', { count: g.length, channel: n.data.channel_name }),
                actions: [{
                    label: t('Review'),
                    style: 'ok',
                    run: async () => {
                        await openChannel(n.data.channel_uuid, n.server);
                        pendingRequestsDialog(store.detail);
                    },
                }],
            };
            // One system notification for the whole group.
            if (g.some((gn) => !store.shownNotifIds.has(gn.key)) && notifyAllowed(n.type, n.server)) {
                systemNotify(notifTitle(n.server), def.text);
            }
            g.forEach((gn) => store.shownNotifIds.add(gn.key));
            localStorage.setItem('krotze_shown_notifs',
                JSON.stringify([...store.shownNotifIds].slice(-200)));
        } else {
            def = NOTIF_RENDER[n.type]?.(n);
        }
        if (!def) continue;
        const card = document.createElement('div');
        card.className = 'rounded-xl border border-white/15 bg-[#1d1a2b] p-4 shadow-xl break-words';
        card.innerHTML = `
            <p class="text-sm text-zinc-200">${serverChipHtml(n.server)} ${esc(def.text)}</p>
            <div class="mt-3 flex flex-wrap justify-end gap-2"></div>`;
        const btnRow = card.querySelector('div');
        const actions = def.actions
            ? [...def.actions, { label: t('Later'), style: 'plain', run: null }]
            : [{ label: t('OK'), style: 'plain', run: () => markNotifRead(n) }];
        for (const a of actions) {
            const b = document.createElement('button');
            b.textContent = a.label;
            b.className = {
                ok: 'rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-500',
                danger: 'rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-500',
                plain: 'rounded-lg border border-white/20 px-3 py-1.5 text-xs hover:bg-white/10',
            }[a.style];
            b.onclick = a.run ? () => a.run() : () => card.remove();
            btnRow.appendChild(b);
        }
        host.appendChild(card);

        // System notification (works in the installed PWA) — once per notification
        if (!store.shownNotifIds.has(n.key)) {
            store.shownNotifIds.add(n.key);
            localStorage.setItem('krotze_shown_notifs',
                JSON.stringify([...store.shownNotifIds].slice(-200)));
            if (notifyAllowed(n.type, n.server)) systemNotify(notifTitle(n.server), def.text);
        }
    }
}

/**
 * Should the poll loop raise a notification for this event itself? Not if
 * that server is already pushing them to this device — directly (home) or
 * through the home server's relay — and not if the kind has been switched off
 * in the settings dialog.
 */
function notifyAllowed(type, server) {
    const settings = notifySettings();
    if (!settings.enabled) return false;
    if (store.pushActive && (server.home || server.pushRelay)) return false;
    const key = NOTIF_FOR_TYPE[type];
    return !key || settings[key];
}

/**
 * Show a notification now, even though the app is in front — used by the test
 * button, where the whole point is to see one appear.
 */
function localNotify(title, body) {
    return systemNotify(title, body, { tag: 'push-test', whileVisible: true });
}

async function systemNotify(title, body, { tag, uuid, origin = null, whileVisible = false } = {}) {
    try {
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        if (!whileVisible && document.visibilityState === 'visible') return;
        const opts = {
            body,
            icon: '/img/icon-192.png',
            // Monochrome silhouette: the status-bar badge is tinted from the
            // alpha channel, so a colour icon would arrive as a white blob.
            badge: '/img/badge-96.png',
            tag,                    // a second message replaces the first card
            data: { uuid, origin }, // the service worker opens that channel (on that server)
        };
        const reg = await navigator.serviceWorker?.ready;
        if (reg) reg.showNotification(title, opts);
        else new Notification(title, opts);
    } catch { /* notifications unavailable */ }
}

/* ------------------------------ web push --------------------------------- */

/**
 * Ask the browser's push service to deliver notifications for this device, and
 * hand the resulting subscription to the server.
 *
 * Push only exists in a secure context, so over plain http (a development host)
 * `pushManager` is simply absent — that is not an error, it just means this
 * device keeps raising notifications from its own poll loop instead.
 */
async function enablePush() {
    try {
        if (!notifySettings().enabled) return false;
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return false;
        if (Notification.permission !== 'granted') return false;

        const home = homeServer();
        const { enabled, public_key: key } = await api('/push/key', { server: home, anonymous: true });
        if (!enabled || !key) return false;

        const reg = await navigator.serviceWorker.ready;
        const existing = await reg.pushManager.getSubscription();
        // A subscription minted under a previous VAPID key is dead weight — the
        // push service will reject it — so replace it rather than reuse it.
        const subscription = existing && sameKey(existing, key)
            ? existing
            : await resubscribe(reg, key, existing);

        const json = subscription.toJSON();
        await api('/push/subscribe', {
            server: home,
            body: {
                endpoint: json.endpoint,
                keys: { p256dh: json.keys.p256dh, auth: json.keys.auth },
                ...pushPreferences(),
            },
        });
        store.pushActive = true;
        syncRelays();
        return true;
    } catch {
        // Permission revoked mid-flight, push service unreachable, browser
        // without support — all of them just mean "keep polling".
        store.pushActive = false;
        return false;
    }
}

async function resubscribe(reg, key, existing) {
    if (existing) await existing.unsubscribe().catch(() => {});
    return reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: b64urlToBytes(key),
    });
}

function sameKey(subscription, key) {
    const current = subscription.options?.applicationServerKey;
    if (!current) return false;
    const a = new Uint8Array(current);
    const b = b64urlToBytes(key);
    return a.length === b.length && a.every((v, i) => v === b[i]);
}

/** applicationServerKey wants raw bytes, and the key travels as base64url. */
function b64urlToBytes(value) {
    const padded = value.replace(/-/g, '+').replace(/_/g, '/')
        + '='.repeat((4 - (value.length % 4)) % 4);
    const raw = atob(padded);
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

/**
 * Push for the other servers. A browser holds one push subscription, bound to
 * the home server's key, so every other server delivers through it: the home
 * server hands out a relay token per foreign server, and that server stores
 * the pair as this device's "subscription". A server too old to know relays
 * simply keeps being notified from the poll loop.
 */
async function syncRelays() {
    if (!store.pushActive) return;
    const home = homeServer();
    await Promise.allSettled(store.servers.filter((s) => !s.home && usableServer(s)).map(async (s) => {
        try {
            const relay = await api('/push/relay', { server: home, body: { origin: s.origin } });
            await api('/push/subscribe', {
                server: s,
                body: { relay_url: relay.relay_url, relay_token: relay.relay_token, ...pushPreferences() },
            });
            s.pushRelay = true;
        } catch {
            s.pushRelay = false;
        }
    }));
}

/** Undo syncRelays() for one server — when it is removed, or push is switched off. */
async function dropRelay(server) {
    if (server.home) return;
    if (server.pushRelay) {
        try { await api('/push/subscription', { method: 'DELETE', server }); } catch { /* already gone */ }
        server.pushRelay = false;
    }
    try {
        await api('/push/relay', { method: 'DELETE', server: homeServer(), body: { origin: server.origin } });
    } catch { /* home unreachable — the relay dies with the next rotation */ }
}

/** Push the current notification preferences to every server that is subscribed. */
async function syncPushPreferences() {
    if (!store.pushActive) return;
    const targets = store.servers.filter((s) => s.home || s.pushRelay);
    await Promise.allSettled(targets.map((s) =>
        api('/push/subscription', { method: 'PATCH', server: s, body: pushPreferences() })));
    // Failures are fine: the next enablePush() carries the preferences along.
}

/**
 * Stop notifying this device: drop the browser subscription and every server's
 * record of it. The identities stay signed in — this is the difference between
 * silencing a browser and revoking it in the profile.
 */
async function disablePush() {
    store.pushActive = false;
    try {
        const reg = await navigator.serviceWorker?.ready;
        const subscription = await reg?.pushManager.getSubscription();
        await subscription?.unsubscribe();
    } catch { /* nothing subscribed */ }
    await Promise.allSettled(store.servers.filter((s) => !s.home).map(dropRelay));
    try { await api('/push/subscription', { method: 'DELETE', server: homeServer() }); } catch { /* already gone */ }
}

/**
 * Announce messages that arrived in channels this device is not looking at.
 * Muted channels stay quiet, and every channel is seeded on the first poll so
 * opening the app never replays the backlog.
 */
function notifyNewMessages(channels, seedOnly = false) {
    const settings = notifySettings();
    const allowed = settings.enabled && settings.messages;
    const several = store.servers.length > 1;
    for (const c of channels) {
        const last = c.last_message;
        if (!last) continue;
        const previous = store.notifiedMessageIds[c.key];
        store.notifiedMessageIds[c.key] = last.id;
        if (seedOnly || previous === undefined || last.id <= previous) continue;
        if (!allowed || c.muted || c.status !== 'approved' || last.user_id === c.server.state.userId) continue;
        // With push active that server already notifies this device (directly
        // or through the relay), and doing it here too would show it twice.
        if (store.pushActive && (c.server.home || c.server.pushRelay)) continue;
        systemNotify(
            (c.type === 'private' ? last.username : `${c.name} · ${last.username}`)
                + (several ? ` · ${serverLabel(c.server)}` : ''),
            last.encrypted ? '🔒 ' + t('Encrypted message') : (last.excerpt || ''),
            { tag: 'chan-' + c.key, uuid: c.uuid, origin: c.server.home ? null : c.server.origin },
        );
    }
}

/* ----------------------------- layout ----------------------------- */

function renderShell() {
    $app.innerHTML = `
        <div class="flex h-full min-h-0">
            <div id="sidebar-backdrop" class="fixed inset-0 z-30 bg-black/60 hidden md:hidden"></div>
            <aside id="sidebar"
                   class="app-sidebar closed fixed z-40 inset-y-0 start-0 w-72 max-w-[85vw]
                          md:static md:z-auto
                          flex flex-col border-e border-white/10 bg-[#12101c]">
                <div class="p-3 flex items-center gap-2 border-b border-white/10">
                    <img src="/img/icon.svg" alt="" class="h-6 w-6">
                    <span class="font-bold text-white">Krotze</span>
                    <button id="btn-close-sidebar" class="ms-auto md:hidden text-zinc-400 hover:text-white px-2 text-xl leading-none">×</button>
                </div>
                <div class="p-3 space-y-2">
                    <button id="btn-create" class="w-full rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-500">
                        ${t('+ Create channel')}
                    </button>
                    <button id="btn-scan" class="w-full rounded-lg border border-white/15 px-3 py-2 text-sm text-zinc-200 hover:bg-white/10">
                        📷 ${t('Scan QR code')}
                    </button>
                    <button id="btn-profile" class="flex w-full items-center gap-2 rounded-lg border border-white/15 px-3 py-2 text-sm text-zinc-200 hover:bg-white/10">
                        <span>👤</span>
                        <span class="min-w-0 flex-1 truncate text-start">${t('Profile')}</span>
                        <span id="profile-name" class="min-w-0 max-w-[8rem] truncate text-xs text-zinc-500"></span>
                    </button>
                    <button id="btn-servers" class="flex w-full items-center gap-2 rounded-lg border border-white/15 px-3 py-2 text-sm text-zinc-200 hover:bg-white/10">
                        <span>🌐</span>
                        <span class="min-w-0 flex-1 truncate text-start">${t('Servers')}</span>
                        <span id="servers-badge" class="hidden shrink-0 rounded-full bg-amber-500 px-1.5 text-xs font-bold text-black"></span>
                    </button>
                </div>
                <div class="flex items-center justify-between px-4 pb-1 text-xs text-zinc-500">
                    <span>${t('Channels')}</span>
                    <button id="btn-list-settings" title="${t('Settings')}" class="opacity-60 hover:opacity-100">⚙️</button>
                </div>
                <nav id="channel-list" class="flex-1 overflow-y-auto px-2 pb-2 space-y-1"></nav>
                <div class="border-t border-white/10 p-3 text-xs text-zinc-500 space-y-2">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <a href="/" class="hover:text-zinc-300">${t('Home')}</a>
                        <a href="/api/docs" class="hover:text-zinc-300">API</a>
                        <a href="/faq" class="hover:text-zinc-300">FAQ</a>
                        <a href="/privacy" class="hover:text-zinc-300">${t('Privacy')}</a>
                        <a href="/terms" class="hover:text-zinc-300">${t('Terms')}</a>
                        <a href="/imprint" class="hover:text-zinc-300">${t('Imprint')}</a>
                        <a id="abuse-link" href="#" class="hover:text-zinc-300">${t('Report abuse')}</a>
                        <select id="lang-select" title="${t('Language')}" aria-label="${t('Language')}"
                            class="rounded border border-white/15 bg-[#12101c] px-1 py-0.5 text-xs text-zinc-400 hover:text-zinc-200 focus:outline-none">
                            ${Object.entries(LANGS).map(([code, name]) =>
                                `<option value="${code}" ${code === getLang() ? 'selected' : ''}>${name}</option>`).join('')}
                        </select>
                    </div>
                    <div class="flex items-center justify-end">
                        <span class="text-zinc-600">v${esc(APP_VERSION)}</span>
                    </div>
                </div>
            </aside>
            <main class="flex-1 min-w-0 flex flex-col" id="main"></main>
        </div>`;

    document.getElementById('btn-create').onclick = () => createChannelDialog();
    document.getElementById('btn-scan').onclick = scanQrDialog;
    document.getElementById('btn-list-settings').onclick = settingsDialog;
    document.getElementById('btn-profile').onclick = () => profileDialog();
    document.getElementById('btn-servers').onclick = () => serversDialog();
    const abuse = document.getElementById('abuse-link');
    abuse.href = `mailto:${window.KROTZE_ABUSE || 'abuse@krotze.com'}`
        + '?subject=' + encodeURIComponent('Abuse report — Krotze')
        + '&body=' + encodeURIComponent('Please describe the abuse and include the channel invite link or upload link if you have one:\n\n');
    document.getElementById('btn-close-sidebar').onclick = () => toggleSidebar(false);
    document.getElementById('sidebar-backdrop').onclick = () => toggleSidebar(false);
    // Changing the language rebuilds every rendered string — a reload is the
    // simplest way to make sure nothing keeps the old one.
    document.getElementById('lang-select').onchange = (e) => {
        setLang(e.target.value);
        location.reload();
    };
}

function renderProfileName() {
    const el = document.getElementById('profile-name');
    if (el) el.textContent = store.username || '';
}

function toggleSidebar(open) {
    store.sidebarOpen = open;
    document.getElementById('sidebar').classList.toggle('closed', !open);
    document.getElementById('sidebar-backdrop').classList.toggle('hidden', !open);
}

/**
 * Unread first — whatever is waiting for you sits on top, pinned or not —
 * then the pinned chats, then everything else by recent activity.
 */
function sortedChannels() {
    const score = (c) => (c.unread > 0 ? 2 : 0) + (c.pinned ? 1 : 0);
    return [...store.channels].sort((a, b) =>
        score(b) - score(a) ||
        new Date(b.last_activity_at || 0) - new Date(a.last_activity_at || 0));
}

/** Servers that need attention, at the top of the list. */
function renderServerStatusRows(list) {
    const troubled = store.servers.filter((s) => serverProblem(s) && !(s.home && s.status !== 'ok'));
    const badge = document.getElementById('servers-badge');
    if (badge) {
        badge.textContent = String(troubled.length);
        badge.classList.toggle('hidden', !troubled.length);
    }
    for (const s of troubled) {
        const row = document.createElement('button');
        row.className = 'flex w-full items-center gap-2 rounded-lg border border-amber-400/30 bg-amber-400/10 px-2 py-2 text-start text-xs text-amber-200';
        row.innerHTML = `
            ${serverChipHtml(s, { always: true })}
            <span class="min-w-0 flex-1 truncate">${esc(serverLabel(s))} — ${esc(serverProblem(s))}</span>`;
        row.onclick = () => serversDialog();
        list.appendChild(row);
    }
}

function renderChannelList() {
    const list = document.getElementById('channel-list');
    if (!list) return;
    list.innerHTML = '';
    renderServerStatusRows(list);
    const empty = (text) => list.insertAdjacentHTML('beforeend', `<p class="px-2 py-4 text-sm text-zinc-500">${text}</p>`);
    if (!store.channels.length) {
        empty(t('No channels yet. Create one or scan a QR code to join.'));
        return;
    }
    const showHidden = localStorage.getItem('krotze_show_hidden') === '1';
    const visible = sortedChannels().filter((c) => showHidden || !c.hidden);
    if (!visible.length) {
        empty(t('All chats are hidden. Enable “Show hidden chats” via ⚙️ above.'));
        return;
    }
    for (const c of visible) {
        const row = document.createElement('div');
        const active = c.key === store.current?.key;
        row.className = `group flex items-center gap-2 rounded-lg px-2 py-2 cursor-pointer text-sm
            ${active ? 'bg-violet-600/25 text-white' : 'text-zinc-300 hover:bg-white/5'} ${c.hidden ? 'opacity-60' : ''}`;
        // A muted channel still counts its unread messages, it just does not
        // shout about them — so its badge is grey rather than violet.
        const badge = c.unread
            ? `<span class="shrink-0 rounded-full px-1.5 text-xs font-bold ${c.muted ? 'bg-white/20 text-zinc-300' : 'bg-violet-500 text-white'}">${c.unread > 99 ? '99+' : c.unread}</span>`
            : '';
        row.innerHTML = `
            <span class="shrink-0">${c.type === 'private' ? '👤' : '#'}</span>
            ${serverChipHtml(c.server)}
            <span class="flex-1 min-w-0 truncate">${esc(c.name)}${c.status === 'pending' ? ` <span class="text-xs text-amber-400">${t('(waiting)')}</span>` : ''}</span>
            ${c.pinned ? `<span class="shrink-0 text-xs opacity-70" title="${t('Pinned')}">📌</span>` : ''}
            ${c.muted ? `<span class="shrink-0 text-xs opacity-70" title="${t('Muted')}">🔕</span>` : ''}
            ${c.pending_count ? `<span class="shrink-0 rounded-full bg-amber-500 px-1.5 text-xs font-bold text-black" title="${t('pending join requests')}">${c.pending_count}</span>` : ''}
            ${badge}
            <button data-act="settings" title="${t('Chat settings (or long-press)')}"
                class="shrink-0 px-1 text-xs opacity-40 hover:opacity-100">⋯</button>`;
        row.onclick = (e) => {
            if (suppressNextClick) { suppressNextClick = false; return; }
            if (e.target.dataset?.act === 'settings') {
                e.stopPropagation();
                channelSettingsDialog(c);
                return;
            }
            if (c.status === 'pending') return;
            openChannel(c.uuid, c.server);
            toggleSidebar(false);
        };
        attachLongPress(row, () => channelSettingsDialog(c));
        list.appendChild(row);
    }
}

/**
 * Long-press (touch) or right-click (desktop) on an element. Shared by the
 * message list's reply gesture and the channel list's settings gesture, so
 * both feel the same: ~half a second, cancelled by any real movement.
 */
function attachLongPress(el, run) {
    let timer = null;
    let start = null;
    const cancel = () => { clearTimeout(timer); timer = null; };

    el.addEventListener('pointerdown', (e) => {
        if (e.button > 0) return;
        start = { x: e.clientX, y: e.clientY };
        cancel();
        timer = setTimeout(() => {
            timer = null;
            suppressNextClick = true;
            run();
        }, 500);
    });
    el.addEventListener('pointerup', cancel);
    el.addEventListener('pointercancel', cancel);
    el.addEventListener('pointerleave', cancel);
    el.addEventListener('pointermove', (e) => {
        if (timer && start && Math.hypot(e.clientX - start.x, e.clientY - start.y) > 10) cancel();
    });
    el.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        cancel();
        run();
    });
}

async function togglePin(c) {
    try {
        await api(`/channels/${c.uuid}/pin`, { server: c.server, body: { pinned: !c.pinned } });
        c.pinned = !c.pinned;
        renderChannelList();
    } catch (e) { toastError(e); }
}

async function toggleHide(c) {
    try {
        await api(`/channels/${c.uuid}/hide`, { server: c.server, body: { hidden: !c.hidden } });
        c.hidden = !c.hidden;
        renderChannelList();
    } catch (e) { toastError(e); }
}

async function toggleMute(c) {
    try {
        await api(`/channels/${c.uuid}/mute`, { server: c.server, body: { muted: !c.muted } });
        c.muted = !c.muted;
        renderChannelList();
    } catch (e) { toastError(e); }
}

/** App-wide preferences: what the list shows and what may interrupt you. */
function settingsDialog() {
    const showHidden = localStorage.getItem('krotze_show_hidden') === '1';
    const n = notifySettings();
    const denied = 'Notification' in window && Notification.permission === 'denied';
    const granted = 'Notification' in window && Notification.permission === 'granted';

    const check = (id, on, label) => `
        <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-200">
            <input type="checkbox" id="${id}" ${on ? 'checked' : ''} class="accent-violet-500">
            <span>${label}</span>
        </label>`;

    // A notification belongs to the browser that created its subscription, so
    // one enabled in a tab opens that browser and never the installed app —
    // worth saying before somebody enables it in the wrong place.
    const tabWarning = (isMobile() && !isStandalone()) ? `
        <p class="mt-3 rounded-lg border border-amber-400/30 bg-amber-400/10 px-3 py-2 text-xs text-amber-200 break-words">
            ${t('You are using Krotze in a browser tab. Notifications turned on here will <b>open this browser</b>, not the installed app — a notification can only ever open the browser it was created in. For notifications that open the app, install Krotze (“Add to home screen”) and turn them on from inside it.')}
        </p>` : '';

    const m = modal(`
        <h2 class="text-lg font-bold text-white">${t('Settings')}</h2>

        <p class="mt-5 text-xs uppercase tracking-wide text-zinc-500">${t('Channel list')}</p>
        <div class="mt-2 space-y-2">
            ${check('ls-hidden', showHidden, t('Show hidden chats'))}
        </div>

        <p class="mt-6 text-xs uppercase tracking-wide text-zinc-500">${t('Notifications')}</p>
        <div class="mt-2 space-y-2">
            ${check('nf-enabled', n.enabled, `<b>${t('Notify me on this device')}</b>`)}
            <div class="space-y-2 border-s-2 border-white/10 ps-3 ${n.enabled ? '' : 'pointer-events-none opacity-40'}">
                ${check('nf-messages', n.messages, '💬 ' + t('New messages'))}
                ${check('nf-chat', n.chat_requests, '👤 ' + t('Private chat requests'))}
                ${check('nf-join', n.join_requests, '👋 ' + t('Join requests for my channels'))}
                ${check('nf-hide', n.hide_message_text, '🙈 ' + t('Hide message text in notifications'))}
            </div>
        </div>
        ${!n.enabled ? `
            <p class="mt-3 text-xs text-zinc-500">
                ${t('This browser stays signed in but is never notified. Other devices on your account are unaffected.')}
            </p>` : denied ? `
            <p class="mt-3 rounded-lg border border-amber-400/30 bg-amber-400/10 px-3 py-2 text-xs text-amber-300">
                ${t('Your browser blocks notifications for this site. Allow them in the site settings to see them.')}
            </p>` : granted ? `
            <p class="mt-3 text-xs text-zinc-500">
                ${store.pushActive
                    ? t('This device receives notifications even when Krotze is closed. What they say is encrypted for this device — the push service only relays it.')
                    : t('Notifications appear while Krotze runs in the background.')}
                ${t('Individual chats can be muted by long-pressing them in the list.')}
            </p>
            ${tabWarning}
            <button id="nf-test" class="mt-3 w-full rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">
                🔔 ${t('Send a test notification')}
            </button>` : `
            ${tabWarning}
            <button id="nf-enable" class="mt-3 w-full rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">
                🔔 ${t('Allow notifications on this device')}
            </button>`}

        <div class="mt-6 flex justify-end">
            <button data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Close')}</button>
        </div>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();
    m.querySelector('#ls-hidden').onchange = (e) => {
        localStorage.setItem('krotze_show_hidden', e.target.checked ? '1' : '0');
        renderChannelList();
    };
    m.querySelector('#nf-enabled').onchange = async (e) => {
        const on = e.target.checked;
        setNotifySetting('enabled', on);
        if (on) {
            if ('Notification' in window && Notification.permission === 'default') {
                try { await Notification.requestPermission(); } catch { /* unsupported */ }
            }
            await enablePush();
        } else {
            await disablePush();
        }
        m.remove();
        settingsDialog(); // redraw: the whole section reads differently either way
    };
    m.querySelector('#nf-messages').onchange = (e) => setNotifySetting('messages', e.target.checked);
    m.querySelector('#nf-chat').onchange = (e) => setNotifySetting('chat_requests', e.target.checked);
    m.querySelector('#nf-join').onchange = (e) => setNotifySetting('join_requests', e.target.checked);
    m.querySelector('#nf-hide').onchange = (e) => setNotifySetting('hide_message_text', e.target.checked);
    m.querySelector('#nf-enable')?.addEventListener('click', async () => {
        try { await Notification.requestPermission(); } catch { /* unsupported */ }
        await enablePush();
        m.remove();
        settingsDialog();
    });
    m.querySelector('#nf-test')?.addEventListener('click', async (e) => {
        // Without a push subscription there is nothing for the server to send
        // to, so fall back to raising one locally.
        if (!store.pushActive) {
            localNotify('Krotze', t('Notifications are working on this device.'));
            toastInfo(t('Test notification sent. It appears once Krotze is in the background.'));
            return;
        }
        e.target.disabled = true;
        try {
            await api('/push/test', { server: homeServer(), body: {} });
            toastInfo(t('Test notification sent.'));
        } catch (err) { toastError(err); }
        e.target.disabled = false;
    });
}

/* --------------------------- main content ------------------------- */

function renderEmpty() {
    document.getElementById('main').innerHTML = `
        <div class="flex items-center gap-2 border-b border-white/10 p-3 md:hidden">
            <button id="btn-menu" class="text-zinc-300 text-xl px-2">☰</button>
            <span class="font-semibold text-white">Krotze</span>
        </div>
        <div class="flex-1 flex flex-col items-center justify-center gap-4 p-6 text-center">
            <img src="/img/icon.svg" alt="" class="h-16 w-16 opacity-60">
            <p class="text-zinc-400 max-w-sm break-words">${t('Select a channel, create a new one, or scan a QR code to join a chat.')}</p>
        </div>`;
    document.getElementById('btn-menu').onclick = () => toggleSidebar(true);
}

/** A channel's detail, tagged with the server it came from. */
async function fetchDetail(uuid, server) {
    const d = await api(`/channels/${uuid}`, { server });
    d.server = server;
    d.key = chanKey(server, uuid);
    return d;
}

async function openChannel(uuid, server = null) {
    server ||= findChannelByUuid(uuid)?.server || store.server || homeServer();
    const cur = { server, uuid, key: chanKey(server, uuid) };
    store.current = cur;
    store.server = server;
    store.messages = [];
    store.lastMessageId = 0;
    store.replyTo = null;
    store.membersOpen = false;
    store.stickToBottom = true;
    setLastChannel(server, uuid);
    renderChannelList();
    renderProfileName(); // the profile button follows the active server
    try {
        const detail = await fetchDetail(uuid, server);
        if (store.current?.key !== cur.key) return; // the user moved on meanwhile
        store.detail = detail;
    } catch {
        if (store.current?.key === cur.key) closeChannel();
        return;
    }
    renderChannelView();
    await loadMessages(true);
}

function channelHeaderHtml(d) {
    const pendingCount = d.is_owner ? d.members.filter((m) => m.status === 'pending').length : 0;
    const memberCount = d.members.filter((m) => m.status === 'approved').length;
    const chip = serverChipHtml(d.server);
    const where = chip ? `${chip} ${esc(serverLabel(d.server))} · ` : '';
    return `
        <button id="btn-menu" class="text-zinc-300 text-xl px-2 md:hidden">☰</button>
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-white truncate">${d.type === 'private' ? '👤 ' : '# '}${esc(d.name)}</p>
            <p class="text-xs text-zinc-500 truncate">${where}${d.type === 'private'
                ? `${t('private conversation')}${e2eeReady(d) ? ' · 🔒 ' + t('end-to-end encrypted') : ''}`
                : `${t('you are :name', { name: esc(d.server?.state.username || '') })}${d.is_owner ? ' · ' + t('owner') : ''}`}</p>
        </div>
        ${pendingCount ? `<button id="btn-requests" title="${t('Open join requests')}" class="shrink-0 rounded-lg border border-amber-400/40 px-3 py-1.5 text-xs text-amber-300 hover:bg-amber-400/10">👋 ${pendingCount}</button>` : ''}
        ${d.type === 'group' ? `<button id="btn-members" title="${t('Members')}" class="shrink-0 rounded-lg border border-white/15 px-3 py-1.5 text-xs text-zinc-300 hover:bg-white/10">👥 ${memberCount}</button>` : ''}
        ${d.is_owner && d.invite_url ? `<button id="btn-qr" class="shrink-0 rounded-lg border border-white/15 px-3 py-1.5 text-xs text-zinc-300 hover:bg-white/10">QR</button>` : ''}
        <button id="btn-chan-menu" class="shrink-0 rounded-lg border border-white/15 px-3 py-1.5 text-xs text-zinc-300 hover:bg-white/10">⋯</button>`;
}

/** Header only — leaves the message list, draft and scroll position alone. */
function renderChannelHeader() {
    const host = document.getElementById('chan-header');
    if (!host || !store.detail) return;
    const d = store.detail;
    host.innerHTML = channelHeaderHtml(d);
    document.getElementById('btn-menu')?.addEventListener('click', () => toggleSidebar(true));
    document.getElementById('btn-requests')?.addEventListener('click', () => pendingRequestsDialog(store.detail));
    document.getElementById('btn-members')?.addEventListener('click', toggleMembersPanel);
    document.getElementById('btn-qr')?.addEventListener('click', () => qrDialog(store.detail));
    // Same modal as long-pressing the chat in the list; the list entry carries
    // the pin/mute/hide state, the detail carries ownership.
    document.getElementById('btn-chan-menu').onclick = () =>
        channelSettingsDialog(store.channels.find((c) => c.key === d.key) || d);
}

function renderChannelView() {
    const d = store.detail;
    document.getElementById('main').innerHTML = `
        <div id="chan-header" class="flex items-center gap-2 border-b border-white/10 p-3"></div>
        <div id="members-panel" class="hidden border-b border-white/10 bg-[#141221] max-h-56 overflow-y-auto"></div>
        <div id="messages" class="flex-1 min-h-0 overflow-y-auto p-4 space-y-3"></div>
        <div id="reply-bar" class="hidden border-t border-white/10 px-3 pt-2"></div>
        <form id="composer" class="border-t border-white/10 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] flex items-end gap-2">
            ${uploadAccept(d) ? `
            <label id="attach-label" class="cursor-pointer rounded-lg border border-white/15 px-3 py-2 text-zinc-300 hover:bg-white/10 shrink-0" title="${t('Upload a file')}">
                📎<input id="file-input" type="file" class="hidden" accept="${uploadAccept(d)}">
            </label>` : ''}
            <textarea id="msg-input" rows="1" placeholder="${t('Message…')}" maxlength="5000"
                class="flex-1 min-w-0 resize-none rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm text-white focus:border-violet-500 focus:outline-none"></textarea>
            <button class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500 shrink-0">${t('Send')}</button>
        </form>`;

    renderChannelHeader();

    const input = document.getElementById('msg-input');
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            document.getElementById('composer').requestSubmit();
        }
    });
    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 140) + 'px';
    });
    // Opening the keyboard shrinks the viewport; keep the latest messages in view.
    input.addEventListener('focus', () => {
        store.stickToBottom = true;
        setTimeout(scrollMessagesToBottom, 300);
    });
    document.getElementById('composer').onsubmit = sendMessage;
    const fileInput = document.getElementById('file-input');
    if (fileInput) fileInput.onchange = sendFile;
    document.getElementById('attach-label')?.addEventListener('click', (e) => {
        if (localStorage.getItem('krotze_attach_info_seen')) return;
        e.preventDefault();
        attachInfoDialog();
    });
    renderReplyBar();
}

function uploadAccept(d) {
    const acc = [];
    if (d.allow_images) acc.push('image/*');
    if (d.allow_audio) acc.push('audio/*');
    if (d.allow_videos) acc.push('video/*');
    if (d.allow_zip) acc.push('.zip', 'application/zip');
    return acc.join(',');
}

function attachInfoDialog() {
    const m = modal(`
        <h2 class="text-lg font-bold text-white">${t('Attaching files')}</h2>
        <p class="mt-2 text-sm text-zinc-400">
            ${t('Your device will now show a file picker. Depending on what you choose there, it may ask for permission to use your <b>camera or microphone</b> (to take a photo or record a video or audio message) or to <b>access your files</b>.')}
        </p>
        <p class="mt-2 text-sm text-zinc-400">
            ${t('You can allow that once, or deny it — nothing on your device is accessed without your consent.')}
        </p>
        <div class="mt-6 flex justify-end gap-3">
            <button data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Cancel')}</button>
            <button id="attach-continue" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">${t('Continue')}</button>
        </div>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();
    m.querySelector('#attach-continue').onclick = () => {
        localStorage.setItem('krotze_attach_info_seen', '1');
        m.remove();
        document.getElementById('file-input')?.click();
    };
}

function toggleMembersPanel() {
    store.membersOpen = !store.membersOpen;
    const panel = document.getElementById('members-panel');
    panel.classList.toggle('hidden', !store.membersOpen);
    if (store.membersOpen) renderMembersPanel();
}

function renderMembersPanel() {
    const d = store.detail;
    const panel = document.getElementById('members-panel');
    if (!panel) return;
    panel.innerHTML = '';
    for (const m of d.members) {
        const row = document.createElement('button');
        row.className = 'w-full flex items-center gap-2 px-4 py-2 text-sm text-start hover:bg-white/5';
        row.innerHTML = `
            <span>${m.is_owner ? '👑' : '👤'}</span>
            <span class="flex-1 min-w-0 truncate text-zinc-200">${esc(m.username)}${m.is_me ? ` <span class="text-zinc-500">${t('(you)')}</span>` : ''}</span>
            ${m.status === 'pending' ? `<span class="text-xs text-amber-400">${t('pending')}</span>` : ''}`;
        row.onclick = () => memberDialog(m);
        panel.appendChild(row);
    }
}

function memberDialog(m) {
    const d = store.detail;
    if (m.is_me) return;

    if (m.status === 'pending' && d.is_owner) {
        const mm = modal(`
            <h2 class="text-lg font-bold text-white break-words">${t('“:name” wants to join', { name: esc(m.username) })}</h2>
            <div class="mt-5 flex justify-end gap-3">
                <button id="m-deny" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500">${t('Deny')}</button>
                <button id="m-ok" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">${t('Approve')}</button>
            </div>`);
        const decide = async (approve) => {
            mm.remove();
            try { await api(`/members/${m.id}/decision`, { server: d.server, body: { approve } }); } catch (e) { toastError(e); }
            await reloadChannelDetail();
            refreshState({ only: d.server });
        };
        mm.querySelector('#m-ok').onclick = () => decide(true);
        mm.querySelector('#m-deny').onclick = () => decide(false);
        return;
    }

    const mm = modal(`
        <h2 class="text-lg font-bold text-white break-words">${esc(m.username)}</h2>
        <div class="mt-5 space-y-2">
            <button id="m-private" class="w-full rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">💬 ${t('Start private chat')}</button>
            ${d.is_owner && d.type === 'group' ? `
                <button id="m-transfer" class="w-full rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">👑 ${t('Transfer channel ownership')}</button>
                <button id="m-remove" class="w-full rounded-lg border border-red-500/40 px-4 py-2 text-sm text-red-400 hover:bg-red-500/10">${t('Remove from channel')}</button>` : ''}
            <button data-cancel class="w-full rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Cancel')}</button>
        </div>`);
    mm.querySelector('[data-cancel]').onclick = () => mm.remove();

    mm.querySelector('#m-private').onclick = async () => {
        mm.remove();
        try {
            const res = await api(`/channels/${d.uuid}/private`, { server: d.server, body: { member_id: m.id } });
            await refreshState({ only: d.server });
            if (res.existing) openChannel(res.uuid, d.server);
            else toastInfo(t('Private chat request sent to :name. Waiting for them to accept.', { name: m.username }));
        } catch (e) { toastError(e); }
    };

    mm.querySelector('#m-transfer')?.addEventListener('click', async () => {
        mm.remove();
        const ok = await confirmDialog({
            title: t('Transfer ownership?'),
            text: t('Transfer ownership of “:channel” to “:name”? You will lose ownership and all owner rights (invites, member approval, settings, destroy). You cannot undo this yourself.', { channel: d.name, name: m.username }),
            confirmLabel: t('Transfer ownership'),
        });
        if (!ok) return;
        try {
            await api(`/channels/${d.uuid}/transfer`, { server: d.server, body: { member_id: m.id } });
            await openChannel(d.uuid, d.server);
            refreshState({ only: d.server });
        } catch (e) { toastError(e); }
    });

    mm.querySelector('#m-remove')?.addEventListener('click', async () => {
        mm.remove();
        const res = await confirmDialog({
            title: t('Remove “:name”?', { name: m.username }),
            text: t('This member will be removed from the channel.'),
            confirmLabel: t('Remove member'),
            checkbox: t('Delete all files of this user in this channel?'),
        });
        if (!res) return;
        try {
            await api(`/channels/${d.uuid}/members/${m.id}?delete_files=${res.checked ? 1 : 0}`, { method: 'DELETE', server: d.server });
            await reloadChannelDetail();
            await loadMessages(true);
        } catch (e) { toastError(e); }
    });
}

/* ------------------------------ E2EE ------------------------------- */

/**
 * Make sure a server knows this device's box public key, so conversation
 * partners can wrap message keys for it. The keypair is one per device and
 * registered on every server; each remembers what it already sent (re-sent
 * when the local key had to be regenerated, which self-heals the registration).
 */
async function ensureBoxKey(server = homeServer()) {
    if (!usableServer(server)) return;
    const pub = boxPublicKey();
    if (server.boxkeySent === pub) return;
    try {
        await api('/profile/devices/key', { server, body: { public_key: pub } });
        server.boxkeySent = pub;
        saveServers();
    } catch { /* retried on the next boot */ }
}

/** True when every participant of this private conversation can decrypt. */
function e2eeReady(d) {
    if (d?.type !== 'private' || !Array.isArray(d.devices) || !d.devices.length) return false;
    const me = (d.server || store.server)?.state.userId;
    // Both sides need at least one keyed device — mine to read my own copy,
    // theirs so the message is not write-only (a partner on an old client
    // simply keeps getting plaintext until they update).
    return d.devices.some((x) => x.user_id === me)
        && d.devices.some((x) => x.user_id !== me);
}

/** Plaintext of a message — decrypts E2EE envelopes once and caches. */
function plaintextOf(m) {
    if (!m.encrypted) return m.body;
    if (m._plain === undefined) {
        const identity = store.current?.server.identity;
        m._plain = identity ? decryptEnvelope(m.body, identity.id) : null;
    }
    return m._plain;
}

/* ---------------------------- messages ---------------------------- */

async function loadMessages(initial = false) {
    if (!store.current) return;
    const cur = store.current;
    try {
        const data = await api(`/channels/${cur.uuid}/messages?after=${initial ? 0 : store.lastMessageId}`, { server: cur.server });
        if (cur.key !== store.current?.key) return;
        for (const del of data.deletions || []) flashDeleted(del.id, del.by);
        if (initial) store.messages = data.messages;
        else if (data.messages.length) store.messages.push(...data.messages);
        else return;
        if (store.messages.length) {
            store.lastMessageId = store.messages[store.messages.length - 1].id;
            api(`/channels/${cur.uuid}/read`, { server: cur.server, body: { message_id: store.lastMessageId } }).catch(() => {});
            const ch = store.channels.find((c) => c.key === cur.key);
            if (ch && ch.unread) { ch.unread = 0; renderChannelList(); }
        }
        renderMessages();
    } catch { /* poll errors are transient */ }
}

function messageHtml(m) {
    const mine = m.user_id === store.userId;
    let content = '';
    if (m.kind === 'text') {
        const text = plaintextOf(m);
        content = text !== null
            ? `<p class="whitespace-pre-wrap break-words text-sm">${esc(text)}</p>`
            : `<p class="text-sm italic text-zinc-400">🔒 ${t('This message cannot be decrypted on this device.')}</p>`;
    } else if (m._flashDeleted) {
        content = `<p class="text-sm italic text-amber-300 animate-pulse">🗑 ${t('Deleted by :name', { name: esc(m._flashDeleted) })}</p>`;
    } else if (uploadExpired(m)) {
        content = `<p class="text-sm italic text-zinc-400">⏱ ${esc(m.file_name || t('Upload'))} — ${t('no longer available')}</p>`;
    } else {
        const mayDeleteFile = !store.detail?.restrict_delete || mine || store.detail?.is_owner;
        const x = mayDeleteFile ? `<button data-del-file="${m.id}" title="${t('Delete this file for everyone')}"
            class="absolute -top-2 -end-2 h-6 w-6 rounded-full bg-black/80 text-white text-xs border border-white/30 hover:bg-red-600 z-10">×</button>` : '';
        // No countdown at all when the server neither expires uploads nor
        // limits how long an opened one stays readable.
        const days = store.uploadRetentionDays;
        const mins = store.uploadViewMinutes;
        const hint = [
            days ? t(days === 1 ? 'Uploads disappear after :n day' : 'Uploads disappear after :n days', { n: days }) : '',
            mins ? t('once opened, after :n min', { n: mins }) : '',
        ].filter(Boolean).join('; ');
        const timer = (m.file_expires_at || m.view_expires_at)
            ? `<p class="mt-1 text-[11px] text-zinc-400" title="${esc(hint)}">
                ⏱ <span data-upload-timer="${m.id}"></span></p>`
            : '';
        if (m.kind === 'image') {
            const media = m.thumb_url
                ? `<img src="${esc(m.thumb_url)}" alt="${esc(m.file_name)}" data-lightbox="${esc(m.file_url)}" loading="lazy"
                       class="max-h-48 max-w-full rounded-lg cursor-zoom-in object-contain">`
                : `<button data-lightbox="${esc(m.file_url)}"
                       class="flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm hover:bg-white/10">
                       🖼 <span class="break-all min-w-0">${esc(m.file_name)}</span></button>`;
            content = `<div class="relative inline-block max-w-full">${x}${media}${timer}</div>`;
        } else if (m.kind === 'audio') {
            content = `<div class="relative inline-block max-w-full">${x}
                <audio controls preload="none" src="${esc(m.file_url)}" class="max-w-full w-64"></audio>
                <p class="text-xs text-zinc-400 mt-1 break-all">${esc(m.file_name)} · ${fmtSize(m.file_size)}</p>${timer}</div>`;
        } else if (m.kind === 'video') {
            content = `<div class="relative inline-block max-w-full">${x}
                <video controls preload="none" ${m.thumb_url ? `poster="${esc(m.thumb_url)}"` : ''} src="${esc(m.file_url)}" class="max-h-64 max-w-full rounded-lg"></video>
                <p class="text-xs text-zinc-400 mt-1 break-all">${esc(m.file_name)} · ${fmtSize(m.file_size)}</p>${timer}</div>`;
        } else {
            // A cross-origin download attribute is ignored by browsers, so a
            // file on another server opens in a new tab instead.
            const foreign = store.current?.server && !store.current.server.home;
            content = `<div class="relative inline-block max-w-full">${x}
                <a href="${esc(m.file_url)}" download="${esc(m.file_name)}" ${foreign ? 'target="_blank" rel="noopener"' : ''}
                   class="flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm hover:bg-white/10">
                   🗜 <span class="break-all min-w-0">${esc(m.file_name)}</span>
                   <span class="text-xs text-zinc-400 shrink-0">${fmtSize(m.file_size)}</span></a>${timer}</div>`;
        }
    }
    const q = m.reply_to;
    const quote = q ? `
        <button data-jump="${q.id}" class="mb-1 block w-full max-w-full rounded-md border-s-2 border-violet-300/70 bg-black/25 px-2 py-1 text-start">
            <span class="block truncate text-[11px] font-semibold ${mine ? 'text-violet-200' : 'text-violet-300'}">↩ ${esc(q.username || t('Deleted message'))}</span>
            ${q.username ? `<span class="block truncate text-[11px] ${mine ? 'text-white/70' : 'text-zinc-400'}">${esc(quoteExcerpt(q))}</span>` : ''}
        </button>` : '';
    return `
        <div class="flex ${mine ? 'justify-end' : 'justify-start'}" data-mid="${m.id}">
            <div class="group max-w-[85%] sm:max-w-[70%] min-w-0">
                <p class="text-xs text-zinc-500 mb-0.5 ${mine ? 'text-end' : ''}">
                    ${mine ? '' : esc(m.username) + ' · '}${fmtTime(m.created_at)}
                    ${(mine || store.detail?.is_owner) ? `<button data-del-msg="${m.id}" title="${t('Delete message')}" class="ms-1 opacity-40 hover:opacity-100 text-red-400">🗑</button>` : ''}
                </p>
                <div class="rounded-2xl px-3 py-2 ${mine ? 'bg-violet-600/80 text-white' : 'bg-white/10 text-zinc-100'} break-words overflow-hidden select-none [-webkit-touch-callout:none]">
                    ${quote}
                    ${content}
                </div>
            </div>
        </div>`;
}

const KIND_ICON = { image: '🖼', audio: '🎵', video: '🎬', zip: '🗜' };

function quoteExcerpt(q) {
    if (q.encrypted && q.excerpt == null) return '🔒 ' + t('Encrypted message');
    return q.kind === 'text' ? (q.excerpt || '') : `${KIND_ICON[q.kind] || '📎'} ${q.excerpt || ''}`;
}

/* ---------- ephemeral uploads (server retention / 5 min view) ------ */

function uploadExpired(m) {
    if (m.kind === 'text') return false;
    if (!m.file_url && !m.thumb_url) return true; // server already withdrew access
    if (m.view_expires_at && Date.parse(m.view_expires_at) <= Date.now()) return true;
    if (m.file_expires_at && Date.parse(m.file_expires_at) <= Date.now()) return true;
    return false;
}

function fmtCountdown(ms) {
    const s = Math.max(0, Math.floor(ms / 1000));
    if (s >= 3600) return `${Math.floor(s / 3600)}h ${Math.floor((s % 3600) / 60)}m`;
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

function updateUploadTimers() {
    for (const m of store.messages) {
        if (m.kind === 'text' || uploadExpired(m)) continue;
        if (!m.file_expires_at && !m.view_expires_at) continue;
        const el = document.querySelector(`[data-upload-timer="${m.id}"]`);
        if (!el) continue;
        const end = m.view_expires_at ? Date.parse(m.view_expires_at) : Date.parse(m.file_expires_at);
        el.textContent = fmtCountdown(end - Date.now());
    }
}

/** Show "deleted by X" at the message's position for a moment, then drop it. */
function flashDeleted(id, by) {
    const m = store.messages.find((x) => x.id === id);
    if (!m || m._flashDeleted) return;
    m._flashDeleted = by || t('a member');
    m.file_url = null;
    m.thumb_url = null;
    renderMessages();
    setTimeout(() => {
        store.messages = store.messages.filter((x) => x.id !== id);
        renderMessages();
    }, 4000);
}

/** First access to the full file: the server's view window starts now. */
function markUploadViewed(messageId) {
    const m = store.messages.find((x) => x.id === messageId);
    if (!m || m.kind === 'text' || m.view_expires_at || !store.uploadViewMinutes) return;
    m.view_expires_at = new Date(Date.now() + store.uploadViewMinutes * 60 * 1000).toISOString();
    updateUploadTimers();
}

setInterval(() => {
    if (!store.messages.length) return;
    let changed = false;
    for (const m of store.messages) {
        if (m.kind === 'text' || m._expiredShown) continue;
        if (uploadExpired(m)) {
            m._expiredShown = true;
            changed = true;
        }
    }
    if (changed) renderMessages();
    else updateUploadTimers();
}, 1000);

function renderMessages() {
    const box = document.getElementById('messages');
    if (!box) return;
    box.innerHTML = store.messages.map(messageHtml).join('')
        || `<p class="text-center text-sm text-zinc-500 py-8">${t('No messages yet — say hi!')}</p>`;
    updateUploadTimers();
    if (store.stickToBottom) box.scrollTop = box.scrollHeight;
    // Scrolling away pauses the auto-follow; scrolling back resumes it. The
    // keyboard handler reads the same flag, so an opening keyboard keeps the
    // newest messages visible instead of hiding them behind itself.
    box.onscroll = () => {
        store.stickToBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 120;
    };
    // Long-press (touch or mouse) starts a reply to that message.
    box.onpointerdown = (e) => {
        const row = e.target.closest('[data-mid]');
        if (!row || e.button > 0 || e.target.closest('button, a, video, audio')) return;
        lpStart = { x: e.clientX, y: e.clientY };
        clearTimeout(lpTimer);
        lpTimer = setTimeout(() => {
            lpTimer = null;
            suppressNextClick = true;
            startReply(Number(row.dataset.mid));
        }, 500);
    };
    box.onpointerup = box.onpointercancel = () => { clearTimeout(lpTimer); lpTimer = null; };
    box.onpointermove = (e) => {
        if (lpTimer && lpStart && Math.hypot(e.clientX - lpStart.x, e.clientY - lpStart.y) > 10) {
            clearTimeout(lpTimer);
            lpTimer = null;
        }
    };
    box.oncontextmenu = (e) => {
        const row = e.target.closest('[data-mid]');
        if (!row) return;
        e.preventDefault();
        clearTimeout(lpTimer);
        lpTimer = null;
        startReply(Number(row.dataset.mid));
    };
    // Starting playback fetches the full file — that's the first "view".
    if (!box.dataset.playHooked) {
        box.dataset.playHooked = '1';
        box.addEventListener('play', (e) => {
            const row = e.target.closest?.('[data-mid]');
            if (row) markUploadViewed(Number(row.dataset.mid));
        }, true);
    }
    box.onclick = async (e) => {
        if (suppressNextClick) { suppressNextClick = false; return; }
        const jump = e.target.closest('[data-jump]');
        if (jump) { jumpToMessage(Number(jump.dataset.jump)); return; }
        const lbEl = e.target.closest('[data-lightbox]');
        if (lbEl) {
            const row = lbEl.closest('[data-mid]');
            if (row) markUploadViewed(Number(row.dataset.mid));
            lightbox(lbEl.dataset.lightbox);
            return;
        }
        const dl = e.target.closest('a[download]');
        if (dl) {
            const row = dl.closest('[data-mid]');
            if (row) markUploadViewed(Number(row.dataset.mid));
            return; // let the download proceed
        }
        const delFile = e.target.dataset?.delFile;
        if (delFile) {
            const ok = await confirmDialog({
                title: t('Delete this file?'),
                text: t('The file and its message are removed for everyone. Your name is shown briefly as the deleter.'),
                confirmLabel: t('Delete file'),
            });
            if (!ok) return;
            try {
                const cur = store.current;
                const res = await api(`/channels/${cur.uuid}/messages/${delFile}/delete-file`, { server: cur.server, body: {} });
                flashDeleted(Number(delFile), res.deleted_by);
            } catch (err) { toastError(err); }
            return;
        }
        const delMsg = e.target.dataset?.delMsg;
        if (delMsg) {
            const ok = await confirmDialog({ title: t('Delete this message?'), text: t('It will be removed for everyone.'), confirmLabel: t('Delete') });
            if (!ok) return;
            try {
                const cur = store.current;
                await api(`/channels/${cur.uuid}/messages/${delMsg}`, { method: 'DELETE', server: cur.server });
                store.messages = store.messages.filter((x) => x.id !== Number(delMsg));
                renderMessages();
            } catch (err) { toastError(err); }
        }
    };
}

/* --------------------------- replies ------------------------------ */

let lpTimer = null;
let lpStart = null;
let suppressNextClick = false;

function startReply(messageId) {
    const m = store.messages.find((x) => x.id === messageId);
    if (!m) return;
    store.replyTo = m;
    renderReplyBar();
    document.getElementById('msg-input')?.focus();
}

function renderReplyBar() {
    const bar = document.getElementById('reply-bar');
    if (!bar) return;
    const m = store.replyTo;
    if (!m) {
        bar.classList.add('hidden');
        bar.innerHTML = '';
        return;
    }
    bar.classList.remove('hidden');
    bar.innerHTML = `
        <div class="flex items-center gap-2 rounded-lg border-s-2 border-violet-400 bg-white/5 px-3 py-1.5 min-w-0">
            <div class="min-w-0 flex-1">
                <p class="truncate text-xs font-semibold text-violet-300">↩ ${esc(m.username)}</p>
                <p class="truncate text-xs text-zinc-400">${esc(quoteExcerpt({
                    kind: m.kind,
                    excerpt: m.kind === 'text' ? (plaintextOf(m) || '').slice(0, 90) : m.file_name,
                }))}</p>
            </div>
            <button id="reply-cancel" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-zinc-400 hover:bg-white/10 hover:text-white">✕</button>
        </div>`;
    document.getElementById('reply-cancel').onclick = () => {
        store.replyTo = null;
        renderReplyBar();
    };
}

function jumpToMessage(id) {
    const el = document.querySelector(`#messages [data-mid="${id}"]`);
    if (!el) {
        toastInfo(t('That message is not loaded anymore.'));
        return;
    }
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add('animate-pulse');
    setTimeout(() => el.classList.remove('animate-pulse'), 1300);
}

function lightbox(url) {
    const wrap = document.createElement('div');
    wrap.className = 'fixed inset-0 z-[70] bg-black/95 overflow-hidden touch-none flex items-center justify-center';
    wrap.innerHTML = `
        <img src="${esc(url)}" draggable="false"
             class="max-h-full max-w-full object-contain select-none [-webkit-touch-callout:none] will-change-transform">
        <button data-lb-close
            class="absolute top-3 end-3 z-10 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-xl text-white hover:bg-white/20">✕</button>`;
    const img = wrap.querySelector('img');

    let scale = 1, tx = 0, ty = 0;
    const pointers = new Map();
    let pinch = null;   // { dist, scale } at pinch start
    let pan = null;     // { x, y, tx, ty } at drag start
    let moved = false;
    let lastTap = 0;

    const apply = () => { img.style.transform = `translate(${tx}px, ${ty}px) scale(${scale})`; };
    const zoomAt = (p, next) => {
        next = Math.min(Math.max(next, 1), 8);
        const cx = wrap.clientWidth / 2, cy = wrap.clientHeight / 2;
        tx = (p.x - cx) - (next / scale) * ((p.x - cx) - tx);
        ty = (p.y - cy) - (next / scale) * ((p.y - cy) - ty);
        scale = next;
        if (scale === 1) { tx = 0; ty = 0; }
        apply();
    };

    wrap.addEventListener('pointerdown', (e) => {
        if (e.target.closest('[data-lb-close]')) return;
        wrap.setPointerCapture(e.pointerId);
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        moved = false;
        if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            pinch = { dist: Math.hypot(a.x - b.x, a.y - b.y), scale };
            pan = null;
        } else if (pointers.size === 1) {
            pan = { x: e.clientX, y: e.clientY, tx, ty };
        }
    });
    wrap.addEventListener('pointermove', (e) => {
        if (!pointers.has(e.pointerId)) return;
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        if (pinch && pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            const mid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
            zoomAt(mid, pinch.scale * (Math.hypot(a.x - b.x, a.y - b.y) / pinch.dist));
            moved = true;
        } else if (pan && pointers.size === 1 && scale > 1) {
            tx = pan.tx + (e.clientX - pan.x);
            ty = pan.ty + (e.clientY - pan.y);
            if (Math.hypot(e.clientX - pan.x, e.clientY - pan.y) > 5) moved = true;
            apply();
        }
    });
    const up = (e) => {
        if (!pointers.delete(e.pointerId)) return;
        if (pointers.size === 1) {
            pinch = null;
            const [p] = pointers.values();
            pan = { x: p.x, y: p.y, tx, ty };
        } else if (pointers.size === 0) {
            pinch = null;
            pan = null;
            if (moved) return;
            const now = Date.now();
            if (now - lastTap < 300) {
                lastTap = 0;
                zoomAt({ x: e.clientX, y: e.clientY }, scale > 1 ? 1 : 2.5);
            } else {
                lastTap = now;
                // Single tap closes (when not zoomed) — delayed so a double-tap can cancel it.
                if (scale === 1) {
                    setTimeout(() => {
                        if (Date.now() - lastTap >= 300 && scale === 1 && wrap.isConnected) wrap.remove();
                    }, 320);
                }
            }
        }
    };
    wrap.addEventListener('pointerup', up);
    wrap.addEventListener('pointercancel', up);
    wrap.addEventListener('wheel', (e) => {
        e.preventDefault();
        zoomAt({ x: e.clientX, y: e.clientY }, scale * (e.deltaY < 0 ? 1.2 : 1 / 1.2));
    }, { passive: false });
    wrap.querySelector('[data-lb-close]').onclick = () => wrap.remove();

    document.body.appendChild(wrap);
}

async function sendMessage(e) {
    e.preventDefault();
    const input = document.getElementById('msg-input');
    const body = input.value.trim();
    if (!body) return;
    input.value = '';
    input.style.height = 'auto';
    const payload = { body };
    if (store.replyTo) payload.reply_to = store.replyTo.id;
    // Private conversations go out end-to-end encrypted whenever both sides
    // have a keyed device; the server only ever sees the envelope.
    const encrypt = e2eeReady(store.detail);
    if (encrypt) {
        payload.body = encryptFor(body, store.detail.devices);
        payload.encrypted = true;
    }
    try {
        const cur = store.current;
        const res = await api(`/channels/${cur.uuid}/messages`, { server: cur.server, body: payload });
        if (cur.key !== store.current?.key) return;
        store.replyTo = null;
        renderReplyBar();
        if (encrypt) res.message._plain = body; // render own message instantly
        store.messages.push(res.message);
        store.lastMessageId = res.message.id;
        renderMessages();
    } catch (err) { toastError(err); }
}

async function sendFile(e) {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file) return;
    if (file.size > 50 * 1048576) { toastError({ message: t('Maximum file size is 50 MB.') }); return; }
    const fd = new FormData();
    fd.append('file', file);
    if (store.replyTo) fd.append('reply_to', store.replyTo.id);
    if (file.type.startsWith('video/')) {
        // No ffmpeg on the server — capture the poster frame here.
        const poster = await videoPoster(file).catch(() => null);
        if (poster) fd.append('thumb', poster, 'poster.jpg');
    }
    toastInfo(t('Uploading :name…', { name: file.name }));
    try {
        const cur = store.current;
        const res = await api(`/channels/${cur.uuid}/messages`, { server: cur.server, body: fd });
        if (cur.key !== store.current?.key) return;
        store.replyTo = null;
        renderReplyBar();
        store.messages.push(res.message);
        store.lastMessageId = res.message.id;
        renderMessages();
    } catch (err) { toastError(err); }
}

/** Grab a frame from a local video file as a JPEG blob (null on failure). */
function videoPoster(file, max = 480) {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const v = document.createElement('video');
        let settled = false;
        const done = (blob) => {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            URL.revokeObjectURL(url);
            v.removeAttribute('src');
            resolve(blob || null);
        };
        const timer = setTimeout(() => done(null), 8000);
        v.muted = true;
        v.playsInline = true;
        v.preload = 'auto';
        v.onerror = () => done(null);
        v.onloadeddata = () => {
            try { v.currentTime = Math.min(1, (v.duration || 2) / 2); } catch { done(null); }
        };
        v.onseeked = () => {
            try {
                const scale = Math.min(1, max / Math.max(v.videoWidth || 1, v.videoHeight || 1));
                const c = document.createElement('canvas');
                c.width = Math.max(1, Math.round(v.videoWidth * scale));
                c.height = Math.max(1, Math.round(v.videoHeight * scale));
                c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
                c.toBlob((b) => done(b), 'image/jpeg', 0.8);
            } catch { done(null); }
        };
        v.src = url;
    });
}

/* -------------------------- channel dialogs ------------------------ */

async function createChannelDialog(server = store.server) {
    if (!usableServer(server)) server = store.servers.find(usableServer) || server;
    if (!(await requireUsername(server))) return;
    const choices = store.servers.filter(usableServer);
    const m = modal(`
        <h2 class="text-lg font-bold text-white">${t('Create channel')}</h2>
        <form id="cc-form" class="mt-4 space-y-3">
            ${choices.length > 1 ? `
            <div>
                <label class="block text-sm text-zinc-400">${t('Create on')}</label>
                <select id="cc-server" class="mt-1 w-full rounded-lg border border-white/15 bg-[#171522] px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
                    ${choices.map((s) => `<option value="${s.id}" ${s === server ? 'selected' : ''}>${esc(serverLabel(s))} · ${esc(s.suffix)}</option>`).join('')}
                </select>
            </div>` : ''}
            <div>
                <label class="block text-sm text-zinc-400">${t('Channel name')}</label>
                <input name="name" required maxlength="60"
                    class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
            </div>
            <p id="cc-as" class="text-xs text-zinc-500">${t('You will appear as :name.', { name: `<b class="text-zinc-300">${esc(server.state.username || '')}</b>` })}</p>
            <div>
                <label class="block text-sm text-zinc-400">${t('Auto-delete after inactivity (days, 1–365)')}</label>
                <input name="retention_days" type="number" min="1" max="365" value="7"
                    class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
            </div>
            <label class="flex cursor-pointer items-start gap-2 text-sm text-zinc-200">
                <input type="checkbox" name="join_open" class="mt-0.5 accent-violet-500">
                <span>${t('Open join — anyone with the invite link joins instantly, no approval. Needed for embedding the chat on a website; uploads start disabled.')}</span>
            </label>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Cancel')}</button>
                <button class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">${t('Create')}</button>
            </div>
        </form>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();
    const select = m.querySelector('#cc-server');
    if (select) {
        select.onchange = async () => {
            const picked = serverById(select.value);
            if (!(await requireUsername(picked))) { select.value = server.id; return; }
            server = picked;
            m.querySelector('#cc-as').innerHTML = t('You will appear as :name.', { name: `<b class="text-zinc-300">${esc(server.state.username || '')}</b>` });
        };
    }
    m.querySelector('#cc-form').onsubmit = async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        try {
            const res = await api('/channels', { server, body: {
                name: fd.get('name'),
                retention_days: Number(fd.get('retention_days')) || 7,
                join_mode: fd.get('join_open') ? 'open' : 'approval',
            } });
            m.remove();
            await refreshState({ only: server });
            await openChannel(res.uuid, server);
            qrDialog(store.detail);
        } catch (err) { toastError(err); }
    };
}

/**
 * Everything about one chat, reached by long-pressing (or right-clicking) it in
 * the list, or through the ⋯ button there and in the channel header. `c` may be
 * either a channel-list entry or an open channel's detail — both carry the
 * fields used here.
 */
function channelSettingsDialog(c) {
    const isPrivate = c.type === 'private';
    const owner = !!c.is_owner;
    const server = c.server;

    const toggle = (id, on, label, hint) => `
        <label class="flex cursor-pointer items-start gap-3 rounded-lg px-1 py-1.5 text-sm text-zinc-200 hover:bg-white/5">
            <input type="checkbox" id="${id}" ${on ? 'checked' : ''} class="mt-0.5 accent-violet-500">
            <span>${label}<span class="mt-0.5 block text-xs text-zinc-500">${hint}</span></span>
        </label>`;

    const m = modal(`
        <h2 class="text-lg font-bold text-white break-words">${isPrivate ? '👤 ' : '# '}${esc(c.name)}</h2>
        ${serverChipHtml(server) ? `<p class="mt-1 text-xs text-zinc-500">${serverChipHtml(server)} ${esc(serverLabel(server))}</p>` : ''}

        <div class="mt-4 space-y-1">
            ${toggle('cs-pin', c.pinned, '📌 ' + t('Pin to top'), t('Keeps this chat above the others in the list.'))}
            ${toggle('cs-mute', c.muted, '🔕 ' + t('Mute this chat'), t('No notifications for new messages here; unread counts still show.'))}
            ${toggle('cs-hide', c.hidden, '🙈 ' + t('Hide this chat'), t('Removed from the list until “Show hidden chats” is on.'))}
        </div>

        <div class="mt-5 space-y-2 border-t border-white/10 pt-4">
            ${owner && !isPrivate ? `
                <button id="cm-qr" class="w-full rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">📱 ${t('Show invite QR code')}</button>
                <button id="cm-embed" class="w-full rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">🌐 ${t('Embed on a website')}</button>
                <button id="cm-settings" class="w-full rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">⚙️ ${t('Channel settings')}</button>
                <button id="cm-purge" class="w-full rounded-lg border border-red-500/40 px-4 py-2 text-sm text-red-400 hover:bg-red-500/10">🧹 ${t('Delete all uploads')}</button>` : ''}
            ${isPrivate ? `
                <button id="cm-verify" class="w-full rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">🔒 ${t('Verify end-to-end encryption')}</button>` : ''}
            ${!owner && !isPrivate ? `
                <button id="cm-leave" class="w-full rounded-lg border border-red-500/40 px-4 py-2 text-sm text-red-400 hover:bg-red-500/10">${t('Leave channel')}</button>` : ''}
            ${(owner || isPrivate) ? `
                <button id="cm-destroy" class="w-full rounded-lg bg-red-600/90 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500">🗑 ${isPrivate ? t('Destroy conversation') : t('Destroy channel')}</button>` : ''}
            <button data-cancel class="w-full rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Close')}</button>
        </div>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();

    // The toggles act immediately — the entry in store.channels is the one the
    // list renders from, so it has to be the one that is updated.
    const entry = store.channels.find((x) => x.key === c.key) || c;
    m.querySelector('#cs-pin').onchange = () => togglePin(entry);
    m.querySelector('#cs-mute').onchange = () => toggleMute(entry);
    m.querySelector('#cs-hide').onchange = () => toggleHide(entry);

    const d = () => (store.detail?.key === c.key ? store.detail : null);
    m.querySelector('#cm-qr')?.addEventListener('click', async () => {
        m.remove();
        try { qrDialog(d() || await fetchDetail(c.uuid, server)); } catch (err) { toastError(err); }
    });
    m.querySelector('#cm-embed')?.addEventListener('click', async () => {
        m.remove();
        try {
            const detail = d() || await fetchDetail(c.uuid, server);
            if (detail.embed_url) embedDialog(detail);
            else toastInfo(t('Enable “Open join” in the channel settings first — embedded visitors cannot wait for approval.'));
        } catch (err) { toastError(err); }
    });
    m.querySelector('#cm-settings')?.addEventListener('click', async () => {
        m.remove();
        try { channelConfigDialog(d() || await fetchDetail(c.uuid, server)); } catch (err) { toastError(err); }
    });
    m.querySelector('#cm-verify')?.addEventListener('click', async () => {
        m.remove();
        try { verifyEncryptionDialog(d() || await fetchDetail(c.uuid, server)); } catch (err) { toastError(err); }
    });
    m.querySelector('#cm-purge')?.addEventListener('click', async () => {
        m.remove();
        const ok = await confirmDialog({
            title: t('Delete all uploads?'),
            text: t('Every uploaded file in “:channel” (including previews) and its message will be deleted permanently for everyone.', { channel: c.name }),
            confirmLabel: t('Delete all uploads'),
        });
        if (!ok) return;
        try {
            const res = await api(`/channels/${c.uuid}/purge-uploads`, { server, body: {} });
            toastInfo(t(res.deleted === 1 ? ':count upload deleted.' : ':count uploads deleted.', { count: res.deleted }));
            if (store.current?.key === c.key) await loadMessages(true);
        } catch (err) { toastError(err); }
    });
    m.querySelector('#cm-leave')?.addEventListener('click', async () => {
        m.remove();
        const res = await confirmDialog({
            title: t('Leave channel?'),
            text: t('You will leave “:channel”. You can rejoin later via QR code.', { channel: c.name }),
            confirmLabel: t('Leave'),
            checkbox: t('Delete all my messages and files in this channel'),
        });
        if (!res) return;
        try {
            await api(`/channels/${c.uuid}/leave`, { server, body: { delete_own_data: res.checked } });
            if (store.current?.key === c.key) closeChannel();
            await refreshState({ only: server });
        } catch (err) { toastError(err); }
    });
    m.querySelector('#cm-destroy')?.addEventListener('click', () => {
        m.remove();
        destroyChannelDialog({ uuid: c.uuid, name: c.name, type: c.type, server, key: c.key });
    });
}

async function destroyChannelDialog(c) {
    const isPrivate = c.type === 'private';
    const ok = await confirmDialog({
        title: isPrivate ? t('Destroy conversation?') : t('Destroy channel?'),
        text: isPrivate
            ? t('All messages of this private conversation will be deleted permanently for both participants.')
            : t('“:channel” and ALL of its messages, files and members will be deleted permanently for everyone. This cannot be undone.', { channel: c.name }),
        confirmLabel: t('Destroy everything'),
    });
    if (!ok) return;
    try {
        await api(`/channels/${c.uuid}`, { method: 'DELETE', server: c.server });
        if (store.current?.key === c.key) closeChannel();
        refreshState({ only: c.server });
    } catch (err) { toastError(err); }
}

/** Owner-only channel configuration: name, retention, what may be uploaded. */
function channelConfigDialog(d) {
    const m = modal(`
        <h2 class="text-lg font-bold text-white">${t('Channel settings')}</h2>
        <form id="cs-form" class="mt-4 space-y-3">
            <div>
                <label class="block text-sm text-zinc-400">${t('Channel name')}</label>
                <input name="name" required maxlength="60" value="${esc(d.name)}"
                    class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm text-zinc-400 break-words">
                    ${t('Automatically delete all content (messages, users, media, meta information) after inactivity for … days (max 365)')}
                </label>
                <input name="retention_days" type="number" min="1" max="365" value="${d.retention_days}"
                    class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
            </div>
            <fieldset class="rounded-lg border border-white/10 p-3">
                <legend class="px-1 text-sm text-zinc-400">${t('Allow uploads of')}</legend>
                <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm text-zinc-200">
                    <label class="flex cursor-pointer items-center gap-1.5"><input type="checkbox" name="allow_images" ${d.allow_images ? 'checked' : ''} class="accent-violet-500"> 🖼 ${t('Images')}</label>
                    <label class="flex cursor-pointer items-center gap-1.5"><input type="checkbox" name="allow_videos" ${d.allow_videos ? 'checked' : ''} class="accent-violet-500"> 🎬 ${t('Videos')}</label>
                    <label class="flex cursor-pointer items-center gap-1.5"><input type="checkbox" name="allow_audio" ${d.allow_audio ? 'checked' : ''} class="accent-violet-500"> 🎵 ${t('Audio')}</label>
                    <label class="flex cursor-pointer items-center gap-1.5"><input type="checkbox" name="allow_zip" ${d.allow_zip ? 'checked' : ''} class="accent-violet-500"> 🗜 ${t('Zip')}</label>
                </div>
            </fieldset>
            <label class="flex cursor-pointer items-start gap-2 text-sm text-zinc-200">
                <input type="checkbox" name="restrict_delete" ${d.restrict_delete ? 'checked' : ''} class="mt-0.5 accent-violet-500">
                <span>${t('Only file owners and the channel owner can delete their files and messages')}</span>
            </label>
            <label class="flex cursor-pointer items-start gap-2 text-sm text-zinc-200">
                <input type="checkbox" name="join_open" ${d.join_mode === 'open' ? 'checked' : ''} class="mt-0.5 accent-violet-500">
                <span>${t('Open join — anyone with the invite link joins instantly, no approval. Needed for embedding the chat on a website.')}</span>
            </label>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Cancel')}</button>
                <button class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">${t('Save')}</button>
            </div>
        </form>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();
    // Enabling open join defaults uploads to off (the owner can re-check them
    // before saving — this just makes the consequence visible).
    m.querySelector('[name="join_open"]').onchange = (e) => {
        if (e.target.checked && d.join_mode !== 'open') {
            for (const n of ['allow_images', 'allow_videos', 'allow_audio', 'allow_zip']) {
                m.querySelector(`[name="${n}"]`).checked = false;
            }
            toastInfo(t('Uploads were switched off — anyone can join an open channel. Re-enable them if you want.'));
        }
    };
    m.querySelector('#cs-form').onsubmit = async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        try {
            await api(`/channels/${d.uuid}`, { method: 'PATCH', server: d.server, body: {
                name: fd.get('name'),
                retention_days: Number(fd.get('retention_days')),
                join_mode: fd.get('join_open') ? 'open' : 'approval',
                allow_images: !!fd.get('allow_images'),
                allow_videos: !!fd.get('allow_videos'),
                allow_audio: !!fd.get('allow_audio'),
                allow_zip: !!fd.get('allow_zip'),
                restrict_delete: !!fd.get('restrict_delete'),
            } });
            m.remove();
            await openChannel(d.uuid, d.server);
            refreshState({ only: d.server });
        } catch (err) { toastError(err); }
    };
}

/** Copyable iframe snippet for an open-join channel. */
function embedDialog(d) {
    const snippet = `<iframe src="${d.embed_url}" style="width:100%;height:500px;border:0;border-radius:12px" title="${d.name.replace(/"/g, '&quot;')} — Krotze chat"></iframe>`;
    const m = modal(`
        <h2 class="text-lg font-bold text-white">${t('Embed this chat')}</h2>
        <p class="mt-2 text-sm text-zinc-400 break-words">
            ${t('Paste this snippet into any website. Visitors pick a username and join instantly — the channel has open join, so no approval is needed. Rotating the invite (“Move Channel”) breaks existing embeds.')}
        </p>
        <pre class="mt-4 max-h-40 overflow-auto rounded-lg border border-white/10 bg-black/40 p-3 text-xs text-zinc-300 whitespace-pre-wrap break-all">${esc(snippet)}</pre>
        <div class="mt-5 flex flex-wrap justify-end gap-3">
            <button id="em-copy" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">📋 ${t('Copy snippet')}</button>
            <button data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Close')}</button>
        </div>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();
    m.querySelector('#em-copy').onclick = async () => {
        try {
            await navigator.clipboard.writeText(snippet);
            toastInfo(t('Embed snippet copied.'));
        } catch {
            const r = document.createRange();
            r.selectNodeContents(m.querySelector('pre'));
            getSelection().removeAllRanges();
            getSelection().addRange(r);
            toastInfo(t('Press Ctrl/Cmd+C to copy.'));
        }
    };
}

/**
 * Safety number of a private conversation — both sides derive the identical
 * number from all participant device keys, so comparing it over another
 * channel (in person, a call) proves nobody sits in between.
 */
function verifyEncryptionDialog(d) {
    if (!e2eeReady(d)) {
        const m = modal(`
            <h2 class="text-lg font-bold text-white">🔒 ${t('Verify end-to-end encryption')}</h2>
            <p class="mt-2 text-sm text-zinc-400">${t('Encryption is not active in this conversation yet — it starts once both sides have used an up-to-date Krotze.')}</p>
            <div class="mt-5 flex justify-end">
                <button data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Close')}</button>
            </div>`);
        m.querySelector('[data-cancel]').onclick = () => m.remove();
        return;
    }

    const number = safetyNumber(d.devices);
    const m = modal(`
        <h2 class="text-lg font-bold text-white">🔒 ${t('Verify end-to-end encryption')}</h2>
        <p class="mt-2 text-sm text-zinc-400">
            ${t('Compare this safety number with :name over another channel — in person or in a call. If both of you see the same number, your conversation is end-to-end encrypted with no one in between.', { name: `<b class="text-zinc-200">${esc(d.name)}</b>` })}
        </p>
        <p class="mt-4 rounded-xl border border-white/10 bg-black/40 p-4 text-center font-mono text-lg tracking-wider text-white break-words" dir="ltr">
            ${number}
        </p>
        <p class="mt-3 text-xs text-zinc-500">
            ${t('The number changes whenever either of you adds or removes a device — compare it again after that.')}
        </p>
        <div class="mt-5 flex justify-end">
            <button data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Close')}</button>
        </div>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();
}

/* ------------------------------ QR -------------------------------- */

async function qrDialog(d) {
    if (!d?.invite_url) return;
    const m = modal(`
        <h2 class="text-lg font-bold text-white break-words text-center">${esc(d.name)}</h2>
        <p class="mt-1 text-center text-sm text-zinc-400">${t('Scan to apply for membership')}</p>
        <div class="mt-4 flex justify-center"><canvas id="qr-canvas" class="rounded-lg bg-white p-2 max-w-full"></canvas></div>
        <p class="mt-3 text-center text-xs text-zinc-500">${t('Or use the link:')}</p>
        <button id="qr-copy" title="${t('Copy link')}"
            class="mt-1 mx-auto flex max-w-full items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-3 py-1.5 text-xs text-zinc-300 hover:bg-white/10">
            <span class="min-w-0 break-all text-start">${esc(d.invite_url)}</span>
            <span class="shrink-0">📋</span>
        </button>
        <div class="mt-5 flex flex-wrap justify-center gap-3">
            <button id="qr-download" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">⬇ ${t('Download as image')}</button>
            <button id="qr-rotate" class="rounded-lg border border-amber-400/40 px-4 py-2 text-sm text-amber-300 hover:bg-amber-400/10">♻ ${t('Move Channel')}</button>
            <button data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Close')}</button>
        </div>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();
    m.querySelector('#qr-rotate').onclick = () => { m.remove(); rotateInviteDialog(d); };
    m.querySelector('#qr-copy').onclick = async () => {
        try {
            await navigator.clipboard.writeText(d.invite_url);
            toastInfo(t('Invite link copied.'));
        } catch {
            // Clipboard API unavailable (e.g. http) — fall back to selection
            const r = document.createRange();
            r.selectNodeContents(m.querySelector('#qr-copy span'));
            getSelection().removeAllRanges();
            getSelection().addRange(r);
            toastInfo(t('Press Ctrl/Cmd+C to copy.'));
        }
    };
    const canvas = m.querySelector('#qr-canvas');
    await QRCode.toCanvas(canvas, d.invite_url, { width: 240, margin: 1 });

    m.querySelector('#qr-download').onclick = async () => {
        // Compose channel name above the QR code
        const qr = document.createElement('canvas');
        await QRCode.toCanvas(qr, d.invite_url, { width: 600, margin: 2 });
        const out = document.createElement('canvas');
        out.width = 680;
        out.height = 800;
        const ctx = out.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, out.width, out.height);
        ctx.fillStyle = '#111111';
        ctx.font = 'bold 40px sans-serif';
        ctx.textAlign = 'center';
        let name = d.name;
        while (ctx.measureText(name).width > 620 && name.length > 3) name = name.slice(0, -2);
        if (name !== d.name) name += '…';
        ctx.fillText(name, out.width / 2, 70);
        ctx.font = '22px sans-serif';
        ctx.fillStyle = '#666666';
        ctx.fillText(t('Scan to join on :host', { host: hostOf(d.invite_url) }), out.width / 2, 110);
        ctx.drawImage(qr, 40, 140, 600, 600);
        const a = document.createElement('a');
        a.download = `krotze-${d.name.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}-qr.png`;
        a.href = out.toDataURL('image/png');
        a.click();
    };
}

/**
 * Pull the member roster again and update the header count and member panel in
 * place — the message list, the draft in the composer and the scroll position
 * all survive, so this is safe to call from the poll.
 */
async function reloadChannelDetail() {
    if (!store.current) return;
    const cur = store.current;
    try {
        const detail = await fetchDetail(cur.uuid, cur.server);
        if (cur.key !== store.current?.key) return;
        store.detail = detail;
        renderChannelHeader();
        if (store.membersOpen) renderMembersPanel();
    } catch { /* channel may be gone */ }
}

function pendingRequestsDialog(d) {
    const pending = d.members.filter((x) => x.status === 'pending')
        .sort((a, b) => new Date(b.joined_at || 0) - new Date(a.joined_at || 0));
    if (!pending.length) { toastInfo(t('No open join requests.')); return; }
    const selected = new Set(pending.map((x) => x.id));

    const m = modal(`
        <h2 class="text-lg font-bold text-white">${t('Join requests')}</h2>
        <p class="mt-1 text-sm text-zinc-400">${t('Select who may join “:channel”. Unselected requests stay open.', { channel: esc(d.name) })}</p>
        <div id="pr-list" class="mt-4 max-h-64 space-y-1 overflow-y-auto">
            ${pending.map((x) => `
                <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-white/5">
                    <input type="checkbox" data-member="${x.id}" checked class="accent-violet-500">
                    <span class="min-w-0 flex-1 truncate text-zinc-200">${esc(x.username)}</span>
                    <span class="shrink-0 text-xs text-zinc-500">${x.joined_at ? fmtTime(x.joined_at) : ''}</span>
                </label>`).join('')}
        </div>
        <div class="mt-6 flex flex-wrap justify-end gap-3">
            <button id="pr-dismiss" class="rounded-lg border border-red-500/40 px-4 py-2 text-sm text-red-400 hover:bg-red-500/10">${t('Dismiss all')}</button>
            <button data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Cancel')}</button>
            <button id="pr-accept" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">${t('Accept selected')}</button>
        </div>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();
    m.querySelectorAll('[data-member]').forEach((cb) => {
        cb.onchange = () => cb.checked
            ? selected.add(Number(cb.dataset.member))
            : selected.delete(Number(cb.dataset.member));
    });

    const decide = async (accept, deny) => {
        try {
            await api(`/channels/${d.uuid}/requests`, { server: d.server, body: { accept, deny } });
            await reloadChannelDetail();
            refreshState({ only: d.server });
        } catch (err) { toastError(err); }
    };
    m.querySelector('#pr-accept').onclick = async () => {
        m.remove();
        if (selected.size) await decide([...selected], []);
    };
    m.querySelector('#pr-dismiss').onclick = async () => {
        m.remove();
        const ok = await confirmDialog({
            title: pending.length === 1 ? t('Dismiss all 1 request?') : t('Dismiss all :count requests?', { count: pending.length }),
            text: t('All open join requests for this channel will be denied.'),
            confirmLabel: t('Dismiss all'),
        });
        if (!ok) return;
        await decide([], pending.map((x) => x.id));
    };
}

function rotateInviteDialog(d) {
    // Newest members first — after a leak, the suspicious accounts are the recent ones.
    const members = d.members.filter((x) => !x.is_me)
        .sort((a, b) => new Date(b.joined_at || 0) - new Date(a.joined_at || 0));
    const selected = new Set(members.map((x) => x.id));
    let query = '';
    let page = 0;
    const PER_PAGE = 8;

    const m = modal(`
        <h2 class="text-lg font-bold text-white">${t('Create new invite')}</h2>
        <p class="mt-2 text-sm text-zinc-400">
            ${t('The invite link and QR code will be replaced — the old ones stop working immediately, and the channel gets a new address. Selected members move with the channel automatically; <b>unselected members are removed</b>.')}
        </p>
        ${members.length ? `
            <input id="ri-search" type="search" placeholder="${t('Search members…')}"
                class="mt-4 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm text-white focus:border-violet-500 focus:outline-none">
            <div id="ri-list" class="mt-2 space-y-1"></div>
            <div id="ri-pager" class="mt-2 flex items-center justify-between text-xs text-zinc-500"></div>`
        : `<p class="mt-4 text-sm text-zinc-500">${t('No other members yet — only the invite link changes.')}</p>`}
        <div class="mt-6 flex justify-end gap-3">
            <button data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Cancel')}</button>
            <button id="ri-ok" class="rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-black hover:bg-amber-400">${t('Recreate invite')}</button>
        </div>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();

    const renderList = () => {
        const list = m.querySelector('#ri-list');
        if (!list) return;
        const filtered = members.filter((x) => x.username.toLowerCase().includes(query));
        const pages = Math.max(1, Math.ceil(filtered.length / PER_PAGE));
        page = Math.min(page, pages - 1);
        const slice = filtered.slice(page * PER_PAGE, (page + 1) * PER_PAGE);
        list.innerHTML = slice.map((x) => `
            <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-white/5">
                <input type="checkbox" data-member="${x.id}" ${selected.has(x.id) ? 'checked' : ''} class="accent-violet-500">
                <span class="min-w-0 flex-1 truncate text-zinc-200">${esc(x.username)}</span>
                ${x.status === 'pending' ? `<span class="text-xs text-amber-400 shrink-0">${t('pending')}</span>` : ''}
                <span class="shrink-0 text-xs text-zinc-500">${x.joined_at ? fmtTime(x.joined_at) : ''}</span>
            </label>`).join('')
            || `<p class="px-2 py-3 text-sm text-zinc-500">${t('No members match your search.')}</p>`;
        list.querySelectorAll('[data-member]').forEach((cb) => {
            cb.onchange = () => cb.checked
                ? selected.add(Number(cb.dataset.member))
                : selected.delete(Number(cb.dataset.member));
        });
        const pager = m.querySelector('#ri-pager');
        pager.innerHTML = pages > 1 ? `
            <button id="ri-prev" class="rounded border border-white/15 px-2 py-1 hover:bg-white/10 ${page === 0 ? 'invisible' : ''}">${t('‹ Prev')}</button>
            <span>${t('Page :a / :b', { a: page + 1, b: pages })} · ${t(':a/:b keep', { a: selected.size, b: members.length })}</span>
            <button id="ri-next" class="rounded border border-white/15 px-2 py-1 hover:bg-white/10 ${page >= pages - 1 ? 'invisible' : ''}">${t('Next ›')}</button>`
            : `<span class="ms-auto">${t(':a/:b keep', { a: selected.size, b: members.length })}</span>`;
        const prev = pager.querySelector('#ri-prev');
        const next = pager.querySelector('#ri-next');
        if (prev) prev.onclick = () => { page--; renderList(); };
        if (next) next.onclick = () => { page++; renderList(); };
    };
    const search = m.querySelector('#ri-search');
    if (search) {
        search.oninput = () => { query = search.value.trim().toLowerCase(); page = 0; renderList(); };
    }
    renderList();

    m.querySelector('#ri-ok').onclick = async () => {
        const dropped = members.length - selected.size;
        if (dropped > 0) {
            m.remove();
            const ok = await confirmDialog({
                title: t(dropped === 1 ? 'Remove :count member?' : 'Remove :count members?', { count: dropped }),
                text: t('Unselected members are removed from the channel and will not receive the new invite.'),
                confirmLabel: t('Recreate invite'),
            });
            if (!ok) return;
        } else {
            m.remove();
        }
        try {
            const res = await api(`/channels/${d.uuid}/rotate`, { server: d.server, body: { keep: [...selected] } });
            const last = getLastChannel();
            if (last?.server === d.server && last.uuid === d.uuid) setLastChannel(d.server, res.uuid);
            toastInfo(t('New invite created — old links are now invalid.'));
            await refreshState({ only: d.server });
            await openChannel(res.uuid, d.server);
            qrDialog(store.detail);
        } catch (err) { toastError(err); }
    };
}

/** `https://host/join/TOKEN` (or a bare `/join/TOKEN`) → { origin, token }, else null. */
function parseInviteLink(text) {
    const match = String(text || '').match(/(?:^\s*(https?:\/\/[^/\s]+))?\/join\/([A-Za-z0-9]+)/);
    return match ? { origin: match[1] || null, token: match[2] } : null;
}

/**
 * An invite names the server it lives on. Known server → join there; the
 * home server, or no host at all → join at home; anywhere else → offer to
 * add that server first.
 */
async function resolveInvite(origin, token) {
    const home = homeServer();
    const target = origin ? normalizeOrigin(origin) : null;
    if (!target || target === home.origin) return joinFlow(token, home);
    const known = serverByOrigin(target);
    if (known) {
        if (!usableServer(known)) { serversDialog(); return; }
        return joinFlow(token, known);
    }
    const ok = await confirmDialog({
        title: t('Add server'),
        text: t('This invite is for :origin. Add that server and join?', { origin: target }),
        confirmLabel: t('Add and continue'),
        danger: false,
    });
    if (ok) addServerDialog(target, { thenJoin: token });
}

function scanQrDialog() {
    const m = modal(`
        <h2 class="text-lg font-bold text-white">${t('Scan QR code')}</h2>
        <p class="mt-1 text-sm text-zinc-400">${t('Point your camera at a Krotze channel QR code.')}</p>
        <video id="scan-video" playsinline class="mt-4 w-full rounded-lg bg-black aspect-square object-cover"></video>
        <p id="scan-status" class="mt-2 text-center text-xs text-zinc-500">${t('Starting camera…')}</p>
        <form id="scan-link-form" class="mt-3 flex gap-2">
            <input id="scan-link" placeholder="${t('Paste an invite link')}" autocomplete="off" dir="ltr"
                class="min-w-0 flex-1 rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm text-white focus:border-violet-500 focus:outline-none">
            <button class="shrink-0 rounded-lg border border-white/20 px-3 py-2 text-sm hover:bg-white/10">${t('Open')}</button>
        </form>
        <div class="mt-4 flex justify-end">
            <button data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Cancel')}</button>
        </div>`);
    const video = m.querySelector('#scan-video');
    const status = m.querySelector('#scan-status');
    let stream = null;
    let stopped = false;

    const stop = () => {
        stopped = true;
        stream?.getTracks().forEach((t) => t.stop());
        m.remove();
    };
    m.querySelector('[data-cancel]').onclick = stop;
    m.querySelector('[data-close]').addEventListener('click', stop);
    m.querySelector('#scan-link-form').onsubmit = (e) => {
        e.preventDefault();
        const invite = parseInviteLink(m.querySelector('#scan-link').value);
        if (!invite) { toastError({ message: t('This invite link is invalid or the channel no longer exists.') }); return; }
        stop();
        resolveInvite(invite.origin, invite.token);
    };

    (async () => {
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment' },
            });
        } catch {
            status.textContent = t('Camera access denied. You can also open the invite link directly.');
            return;
        }
        if (stopped) { stream.getTracks().forEach((track) => track.stop()); return; }
        video.srcObject = stream;
        await video.play();
        status.textContent = t('Scanning…');
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        const tick = () => {
            if (stopped) return;
            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                ctx.drawImage(video, 0, 0);
                const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
                const code = jsQR(img.data, img.width, img.height);
                const invite = parseInviteLink(code?.data);
                if (invite) {
                    stop();
                    resolveInvite(invite.origin, invite.token);
                    return;
                }
            }
            requestAnimationFrame(tick);
        };
        tick();
    })();
}

/* ----------------------------- joining ----------------------------- */

async function joinFlow(inviteToken, server = homeServer()) {
    if (!(await requireUsername(server))) return;
    let info;
    try {
        info = await api(`/join/${inviteToken}`, { server });
    } catch {
        toastError({ message: t('This invite link is invalid or the channel no longer exists.') });
        return;
    }
    const open = info.join_mode === 'open';
    const chip = serverChipHtml(server);
    const m = modal(`
        <h2 class="text-lg font-bold text-white break-words">${t('Join “:name”', { name: esc(info.name) })}</h2>
        ${chip ? `<p class="mt-1 text-xs text-zinc-500">${chip} ${esc(serverLabel(server))}</p>` : ''}
        <p class="mt-1 text-sm text-zinc-400">
            ${t(':count member(s).', { count: info.members })}
            ${t('You will appear as :name.', { name: `<b class="text-zinc-200">${esc(server.state.username || '')}</b>` })}
            ${open
                ? t('this channel has open join, so you will join immediately.')
                : t('the channel owner must approve your request.')}
        </p>
        <form id="jf-form" class="mt-4 space-y-3">
            <div class="flex justify-end gap-3">
                <button type="button" data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Cancel')}</button>
                <button class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">${open ? t('Join') : t('Request to join')}</button>
            </div>
        </form>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();
    m.querySelector('#jf-form').onsubmit = async (e) => {
        e.preventDefault();
        try {
            const res = await api(`/join/${inviteToken}`, { server, body: {} });
            m.remove();
            await refreshState({ only: server });
            if (res.status === 'approved') openChannel(res.uuid, server);
            else modal(`
                <h2 class="text-lg font-bold text-white">${t('Request sent')}</h2>
                <p class="mt-2 text-sm text-zinc-400">${t('The channel owner has been notified. You will get a notification here once you are approved.')}</p>
                <div class="mt-5 flex justify-end"><button data-ok class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">${t('OK')}</button></div>`,
                { onOpen: (mm) => { mm.querySelector('[data-ok]').onclick = () => mm.remove(); } });
        } catch (err) { toastError(err); }
    };
}

/* ---------------------------- profile ------------------------------ */

/** Everything here needs a name first — it is the identity other people see. */
async function requireUsername(server = store.server) {
    if (server.state.username) return true;
    await usernameDialog({ required: true, server });
    return !!server.state.username;
}

function usernameDialog({ required = false, server = store.server } = {}) {
    return new Promise((resolve) => {
        const chip = serverChipHtml(server);
        const m = modal(`
            <h2 class="text-lg font-bold text-white">${required ? t('Choose your username') : t('Change username')}</h2>
            ${chip ? `<p class="mt-1 text-xs text-zinc-500">${chip} ${esc(serverLabel(server))}</p>` : ''}
            <p class="mt-2 text-sm text-zinc-400">
                ${t('This is the name every channel shows you under. Usernames are unique — nobody else on Krotze can use the same one.')}
            </p>
            <form id="un-form" class="mt-4 space-y-3">
                <input name="username" required minlength="2" maxlength="40" autocomplete="off"
                    value="${esc(server.state.username || '')}" placeholder="${t('e.g. nightowl')}"
                    class="w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
                <p id="un-error" class="hidden text-sm text-red-400 break-words"></p>
                <div class="flex justify-end gap-3">
                    ${required ? '' : `<button type="button" data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Cancel')}</button>`}
                    <button class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">${t('Save')}</button>
                </div>
            </form>`, { dismissible: !required });

        m.querySelector('[data-cancel]')?.addEventListener('click', () => { m.remove(); resolve(false); });
        m.querySelector('input').focus();

        m.querySelector('#un-form').onsubmit = async (e) => {
            e.preventDefault();
            const err = m.querySelector('#un-error');
            const username = new FormData(e.target).get('username').trim();
            try {
                const res = await api('/profile/username', { server, body: { username } });
                server.state.username = res.username;
                renderProfileName();
                renderChannelHeader();
                m.remove();
                resolve(true);
            } catch (ex) {
                err.textContent = ex.data?.errors?.username?.[0] || ex.message;
                err.classList.remove('hidden');
            }
        };
    });
}

async function profileDialog(server = store.server) {
    if (!usableServer(server)) server = store.servers.find(usableServer) || server;
    const m = modal(`<p class="text-sm text-zinc-400">${t('Loading…')}</p>`);
    const panel = m.querySelector('.relative');
    const choices = store.servers.filter(usableServer);

    const render = async () => {
        let d;
        try { d = await api('/profile', { server }); } catch (e) { toastError(e); m.remove(); return; }

        panel.innerHTML = `
            <h2 class="text-lg font-bold text-white">${choices.length > 1 ? t('Profile on :server', { server: esc(serverLabel(server)) }) : t('Profile')}</h2>
            ${choices.length > 1 ? `
            <div class="mt-2 flex items-center gap-2 text-xs text-zinc-500">
                ${serverChipHtml(server)}
                <select id="pf-server" class="min-w-0 flex-1 rounded border border-white/15 bg-[#171522] px-2 py-1 text-xs text-zinc-300 focus:outline-none">
                    ${choices.map((s) => `<option value="${s.id}" ${s === server ? 'selected' : ''}>${esc(serverLabel(s))} · ${esc(s.suffix)}</option>`).join('')}
                </select>
            </div>` : ''}

            <div class="mt-4 rounded-xl border border-white/10 p-3">
                <p class="text-xs uppercase tracking-wide text-zinc-500">${t('Username')}</p>
                <div class="mt-1 flex items-center gap-2">
                    <span class="min-w-0 flex-1 truncate font-semibold text-white">${esc(d.username || t('not set yet'))}</span>
                    <button id="pf-username" class="shrink-0 rounded-lg border border-white/20 px-3 py-1.5 text-xs hover:bg-white/10">${t('Change')}</button>
                </div>
            </div>

            <div class="mt-3 rounded-xl border border-white/10 p-3">
                <p class="text-xs uppercase tracking-wide text-zinc-500">${t('My devices')}</p>
                <div id="pf-devices" class="mt-2 space-y-1"></div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button id="pf-add-device" class="rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-500">➕ ${t('Register a device')}</button>
                    <button id="pf-use-code" class="rounded-lg border border-white/20 px-3 py-1.5 text-xs hover:bg-white/10">${t('I have a pairing code')}</button>
                </div>
            </div>

            <div class="mt-3 rounded-xl border border-white/10 p-3">
                <p class="text-xs uppercase tracking-wide text-zinc-500">${t('Identity token')}</p>
                <p class="mt-1 text-xs text-zinc-400">
                    ${t('Export your identity to a passphrase-encrypted file, and import it somewhere else to continue as this user.')}
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button id="pf-export" class="rounded-lg border border-white/20 px-3 py-1.5 text-xs hover:bg-white/10">⬆ ${t('Export identity')}</button>
                    <button id="pf-import" class="rounded-lg border border-white/20 px-3 py-1.5 text-xs hover:bg-white/10">⬇ ${t('Import identity')}</button>
                    ${d.has_transfer_token ? `<button id="pf-revoke" class="rounded-lg border border-amber-400/40 px-3 py-1.5 text-xs text-amber-300 hover:bg-amber-400/10">${t('Revoke exported token')}</button>` : ''}
                </div>
            </div>

            <div class="mt-3 space-y-2 rounded-xl border border-red-500/20 p-3">
                <button id="pf-swap" class="w-full rounded-lg border border-amber-400/40 px-4 py-2 text-sm text-amber-300 hover:bg-amber-400/10">♻ ${t('Swap my token')}</button>
                <button id="pf-delete" class="w-full rounded-lg border border-red-500/40 px-4 py-2 text-sm text-red-400 hover:bg-red-500/10">${t('Delete all my data')}</button>
            </div>

            <div class="mt-5 flex justify-end">
                <button data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Close')}</button>
            </div>`;

        const list = panel.querySelector('#pf-devices');
        for (const dev of d.devices) {
            const row = document.createElement('div');
            // The device you are holding is the one whose removal has
            // consequences, so it is the one that stands out.
            row.className = `flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm ${dev.current
                ? 'border border-violet-500/50 bg-violet-500/15'
                : 'bg-white/5'}`;
            row.innerHTML = `
                <span class="shrink-0">${dev.current ? '📍' : '💻'}</span>
                <span class="min-w-0 flex-1 truncate ${dev.current ? 'font-semibold text-white' : 'text-zinc-200'}">${esc(dev.name)}</span>
                ${dev.current
                    ? `<span class="shrink-0 rounded-full bg-violet-500/30 px-2 py-0.5 text-[11px] font-semibold text-violet-200">${t('this device')}</span>`
                    : `<span class="shrink-0 text-xs text-zinc-500">${dev.last_seen_at ? fmtTime(dev.last_seen_at) : ''}</span>`}
                <button data-remove="${dev.id}" title="${dev.current ? t('Sign this device out') : t('Remove this device')}"
                    class="shrink-0 rounded px-1.5 text-xs text-red-400 opacity-60 hover:opacity-100">✕</button>`;
            list.appendChild(row);
        }
        if (!d.devices.length) {
            list.innerHTML = `<p class="text-sm text-zinc-500">${t('No devices registered.')}</p>`;
        }

        list.onclick = async (e) => {
            const id = e.target.dataset?.remove;
            if (!id) return;
            const dev = d.devices.find((x) => String(x.id) === id);
            const isLast = d.devices.length === 1;

            // Removing the device you are holding signs *you* out — and if it is
            // the only one, an exported identity is the only way back to this
            // username, its channels and its messages.
            const ok = await confirmDialog(dev?.current ? {
                title: t('Sign this device out?'),
                text: isLast
                    ? t('“:device” is the only device registered to “:user”. Removing it signs you out here and leaves no way back in — unless you have exported your identity first. Your channels and messages are not deleted, but without an identity file nothing can reach them again. Export your identity before you do this.', { device: dev.name, user: d.username || t('this identity') })
                    : t('“:device” is the device you are using. It is signed out immediately and Krotze starts fresh here; your other devices are unaffected. Make sure you can get back in — through another device or an exported identity file.', { device: dev.name }),
                confirmLabel: isLast ? t('Sign out anyway') : t('Sign this device out'),
            } : {
                title: t('Remove “:name”?', { name: dev?.name }),
                text: t('That device is signed out immediately and has to be paired again to come back.'),
                confirmLabel: t('Remove device'),
            });
            if (!ok) return;

            try {
                const res = await api(`/profile/devices/${id}`, { method: 'DELETE', server });
                if (res.was_current) {
                    // Nothing left to render — the credentials this dialog was
                    // built on no longer exist.
                    if (server.home) {
                        forgetIdentity(t('You signed this device out. Import your identity to come back.'));
                        return;
                    }
                    m.remove();
                    markServerRevoked(server);
                    return;
                }
            } catch (ex) { toastError(ex); }
            render();
        };

        panel.querySelector('[data-cancel]').onclick = () => m.remove();
        panel.querySelector('#pf-server')?.addEventListener('change', (e) => {
            server = serverById(e.target.value) || server;
            render();
        });
        panel.querySelector('#pf-username').onclick = async () => {
            await usernameDialog({ server });
            render();
        };
        panel.querySelector('#pf-add-device').onclick = () => addDeviceDialog(server);
        panel.querySelector('#pf-use-code').onclick = () => pairingCodeDialog('', server);
        panel.querySelector('#pf-export').onclick = () => exportIdentityDialog(server);
        panel.querySelector('#pf-import').onclick = () => importIdentityDialog(server);
        panel.querySelector('#pf-revoke')?.addEventListener('click', async () => {
            const ok = await confirmDialog({
                title: t('Revoke the exported token?'),
                text: t('Identity files exported earlier stop working. Devices already registered stay signed in.'),
                confirmLabel: t('Revoke token'),
            });
            if (!ok) return;
            try { await api('/profile/transfer', { method: 'DELETE', server }); } catch (ex) { toastError(ex); }
            render();
        });
        panel.querySelector('#pf-swap').onclick = () => { m.remove(); swapTokenDialog(server); };
        panel.querySelector('#pf-delete').onclick = () => { m.remove(); deleteMeDialog(server); };
    };

    await render();
}

/* --------------------------- devices ------------------------------- */

/** Show a one-time pairing code (and QR) for a device that is not signed in. */
async function addDeviceDialog(server = store.server) {
    if (!(await requireUsername(server))) return;

    let res;
    try { res = await api('/profile/devices/pair', { server, body: {} }); } catch (e) { toastError(e); return; }

    const m = modal(`
        <h2 class="text-lg font-bold text-white">${t('Register a device')}</h2>
        <p class="mt-2 text-sm text-zinc-400">
            ${t('On the other device, open Krotze and scan this code — or go to Profile → “I have a pairing code” and type it in.')}
            ${server.home ? '' : ' ' + t('The other device opens :origin to pair.', { origin: `<span dir="ltr">${esc(server.origin)}</span>` })}
        </p>
        <div class="mt-4 flex justify-center"><canvas id="ad-qr" class="rounded-lg bg-white p-2 max-w-full"></canvas></div>
        <p class="mt-4 text-center font-mono text-2xl tracking-[0.3em] text-white">${esc(res.code)}</p>
        <p id="ad-expiry" class="mt-1 text-center text-xs text-zinc-500"></p>
        <p class="mt-3 text-xs text-zinc-500">
            ${t('The code works once. The new device gets its own key — this device\'s key is never shared.')}
        </p>
        <div class="mt-5 flex justify-end">
            <button data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Done')}</button>
        </div>`);

    m.querySelector('[data-cancel]').onclick = () => m.remove();
    await QRCode.toCanvas(m.querySelector('#ad-qr'), res.url, { width: 220, margin: 1 });

    const until = Date.now() + res.expires_in * 1000;
    const label = m.querySelector('#ad-expiry');
    const tick = setInterval(() => {
        if (!m.isConnected) { clearInterval(tick); return; }
        const left = until - Date.now();
        if (left <= 0) {
            clearInterval(tick);
            label.textContent = t('This code has expired.');
            return;
        }
        label.textContent = t('Expires in :time', { time: fmtCountdown(left) });
    }, 1000);
    label.textContent = t('Expires in :time', { time: fmtCountdown(res.expires_in * 1000) });
}

/** Redeem a pairing code, so this device joins that account. */
function pairingCodeDialog(prefill = '', server = homeServer()) {
    const choices = store.servers.filter((s) => s.status !== 'revoked');
    const m = modal(`
        <h2 class="text-lg font-bold text-white">${t('Enter pairing code')}</h2>
        <p class="mt-2 text-sm text-zinc-400">
            ${t('Type the 8-character code shown on your other device. This device then acts as that user:suffix', {
                suffix: server.state.username ? t(' instead of “:name”', { name: esc(server.state.username) }) : '',
            })}.
        </p>
        <form id="pc-form" class="mt-4 space-y-3">
            ${choices.length > 1 ? `
            <div>
                <label class="block text-sm text-zinc-400">${t('Server')}</label>
                <select id="pc-server" class="mt-1 w-full rounded-lg border border-white/15 bg-[#171522] px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
                    ${choices.map((s) => `<option value="${s.id}" ${s === server ? 'selected' : ''}>${esc(serverLabel(s))} · ${esc(s.suffix)}</option>`).join('')}
                </select>
            </div>` : ''}
            <input name="code" required maxlength="8" autocapitalize="characters" autocomplete="off"
                value="${esc(prefill)}" placeholder="ABCD2345"
                class="w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-center font-mono text-xl uppercase tracking-[0.3em] text-white focus:border-violet-500 focus:outline-none">
            <p id="pc-error" class="hidden text-sm text-red-400 break-words"></p>
            <div class="flex justify-end gap-3">
                <button type="button" data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Cancel')}</button>
                <button class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">${t('Register device')}</button>
            </div>
        </form>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();

    m.querySelector('#pc-form').onsubmit = async (e) => {
        e.preventDefault();
        const err = m.querySelector('#pc-error');
        const code = new FormData(e.target).get('code').trim().toUpperCase();
        const target = serverById(m.querySelector('#pc-server')?.value) || server;
        try {
            const cred = await api('/devices/claim', {
                server: target,
                anonymous: true,
                body: { code, device_name: deviceName() },
            });
            m.remove();
            adoptIdentity(cred, t('You are now signed in as :name.', { name: cred.username }), target);
        } catch (ex) {
            err.textContent = ex.data?.error || ex.message;
            err.classList.remove('hidden');
        }
    };
}

/** Switch this client's identity on one server to the given credentials and start over cleanly. */
function adoptIdentity(cred, message, server = homeServer()) {
    saveIdentity(cred, server);
    server.boxkeySent = null; // a new device registers its E2EE key afresh
    saveServers();
    clearLastChannel();
    sessionStorage.setItem('krotze_flash', message);
    location.reload();
}

/* ---------------------- identity export / import -------------------- */

function passphraseFields(confirm) {
    return `
        <input name="passphrase" type="password" required minlength="8" autocomplete="new-password"
            placeholder="${t('Passphrase (at least 8 characters)')}"
            class="w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
        ${confirm ? `
        <input name="passphrase2" type="password" required minlength="8" autocomplete="new-password"
            placeholder="${t('Repeat passphrase')}"
            class="w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">` : ''}`;
}

async function exportIdentityDialog(server = store.server) {
    if (!(await requireUsername(server))) return;

    const m = modal(`
        <h2 class="text-lg font-bold text-white">${t('Export identity')}</h2>
        ${serverChipHtml(server) ? `<p class="mt-1 text-xs text-zinc-500">${serverChipHtml(server)} ${esc(serverLabel(server))}</p>` : ''}
        <p class="mt-2 text-sm text-zinc-400">
            ${t('Choose a passphrase. The identity token is encrypted with it before it leaves this page, so the file alone is useless to anyone else.')}
        </p>
        <form id="ex-form" class="mt-4 space-y-3">
            ${passphraseFields(true)}
            <p id="ex-error" class="hidden text-sm text-red-400 break-words"></p>
            <div class="flex justify-end gap-3">
                <button type="button" data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Cancel')}</button>
                <button class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">${t('Encrypt & export')}</button>
            </div>
        </form>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();

    m.querySelector('#ex-form').onsubmit = async (e) => {
        e.preventDefault();
        const err = m.querySelector('#ex-error');
        const fd = new FormData(e.target);
        if (fd.get('passphrase') !== fd.get('passphrase2')) {
            err.textContent = t('The two passphrases do not match.');
            err.classList.remove('hidden');
            return;
        }
        const submit = e.target.querySelector('button:not([data-cancel])');
        submit.disabled = true;
        submit.textContent = t('Encrypting…');
        try {
            const res = await api('/profile/transfer', { server, body: {} });
            // v2 names the server, so importing elsewhere lands on the right one.
            const blob = await seal(
                JSON.stringify({ v: 2, token: res.token, username: res.username, origin: server.origin }),
                fd.get('passphrase'),
            );
            m.remove();
            showExportedIdentity(blob, res.username, server);
        } catch (ex) {
            submit.disabled = false;
            submit.textContent = t('Encrypt & export');
            err.textContent = ex.message;
            err.classList.remove('hidden');
        }
    };
}

function showExportedIdentity(blob, username, server) {
    const m = modal(`
        <h2 class="text-lg font-bold text-white">${t('Your encrypted identity')}</h2>
        <p class="mt-2 text-sm text-zinc-400">
            ${t('Keep this safe. Anyone who has both the file and your passphrase can take over “:name”. You can revoke it any time from your profile.', { name: esc(username) })}
        </p>
        <textarea id="ei-blob" readonly rows="5"
            class="mt-3 w-full resize-none rounded-lg border border-white/15 bg-black/40 p-2 font-mono text-[11px] break-all text-zinc-300">${esc(blob)}</textarea>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
            <button id="ei-copy" class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">📋 ${t('Copy')}</button>
            <button id="ei-download" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">⬇ ${t('Download file')}</button>
            <button data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Close')}</button>
        </div>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();
    m.querySelector('#ei-copy').onclick = async () => {
        try {
            await navigator.clipboard.writeText(blob);
            toastInfo(t('Encrypted identity copied.'));
        } catch {
            m.querySelector('#ei-blob').select();
            toastInfo(t('Press Ctrl/Cmd+C to copy.'));
        }
    };
    m.querySelector('#ei-download').onclick = () => {
        const url = URL.createObjectURL(new Blob([blob], { type: 'text/plain' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = `krotze-identity-${username.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}-${(server?.suffix || '').toLowerCase()}.txt`;
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    };
}

function importIdentityDialog(server = store.server) {
    const m = modal(`
        <h2 class="text-lg font-bold text-white">${t('Import identity')}</h2>
        <p class="mt-2 text-sm text-zinc-400">
            ${t('Paste an exported identity (or pick the file) and enter its passphrase. This device then continues as that user:suffix', {
                suffix: server.state.username ? t(', no longer as “:name”', { name: esc(server.state.username) }) : '',
            })}.
        </p>
        <form id="im-form" class="mt-4 space-y-3">
            <input id="im-file" type="file" accept=".txt,text/plain"
                class="w-full text-xs text-zinc-400 file:me-2 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-1.5 file:text-xs file:text-zinc-200">
            <textarea name="blob" required rows="4" placeholder="KRZ1.…"
                class="w-full resize-none rounded-lg border border-white/15 bg-white/5 p-2 font-mono text-[11px] break-all text-white focus:border-violet-500 focus:outline-none"></textarea>
            ${passphraseFields(false)}
            <p id="im-error" class="hidden text-sm text-red-400 break-words"></p>
            <div class="flex justify-end gap-3">
                <button type="button" data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Cancel')}</button>
                <button class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">${t('Import')}</button>
            </div>
        </form>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();
    m.querySelector('#im-file').onchange = async (e) => {
        const file = e.target.files[0];
        if (file) m.querySelector('[name="blob"]').value = (await file.text()).trim();
    };

    m.querySelector('#im-form').onsubmit = async (e) => {
        e.preventDefault();
        const err = m.querySelector('#im-error');
        const fd = new FormData(e.target);
        const submit = e.target.querySelector('button:not([data-cancel])');
        submit.disabled = true;
        submit.textContent = t('Decrypting…');
        try {
            const payload = JSON.parse(await unseal(fd.get('blob'), fd.get('passphrase')));
            // A v2 file names its server; the identity goes there — adding
            // the server on the way if this device does not know it yet. A v1
            // file lands on the server this dialog was opened for.
            const origin = payload.origin ? normalizeOrigin(payload.origin) : null;
            let target = origin ? serverByOrigin(origin) : server;
            let fresh = null;
            if (!target) {
                fresh = initRuntime(newServerEntry(origin));
                target = fresh;
            }
            const cred = await api('/devices/import', {
                server: target,
                anonymous: true,
                body: { token: payload.token, device_name: deviceName() },
            });
            if (fresh) {
                store.servers.push(fresh);
                assignSuffixes();
                assignColors();
            }
            m.remove();
            adoptIdentity(cred, t('You are now signed in as :name.', { name: cred.username }), target);
        } catch (ex) {
            submit.disabled = false;
            submit.textContent = t('Import');
            err.textContent = ex.data?.error || ex.message;
            err.classList.remove('hidden');
        }
    };
}

/* ------------------------ swap token / delete ----------------------- */

/**
 * Roll the identity's keys: every device (and every exported identity file)
 * stops working, and this device gets a brand-new one. Channels, messages and
 * memberships are untouched — only the credentials change.
 */
async function swapTokenDialog(server = store.server) {
    const ok = await confirmDialog({
        title: t('Swap my token?'),
        text: t('A new token is created for you. Every other device is signed out and all exported identity files stop working. You keep your username, channels and messages, and this device stays signed in.'),
        confirmLabel: t('Swap token'),
    });
    if (!ok) return;
    try {
        const cred = await api('/profile/swap-token', { server, body: { device_name: deviceName() } });
        adoptIdentity(cred, t('Your token was swapped — other devices are signed out.'), server);
    } catch (e) { toastError(e); }
}

async function deleteMeDialog(server = store.server) {
    const others = store.servers.filter((s) => s !== server && s.identity);
    const ok = await confirmDialog({
        title: t('Delete all my data?'),
        text: t('This permanently deletes your identity, all your messages and uploaded files in every channel, your memberships, your private conversations, and every channel you own (for all members). This cannot be undone.')
            + (others.length ? ' ' + t('Your identities on other servers stay on this device.') : ''),
        confirmLabel: t('Delete everything'),
    });
    if (!ok) return;
    try {
        await api('/me', { method: 'DELETE', server });
    } catch { /* identity gone either way */ }

    if (!server.home) {
        // Only that server is affected; the app keeps running on the rest.
        await dropRelay(server).catch(() => {});
        removeServerLocally(server);
        toastInfo(t('Server removed.'));
        return;
    }
    if (!others.length) {
        localStorage.clear();
    } else {
        // Keep what still belongs to somebody: the other servers' identities,
        // the shared E2EE keypair and the language.
        server.identity = null;
        server.boxkeySent = null;
        server.status = 'ok';
        saveServers();
        for (const k of ['krotze_last_channel', 'krotze_shown_notifs', 'krotze_show_hidden', 'krotze_dismissed_version', REG_INVITE_KEY]) {
            localStorage.removeItem(k);
        }
    }
    location.href = '/';
}

/* ------------------------------ servers UI ----------------------------- */

/** Every server this device is on, with a way to add, tune and drop them. */
function serversDialog() {
    const home = homeServer();
    const m = modal(`
        <h2 class="text-lg font-bold text-white">🌐 ${t('Servers')}</h2>
        <p class="mt-1 text-sm text-zinc-400">${t('Channels from every server appear in one list; the tag shows where each one lives.')}</p>
        <div id="sv-list" class="mt-4 space-y-2"></div>
        <p class="mt-3 text-xs text-zinc-500 break-words">
            ${t('Notifications while Krotze is closed come through :home, which relays them for the other servers.', { home: `<b>${esc(serverLabel(home))}</b>` })}
        </p>
        <div class="mt-5 flex flex-wrap justify-end gap-3">
            <button data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Close')}</button>
            <button id="sv-add" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">➕ ${t('Add server')}</button>
        </div>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();
    m.querySelector('#sv-add').onclick = () => { m.remove(); addServerDialog(); };

    const list = m.querySelector('#sv-list');
    for (const s of store.servers) {
        const problem = serverProblem(s);
        const push = !s.home && usableServer(s)
            ? ' · ' + (s.pushRelay ? t('Push relayed via :home', { home: serverLabel(home) }) : t('Push not available on this server'))
            : '';
        const who = s.state.username ? t('Signed in as :name', { name: s.state.username }) : t('No username yet');
        const row = document.createElement('div');
        row.className = 'rounded-xl border border-white/10 p-3';
        row.innerHTML = `
            <div class="flex items-center gap-2">
                ${serverChipHtml(s, { always: true })}
                <span class="min-w-0 flex-1 truncate font-semibold text-white">${esc(serverLabel(s))}</span>
                ${s.home ? `<span class="shrink-0 rounded-full bg-white/10 px-2 py-0.5 text-[11px] text-zinc-300">${t('this site')}</span>` : ''}
            </div>
            <p class="mt-1 truncate text-xs text-zinc-500" dir="ltr">${esc(s.origin)}</p>
            <p class="mt-1 text-xs break-words ${problem ? 'text-amber-300' : 'text-zinc-400'}">${esc(problem || who)}${esc(push)}</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <button data-edit="${s.id}" class="rounded-lg border border-white/20 px-3 py-1.5 text-xs hover:bg-white/10">✏️ ${t('Edit server')}</button>
                ${s.status === 'revoked' ? `<button data-register="${s.id}" class="rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-500">${t('Register again')}</button>` : ''}
                ${s.home ? '' : `<button data-remove="${s.id}" class="rounded-lg border border-red-500/40 px-3 py-1.5 text-xs text-red-400 hover:bg-red-500/10">${t('Remove server')}</button>`}
            </div>`;
        list.appendChild(row);
    }
    list.onclick = (e) => {
        const b = e.target.closest('button');
        if (!b) return;
        if (b.dataset.edit) { m.remove(); editServerDialog(serverById(b.dataset.edit)); }
        if (b.dataset.remove) { m.remove(); removeServerDialog(serverById(b.dataset.remove)); }
        if (b.dataset.register) {
            const s = serverById(b.dataset.register);
            m.remove();
            removeServerLocally(s);
            addServerDialog(s.origin);
        }
    };
}

/**
 * Join another Krotze server: find it, register a fresh identity there (with
 * an invite token if it is closed), and pull its channels into the list.
 */
function addServerDialog(prefill = '', { thenJoin = null } = {}) {
    const home = homeServer();
    const m = modal(`
        <h2 class="text-lg font-bold text-white">➕ ${t('Add server')}</h2>
        <p class="mt-2 text-sm text-zinc-400 break-words">
            ${t('You get a separate identity on that server — nothing is shared with :home.', { home: `<b>${esc(serverLabel(home))}</b>` })}
        </p>
        <form id="as-form" class="mt-4 space-y-3">
            <div>
                <label class="block text-sm text-zinc-400">${t('Server address')}</label>
                <input name="url" required value="${esc(prefill)}" placeholder="${t('e.g. https://chat.example.org')}" autocomplete="off" inputmode="url" dir="ltr"
                    class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
            </div>
            <div id="as-invite-wrap" class="hidden">
                <label class="block text-sm text-zinc-400">${t('Invite token')}</label>
                <input name="invite" autocomplete="off" dir="ltr"
                    class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
                <p class="mt-1 text-xs text-zinc-500">${t('Registrations on this server are closed. Enter an invite token or link from its admin.')}</p>
            </div>
            <p id="as-error" class="hidden text-sm text-red-400 break-words"></p>
            <div class="flex justify-end gap-3">
                <button type="button" data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Cancel')}</button>
                <button id="as-ok" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">${t('Add and continue')}</button>
            </div>
        </form>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();
    const err = m.querySelector('#as-error');
    const fail = (message) => {
        err.textContent = message;
        err.classList.remove('hidden');
    };

    m.querySelector('#as-form').onsubmit = async (e) => {
        e.preventDefault();
        err.classList.add('hidden');
        const fd = new FormData(e.target);
        const origin = normalizeOrigin(fd.get('url'));
        if (!origin) { fail(t('That is not a valid server address.')); return; }
        if (location.protocol === 'https:' && origin.startsWith('http:')) { fail(t('Use an https address.')); return; }
        const existing = serverByOrigin(origin);
        if (existing) {
            m.remove();
            toastInfo(t('That server is already added.'));
            editServerDialog(existing);
            return;
        }

        const submit = m.querySelector('#as-ok');
        submit.disabled = true;
        submit.textContent = t('Loading…');
        const entry = initRuntime(newServerEntry(origin));
        try {
            // Is there a Krotze at all? Its push key is the one thing every
            // version answers without an identity.
            let probe;
            try {
                probe = await api('/push/key', { server: entry, anonymous: true, timeout: 8000 });
            } catch (ex) {
                throw new Error(ex.network ? t('Could not reach that address.') : t('This is not a Krotze server, or it is too old.'));
            }
            if (typeof probe?.enabled !== 'boolean') throw new Error(t('This is not a Krotze server, or it is too old.'));

            const invite = String(fd.get('invite') || '').trim().match(/([A-Za-z0-9]+)\/?$/)?.[1] || null;
            let cred;
            try {
                cred = await api('/session', { server: entry, anonymous: true, body: { device_name: deviceName(), invite } });
            } catch (ex) {
                if (ex.data?.reason === 'registration_closed') {
                    m.querySelector('#as-invite-wrap').classList.remove('hidden');
                    throw new Error(invite ? ex.message : t('Registrations on this server are closed. Enter an invite token or link from its admin.'));
                }
                if (ex.status === 426) throw new Error(t('This is not a Krotze server, or it is too old.'));
                throw ex;
            }

            store.servers.push(entry);
            saveIdentity(cred, entry);
            assignSuffixes();
            assignColors();
            saveServers();
            m.remove();
            toastInfo(t('Server added.'));
            await refreshState({ only: entry });
            ensureBoxKey(entry);
            syncRelays();
            if (entry.status !== 'denied') await usernameDialog({ required: true, server: entry });
            renderChannelList();
            if (thenJoin && usableServer(entry)) joinFlow(thenJoin, entry);
        } catch (ex) {
            submit.disabled = false;
            submit.textContent = t('Add and continue');
            fail(ex.message);
        }
    };
}

/** Nickname and colour — what tells this server apart in the list. */
function editServerDialog(server) {
    let color = server.color;
    const m = modal(`
        <h2 class="text-lg font-bold text-white">✏️ ${t('Edit server')}</h2>
        <form id="es-form" class="mt-4 space-y-3">
            <div>
                <label class="block text-sm text-zinc-400">${t('Nickname (optional)')}</label>
                <input name="nick" maxlength="40" value="${esc(server.nick || '')}" placeholder="${esc(hostOf(server.origin))}" autocomplete="off"
                    class="mt-1 w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-white focus:border-violet-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm text-zinc-400">${t('Colour')}</label>
                <div id="es-colors" class="mt-2 flex flex-wrap gap-2">
                    ${SERVER_PALETTE.map((c, i) => `<button type="button" data-color="${i}" style="background:${c}"
                        class="h-8 w-8 rounded-full border-2 ${i === color ? 'border-white' : 'border-transparent'}"></button>`).join('')}
                </div>
            </div>
            <p class="text-xs text-zinc-500">
                ${t('Short tag')}: <span id="es-preview">${serverChipHtml(server, { always: true })}</span>
                · <span dir="ltr">${esc(server.origin)}</span>
            </p>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" data-cancel class="rounded-lg border border-white/20 px-4 py-2 text-sm hover:bg-white/10">${t('Cancel')}</button>
                <button class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">${t('Save')}</button>
            </div>
        </form>`);
    m.querySelector('[data-cancel]').onclick = () => m.remove();
    const preview = () => {
        m.querySelector('#es-preview').innerHTML = serverChipHtml({ ...server, color, nick: m.querySelector('[name="nick"]').value.trim() || null }, { always: true });
    };
    m.querySelector('#es-colors').onclick = (e) => {
        const b = e.target.closest('[data-color]');
        if (!b) return;
        color = Number(b.dataset.color);
        m.querySelectorAll('[data-color]').forEach((x) => {
            x.classList.toggle('border-white', x === b);
            x.classList.toggle('border-transparent', x !== b);
        });
        preview();
    };
    m.querySelector('[name="nick"]').oninput = preview;
    m.querySelector('#es-form').onsubmit = (e) => {
        e.preventDefault();
        server.nick = new FormData(e.target).get('nick').trim() || null;
        server.color = color;
        saveServers();
        m.remove();
        renderChannelList();
        renderChannelHeader();
        renderNotifications();
    };
}

async function removeServerDialog(server) {
    if (server.home) return;
    const res = await confirmDialog({
        title: t('Remove :name from this device?', { name: serverLabel(server) }),
        text: t('Its channels disappear from the list and this device forgets its identity there. Nothing on the server is deleted unless you tick the box.'),
        confirmLabel: t('Remove server'),
        checkbox: server.identity ? t('Also delete my identity and data on this server') : null,
    });
    if (!res) return;
    if (res.checked) {
        try { await api('/me', { method: 'DELETE', server }); } catch { /* best effort */ }
    }
    await dropRelay(server).catch(() => {});
    removeServerLocally(server);
    toastInfo(t('Server removed.'));
}

function removeServerLocally(server) {
    store.servers = store.servers.filter((s) => s !== server);
    assignSuffixes();
    saveServers();
    if (store.current?.server === server) closeChannel();
    if (store.server === server) store.server = homeServer();
    mergeServers();
    renderChannelList();
    renderNotifications();
    updateTitleBadge();
}

/* ----------------------------- polling ----------------------------- */

const handledMoves = new Set();

/**
 * One server's share of the poll: its channels, notifications and account
 * facts. Failures are per server — an unreachable one is marked as such and
 * keeps the channels it last reported, the rest of the list is unaffected.
 */
async function pollServer(server) {
    if (!server.identity || server.status === 'revoked' || server.polling) return;
    server.polling = true;
    try {
        const data = await api('/state', { server, timeout: 8000 });
        server.reachable = true;
        const before = server.status;
        const wasBlocked = before === 'pending' || before === 'denied';
        server.state.userId = data.user_id;
        server.state.username = data.username;
        server.state.uploadRetentionDays = data.upload_retention_days ?? null;
        server.state.uploadViewMinutes = data.upload_view_minutes ?? null;
        server.state.version = data.version ?? null;
        const status = data.account_status || 'approved';
        server.status = status === 'approved' ? 'ok' : status;
        if (server.status !== before) saveServers();

        // Still (or newly) waiting for the admin. At home that means the gate
        // screen — there is no shell to render channels into.
        if (server.status !== 'ok') {
            server.channels = [];
            server.notifications = [];
            if (server.home) renderAccountGate();
            return;
        }
        // Just approved: rebuild the app around the identity.
        if (wasBlocked) {
            if (server.home) {
                delete $app.dataset.gate;
                renderShell();
                renderEmpty();
            }
            toastInfo(t('Your registration was approved. Welcome!'));
        }

        server.channels = data.channels.map((c) => Object.assign(c, { server, key: chanKey(server, c.uuid) }));
        const notes = data.notifications.map((n) => Object.assign(n, { server, key: notifKey(server, n.id) }));

        // Channel got a new uuid (invite rotation): follow it silently.
        const moves = notes.filter((n) => n.type === 'channel_moved');
        server.notifications = notes.filter((n) => n.type !== 'channel_moved');
        for (const n of moves) {
            if (handledMoves.has(n.key)) continue;
            handledMoves.add(n.key);
            markNotifRead(n).catch(() => {});
            const last = getLastChannel();
            if (last?.server === server && last.uuid === n.data.old_uuid) setLastChannel(server, n.data.channel_uuid);
            if (store.current?.server === server && store.current.uuid === n.data.old_uuid) {
                openChannel(n.data.channel_uuid, server);
            }
        }

        if (server.home) checkVersion(data.version);
    } catch (e) {
        if (e?.network) server.reachable = false;
        // 401/403 were handled inside api(); anything else is transient.
    } finally {
        server.polling = false;
    }
}

async function refreshState({ seedNotifications = false, only = null } = {}) {
    const targets = only ? [only] : store.servers;
    await Promise.allSettled(targets.map(pollServer));
    mergeServers();

    notifyNewMessages(store.channels, seedNotifications);
    renderProfileName();

    // The roster fingerprint changes the moment anyone joins, leaves or
    // renames themselves — reload the member list (and the header count)
    // right then, instead of waiting for the next time the channel opens.
    const cur = store.current;
    if (cur) {
        const open = cur.server.channels.find((c) => c.uuid === cur.uuid);
        if (open?.members_hash && store.detail && open.members_hash !== store.detail.members_hash) {
            reloadChannelDetail();
        }
    }

    renderChannelList();
    renderNotifications();
    updateTitleBadge();
}

function checkVersion(serverVersion) {
    if (!serverVersion || serverVersion === APP_VERSION) {
        document.getElementById('update-notice')?.remove();
        return;
    }
    if (serverVersion === localStorage.getItem('krotze_dismissed_version')
        || document.getElementById('update-notice')) return;
    const el = document.createElement('div');
    el.id = 'update-notice';
    el.className = 'fixed top-3 left-1/2 -translate-x-1/2 z-[60] flex items-center gap-2 rounded-full bg-violet-600 text-white text-sm ps-4 pe-1 py-1.5 shadow-xl max-w-[95vw]';
    el.innerHTML = `
        <span class="whitespace-nowrap">${t('Krotze :version is available', { version: `<b>v${esc(serverVersion)}</b>` })}</span>
        <button id="update-reload" class="rounded-full bg-white/20 px-3 py-1.5 font-semibold hover:bg-white/30 whitespace-nowrap">${t('Reload site')}</button>
        <button id="update-dismiss" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full opacity-70 hover:opacity-100 hover:bg-white/20" title="${t('Dismiss')}">✕</button>`;
    document.body.appendChild(el);
    el.querySelector('#update-reload').onclick = () => location.reload();
    el.querySelector('#update-dismiss').onclick = () => {
        localStorage.setItem('krotze_dismissed_version', serverVersion);
        el.remove();
    };
}

function updateTitleBadge() {
    const unread = store.channels.reduce((s, c) => s + (c.unread || 0), 0)
        + store.notifications.length;
    document.title = (unread ? `(${unread}) ` : '') + 'Krotze Chat';
    if ('setAppBadge' in navigator) {
        unread
            ? navigator.setAppBadge(unread).catch(() => {})
            : navigator.clearAppBadge?.().catch(() => {});
    }
}

/* ----------------------- viewport / keyboard ------------------------ */

/**
 * An on-screen keyboard shrinks the *visual* viewport but leaves the layout
 * viewport alone, so a full-height shell keeps its lower part — the composer
 * and the newest messages — hidden behind the keyboard. Size the shell from
 * visualViewport instead, follow the offset iOS introduces when it scrolls the
 * page, and keep the message list pinned to the bottom while it happens.
 */
let viewportRaf = 0;

function scrollMessagesToBottom() {
    const box = document.getElementById('messages');
    if (box) box.scrollTop = box.scrollHeight;
}

function syncViewport() {
    const vv = window.visualViewport;
    if (!vv) return;
    cancelAnimationFrame(viewportRaf);
    viewportRaf = requestAnimationFrame(() => {
        document.documentElement.style.setProperty('--app-h', `${Math.round(vv.height)}px`);
        $app.style.transform = vv.offsetTop ? `translateY(${Math.round(vv.offsetTop)}px)` : '';
        if (store.stickToBottom) scrollMessagesToBottom();
    });
}

function initViewport() {
    const vv = window.visualViewport;
    if (!vv) return; // browsers without it fall back to the CSS 100dvh height
    vv.addEventListener('resize', syncViewport);
    vv.addEventListener('scroll', syncViewport);
    window.addEventListener('orientationchange', () => setTimeout(syncViewport, 300));
    syncViewport();
}

/* ------------------------------ boot ------------------------------- */

async function boot() {
    document.documentElement.lang = getLang();
    document.documentElement.dir = dir();
    loadServers();
    renderShell();
    renderEmpty();
    initViewport();

    // Registration invite from the admin: keep the token, then continue as a
    // normal visit — ensureIdentity() presents it when it registers this device.
    const registerMatch = location.pathname.match(/^\/register\/([A-Za-z0-9]+)/);
    if (registerMatch) {
        localStorage.setItem(REG_INVITE_KEY, registerMatch[1]);
        history.replaceState(null, '', '/app');
    }

    // A device paired from another one lands here before it has any identity.
    const readPairCode = () => location.hash.match(/^#pair=([A-Za-z0-9]{8})$/)?.[1];
    const pairCode = readPairCode();
    if (pairCode) history.replaceState(null, '', '/app');

    // Scanning the QR while Krotze is already open only changes the hash — the
    // page is not reloaded, so pick that up too.
    // A notification tap lands on #c=<uuid>, plus &s=<origin> when the chat
    // lives on another server than the one that delivered it.
    const readChannelLink = () => {
        const m = location.hash.match(/^#c=([0-9a-f-]{36})(?:&s=([^&]+))?$/);
        return m ? { uuid: m[1], origin: m[2] ? decodeURIComponent(m[2]) : null } : null;
    };
    window.addEventListener('hashchange', () => {
        // Tapping a notification while the app is already open navigates the
        // existing tab to #c=<uuid> — open that chat instead of reloading.
        const link = readChannelLink();
        if (link) {
            history.replaceState(null, '', '/app');
            const ch = findChannelByUuid(link.uuid, link.origin);
            if (ch && ch.status === 'approved') openChannel(ch.uuid, ch.server);
            return;
        }
        const code = readPairCode();
        if (!code) return;
        history.replaceState(null, '', '/app');
        pairingCodeDialog(code.toUpperCase());
    });

    // Only a device without any home identity is stuck when the home server
    // cannot be reached; one that has an identity carries on with whatever
    // servers do answer.
    try {
        await ensureIdentity();
    } catch (e) {
        if (e?.data?.reason === 'registration_closed') {
            renderRegistrationClosed(e.message);
            return;
        }
        document.getElementById('main').innerHTML =
            `<p class="p-8 text-center text-zinc-400">${t('Could not reach the server. Please try again later.')}</p>`;
        return;
    }

    if (sessionStorage.getItem('krotze_revoked')) {
        sessionStorage.removeItem('krotze_revoked');
        toastError({ message: t('This device was removed from that account.') });
    }
    const flash = sessionStorage.getItem('krotze_flash');
    if (flash) {
        sessionStorage.removeItem('krotze_flash');
        toastInfo(flash);
    }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
        // The push service rotated this device's endpoint; register the new one.
        navigator.serviceWorker.addEventListener('message', (e) => {
            if (e.data?.type === 'push-resubscribe') enablePush();
        });
    }
    // Ask after the first user interaction, not on load — and not at all if
    // this device has been switched off in the settings.
    if ('Notification' in window && Notification.permission === 'default' && notifySettings().enabled) {
        document.body.addEventListener('click', async () => {
            await Notification.requestPermission();
            enablePush();
        }, { once: true });
    }

    // First poll only records where every channel stands — otherwise opening
    // the app would replay every message that arrived while it was closed.
    await refreshState({ seedNotifications: true });

    if (pairCode) {
        pairingCodeDialog(pairCode.toUpperCase());
        return;
    }

    // Nothing below this point works until an admin has let this identity in.
    const home = homeServer();
    if (home.status !== 'ok') {
        if (home.status === 'pending' && !home.state.username) {
            await usernameDialog({ required: true, server: home });
            renderAccountGate();
        }
        startPolling();
        return;
    }

    // Subscribing needs an identity to attach the subscription to, so it waits
    // until here. Failure is silent and simply leaves the poll loop in charge.
    enablePush();

    // Register this device's E2EE public key on every server so private
    // conversations can encrypt to it. Also silent on failure — retried on
    // the next boot.
    store.servers.forEach((s) => ensureBoxKey(s));

    // Every channel action needs the name other members will see.
    if (!home.state.username && home.reachable !== false) await usernameDialog({ required: true, server: home });

    // Deep links: /join/<token>, #create, or a channel from a notification
    const joinMatch = location.pathname.match(/^\/join\/([A-Za-z0-9]+)/);
    const link = readChannelLink();
    const linked = link ? findChannelByUuid(link.uuid, link.origin) : null;
    const approved = (c) => c.status === 'approved';
    if (joinMatch) {
        history.replaceState(null, '', '/app');
        joinFlow(joinMatch[1], home);
    } else if (location.hash === '#create') {
        history.replaceState(null, '', '/app');
        createChannelDialog();
    } else if (linked && approved(linked)) {
        history.replaceState(null, '', '/app');
        await openChannel(linked.uuid, linked.server);
    } else {
        // Re-open the last opened chat
        const last = getLastChannel();
        const lastChannel = last && last.server.channels.find((c) => c.uuid === last.uuid && approved(c));
        if (lastChannel) {
            await openChannel(lastChannel.uuid, lastChannel.server);
        } else if (store.channels.some(approved)) {
            const first = sortedChannels().find(approved);
            await openChannel(first.uuid, first.server);
        }
    }

    startPolling();
}

let pollTimer = null;

function startPolling() {
    if (pollTimer) return;
    pollTimer = setInterval(() => {
        refreshState();
        if (store.current && store.current.server.status === 'ok') loadMessages();
    }, 4000);
}

boot();
