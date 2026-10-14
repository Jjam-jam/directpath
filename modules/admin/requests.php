<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin', 'payroll_manager');

$pageTitle  = 'Employee Requests';
$activePage = 'requests';
$db = getDB();
$requestType = $_SESSION['role'] === 'hr_admin' ? 'coe' : 'payslip';

$success = '';
$error   = '';

// ── Handle Actions ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $reqId  = (int)($_POST['req_id'] ?? 0);
    $notes  = sanitize($_POST['admin_notes'] ?? '');

    if ($action === 'approve' || $action === 'reject') {
        $typeStmt = $db->prepare("SELECT request_type, employee_id FROM employee_requests WHERE id=?");
        $typeStmt->execute([$reqId]);
        $request = $typeStmt->fetch();
        if (!$request || $request['request_type'] !== $requestType) {
            $error = 'This request type is outside your responsibilities.';
        } else {
            $status = $action === 'approve' ? 'approved' : 'rejected';
            $stmt   = $db->prepare("UPDATE employee_requests SET status=?, admin_notes=?, reviewed_by=?, review_date=NOW() WHERE id=? AND request_type=?");
            $stmt->execute([$status, $notes, $_SESSION['name'], $reqId, $requestType]);
            auditLog(ucfirst($action) . ' Request', 'Requests', "$action $requestType request ID: $reqId");
            createNotification($db, "Your $requestType request was $status." . ($notes ? " HR notes: $notes" : ''), 'modules/employee/requests.php', (string)$request['employee_id']);
            $success = "Request has been $status.";
        }
    }
}

// ── Load Requests (tabs: Pending / Approved-Processed / Rejected / All; newest first) ──
$tabs = [
    'pending'  => ['label' => 'Pending',             'status' => 'pending'],
    'approved' => ['label' => 'Approved / Processed', 'status' => 'approved'],
    'rejected' => ['label' => 'Rejected',            'status' => 'rejected'],
    'all'      => ['label' => 'All Requests',        'status' => null],
];
$tab = $_GET['tab'] ?? 'pending';
if (!isset($tabs[$tab])) $tab = 'pending';

$cnt = $db->prepare("SELECT status, COUNT(*) c FROM employee_requests WHERE request_type=? GROUP BY status");
$cnt->execute([$requestType]);
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($cnt->fetchAll() as $c) $counts[$c['status']] = (int)$c['c'];
$counts['all'] = array_sum($counts);

$where  = "WHERE er.request_type=?";
$params = [$requestType];
if ($tabs[$tab]['status']) { $where .= " AND er.status=?"; $params[] = $tabs[$tab]['status']; }

$stmt = $db->prepare("
    SELECT
        er.*,
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
        e.department,
        e.position,
        e.email
    FROM employee_requests er
    JOIN users e ON er.employee_id = e.employee_id
    $where
    ORDER BY er.created_at DESC, er.id DESC
");

$stmt->execute($params);
$requests = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Request tabs -->
<div class="tab-bar">
    <?php foreach ($tabs as $key => $t): ?>
    <a href="requests.php?tab=<?= $key ?>" class="tab-link <?= $tab === $key ? 'active' : '' ?>">
        <?= htmlspecialchars($t['label']) ?> <span class="tab-count"><?= (int)$counts[$key] ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Requests Table -->
<div class="card">
    <div class="card-title"><?= htmlspecialchars($tabs[$tab]['label']) ?> (<?= count($requests) ?>) <span class="muted-note">Newest first</span></div>
    <div class="table-wrapper" data-table-search="Search requests...">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Request Type</th>
                    <th>Reason</th>
                    <th>Requested</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($r['employee_name']) ?></div>
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
                            <form method="POST" action="requests.php" style="display:inline;">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="req_id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn-primary btn-sm"
                                    onclick="return confirm('Approve this request from <?= htmlspecialchars($r['employee_name'], ENT_QUOTES) ?>?')">
                                    Approve
                                </button>
                            </form>
                            <form method="POST" action="requests.php" style="display:inline;">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="req_id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn-secondary btn-sm" style="color:#CD1E27;"
                                    onclick="return confirm('Reject this request from <?= htmlspecialchars($r['employee_name'], ENT_QUOTES) ?>?')">
                                    Reject
                                </button>
                            </form>
                            <?php endif; ?>

                            <?php if ($r['status'] === 'approved' && $r['request_type'] === 'coe'): ?>
                            <a href="coe.php?emp=<?= urlencode($r['employee_id']) ?>&req=<?= $r['id'] ?>" target="_blank" class="btn-secondary btn-sm">Generate COE</a>
                            <?php endif; ?>

                            <?php if ($r['status'] === 'approved' && $r['request_type'] === 'payslip' && !empty($r['payroll_id'])): ?>
                            <a href="payslip.php?id=<?= $r['payroll_id'] ?>" target="_blank" class="btn-secondary btn-sm">View Payslip</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($requests)): ?>
                <tr><td colspan="6" style="text-align:center; color:#9CA3AF; padding:24px;">No requests in this tab.</td></tr>
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
            <button class="modal-close" onclick="closeModal('reviewModal')" aria-label="Close">&times;</button>
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
