import { associatedData, decrypt, encrypt, headerInit, initiate, newKeyPair, respond } from './ratchet.js';

/* ------------------------------------------------------------------ */
/* Double Ratchet for private conversations: storage and locking       */
/* around ratchet.js.                                                   */
/*                                                                      */
/*   envelope v3  {"v":3,"s":<sender device id>,                        */
/*                 "to":{"<device id>":{"h":<header>,"c":<ciphertext>}}} */
/*                one ratchet message per recipient device (the peer's  */
/*                devices and the sender's own other devices)           */
/*                                                                      */
/* Sessions and the plaintext of every message this device decrypted   */
/* live in IndexedDB: once a message key is used it is gone, so the     */
/* stored plaintext is what keeps the history readable after a reload. */
/* All work on one identity's sessions runs under a Web Lock, so two    */
/* tabs never advance the same chain.                                   */
/* ------------------------------------------------------------------ */

const PREKEYS = 'krotze_prekeys';
const PREKEY_ROTATE_MS = 7 * 24 * 3600 * 1000;
const PREKEYS_KEPT = 4;          // older ones still open first messages in flight
const SESSIONS_PER_PEER = 4;

/* ---------------------------- storage ------------------------------ */

const memory = new Map();        // when IndexedDB is unavailable (some private modes)
let dbPromise = null;

function db() {
    dbPromise ||= new Promise((resolve) => {
        try {
            const req = indexedDB.open('krotze', 1);
            req.onupgradeneeded = () => req.result.createObjectStore('kv');
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => resolve(null);
        } catch {
            resolve(null);
        }
    });
    return dbPromise;
}

async function objectStore(mode) {
    const d = await db();
    return d ? d.transaction('kv', mode).objectStore('kv') : null;
}

const settle = (req) => new Promise((resolve) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => resolve(undefined);
});

async function get(key) {
    const s = await objectStore('readonly');
    return s ? settle(s.get(key)) : memory.get(key);
}

async function put(key, value) {
    const s = await objectStore('readwrite');
    if (s) await settle(s.put(value, key));
    else memory.set(key, value);
}

async function del(key) {
    const s = await objectStore('readwrite');
    if (s) await settle(s.delete(key));
    else memory.delete(key);
}

async function keysFrom(prefix) {
    const s = await objectStore('readonly');
    if (!s) return [...memory.keys()].filter((k) => k.startsWith(prefix));
    return (await settle(s.getAllKeys(IDBKeyRange.bound(prefix, prefix + '￿')))) || [];
}

function withLock(name, fn) {
    return navigator.locks?.request ? navigator.locks.request(name, fn) : fn();
}

/* ---------------------------- prekeys ------------------------------ */

function loadPrekeys() {
    try {
        const list = JSON.parse(localStorage.getItem(PREKEYS) || '[]');
        return Array.isArray(list) ? list.filter((p) => Number.isInteger(p.id) && p.pub && p.sec) : [];
    } catch {
        return [];
    }
}

/**
 * The prekey to publish: the newest one, replaced weekly. The previous few
 * are kept so first messages built on them can still be opened.
 */
export function currentPrekey() {
    let list = loadPrekeys().sort((a, b) => b.id - a.id);
    if (!list.length || Date.now() - (list[0].at || 0) > PREKEY_ROTATE_MS) {
        list = [{ id: (list[0]?.id || 0) + 1, at: Date.now(), ...newKeyPair() }, ...list].slice(0, PREKEYS_KEPT);
        try { localStorage.setItem(PREKEYS, JSON.stringify(list)); } catch { /* storage blocked */ }
    }
    return { id: list[0].id, pub: list[0].pub };
}

const prekeyById = (id) => loadPrekeys().find((p) => p.id === id) || null;

/* ---------------------------- sessions ----------------------------- */

const sessionKey = (serverId, me, peer) => `s:${serverId}:${me}:${peer}`;
const textKey = (serverId, uuid, id) => `p:${serverId}:${uuid}:${id}`;

async function loadSessions(key) {
    const list = await get(key);
    return Array.isArray(list) ? list : [];
}

/** Is this message body a v3 (Double Ratchet) envelope? */
export function isRatchetEnvelope(body) {
    return typeof body === 'string' && body.startsWith('{"v":3,');
}

/**
 * Seal `text` for every listed device except this one. `devices` come from
 * the channel detail ({id, key, prekey, prekey_id}); `me` is {serverId,
 * deviceId, ik: {pub, sec}}. Returns the envelope JSON.
 */
export function sealPrivate(me, channelUuid, devices, text) {
    return withLock(`krotze-dr:${me.serverId}:${me.deviceId}`, async () => {
        const to = {};
        for (const device of devices) {
            if (device.id === me.deviceId) continue;
            const key = sessionKey(me.serverId, me.deviceId, device.id);
            // A device that registered a new identity key is a new device as
            // far as sessions go: old ones cannot reach it any more.
            let list = (await loadSessions(key)).filter((s) => s.remoteIk === device.key);
            let session = list.find((s) => s.cks);
            if (!session) {
                session = initiate({ ik: me.ik }, device);
                list = [session, ...list];
            }
            to[device.id] = encrypt(session, text, associatedData(channelUuid, me.ik.pub, device.key));
            await put(key, [session, ...list.filter((s) => s !== session)].slice(0, SESSIONS_PER_PEER));
        }
        return JSON.stringify({ v: 3, s: me.deviceId, to });
    });
}

/**
 * Plaintext of a v3 message for this device: from the local store if it was
 * opened before, else by advancing the matching session. null when it cannot
 * be read here (sent before this device existed, or state was lost).
 */
export function openPrivate(me, channelUuid, messageId, body) {
    return withLock(`krotze-dr:${me.serverId}:${me.deviceId}`, async () => {
        const cacheKey = textKey(me.serverId, channelUuid, messageId);
        const cached = await get(cacheKey);
        if (cached && 't' in cached) return cached.t;

        const text = await openUncached(me, channelUuid, body);
        await put(cacheKey, { t: text, at: Date.now() });
        return text;
    });
}

async function openUncached(me, channelUuid, body) {
    let env;
    try { env = JSON.parse(body); } catch { return null; }
    const entry = env?.to?.[me.deviceId];
    if (!entry || typeof env.s !== 'string' || typeof entry.h !== 'string' || typeof entry.c !== 'string') return null;

    const key = sessionKey(me.serverId, me.deviceId, env.s);
    const list = await loadSessions(key);
    const init = headerInit(entry.h);
    let candidates = list;
    if (init) {
        const known = list.find((s) => s.initEk === init.ek);
        if (known) {
            candidates = [known];
        } else {
            const prekey = prekeyById(init.spk);
            if (!prekey) return null;
            candidates = [respond({ ik: me.ik }, prekey, init, env.s)];
        }
    }
    for (const session of candidates) {
        const res = decrypt(session, entry.h, entry.c, associatedData(channelUuid, me.ik.pub, session.remoteIk));
        if (!res) continue;
        // The session that just worked is the one to answer on (Sesame).
        await put(key, [res.session, ...list.filter((s) => s !== session)].slice(0, SESSIONS_PER_PEER));
        return res.plaintext;
    }
    return null;
}

/** Remember the plaintext of a message this device sent (it has no copy for itself). */
export function rememberPrivate(serverId, channelUuid, messageId, text) {
    return put(textKey(serverId, channelUuid, messageId), { t: text, at: Date.now() });
}

/** Drop stored plaintext of conversations that no longer exist on a server. */
export async function prunePrivate(serverId, keepUuids) {
    const keep = new Set(keepUuids);
    for (const key of await keysFrom(`p:${serverId}:`)) {
        if (!keep.has(String(key).split(':')[2])) await del(key);
    }
}
