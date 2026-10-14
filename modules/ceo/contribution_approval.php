<?php
// ============================================================
// CEO — Government Contribution Schedule Approval
// HR adds or edits a schedule -> CEO approves or rejects -> HR activates it.
// A rejected schedule can never be activated; the CEO's reason is shown to HR and Payroll.
// Allowed CEO POST (see enforceCeoReadOnly in includes/auth.php).
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('ceo');

$pageTitle  = 'Contribution Approval';
$activePage = 'contribution_approval';
$db = getDB();
$me = $_SESSION['employee_id'];
$success = $error = '';
if (isset($_GET['notification_id'])) {
    $db->prepare("UPDATE government_contribution_notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND recipient_role='ceo'")
       ->execute([(int)$_GET['notification_id']]);
}

function ceoContributionRules(PDO $db, array $s): array {
    $table = $s['agency'] === 'SSS' ? 'sss_contribution_table' : 'government_contribution_rules';
    $st = $db->prepare("SELECT * FROM $table WHERE schedule_id = ? ORDER BY salary_min, id");
    $st->execute([$s['id']]);
    return $st->fetchAll();
}

$ready = false;
try {
    $c = $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
                     AND table_name = 'government_contribution_schedules' AND column_name IN ('approval_status','reject_reason','reviewed_at')");
    $n = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='government_contribution_notifications'");
    $ready = (int)$c->fetchColumn() === 3 && (int)$n->fetchColumn() === 1;
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrfTokenIsValid($_POST['_csrf_token'] ?? null)) {
    http_response_code(403);
    $error = 'Your session validation failed. Refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    $id     = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $reason = trim(strip_tags((string)($_POST['reason'] ?? '')));
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT * FROM government_contribution_schedules WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $s = $st->fetch();
        if (!$s) throw new DomainException('Contribution schedule not found.');
        if ($s['approval_status'] !== 'pending_ceo') throw new DomainException('This schedule was already decided.');
        if ($s['created_by'] === $me) throw new DomainException('You cannot approve or reject a schedule that you added yourself.');
        if (!in_array($action, ['approve', 'reject'], true)) throw new DomainException('Unknown action.');
        if ($action === 'reject' && $reason === '') throw new DomainException('A rejection reason is required.');
        if (mb_strlen($reason) > 1000) throw new DomainException('The reason must be 1000 characters or fewer.');

        $new = $action === 'approve' ? 'approved' : 'rejected';
        $db->prepare("UPDATE government_contribution_schedules
                      SET approval_status=?, reviewed_by=?, reviewed_by_name=?, reviewed_at=NOW(), reject_reason=?
                      WHERE id=? AND approval_status='pending_ceo'")
           ->execute([$new, $me, $_SESSION['name'], $action === 'reject' ? $reason : null, $id]);
        if (!auditLog($action === 'approve' ? 'CEO Approved Contribution Schedule' : 'CEO Rejected Contribution Schedule',
                      'Government Contributions',
                      json_encode(['schedule_id' => $id, 'agency' => $s['agency'], 'schedule_name' => $s['schedule_name'],
                                   'effective_from' => $s['effective_from'], 'decision' => $new, 'reason' => $action === 'reject' ? $reason : null],
                                  JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Audit log write failed.');
        }
        $decisionMessage = $action === 'approve'
            ? 'CEO approved contribution schedule: ' . $s['schedule_name'] . ' (' . $s['agency'] . '). HR can activate it.'
            : 'CEO rejected contribution schedule: ' . $s['schedule_name'] . ' (' . $s['agency'] . '). Review the rejection reason.';
        foreach (['hr_admin', 'payroll_manager'] as $recipientRole) {
            $db->prepare('INSERT INTO government_contribution_notifications (schedule_id, recipient_role, message) VALUES (?,?,?)')
               ->execute([$id, $recipientRole, $decisionMessage]);
        }
        $db->commit();
        $success = $action === 'approve'
            ? 'Schedule approved. HR has been notified to activate it.'
            : 'Schedule rejected. HR and Payroll have been notified and can see your reason.';
    } catch (DomainException $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('CEO contribution decision failed: ' . $e->getMessage());
        $error = 'The decision was not saved because it could not be recorded.';
    }
}

$rows = [];
if ($ready) {
    $rows = $db->query("
        SELECT s.*, CONCAT_WS(' ', u.first_name, u.last_name) AS creator_name
        FROM government_contribution_schedules s
        LEFT JOIN users u ON u.employee_id = s.created_by
        ORDER BY (s.approval_status='pending_ceo') DESC, COALESCE(s.reviewed_at, s.submitted_at, s.created_at) DESC
        LIMIT 40
    ")->fetchAll();
}
include '../../includes/header.php';
?>
<div class="page-body">
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if (!$ready): ?><div class="alert alert-error">Apply <code>config/government_contributions_approval_migration.sql</code> to enable contribution schedule approval and notifications.</div><?php endif; ?>
<?php if ($ready && !$rows): ?><div class="card"><div style="color:#9CA3AF;text-align:center;padding:24px;">No contribution schedules have been submitted yet.</div></div><?php endif; ?>

<?php foreach ($rows as $s):
    $ap = $s['approval_status']; $pending = $ap === 'pending_ceo'; $rules = ceoContributionRules($db, $s); $sss = $s['agency'] === 'SSS'; ?>
<div class="card" style="<?= $pending ? 'border-left:4px solid #D97706;' : '' ?>">
    <div class="card-title">
        <span><?= htmlspecialchars($s['agency']) ?> — <?= htmlspecialchars($s['schedule_name']) ?></span>
        <span class="badge <?= $ap==='approved'?'badge-green':($ap==='rejected'?'badge-red':'badge-amber') ?>">
            <?= $pending ? 'Pending CEO review' : ($ap === 'approved' ? ($s['status']==='active' ? 'Approved · Active' : 'Approved · Waiting for HR') : 'Rejected') ?></span>
    </div>
    <div style="font-size:13px;color:#374151;margin-bottom:8px;">
        Effective <strong><?= formatDate($s['effective_from']) ?></strong><?= $s['effective_until'] ? ' to <strong>' . formatDate($s['effective_until']) . '</strong>' : ' onward' ?>
        · Source: <?= htmlspecialchars($s['source_reference'] ?: 'Not provided') ?>
        <?php if (!empty($s['source_url'])): ?> · <a href="<?= htmlspecialchars($s['source_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Official source</a><?php endif; ?>
    </div>
    <details <?= $pending ? 'open' : '' ?>>
        <summary style="cursor:pointer;font-size:12px;color:#2563EB;">Contribution rules (<?= count($rules) ?>)</summary>
        <div class="table-wrapper" style="margin-top:8px;"><table>
            <thead><?php if ($sss): ?>
                <tr><th>Min</th><th>Max</th><th>MSC</th><th>SS Employee</th><th>SS Employer</th><th>MPF Employee</th><th>MPF Employer</th><th>ECP</th></tr>
            <?php else: ?>
                <tr><th>Min</th><th>Max</th><th>Basis</th><th>Min Basis</th><th>Max Basis</th><th>Employee Rate</th><th>Employer Rate</th><th>Employee Fixed</th><th>Employer Fixed</th></tr>
            <?php endif; ?></thead>
            <tbody>
            <?php foreach ($rules as $r): ?><tr>
                <td><?= formatMoney((float)$r['salary_min']) ?></td>
                <td><?= $r['salary_max'] === null ? 'No limit' : formatMoney((float)$r['salary_max']) ?></td>
                <?php if ($sss): ?>
                    <td><?= formatMoney((float)$r['msc']) ?></td><td><?= formatMoney((float)$r['regular_ss_employee']) ?></td><td><?= formatMoney((float)$r['regular_ss_employer']) ?></td>
                    <td><?= formatMoney((float)$r['mpf_employee']) ?></td><td><?= formatMoney((float)$r['mpf_employer']) ?></td><td><?= formatMoney((float)$r['ec_employer']) ?></td>
                <?php else: ?>
                    <td><?= htmlspecialchars(str_replace('_', ' ', ucfirst($r['contribution_base_mode']))) ?><?= $r['contribution_base'] !== null ? ': ' . formatMoney((float)$r['contribution_base']) : '' ?></td>
                    <td><?= $r['contribution_base_floor'] === null ? '—' : formatMoney((float)$r['contribution_base_floor']) ?></td>
                    <td><?= $r['contribution_base_cap'] === null ? '—' : formatMoney((float)$r['contribution_base_cap']) ?></td>
                    <td><?= htmlspecialchars($r['employee_rate']) ?>%</td><td><?= htmlspecialchars($r['employer_rate']) ?>%</td>
                    <td><?= formatMoney((float)$r['employee_fixed_amount']) ?></td><td><?= formatMoney((float)$r['employer_fixed_amount']) ?></td>
                <?php endif; ?>
            </tr><?php endforeach; ?>
            </tbody></table></div>
    </details>
    <div style="font-size:12px;color:#6B7280;margin-top:8px;">Added by <strong><?= htmlspecialchars(trim((string)$s['creator_name']) ?: $s['created_by']) ?></strong><?= $s['submitted_at'] ? ' on ' . date('M j, Y g:i A', strtotime($s['submitted_at'])) : '' ?>
        <?php if ($s['reviewed_at']): ?> · Decided by <strong><?= htmlspecialchars((string)$s['reviewed_by_name']) ?></strong> on <?= date('M j, Y g:i A', strtotime($s['reviewed_at'])) ?><?php endif; ?></div>
    <?php if ($ap === 'rejected'): ?><div style="margin-top:6px;color:#991B1B;font-size:12px;">Rejection reason: <?= htmlspecialchars((string)$s['reject_reason']) ?></div><?php endif; ?>
    <?php if ($pending): ?>
        <?php if ($s['created_by'] === $me): ?>
            <div class="alert alert-error" style="margin-top:10px;">You added this schedule, so another approver must decide it.</div>
        <?php else: ?>
        <form method="POST" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <textarea name="reason" class="form-textarea" rows="2" maxlength="1000" style="flex:1;min-width:240px;" placeholder="Reason (required only when rejecting)"></textarea>
            <button class="btn-primary" name="action" value="approve" style="background:#059669;" onclick="return confirm('Approve this contribution schedule? HR will be notified to activate it.')">Approve</button>
            <button class="btn-primary" name="action" value="reject" style="background:#DC2626;" onclick="if(!this.form.reason.value.trim()){alert('Please enter the rejection reason.');return false;}return confirm('Reject this schedule? It will not be usable for payroll.')">Reject</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
