<?php
// ============================================================
// Directpath EMPS — HR Payroll (release)
// Workflow: Payroll Manager generates + submits -> CEO approves -> HR releases (this page).
// HR cannot generate or edit payroll, and can only release payroll that a CEO-approved
// submission covers (see payrollApprovedCoverSql in includes/org_helpers.php).
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin');

$pageTitle  = 'Payroll';
$activePage = 'hr_payroll';
$db = getDB();
payrollReleaseDue($db);   // safety net: releases CEO-approved payroll HR missed, once its pay date has arrived

$success = '';
$error   = '';

// ── Release (HR only) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !csrfTokenIsValid($_POST['_csrf_token'] ?? null)) {
    http_response_code(403);
    $error = 'Your session validation failed. Refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'release_change') {
    $db->beginTransaction();
    try {
        $changeId = (int)($_POST['change_id'] ?? 0);
        $st = $db->prepare("SELECT * FROM payroll_change_requests WHERE id=? AND status='approved_pending_release' FOR UPDATE");
        $st->execute([$changeId]);
        $change = $st->fetch();
        if (!$change) throw new DomainException('This change is not approved for release.');
        $payload = json_decode((string)$change['payload'], true, 512, JSON_THROW_ON_ERROR);
        $before = $payload['before'] ?? null;
        $after = $payload['after'] ?? null;
        if (!is_array($after)) throw new DomainException('The approved change data is invalid.');
        $employeeId = (string)$change['employee_id'];
        if ($change['change_type'] === 'compensation') {
            $st = $db->prepare('SELECT basic_salary, allowances FROM users WHERE employee_id=? FOR UPDATE');
            $st->execute([$employeeId]);
            $current = $st->fetch();
            if (!$current || !is_array($before) || number_format((float)$current['basic_salary'], 2, '.', '') !== number_format((float)($before['basic_salary'] ?? -1), 2, '.', '') || number_format((float)$current['allowances'], 2, '.', '') !== number_format((float)($before['allowances'] ?? -1), 2, '.', '')) {
                throw new DomainException('The employee compensation changed after approval was requested. Ask the Payroll Manager to submit a new request.');
            }
            $db->prepare('UPDATE users SET basic_salary=?, allowances=? WHERE employee_id=?')
               ->execute([$after['basic_salary'], $after['allowances'], $employeeId]);
            $releasedMessage = 'Your updated compensation is now effective.';
        } elseif ($change['change_type'] === 'ytd') {
            $year = (int)$change['reference_year'];
            $st = $db->prepare('SELECT taxable_income, non_taxable_income, sss, philhealth, hdmf, withholding_tax FROM payroll_previous_employer_ytd WHERE employee_id=? AND tax_year=? FOR UPDATE');
            $st->execute([$employeeId, $year]);
            $current = $st->fetch() ?: null;
            $same = $current === null && $before === null;
            if ($current !== null && is_array($before)) {
                $same = true;
                foreach (['taxable_income','non_taxable_income','sss','philhealth','hdmf','withholding_tax'] as $field) {
                    if (number_format((float)$current[$field], 2, '.', '') !== number_format((float)($before[$field] ?? -1), 2, '.', '')) $same = false;
                }
            }
            if (!$same) throw new DomainException('The YTD record changed after approval was requested. Ask the Payroll Manager to submit a new request.');
            $db->prepare("INSERT INTO payroll_previous_employer_ytd (employee_id, tax_year, taxable_income, non_taxable_income, sss, philhealth, hdmf, withholding_tax) VALUES (?,?,?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE taxable_income=VALUES(taxable_income), non_taxable_income=VALUES(non_taxable_income), sss=VALUES(sss), philhealth=VALUES(philhealth), hdmf=VALUES(hdmf), withholding_tax=VALUES(withholding_tax)")
               ->execute([$employeeId, $year, $after['taxable_income'], $after['non_taxable_income'], $after['sss'], $after['philhealth'], $after['hdmf'], $after['withholding_tax']]);
            $releasedMessage = 'Your previous employer YTD information is now updated.';
        } else {
            throw new DomainException('Unknown payroll change type.');
        }
        $db->prepare("UPDATE payroll_change_requests SET status='released', released_by=?, released_by_name=?, released_at=NOW() WHERE id=? AND status='approved_pending_release'")
           ->execute([$_SESSION['employee_id'], $_SESSION['name'], $changeId]);
        if (!auditLog('Release Payroll Change (HR)', 'Payroll', "Change request #$changeId | Type: {$change['change_type']} | Employee: $employeeId")) throw new RuntimeException('Audit record could not be saved.');
        createNotification($db, $releasedMessage, 'modules/employee/payroll.php', $employeeId);
        if (!empty($change['submitted_by'])) createNotification($db, "Payroll change request #$changeId has been released by HR.", 'modules/admin/payroll.php', (string)$change['submitted_by']);
        $db->commit();
        $success = 'Approved payroll change released for ' . $employeeId . '.';
    } catch (DomainException $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('HR payroll change release failed: ' . $e->getMessage());
        $error = 'The approved change was not released because it could not be recorded.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['release_period', 'release_employee'], true)) {
    $db->beginTransaction();
    try {
        $pid = null;
        if ($_POST['action'] === 'release_employee') {
            $pid = (int)($_POST['payroll_id'] ?? 0);
            $row = $db->prepare("SELECT payroll_period_start s, payroll_period_end e FROM payroll WHERE id = ? AND status = 'generated'");
            $row->execute([$pid]);
            $per = $row->fetch();
            if (!$per) throw new DomainException('Payroll record not found or already released.');
        } else {
            $per = ['s' => sanitize($_POST['period_start'] ?? ''), 'e' => sanitize($_POST['period_end'] ?? '')];
        }
        if ($per['e'] > date('Y-m-d')) {
            throw new DomainException('This payroll period is still ongoing. It can be released after its cutoff date (' . formatDate($per['e']) . ').');
        }
        $released = payrollReleaseApproved($db, $per['s'], $per['e'], $pid, (string)$_SESSION['name']);
        $who = payrollEmployeeLabel(array_map(static fn($r) => [
            'employee_id' => $r['employee_id'],
            'employee_name' => getFullName((string)$r['first_name'], $r['middle_name'] ?? null, (string)$r['last_name']),
        ], $released), 5);
        if (!$released) throw new DomainException('Nothing was released. The payroll may have changed after the CEO approved it; ask the Payroll Manager to submit it again.');
        if (!auditLog('Release Payroll (HR)', 'Payroll', 'Released ' . count($released) . " payroll record(s) | Period: {$per['s']} to {$per['e']} | Employee(s): $who")) {
            throw new RuntimeException('Audit log write failed.');
        }
        $db->commit();
        $success = 'Payroll released for ' . $who . '. Payslips become visible to employees on the pay date.';
    } catch (DomainException $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('HR payroll release failed: ' . $e->getMessage());
        $error = 'Payroll was not released because the audit record could not be written.';
    }
}

// ── Periods: who is in each payroll + CEO approval ────────
$periods = $db->query("SELECT payroll_period_start s, payroll_period_end e, COUNT(*) n, SUM(status='generated') gen, SUM(status='released') rel, SUM(net_salary) net
                       FROM payroll WHERE status IN ('generated','released')
                       GROUP BY payroll_period_start, payroll_period_end ORDER BY payroll_period_start DESC LIMIT 12")->fetchAll();
foreach ($periods as &$per) {
    $per['employer'] = payrollPeriodTotals($db, $per['s'], $per['e'])['employer'];
    $per['apr']  = getPayrollApproval($db, $per['s'], $per['e']);
    $per['ready'] = payrollReleasableRows($db, $per['s'], $per['e']);   // approved by CEO, not released yet
    $per['wait']  = payrollUncoveredRows($db, $per['s'], $per['e']);    // generated, no CEO approval covering it
    $emps = $db->prepare("SELECT p.employee_id, CONCAT(u.first_name,' ',u.last_name) AS employee_name FROM payroll p
                          JOIN users u ON u.employee_id = p.employee_id
                          WHERE p.payroll_period_start = ? AND p.payroll_period_end = ? AND p.status IN ('generated','released')
                          ORDER BY u.last_name, u.first_name");
    $emps->execute([$per['s'], $per['e']]);
    $per['emps'] = $emps->fetchAll();
} unset($per);

// ── Payroll records (read-only for HR) ────────────────────
$filterEmp    = sanitize($_GET['emp'] ?? '');
$filterStatus = sanitize($_GET['status'] ?? '');
$filterMonth  = sanitize($_GET['month'] ?? '');
$cover  = payrollApprovedCoverSql($db);
$where  = "WHERE p.status IN ('generated','released')";
$params = [];
if ($filterEmp !== '')    { $where .= ' AND p.employee_id = ?'; $params[] = $filterEmp; }
if (in_array($filterStatus, ['generated', 'released'], true)) { $where .= ' AND p.status = ?'; $params[] = $filterStatus; }
if (preg_match('/^\d{4}-\d{2}$/', $filterMonth)) { $where .= " AND DATE_FORMAT(p.payroll_period_start, '%Y-%m') = ?"; $params[] = $filterMonth; }
$st = $db->prepare("SELECT p.*, CONCAT(u.first_name,' ',u.last_name) AS name, u.department, ($cover) AS covered
                    FROM payroll p JOIN users u ON u.employee_id = p.employee_id
                    $where ORDER BY p.payroll_period_start DESC, u.last_name, u.first_name LIMIT 300");
$st->execute($params);
$payrolls = $st->fetchAll();
foreach ($payrolls as &$payrollRow) {
    $payrollRow['employer_shares'] = payrollEmployerShares($payrollRow);
}
unset($payrollRow);
$employees = $db->query("SELECT DISTINCT u.employee_id, CONCAT(u.first_name,' ',u.last_name) AS name
                         FROM users u JOIN payroll p ON p.employee_id = u.employee_id ORDER BY name")->fetchAll();
$approvedChanges = $db->query("SELECT pcr.*, CONCAT(u.first_name,' ',u.last_name) AS employee_name FROM payroll_change_requests pcr JOIN users u ON u.employee_id=pcr.employee_id WHERE pcr.status='approved_pending_release' ORDER BY pcr.reviewed_at ASC")->fetchAll();

include '../../includes/header.php';
?>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
    <div class="card-title">CEO Approved Payroll Changes Waiting for Release</div>
    <div class="table-wrapper"><table>
        <thead><tr><th>Employee</th><th>Change</th><th>Approved values</th><th>Reason</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($approvedChanges as $change): $payload = json_decode((string)$change['payload'], true) ?: []; $after = $payload['after'] ?? []; ?>
        <tr>
            <td><?= htmlspecialchars($change['employee_name']) ?><br><small><?= htmlspecialchars($change['employee_id']) ?></small></td>
            <td><?= $change['change_type'] === 'ytd' ? 'Previous employer YTD · ' . (int)$change['reference_year'] : 'Compensation' ?></td>
            <td style="font-size:12px;"><?php foreach ($after as $field => $value): ?><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$field))) ?>: <?= htmlspecialchars((string)$value) ?><br><?php endforeach; ?></td>
            <td><?= htmlspecialchars((string)($payload['reason'] ?? '')) ?></td>
            <td><form method="POST">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="change_id" value="<?= (int)$change['id'] ?>">
                <button class="btn-primary btn-sm" name="action" value="release_change" onclick="return confirm('Release this approved change for <?= htmlspecialchars(addslashes($change['employee_id']), ENT_QUOTES, 'UTF-8') ?>?')">Release Change</button>
            </form></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$approvedChanges): ?><tr><td colspan="5" style="text-align:center;color:#9CA3AF;padding:18px;">No approved payroll changes waiting for release.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<!-- Release by period -->
<div class="card">
    <div class="card-title">Release Payroll <span class="muted-note">Payroll Manager generates · CEO approves · HR releases</span></div>
    <div class="table-wrapper" data-table-search="Search payroll periods..."><table>
        <thead><tr><th>Period</th><th>Employee(s)</th><th>Cut-off / Pay date</th><th>Records</th><th>Net total</th><th>Employer shares</th><th>CEO approval</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($periods as $per): $ci = payCutoffInfo($per['s'], $per['e']); $a = $per['apr'];
              $ongoing = $per['e'] > date('Y-m-d'); ?>
        <tr>
            <td><?= formatDate($per['s']) ?> – <?= formatDate($per['e']) ?></td>
            <td style="font-size:12px;max-width:260px;"><?= htmlspecialchars(payrollEmployeeLabel($per['emps'], 3)) ?></td>
            <td style="font-size:12px;"><?= $ci['cutoff'] ?> · pay date <?= formatDate($ci['pay_date']) ?></td>
            <td><?= (int)$per['n'] ?> (<?= (int)$per['gen'] ?> generated, <?= (int)$per['rel'] ?> released)</td>
            <td><?= formatMoney((float)$per['net']) ?></td>
            <td><?= formatMoney((float)$per['employer']) ?></td>
            <td>
                <?php if (!$a): ?><span class="badge badge-gray">Not submitted</span>
                <?php elseif ($a['status'] === 'pending_ceo'): ?><span class="badge badge-amber">Pending CEO review</span>
                <?php elseif ($a['status'] === 'approved'): ?><span class="badge badge-green">Approved</span> <span style="font-size:11px;color:#6B7280;"><?= htmlspecialchars($a['reviewed_by_name'] ?? '') ?></span>
                <?php else: ?><span class="badge badge-red">Rejected</span><div style="font-size:11px;color:#991B1B;max-width:240px;"><?= htmlspecialchars($a['reject_reason'] ?? '') ?></div><?php endif; ?>
                <?php if ($per['wait'] && $a && $a['status'] === 'approved'): ?>
                    <div style="font-size:11px;color:#92400E;"><?= count($per['wait']) ?> record(s) not covered by the approval: <?= htmlspecialchars(payrollEmployeeLabel(array_map(static fn($r) => ['employee_id' => $r['employee_id'], 'employee_name' => trim($r['first_name'] . ' ' . $r['last_name'])], $per['wait']), 2)) ?></div>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($per['ready']): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="period_start" value="<?= htmlspecialchars($per['s']) ?>">
                        <input type="hidden" name="period_end" value="<?= htmlspecialchars($per['e']) ?>">
                        <?php if ($ongoing): ?>
                            <span class="muted-note">Releasable after <?= formatDate($per['e']) ?></span>
                        <?php else: ?>
                            <button class="btn-primary btn-sm" name="action" value="release_period"
                                    onclick="return confirm(<?= htmlspecialchars(json_encode('Release payroll for ' . payrollEmployeeLabel(array_map(static fn($r) => ['employee_id' => $r['employee_id'], 'employee_name' => trim($r['first_name'] . ' ' . $r['last_name'])], $per['ready']), 3) . '?', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">Release (<?= count($per['ready']) ?>)</button>
                        <?php endif; ?>
                    </form>
                <?php elseif ((int)$per['gen'] > 0): ?>
                    <span class="muted-note">Waiting for CEO approval</span>
                <?php else: ?>
                    <span class="muted-note">All released</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if ($periods): ?><tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="4">Total</td><td><?= formatMoney(array_sum(array_map(static fn($per) => (float)$per['net'], $periods))) ?></td><td><?= formatMoney(array_sum(array_map(static fn($per) => (float)$per['employer'], $periods))) ?></td><td colspan="2"></td></tr><?php endif; ?>
        <?php if (!$periods): ?><tr><td colspan="8" style="text-align:center;color:#9CA3AF;">No payroll has been generated yet.</td></tr><?php endif; ?>
        </tbody></table></div>
</div>

<!-- Payroll records -->
<div class="card">
    <form method="GET" class="filter-bar" style="margin:0 0 12px;">
        <select name="emp" class="form-select" style="width:auto;">
            <option value="">All Employees</option>
            <?php foreach ($employees as $e): ?>
            <option value="<?= htmlspecialchars($e['employee_id']) ?>" <?= $filterEmp === $e['employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="form-select" style="width:auto;">
            <option value="">All Status</option>
            <option value="generated" <?= $filterStatus === 'generated' ? 'selected' : '' ?>>Generated</option>
            <option value="released"  <?= $filterStatus === 'released'  ? 'selected' : '' ?>>Released</option>
        </select>
        <input type="month" name="month" class="form-input" style="width:auto;" value="<?= htmlspecialchars($filterMonth) ?>">
        <button type="submit" class="btn-primary">Filter</button>
        <a href="hr_payroll.php" class="btn-secondary">Reset</a>
    </form>
    <div class="card-title">Payroll Records (<?= count($payrolls) ?>)</div>
    <div class="table-wrapper" data-table-search="Search payroll records..."><table>
        <thead><tr><th>Employee</th><th>Period</th><th>Gross Pay</th><th>Total Ded.</th><th>Net Pay</th><th>Employer Share</th><th>Status</th><th>CEO approval</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($payrolls as $p):
              $ongoing = $p['payroll_period_end'] > date('Y-m-d'); ?>
        <tr>
            <td>
                <div style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></div>
                <div style="font-size:11px;color:#9CA3AF;"><?= htmlspecialchars($p['employee_id']) ?> · <?= htmlspecialchars((string)$p['department']) ?></div>
            </td>
            <td style="font-size:12px;"><?= formatDate($p['payroll_period_start']) ?><br><span style="color:#9CA3AF;">to <?= formatDate($p['payroll_period_end']) ?></span></td>
            <td><?= formatMoney((float)$p['gross_pay'] + (float)($p['bonuses'] ?? 0)) ?></td>
            <td style="color:#CD1E27;"><?= formatMoney((float)$p['total_deductions']) ?></td>
            <td style="font-weight:700;color:#16A34A;"><?= formatMoney((float)$p['net_salary']) ?></td>
            <td><?= formatMoney($p['employer_shares']['total']) ?></td>
            <td><span class="badge <?= statusBadge((string)$p['status']) ?>"><?= htmlspecialchars(ucfirst((string)$p['status'])) ?></span>
                <?php if ($p['status'] === 'released' && !empty($p['released_by_name'])): ?><div style="font-size:11px;color:#6B7280;">by <?= htmlspecialchars($p['released_by_name']) ?></div><?php endif; ?></td>
            <td style="font-size:12px;">
                <?= $p['status'] === 'released' ? '<span class="badge badge-green">Approved</span>'
                    : ((int)$p['covered'] === 1 ? '<span class="badge badge-green">Approved</span>' : '<span class="badge badge-amber">Not approved</span>') ?>
            </td>
            <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <a href="payslip.php?id=<?= (int)$p['id'] ?>" target="_blank" class="btn-secondary btn-sm">Payslip</a>
                    <?php if ($p['status'] === 'generated' && (int)$p['covered'] === 1 && !$ongoing): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="payroll_id" value="<?= (int)$p['id'] ?>">
                        <button class="btn-primary btn-sm" name="action" value="release_employee"
                                onclick="return confirm(<?= htmlspecialchars(json_encode('Release the payroll of ' . $p['name'] . ' (' . $p['employee_id'] . ')?', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">Release</button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if ($payrolls): ?><tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="2">Total</td>
            <td><?= formatMoney(array_sum(array_map(static fn($p) => (float)$p['gross_pay'] + (float)($p['bonuses'] ?? 0), $payrolls))) ?></td>
            <td><?= formatMoney(array_sum(array_column($payrolls, 'total_deductions'))) ?></td>
            <td><?= formatMoney(array_sum(array_column($payrolls, 'net_salary'))) ?></td>
            <td><?= formatMoney(array_sum(array_map(static fn($p) => $p['employer_shares']['total'], $payrolls))) ?></td><td colspan="3"></td></tr><?php endif; ?>
        <?php if (!$payrolls): ?><tr><td colspan="9" style="text-align:center;color:#9CA3AF;padding:24px;">No payroll records found.</td></tr><?php endif; ?>
        </tbody></table></div>
</div>

</div><!-- page-body -->
