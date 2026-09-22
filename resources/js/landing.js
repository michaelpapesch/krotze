import QRCode from 'qrcode';

// Install QR on the landing page — points at the PWA start URL
const canvas = document.getElementById('install-qr');
if (canvas) {
    QRCode.toCanvas(canvas, `${location.origin}/app`, { width: 200, margin: 1 })
        .catch(() => canvas.remove());
}

// Two-factor enrolment QR in the admin profile. The otpauth URI carries the
// shared secret, so it is rendered in the page rather than fetched.
const totp = document.getElementById('totp-qr');
if (totp) {
    QRCode.toCanvas(totp, totp.dataset.uri, { width: 200, margin: 1 })
        .catch(() => totp.remove());
}

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
}
