<?php
// ============================================================
// Directpath EMPS — HR/Admin review of employee loan submissions (SOA)
// For HR Review -> Verified -> Approved for Payroll Deduction (or Rejected / Needs Info).
// Nothing here is reachable by employees; every action is checked server-side.
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin');

$pageTitle  = 'Loan Documents';
$activePage = 'loan_review';
$db = getDB();
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action  = $_POST['action'];
    $id      = (int)($_POST['id'] ?? 0);
    $remarks = sanitize($_POST['remarks'] ?? '');
    $db->beginTransaction();
    try {
        $st = $db->prepare("SELECT * FROM loan_disclosures WHERE id = ? FOR UPDATE");
        $st->execute([$id]);
        $ln = $st->fetch();
        if (!$ln) throw new DomainException('Loan submission not found.');
        $open = in_array($ln['status'], ['for_review', 'verified'], true);

        if ($action === 'verify') {
            if ($ln['status'] !== 'for_review') throw new DomainException('Only a submission that is For HR Review can be verified.');
            $db->prepare("UPDATE loan_disclosures SET status='verified', hr_remarks=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
               ->execute([$remarks ?: null, $_SESSION['employee_id'], $id]);
            $msg = 'Loan information verified against the document.';
        } elseif ($action === 'approve') {
            if (!$open) throw new DomainException('This submission can no longer be approved.');
            // HR confirms/corrects the official figures before they become payroll-eligible
            $amort = round((float)($_POST['monthly_amortization'] ?? 0), 2);
            $bal   = round((float)($_POST['outstanding_balance'] ?? 0), 2);
            $sched = normalizeLoanSchedule((string)($_POST['deduction_schedule'] ?? '')) ?: 'end_of_month';
            if ($amort <= 0 || $bal <= 0 || $bal > (float)$ln['original_amount']) {
                throw new DomainException('Enter a positive monthly amortization and an outstanding balance not above the original amount.');
            }
            $db->prepare("UPDATE loan_disclosures SET status='approved', monthly_amortization=?, outstanding_balance=?, deduction_schedule=?, loan_status='active',
                          next_deduction_date=?, hr_remarks=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
               ->execute([$amort, $bal, $sched, loanNextDeductionDate($sched), $remarks ?: null, $_SESSION['employee_id'], $id]);
            $msg = 'Loan approved. It is now an official employee loan and will be deducted monthly by payroll.';
        } elseif ($action === 'reject' || $action === 'needs_info') {
            if (!$open) throw new DomainException('This submission is already closed.');
            if ($remarks === '') throw new DomainException($action === 'reject' ? 'A rejection reason is required.' : 'Tell the employee what is missing.');
            $new = $action === 'reject' ? 'rejected' : 'needs_info';
            $db->prepare("UPDATE loan_disclosures SET status=?, hr_remarks=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
               ->execute([$new, $remarks, $_SESSION['employee_id'], $id]);
            $msg = $action === 'reject' ? 'Submission rejected.' : 'Employee asked for additional document/information.';
        } else {
            throw new DomainException('Unknown action.');
        }
        if (!auditLog('Loan Submission: ' . $action, 'Loans', "Submission #$id | Employee: {$ln['employee_id']} | Provider: {$ln['provider']} | Remarks: $remarks")) {
            throw new RuntimeException('Audit log write failed.');
        }
        $loanStatusMessage = match ($action) {
            'approve' => 'approved', 'reject' => 'rejected', 'needs_info' => 'returned for more information', default => 'verified'
        };
        createNotification($db, "Your loan submission was $loanStatusMessage.", 'modules/employee/loan.php', (string)$ln['employee_id']);
        $db->commit();
        $success = $msg;
    } catch (DomainException $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Loan review failed: ' . $e->getMessage());
        $error = 'The action was not saved because it could not be recorded.';
    }
}

$filter = sanitize($_GET['filter'] ?? 'open');
$where = match ($filter) {
    'all'      => '1=1',
    'approved' => "d.status='approved'",
    'closed'   => "d.status IN ('rejected','needs_info')",
    default    => "d.status IN ('for_review','verified')",
};
$rows = $db->query("SELECT d.*, CONCAT(u.first_name,' ',u.last_name) AS emp_name, u.department
                    FROM loan_disclosures d JOIN users u ON u.employee_id = d.employee_id
                    WHERE $where ORDER BY d.submitted_at DESC")->fetchAll();

include '../../includes/header.php';
?>
<div class="page-body">
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php
$lc = ['open' => 0, 'approved' => 0, 'closed' => 0, 'all' => 0];
foreach ($db->query("SELECT status, COUNT(*) c FROM loan_disclosures GROUP BY status")->fetchAll() as $cr) {
    $k = in_array($cr['status'], ['for_review','verified'], true) ? 'open' : ($cr['status'] === 'approved' ? 'approved' : 'closed');
    $lc[$k] += (int)$cr['c']; $lc['all'] += (int)$cr['c'];
}
echo renderTabBar(['open' => ['Awaiting Review', $lc['open']], 'approved' => ['Approved for Payroll', $lc['approved']],
                   'closed' => ['Rejected / Needs Info', $lc['closed']], 'all' => ['All', $lc['all']]], $filter, 'filter');
?>

<div class="card">
    <div class="card-title">Employee Loan Submissions (<?= count($rows) ?>)</div>
    <div class="table-wrapper" data-table-search="Search loans..."><table>
        <thead><tr><th>Employee</th><th>Provider / Type</th><th>Original</th><th>Balance</th><th>Monthly</th><th>Schedule</th><th>Document</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $open = in_array($r['status'], ['for_review','verified'], true); ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['emp_name']) ?></strong><div style="font-size:11px;color:#9CA3AF;"><?= htmlspecialchars($r['employee_id']) ?> · <?= htmlspecialchars_decode($r['department']) ?></div></td>
                <td><?= htmlspecialchars($r['provider']) ?><div style="font-size:11px;color:#6B7280;"><?= htmlspecialchars($r['loan_type']) ?> <?= $r['reference_no'] ? '· ' . htmlspecialchars($r['reference_no']) : '' ?></div></td>
                <td><?= formatMoney((float)$r['original_amount']) ?></td>
                <td><?= formatMoney((float)$r['outstanding_balance']) ?></td>
                <td><?= formatMoney((float)$r['monthly_amortization']) ?></td>
                <td style="font-size:12px;"><?= $r['deduction_schedule'] === '15th' ? '15th' : 'End of month' ?></td>
                <td><a href="../../api/loan_document.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener"><?= htmlspecialchars($r['doc_name'] ?? 'Open') ?></a></td>
                <td><span class="badge <?= $r['status']==='approved'?'badge-green':($r['status']==='rejected'?'badge-red':($r['status']==='verified'?'badge-blue':'badge-amber')) ?>"><?= htmlspecialchars(loanStatusLabel($r['status'])) ?></span>
                    <?php if ($r['hr_remarks']): ?><div style="font-size:11px;color:#6B7280;"><?= htmlspecialchars($r['hr_remarks']) ?></div><?php endif; ?></td>
                <td>
                <?php if ($open): ?>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <?php if ($r['status'] === 'for_review'): ?>
                        <button class="btn-secondary btn-sm" onclick="act(<?= (int)$r['id'] ?>,'verify')">Verify</button>
                        <?php endif; ?>
                        <button class="btn-secondary btn-sm" style="color:#059669;" onclick='approveLoan(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'>Approve</button>
                        <button class="btn-secondary btn-sm" onclick="act(<?= (int)$r['id'] ?>,'needs_info')">Need info</button>
                        <button class="btn-secondary btn-sm" style="color:#DC2626;" onclick="act(<?= (int)$r['id'] ?>,'reject')">Reject</button>
                    </div>
                <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows): ?><tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="2">Total</td>
            <td><?= formatMoney(array_sum(array_column($rows, 'original_amount'))) ?></td>
            <td><?= formatMoney(array_sum(array_column($rows, 'outstanding_balance'))) ?></td>
            <td><?= formatMoney(array_sum(array_column($rows, 'monthly_amortization'))) ?></td><td colspan="4"></td></tr><?php endif; ?>
        <?php if (!$rows): ?><tr><td colspan="9" style="text-align:center;color:#9CA3AF;padding:24px;">Nothing here.</td></tr><?php endif; ?>
        </tbody></table></div>
</div>
</div>

<div class="modal-overlay" id="actModal">
  <div class="modal-box" style="max-width:420px;">
    <div class="modal-header"><div class="modal-title" id="actTitle">Review</div><button class="modal-close" onclick="closeModal('actModal')">&times;</button></div>
    <form method="POST">
      <input type="hidden" name="id" id="actId"><input type="hidden" name="action" id="actAction">
      <div id="approveFields" style="display:none;">
        <div class="form-group"><label class="form-label">Official monthly amortization *</label><input type="number" step="0.01" min="0.01" name="monthly_amortization" id="a_mon" class="form-input"></div>
        <div class="form-group"><label class="form-label">Official outstanding balance *</label><input type="number" step="0.01" min="0.01" name="outstanding_balance" id="a_bal" class="form-input"></div>
        <div class="form-group"><label class="form-label">Deduct on</label><select name="deduction_schedule" id="a_sch" class="form-select"><option value="end_of_month">Last day of the month</option><option value="15th">Every 15th</option></select></div>
      </div>
      <div class="form-group"><label class="form-label" id="actRemLabel">Remarks</label><textarea name="remarks" id="actRem" class="form-textarea" rows="3"></textarea></div>
      <div style="display:flex;gap:10px;"><button class="btn-primary" type="submit">Submit</button><button type="button" class="btn-secondary" onclick="closeModal('actModal')">Cancel</button></div>
    </form>
  </div>
</div>
<script>
function act(id, action) {
    document.getElementById('actId').value = id; document.getElementById('actAction').value = action;
    document.getElementById('approveFields').style.display = 'none';
    var need = (action === 'reject' || action === 'needs_info');
    document.getElementById('actRem').required = need;
    document.getElementById('actRemLabel').textContent = action === 'reject' ? 'Rejection reason (required)' : (action === 'needs_info' ? 'What is missing? (required)' : 'Remarks (optional)');
    document.getElementById('actTitle').textContent = {verify:'Verify loan information', reject:'Reject submission', needs_info:'Request additional document/information'}[action];
    openModal('actModal');
}
function approveLoan(r) {
    act(r.id, 'approve');
    document.getElementById('approveFields').style.display = 'block';
    document.getElementById('a_mon').value = r.monthly_amortization; document.getElementById('a_bal').value = r.outstanding_balance;
    document.getElementById('a_sch').value = r.deduction_schedule;
    document.getElementById('actTitle').textContent = 'Approve for payroll deduction';
}
</script>
</body></html>
