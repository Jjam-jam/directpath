<?php
require_once '../../includes/auth.php';
requireRole('hr_admin');

$pageTitle = 'Face Training';
require_once '../../includes/header.php';

$db = getDB();
$stmt = $db->prepare("
    SELECT u.employee_id, u.first_name, u.middle_name, u.last_name, u.reference_photo, u.reference_photo_data,
           CASE WHEN fe.id IS NOT NULL THEN 1 ELSE 0 END as has_encoding
    FROM users u
    LEFT JOIN face_encodings fe ON u.employee_id = fe.employee_id
    WHERE EXISTS (
        SELECT 1
        FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        WHERE ur.employee_id = u.employee_id AND r.role_name = 'employee'
    )
    ORDER BY u.last_name, u.first_name
");
$stmt->execute();
$employees = $stmt->fetchAll();
?>

<style>
.modal-overlay {
    display: none !important;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 99999;
    align-items: center;
    justify-content: center;
}
.modal-overlay.show {
    display: flex !important;
}
</style>

<div class="container-fluid py-4">
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Face Recognition Training</h5>
        </div>
        <div class="card-body">
            <p class="text-muted">Upload or update reference photos for employees to enable facial recognition check-in/out.</p>
            <div id="faceTrainingFilterWrap" style="max-width:260px;">
                <select id="faceTrainingFilter" class="form-select">
                    <option value="" disabled selected>Face Trained</option>
                    <option value="all">All Employees</option>
                    <option value="trained">Trained</option>
                    <option value="for-training">For Training</option>
                    <option value="not-trained">Not Trained</option>
                </select>
            </div>
            <div class="table-responsive" data-table-search="Search employees...">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Employee ID</th>
                            <th>Name</th>
                            <th>Current Photo</th>
                            <th>Face Trained</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $emp):
                            $hasPhoto = !empty($emp['reference_photo_data'])
                                || (!empty($emp['reference_photo']) && is_file(REFERENCE_PHOTO_PATH . basename($emp['reference_photo'])));
                            $isTrained = $hasPhoto && !empty($emp['has_encoding']);
                            $trainingStatus = $isTrained ? 'trained' : ($hasPhoto ? 'for-training' : 'not-trained');
                        ?>
                        <tr data-face-trained="<?= $trainingStatus ?>">
                            <td><?= htmlspecialchars($emp['employee_id']) ?></td>
                            <td><?= htmlspecialchars(getFullName($emp['first_name'], $emp['middle_name'], $emp['last_name'])) ?></td>
                            <td>
                                <?php if ($hasPhoto): ?>
                                    <img src="face_photo_image.php?employee_id=<?= rawurlencode($emp['employee_id']) ?>"
                                         style="width:50px;height:50px;object-fit:cover;border-radius:50%;">
                                <?php else: ?>
                                    <span class="text-muted">No photo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $isTrained ? 'success' : ($hasPhoto ? 'info' : 'warning') ?>">
                                    <?= $isTrained ? 'Trained' : ($hasPhoto ? 'For Training' : 'Not Trained') ?>
                                </span>
                            </td>
                            <td style="display:flex;gap:6px;flex-wrap:wrap;">
                                <?php if ($isTrained): ?>
                                    <button class="btn btn-sm btn-primary"
                                            data-empid="<?= htmlspecialchars($emp['employee_id']) ?>"
                                            onclick="uploadPhoto(this.dataset.empid)">
                                        Reupload
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-primary"
                                            data-empid="<?= htmlspecialchars($emp['employee_id']) ?>"
                                            onclick="uploadPhoto(this.dataset.empid)">
                                        Upload Photo
                                    </button>
                                    <?php if ($hasPhoto): ?>
                                    <button class="btn btn-sm btn-success"
                                            data-empid="<?= htmlspecialchars($emp['employee_id']) ?>"
                                            onclick="trainFace(this.dataset.empid)">
                                        Train Face
                                    </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Upload Modal — completely self-contained, no Bootstrap dependency -->
<div id="uploadModal" class="modal-overlay">
    <div style="background:#fff;border-radius:12px;padding:28px;width:90%;max-width:460px;
                box-shadow:0 20px 60px rgba(0,0,0,0.3);position:relative;">

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h5 style="margin:0;font-weight:600;">Upload Reference Photo</h5>
            <button id="modalCloseBtn"
                    style="background:none;border:none;font-size:24px;cursor:pointer;
                           color:#9CA3AF;line-height:1;padding:0 4px;">
                &times;
            </button>
        </div>

        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:14px;font-weight:500;margin-bottom:6px;">
                Select Photo (JPG or PNG, max 5MB)
            </label>
            <input type="file" id="photoFile" accept="image/jpeg,image/png"
                   style="display:block;width:100%;padding:8px;border:1px solid #D1D5DB;
                          border-radius:8px;font-size:14px;">
        </div>

        <div id="uploadPreview" style="display:none;margin-bottom:16px;text-align:center;">
            <img id="previewImg" style="max-height:150px;border-radius:8px;border:1px solid #E5E7EB;">
        </div>

        <div id="uploadStatus" style="display:none;padding:10px;border-radius:8px;
                                       margin-bottom:12px;font-size:13px;"></div>

        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button id="modalCancelBtn"
                    style="padding:8px 20px;border:1px solid #D1D5DB;border-radius:8px;
                           background:#fff;cursor:pointer;font-size:14px;">
                Cancel
            </button>
            <button id="modalUploadBtn"
                    style="padding:8px 20px;border:none;border-radius:8px;
                           background:#3B82F6;color:#fff;cursor:pointer;
                           font-size:14px;font-weight:500;">
                Upload
            </button>
        </div>
    </div>
</div>

<script>
// ── State ──────────────────────────────────────────────────────
var currentEmployeeId = null;
document.getElementById('faceTrainingFilter').addEventListener('change', function () {
    var selected = this.value || 'all';
    document.querySelectorAll('tr[data-face-trained]').forEach(function (row) {
        row.hidden = selected !== 'all' && row.dataset.faceTrained !== selected;
    });
});

function placeFaceTrainingFilter() {
    var filter = document.getElementById('faceTrainingFilterWrap');
    var searchBar = document.querySelector('.table-responsive[data-table-search]')?.previousElementSibling;
    if (searchBar && !searchBar.classList.contains('ts-bar')) searchBar = null;
    if (!filter || !searchBar) return false;
    filter.style.cssText = 'display:flex;align-items:center;gap:8px;max-width:360px;';
    filter.querySelector('select').style.width = '190px';
    searchBar.appendChild(filter);
    return true;
}
if (!placeFaceTrainingFilter() && window.MutationObserver) {
    var filterObserver = new MutationObserver(function () {
        if (placeFaceTrainingFilter()) filterObserver.disconnect();
    });
    filterObserver.observe(document.body, {childList: true, subtree: true});
}

// ── DOM refs (grabbed once after DOM ready) ────────────────────
var modal        = document.getElementById('uploadModal');
var photoFile    = document.getElementById('photoFile');
var previewBox   = document.getElementById('uploadPreview');
var previewImg   = document.getElementById('previewImg');
var statusBox    = document.getElementById('uploadStatus');
var uploadBtn    = document.getElementById('modalUploadBtn');

// ── Wire up buttons with addEventListener (never conflicts) ────
document.getElementById('modalCloseBtn').addEventListener('click',  hideModal);
document.getElementById('modalCancelBtn').addEventListener('click', hideModal);
document.getElementById('modalUploadBtn').addEventListener('click', submitUpload);

// Close when clicking the dark backdrop
modal.addEventListener('click', function(e) {
    if (e.target === modal) hideModal();
});

// Image preview
photoFile.addEventListener('change', function() {
    if (this.files && this.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewBox.style.display = 'block';
        };
        reader.readAsDataURL(this.files[0]);
    } else {
        previewBox.style.display = 'none';
    }
});

// ── Show / Hide modal (no dependency on openModal/closeModal) ──
function showModal() {
    modal.classList.add('show');
}
function hideModal() {
    modal.classList.remove('show');
    photoFile.value       = '';
    previewBox.style.display  = 'none';
    statusBox.style.display   = 'none';
    uploadBtn.disabled        = false;
    uploadBtn.textContent     = 'Upload';
}

// ── Called from table buttons ──────────────────────────────────
function uploadPhoto(employeeId) {
    console.log('uploadPhoto called for:', employeeId); // debug
    currentEmployeeId = employeeId;
    hideModal(); // reset state first
    showModal();
}

// ── Upload logic ───────────────────────────────────────────────
async function submitUpload() {
    if (!photoFile.files || photoFile.files.length === 0) {
        showStatus('Please select a photo first.', 'error');
        return;
    }

    var formData = new FormData();
    formData.append('employee_id', currentEmployeeId);
    var photo = photoFile.files[0];
    try {
        var bitmap = await createImageBitmap(photo);
        if (photo.size > 900 * 1024 || bitmap.width > 1400 || bitmap.height > 1400) {
            var scale = Math.min(1, 1400 / Math.max(bitmap.width, bitmap.height));
            var canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            var compressed = await new Promise(function (resolve) { canvas.toBlob(resolve, 'image/jpeg', 0.82); });
            if (compressed && compressed.size < photo.size) photo = compressed;
        }
        bitmap.close();
    } catch (compressionError) {
        console.warn('Photo compression skipped:', compressionError);
    }
    formData.append('photo', photo, photo.type === 'image/jpeg' ? 'reference.jpg' : photo.name);

    uploadBtn.disabled    = true;
    uploadBtn.textContent = 'Uploading…';
    showStatus('Uploading, please wait…', 'info');

    fetch('face_upload.php', {
        method: 'POST',
        body: formData
    })
    .then(function(response) {
        return response.text();
    })
    .then(function(text) {
        console.log('Raw server response:', text); // debug
        var data;
        try {
            data = JSON.parse(text);
        } catch(e) {
            throw new Error('Server returned non-JSON: ' + text.substring(0, 300));
        }

        if (data.success) {
            showStatus('Photo uploaded successfully!', 'success');
            setTimeout(function() {
                hideModal();
                location.reload();
            }, 1000);
        } else {
            showStatus('Error: ' + (data.message || 'Unknown error'), 'error');
            uploadBtn.disabled    = false;
            uploadBtn.textContent = 'Upload';
        }
    })
    .catch(function(error) {
        console.error('Upload error:', error);
        showStatus('Upload failed: ' + error.message, 'error');
        uploadBtn.disabled    = false;
        uploadBtn.textContent = 'Upload';
    });
}

// ── Train Face ─────────────────────────────────────────────────
function trainFace(employeeId) {
    if (!confirm('Train face recognition for employee ' + employeeId + '?')) return;

    fetch('face_train.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ employee_id: employeeId })
    })
    .then(function(response) { return response.text(); })
    .then(function(text) {
        console.log('Train response:', text);
        var data;
        try { data = JSON.parse(text); }
        catch(e) { throw new Error('Non-JSON response: ' + text.substring(0, 300)); }

        if (data.success) {
            var row = Array.from(document.querySelectorAll('tr[data-face-trained]')).find(function (item) {
                return item.querySelector('[data-empid]') && item.querySelector('[data-empid]').dataset.empid === employeeId;
            });
            if (row) {
                row.dataset.faceTrained = 'trained';
                var statusBadge = row.cells[3].querySelector('.badge');
                statusBadge.className = 'badge bg-success';
                statusBadge.textContent = 'Trained';

                var reupload = document.createElement('button');
                reupload.type = 'button';
                reupload.className = 'btn btn-sm btn-primary';
                reupload.dataset.empid = employeeId;
                reupload.textContent = 'Reupload';
                reupload.addEventListener('click', function () { uploadPhoto(employeeId); });
                row.cells[4].replaceChildren(reupload);

                document.getElementById('faceTrainingFilter').dispatchEvent(new Event('change'));
            }
            alert('Face trained successfully!');
        } else {
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(function(error) {
        console.error('Train error:', error);
        alert('Training failed: ' + error.message);
    });
}

// ── Status helper ──────────────────────────────────────────────
function showStatus(message, type) {
    var colors = {
        success: { bg: '#D1FAE5', color: '#065F46' },
        error:   { bg: '#FEE2E2', color: '#991B1B' },
        info:    { bg: '#DBEAFE', color: '#1E40AF' }
    };
    var c = colors[type] || colors.info;
    statusBox.style.background = c.bg;
    statusBox.style.color      = c.color;
    statusBox.textContent      = message;
    statusBox.style.display    = 'block';
}
</script>
