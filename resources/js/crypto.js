/* ------------------------------------------------------------------ */
/* Krotze crypto — SHA-256, HMAC-SHA256, PBKDF2 and a passphrase cipher */
/*                                                                      */
/* Deliberately dependency-free and synchronous: crypto.subtle only     */
/* exists in secure contexts, but request signing has to work wherever  */
/* the app runs (including plain-http development hosts).               */
/* ------------------------------------------------------------------ */

const K = new Uint32Array([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
]);

const encoder = new TextEncoder();
const decoder = new TextDecoder();

const rotr = (x, n) => (x >>> n) | (x << (32 - n));

export function bytes(data) {
    if (data instanceof Uint8Array) return data;
    if (data instanceof ArrayBuffer) return new Uint8Array(data);
    return encoder.encode(String(data ?? ''));
}

export function concat(...parts) {
    const list = parts.map(bytes);
    const out = new Uint8Array(list.reduce((n, p) => n + p.length, 0));
    let at = 0;
    for (const p of list) { out.set(p, at); at += p.length; }
    return out;
}

export function sha256(data) {
    const msg = bytes(data);
    const bitLen = msg.length * 8;
    const padded = new Uint8Array(64 * Math.ceil((msg.length + 9) / 64));
    padded.set(msg);
    padded[msg.length] = 0x80;

    const dv = new DataView(padded.buffer);
    dv.setUint32(padded.length - 8, Math.floor(bitLen / 4294967296));
    dv.setUint32(padded.length - 4, bitLen >>> 0);

    const h = new Uint32Array([
        0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
        0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
    ]);
    const w = new Uint32Array(64);

    for (let off = 0; off < padded.length; off += 64) {
        for (let i = 0; i < 16; i++) w[i] = dv.getUint32(off + i * 4);
        for (let i = 16; i < 64; i++) {
            const a = w[i - 15];
            const b = w[i - 2];
            const s0 = rotr(a, 7) ^ rotr(a, 18) ^ (a >>> 3);
            const s1 = rotr(b, 17) ^ rotr(b, 19) ^ (b >>> 10);
            w[i] = (w[i - 16] + s0 + w[i - 7] + s1) >>> 0;
        }

        let [a, b, c, d, e, f, g, hh] = h;
        for (let i = 0; i < 64; i++) {
            const t1 = (hh + (rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25))
                + ((e & f) ^ (~e & g)) + K[i] + w[i]) >>> 0;
            const t2 = ((rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22))
                + ((a & b) ^ (a & c) ^ (b & c))) >>> 0;
            hh = g; g = f; f = e;
            e = (d + t1) >>> 0;
            d = c; c = b; b = a;
            a = (t1 + t2) >>> 0;
        }
        h[0] = (h[0] + a) >>> 0; h[1] = (h[1] + b) >>> 0;
        h[2] = (h[2] + c) >>> 0; h[3] = (h[3] + d) >>> 0;
        h[4] = (h[4] + e) >>> 0; h[5] = (h[5] + f) >>> 0;
        h[6] = (h[6] + g) >>> 0; h[7] = (h[7] + hh) >>> 0;
    }

    const out = new Uint8Array(32);
    const outView = new DataView(out.buffer);
    for (let i = 0; i < 8; i++) outView.setUint32(i * 4, h[i]);
    return out;
}

export function hmacSha256(key, data) {
    let k = bytes(key);
    if (k.length > 64) k = sha256(k);

    const ipad = new Uint8Array(64);
    const opad = new Uint8Array(64);
    ipad.set(k);
    opad.set(k);
    for (let i = 0; i < 64; i++) { ipad[i] ^= 0x36; opad[i] ^= 0x5c; }

    return sha256(concat(opad, sha256(concat(ipad, bytes(data)))));
}

export function pbkdf2(password, salt, iterations, dkLen) {
    const pw = bytes(password);
    const saltBytes = bytes(salt);
    const out = new Uint8Array(dkLen);
    const counter = new Uint8Array(4);
    const counterView = new DataView(counter.buffer);

    let done = 0;
    for (let block = 1; done < dkLen; block++) {
        counterView.setUint32(0, block);
        let u = hmacSha256(pw, concat(saltBytes, counter));
        const t = u.slice();
        for (let i = 1; i < iterations; i++) {
            u = hmacSha256(pw, u);
            for (let j = 0; j < 32; j++) t[j] ^= u[j];
        }
        const take = Math.min(32, dkLen - done);
        out.set(t.subarray(0, take), done);
        done += take;
    }
    return out;
}

/* ------------------------------ encoding --------------------------- */

export function hex(data) {
    let s = '';
    for (const b of bytes(data)) s += b.toString(16).padStart(2, '0');
    return s;
}

export function randomBytes(n) {
    return crypto.getRandomValues(new Uint8Array(n));
}

/** RFC 4648 base32 without padding — letters and digits that survive being read aloud. */
export function base32(data) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = 0, value = 0, out = '';
    for (const b of bytes(data)) {
        value = (value << 8) | b;
        bits += 8;
        while (bits >= 5) {
            out += alphabet[(value >>> (bits - 5)) & 31];
            bits -= 5;
        }
    }
    if (bits > 0) out += alphabet[(value << (5 - bits)) & 31];
    return out;
}

function b64encode(data) {
    let s = '';
    for (const b of bytes(data)) s += String.fromCharCode(b);
    return btoa(s);
}

function b64decode(text) {
    const raw = atob(text);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
}

/* ------------------- passphrase-protected payloads ------------------ */

const MAGIC = 'KRZ1';

// Native PBKDF2 is orders of magnitude faster, so it can afford a real work
// factor; the pure-JS path (non-secure origins) uses a lower one to stay
// interactive. The count travels inside the blob, so a file sealed by either
// path opens on either path.
const ITERATIONS = () => (globalThis.crypto?.subtle ? 210000 : 50000);
const MAX_ITERATIONS = 1000000;

/** HMAC-SHA256 in counter mode: a keystream to XOR the plaintext with. */
function keystream(key, nonce, length) {
    const out = new Uint8Array(length);
    const counter = new Uint8Array(4);
    const counterView = new DataView(counter.buffer);
    for (let done = 0, block = 0; done < length; block++, done += 32) {
        counterView.setUint32(0, block);
        out.set(hmacSha256(key, concat(nonce, counter)).subarray(0, Math.min(32, length - done)), done);
    }
    return out;
}

async function derive(passphrase, salt, iterations) {
    let keys;
    if (globalThis.crypto?.subtle) {
        const base = await crypto.subtle.importKey('raw', bytes(passphrase), 'PBKDF2', false, ['deriveBits']);
        keys = new Uint8Array(await crypto.subtle.deriveBits(
            { name: 'PBKDF2', hash: 'SHA-256', salt, iterations }, base, 512));
    } else {
        keys = pbkdf2(passphrase, salt, iterations, 64);
    }
    return { enc: keys.subarray(0, 32), mac: keys.subarray(32) };
}

/** Encrypt-then-MAC a string with a passphrase. Returns a printable blob. */
export async function seal(plaintext, passphrase) {
    const salt = randomBytes(16);
    const nonce = randomBytes(16);
    const iterations = ITERATIONS();
    const { enc, mac } = await derive(passphrase, salt, iterations);

    const data = bytes(plaintext);
    const cipher = keystream(enc, nonce, data.length);
    for (let i = 0; i < data.length; i++) cipher[i] ^= data[i];

    const tag = hmacSha256(mac, concat(nonce, cipher));
    return [MAGIC, iterations, b64encode(salt), b64encode(nonce), b64encode(cipher), b64encode(tag)].join('.');
}

/** Reverse of seal(). Throws if the passphrase is wrong or the blob is damaged. */
export async function open(blob, passphrase) {
    const parts = String(blob).trim().replace(/\s+/g, '').split('.');
    if (parts.length !== 6 || parts[0] !== MAGIC) {
        throw new Error('This does not look like a Krotze identity file.');
    }

    const iterations = Number(parts[1]);
    if (!Number.isInteger(iterations) || iterations < 1 || iterations > MAX_ITERATIONS) {
        throw new Error('This identity file is damaged.');
    }

    let salt, nonce, cipher, tag;
    try {
        [salt, nonce, cipher, tag] = parts.slice(2).map(b64decode);
    } catch {
        throw new Error('This identity file is damaged.');
    }

    const { enc, mac } = await derive(passphrase, salt, iterations);
    const expected = hmacSha256(mac, concat(nonce, cipher));
    if (expected.length !== tag.length || !expected.every((b, i) => b === tag[i])) {
        throw new Error('Wrong passphrase.');
    }

    const stream = keystream(enc, nonce, cipher.length);
    const plain = new Uint8Array(cipher.length);
    for (let i = 0; i < cipher.length; i++) plain[i] = cipher[i] ^ stream[i];
    return decoder.decode(plain);
}
