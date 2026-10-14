<?php
header('Location: ../../facial_login.php', true, 302);
exit;
?>

<div class="page-body">
    <div class="card">
        <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
            <div>
                <h5 class="card-title mb-0">Onsite Facial Login</h5>
                <p class="text-muted mb-0">Use this page to time in/out onsite employees via face recognition.</p>
            </div>
            <span class="badge bg-info" style="font-size:12px; padding:8px 12px;">Admin only</span>
        </div>
        <div class="card-body">
            <div class="row gy-4">
                <div class="col-md-6">
                    <div class="text-center">
                        <video id="video" width="100%" height="auto" autoplay style="border:1px solid #D1D5DB; border-radius:12px;"></video>
                        <canvas id="canvas" width="640" height="480" style="display:none;"></canvas>
                        <div class="mt-3 d-flex justify-content-center gap-2 flex-wrap">
                            <button id="captureBtn" class="btn btn-primary">
                                <i class="ti ti-camera me-1"></i> Capture Photo
                            </button>
                            <button id="checkinBtn" class="btn btn-success" disabled>
                                <i class="ti ti-login me-1"></i> Time In
                            </button>
                            <button id="checkoutBtn" class="btn btn-danger" disabled>
                                <i class="ti ti-logout me-1"></i> Time Out
                            </button>
                        </div>
                        <div id="previewBox" style="display:none; margin-top:12px;">
                            <p class="text-muted mb-1" style="font-size:13px;">Captured photo</p>
                            <img id="previewImg" src="" alt="Captured" style="width:120px; height:120px; object-fit:cover; border-radius:50%; border:2px solid #6c757d;">
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div id="status" class="alert alert-info">
                        <i class="ti ti-info-circle me-1"></i>
                        Ready. Capture the employee face then press <strong>Time In</strong> or <strong>Time Out</strong>.
                    </div>

                    <div id="result" class="alert" style="display:none;"></div>

                    <div id="logBox" style="display:none;" class="mt-3">
                        <h6 class="mb-2">Last Action</h6>
                        <table class="table table-sm table-bordered">
                            <tbody>
                                <tr><td class="text-muted" style="width:40%">Employee ID</td><td id="logEmpId">—</td></tr>
                                <tr><td class="text-muted">Action</td><td id="logAction">—</td></tr>
                                <tr><td class="text-muted">Time</td><td id="logTime">—</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3">
                        <strong>Instructions:</strong>
                        <ul style="padding-left:18px; margin-top:10px; font-size:14px;">
                            <li>Allow webcam access if prompted.</li>
                            <li>Position the employee's face clearly in the camera.</li>
                            <li>Click <strong>Capture Photo</strong> to take a snapshot.</li>
                            <li>Click <strong>Time In</strong> to record arrival.</li>
                            <li>Click <strong>Time Out</strong> to record departure.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const video       = document.getElementById('video');
const canvas      = document.getElementById('canvas');
const captureBtn  = document.getElementById('captureBtn');
const checkinBtn  = document.getElementById('checkinBtn');
const checkoutBtn = document.getElementById('checkoutBtn');
const statusEl    = document.getElementById('status');
const resultEl    = document.getElementById('result');
const previewBox  = document.getElementById('previewBox');
const previewImg  = document.getElementById('previewImg');
const logBox      = document.getElementById('logBox');

// Start webcam
navigator.mediaDevices.getUserMedia({ video: true })
    .then(stream => {
        video.srcObject = stream;
    })
    .catch(err => {
        statusEl.className = 'alert alert-danger';
        statusEl.innerHTML = '<i class="ti ti-alert-circle me-1"></i>Unable to access webcam. Please allow camera permissions.';
    });

// Reset UI to initial state — forces a fresh capture every time
function resetCapture() {
    canvas.style.display = 'none';
    canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
    previewBox.style.display = 'none';
    previewImg.src = '';
    checkinBtn.disabled  = true;
    checkoutBtn.disabled = true;
}

// Capture photo from webcam
captureBtn.addEventListener('click', () => {
    const context = canvas.getContext('2d');
    canvas.width  = video.videoWidth;
    canvas.height = video.videoHeight;
    context.drawImage(video, 0, 0, canvas.width, canvas.height);
    canvas.style.display = 'block';

    previewImg.src = canvas.toDataURL('image/jpeg', 0.8);
    previewBox.style.display = 'block';

    checkinBtn.disabled  = false;
    checkoutBtn.disabled = false;

    statusEl.className = 'alert alert-secondary';
    statusEl.innerHTML = '<i class="ti ti-check me-1"></i>Photo captured. Press <strong>Time In</strong> or <strong>Time Out</strong>.';
    resultEl.style.display = 'none';
});

// Submit action to facial_log.php
function submitAction(action) {
    // Safety check — make sure canvas has image data
    if (canvas.width === 0 || canvas.height === 0) {
        statusEl.className = 'alert alert-warning';
        statusEl.innerHTML = '<i class="ti ti-alert-circle me-1"></i>Please capture a photo first.';
        return;
    }

    const imageData = canvas.toDataURL('image/jpeg', 0.8);
    const formData  = new FormData();
    formData.append('image', imageData);
    formData.append('action', action);

    checkinBtn.disabled  = true;
    checkoutBtn.disabled = true;
    resultEl.style.display = 'none';

    const actionLabel = action === 'in' ? 'Time In' : 'Time Out';
    statusEl.className = 'alert alert-info';
    statusEl.innerHTML = `<i class="ti ti-loader me-1"></i>Processing ${actionLabel}...`;

    fetch('facial_log.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        resultEl.style.display = 'block';

        if (data.success) {
            resultEl.className = 'alert alert-success';
            const label = action === 'in' ? 'timed in' : 'timed out';
            resultEl.innerHTML = data.employee_id
                ? `<strong>Employee ${data.employee_id}</strong> ${label} successfully.`
                : data.message;

            statusEl.className = 'alert alert-success';
            statusEl.innerHTML = '<i class="ti ti-circle-check me-1"></i>Completed successfully.';

            // Update last action log
            logBox.style.display = 'block';
            document.getElementById('logEmpId').textContent = data.employee_id || '—';
            document.getElementById('logAction').innerHTML  = action === 'in'
                ? '<span class="badge bg-success">Time In</span>'
                : '<span class="badge bg-danger">Time Out</span>';
            document.getElementById('logTime').textContent  = new Date().toLocaleTimeString('en-PH', {
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            });

        } else {
            // Show specific error — no generic "Error occurred"
            resultEl.className   = 'alert alert-warning';
            resultEl.textContent = data.message || 'An error occurred. Please try again.';

            statusEl.className = 'alert alert-secondary';
            statusEl.innerHTML = '<i class="ti ti-refresh me-1"></i>Please capture a new photo to try again.';
        }

        // Always reset after every action so next person must recapture
        resetCapture();
    })
    .catch(error => {
        resultEl.style.display  = 'block';
        resultEl.className      = 'alert alert-danger';
        resultEl.textContent    = 'Network error: ' + error.message;
        statusEl.className      = 'alert alert-secondary';
        statusEl.innerHTML      = '<i class="ti ti-refresh me-1"></i>Please try again.';
        resetCapture();
    });
}

checkinBtn.addEventListener('click',  () => submitAction('in'));
checkoutBtn.addEventListener('click', () => submitAction('out'));
</script>