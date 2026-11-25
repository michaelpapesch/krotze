import nacl from 'tweetnacl';
import { hex, sha256 } from './crypto.js';

/* ------------------------------------------------------------------ */
/* Krotze E2EE — private 1:1 conversations (text messages) here, and   */
/* encrypted group channels further down.                              */
/*                                                                      */
/* Each device holds an X25519 keypair; only the public half is ever    */
/* uploaded. A message is encrypted once with a random key              */
/* (XSalsa20-Poly1305), and that key is wrapped for every participant   */
/* device — the recipient's and the sender's own others — via an        */
/* ephemeral box. The server stores the envelope and can read nothing.  */
/* A device added later has no wrap for older messages and correctly    */
/* cannot decrypt them.                                                 */
/* ------------------------------------------------------------------ */

const KEY = 'krotze_boxkey';

const encoder = new TextEncoder();
const decoder = new TextDecoder();

function b64(bytes) {
    let s = '';
    for (const b of bytes) s += String.fromCharCode(b);
    return btoa(s);
}

function b64d(text) {
    const raw = atob(text);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
}

/** This device's box keypair, created on first use and kept locally. */
function boxKeys() {
    try {
        const stored = JSON.parse(localStorage.getItem(KEY) || 'null');
        if (stored?.p && stored?.s) {
            return { publicKey: b64d(stored.p), secretKey: b64d(stored.s) };
        }
    } catch { /* regenerate below */ }
    const pair = nacl.box.keyPair();
    try {
        localStorage.setItem(KEY, JSON.stringify({ p: b64(pair.publicKey), s: b64(pair.secretKey) }));
    } catch { /* storage blocked — the pair still works for this session */ }
    return pair;
}

/** This device's identity keypair as base64 — for the Double Ratchet (ratchet.js). */
export function identityKeyPair() {
    const k = boxKeys();
    return { pub: b64(k.publicKey), sec: b64(k.secretKey) };
}

/** Base64 public key, the only part that ever leaves the device. */
export function boxPublicKey() {
    return b64(boxKeys().publicKey);
}

/**
 * Encrypt `plaintext` for the given devices ([{id, key}] with base64 X25519
 * public keys). Returns the JSON envelope stored as the message body.
 */
export function encryptFor(plaintext, devices) {
    const messageKey = nacl.randomBytes(nacl.secretbox.keyLength);
    const nonce = nacl.randomBytes(nacl.secretbox.nonceLength);
    const ciphertext = nacl.secretbox(encoder.encode(plaintext), nonce, messageKey);
    const eph = nacl.box.keyPair();

    const keys = {};
    for (const device of devices) {
        try {
            const wrapNonce = nacl.randomBytes(nacl.box.nonceLength);
            keys[device.id] = {
                n: b64(wrapNonce),
                k: b64(nacl.box(messageKey, wrapNonce, b64d(device.key), eph.secretKey)),
            };
        } catch { /* malformed key — skip that device */ }
    }

    return JSON.stringify({
        v: 1,
        n: b64(nonce),
        ct: b64(ciphertext),
        eph: b64(eph.publicKey),
        keys,
    });
}

/**
 * Safety number of a conversation: a digest over every participant device's
 * public key, in a canonical order — so both sides compute the identical
 * number and can compare it out-of-band. It changes whenever either side adds
 * or removes a device, which is exactly what a comparison is meant to catch.
 * Returns eight groups of four characters, e.g. "3F0A 91BC …".
 */
export function safetyNumber(devices) {
    const keys = devices.map((d) => d.key).sort();
    const digest = hex(sha256(keys.join('|'))).toUpperCase().slice(0, 32);
    return digest.match(/.{4}/g).join(' ');
}

/* ------------------------------------------------------------------ */
/* Group channels: one symmetric channel key per epoch.                 */
/*                                                                      */
/* A member device creates the key (32 random bytes) and wraps it for   */
/* every member device with an ephemeral box; the server keeps the      */
/* wraps and starts a new epoch whenever the member devices change.     */
/*                                                                      */
/*   wrap      {"v":2,"eph":…,"n":…,"k":…}     one per device           */
/*   envelope  {"v":2,"e":epoch,"n":…,"ct":…}  message text, or a file's */
/*             metadata {"name","mime","size"} as JSON                  */
/*   file      nonce(24) ‖ secretbox(bytes)    uploads and thumbnails   */
/* ------------------------------------------------------------------ */

/** A fresh channel key. */
export function newChannelKey() {
    return nacl.randomBytes(nacl.secretbox.keyLength);
}

/** Wrap `key` for every device ([{id, key}]); returns { deviceId: wrapJson }. */
export function wrapChannelKey(key, devices) {
    const eph = nacl.box.keyPair();
    const wraps = {};
    for (const device of devices) {
        const n = nacl.randomBytes(nacl.box.nonceLength);
        wraps[device.id] = JSON.stringify({
            v: 2,
            eph: b64(eph.publicKey),
            n: b64(n),
            k: b64(nacl.box(key, n, b64d(device.key), eph.secretKey)),
        });
    }
    return wraps;
}

/** Open a wrap meant for this device; null when it is not ours or broken. */
export function unwrapChannelKey(wrapJson) {
    try {
        const w = JSON.parse(wrapJson);
        if (w?.v !== 2) return null;
        const key = nacl.box.open(b64d(w.k), b64d(w.n), b64d(w.eph), boxKeys().secretKey);
        return key && key.length === nacl.secretbox.keyLength ? key : null;
    } catch {
        return null;
    }
}

/** Seal text for a channel epoch; returns the envelope JSON. */
export function sealForChannel(text, key, epoch) {
    const n = nacl.randomBytes(nacl.secretbox.nonceLength);
    return JSON.stringify({ v: 2, e: epoch, n: b64(n), ct: b64(nacl.secretbox(encoder.encode(text), n, key)) });
}

/** The epoch a channel envelope names, or null if it is not one. */
export function channelEnvelopeEpoch(envelopeJson) {
    try {
        const env = JSON.parse(envelopeJson);
        return env?.v === 2 && Number.isInteger(env.e) ? env.e : null;
    } catch {
        return null;
    }
}

/** Open a channel envelope with its epoch's key; null when that fails. */
export function openFromChannel(envelopeJson, key) {
    try {
        const env = JSON.parse(envelopeJson);
        const plain = nacl.secretbox.open(b64d(env.ct), b64d(env.n), key);
        return plain ? decoder.decode(plain) : null;
    } catch {
        return null;
    }
}

/** Seal raw bytes (an upload): nonce followed by the secretbox. */
export function sealBytes(bytes, key) {
    const n = nacl.randomBytes(nacl.secretbox.nonceLength);
    const box = nacl.secretbox(bytes, n, key);
    const out = new Uint8Array(n.length + box.length);
    out.set(n);
    out.set(box, n.length);
    return out;
}

/** Open what sealBytes() made; null when it is not authentic. */
export function openBytes(sealed, key) {
    if (sealed.length < nacl.secretbox.nonceLength + nacl.secretbox.overheadLength) return null;
    return nacl.secretbox.open(sealed.subarray(nacl.secretbox.nonceLength), sealed.subarray(0, nacl.secretbox.nonceLength), key);
}

/**
 * Decrypt an envelope with this device's key. Returns the plaintext, or null
 * when there is no wrap for this device (added later, or key rotated) or the
 * envelope fails authentication.
 */
export function decryptEnvelope(envelopeJson, deviceId) {
    try {
        const env = JSON.parse(envelopeJson);
        if (env?.v !== 1) return null;
        const wrap = env.keys?.[deviceId];
        if (!wrap) return null;
        const messageKey = nacl.box.open(b64d(wrap.k), b64d(wrap.n), b64d(env.eph), boxKeys().secretKey);
        if (!messageKey) return null;
        const plain = nacl.secretbox.open(b64d(env.ct), b64d(env.n), messageKey);
        return plain ? decoder.decode(plain) : null;
    } catch {
        return null;
    }
}
