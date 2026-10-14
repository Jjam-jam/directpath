<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin', 'payroll_manager');
header('Location: ../admin/requests.php');
exit;

$pageTitle  = 'Employee Requests';
$activePage = 'requests';
$db = getDB();

$success = '';
$error   = '';

// ── Handle Actions ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $reqId  = (int)($_POST['req_id'] ?? 0);
    $notes  = sanitize($_POST['admin_notes'] ?? '');

    if ($action === 'approve' || $action === 'reject') {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt   = $db->prepare("UPDATE employee_requests SET status=?, admin_notes=?, reviewed_by=?, review_date=NOW() WHERE id=?");
        $stmt->execute([$status, $notes, $_SESSION['name'], $reqId]);
        auditLog(ucfirst($action) . ' Request', 'Requests', "$action request ID: $reqId");
        $success = "Request has been $status.";
    }
}

// ── Load Requests ──────────────────────────────────────────
$filterType   = sanitize($_GET['type']   ?? '');
$reqTabs = ['pending' => 'Pending', 'approved' => 'Approved / Processed', 'rejected' => 'Rejected', 'all' => 'All Requests'];
$reqTab = $_GET['tab'] ?? 'pending';
if (!isset($reqTabs[$reqTab])) $reqTab = 'pending';
$reqCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
foreach ($db->query("SELECT status, COUNT(*) c FROM employee_requests GROUP BY status")->fetchAll() as $cr) {
    if (isset($reqCounts[$cr['status']])) $reqCounts[$cr['status']] += (int)$cr['c'];
    $reqCounts['all'] += (int)$cr['c'];
}

$where  = "WHERE 1=1";
$params = [];
if ($filterType)   { $where .= " AND er.request_type=?"; $params[] = $filterType; }
if ($reqTab !== 'all') { $where .= " AND er.status=?"; $params[] = $reqTab; }

$stmt = $db->prepare("SELECT er.*, CONCAT(e.first_name, ' ', e.last_name), e.department, e.position, e.email FROM employee_requests er JOIN users e ON er.employee_id=e.employee_id $where ORDER BY er.created_at DESC, er.id DESC");
$stmt->execute($params);
$requests = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php $rt = []; foreach ($reqTabs as $k => $lbl) $rt[$k] = [$lbl, $reqCounts[$k]]; ?>
<?= renderTabBar($rt, $reqTab, 'tab', $filterType ? ['type' => $filterType] : []) ?>
<div class="card">
    <form method="GET" class="filter-bar" style="margin:0;">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($reqTab) ?>">
        <select name="type" class="form-select" style="width:auto;">
            <option value="">All Types</option>
            <option value="payslip" <?= $filterType==='payslip'?'selected':'' ?>>Payslip Request</option>
            <option value="coe"     <?= $filterType==='coe'?'selected':'' ?>>Certificate of Employment</option>
        </select>
        <button type="submit" class="btn-primary">Filter</button>
        <a href="requests.php?tab=<?= htmlspecialchars($reqTab) ?>" class="btn-secondary">Reset</a>
    </form>
</div>

<!-- Requests Table -->
<div class="card">
    <div class="card-title">Employee Requests (<?= count($requests) ?>)</div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Request Type</th>
                    <th>Purpose</th>
                    <th>Requested</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($r['name']) ?></div>
                        <div style="font-size:11px; color:#9CA3AF;"><?= htmlspecialchars($r['department']) ?></div>
                    </td>
                    <td>
                        <span class="badge <?= $r['request_type']==='coe'?'badge-blue':'badge-amber' ?>">
                            <?= $r['request_type']==='coe' ? 'Certificate of Employment' : 'Payslip' ?>
                        </span>
                    </td>
                    <td style="font-size:12px; max-width:200px;"><?= htmlspecialchars($r['purpose'] ?? '—') ?></td>
                    <td style="font-size:12px;"><?= formatDate($r['created_at']) ?></td>
                    <td><span class="badge <?= statusBadge($r['status']) ?>"><?= ucfirst($r['status']) ?></span></td>
                    <td>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <?php if ($r['status'] === 'pending'): ?>
                            <button class="btn-primary btn-sm" onclick="openReviewModal(<?= $r['id'] ?>, 'approve', '<?= htmlspecialchars($r['name']) ?>', '<?= $r['request_type'] ?>')">Approve</button>
                            <button class="btn-secondary btn-sm" style="color:#CD1E27;" onclick="openReviewModal(<?= $r['id'] ?>, 'reject', '<?= htmlspecialchars($r['name']) ?>', '<?= $r['request_type'] ?>')">Reject</button>
                            <?php endif; ?>

                            <?php if ($r['status'] === 'approved' && $r['request_type'] === 'coe'): ?>
                            <a href="coe.php?emp=<?= urlencode($r['employee_id']) ?>" target="_blank" class="btn-secondary btn-sm">Generate COE</a>
                            <?php endif; ?>

                            <?php if ($r['status'] === 'approved' && $r['request_type'] === 'payslip' && $r['payroll_id']): ?>
                            <a href="payslip.php?id=<?= $r['payroll_id'] ?>" target="_blank" class="btn-secondary btn-sm">View Payslip</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($requests)): ?>
                <tr><td colspan="6" style="text-align:center; color:#9CA3AF; padding:24px;">No requests found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<!-- Review Modal -->
<div class="modal-overlay" id="reviewModal">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-header">
            <div class="modal-title" id="reviewTitle">Review Request</div>
            <button class="modal-close" onclick="closeModal('reviewModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" id="reviewAction">
            <input type="hidden" name="req_id" id="reviewId">
            <div style="font-size:13px; color:#6B7280; margin-bottom:16px;" id="reviewDesc"></div>
            <div class="form-group">
                <label class="form-label">Admin Notes (optional)</label>
                <textarea name="admin_notes" class="form-textarea" rows="3" placeholder="Add notes..."></textarea>
            </div>
            <div style="display:flex; gap:10px;">
                <button type="submit" class="btn-primary" id="reviewBtn">Confirm</button>
                <button type="button" class="btn-secondary" onclick="closeModal('reviewModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReviewModal(id, action, name, type) {
    document.getElementById('reviewId').value     = id;
    document.getElementById('reviewAction').value = action;
    document.getElementById('reviewTitle').textContent = action === 'approve' ? 'Approve Request' : 'Reject Request';
    document.getElementById('reviewDesc').textContent  = `${action === 'approve' ? 'Approve' : 'Reject'} ${type.toUpperCase()} request from ${name}?`;
    document.getElementById('reviewBtn').textContent   = action === 'approve' ? 'Approve' : 'Reject';
    document.getElementById('reviewBtn').style.background = action === 'approve' ? '#16A34A' : '#CD1E27';
    openModal('reviewModal');
}
</script>

<?php include '../../includes/footer.php'; ?>
