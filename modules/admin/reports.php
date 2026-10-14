<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin', 'payroll_manager', 'ceo');

$pageTitle  = 'Reports';
$activePage = 'reports';
$db = getDB();

$month = sanitize($_GET['month'] ?? date('Y-m'));
$role = $_SESSION['role'];
$canSeePayroll = in_array($role, ['payroll_manager', 'ceo'], true);
$isCeo = $role === 'ceo';

// HR Admin never queries payroll data; the CEO receives totals only.
$payrollRows = [];
if ($canSeePayroll) {
    $payrollSql = $isCeo ? "
        SELECT 'Company Total' AS employee_name, 'All Departments' AS department,
               COALESCE(SUM(p.gross_pay + COALESCE(p.bonuses, 0)), 0) AS gross_pay,
               COALESCE(SUM(p.total_deductions), 0) AS total_deductions,
               COALESCE(SUM(p.net_salary), 0) AS net_salary,
               COALESCE(SUM(p.loan_deduction), 0) AS loan_deduction,
               COALESCE(SUM(p.sss), 0) AS sss,
               COALESCE(SUM(p.philhealth), 0) AS philhealth,
               COALESCE(SUM(p.pagibig), 0) AS pagibig,
               COALESCE(SUM(p.withholding_tax), 0) AS withholding_tax,
               'summary' AS status
        FROM payroll p
        WHERE DATE_FORMAT(p.payroll_period_start,'%Y-%m') = ?
    " : "
    SELECT p.*, CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.department,
        (p.gross_pay + COALESCE(p.bonuses, 0)) AS gross_pay
    FROM payroll p
    JOIN users e ON p.employee_id = e.employee_id
    WHERE DATE_FORMAT(p.payroll_period_start,'%Y-%m') = ?
    ORDER BY employee_name
    ";
    $payrollSummary = $db->prepare($payrollSql);
    $payrollSummary->execute([$month]);
    $payrollRows = $payrollSummary->fetchAll();
    foreach ($payrollRows as &$payrollRow) {
        $shares = payrollEmployerShares($payrollRow);
        $payrollRow['employer_total'] = $shares['total'];
    }
    unset($payrollRow);
    if ($isCeo && $payrollRows) {
        $shareRows = $db->prepare("SELECT * FROM payroll WHERE DATE_FORMAT(payroll_period_start,'%Y-%m') = ?");
        $shareRows->execute([$month]);
        $companyShares = ['sss'=>0.0,'ec'=>0.0,'philhealth'=>0.0,'pagibig'=>0.0,'total'=>0.0];
        foreach ($shareRows->fetchAll() as $shareRow) foreach (payrollEmployerShares($shareRow) as $key => $value) $companyShares[$key] += $value;
        $payrollRows[0]['employer_shares'] = $companyShares;
        $payrollRows[0]['employer_total'] = $companyShares['total'];
    }
}

$employerContributionRows = [];
$employerPayrollCost = 0.0;
$contributionSchemaReady = false;
if ($role === 'payroll_manager') {
    $requiredColumns = ['sss_employer', 'sss_ec_employer', 'philhealth_employer', 'pagibig_employer'];
    $columnQuery = $db->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll'
          AND COLUMN_NAME IN (?,?,?,?)
    ");
    $columnQuery->execute($requiredColumns);
    $contributionSchemaReady = count($columnQuery->fetchAll(PDO::FETCH_COLUMN)) === count($requiredColumns);

    if ($contributionSchemaReady) {
        $employerStmt = $db->prepare("
            SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                   p.*, (p.gross_pay + COALESCE(p.bonuses, 0)) AS gross_pay
            FROM payroll p
            JOIN users e ON e.employee_id = p.employee_id
            WHERE DATE_FORMAT(p.payroll_period_start, '%Y-%m') = ?
            ORDER BY employee_name, p.payroll_period_start
        ");
        $employerStmt->execute([$month]);
        $employerContributionRows = $employerStmt->fetchAll();
        foreach ($employerContributionRows as &$row) {
            $shares = payrollEmployerShares($row);
            $row['sss_employer'] = $shares['sss']; $row['sss_ec_employer'] = $shares['ec'];
            $row['philhealth_employer'] = $shares['philhealth']; $row['pagibig_employer'] = $shares['pagibig'];
            $row['employer_payroll_cost'] = (float)$row['gross_pay'] + $shares['total'];
        }
        unset($row);
        $employerPayrollCost = array_sum(array_column($employerContributionRows, 'employer_payroll_cost'));
    }
}

$totalGross = array_sum(array_column($payrollRows, 'gross_pay'));
$totalNet   = array_sum(array_column($payrollRows, 'net_salary'));
$totalDed   = array_sum(array_column($payrollRows, 'total_deductions'));

// The CEO sees attendance totals without employee-level rows.
$attSql = $isCeo ? "
    SELECT 'Company Summary' AS employee_name,
        COUNT(CASE WHEN a.status IN ('present','late') THEN 1 END) AS present_days,
        COUNT(CASE WHEN a.status = 'absent' THEN 1 END) AS absent_days,
        COUNT(CASE WHEN a.status = 'on-leave' THEN 1 END) AS leave_days,
        COALESCE(SUM(a.late_minutes), 0) AS total_late,
        COALESCE(SUM(a.undertime_mins), 0) AS total_undertime
    FROM attendance a
    WHERE DATE_FORMAT(a.date,'%Y-%m') = ?
" : "
    SELECT 
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
        COUNT(CASE WHEN a.status IN ('present','late') THEN 1 END) AS present_days,
        COUNT(CASE WHEN a.status = 'absent' THEN 1 END) AS absent_days,
        COUNT(CASE WHEN a.status = 'on-leave' THEN 1 END) AS leave_days,
        COALESCE(SUM(a.late_minutes), 0) AS total_late,
        COALESCE(SUM(a.undertime_mins), 0) AS total_undertime
    FROM attendance a
    JOIN users e ON a.employee_id = e.employee_id
    WHERE DATE_FORMAT(a.date,'%Y-%m') = ?
    GROUP BY e.employee_id, employee_name
    ORDER BY employee_name
    ";
$attSummary = $db->prepare($attSql);
$attSummary->execute([$month]);
$attRows = $attSummary->fetchAll();

// The CEO sees company-wide leave totals; HR Admin retains employee-level detail.
$aggregateLeave = in_array($role, ['payroll_manager', 'ceo'], true);
$leaveSql = $aggregateLeave ? "
    SELECT 'Company Summary' AS employee_name, lr.leave_type,
           COUNT(*) AS count, SUM(lr.total_days) AS total_days, lr.status
    FROM leave_requests lr
    WHERE DATE_FORMAT(lr.start_date,'%Y-%m') = ?
    GROUP BY lr.leave_type, lr.status
    ORDER BY lr.leave_type, lr.status
" : "
    SELECT 
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
        lr.leave_type,
        COUNT(*) AS count,
        SUM(lr.total_days) AS total_days,
        lr.status
    FROM leave_requests lr
    JOIN users e ON lr.employee_id = e.employee_id
    WHERE DATE_FORMAT(lr.start_date,'%Y-%m') = ?
    GROUP BY e.employee_id, employee_name, lr.leave_type, lr.status
    ORDER BY employee_name
    ";
$leaveSummary = $db->prepare($leaveSql);
$leaveSummary->execute([$month]);
$leaveRows = $leaveSummary->fetchAll();

$loanSummary = null;
$monthlyLoanDeductions = 0.0;
if ($canSeePayroll) {
    $loanSummary = $db->query("SELECT COUNT(CASE WHEN (status='approved' AND loan_status='active') THEN 1 END) AS active_loans, COALESCE(SUM(CASE WHEN (status='approved' AND loan_status='active') THEN outstanding_balance ELSE 0 END),0) AS outstanding_balance FROM loan_disclosures")->fetch();
    $deductionStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM loan_disclosure_payments WHERE DATE_FORMAT(payment_date,'%Y-%m')=?");
    $deductionStmt->execute([$month]);
    $monthlyLoanDeductions = (float)$deductionStmt->fetchColumn();
}

include '../../includes/header.php';
?>

<div class="page-body">

<!-- Month Filter -->
<div class="card no-print">
    <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <label class="form-label" style="margin:0;">Report Month:</label>
        <input type="month" name="month" class="form-input" style="width:auto;" value="<?= $month ?>">
        <button type="submit" class="btn-primary">Load Report</button>
        <button type="button" class="btn-secondary" onclick="window.print()">Print</button>
    </form>
</div>

<div class="a4-stage rpt-fluid"><div class="a4-sheet">
<div class="rpt-head">
    <div><div class="nm">Directpath Canada Consultation Inc.</div><div class="ttl">HR and Payroll Report</div></div>
    <div class="meta">Period: <b><?= date('F Y', strtotime($month . '-01')) ?></b><br>Generated: <?= date('F j, Y g:i A') ?></div>
</div>
<?php if ($canSeePayroll): ?>
<!-- Payroll Summary -->
<div class="card">
    <div class="card-title">Payroll Summary — <?= date('F Y', strtotime($month . '-01')) ?></div>
    <div class="row g-3 mb-3">
        <div class="col-4">
            <div style="background:#F0FDF4; border-radius:8px; padding:14px; text-align:center;">
                <div style="font-size:20px; font-weight:700; color:#16A34A;"><?= formatMoney($totalGross) ?></div>
                <div style="font-size:12px; color:#6B7280;">Total Gross Pay</div>
            </div>
        </div>
        <div class="col-4">
            <div style="background:#FEE2E2; border-radius:8px; padding:14px; text-align:center;">
                <div style="font-size:20px; font-weight:700; color:#CD1E27;"><?= formatMoney($totalDed) ?></div>
                <div style="font-size:12px; color:#6B7280;">Total Deductions</div>
            </div>
        </div>
        <div class="col-4">
            <div style="background:#EFF6FF; border-radius:8px; padding:14px; text-align:center;">
                <div style="font-size:20px; font-weight:700; color:#2563EB;"><?= formatMoney($totalNet) ?></div>
                <div style="font-size:12px; color:#6B7280;">Total Net Pay</div>
            </div>
        </div>
    </div>
    <div class="table-wrapper" data-table-search="Search payroll summary...">
        <table>
            <thead>
                <tr><th>Employee</th><th>Department</th><th>Gross Pay</th><th>SSS</th><th>PhilHealth</th><th>Pag-IBIG</th><th>Tax</th><th>Loan Deduction</th><th>Net Pay</th><th>Employer Shares</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($payrollRows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['employee_name']) ?></td>
                    <td><?= htmlspecialchars($r['department']) ?></td>
                    <td><?= formatMoney((float)$r['gross_pay']) ?></td>
                    <td><?= formatMoney((float)$r['sss']) ?></td>
                    <td><?= formatMoney((float)$r['philhealth']) ?></td>
                    <td><?= formatMoney((float)$r['pagibig']) ?></td>
                    <td><?= formatMoney((float)$r['withholding_tax']) ?></td>
                    <td><?= formatMoney((float)$r['loan_deduction']) ?></td>
                    <td style="font-weight:700;"><?= formatMoney((float)$r['net_salary']) ?></td>
                    <td><?= formatMoney((float)($r['employer_total'] ?? 0)) ?></td>
                    <td><span class="badge <?= statusBadge($r['status']) ?>"><?= ucfirst($r['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($payrollRows && !$isCeo): ?><tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="2">Total</td>
                    <?php foreach (['gross_pay','sss','philhealth','pagibig','withholding_tax','loan_deduction','net_salary','employer_total'] as $field): ?><td><?= formatMoney(array_sum(array_map(static fn($r) => (float)$r[$field], $payrollRows))) ?></td><?php endforeach; ?><td></td>
                </tr><?php endif; ?>
                <?php if (empty($payrollRows)): ?><tr><td colspan="11" style="text-align:center; color:#9CA3AF; padding:16px;">No payroll data.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($role === 'payroll_manager'): ?>
<div class="card">
    <div class="card-title">Government Contributions &amp; Employer Payroll Cost — <?= date('F Y', strtotime($month . '-01')) ?></div>
    <?php if (!$contributionSchemaReady): ?>
        <div class="alert alert-info">Employer contribution reporting is unavailable until the additive government-contributions migration has been applied.</div>
    <?php else: ?>
        <div style="font-size:13px;margin-bottom:12px;">
            <strong>Employer payroll cost:</strong> <?= formatMoney($employerPayrollCost) ?>
            <span style="color:#6B7280;">(gross payroll plus employer SSS, ECP, PhilHealth, and Pag-IBIG)</span>
        </div>
        <div class="table-wrapper" data-table-search="Search employer contributions...">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th><th>Period</th><th>Gross Pay</th>
                        <th>SSS Employee</th><th>SSS Employer</th><th>ECP</th>
                        <th>PhilHealth Employee</th><th>PhilHealth Employer</th>
                        <th>Pag-IBIG Employee</th><th>Pag-IBIG Employer</th><th>Employer Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employerContributionRows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['employee_name']) ?></td>
                        <td><?= formatDate($row['payroll_period_start']) ?> – <?= formatDate($row['payroll_period_end']) ?></td>
                        <td><?= formatMoney((float)$row['gross_pay']) ?></td>
                        <td><?= formatMoney((float)$row['sss']) ?></td>
                        <td><?= formatMoney((float)$row['sss_employer']) ?></td>
                        <td><?= formatMoney((float)$row['sss_ec_employer']) ?></td>
                        <td><?= formatMoney((float)$row['philhealth']) ?></td>
                        <td><?= formatMoney((float)$row['philhealth_employer']) ?></td>
                        <td><?= formatMoney((float)$row['pagibig']) ?></td>
                        <td><?= formatMoney((float)$row['pagibig_employer']) ?></td>
                        <td><?= formatMoney((float)$row['employer_payroll_cost']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($employerContributionRows): ?><tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="2">Total</td>
                        <?php foreach (['gross_pay','sss','sss_employer','sss_ec_employer','philhealth','philhealth_employer','pagibig','pagibig_employer','employer_payroll_cost'] as $field): ?><td><?= formatMoney(array_sum(array_map(static fn($r) => (float)$r[$field], $employerContributionRows))) ?></td><?php endforeach; ?>
                    </tr><?php endif; ?>
                    <?php if (!$employerContributionRows): ?><tr><td colspan="11" style="text-align:center;color:#9CA3AF;padding:16px;">No payroll data.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Attendance Summary -->
<div class="card">
    <div class="card-title">Attendance Summary</div>
    <div class="table-wrapper" data-table-search="Search attendance summary...">
        <table>
            <thead>
                <tr><th>Employee</th><th>Present Days</th><th>Absent Days</th><th>Leave Days</th><th>Total Late (min)</th><th>Total Undertime (min)</th></tr>
            </thead>
            <tbody>
                <?php foreach ($attRows as $r): ?>
                <tr>
                <td><?= htmlspecialchars($r['employee_name']) ?></td>
                    <td><span class="badge badge-green"><?= $r['present_days'] ?></span></td>
                    <td><span class="badge badge-red"><?= $r['absent_days'] ?></span></td>
                    <td><span class="badge badge-amber"><?= $r['leave_days'] ?></span></td>
                    <td><?= $r['total_late'] > 0 ? '<span style="color:#D97706;">'.$r['total_late'].' min</span>' : '—' ?></td>
                    <td><?= $r['total_undertime'] > 0 ? '<span style="color:#2563EB;">'.$r['total_undertime'].' min</span>' : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($attRows)): ?><tr><td colspan="6" style="text-align:center; color:#9CA3AF; padding:16px;">No attendance data.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Leave Summary -->
<div class="card">
    <div class="card-title">Leave Summary — <?= date('F Y', strtotime($month . '-01')) ?></div>
    <div class="table-wrapper" data-table-search="Search leave summary...">
        <table>
            <thead><tr><th><?= $aggregateLeave ? 'Scope' : 'Employee' ?></th><th>Type</th><th>Requests</th><th>Days</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($leaveRows as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['employee_name']) ?></td>
                    <td><?= ucfirst($row['leave_type']) ?></td>
                    <td><?= (int)$row['count'] ?></td>
                    <td><?= (int)$row['total_days'] ?></td>
                    <td><?= ucfirst($row['status']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($leaveRows)): ?><tr><td colspan="5" style="text-align:center;color:#9CA3AF;padding:16px;">No leave data.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canSeePayroll): ?>
<div class="card">
    <div class="card-title">Loan Summary</div>
    <div style="display:flex;gap:16px;flex-wrap:wrap;">
        <div><div style="font-size:20px;font-weight:700;"><?= (int)$loanSummary['active_loans'] ?></div><div style="font-size:12px;color:#6B7280;">Active Loans</div></div>
        <div><div style="font-size:20px;font-weight:700;"><?= formatMoney((float)$loanSummary['outstanding_balance']) ?></div><div style="font-size:12px;color:#6B7280;">Outstanding Balance</div></div>
        <div><div style="font-size:20px;font-weight:700;"><?= formatMoney($monthlyLoanDeductions) ?></div><div style="font-size:12px;color:#6B7280;">Deductions This Month</div></div>
    </div>
</div>
<?php endif; ?>

<div class="rpt-foot"><span>Directpath Canada Consultation Inc. - system generated report</span><span>Confidential</span></div>
</div></div>
</div>
