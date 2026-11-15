import { t } from './i18n.js';

/* ------------------------------------------------------------------ */
/* Krotze live streams — voice, camera and screen sharing.              */
/*                                                                      */
/* Everything goes through the server: this module opens ONE WebSocket  */
/* to the server's live relay, sends its own encoded audio/video there  */
/* and receives everybody else's from there. There is no WebRTC and no  */
/* connection between clients, so members never learn each other's      */
/* network address.                                                     */
/*                                                                      */
/*   video  WebCodecs (VP8, H.264 as fallback) → canvas                 */
/*   audio  16 kHz mono µ-law (G.711), 40 ms packets → Web Audio        */
/*                                                                      */
/* Written against Chrome/Edge, Firefox and Safari (macOS and iOS):     */
/* nothing on the critical path is Chrome-only. Safari specifics are    */
/* marked where they matter — audio must be unlocked inside a tap, iOS  */
/* needs an audio-session type to play with the silent switch on, has   */
/* no screen capture and (on iPhone) no element fullscreen.             */
/*                                                                      */
/* Wire format: JSON text frames for control; binary frames for media,  */
/* streamer → server [u8 kind][u32 ms][payload], server → viewer the    */
/* same prefixed with [u32 stream id]. kind 1 = keyframe, 2 = delta,    */
/* 3 = audio.                                                           */
/* ------------------------------------------------------------------ */

const KIND_KEY = 1;
const KIND_DELTA = 2;
const KIND_AUDIO = 3;

const AUDIO_RATE = 16000;
const KEYFRAME_EVERY_MS = 4000;
const MAX_WIDTH = 1280;
const MAX_HEIGHT = 720;
const PROFILES = {
    camera: { fps: 15, bitrate: 600_000 },
    screen: { fps: 8, bitrate: 1_500_000 },
};
const CODECS = [
    { codec: 'vp8' },
    { codec: 'avc1.42E01F', avc: { format: 'annexb' } },
];

let host = null;      // { api, esc, toastError, toastInfo, onChange } — handed in by app.js
let session = null;   // the one live connection: the open channel's
const dismissed = new Set();   // channels whose stream the user chose to leave
const retryAfter = new Map();  // channel key → time before which we do not reconnect

export function initLive(h) {
    host = h;
}

/** What the header needs to know about a channel's live state. */
export function liveStatus(key) {
    const mine = session && session.key === key;
    return { connected: !!mine, broadcasting: !!(mine && session.out) };
}

/** Can this browser send / show video at all? Voice works everywhere. */
export const canEncodeVideo = () => 'VideoEncoder' in window && 'VideoFrame' in window;
export const canShareScreen = () => canEncodeVideo() && !!navigator.mediaDevices?.getDisplayMedia;
export const canCapture = () => !!navigator.mediaDevices?.getUserMedia && 'AudioWorkletNode' in window;

/**
 * Called after every state poll and whenever the open channel changes, with
 * the open channel (or null). Joins the live room as soon as the channel has
 * a running stream — that is what makes streams start by themselves — and
 * tears the connection down when the user moves elsewhere.
 */
export function liveSync(cur) {
    if (session && (!cur || session.key !== cur.key)) closeSession(session);
    if (!cur) return;

    if (!cur.streams.length) dismissed.delete(cur.key);
    if (session || !cur.available || !cur.streams.length || dismissed.has(cur.key)) return;
    if ((retryAfter.get(cur.key) || 0) > Date.now()) return;
    connect(cur).catch(() => {});
}

/** Put the stream panel into the channel view (it survives re-renders). */
export function liveMount(panelHost) {
    if (session && panelHost && session.root.parentNode !== panelHost) panelHost.appendChild(session.root);
}

/** The user asked to watch after having left the stream. */
export function liveWatch(cur) {
    unlockAudio();
    dismissed.delete(cur.key);
    retryAfter.delete(cur.key);
    if (!session) connect(cur).catch((e) => host.toastError(e));
}

/** Go live in the open channel. mode: 'voice' | 'camera' | 'screen'. */
export async function liveStart(cur, mode) {
    if (session?.out) return;
    // Still inside the click: Safari only starts audio from a user gesture,
    // and the permission prompts below would use it up.
    unlockAudio();
    let media;
    try {
        media = await acquire(mode);
    } catch (e) {
        host.toastError({ message: describeMediaError(e) });
        return;
    }

    try {
        dismissed.delete(cur.key);
        if (session && session.key !== cur.key) closeSession(session);
        const s = session || await connect(cur);
        await s.ready;
        if (session !== s) throw new Error(t('Could not connect to the live server.'));
        await startBroadcast(s, media);
    } catch (e) {
        stopTracks(media.mic);
        stopTracks(media.video);
        host.toastError(e);
    }
}

/* ----------------------------- connection ---------------------------- */

async function connect(cur) {
    const root = document.createElement('div');
    root.className = 'border-b border-white/10 bg-black/40 p-2';
    root.innerHTML = '<div data-grid class="grid gap-2"></div><div data-bar class="flex flex-wrap items-center gap-2 text-xs text-zinc-300"></div>';

    const s = session = {
        key: cur.key, server: cur.server, uuid: cur.uuid, isOwner: cur.isOwner,
        ws: null, joined: false, streams: new Map(), out: null, viewers: 0, root,
    };
    s.ready = new Promise((resolve, reject) => { s.resolveReady = resolve; s.rejectReady = reject; });
    s.ready.catch(() => {});
    liveMount(document.getElementById('stream-panel'));
    renderBar(s);
    host.onChange();

    let pass;
    try {
        pass = await host.api(`/channels/${cur.uuid}/live`, { server: cur.server, method: 'POST' });
    } catch (e) {
        retryAfter.set(cur.key, Date.now() + 15000);
        closeSession(s);
        throw e;
    }
    if (session !== s) throw new Error('superseded');

    const ws = s.ws = new WebSocket(pass.url);
    ws.binaryType = 'arraybuffer';
    ws.onopen = () => ws.send(JSON.stringify({ t: 'join', ticket: pass.ticket }));
    ws.onmessage = (e) => {
        if (session !== s) return;
        if (typeof e.data === 'string') {
            let msg;
            try { msg = JSON.parse(e.data); } catch { return; }
            onControl(s, msg);
        } else {
            onMedia(s, e.data);
        }
    };
    ws.onclose = () => {
        if (session !== s) return;
        const wasLive = !!s.out;
        if (!s.joined) retryAfter.set(s.key, Date.now() + 15000);
        closeSession(s);
        if (wasLive) host.toastError({ message: t('The live connection was lost. Your stream has ended.') });
    };
    return s;
}

function closeSession(s) {
    if (session === s) session = null;
    s.rejectReady?.(new Error(t('Could not connect to the live server.')));
    if (s.out) teardownBroadcast(s);
    for (const st of s.streams.values()) disposeStream(st);
    s.streams.clear();
    try { s.ws?.close(); } catch { /* already closed */ }
    exitFullscreen();
    s.root.remove();
    host.onChange();
}

function control(s, msg) {
    if (s.ws?.readyState === 1) s.ws.send(JSON.stringify(msg));
}

function onControl(s, msg) {
    switch (msg.t) {
        case 'joined':
            s.joined = true;
            for (const info of msg.streams) upsertStream(s, info);
            s.resolveReady();
            renderBar(s);
            break;
        case 'stream':
            upsertStream(s, msg.stream);
            renderBar(s);
            host.onChange();
            break;
        case 'ended': {
            if (s.out && s.out.id === msg.id) {
                teardownBroadcast(s);
                host.toastInfo(t('Your stream has ended.'));
            }
            const st = s.streams.get(msg.id);
            if (st) {
                disposeStream(st);
                s.streams.delete(msg.id);
            }
            layout(s);
            // Nothing left to watch: hang up; the next stream reconnects us.
            if (!s.streams.size && !s.out) closeSession(s);
            else { renderBar(s); host.onChange(); }
            break;
        }
        case 'keyframe':
            if (s.out) s.out.forceKey = true;
            break;
        case 'viewers':
            s.viewers = msg.count;
            renderBar(s);
            break;
        case 'error':
            host.toastError({ message: msg.error });
            // Refused before the stream ever started (e.g. the role was just
            // withdrawn): give the camera and microphone back.
            if (s.out && s.out.id === null) {
                teardownBroadcast(s);
                if (!s.streams.size) closeSession(s);
                else { renderBar(s); host.onChange(); }
            }
            break;
    }
}

/* ------------------------------ watching ----------------------------- */

function upsertStream(s, info) {
    if (info.mine) {
        if (s.out) s.out.id = info.id;
        return;
    }
    let st = s.streams.get(info.id);
    if (!st) {
        st = {
            id: info.id, info, decoder: null, needKey: true, lastKeyAsk: 0, painted: false,
            unsupported: undefined,                       // unknown until checkDecodable() answers
            muted: false, gain: null, next: 0, resamplePos: -1, lastSample: 0,
        };
        st.tile = buildTile(s, st);
        s.streams.set(info.id, st);
        s.root.querySelector('[data-grid]').appendChild(st.tile);
        layout(s);
        playbackContext(); // so the "enable sound" hint can show before audio arrives
    }
    const codecChanged = st.info.vconf?.codec !== info.vconf?.codec;
    st.info = info;
    if (!info.video || codecChanged) resetDecoder(st);
    if (info.vconf && (codecChanged || st.unsupported === undefined)) checkDecodable(st, info.vconf.codec);
    if (!info.video) st.painted = false;
    refreshTile(st);
}

/** Not every browser decodes every codec — find out before frames arrive. */
async function checkDecodable(st, codec) {
    let supported = false;
    try {
        supported = 'VideoDecoder' in window && (await VideoDecoder.isConfigSupported({ codec })).supported;
    } catch { /* treated as unsupported */ }
    if (st.info.vconf?.codec !== codec) return; // changed meanwhile
    st.unsupported = !supported;
    refreshTile(st);
}

function resetDecoder(st) {
    if (st.decoder && st.decoder.state !== 'closed') {
        try { st.decoder.close(); } catch { /* already closed */ }
    }
    st.decoder = null;
    st.needKey = true;
}

function ensureDecoder(st) {
    if (st.decoder || st.unsupported !== false || !st.info.vconf) return;
    const decoder = new VideoDecoder({
        output: (frame) => paint(st, frame),
        error: () => {
            // A broken frame: start over at the next keyframe.
            if (st.decoder === decoder) { st.decoder = null; st.needKey = true; }
        },
    });
    try {
        decoder.configure({ codec: st.info.vconf.codec, optimizeForLatency: true });
        st.decoder = decoder;
    } catch {
        st.unsupported = true;
        refreshTile(st);
    }
}

function paint(st, frame) {
    const canvas = st.tile.querySelector('canvas');
    if (canvas.width !== frame.displayWidth || canvas.height !== frame.displayHeight) {
        canvas.width = frame.displayWidth;
        canvas.height = frame.displayHeight;
    }
    canvas.getContext('2d').drawImage(frame, 0, 0, canvas.width, canvas.height);
    frame.close();
    if (!st.painted) {
        st.painted = true;
        refreshTile(st);
    }
}

function onMedia(s, buffer) {
    if (buffer.byteLength < 9) return;
    const view = new DataView(buffer);
    const st = s.streams.get(view.getUint32(0));
    if (!st) return;
    const kind = view.getUint8(4);
    const data = new Uint8Array(buffer, 9);

    if (kind === KIND_AUDIO) {
        playAudio(st, data);
        return;
    }
    if (!st.info.video) return;

    if (kind === KIND_KEY) {
        ensureDecoder(st);
        st.needKey = false;
    }
    // Behind by a lot (a slow device): skip ahead to the next keyframe.
    if (st.decoder && st.decoder.decodeQueueSize > 12) st.needKey = true;
    if (!st.decoder || st.needKey) {
        const now = Date.now();
        if (!st.unsupported && now - st.lastKeyAsk > 2000) {
            st.lastKeyAsk = now;
            control(s, { t: 'keyframe', id: st.id });
        }
        return;
    }
    try {
        st.decoder.decode(new EncodedVideoChunk({
            type: kind === KIND_KEY ? 'key' : 'delta',
            timestamp: view.getUint32(5) * 1000,
            data,
        }));
    } catch {
        resetDecoder(st);
    }
}

function disposeStream(st) {
    resetDecoder(st);
    st.gain?.disconnect();
    st.tile.remove();
}

/* -------------------------------- audio ------------------------------ */

let audioContext = null;

function playbackContext() {
    if (!audioContext) {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return null;
        audioContext = new Ctx({ latencyHint: 'interactive' });
        audioContext.onstatechange = () => { if (session) renderBar(session); };
        // Browsers hold audio back until the user has touched the page; in
        // Safari the resume has to happen inside the tap itself, and only
        // these events count as one.
        for (const type of ['click', 'touchend', 'keydown']) document.addEventListener(type, unlockAudio, true);
        audioSession(false);
    }
    return audioContext;
}

/** Call from a user gesture: lets a stream that started by itself be heard. */
function unlockAudio() {
    const ctx = playbackContext();
    if (ctx && ctx.state !== 'running') ctx.resume().catch(() => {});
}

/**
 * iOS: without a session type Web Audio is silenced by the ring/silent
 * switch, and capturing needs the play-and-record route. Other browsers
 * have no navigator.audioSession and need none.
 */
function audioSession(capturing) {
    try {
        if (navigator.audioSession) navigator.audioSession.type = capturing ? 'play-and-record' : 'playback';
    } catch { /* optional */ }
}

// µ-law (G.711) decode table: byte → float sample.
const MULAW = new Float32Array(256);
for (let i = 0; i < 256; i++) {
    const u = ~i & 0xff;
    const magnitude = ((((u & 0x0f) << 3) + 0x84) << ((u >> 4) & 7)) - 0x84;
    MULAW[i] = (u & 0x80 ? -magnitude : magnitude) / 32768;
}

function playAudio(st, bytes) {
    const ctx = playbackContext();
    if (!ctx || ctx.state !== 'running' || !bytes.length) return;
    if (!st.gain) {
        st.gain = ctx.createGain();
        st.gain.gain.value = st.muted ? 0 : 1;
        st.gain.connect(ctx.destination);
    }

    // Resampled to the output rate by hand (linear, with the position and
    // last sample carried from packet to packet): letting the browser do it
    // per buffer clicks at every packet boundary, and older Safari refuses
    // 16 kHz buffers altogether.
    const step = AUDIO_RATE / ctx.sampleRate;
    const last = bytes.length - 1;
    const count = Math.max(0, Math.ceil((last - st.resamplePos) / step));
    if (!count) return;
    const buffer = ctx.createBuffer(1, count, ctx.sampleRate);
    const samples = buffer.getChannelData(0);
    let pos = st.resamplePos;
    for (let i = 0; i < count; i++, pos += step) {
        const index = Math.floor(pos);
        const a = index < 0 ? st.lastSample : MULAW[bytes[index]];
        const b = MULAW[bytes[Math.min(index + 1, last)]];
        samples[i] = a + (b - a) * (pos - index);
    }
    st.resamplePos = pos - bytes.length;
    st.lastSample = MULAW[bytes[last]];

    // A small jitter buffer: packets are queued back to back; after a gap we
    // start 100 ms ahead again, and a backlog beyond half a second is dropped
    // so the delay cannot build up.
    const now = ctx.currentTime;
    if (st.next < now + 0.01) {
        st.next = now + 0.1;
    } else if (st.next > now + 0.5) {
        return;
    }
    const source = ctx.createBufferSource();
    source.buffer = buffer;
    source.connect(st.gain);
    source.start(st.next);
    st.next += buffer.duration;
}

// Runs on the audio thread: resamples the microphone to 16 kHz, µ-law encodes
// it and posts 40 ms packets. Not throttled when the tab is in the background.
const MIC_WORKLET = `
class KrotzeMic extends AudioWorkletProcessor {
    constructor() {
        super();
        this.step = sampleRate / ${AUDIO_RATE};
        this.acc = 0; this.sum = 0; this.n = 0;
        this.out = new Uint8Array(${AUDIO_RATE * 0.04});
        this.pos = 0;
    }
    encode(x) {
        let s = Math.max(-1, Math.min(1, x)) * 32767 | 0;
        const sign = s < 0 ? 0x80 : 0;
        if (s < 0) s = -s;
        s = Math.min(s, 32635) + 0x84;
        let exp = 7;
        for (let mask = 0x4000; (s & mask) === 0 && exp > 0; exp--, mask >>= 1) {}
        return ~(sign | (exp << 4) | ((s >> (exp + 3)) & 0x0f)) & 0xff;
    }
    process(inputs) {
        const ch = inputs[0] && inputs[0][0];
        if (!ch) return true;
        for (let i = 0; i < ch.length; i++) {
            this.sum += ch[i]; this.n++; this.acc++;
            if (this.acc >= this.step) {
                this.acc -= this.step;
                this.out[this.pos++] = this.encode(this.sum / this.n);
                this.sum = 0; this.n = 0;
                if (this.pos === this.out.length) {
                    this.port.postMessage(this.out.slice());
                    this.pos = 0;
                }
            }
        }
        return true;
    }
}
registerProcessor('krotze-mic', KrotzeMic);`;

let micModule = null;

async function startMic(s, stream) {
    const out = s.out;
    const ctx = playbackContext();
    if (!ctx) throw new Error(t('This browser cannot capture audio.'));
    audioSession(true);
    if (ctx.state !== 'running') await ctx.resume().catch(() => {});
    micModule ||= ctx.audioWorklet.addModule(URL.createObjectURL(new Blob([MIC_WORKLET], { type: 'text/javascript' })));
    await micModule;

    out.mic = stream;
    out.micSource = ctx.createMediaStreamSource(stream);
    out.micNode = new AudioWorkletNode(ctx, 'krotze-mic', { numberOfOutputs: 1 });
    out.micNode.port.onmessage = (e) => sendMedia(s, KIND_AUDIO, e.data);
    out.micSource.connect(out.micNode);
    // The node outputs silence; connecting it keeps the graph pulling.
    out.micNode.connect(ctx.destination);
    out.audio = true;
    stream.getAudioTracks()[0]?.addEventListener('ended', () => {
        if (s.out === out && out.mic === stream) { stopMic(out); announce(s); }
    });
}

function stopMic(out) {
    out.micSource?.disconnect();
    out.micNode?.disconnect();
    if (out.micNode) out.micNode.port.onmessage = null;
    stopTracks(out.mic);
    out.mic = out.micSource = out.micNode = null;
    out.audio = false;
    audioSession(false);
}

/* ----------------------------- broadcasting -------------------------- */

function stopTracks(stream) {
    stream?.getTracks().forEach((track) => track.stop());
}

function describeMediaError(e) {
    if (e?.name === 'NotAllowedError' || e?.name === 'SecurityError') {
        return t('Access to the microphone, camera or screen was not allowed.');
    }
    if (e?.name === 'NotFoundError' || e?.name === 'OverconstrainedError') {
        return t('No microphone or camera was found.');
    }
    // The device exists and is allowed, but could not be started — nearly
    // always because another program (or another browser) is using it.
    // Firefox words this as "Failed to allocate videosource".
    if (e?.name === 'NotReadableError' || e?.name === 'AbortError' || e?.name === 'TrackStartError') {
        return t('The camera or microphone could not be started. It is probably in use by another app or browser.');
    }
    return e?.message || String(e);
}

const micConstraints = { audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 } };

function captureVideo(kind) {
    return kind === 'screen'
        ? navigator.mediaDevices.getDisplayMedia({
            video: { width: { max: 1920 }, height: { max: 1080 }, frameRate: { max: 10 } }, audio: false,
        })
        : navigator.mediaDevices.getUserMedia({
            video: { width: { ideal: 640 }, height: { ideal: 360 }, frameRate: { ideal: 15, max: 30 }, facingMode: 'user' },
        });
}

async function acquire(mode) {
    const media = { mic: null, video: null, kind: mode === 'voice' ? null : mode };
    // Screen first: its picker needs the click that got us here.
    if (media.kind) media.video = await captureVideo(media.kind);
    try {
        media.mic = await navigator.mediaDevices.getUserMedia(micConstraints);
    } catch (e) {
        // A camera or screen stream without a microphone is still a stream.
        if (!media.video) throw e;
    }
    return media;
}

async function pickCodec() {
    for (const candidate of CODECS) {
        try {
            const probe = { ...candidate, width: 640, height: 360, bitrate: 500_000, framerate: 15 };
            if ((await VideoEncoder.isConfigSupported(probe)).supported) return candidate;
        } catch { /* try the next one */ }
    }
    return null;
}

async function startBroadcast(s, media) {
    const out = s.out = {
        id: null, announced: false, audio: false, video: null,
        mic: null, micSource: null, micNode: null,
        videoStream: null, track: null, encoder: null, codec: null, width: 0, height: 0,
        lastFrame: 0, lastKey: 0, forceKey: true, stopPump: null,
    };
    out.tile = buildTile(s, null);
    s.root.querySelector('[data-grid]').prepend(out.tile);
    layout(s);

    if (media.mic) await startMic(s, media.mic).catch((e) => { stopTracks(media.mic); host.toastError(e); });
    if (s.out !== out) return;
    if (media.video) await startVideo(s, media.kind, media.video);
    if (s.out !== out) return;

    announce(s);
    refreshOwnTile(s);
    renderBar(s);
    host.onChange();
}

/** Tell the server (and through it, the viewers) what we are sending. */
function announce(s) {
    const out = s.out;
    if (!out) return;
    control(s, {
        t: out.announced ? 'update' : 'start',
        audio: out.audio,
        video: out.video,
        vconf: out.video && out.codec ? { codec: out.codec.codec, width: out.width, height: out.height } : null,
    });
    out.announced = true;
    refreshOwnTile(s);
    renderBar(s);
}

async function startVideo(s, kind, stream) {
    const out = s.out;
    out.codec ||= canEncodeVideo() ? await pickCodec() : null;
    if (!out.codec) {
        stopTracks(stream);
        host.toastError({ message: t('This browser cannot send video. You are streaming voice only.') });
        return;
    }
    if (s.out !== out) { stopTracks(stream); return; }

    const track = stream.getVideoTracks()[0];
    if (kind === 'screen') { try { track.contentHint = 'detail'; } catch { /* optional */ } }
    out.videoStream = stream;
    out.track = track;
    out.video = kind;
    out.forceKey = true;
    // The browser's own "stop sharing" button ends the track.
    track.addEventListener('ended', () => {
        if (s.out === out && out.track === track) { stopVideo(out); announce(s); }
    });

    const preview = out.tile.querySelector('video');
    preview.muted = true;          // iOS only autoplays inline video that is muted
    preview.playsInline = true;
    preview.srcObject = stream;
    preview.play().catch(() => {});
    out.stopPump = pumpFrames(s, out, track, preview);
}

function stopVideo(out) {
    out.stopPump?.();
    out.stopPump = null;
    stopTracks(out.videoStream);
    if (out.encoder && out.encoder.state !== 'closed') {
        try { out.encoder.close(); } catch { /* already closed */ }
    }
    out.encoder = out.videoStream = out.track = null;
    out.video = null;
    out.width = out.height = 0;
    const preview = out.tile.querySelector('video');
    if (preview) preview.srcObject = null;
}

/**
 * Feed captured frames to the encoder. Chrome and Edge hand them out as a
 * stream (which keeps running while the tab is in the background — exactly
 * the situation when sharing a screen); elsewhere frames are grabbed from the
 * preview element on a worker-driven timer, which is not throttled either.
 */
function pumpFrames(s, out, track, preview) {
    let stopped = false;

    if ('MediaStreamTrackProcessor' in window) {
        const reader = new MediaStreamTrackProcessor({ track }).readable.getReader();
        (async () => {
            for (;;) {
                const { value: frame, done } = await reader.read().catch(() => ({ done: true }));
                if (done || stopped) { frame?.close(); break; }
                encodeFrame(s, out, frame);
            }
        })();
        return () => { stopped = true; reader.cancel().catch(() => {}); };
    }

    const worker = new Worker(URL.createObjectURL(new Blob(
        ['let h;onmessage=(e)=>{clearInterval(h);if(e.data)h=setInterval(()=>postMessage(0),e.data)}'],
        { type: 'text/javascript' },
    )));
    worker.onmessage = () => {
        if (stopped || preview.readyState < 2 || !preview.videoWidth) return;
        let frame;
        try { frame = new VideoFrame(preview, { timestamp: Math.round(performance.now() * 1000) }); } catch { return; }
        encodeFrame(s, out, frame);
    };
    worker.postMessage(Math.round(1000 / PROFILES[out.video].fps));
    return () => { stopped = true; worker.terminate(); };
}

let scaler = null;

function encodeFrame(s, out, frame) {
    try {
        const profile = PROFILES[out.video];
        const now = performance.now();
        // Pace to the profile's frame rate, and back off when the socket or
        // the encoder cannot keep up — dropping here is what keeps latency low.
        if (!profile || s.out !== out || now - out.lastFrame < 1000 / profile.fps - 4
            || s.ws.bufferedAmount > 1_000_000 || (out.encoder && out.encoder.encodeQueueSize > 2)) {
            return;
        }

        const scale = Math.min(1, MAX_WIDTH / frame.displayWidth, MAX_HEIGHT / frame.displayHeight);
        const width = Math.max(2, Math.round(frame.displayWidth * scale / 2) * 2);
        const height = Math.max(2, Math.round(frame.displayHeight * scale / 2) * 2);

        if (!out.encoder || out.encoder.state === 'closed' || out.width !== width || out.height !== height) {
            if (out.encoder && out.encoder.state !== 'closed') out.encoder.close();
            const encoder = new VideoEncoder({
                output: (chunk) => {
                    const data = new Uint8Array(chunk.byteLength);
                    chunk.copyTo(data);
                    sendMedia(s, chunk.type === 'key' ? KIND_KEY : KIND_DELTA, data);
                },
                error: () => { if (out.encoder === encoder) out.encoder = null; },
            });
            encoder.configure({
                ...out.codec, width, height,
                bitrate: profile.bitrate, framerate: profile.fps, latencyMode: 'realtime',
            });
            out.encoder = encoder;
            out.width = width;
            out.height = height;
            out.forceKey = true;
            announce(s); // viewers learn the codec and size
        }

        let input = frame;
        if (frame.displayWidth !== width || frame.displayHeight !== height) {
            scaler ||= typeof OffscreenCanvas === 'function' ? new OffscreenCanvas(width, height) : document.createElement('canvas');
            if (scaler.width !== width || scaler.height !== height) { scaler.width = width; scaler.height = height; }
            scaler.getContext('2d').drawImage(frame, 0, 0, width, height);
            input = new VideoFrame(scaler, { timestamp: frame.timestamp ?? Math.round(now * 1000) });
        }

        const keyFrame = out.forceKey || now - out.lastKey > KEYFRAME_EVERY_MS;
        out.encoder.encode(input, { keyFrame });
        if (input !== frame) input.close();
        if (keyFrame) { out.lastKey = now; out.forceKey = false; }
        out.lastFrame = now;
    } catch {
        out.encoder = null; // rebuilt on the next frame
    } finally {
        frame.close();
    }
}

function sendMedia(s, kind, data) {
    if (s.ws?.readyState !== 1 || !s.out?.announced) return;
    const packet = new Uint8Array(5 + data.length);
    packet[0] = kind;
    new DataView(packet.buffer).setUint32(1, Math.floor(performance.now()) >>> 0);
    packet.set(data, 5);
    s.ws.send(packet);
}

function teardownBroadcast(s) {
    const out = s.out;
    if (!out) return;
    s.out = null;
    stopVideo(out);
    stopMic(out);
    out.tile.remove();
    layout(s);
}

function stopBroadcast(s) {
    control(s, { t: 'stop' });
    teardownBroadcast(s);
    if (!s.streams.size) closeSession(s);
    else { renderBar(s); host.onChange(); }
}

async function toggleMic(s) {
    const out = s.out;
    if (out.audio) {
        stopMic(out);
    } else {
        try {
            await startMic(s, await navigator.mediaDevices.getUserMedia(micConstraints));
        } catch (e) {
            host.toastError({ message: describeMediaError(e) });
        }
    }
    if (s.out === out) announce(s);
}

async function toggleVideo(s, kind) {
    const out = s.out;
    const wasThis = out.video === kind;
    if (!wasThis) {
        // Ask first, switch after: cancelling the picker keeps what is running.
        let stream;
        try {
            stream = await captureVideo(kind);
        } catch (e) {
            if (e?.name !== 'NotAllowedError' || kind !== 'screen') host.toastError({ message: describeMediaError(e) });
            return;
        }
        if (s.out !== out) { stopTracks(stream); return; }
        if (out.video) stopVideo(out);
        await startVideo(s, kind, stream);
    } else {
        stopVideo(out);
    }
    if (s.out === out) announce(s);
}

/* --------------------------------- UI -------------------------------- */

const BTN = 'rounded-lg border border-white/15 px-2.5 py-1.5 hover:bg-white/10';
const BTN_ON = 'rounded-lg border border-violet-400/60 bg-violet-600/30 px-2.5 py-1.5 text-white hover:bg-violet-600/40';

function buildTile(s, st) {
    const own = st === null;
    const tile = document.createElement('div');
    tile.className = 'group relative flex items-center justify-center overflow-hidden rounded-lg bg-black';
    tile.innerHTML = `
        ${own
            ? '<video muted playsinline autoplay class="hidden h-full w-full object-contain"></video>'
            : '<canvas class="hidden h-full w-full object-contain"></canvas>'}
        <div data-note class="absolute inset-0 flex flex-col items-center justify-center gap-1 p-2 text-center text-xs text-zinc-400"></div>
        <div class="absolute inset-x-0 bottom-0 flex items-center gap-1 bg-gradient-to-t from-black/80 to-transparent px-2 pb-1 pt-4 text-xs text-white">
            <span data-name class="min-w-0 flex-1 truncate"></span>
            ${own ? '' : `<button data-mute class="rounded px-1.5 py-0.5 hover:bg-white/20" title="${t('Mute / unmute this stream')}">🔊</button>`}
            ${!own && s.isOwner ? `<button data-end class="rounded px-1.5 py-0.5 hover:bg-white/20" title="${t('End this stream')}">⏹</button>` : ''}
            <button data-full class="rounded px-1.5 py-0.5 hover:bg-white/20" title="${t('Fullscreen')}">⛶</button>
        </div>`;

    tile.querySelector('[data-full]').onclick = () => toggleFullscreen(tile);
    tile.ondblclick = () => toggleFullscreen(tile);
    if (!own) {
        tile.querySelector('[data-mute]').onclick = (e) => {
            st.muted = !st.muted;
            if (st.gain) st.gain.gain.value = st.muted ? 0 : 1;
            e.currentTarget.textContent = st.muted ? '🔇' : '🔊';
        };
        tile.querySelector('[data-end]')?.addEventListener('click', () => control(s, { t: 'stop', id: st.id }));
    }
    return tile;
}

function tileLabel(name, audio, video) {
    return `${video === 'screen' ? '🖥' : video === 'camera' ? '📷' : '🎙'} ${host.esc(name)}${audio ? '' : ' · 🔇'}`;
}

function refreshTile(st) {
    const { info, tile } = st;
    const showVideo = !!info.video && st.painted && !st.unsupported;
    tile.querySelector('canvas').classList.toggle('hidden', !showVideo);
    tile.querySelector('[data-name]').innerHTML = tileLabel(info.username, info.audio, info.video);
    const note = tile.querySelector('[data-note]');
    note.classList.toggle('hidden', showVideo);
    note.innerHTML = st.unsupported
        ? `<span class="text-2xl">⚠️</span><span>${t('This browser cannot play live video. Voice still works.')}</span>`
        : info.video
            ? `<span>${t('Connecting…')}</span>`
            : `<span class="text-4xl">🎙</span><span>${t('Voice only')}</span>`;
}

function refreshOwnTile(s) {
    const out = s.out;
    if (!out) return;
    out.tile.querySelector('video').classList.toggle('hidden', !out.video);
    out.tile.querySelector('[data-name]').innerHTML = tileLabel(t('You'), out.audio, out.video);
    const note = out.tile.querySelector('[data-note]');
    note.classList.toggle('hidden', !!out.video);
    note.innerHTML = `<span class="text-4xl">🎙</span><span>${out.audio ? t('Voice only') : t('Microphone off')}</span>`;
}

/** Tile sizes follow the number of streams on screen. */
function layout(s) {
    const grid = s.root.querySelector('[data-grid]');
    const count = grid.children.length;
    grid.className = `grid gap-2 ${count > 1 ? 'grid-cols-2' : 'grid-cols-1'} ${count ? 'mb-2' : ''}`;
    for (const tile of grid.children) {
        tile.classList.remove('h-[38vh]', 'h-[22vh]');
        tile.classList.add(count > 1 ? 'h-[22vh]' : 'h-[38vh]');
    }
}

function renderBar(s) {
    const bar = s.root.querySelector('[data-bar]');
    const out = s.out;
    const soundBlocked = audioContext?.state === 'suspended' && s.streams.size > 0;

    let html = '';
    if (!s.joined) {
        html += `<span>${t('Connecting…')}</span>`;
    } else if (out) {
        html += `<span class="font-semibold text-red-400">● ${t('You are live')}</span>
            <span title="${t('Members connected to this stream')}">👁 ${s.viewers}</span>`;
    } else {
        html += `<span class="font-semibold text-red-400">● ${t('Live')}</span>`;
    }
    html += '<span class="flex-1"></span>';
    if (soundBlocked) html += `<button data-act="sound" class="${BTN_ON}">🔈 ${t('Enable sound')}</button>`;
    if (out) {
        html += `<button data-act="mic" class="${out.audio ? BTN_ON : BTN}" title="${t('Microphone')}">🎙</button>`;
        if (canEncodeVideo()) html += `<button data-act="camera" class="${out.video === 'camera' ? BTN_ON : BTN}" title="${t('Camera')}">📷</button>`;
        if (canShareScreen()) html += `<button data-act="screen" class="${out.video === 'screen' ? BTN_ON : BTN}" title="${t('Share screen')}">🖥</button>`;
        html += `<button data-act="stop" class="rounded-lg bg-red-600 px-3 py-1.5 font-semibold text-white hover:bg-red-500">${t('Stop stream')}</button>`;
    } else {
        html += `<button data-act="leave" class="${BTN}">${t('Leave stream')}</button>`;
    }
    bar.innerHTML = html;

    const on = (act, run) => bar.querySelector(`[data-act="${act}"]`)?.addEventListener('click', run);
    on('sound', () => audioContext.resume().catch(() => {}));
    on('mic', () => toggleMic(s));
    on('camera', () => toggleVideo(s, 'camera'));
    on('screen', () => toggleVideo(s, 'screen'));
    on('stop', () => stopBroadcast(s));
    on('leave', () => { dismissed.add(s.key); closeSession(s); });
}

/* ------------------------------ fullscreen --------------------------- */

const PSEUDO_FULL = ['fixed', 'inset-0', 'z-[70]', '!h-auto', 'rounded-none'];

function fullscreenElement() {
    return document.fullscreenElement || document.webkitFullscreenElement || null;
}

function exitFullscreen() {
    if (fullscreenElement()) (document.exitFullscreen || document.webkitExitFullscreen)?.call(document);
    document.querySelectorAll('[data-pseudo-full]').forEach((el) => {
        el.classList.remove(...PSEUDO_FULL);
        delete el.dataset.pseudoFull;
    });
}

/**
 * Real fullscreen where the browser offers it for arbitrary elements; on
 * iPhones (which only allow it for <video>) the tile fills the window instead.
 */
function toggleFullscreen(tile) {
    if (fullscreenElement() || tile.dataset.pseudoFull) {
        exitFullscreen();
        return;
    }
    const request = tile.requestFullscreen || tile.webkitRequestFullscreen;
    if (request) {
        Promise.resolve(request.call(tile)).catch(() => pseudoFullscreen(tile));
    } else {
        pseudoFullscreen(tile);
    }
}

function pseudoFullscreen(tile) {
    tile.dataset.pseudoFull = '1';
    tile.classList.add(...PSEUDO_FULL);
}
