// Run: node tests/js/ratchet.test.mjs — exercises resources/js/ratchet.js
// without a browser: session setup, both directions, out-of-order delivery,
// replays, tampering, simultaneous initiation and a long random exchange.
import assert from 'node:assert/strict';
import { associatedData, decrypt, encrypt, headerInit, initiate, newKeyPair, respond } from '../../resources/js/ratchet.js';

let passed = 0;
const test = (name, fn) => { fn(); passed++; console.log('ok -', name); };

function device(id) {
    const ik = newKeyPair();
    const spk = { id: 1, ...newKeyPair() };
    return { id, ik, spk, listed: { id, key: ik.pub, prekey: spk.pub, prekey_id: spk.id } };
}

const AD = (a, b) => associatedData('chan-1', a.ik.pub, b.ik.pub);

/** Receive on `me` from `from`, managing sessions like the app does. */
function receive(me, sessions, from, msg) {
    const ad = AD(me, from);
    const list = sessions.get(from.id) || [];
    const init = headerInit(msg.h);
    let candidates = list;
    if (init) {
        const match = list.find((s) => s.initEk === init.ek);
        if (match) candidates = [match];
        else {
            assert.equal(init.spk, me.spk.id);
            candidates = [respond({ ik: me.ik }, me.spk, init, from.id)];
        }
    }
    for (const s of candidates) {
        const res = decrypt(s, msg.h, msg.c, ad);
        if (res) {
            const rest = list.filter((x) => x !== s);
            sessions.set(from.id, [res.session, ...rest]); // most recent first = active
            return res.plaintext;
        }
    }
    return null;
}

function send(me, sessions, to, text) {
    let list = sessions.get(to.id) || [];
    if (!list.length || !list[0].cks) {
        const s = initiate({ ik: me.ik }, to.listed);
        list = [s, ...list];
        sessions.set(to.id, list);
    }
    return encrypt(list[0], text, AD(me, to));
}

test('first messages set up the session; replies flow back', () => {
    const a = device('A'), b = device('B');
    const sa = new Map(), sb = new Map();
    const m1 = send(a, sa, b, 'hallo 1');
    const m2 = send(a, sa, b, 'hallo 2');
    assert.ok(headerInit(m1.h));
    assert.equal(receive(b, sb, a, m1), 'hallo 1');
    assert.equal(receive(b, sb, a, m2), 'hallo 2');
    const r1 = send(b, sb, a, 'antwort');
    assert.equal(headerInit(r1.h), null);
    assert.equal(receive(a, sa, b, r1), 'antwort');
    const m3 = send(a, sa, b, 'nach antwort');
    assert.equal(headerInit(m3.h), null, 'no X3DH prefix once the peer answered');
    assert.equal(receive(b, sb, a, m3), 'nach antwort');
});

test('out-of-order delivery and replays', () => {
    const a = device('A'), b = device('B');
    const sa = new Map(), sb = new Map();
    const msgs = [1, 2, 3, 4].map((i) => send(a, sa, b, 'm' + i));
    assert.equal(receive(b, sb, a, msgs[0]), 'm1');
    assert.equal(receive(b, sb, a, msgs[3]), 'm4');
    assert.equal(receive(b, sb, a, msgs[1]), 'm2');
    assert.equal(receive(b, sb, a, msgs[2]), 'm3');
    assert.equal(receive(b, sb, a, msgs[2]), null, 'replay refused');
    assert.equal(receive(b, sb, a, msgs[0]), null, 'replay refused');
    // across a DH step
    const r = send(b, sb, a, 'r');
    const late = send(a, sa, b, 'before reply seen');
    assert.equal(receive(a, sa, b, r), 'r');
    const after = send(a, sa, b, 'after reply seen');
    assert.equal(receive(b, sb, a, after), 'after reply seen');
    assert.equal(receive(b, sb, a, late), 'before reply seen');
});

test('tampering and wrong context are rejected without harming the session', () => {
    const a = device('A'), b = device('B');
    const sa = new Map(), sb = new Map();
    assert.equal(receive(b, sb, a, send(a, sa, b, 'x')), 'x');
    const m = send(a, sa, b, 'secret');
    const header = JSON.parse(m.h);
    const badHeader = { h: JSON.stringify({ ...header, n: header.n }).replace('"pn":', '"pn": '), c: m.c };
    assert.equal(receive(b, sb, a, badHeader), null, 'header bytes are authenticated');
    const raw = Buffer.from(m.c, 'base64'); raw[raw.length - 1] ^= 1;
    assert.equal(receive(b, sb, a, { h: m.h, c: raw.toString('base64') }), null, 'ciphertext is authenticated');
    const s = sb.get('A')[0];
    assert.equal(decrypt(s, m.h, m.c, associatedData('other-chan', a.ik.pub, b.ik.pub)), null, 'bound to the conversation');
    assert.equal(receive(b, sb, a, m), 'secret', 'the real message still opens');
});

test('both sides start at once and converge', () => {
    const a = device('A'), b = device('B');
    const sa = new Map(), sb = new Map();
    const fromA = send(a, sa, b, 'a first');
    const fromB = send(b, sb, a, 'b first');
    assert.equal(receive(b, sb, a, fromA), 'a first');
    assert.equal(receive(a, sa, b, fromB), 'b first');
    for (let i = 0; i < 5; i++) {
        assert.equal(receive(b, sb, a, send(a, sa, b, 'a' + i)), 'a' + i);
        assert.equal(receive(a, sa, b, send(b, sb, a, 'b' + i)), 'b' + i);
    }
});

test('a session never reuses a message key (forward secrecy of past keys)', () => {
    const a = device('A'), b = device('B');
    const sa = new Map(), sb = new Map();
    const m = send(a, sa, b, 'once');
    assert.equal(receive(b, sb, a, m), 'once');
    const snapshot = structuredClone(sb.get('A')[0]);
    assert.equal(Object.keys(snapshot.skipped).length, 0, 'no key for the read message is kept');
    assert.equal(decrypt(snapshot, m.h, m.c, AD(b, a)), null, 'the stored state cannot open it again');
});

test('long random exchange with losses and reordering', () => {
    const a = device('A'), b = device('B');
    const sa = new Map(), sb = new Map();
    let seed = 42;
    const rnd = () => (seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648;
    const pending = [];
    for (let i = 0; i < 400; i++) {
        const fromA = rnd() < 0.5;
        const msg = fromA ? send(a, sa, b, 'A' + i) : send(b, sb, a, 'B' + i);
        pending.push({ fromA, msg, text: (fromA ? 'A' : 'B') + i });
        // deliver a random pending message now and then, sometimes out of order
        while (pending.length && rnd() < 0.6) {
            const k = rnd() < 0.8 ? 0 : Math.floor(rnd() * pending.length);
            const p = pending.splice(k, 1)[0];
            const got = p.fromA ? receive(b, sb, a, p.msg) : receive(a, sa, b, p.msg);
            assert.equal(got, p.text);
        }
    }
    for (const p of pending) assert.equal(p.fromA ? receive(b, sb, a, p.msg) : receive(a, sa, b, p.msg), p.text);
});

console.log(`${passed} ratchet tests passed`);
