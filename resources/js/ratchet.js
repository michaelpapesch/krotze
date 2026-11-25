import nacl from 'tweetnacl';
import { concat, hmacSha256 } from './crypto.js';

/* ------------------------------------------------------------------ */
/* Krotze Double Ratchet — private 1:1 conversations.                  */
/*                                                                      */
/* The Signal design (https://signal.org/docs/specifications/          */
/* doubleratchet/ and /x3dh/), per pair of devices:                     */
/*                                                                      */
/*   X3DH   the first message of a session carries the sender's        */
/*          identity key and an ephemeral key; the receiver combines   */
/*          them with its identity key and its current prekey:          */
/*            SK = HKDF(DH(IKa,SPKb) ‖ DH(EKa,IKb) ‖ DH(EKa,SPKb))      */
/*          Prekeys are not signed (an X25519 identity cannot sign): a */
/*          prekey swapped by the server still leaves DH(EKa,IKb), which */
/*          only the real device can compute, so the server learns     */
/*          nothing — identities themselves are checked with the       */
/*          safety number. There are no one-time prekeys; prekeys      */
/*          rotate weekly instead.                                      */
/*   DR     a DH ratchet on every change of direction plus symmetric   */
/*          chains per message: every message key is used once and     */
/*          forgotten (forward secrecy), and a stolen state heals with */
/*          the next DH step (post-compromise security).               */
/*                                                                      */
/* Pure functions over JSON-serialisable state (base64 strings); the   */
/* caller stores sessions and makes sure only one tab works on them.   */
/* `decrypt` works on a copy and returns the new state only on success, */
/* so a failed or foreign message never damages a session.              */
/* ------------------------------------------------------------------ */

const MAX_SKIP = 1000;          // message keys one step may skip ahead
const MAX_SKIPPED_KEPT = 2000;  // stored skipped keys per session
const encoder = new TextEncoder();
const decoder = new TextDecoder();

export function b64(bytes) {
    let s = '';
    for (const b of bytes) s += String.fromCharCode(b);
    return btoa(s);
}

export function b64d(text) {
    const raw = atob(text);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
}

/** HKDF-SHA256 (RFC 5869). */
function hkdf(ikm, salt, info, length) {
    const prk = hmacSha256(salt, ikm);
    const out = new Uint8Array(length);
    let block = new Uint8Array(0);
    for (let i = 1, at = 0; at < length; i++) {
        block = hmacSha256(prk, concat(block, encoder.encode(info), new Uint8Array([i])));
        out.set(block.subarray(0, Math.min(block.length, length - at)), at);
        at += block.length;
    }
    return out;
}

const dh = (secretB64, publicB64) => nacl.scalarMult(b64d(secretB64), b64d(publicB64));

export function newKeyPair() {
    const pair = nacl.box.keyPair();
    return { pub: b64(pair.publicKey), sec: b64(pair.secretKey) };
}

function kdfRk(rk, dhOut) {
    const out = hkdf(dhOut, b64d(rk), 'krotze-dr-root', 64);
    return [b64(out.subarray(0, 32)), b64(out.subarray(32))];
}

function kdfCk(ck) {
    const key = b64d(ck);
    return [b64(hmacSha256(key, new Uint8Array([2]))), hmacSha256(key, new Uint8Array([1]))];
}

/** Message key → secretbox key and nonce, bound to the header and `ad`. */
function messageCipher(mk, headerText, ad) {
    const out = hkdf(mk, new Uint8Array(32), 'krotze-dr-msg|' + ad + '|' + headerText, 56);
    return { key: out.subarray(0, 32), nonce: out.subarray(32) };
}

function x3dh(parts) {
    const ikm = concat(new Uint8Array(32).fill(0xff), ...parts);
    return b64(hkdf(ikm, new Uint8Array(32), 'krotze-x3dh', 32));
}

/**
 * Start a session as the sender. `me.ik` is this device's identity keypair;
 * `peer` is the other device as the server lists it: {id, key, prekey, prekey_id}.
 */
export function initiate(me, peer) {
    const ek = newKeyPair();
    const sk = x3dh([dh(me.ik.sec, peer.prekey), dh(ek.sec, peer.key), dh(ek.sec, peer.prekey)]);
    const dhs = newKeyPair();
    const [rk, cks] = kdfRk(sk, dh(dhs.sec, peer.prekey));
    return {
        peer: peer.id, remoteIk: peer.key,
        rk, cks, ckr: null, dhs, dhr: peer.prekey,
        ns: 0, nr: 0, pn: 0, skipped: {},
        // Sent along until the peer answers, so it can build the session.
        init: { ik: me.ik.pub, ek: ek.pub, spk: peer.prekey_id },
        initEk: ek.pub,
        used: Date.now(),
    };
}

/**
 * Build the receiving side from a first message's `init`. `prekey` is the
 * prekey ({id, pub, sec}) the sender named; null when it is gone.
 */
export function respond(me, prekey, init, peerId) {
    const sk = x3dh([dh(prekey.sec, init.ik), dh(me.ik.sec, init.ek), dh(prekey.sec, init.ek)]);
    return {
        peer: peerId, remoteIk: init.ik,
        rk: sk, cks: null, ckr: null, dhs: { pub: prekey.pub, sec: prekey.sec }, dhr: null,
        ns: 0, nr: 0, pn: 0, skipped: {},
        init: null, initEk: init.ek,
        used: Date.now(),
    };
}

/** Encrypt for one device. Mutates `session`; returns {h, c} (header text, ciphertext). */
export function encrypt(session, plaintext, ad) {
    if (!session.cks) {
        // We only ever received on this session so far (we are the responder
        // and have not sent yet): the DH step on receive already set cks.
        throw new Error('session cannot send yet');
    }
    const [cks, mk] = kdfCk(session.cks);
    const header = { dh: session.dhs.pub, pn: session.pn, n: session.ns };
    if (session.init) header.i = session.init;
    const h = JSON.stringify(header);
    session.cks = cks;
    session.ns += 1;
    session.used = Date.now();
    const { key, nonce } = messageCipher(mk, h, ad);
    return { h, c: b64(nacl.secretbox(encoder.encode(plaintext), nonce, key)) };
}

function trySkipped(state, header, h, c, ad) {
    const id = header.dh + ':' + header.n;
    const mk = state.skipped[id];
    if (!mk) return null;
    const { key, nonce } = messageCipher(b64d(mk), h, ad);
    const plain = nacl.secretbox.open(b64d(c), nonce, key);
    if (!plain) return null;
    delete state.skipped[id];
    return decoder.decode(plain);
}

function skipTo(state, until) {
    if (!state.ckr) return;
    if (until - state.nr > MAX_SKIP) throw new Error('too many skipped messages');
    while (state.nr < until) {
        const [ckr, mk] = kdfCk(state.ckr);
        state.ckr = ckr;
        state.skipped[state.dhr + ':' + state.nr] = b64(mk);
        state.nr += 1;
    }
    const ids = Object.keys(state.skipped);
    for (let i = 0; i < ids.length - MAX_SKIPPED_KEPT; i++) delete state.skipped[ids[i]];
}

/**
 * Decrypt a message of this session. Returns {session, plaintext} with the
 * advanced state, or null when it does not belong here or fails to open —
 * the passed state is left untouched either way.
 */
export function decrypt(session, h, c, ad) {
    let header;
    try {
        header = JSON.parse(h);
        if (typeof header.dh !== 'string' || !Number.isInteger(header.n) || !Number.isInteger(header.pn)) return null;
    } catch {
        return null;
    }
    const state = structuredClone(session);
    try {
        const early = trySkipped(state, header, h, c, ad);
        if (early !== null) return { session: state, plaintext: early };

        if (header.dh !== state.dhr) {
            skipTo(state, header.pn);
            state.pn = state.ns;
            state.ns = 0;
            state.nr = 0;
            state.dhr = header.dh;
            [state.rk, state.ckr] = kdfRk(state.rk, dh(state.dhs.sec, state.dhr));
            state.dhs = newKeyPair();
            [state.rk, state.cks] = kdfRk(state.rk, dh(state.dhs.sec, state.dhr));
        }
        skipTo(state, header.n);
        if (header.n < state.nr) return null; // a key already used: replay
        const [ckr, mk] = kdfCk(state.ckr);
        const { key, nonce } = messageCipher(mk, h, ad);
        const plain = nacl.secretbox.open(b64d(c), nonce, key);
        if (!plain) return null;
        state.ckr = ckr;
        state.nr += 1;
        state.init = null;     // the peer has the session: no more X3DH prefix
        state.used = Date.now();
        return { session: state, plaintext: decoder.decode(plain) };
    } catch {
        return null;
    }
}

/** The X3DH prefix of a header, if it carries one. */
export function headerInit(h) {
    try {
        const i = JSON.parse(h).i;
        return i && typeof i.ik === 'string' && typeof i.ek === 'string' && Number.isInteger(i.spk) ? i : null;
    } catch {
        return null;
    }
}

/** Associated data binding a message to its conversation and both identities. */
export function associatedData(channelUuid, ikA, ikB) {
    return [channelUuid, ...[ikA, ikB].sort()].join('|');
}
