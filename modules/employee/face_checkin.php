<?php
// Face Check-in Page
require_once '../../includes/auth.php';
requireRole('employee', 'admin', 'superadmin');

$pageTitle = 'Face Check-in';
require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Face Recognition Check-in/Out</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="text-center">
                                <video id="video" width="400" height="300" autoplay style="border: 1px solid #ccc;"></video>
                                <br><br>
                                <button id="checkinBtn" class="btn btn-success" disabled>Check In</button>
                                <button id="checkoutBtn" class="btn btn-warning" disabled>Check Out</button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <canvas id="canvas" width="400" height="300" style="border: 1px solid #ccc; display: none;"></canvas>
                            <br>
                            <div id="result" class="alert" style="display: none;"></div>
                            <div id="status" class="mt-3"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Get video element
const video = document.getElementById('video');
const canvas = document.getElementById('canvas');
const checkinBtn = document.getElementById('checkinBtn');
const checkoutBtn = document.getElementById('checkoutBtn');
const resultDiv = document.getElementById('result');
const statusDiv = document.getElementById('status');

// Access webcam
navigator.mediaDevices.getUserMedia({ video: true })
    .then(stream => {
        video.srcObject = stream;
        // Enable buttons once video is ready
        video.onloadedmetadata = () => {
            checkinBtn.disabled = false;
            checkoutBtn.disabled = false;
            statusDiv.innerHTML = '<div class="alert alert-info">Camera ready. Click Check In or Check Out to scan your face.</div>';
        };
    })
    .catch(err => {
        statusDiv.innerHTML = '<div class="alert alert-danger">Unable to access webcam. Please allow camera permissions.</div>';
    });

// Check in/out function
function performCheck(action) {
    // Capture current video frame
    const context = canvas.getContext('2d');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    context.drawImage(video, 0, 0, canvas.width, canvas.height);

    const imageData = canvas.toDataURL('image/jpeg', 0.8);

    // Convert base64 to blob
    fetch(imageData)
        .then(res => res.blob())
        .then(blob => {
            const formData = new FormData();
            formData.append('image', blob, 'capture.jpg');
            formData.append('action', action);

            statusDiv.innerHTML = '<div class="alert alert-info">Scanning face...</div>';

            fetch('face_process.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                resultDiv.style.display = 'block';
                if (data.success) {
                    resultDiv.className = 'alert alert-success';
                    let displayMessage = data.message;
                    if (data.employee_id) {
                        displayMessage = `<strong>Employee ${data.employee_id}</strong> checked ${data.action === 'in' ? 'in' : 'out'} successfully.<br>${data.message}`;
                    }
    
                    resultDiv.innerHTML = displayMessage;
                } else {
                    resultDiv.className = 'alert alert-danger';
                    resultDiv.innerHTML = data.message;
                }
                statusDiv.innerHTML = '';
                checkinBtn.disabled = false;
                checkoutBtn.disabled = false;
            })
            .catch(error => {
                console.error('Error:', error);
                resultDiv.style.display = 'block';
                resultDiv.className = 'alert alert-danger';
                resultDiv.innerHTML = 'An error occurred while processing.';
                statusDiv.innerHTML = '';
            });
        });
}

// Event listeners
checkinBtn.addEventListener('click', () => performCheck('in'));
checkoutBtn.addEventListener('click', () => performCheck('out'));
</script>