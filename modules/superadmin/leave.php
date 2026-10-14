<?php
// ============================================================
// Directpath HRMS — Leave Management (Admin)
// Pinagbago: Kapag nag-approve ng leave, babawasan ang leave_balances
// Kapag nag-reject, hindi mababawasan (nandito pa rin ang full days)
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin');
header('Location: ../admin/leave.php');
exit;

$pageTitle  = 'Leave Management';
$activePage = 'leave';
$db = getDB();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $lid    = (int)($_POST['leave_id'] ?? 0);
    $remark = sanitize($_POST['remarks'] ?? '');

    // === APPROVE LEAVE ===
    if ($action === 'approve') {
        // Kunin ang details ng leave request bago i-approve
        $stmt = $db->prepare("SELECT * FROM leave_requests WHERE id=?");
        $stmt->execute([$lid]);
        $lr = $stmt->fetch();

        if ($lr && $lr['status'] === 'pending') {
            // I-update ang status ng leave request sa 'approved'
            $db->prepare("UPDATE leave_requests SET status='approved', reviewed_by=?, review_date=NOW(), review_remarks=? WHERE id=?")
               ->execute([$_SESSION['name'], $remark, $lid]);

            // === IMPORTANTE: Ibawas ang used days sa leave_balances table ===
            // Kinukuha ang taon ng leave para ma-update ang tamang year record
            $leaveYear = (int)date('Y', strtotime($lr['start_date']));
            $days      = (int)$lr['total_days'];
            $leaveType = $lr['leave_type'];

            // Ang column na babaguhin ay depende sa leave type
            // e.g., vacation = vacation_used, sick = sick_used, etc.
            $usedColumn = $leaveType . '_used'; // dinamikong column name

            // Ensure na may existing leave_balances record para sa employee + year
            $db->prepare("INSERT IGNORE INTO leave_balances (employee_id, year) VALUES (?, ?)")
               ->execute([$lr['employee_id'], $leaveYear]);

            // Idagdag sa *_used column ang number of days na na-approve
            // Ginagamit ang LEAST() para hindi lalampas sa total (safety net)
            $totalColumn = $leaveType . '_total';
            $db->prepare("UPDATE leave_balances SET $usedColumn = LEAST($usedColumn + ?, $totalColumn) WHERE employee_id=? AND year=?")
               ->execute([$days, $lr['employee_id'], $leaveYear]);

            auditLog('Approve Leave', 'Leave', "Approved leave ID: $lid ({$leaveType}, {$days} days)");
            $success = "Leave request approved. {$days} {$leaveType} day(s) deducted from employee balance.";
        } else {
            $error = 'Cannot approve — leave not found or already reviewed.';
        }
    }

    // === REJECT LEAVE ===
    if ($action === 'reject') {
        // Kapag reject, HINDI babaguhin ang leave_balances — walang mababawas
        $db->prepare("UPDATE leave_requests SET status='rejected', reviewed_by=?, review_date=NOW(), review_remarks=? WHERE id=?")
           ->execute([$_SESSION['name'], $remark, $lid]);
        auditLog('Reject Leave', 'Leave', "Rejected leave ID: $lid | Remarks: $remark");
        $success = 'Leave request rejected.';
    }

}

// Filters para sa leave table
$filterType   = sanitize($_GET['type']   ?? '');

$leaveTabGroups = [
    'pending'  => ['Pending',              ['pending']],
    'approved' => ['Approved / Processed', ['approved', 'approved_pending_documents', 'documents_submitted', 'documents_rejected']],
    'rejected' => ['Rejected',             ['rejected']],
    'all'      => ['All Requests',         null],
];
$leaveTab = $_GET['tab'] ?? 'pending';
if (!isset($leaveTabGroups[$leaveTab])) $leaveTab = 'pending';
$leaveCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
foreach ($db->query("SELECT status, COUNT(*) c FROM leave_requests GROUP BY status")->fetchAll() as $cr) {
    foreach ($leaveTabGroups as $k => [$lbl, $sts]) if ($sts && in_array($cr['status'], $sts, true)) $leaveCounts[$k] += (int)$cr['c'];
    $leaveCounts['all'] += (int)$cr['c'];
}

$where  = "WHERE 1=1";
$params = [];
if ($leaveTabGroups[$leaveTab][1]) {
    $in = implode(',', array_fill(0, count($leaveTabGroups[$leaveTab][1]), '?'));
    $where .= " AND lr.status IN ($in)"; array_push($params, ...$leaveTabGroups[$leaveTab][1]);
}
if ($filterType)   { $where .= " AND lr.leave_type=?"; $params[] = $filterType; }

// Kinukuha ang full name ng employee mula sa users table (first + middle + last)
$stmt = $db->prepare("
    SELECT lr.*,
           CONCAT(u.first_name,
                  IF(u.middle_name IS NOT NULL AND u.middle_name != '',
                     CONCAT(' ', SUBSTRING(u.middle_name,1,1), '.'), ''),
                  ' ', u.last_name) AS employee_name,
              u.department, u.position, u.email
    FROM leave_requests lr
    JOIN users u ON lr.employee_id = u.employee_id
    $where
    ORDER BY lr.created_at DESC, lr.id DESC
");
$stmt->execute($params);
$leaves = $stmt->fetchAll();

include '../../includes/header.php';
?>

<style>
.leave-detail-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px 20px; }
.leave-detail-item { min-width:0; }
.leave-detail-item dt { margin-bottom:4px; color:#6B7280; font-size:11px; font-weight:600; text-transform:uppercase; }
.leave-detail-item dd { margin:0; color:#1F2937; font-size:14px; overflow-wrap:anywhere; white-space:pre-wrap; }
.leave-details-button { color:#2563EB; }
</style>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php $lt = []; foreach ($leaveTabGroups as $k => [$lbl, $sts]) $lt[$k] = [$lbl, $leaveCounts[$k]]; ?>
<?= renderTabBar($lt, $leaveTab, 'tab', $filterType ? ['type' => $filterType] : []) ?>
<div class="card">
    <form method="GET" class="filter-bar" style="margin:0;">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($leaveTab) ?>">
        <select name="type" class="form-select" style="width:auto;">
            <option value="">All Types</option>
            <?php foreach (['vacation','sick','emergency','maternity','paternity','others'] as $lt2): ?>
            <option value="<?= $lt2 ?>" <?= $filterType===$lt2?'selected':'' ?>><?= ucfirst($lt2) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-primary">Filter</button>
        <a href="leave.php?tab=<?= htmlspecialchars($leaveTab) ?>" class="btn-secondary">Reset</a>
    </form>
</div>

<!-- Leave Requests Table -->
<div class="card">
    <div class="card-title">Leave Requests (<?= count($leaves) ?>)</div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Employee</th><th>Department</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($leaves as $lr): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($lr['employee_name']) ?></div>
                        <div style="font-size:11px; color:#9CA3AF;"><?= htmlspecialchars($lr['employee_id']) ?></div>
                    </td>
                    <td><?= htmlspecialchars($lr['department']) ?></td>
                    <td><span class="badge badge-blue"><?= ucfirst($lr['leave_type']) ?></span></td>
                    <td><?= formatDate($lr['start_date']) ?></td>
                    <td><?= formatDate($lr['end_date']) ?></td>
                    <td style="text-align:center;"><?= $lr['total_days'] ?></td>
                    <td style="font-size:12px;"><?= htmlspecialchars(substr($lr['reason'], 0, 40)) ?>...</td>
                    <td><span class="badge <?= statusBadge($lr['status']) ?>"><?= htmlspecialchars(formatLeaveStatus($lr['status'])) ?></span></td>
                    <td>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <button class="btn-secondary btn-sm leave-details-button" type="button"
                                    data-leave="<?= htmlspecialchars(json_encode($lr, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>"
                                    onclick="viewLeaveDetails(this)">Details</button>
                            <?php if ($lr['status'] === 'pending'): ?>
                            <!-- Approve Button — may remarks modal -->
                            <button class="btn-secondary btn-sm" style="color:#059669;"
                                    onclick="reviewLeave(<?= $lr['id'] ?>, 'approve', '<?= htmlspecialchars($lr['employee_name']) ?>')">
                                Approve
                            </button>
                            <button class="btn-secondary btn-sm" style="color:#DC2626;"
                                    onclick="reviewLeave(<?= $lr['id'] ?>, 'reject', '<?= htmlspecialchars($lr['employee_name']) ?>')">
                                Reject
                            </button>
                            <?php endif; ?>
                            <!-- Delete button — laging available -->
                            <button class="btn-secondary btn-sm" style="color:#6B7280;"
                                    onclick="deleteLeave(<?= $lr['id'] ?>)">
                                Delete
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($leaves)): ?>
                <tr><td colspan="9" style="text-align:center; color:#9CA3AF; padding:24px;">No leave requests found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<div class="modal-overlay" id="leaveDetailsModal" role="dialog" aria-modal="true" aria-labelledby="leaveDetailsTitle">
    <div class="modal-box" style="max-width:680px;max-height:90vh;overflow:auto;">
        <div class="modal-header">
            <div class="modal-title" id="leaveDetailsTitle">Leave Request Details</div>
            <button class="modal-close" type="button" aria-label="Close details" onclick="closeModal('leaveDetailsModal')">&times;</button>
        </div>
        <dl id="leaveDetailsGrid" class="leave-detail-grid"></dl>
        <div style="display:flex;justify-content:flex-end;margin-top:20px;">
            <button type="button" class="btn-secondary" onclick="closeModal('leaveDetailsModal')">Close</button>
        </div>
    </div>
</div>

<!-- Review Modal (para sa approve at reject) -->
<div class="modal-overlay" id="reviewModal">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-header">
            <div class="modal-title" id="reviewModalTitle">Review Leave</div>
            <button class="modal-close" onclick="closeModal('reviewModal')">&times;</button>
        </div>
        <form method="POST" id="reviewForm">
            <input type="hidden" name="action"   id="reviewAction">
            <input type="hidden" name="leave_id" id="reviewLeaveId">
            <div class="form-group">
                <label class="form-label">Employee</label>
                <input type="text" id="reviewEmpName" class="form-input" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">Remarks <span style="color:#9CA3AF;font-size:11px;">(optional)</span></label>
                <textarea name="remarks" class="form-textarea" rows="3"
                          placeholder="Enter remarks or reason..."></textarea>
            </div>
            <div style="display:flex; gap:10px; margin-top:12px;">
                <button type="submit" class="btn-primary" id="reviewSubmitBtn">Submit</button>
                <button type="button" class="btn-secondary" onclick="closeModal('reviewModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Leave Form (hidden) -->
<form method="POST" id="deleteLeaveForm">
    <input type="hidden" name="action"   value="delete">
    <input type="hidden" name="leave_id" id="deleteLeaveId">
</form>

<script>
function viewLeaveDetails(button) {
    const request = JSON.parse(button.dataset.leave);
    const fields = [
        ['Request ID', request.id],
        ['Employee', request.employee_name],
        ['Employee ID', request.employee_id],
        ['Department', request.department],
        ['Position', request.position],
        ['Email', request.email],
        ['Leave type', request.leave_type],
        ['Start date', request.start_date],
        ['End date', request.end_date],
        ['Total days', request.total_days],
        ['Reason', request.reason],
        ['Status', request.status],
        ['Submitted', request.created_at],
        ['Reviewed by', request.reviewed_by],
        ['Review date', request.review_date],
        ['Review remarks', request.review_remarks]
    ];
    const grid = document.getElementById('leaveDetailsGrid');
    grid.replaceChildren();
    fields.forEach(([label, value]) => {
        const item = document.createElement('div');
        item.className = 'leave-detail-item';
        const term = document.createElement('dt');
        term.textContent = label;
        const description = document.createElement('dd');
        description.textContent = value === null || value === '' || value === undefined ? '—' : String(value);
        item.append(term, description);
        grid.appendChild(item);
    });
    openModal('leaveDetailsModal');
}

// Binubuksan ang review modal para sa approve o reject
function reviewLeave(id, action, empName) {
    document.getElementById('reviewLeaveId').value = id;
    document.getElementById('reviewAction').value  = action;
    document.getElementById('reviewEmpName').value = empName;

    // I-update ang modal title at button color depende sa action
    if (action === 'approve') {
        document.getElementById('reviewModalTitle').textContent = 'Approve Leave Request';
        document.getElementById('reviewSubmitBtn').style.background = '#059669';
    } else {
        document.getElementById('reviewModalTitle').textContent = 'Reject Leave Request';
        document.getElementById('reviewSubmitBtn').style.background = '#DC2626';
    }
    openModal('reviewModal');
}

// Delete ng leave record — nagtatanong muna
function deleteLeave(id) {
    if (confirm('Delete this leave record? This action cannot be undone.')) {
        document.getElementById('deleteLeaveId').value = id;
        document.getElementById('deleteLeaveForm').submit();
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
