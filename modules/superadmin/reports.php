<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin', 'payroll_manager', 'ceo');
header('Location: ../admin/reports.php');
exit;

$pageTitle  = 'Reports';
$activePage = 'reports';
$db = getDB();

$month = sanitize($_GET['month'] ?? date('Y-m'));

// Payroll summary
$payrollSummary = $db->prepare("SELECT CONCAT(e.first_name, ' ', e.last_name), e.department, p.gross_pay, p.total_deductions, p.net_salary, p.sss, p.philhealth, p.pagibig, p.withholding_tax, p.status FROM payroll p JOIN users e ON p.employee_id=e.employee_id WHERE DATE_FORMAT(p.payroll_period_start,'%Y-%m')=? ORDER BY CONCAT(e.first_name, ' ', e.last_name)");
$payrollSummary->execute([$month]);
$payrollRows = $payrollSummary->fetchAll();

$totalGross = array_sum(array_column($payrollRows, 'gross_pay'));
$totalNet   = array_sum(array_column($payrollRows, 'net_salary'));
$totalDed   = array_sum(array_column($payrollRows, 'total_deductions'));

// Attendance summary
$attSummary = $db->prepare("SELECT CONCAT(e.first_name, ' ', e.last_name), COUNT(CASE WHEN a.status='present' THEN 1 END) AS present_days, COUNT(CASE WHEN a.status='absent' THEN 1 END) AS absent_days, COUNT(CASE WHEN a.status='on-leave' THEN 1 END) AS leave_days, SUM(a.late_minutes) AS total_late FROM attendance a JOIN users e ON a.employee_id=e.employee_id WHERE DATE_FORMAT(a.date,'%Y-%m')=? GROUP BY e.employee_id, CONCAT(e.first_name, ' ', e.last_name) ORDER BY CONCAT(e.first_name, ' ', e.last_name)");
$attSummary->execute([$month]);
$attRows = $attSummary->fetchAll();

// Leave summary
$leaveSummary = $db->prepare("SELECT CONCAT(e.first_name, ' ', e.last_name), lr.leave_type, COUNT(*) AS count, SUM(lr.total_days) AS total_days, lr.status FROM leave_requests lr JOIN users e ON lr.employee_id=e.employee_id WHERE DATE_FORMAT(lr.start_date,'%Y-%m')=? GROUP BY e.employee_id, lr.leave_type, lr.status ORDER BY CONCAT(e.first_name, ' ', e.last_name)");
$leaveSummary->execute([$month]);
$leaveRows = $leaveSummary->fetchAll();

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
    <div><div class="nm">Directpath Canada Consultation Inc.</div><div class="ttl">System Report</div></div>
    <div class="meta">Period: <b><?= date('F Y', strtotime($month . '-01')) ?></b><br>Generated: <?= date('F j, Y g:i A') ?></div>
</div>
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
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Employee</th><th>Department</th><th>Gross Pay</th><th>SSS</th><th>PhilHealth</th><th>Pag-IBIG</th><th>Tax</th><th>Net Pay</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($payrollRows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['name']) ?></td>
                    <td><?= htmlspecialchars($r['department']) ?></td>
                    <td><?= formatMoney((float)$r['gross_pay']) ?></td>
                    <td><?= formatMoney((float)$r['sss']) ?></td>
                    <td><?= formatMoney((float)$r['philhealth']) ?></td>
                    <td><?= formatMoney((float)$r['pagibig']) ?></td>
                    <td><?= formatMoney((float)$r['withholding_tax']) ?></td>
                    <td style="font-weight:700;"><?= formatMoney((float)$r['net_salary']) ?></td>
                    <td><span class="badge <?= statusBadge($r['status']) ?>"><?= ucfirst($r['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($payrollRows): ?><tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="2">Total</td>
                    <?php foreach (['gross_pay','sss','philhealth','pagibig','withholding_tax','net_salary'] as $field): ?><td><?= formatMoney(array_sum(array_map(static fn($r) => (float)$r[$field], $payrollRows))) ?></td><?php endforeach; ?><td></td>
                </tr><?php endif; ?>
                <?php if (empty($payrollRows)): ?><tr><td colspan="9" style="text-align:center; color:#9CA3AF; padding:16px;">No payroll data.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Attendance Summary -->
<div class="card">
    <div class="card-title">Attendance Summary</div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Employee</th><th>Present Days</th><th>Absent Days</th><th>Leave Days</th><th>Total Late (min)</th></tr>
            </thead>
            <tbody>
                <?php foreach ($attRows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['name']) ?></td>
                    <td><span class="badge badge-green"><?= $r['present_days'] ?></span></td>
                    <td><span class="badge badge-red"><?= $r['absent_days'] ?></span></td>
                    <td><span class="badge badge-amber"><?= $r['leave_days'] ?></span></td>
                    <td><?= $r['total_late'] > 0 ? '<span style="color:#D97706;">'.$r['total_late'].' min</span>' : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($attRows)): ?><tr><td colspan="5" style="text-align:center; color:#9CA3AF; padding:16px;">No attendance data.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="rpt-foot"><span>Directpath Canada Consultation Inc. - system generated report</span><span>Confidential</span></div>
</div></div>
</div>
