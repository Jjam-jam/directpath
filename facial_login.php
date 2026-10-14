<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Facial Attendance | Directpath</title>
<link rel="stylesheet" href="assets/css/bootstrap.min.css">
<style>
* { box-sizing: border-box; }
html, body { width: 100%; height: 100%; margin: 0; overflow: hidden; background: #080b0c; }
body { color: #f7f8f8; font-family: Arial, sans-serif; }
.camera-stage { position: fixed; inset: 0; overflow: hidden; background: #080b0c; }
.camera-frame { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; background: #080b0c; transform: scaleX(-1); }
.camera-shade { position: absolute; inset: 0; pointer-events: none; background: linear-gradient(180deg, rgba(0,0,0,.58), transparent 25%, transparent 56%, rgba(0,0,0,.82)); }
.topbar { position: absolute; z-index: 4; inset: 0 0 auto; padding: 20px clamp(16px, 4vw, 48px); }
.topbar-inner { display: flex; align-items: center; gap: 12px; }
.brand-mark { width: 38px; height: 38px; object-fit: contain; }
.brand-copy { line-height: 1.25; text-shadow: 0 1px 8px #000; }
.top-actions { display: flex; gap: 8px; margin-left: auto; }
.top-action { min-height: 42px; padding: 0 14px; border: 1px solid rgba(255,255,255,.38); border-radius: 6px; background: rgba(12,16,17,.55); color: #fff; font-size: 13px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; backdrop-filter: blur(8px); cursor: pointer; }
.top-action:hover, .top-action:focus-visible { color: #fff; background: rgba(255,255,255,.18); outline: 2px solid #fff; outline-offset: 2px; }
.scan-guide { position: absolute; z-index: 1; left: 50%; top: 47%; width: clamp(220px, 28vw, 340px); aspect-ratio: .78; transform: translate(-50%, -50%); border: 1px solid rgba(255,255,255,.58); border-radius: 48% 48% 42% 42%; box-shadow: 0 0 0 100vmax rgba(0,0,0,.08); overflow: hidden; }
.scan-line { position: absolute; top: 0; left: 5%; width: 90%; height: 2px; opacity: 0; background: #8df2c1; box-shadow: 0 0 12px 3px rgba(62,231,157,.7); }
.is-scanning .scan-guide { border-color: #8df2c1; box-shadow: 0 0 24px rgba(62,231,157,.2), 0 0 0 100vmax rgba(0,0,0,.08); }
.is-scanning .scan-line { opacity: 1; animation: scan-sweep 1.6s ease-in-out infinite alternate; }
@keyframes scan-sweep { from { top: 3%; } to { top: 97%; } }
.scan-copy { position: absolute; z-index: 2; top: 14%; left: 16px; right: 16px; text-align: center; text-shadow: 0 1px 10px #000; }
.scan-copy h1 { font-size: clamp(22px, 3vw, 32px); font-weight: 600; margin: 0 0 6px; }
.scan-copy p { margin: 0; color: rgba(255,255,255,.82); font-size: 14px; }
.bottom-panel { position: absolute; z-index: 3; inset: auto 0 0; display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 28px 16px max(24px, env(safe-area-inset-bottom)); }
.status-pill { max-width: min(92vw, 560px); padding: 10px 16px; border: 1px solid rgba(255,255,255,.24); border-radius: 999px; background: rgba(13,18,19,.72); color: #fff; font-size: 14px; text-align: center; backdrop-filter: blur(10px); }
.status-pill::before { content: ''; display: inline-block; width: 8px; height: 8px; margin-right: 9px; border-radius: 50%; background: #8df2c1; vertical-align: 1px; }
.status-pill.is-error::before { background: #ff7777; }
.is-scanning .status-pill::before { animation: status-pulse .8s ease-in-out infinite alternate; }
@keyframes status-pulse { to { opacity: .35; transform: scale(.75); } }
.action-row { display: flex; justify-content: center; gap: 12px; width: min(100%, 480px); }
.action-button { width: 50%; min-height: 56px; border: 0; border-radius: 6px; color: #fff; font-size: 16px; font-weight: 700; cursor: pointer; box-shadow: 0 4px 18px rgba(0,0,0,.24); }
.action-button.time-in { background: #168452; }
.action-button.time-out { background: #c52d36; }
.action-button:hover:not(:disabled) { filter: brightness(1.1); }
.action-button:focus-visible { outline: 3px solid #fff; outline-offset: 3px; }
.action-button:disabled { opacity: .48; cursor: wait; }
.result-message { max-width: min(92vw, 600px); color: #fff; text-align: center; font-size: 14px; text-shadow: 0 1px 8px #000; }
.result-message.d-none, .log-box.d-none { display: none; }
.time-in-note { max-width: min(92vw, 480px); margin: 0; padding: 8px 14px; border: 1px solid rgba(255,196,87,.55); border-radius: 6px; background: rgba(60,40,5,.72); color: #ffd98a; font-size: 13px; text-align: center; backdrop-filter: blur(10px); }
.time-in-note[hidden] { display: none; }
.log-box { position: absolute; z-index: 4; top: 82px; right: 24px; min-width: 200px; padding: 12px 16px; border: 1px solid rgba(255,255,255,.24); border-radius: 6px; background: rgba(13,18,19,.76); backdrop-filter: blur(10px); font-size: 13px; }
.log-box h2 { margin: 0 0 8px; font-size: 14px; }
.log-box p { margin: 4px 0; color: rgba(255,255,255,.84); }
.log-box strong { color: #fff; }
.camera-stage:fullscreen { width: 100vw; height: 100vh; }
@media (max-width: 575px) {
    .topbar { padding: 12px; }
    .brand-mark { width: 32px; height: 32px; }
    .top-action { min-height: 38px; padding: 0 10px; font-size: 12px; }
    .top-actions { gap: 6px; }
    .scan-guide { top: 44%; width: min(62vw, 290px); }
    .scan-copy { top: 15%; }
    .bottom-panel { gap: 10px; padding-inline: 12px; }
    .action-row { gap: 8px; }
    .action-button { min-height: 52px; font-size: 15px; }
    .log-box { top: 64px; right: 12px; min-width: 170px; padding: 9px 12px; }
}
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; scroll-behavior: auto !important; }
}
</style>
</head>
<body>
<main class="camera-stage" id="cameraStage" aria-busy="false">
    <video id="video" class="camera-frame" autoplay muted playsinline></video>
    <canvas id="canvas" width="640" height="480" hidden></canvas>
    <div class="camera-shade" aria-hidden="true"></div>
    <div class="scan-guide" aria-hidden="true"><div class="scan-line"></div></div>
    <header class="topbar">
        <div class="topbar-inner">
            <img class="brand-mark" src="assets/img/logo.png" alt="">
            <div class="brand-copy"><strong>Directpath</strong><br><span>Facial Attendance</span></div>
            <div class="top-actions">
                <button id="fullscreenBtn" class="top-action" type="button" aria-pressed="false">Full screen</button>
                <a class="top-action" href="index.php">Return to login</a>
            </div>
        </div>
    </header>
    <section class="scan-copy" aria-live="polite">
        <h1>Onsite time clock</h1>
        <p>Center your face in the frame, then choose an action.</p>
    </section>
    <section id="logBox" class="log-box d-none" aria-live="polite">
        <h2>Last action</h2>
        <p>Employee: <strong id="logEmpId">-</strong></p>
        <p>Action: <strong id="logAction">-</strong></p>
        <p>Time: <strong id="logTime">-</strong></p>
    </section>
    <section class="bottom-panel">
        <div id="status" class="status-pill" role="status">Starting camera…</div>
        <div class="action-row">
            <button id="checkinBtn" class="action-button time-in" type="button" disabled>Time in</button>
            <button id="checkoutBtn" class="action-button time-out" type="button" disabled>Time out</button>
        </div>
        <p id="timeInNote" class="time-in-note" role="status" hidden></p>
        <div id="result" class="result-message d-none" role="status"></div>
    </section>
</main>
<script>
const video = document.getElementById('video');
const canvas = document.getElementById('canvas');
const checkinBtn = document.getElementById('checkinBtn');
const checkoutBtn = document.getElementById('checkoutBtn');
const statusEl = document.getElementById('status');
const resultEl = document.getElementById('result');
const logBox = document.getElementById('logBox');
const cameraStage = document.getElementById('cameraStage');
const fullscreenBtn = document.getElementById('fullscreenBtn');
const timeInNote = document.getElementById('timeInNote');
let cameraReady = false;
let busy = false;
let timeInAllowed = false; // stays false until the server confirms the time-in window is open

function setStatus(message, isError = false) {
    statusEl.textContent = message;
    statusEl.classList.toggle('is-error', isError);
}

function updateFullscreenButton() {
    const isFullscreen = document.fullscreenElement === cameraStage;
    fullscreenBtn.textContent = isFullscreen ? 'Exit full screen' : 'Full screen';
    fullscreenBtn.setAttribute('aria-pressed', String(isFullscreen));
}

fullscreenBtn.addEventListener('click', async () => {
    try {
        if (document.fullscreenElement) await document.exitFullscreen();
        else await cameraStage.requestFullscreen();
    } catch (error) {
        setStatus('Full screen mode is unavailable in this browser.', true);
    }
});
document.addEventListener('fullscreenchange', updateFullscreenButton);

// Time In is only enabled while the server says the time-in window is open.
// Time Out stays available whenever the camera is ready.
function refreshButtons() {
    checkinBtn.disabled  = !cameraReady || busy || !timeInAllowed;
    checkoutBtn.disabled = !cameraReady || busy;
    if (timeInAllowed) checkinBtn.removeAttribute('title');
}

async function checkTimeInWindow() {
    try {
        const response = await fetch('api/facial_log.php?action=status', { cache: 'no-store' });
        const data = await response.json();
        timeInAllowed = data.success === true && data.time_in_allowed === true;
        timeInNote.hidden = timeInAllowed;
        if (!timeInAllowed) {
            const reason = data.message || 'Time In is not available right now.';
            timeInNote.textContent = reason + ' You can still Time Out.';
            checkinBtn.title = reason;
        }
    } catch (error) {
        timeInAllowed = false;
        timeInNote.hidden = false;
        timeInNote.textContent = 'Cannot check the Time In schedule right now. You can still Time Out.';
    }
    refreshButtons();
}
checkTimeInWindow();
setInterval(checkTimeInWindow, 30000); // re-check so the button opens/closes without reloading

const cameraRequest = navigator.mediaDevices?.getUserMedia
    ? navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }
    })
    : Promise.reject(new Error('Camera API unavailable'));

cameraRequest
    .then(stream => {
        video.srcObject = stream;
        video.addEventListener('loadedmetadata', () => {
            cameraReady = true;
            refreshButtons();
            setStatus('Camera ready. Select Time In or Time Out to scan.');
        }, { once: true });
    })
    .catch(() => {
        setStatus('Unable to access the camera. Allow camera permissions and reload this page.', true);
    });

async function submitAction(action) {
    if (!cameraReady || !video.videoWidth || !video.videoHeight) return;
        if (action === 'in' && !timeInAllowed) return; // the server also rejects this
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    const formData = new FormData();
    formData.append('image', canvas.toDataURL('image/jpeg', 0.8));
    formData.append('action', action);
    busy = true;
    refreshButtons();
    cameraStage.classList.add('is-scanning');
    cameraStage.setAttribute('aria-busy', 'true');
    resultEl.classList.add('d-none');
    setStatus(`Scanning face for Time ${action}…`);

    try {
        const response = await fetch('api/facial_log.php', { method: 'POST', body: formData });
        const data = await response.json();
        resultEl.textContent = data.success
            ? `Employee ${data.employee_id} ${action === 'in' ? 'timed in' : 'timed out'} successfully.`
            : (data.message || 'Attendance could not be recorded.');
        resultEl.classList.remove('d-none');
        setStatus(data.success ? 'Attendance recorded.' : 'Face not confirmed. Check the camera view and try again.', !data.success);
        if (data.success) {
            document.getElementById('logEmpId').textContent = data.employee_id || '-';
            document.getElementById('logAction').textContent = action === 'in' ? 'Time in' : 'Time out';
            document.getElementById('logTime').textContent = new Date().toLocaleTimeString();
            logBox.classList.remove('d-none');
        }
    } catch (error) {
        resultEl.textContent = 'Could not contact the attendance service. Please try again.';
        resultEl.classList.remove('d-none');
        setStatus('Scan failed. Check the camera view and try again.', true);
    } finally {
        cameraStage.classList.remove('is-scanning');
        cameraStage.setAttribute('aria-busy', 'false');
        busy = false;
        refreshButtons();
        checkTimeInWindow(); // the window may have just closed
    }
}

checkinBtn.addEventListener('click', () => submitAction('in'));
checkoutBtn.addEventListener('click', () => submitAction('out'));
</script>
</body>
</html>