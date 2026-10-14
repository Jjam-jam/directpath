<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('employee');

$pageTitle  = 'Requests';
$activePage = 'requests';
$db = getDB();
$empId = $_SESSION['employee_id'];

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'request_coe') {
        // The reason must be one of the approved choices (no free text)
        $purpose = trim((string)($_POST['purpose'] ?? ''));
        if (!in_array($purpose, COE_REASONS, true)) {
            $error = 'Please select a reason for your COE request.';
        } else {
            // No HR approval step: the COE is generated immediately and can be viewed and printed right away.
            $db->prepare("INSERT INTO employee_requests (employee_id, request_type, purpose, status, coe_sent, reviewed_by, review_date, admin_notes)
                          VALUES (?, 'coe', ?, 'approved', 1, 'System (automatic)', NOW(), 'Generated automatically')")
               ->execute([$empId, $purpose]);
            $newCoeId = (int)$db->lastInsertId();
            auditLog('Generate COE', 'Requests', 'COE generated automatically | Reason: ' . $purpose);
            $success = 'Your Certificate of Employment was generated. Use View & Print COE below.';
        }
    }
}

// Load my requests
$myRequests = $db->prepare("SELECT er.*, p.payroll_period_start, p.payroll_period_end FROM employee_requests er LEFT JOIN payroll p ON er.payroll_id=p.id WHERE er.employee_id=? ORDER BY er.created_at DESC LIMIT 50");
$myRequests->execute([$empId]);
$requests = $myRequests->fetchAll();

// Payroll periods for dropdown
$payrolls = $db->prepare("SELECT * FROM payroll WHERE employee_id=? AND status IN ('generated','released') ORDER BY payroll_period_start DESC");
$payrolls->execute([$empId]);
$myPayrolls = $payrolls->fetchAll();

include '../../includes/header.php';
?>


<!-- Boxicons CDN -->

<style>

.stat-icon-box {
    width: 48px;
    height: 48px;
    min-width: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

</style>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Small button top right -->
<div style="display:flex; justify-content:flex-end; margin-bottom:12px;">
    <button class="btn-primary" onclick="openModal('coeReqModal')"
            style="padding:8px 16px; font-size:13px; border-radius:8px;">
        + Request COE
    </button>
</div>



<!-- ── COE Request History Table ────────────────────────── -->
<div class="card">
    <div class="card-title">My COE Request History</div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Reason</th>
                    <th>Date Submitted</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r): ?>
                <tr>
                    <!-- Type badge — COE lang nandito -->
                    <td>
                        <span class="badge badge-blue">COE</span>
                    </td>
 
                    <!-- Purpose na sinulat ng empleyado -->
                    <td style="font-size:12px;">
                        <?= htmlspecialchars($r['purpose'] ?? '—') ?>
                    </td>
 
                    <!-- Petsa ng pag-submit -->
                    <td style="font-size:12px;">
                        <?= formatDate($r['created_at']) ?>
                    </td>
 
                    <!-- Status badge -->
                    <td>
                        <span class="badge <?= statusBadge($r['status']) ?>">
                            <?= ucfirst($r['status']) ?>
                        </span>
                    </td>
 
                    <!-- Action column — depende sa status at coe_sent -->
                    <td>
                        <?php if ($r['status'] === 'approved'): ?>
                            <?php if (!empty($r['coe_file']) || !empty($r['coe_sent'])): ?>
                                <!-- COE ready na — pwede nang i-view at i-print -->
                                <a href="coe_view.php?req=<?= (int)$r['id'] ?>"
                                   target="_blank"
                                   class="btn-primary btn-sm">
                                    View &amp; Print COE
                                </a>
                            <?php else: ?>
                                <!-- Approved pero hindi pa nire-release ng HR -->
                                <span style="font-size:12px; color:#F59E0B;">
                                    Generating
                                </span>
                            <?php endif; ?>
 
                        <?php elseif ($r['status'] === 'pending'): ?>
                            <!-- Naghihintay pa ng approval -->
                            <span style="font-size:12px; color:#9CA3AF;">
                                Processing
                            </span>
 
                        <?php elseif ($r['status'] === 'rejected'): ?>
                            <!-- Na-reject — ipakita kung may dahilan -->
                            <span style="font-size:12px; color:#DC2626;">
                                Rejected
                            </span>
 
                        <?php else: ?>
                            <span style="font-size:12px; color:#9CA3AF;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
 
                <?php if (empty($requests)): ?>
                <tr>
                    <td colspan="5" style="text-align:center; color:#9CA3AF; padding:28px;">
                        <i class="bx bx-folder-open" style="font-size:28px; display:block; margin-bottom:8px; color:#E5E7EB;"></i>
                        No COE requests yet.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- COE Request Modal -->
<div class="modal-overlay" id="coeReqModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Request Certificate of Employment</div>
            <button class="modal-close" onclick="closeModal('coeReqModal')" aria-label="Close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="request_coe">
            <div class="form-group">
                <label class="form-label" for="coeReason">Reason for request *</label>
                <select name="purpose" id="coeReason" class="form-select" required>
                    <option value="">Select a reason</option>
                    <?php foreach (COE_REASONS as $reason): ?>
                    <option value="<?= htmlspecialchars($reason) ?>"><?= htmlspecialchars($reason) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="background:#DBEAFE; border-radius:8px; padding:12px; font-size:12px; color:#1E40AF; margin-bottom:16px;">
                Your COE is generated right away from your employment record. No HR approval is needed.
            </div>
            <div style="display:flex; gap:10px;">
                <button type="submit" class="btn-primary">Submit Request</button>
                <button type="button" class="btn-secondary" onclick="closeModal('coeReqModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>
