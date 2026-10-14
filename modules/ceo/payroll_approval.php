<?php
// ============================================================
// CEO — Payroll Approval
// Payroll Manager generates + submits -> CEO Approves or Rejects -> HR releases.
// This is the CEO's ONLY write action (see enforceCeoReadOnly in includes/auth.php).
// The CEO cannot approve payroll that the same account submitted.
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('ceo');

$pageTitle  = 'Payroll Approval';
$activePage = 'payroll_approval';
$db = getDB();
$me = $_SESSION['employee_id'];
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrfTokenIsValid($_POST['_csrf_token'] ?? null)) {
    http_response_code(403);
    $error = 'Your session validation failed. Refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['approve_change', 'reject_change'], true)) {
        $changeId = (int)($_POST['change_id'] ?? 0);
        $reason = trim(strip_tags($_POST['reason'] ?? ''));
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT * FROM payroll_change_requests WHERE id=? FOR UPDATE');
            $st->execute([$changeId]);
            $change = $st->fetch();
            if (!$change || $change['status'] !== 'pending_ceo') throw new DomainException('This change request has already been decided or does not exist.');
            if ((string)$change['submitted_by'] === (string)$me) throw new DomainException('You cannot approve or reject a change request you submitted yourself.');
            if ($action === 'reject_change' && $reason === '') throw new DomainException('A rejection reason is required.');
            $approved = $action === 'approve_change';
            $newStatus = $approved ? 'approved_pending_release' : 'rejected';
            $db->prepare('UPDATE payroll_change_requests SET status=?, reviewed_by=?, reviewed_by_name=?, reviewed_at=NOW(), reject_reason=? WHERE id=? AND status=\'pending_ceo\'')
               ->execute([$newStatus, $me, $_SESSION['name'], $approved ? null : $reason, $changeId]);
            if (!auditLog($approved ? 'CEO Approved Payroll Change' : 'CEO Rejected Payroll Change', 'Payroll', "Change request #$changeId | Type: {$change['change_type']} | Employee: {$change['employee_id']}" . ($reason ? " | Reason: $reason" : ''))) {
                throw new RuntimeException('Audit log write failed.');
            }
            $message = $approved ? 'Your payroll change request was approved by the CEO.' : 'Your payroll change request was rejected by the CEO.';
            if (!empty($change['submitted_by'])) createNotification($db, $message, 'modules/admin/payroll.php', (string)$change['submitted_by']);
            if ($approved) createNotification($db, 'A payroll change was approved and is waiting for HR release.', 'modules/admin/hr_payroll.php?change=' . $changeId, null, 'hr_admin');
            else createNotification($db, 'A payroll change request was rejected.', 'modules/admin/payroll.php', null, 'payroll_manager');
            $db->commit();
            $success = $approved ? 'Change approved. HR can release it.' : 'Change request rejected.';
        } catch (DomainException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = $e->getMessage();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('CEO payroll change decision failed: ' . $e->getMessage());
            $error = 'The change decision was not saved.';
        }
    } else {
    $id     = (int)($_POST['id'] ?? 0);
    $reason = trim(strip_tags($_POST['reason'] ?? ''));
    $db->beginTransaction();
    try {
        $st = $db->prepare("SELECT * FROM payroll_approvals WHERE id = ? FOR UPDATE");
        $st->execute([$id]);
        $a = $st->fetch();
        if (!$a) throw new DomainException('Payroll submission not found.');
        if ($a['status'] !== 'pending_ceo') throw new DomainException('This submission was already decided.');
        if ($a['submitted_by'] === $me) throw new DomainException('You cannot approve or reject a payroll that you submitted yourself.');
        if (!in_array($action, ['approve', 'reject'], true)) throw new DomainException('Unknown action.');
        if ($action === 'reject' && $reason === '') throw new DomainException('A rejection reason is required.');

        $new = $action === 'approve' ? 'approved' : 'rejected';
        $db->prepare("UPDATE payroll_approvals SET status=?, reviewed_by=?, reviewed_by_name=?, reviewed_at=NOW(), reject_reason=? WHERE id=? AND status='pending_ceo'")
           ->execute([$new, $me, $_SESSION['name'], $action === 'reject' ? $reason : null, $id]);
        if (!auditLog($action === 'approve' ? 'CEO Approved Payroll' : 'CEO Rejected Payroll', 'Payroll',
                      "Approval #$id | Period: {$a['period_start']} to {$a['period_end']} | Employee(s): " . payrollEmployeeLabel(payrollApprovalEmployees($db, $a), 5) . " | Net: {$a['net_total']}" . ($reason ? " | Reason: $reason" : ''))) {
            throw new RuntimeException('Audit log write failed.');
        }
        $decisionMessage = $action === 'approve' ? 'Payroll was approved by the CEO and is ready for HR release.' : 'Payroll was rejected by the CEO and needs revision.';
        createNotification($db, $decisionMessage, $action === 'approve' ? 'modules/admin/hr_payroll.php' : 'modules/admin/payroll.php', null, $action === 'approve' ? 'hr_admin' : 'payroll_manager');
        createNotification($db, $action === 'approve' ? 'Your payroll submission was approved by the CEO.' : 'Your payroll submission was rejected by the CEO.', 'modules/admin/payroll.php', (string)$a['submitted_by']);
        $db->commit();
        $success = $action === 'approve' ? 'Payroll approved. HR can now release it.' : 'Payroll rejected and returned to the Payroll Manager with your reason.';
    } catch (DomainException $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('CEO payroll decision failed: ' . $e->getMessage());
        $error = 'The decision was not saved because it could not be recorded.';
    }
    }
}

$rows = $db->query("SELECT * FROM payroll_approvals ORDER BY (status='pending_ceo') DESC, submitted_at DESC LIMIT 30")->fetchAll();
$changeRequests = $db->query("SELECT pcr.*, CONCAT(u.first_name,' ',u.last_name) AS employee_name FROM payroll_change_requests pcr JOIN users u ON u.employee_id=pcr.employee_id ORDER BY (pcr.status='pending_ceo') DESC, pcr.submitted_at DESC LIMIT 100")->fetchAll();
include '../../includes/header.php';
?>
<div class="page-body">
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
    <div class="card-title">Compensation &amp; YTD Change Requests</div>
    <div class="table-wrapper"><table>
        <thead><tr><th>Employee</th><th>Change</th><th>Requested values / reason</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($changeRequests as $change): $data = json_decode((string)$change['payload'], true) ?: []; $before = $data['before'] ?? []; $after = $data['after'] ?? []; $isPendingChange = $change['status'] === 'pending_ceo'; ?>
        <tr>
            <td><?= htmlspecialchars($change['employee_name']) ?><br><small><?= htmlspecialchars($change['employee_id']) ?></small></td>
            <td><?= $change['change_type'] === 'ytd' ? 'Previous employer YTD · ' . (int)$change['reference_year'] : 'Compensation' ?></td>
            <td style="font-size:12px;">
                <?php foreach ($after as $field => $value): ?><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$field))) ?>: <?= htmlspecialchars((string)($before[$field] ?? '—')) ?> → <?= htmlspecialchars((string)$value) ?><br><?php endforeach; ?>
                <?= htmlspecialchars((string)($data['reason'] ?? '')) ?>
                <?php if ($change['status'] === 'rejected' && $change['reject_reason']): ?><div style="color:#991B1B;">Rejected: <?= htmlspecialchars($change['reject_reason']) ?></div><?php endif; ?>
            </td>
            <td><span class="badge <?= $isPendingChange ? 'badge-amber' : ($change['status'] === 'approved_pending_release' ? 'badge-blue' : ($change['status'] === 'released' ? 'badge-green' : 'badge-red')) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $change['status']))) ?></span></td>
            <td>
                <?php if ($isPendingChange && (string)$change['submitted_by'] !== (string)$me): ?>
                <form method="POST" style="min-width:220px;">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="change_id" value="<?= (int)$change['id'] ?>">
                    <input type="text" name="reason" class="form-input" placeholder="Reason if rejecting" style="margin-bottom:6px;">
                    <button class="btn-primary btn-sm" name="action" value="approve_change">Approve</button>
                    <button class="btn-secondary btn-sm" name="action" value="reject_change" onclick="if(!this.form.reason.value.trim()){alert('Enter a rejection reason.');return false;}return confirm('Reject this change?')">Reject</button>
                </form>
                <?php elseif ($isPendingChange): ?>Awaiting another CEO<?php else: ?>—<?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$changeRequests): ?><tr><td colspan="5" style="text-align:center;color:#9CA3AF;padding:18px;">No compensation or YTD change requests.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php if (!$rows): ?><div class="card"><div style="color:#9CA3AF;text-align:center;padding:24px;">No payroll has been submitted for review yet.</div></div><?php endif; ?>

<?php foreach ($rows as $a): $ci = payCutoffInfo($a['period_start'], $a['period_end']); $pending = $a['status'] === 'pending_ceo';
    $emps = payrollApprovalEmployees($db, $a);
    $nReleased = count(array_filter($emps, static fn($e) => ($e['payroll_status'] ?? '') === 'released'));
    $released = $a['status'] === 'approved' && $emps && $nReleased === count($emps); ?>
<div class="card" style="<?= $pending ? 'border-left:4px solid #D97706;' : '' ?>">
    <div class="card-title">
        <span>Payroll Period: <?= formatDate($a['period_start']) ?> – <?= formatDate($a['period_end']) ?>
            <span style="font-weight:400;font-size:12px;color:#6B7280;">· cut-off <?= $ci['cutoff'] ?> · pay date <?= formatDate($ci['pay_date']) ?></span></span>
        <span class="badge <?= $a['status']==='approved'?'badge-green':($a['status']==='rejected'?'badge-red':'badge-amber') ?>"><?= $pending ? 'Pending CEO review' : ucfirst($a['status']) ?><?= $released ? ' · Released by HR' : ($a['status']==='approved' ? ' · Waiting for HR release' : '') ?></span>
    </div>
    <!-- WHO this payroll is for -->
    <div style="margin-bottom:12px;">
        <div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;"><?= count($emps) === 1 ? 'Payroll generated for this employee' : 'Payroll generated for ' . count($emps) . ' employees' ?></div>
        <?php if (count($emps) === 1): $e = $emps[0]; ?>
            <div style="display:inline-block;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:8px 12px;">
                <strong><?= htmlspecialchars($e['employee_name']) ?></strong>
                <span style="font-family:monospace;font-size:12px;color:#6B7280;"> <?= htmlspecialchars($e['employee_id']) ?></span>
                <div style="font-size:11px;color:#6B7280;"><?= htmlspecialchars(trim(($e['department'] ?? '') . ' · ' . ($e['position'] ?? ''), ' ·')) ?></div>
            </div>
        <?php elseif ($emps): ?>
            <details <?= $pending && count($emps) <= 8 ? 'open' : '' ?>>
                <summary style="cursor:pointer;font-size:12px;color:#2563EB;"><?= htmlspecialchars(payrollEmployeeLabel($emps, 3)) ?> — show all</summary>
                <div class="table-wrapper" style="margin-top:8px;"><table>
                    <thead><tr><th>Employee</th><th>ID</th><th>Department</th><th>Position</th><th>Gross</th><th>Net pay</th></tr></thead>
                    <tbody>
                    <?php foreach ($emps as $e): ?>
                    <tr><td><?= htmlspecialchars($e['employee_name']) ?></td>
                        <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($e['employee_id']) ?></td>
                        <td><?= htmlspecialchars((string)($e['department'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($e['position'] ?? '')) ?></td>
                        <td><?= formatMoney((float)$e['gross_pay']) ?></td>
                        <td><strong><?= formatMoney((float)$e['net_salary']) ?></strong></td></tr>
                    <?php endforeach; ?>
                    <?php if ($emps): ?><tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="4">Total</td><td><?= formatMoney(array_sum(array_column($emps, 'gross_pay'))) ?></td><td><?= formatMoney(array_sum(array_column($emps, 'net_salary'))) ?></td></tr><?php endif; ?>
                    </tbody></table></div>
            </details>
        <?php endif; ?>
        <?php if (count($emps) === 1): ?><div style="font-size:12px;color:#6B7280;margin-top:6px;">Gross <?= formatMoney((float)$emps[0]['gross_pay']) ?> · Net pay <strong><?= formatMoney((float)$emps[0]['net_salary']) ?></strong></div><?php endif; ?>
    </div>
    <div class="row g-2" style="margin-bottom:10px;">
        <?php foreach ([
            ['Employees', (int)$a['employee_count']], ['Gross pay', formatMoney((float)$a['gross_total'])],
            ['Deductions', formatMoney((float)$a['deduction_total'])], ['Net pay', formatMoney((float)$a['net_total'])],
            ['Employer contributions', formatMoney((float)$a['employer_total'])], ['Christmas Bonus', formatMoney((float)$a['bonus_total'])],
            ['Adjustments', formatMoney((float)$a['adjustment_total'])], ['13th month', formatMoney((float)$a['thirteenth_total'])],
        ] as [$l, $v]): ?>
        <div class="col-6 col-md-3"><div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:10px;"><div style="font-weight:700;"><?= $v ?></div><div style="font-size:11px;color:#6B7280;"><?= $l ?></div></div></div>
        <?php endforeach; ?>
    </div>
    <div style="font-size:12px;color:#6B7280;">Prepared by <strong><?= htmlspecialchars($a['submitted_by_name'] ?? '—') ?></strong> on <?= date('M j, Y g:i A', strtotime($a['submitted_at'])) ?>
        <?php if ($a['reviewed_at']): ?> · Decided by <strong><?= htmlspecialchars($a['reviewed_by_name'] ?? '') ?></strong> on <?= date('M j, Y g:i A', strtotime($a['reviewed_at'])) ?><?php endif; ?></div>
    <?php if ($a['status'] === 'rejected'): ?><div style="margin-top:6px;color:#991B1B;font-size:12px;">Rejection reason: <?= htmlspecialchars($a['reject_reason'] ?? '') ?></div><?php endif; ?>
    <?php if ($pending): ?>
        <?php if ($a['submitted_by'] === $me): ?>
            <div class="alert alert-error" style="margin-top:10px;">You submitted this payroll, so another approver must decide it.</div>
        <?php else: ?>
        <form method="POST" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <textarea name="reason" class="form-textarea" rows="2" style="flex:1;min-width:240px;" placeholder="Reason (required only when rejecting)"></textarea>
            <button class="btn-primary" name="action" value="approve" style="background:#059669;" onclick="return confirm(<?= htmlspecialchars(json_encode('Approve the payroll of ' . payrollEmployeeLabel($emps, 3) . '?', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">Approve</button>
            <button class="btn-primary" name="action" value="reject" style="background:#DC2626;" onclick="if(!this.form.reason.value.trim()){alert('Please enter the rejection reason.');return false;}return confirm('Reject this payroll?')">Reject</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
