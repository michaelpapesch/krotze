/* ------------------------------------------------------------------ */
/* Fallback video playback for browsers without WebCodecs decoding      */
/* (Firefox for Android, older Safari): the encoded frames that arrive  */
/* over the live socket are wrapped into a media container on the fly   */
/* and played by a muted <video> element through Media Source           */
/* Extensions.                                                          */
/*                                                                      */
/*   VP8           → WebM, one Cluster per frame                        */
/*   H.264 Annex B → fragmented MP4, one moof/mdat per frame            */
/*                                                                      */
/* Audio is not involved — it keeps going through Web Audio.            */
/* ------------------------------------------------------------------ */

const LIVE_EDGE_S = 0.15;   // how far behind the newest frame we aim to play
const MAX_BEHIND_S = 0.8;   // further behind than this: jump to the live edge
const KEEP_S = 6;           // buffered history kept before older data is removed

const isVp8 = (codec) => codec === 'vp8';

function mimeFor(codec, avcCodec) {
    return isVp8(codec) ? 'video/webm; codecs="vp8"' : `video/mp4; codecs="${avcCodec || codec}"`;
}

/** Can this browser play the given live codec through MSE? */
export function mseSupports(codec) {
    const MS = window.MediaSource || window.ManagedMediaSource;
    if (!MS?.isTypeSupported) return false;
    try {
        return MS.isTypeSupported(mimeFor(codec));
    } catch {
        return false;
    }
}

/**
 * A player bound to one <video> element. push() takes the frames exactly
 * as they come off the wire; the first one must be a keyframe.
 *   onPlaying()  called once, when the first picture is on screen
 *   onError()    the pipeline broke; the caller drops the player and starts
 *                a new one at the next keyframe
 */
export function createMsePlayer(video, { codec, width, height }, { onPlaying, onError }) {
    const MS = window.MediaSource || window.ManagedMediaSource;
    const mux = isVp8(codec) ? webmMuxer(width, height) : mp4Muxer();
    const media = new MS();
    const url = URL.createObjectURL(media);
    let buffer = null;
    let mime = null;
    let closed = false;
    let pending = [];          // muxed bytes waiting for the SourceBuffer
    let base = null;           // ms of the first frame → media time 0
    let lastMs = null;
    let playingSeen = false;

    video.muted = true;        // autoplay without a gesture; sound is Web Audio's job
    video.playsInline = true;
    video.disableRemotePlayback = true; // required by ManagedMediaSource (Safari)
    video.src = url;
    media.addEventListener('sourceopen', () => attach(), { once: true });

    const fail = () => {
        if (closed) return;
        close();
        onError?.();
    };

    const onPlayingEvent = () => {
        if (!playingSeen && video.videoWidth) {
            playingSeen = true;
            onPlaying?.();
        }
    };
    video.addEventListener('playing', onPlayingEvent);
    video.addEventListener('loadeddata', onPlayingEvent);
    video.addEventListener('resize', onPlayingEvent);

    function flush() {
        if (closed || !buffer || buffer.updating || !pending.length) return;
        const total = pending.reduce((n, p) => n + p.length, 0);
        const bytes = new Uint8Array(total);
        let at = 0;
        for (const p of pending) { bytes.set(p, at); at += p.length; }
        pending = [];
        try {
            buffer.appendBuffer(bytes);
        } catch {
            fail();
        }
    }

    // Stay at the live edge: skip gaps (frames the server dropped for us)
    // and never fall behind, and keep the buffer short.
    const timer = setInterval(() => {
        if (closed || !buffer) return;
        const ranges = video.buffered;
        if (!ranges.length) return;
        const start = ranges.start(ranges.length - 1);
        const end = ranges.end(ranges.length - 1);
        if (video.currentTime < start || end - video.currentTime > MAX_BEHIND_S) {
            video.currentTime = Math.max(start, end - LIVE_EDGE_S);
        }
        if (video.paused) video.play().catch(() => {});
        if (!buffer.updating && video.currentTime - ranges.start(0) > KEEP_S * 2) {
            try { buffer.remove(0, video.currentTime - KEEP_S); } catch { /* next round */ }
        }
    }, 250);

    // The SourceBuffer needs the MediaSource open and the codec string,
    // which for H.264 comes from the first keyframe's SPS — whichever is last.
    function attach() {
        if (closed || buffer || !mime || media.readyState !== 'open') return;
        try {
            buffer = media.addSourceBuffer(mime);
        } catch {
            fail();
            return;
        }
        buffer.addEventListener('updateend', flush);
        buffer.addEventListener('error', fail);
        video.play().catch(() => {});
        flush();
    }

    function push(isKey, ms, data) {
        if (closed) return;
        if (base === null) {
            if (!isKey) return;
            base = ms;
        }
        // A clock that ran backwards (should not happen): start over.
        if (ms < base) { fail(); return; }
        const t = ms - base;
        const duration = lastMs === null ? 66 : Math.max(1, Math.min(1000, ms - lastMs));
        lastMs = ms;

        const out = mux.frame(isKey, t, duration, data);
        if (!out) return; // H.264 delta before any parameter sets
        mime ||= mimeFor(codec, out.codec);
        pending.push(...out.parts);
        if (buffer) flush();
        else attach();
    }

    function close() {
        if (closed) return;
        closed = true;
        clearInterval(timer);
        video.removeEventListener('playing', onPlayingEvent);
        video.removeEventListener('loadeddata', onPlayingEvent);
        video.removeEventListener('resize', onPlayingEvent);
        pending = [];
        try { if (media.readyState === 'open') media.endOfStream(); } catch { /* closing anyway */ }
        video.removeAttribute('src');
        try { video.load(); } catch { /* detached */ }
        URL.revokeObjectURL(url);
    }

    return {
        push,
        close,
        /** Muxed bytes not yet handed to the browser — a measure of lag. */
        get backlog() { return pending.length; },
    };
}

/* ------------------------------- WebM -------------------------------- */

function webmMuxer(width, height) {
    let started = false;
    return {
        frame(isKey, t, _duration, data) {
            const parts = [];
            if (!started) {
                started = true;
                parts.push(webmHeader(width, height));
            }
            // Cluster(Timecode, SimpleBlock(track 1, relative 0, flags)).
            const block = new Uint8Array(4 + data.length);
            block[0] = 0x81;               // track number 1 as a vint
            block[1] = 0; block[2] = 0;    // timecode relative to the cluster
            block[3] = isKey ? 0x80 : 0x00;
            block.set(data, 4);
            parts.push(ebml(0x1F43B675, [ebmlUint(0xE7, t), ebml(0xA3, [block])]));
            return { parts, codec: 'vp8' };
        },
    };
}

function webmHeader(width, height) {
    const header = ebml(0x1A45DFA3, [
        ebmlUint(0x4286, 1), ebmlUint(0x42F7, 1), ebmlUint(0x42F2, 4), ebmlUint(0x42F3, 8),
        ebmlString(0x4282, 'webm'), ebmlUint(0x4287, 2), ebmlUint(0x4285, 2),
    ]);
    const info = ebml(0x1549A966, [ebmlUint(0x2AD7B1, 1_000_000), ebmlString(0x4D80, 'krotze'), ebmlString(0x5741, 'krotze')]);
    const tracks = ebml(0x1654AE6B, [ebml(0xAE, [
        ebmlUint(0xD7, 1), ebmlUint(0x73C5, 1), ebmlUint(0x83, 1), ebmlString(0x86, 'V_VP8'),
        ebml(0xE0, [ebmlUint(0xB0, width || 640), ebmlUint(0xBA, height || 360)]),
    ])]);
    // Segment of unknown size: it grows for as long as the stream runs.
    const segment = concat([idBytes(0x18538067), new Uint8Array([0x01, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF])]);
    return concat([header, segment, info, tracks]);
}

function idBytes(id) {
    const out = [];
    for (let v = id; v > 0; v = Math.floor(v / 256)) out.unshift(v & 0xff);
    return new Uint8Array(out);
}

function sizeBytes(n) {
    // Always 8 bytes: simple, and valid for any size.
    const out = new Uint8Array(8);
    out[0] = 0x01;
    for (let i = 7, v = n; i >= 1; i--, v = Math.floor(v / 256)) out[i] = v & 0xff;
    return out;
}

function ebml(id, children) {
    const body = concat(children);
    return concat([idBytes(id), sizeBytes(body.length), body]);
}

function ebmlUint(id, value) {
    const bytes = [];
    for (let v = value; v > 0; v = Math.floor(v / 256)) bytes.unshift(v & 0xff);
    if (!bytes.length) bytes.push(0);
    return ebml(id, [new Uint8Array(bytes)]);
}

function ebmlString(id, text) {
    return ebml(id, [new TextEncoder().encode(text)]);
}

/* -------------------------- fragmented MP4 --------------------------- */

function mp4Muxer() {
    let sps = null;
    let pps = null;
    let seq = 0;
    let codecString = null;

    return {
        frame(isKey, t, duration, data) {
            const nals = splitAnnexB(data);
            const parts = [];
            let newSps = null;
            let newPps = null;
            const samples = [];
            for (const nal of nals) {
                const type = nal[0] & 0x1f;
                if (type === 7) newSps = nal;
                else if (type === 8) newPps = nal;
                else if (type !== 9) samples.push(nal); // 9 = access unit delimiter
            }
            if (newSps && newPps && (!sps || !sameBytes(sps, newSps) || !sameBytes(pps, newPps))) {
                sps = newSps;
                pps = newPps;
                codecString = 'avc1.' + [sps[1], sps[2], sps[3]].map((b) => b.toString(16).padStart(2, '0')).join('');
                parts.push(mp4Init(sps, pps));
            }
            if (!sps || !samples.length) return parts.length ? { parts, codec: codecString } : null;

            const sample = concat(samples.flatMap((nal) => [u32(nal.length), nal]));
            parts.push(mp4Fragment(++seq, t, duration, isKey, sample));
            return { parts, codec: codecString };
        },
    };
}

function splitAnnexB(data) {
    const nals = [];
    let start = -1;
    for (let i = 0; i + 2 < data.length; i++) {
        if (data[i] === 0 && data[i + 1] === 0 && data[i + 2] === 1) {
            if (start >= 0) {
                let end = i;
                while (end > start && data[end - 1] === 0) end--; // 4-byte start code / trailing zeros
                nals.push(data.subarray(start, end));
            }
            start = i + 3;
            i += 2;
        }
    }
    if (start >= 0 && start < data.length) nals.push(data.subarray(start));
    // No start codes at all: treat as one NAL unit.
    if (start < 0 && data.length) nals.push(data);
    return nals;
}

/** Width and height from the SPS (enough of it for baseline/main profiles). */
function spsSize(sps) {
    const r = bitReader(unescapeRbsp(sps.subarray(1)));
    const profile = r.bits(8);
    r.bits(16);                // constraint flags + level
    r.ue();                    // seq_parameter_set_id
    let chroma = 1;
    if ([100, 110, 122, 244, 44, 83, 86, 118, 128, 138, 139, 134, 135].includes(profile)) {
        chroma = r.ue();
        if (chroma === 3) r.bits(1);
        r.ue(); r.ue(); r.bits(1);
        if (r.bits(1)) {
            for (let i = 0; i < (chroma !== 3 ? 8 : 12); i++) {
                if (r.bits(1)) {
                    let last = 8, next = 8;
                    for (let j = 0; j < (i < 6 ? 16 : 64); j++) {
                        if (next !== 0) next = (last + r.se() + 256) % 256;
                        last = next === 0 ? last : next;
                    }
                }
            }
        }
    }
    r.ue();                    // log2_max_frame_num
    const pocType = r.ue();
    if (pocType === 0) r.ue();
    else if (pocType === 1) {
        r.bits(1); r.se(); r.se();
        for (let i = r.ue(); i > 0; i--) r.se();
    }
    r.ue(); r.bits(1);         // max_num_ref_frames, gaps allowed
    const mbW = r.ue() + 1;
    const mbH = r.ue() + 1;
    const frameMbsOnly = r.bits(1);
    if (!frameMbsOnly) r.bits(1);
    r.bits(1);                 // direct_8x8_inference
    let cropL = 0, cropR = 0, cropT = 0, cropB = 0;
    if (r.bits(1)) { cropL = r.ue(); cropR = r.ue(); cropT = r.ue(); cropB = r.ue(); }
    const unitX = chroma === 0 ? 1 : 2;
    const unitY = (chroma === 1 ? 2 : 1) * (2 - frameMbsOnly);
    return {
        width: mbW * 16 - unitX * (cropL + cropR),
        height: (2 - frameMbsOnly) * mbH * 16 - unitY * (cropT + cropB),
    };
}

function unescapeRbsp(bytes) {
    const out = [];
    for (let i = 0; i < bytes.length; i++) {
        if (i >= 2 && bytes[i] === 3 && bytes[i - 1] === 0 && bytes[i - 2] === 0) continue;
        out.push(bytes[i]);
    }
    return new Uint8Array(out);
}

function bitReader(bytes) {
    let pos = 0;
    const bit = () => {
        const b = bytes[pos >> 3];
        const v = b === undefined ? 0 : (b >> (7 - (pos & 7))) & 1;
        pos++;
        return v;
    };
    const bits = (n) => { let v = 0; for (let i = 0; i < n; i++) v = v * 2 + bit(); return v; };
    const ue = () => { let zeros = 0; while (!bit() && zeros < 32) zeros++; return (2 ** zeros - 1) + bits(zeros); };
    const se = () => { const k = ue(); return k & 1 ? (k + 1) / 2 : -k / 2; };
    return { bits, ue, se };
}

function mp4Init(sps, pps) {
    const { width, height } = spsSize(sps);
    const avcC = box('avcC', [
        new Uint8Array([1, sps[1], sps[2], sps[3], 0xFF, 0xE1]), u16(sps.length), sps,
        new Uint8Array([1]), u16(pps.length), pps,
    ]);
    const avc1 = box('avc1', [
        zeros(6), u16(1),                   // reserved, data_reference_index
        zeros(16),                          // pre_defined / reserved
        u16(width), u16(height),
        u32(0x00480000), u32(0x00480000),   // 72 dpi
        zeros(4), u16(1),                   // reserved, frame_count
        zeros(32),                          // compressorname
        u16(0x0018), u16(0xFFFF),           // depth, pre_defined
        avcC,
    ]);
    const matrix = concat([u32(0x00010000), u32(0), u32(0), u32(0), u32(0x00010000), u32(0), u32(0), u32(0), u32(0x40000000)]);
    const stbl = box('stbl', [
        fullBox('stsd', 0, 0, [u32(1), avc1]),
        fullBox('stts', 0, 0, [u32(0)]),
        fullBox('stsc', 0, 0, [u32(0)]),
        fullBox('stsz', 0, 0, [u32(0), u32(0)]),
        fullBox('stco', 0, 0, [u32(0)]),
    ]);
    const minf = box('minf', [
        fullBox('vmhd', 0, 1, [zeros(8)]),
        box('dinf', [fullBox('dref', 0, 0, [u32(1), fullBox('url ', 0, 1, [])])]),
        stbl,
    ]);
    const mdia = box('mdia', [
        fullBox('mdhd', 0, 0, [u32(0), u32(0), u32(1000), u32(0), u16(0x55C4), u16(0)]),
        fullBox('hdlr', 0, 0, [u32(0), ascii('vide'), zeros(12), ascii('Krotze\0')]),
        minf,
    ]);
    const trak = box('trak', [
        fullBox('tkhd', 0, 3, [u32(0), u32(0), u32(1), u32(0), u32(0), zeros(8), u16(0), u16(0), u16(0), u16(0), matrix, u32(width * 65536), u32(height * 65536)]),
        mdia,
    ]);
    const moov = box('moov', [
        fullBox('mvhd', 0, 0, [u32(0), u32(0), u32(1000), u32(0), u32(0x00010000), u16(0x0100), zeros(10), matrix, zeros(24), u32(2)]),
        trak,
        box('mvex', [fullBox('trex', 0, 0, [u32(1), u32(1), u32(0), u32(0), u32(0)])]),
    ]);
    const ftyp = box('ftyp', [ascii('isom'), u32(0x200), ascii('isom'), ascii('iso5'), ascii('iso6'), ascii('avc1'), ascii('mp41')]);
    return concat([ftyp, moov]);
}

function mp4Fragment(seq, t, duration, isKey, sample) {
    const build = (offset) => box('moof', [
        fullBox('mfhd', 0, 0, [u32(seq)]),
        box('traf', [
            fullBox('tfhd', 0, 0x020000, [u32(1)]),                         // default-base-is-moof
            fullBox('tfdt', 1, 0, [u32(Math.floor(t / 2 ** 32)), u32(t >>> 0)]),
            fullBox('trun', 0, 0x000701, [                                  // offset, duration, size, flags
                u32(1), u32(offset), u32(duration), u32(sample.length),
                u32(isKey ? 0x02000000 : 0x01010000),
            ]),
        ]),
    ]);
    const size = build(0).length;
    return concat([build(size + 8), concat([u32(sample.length + 8), ascii('mdat')]), sample]);
}

function box(type, children) {
    const body = concat(children);
    return concat([u32(body.length + 8), ascii(type), body]);
}

function fullBox(type, version, flags, children) {
    return box(type, [new Uint8Array([version, (flags >> 16) & 0xff, (flags >> 8) & 0xff, flags & 0xff]), ...children]);
}

/* ------------------------------- bytes ------------------------------- */

function u16(v) { return new Uint8Array([(v >> 8) & 0xff, v & 0xff]); }
function u32(v) { return new Uint8Array([(v >>> 24) & 0xff, (v >>> 16) & 0xff, (v >>> 8) & 0xff, v & 0xff]); }
function zeros(n) { return new Uint8Array(n); }
function ascii(s) { return Uint8Array.from(s, (c) => c.charCodeAt(0)); }

function concat(parts) {
    const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
    let at = 0;
    for (const p of parts) { out.set(p, at); at += p.length; }
    return out;
}

function sameBytes(a, b) {
    if (a.length !== b.length) return false;
    for (let i = 0; i < a.length; i++) if (a[i] !== b[i]) return false;
    return true;
}
