<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('payroll_manager', 'hr_admin', 'employee');

$db = getDB();
if ($_SESSION['role'] === 'employee') {
    // Direct links from Loan can arrive before the employee has opened Payroll to release due records.
    payrollReleaseDue($db);
}
$returnPath = $_SESSION['role'] === 'employee'
    ? (($_GET['from'] ?? '') === 'loan' ? '../employee/loan.php' : '../employee/payroll.php')
    : ($_SESSION['role'] === 'hr_admin' ? 'hr_payroll.php' : 'payroll.php');
$id = (int)($_GET['id'] ?? 0);
if ($_SESSION['role'] === 'employee') {
    $stmt = $db->prepare("
        SELECT p.*, CONCAT(e.first_name, ' ', e.last_name) AS name,
            e.employee_id, e.department, e.position,
            e.sss_no, e.philhealth_no, e.pagibig_no, e.tin_no, e.date_hired, e.civil_status
        FROM payroll p
        JOIN users e ON p.employee_id = e.employee_id
        WHERE p.id = ? AND p.employee_id = ? AND p.status = 'released'
    ");
    $stmt->execute([$id, $_SESSION['employee_id']]);  //  2 params for 2 placeholders
} else {
    $stmt = $db->prepare("
        SELECT p.*, CONCAT(e.first_name, ' ', e.last_name) AS name,
            e.employee_id, e.department, e.position,
            e.sss_no, e.philhealth_no, e.pagibig_no, e.tin_no, e.date_hired, e.civil_status
        FROM payroll p
        JOIN users e ON p.employee_id = e.employee_id
        WHERE p.id = ?
    ");
    $stmt->execute([$id]);  //  1 param for 1 placeholder — now inside else
}

$p = $stmt->fetch();
// Employees never generate a payslip: it opens only after release AND once the pay date has arrived.
if ($p && $_SESSION['role'] === 'employee' && !payslipAvailable($p)) $p = false;
if (!$p) {
    echo '<div style="text-align:center;padding:40px;font-family:sans-serif;color:#b91c1c;">Payslip is unavailable. It may not have been released yet.<br><a style="display:inline-block;margin-top:16px;color:#1d4ed8;" href="' . htmlspecialchars($returnPath, ENT_QUOTES, 'UTF-8') . '">Back</a></div>';
    exit;
}
$employerShares = payrollEmployerShares($p);
$attendanceStmt = $db->prepare("SELECT date, time_in, time_out, hours_worked, late_minutes, undertime_mins, status
                               FROM attendance WHERE employee_id=? AND date BETWEEN ? AND ? ORDER BY date");
$attendanceStmt->execute([$p['employee_id'], $p['payroll_period_start'], $p['payroll_period_end']]);
$attendanceDed = computeAttendanceDeductionsForRecords((float)$p['basic_salary'], $attendanceStmt->fetchAll(), approvedLeaveRangesForPeriod($db, (string)$p['employee_id'], $p['payroll_period_start'], $p['payroll_period_end']));
$payDesignation = $p['position'];
if (designationsReady($db)) {
    $dq = $db->prepare("SELECT designation FROM users WHERE employee_id=?");
    $dq->execute([$p['employee_id']]);
    $dv = trim((string)$dq->fetchColumn());
    if ($dv !== '') $payDesignation = $dv;
}
$ci        = payCutoffInfo($p['payroll_period_start'], $p['payroll_period_end']);
$attSum    = payrollAttendanceSummary($db, $p['employee_id'], $p['payroll_period_start'], $p['payroll_period_end']);
$mealAllow = (float)$p['allowances'];                         // stored as the employee's allowance = Meal Allowance
$basicPeriod = round((float)$p['gross_pay'] - $mealAllow, 2); // semi-monthly basic actually included in gross pay
$bonusAmt  = (float)$p['bonuses'];
$totalEarn = round((float)$p['net_salary'] + (float)$p['total_deductions'], 2);   // always ties to Net Pay
$otherEarn = round($totalEarn - $basicPeriod - $mealAllow - $bonusAmt, 2);
$isReleased = $p['status'] === 'released';
$n2 = fn($v) => number_format((float)$v, 2);
// Loan balances and year-to-date totals come from the saved payroll snapshots (partner engine)
$loanBalanceData = payrollLoanBalancesForRecord($db, (int)$p['id'], $p['loan_balance_snapshot'] ?? null, (float)($p['loan_deduction'] ?? 0));
$ytd = payrollYearToDateSummary($db, (string)$p['employee_id'], (string)$p['payroll_period_end']);
$taxStatus = ($p['civil_status'] ?? '') === 'married' ? 'ME' : 'S';
$totalTaxable = round($basicPeriod, 2);
$totalNonTax  = round($mealAllow + $bonusAmt + $otherEarn, 2);
$attDedTotal  = (float)($p['late_deduction'] ?? 0) + (float)($p['undertime_deduction'] ?? 0);
$periodTxt    = strtoupper(date('d M Y', strtotime($p['payroll_period_start']))) . ' TO ' . strtoupper(date('d M Y', strtotime($p['payroll_period_end'])));
$deductionMismatch = abs($attendanceDed['late_deduction'] - (float)($p['late_deduction'] ?? 0)) >= 0.01
    || abs($attendanceDed['undertime_deduction'] - (float)($p['undertime_deduction'] ?? 0)) >= 0.01;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payslip — <?= htmlspecialchars($p['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#CD1E27;--red-dark:#A8181F;--red-light:#FCEAEA;--sidebar-bg:#1A1D26;--body-bg:#F4F5F7;--card-bg:#fff;--text-dark:#1A1D26;--text-muted:#6B7280;--border:#E5E7EB;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Poppins',Arial,sans-serif;background:#E9EBEF;padding:20px;font-size:12px;color:var(--text-dark);}
.print-bar{max-width:210mm;margin:0 auto 14px;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;}
.btn-print{background:var(--red);color:#fff;border:none;padding:9px 20px;border-radius:8px;font:600 12px 'Poppins',sans-serif;cursor:pointer;}
.btn-print:hover{background:var(--red-dark);}
.btn-back{background:#fff;color:var(--text-dark);border:1px solid var(--border);padding:9px 20px;border-radius:8px;font:600 12px 'Poppins',sans-serif;cursor:pointer;text-decoration:none;}
.payslip{width:210mm;min-height:297mm;margin:0 auto;background:#fff;padding:12mm 12mm;border:1px solid var(--border);border-radius:0;border-top:4px solid var(--red);box-sizing:border-box;}
.ps-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:12px;flex-wrap:wrap;}
.ps-brand{display:flex;align-items:center;gap:14px;}
.ps-brand img{width:56px;height:56px;object-fit:contain;}
.ps-brand .nm{font-size:13px;font-weight:700;}
.ps-brand .sub{font-size:11px;color:var(--text-muted);}
.ps-for{text-align:right;font-size:12px;font-weight:600;}
.ps-pill{display:inline-block;background:var(--red-light);color:var(--red-dark);border-radius:20px;padding:3px 10px;margin-top:6px;font-size:11px;font-weight:600;}
.tbl-scroll{overflow-x:auto;margin-bottom:18px;}
table.ps{width:100%;border-collapse:collapse;margin-bottom:14px;font-size:10.5px;}
table.ps td,table.ps th{border:1px solid var(--border);padding:7px 9px;text-align:left;vertical-align:middle;}
table.ps th{background:#F9FAFB;font-weight:600;color:var(--text-dark);}
table.ps .r{text-align:right;white-space:nowrap;}
table.ps tr.tot td{font-weight:600;background:#F9FAFB;}
table.info td:nth-child(odd){background:#F9FAFB;width:17%;color:var(--text-muted);font-weight:500;}
table.info td:nth-child(even){width:33%;font-weight:500;}
.three{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;align-items:start;}
.sumrow{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px;}
.sumrow div{border:1px solid var(--border);padding:10px 14px;display:flex;justify-content:space-between;gap:10px;font-weight:700;font-size:12.5px;background:#F9FAFB;border-radius:8px;}
.sumrow div.net{background:var(--sidebar-bg);color:#fff;border-color:var(--sidebar-bg);}
.center th{text-align:center!important;}
.pay-section-title{font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;margin:6px 0;}
.attendance-rates{font-size:11px;color:var(--text-muted);margin:-2px 0 10px;}
.attendance-table{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:14px;}
.attendance-table th,.attendance-table td{padding:6px 8px;border:1px solid var(--border);text-align:right;white-space:nowrap;}
.attendance-table th:first-child,.attendance-table td:first-child{text-align:left;}
.attendance-table th{background:#F9FAFB;}
.attendance-warning{background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;border-radius:6px;padding:8px;margin-bottom:10px;font-size:11px;}
.ps-footer{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;border-top:1px solid var(--border);padding-top:12px;margin-top:8px;font-size:11px;color:var(--text-muted);}
@page{size:A4 portrait;margin:0;}
@media print{
  html,body{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  body{background:#fff;padding:0;}
  .no-print{display:none!important;}
  .payslip{border:none;border-radius:0;padding:12mm;width:210mm;min-height:0;zoom:1!important;}
}
</style>
</head>
<body>

<div class="print-bar no-print">
    <a href="<?= htmlspecialchars($returnPath, ENT_QUOTES, 'UTF-8') ?>" class="btn-back">Back</a>
    <button class="btn-print" onclick="window.print()">Print Payslip</button>
</div>

<div class="payslip">
    <div class="ps-top">
        <div class="ps-brand">
            <img src="../../assets/img/logo.png" alt="Directpath" onerror="this.style.display='none'">
            <div><div class="nm">DIRECTPATH CANADA CONSULTATION INC.</div><div class="sub">Madrigal Business Park, Alabang Muntinlupa</div></div>
        </div>
        <div class="ps-for">Payslip For : <?= $periodTxt ?><br><span class="ps-pill">Amount in PHP</span></div>
    </div>

    <table class="ps info"><tbody>
        <tr><td>Employee Code</td><td><?= htmlspecialchars($p['employee_id']) ?></td><td>Employee Name</td><td><?= htmlspecialchars(strtoupper($p['name'])) ?></td></tr>
        <tr><td>Location</td><td>Alabang, Muntinlupa</td><td>Tax Status</td><td><?= $taxStatus ?></td></tr>
        <tr><td>SSS No</td><td><?= htmlspecialchars($p['sss_no'] ?? '') ?></td><td>HDMF No</td><td><?= htmlspecialchars($p['pagibig_no'] ?? '') ?></td></tr>
        <tr><td>TIN</td><td><?= htmlspecialchars($p['tin_no'] ?? '') ?></td><td>Philhealth No</td><td><?= htmlspecialchars($p['philhealth_no'] ?? '') ?></td></tr>
        <tr><td>Designation</td><td><?= htmlspecialchars($payDesignation) ?></td><td>Date Hired</td><td><?= $p['date_hired'] ? strtoupper(date('d M Y', strtotime($p['date_hired']))) : '' ?></td></tr>
        <tr><td>Department</td><td><?= htmlspecialchars($p['department']) ?></td><td>Payroll Period / Cut-Off</td><td><?= htmlspecialchars($ci['label']) ?> · <?= htmlspecialchars($ci['cutoff']) ?></td></tr>
        <tr><td>Pay Date</td><td><?= strtoupper(date('d M Y', strtotime($ci['pay_date']))) ?></td><td>Status</td><td><?= $isReleased ? 'Released' : 'Pending Release' ?></td></tr>
        <tr><td>Pay Basis</td><td>Bi-Monthly</td><td>Date Released</td><td><?= $isReleased && $p['released_at'] ? strtoupper(date('d M Y', strtotime($p['released_at']))) : '—' ?></td></tr>
    </tbody></table>

    <div class="three">
        <table class="ps">
            <thead><tr><th>Taxable Earnings</th><th style="width:70px;">Hours/Days</th><th class="r" style="width:110px;">Amount PHP</th></tr></thead>
            <tbody>
                <tr><td>BASIC PAY (<?= htmlspecialchars($ci['cutoff']) ?>)</td><td class="r"><?= (int)($p['days_worked'] ?? 0) ?></td><td class="r"><?= $n2($basicPeriod) ?></td></tr>
                <tr><td>&nbsp;</td><td></td><td></td></tr><tr><td>&nbsp;</td><td></td><td></td></tr>
                <tr class="tot"><td>TOTAL TAXABLE INCOME</td><td></td><td class="r"><?= $n2($totalTaxable) ?></td></tr>
            </tbody>
        </table>
        <table class="ps">
            <thead><tr><th>Deductions</th><th class="r" style="width:110px;">Amount PHP</th></tr></thead>
            <tbody>
                <tr><td>Absent / LWOP (<?= (int)($p['absent_days'] ?? 0) + (int)($p['lwp_days'] ?? 0) ?> day/s)</td><td class="r"><?= $n2((float)($p['absence_deduction'] ?? 0) + (float)($p['lwp_deduction'] ?? 0)) ?></td></tr>
                <tr><td>Late (<?= (int)$attendanceDed['late_minutes'] ?> min)</td><td class="r"><?= $n2($p['late_deduction'] ?? 0) ?></td></tr>
                <tr><td>Undertime (<?= (int)$attendanceDed['undertime_minutes'] ?> min)</td><td class="r"><?= $n2($p['undertime_deduction'] ?? 0) ?></td></tr>
                <tr><td>SSS</td><td class="r"><?= $n2($p['sss']) ?></td></tr>
                <tr><td>PHILHEALTH</td><td class="r"><?= $n2($p['philhealth']) ?></td></tr>
                <tr><td>HDMF (Pag-IBIG)</td><td class="r"><?= $n2($p['pagibig']) ?></td></tr>
                <tr><td>WTAX (BIR / Withholding Tax)</td><td class="r"><?= $n2($p['withholding_tax']) ?></td></tr>
                <tr><td>Loan Deduction</td><td class="r"><?= $n2($p['loan_deduction'] ?? 0) ?></td></tr>
                <tr><td>Other Authorized Deductions</td><td class="r"><?= $n2($p['other_deductions']) ?></td></tr>
                <tr class="tot"><td>TOTAL DEDUCTIONS:</td><td class="r"><?= $n2($p['total_deductions']) ?></td></tr>
            </tbody>
        </table>
        <table class="ps">
            <thead><tr><th>Non - Taxable Earnings</th><th class="r" style="width:110px;">Amount PHP</th></tr></thead>
            <tbody>
                <tr><td>MEAL ALLOWANCE</td><td class="r"><?= $n2($mealAllow) ?></td></tr>
                <tr><td>CHRISTMAS BONUS</td><td class="r"><?= $n2($bonusAmt) ?></td></tr>
                <?php if (abs($otherEarn) >= 0.01): ?><tr><td>OTHER EARNINGS<?= (float)($p['thirteenth_month_pay'] ?? 0) > 0 ? ' (incl. 13th Month)' : '' ?><?= !empty($p['adjustment_reason']) ? ' (' . htmlspecialchars($p['adjustment_reason']) . ')' : '' ?></td><td class="r"><?= $n2($otherEarn) ?></td></tr><?php endif; ?>
                <tr class="tot"><td>TOTAL NON-TAXABLE INCOME</td><td class="r"><?= $n2($totalNonTax) ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="pay-section-title">Employer Contributions (company paid)</div>
    <table class="ps">
        <tr><td>SSS employer share</td><td><?= formatMoney($employerShares['sss']) ?></td></tr>
        <tr><td>SSS Employees’ Compensation (ECP)</td><td><?= formatMoney($employerShares['ec']) ?></td></tr>
        <tr><td>PhilHealth employer share</td><td><?= formatMoney($employerShares['philhealth']) ?></td></tr>
        <tr><td>Pag-IBIG employer share</td><td><?= formatMoney($employerShares['pagibig']) ?></td></tr>
        <tr class="total"><td>TOTAL EMPLOYER CONTRIBUTIONS</td><td><?= formatMoney($employerShares['total']) ?></td></tr>
    </table>

    <div class="sumrow">
        <div><span>GROSS PAY</span><span><?= $n2($totalEarn) ?></span></div>
        <div><span>TOTAL DEDUCTIONS</span><span><?= $n2($p['total_deductions']) ?></span></div>
        <div class="net"><span>NET PAY</span><span><?= $n2($p['net_salary']) ?></span></div>
    </div>

    <div class="three">
        <table class="ps center">
            <thead><tr><th>Loan/Deduction balances</th><th class="r" style="width:110px;">Amount PHP</th></tr></thead>
            <tbody>
                <?php $loanRows = $loanBalanceData['loans']; foreach ($loanRows as $lr): ?>
                <tr><td><?= htmlspecialchars(($lr['loan_no'] ?? '') . ' ' . ucwords(str_replace('_', ' ', (string)($lr['loan_type'] ?? '')))) ?></td><td class="r"><?= $n2($lr['remaining_balance']) ?></td></tr>
                <?php endforeach; if (!$loanRows): ?><tr><td>No active loan</td><td class="r">0.00</td></tr><?php endif; ?>
                <tr class="tot"><td>TOTAL LOAN BALANCE</td><td class="r"><?= $n2($loanBalanceData['total_balance']) ?></td></tr>
            </tbody>
        </table>
        <table class="ps center">
            <thead><tr><th>Year-to-Date Summary</th><th class="r" style="width:110px;">Amount PHP</th></tr></thead>
            <tbody>
                <tr><td>TAXABLE INCOME</td><td class="r"><?= $n2($ytd['taxable_income']) ?></td></tr>
                <tr><td>NON TAXABLE INCOME</td><td class="r"><?= $n2($ytd['non_taxable_income']) ?></td></tr>
                <tr><td>SSS</td><td class="r"><?= $n2($ytd['sss']) ?></td></tr>
                <tr><td>PHILHEALTH</td><td class="r"><?= $n2($ytd['philhealth']) ?></td></tr>
                <tr><td>HDMF</td><td class="r"><?= $n2($ytd['hdmf']) ?></td></tr>
                <tr><td>WTAX</td><td class="r"><?= $n2($ytd['withholding_tax']) ?></td></tr>
                <tr><td>NET PAY</td><td class="r"><?= $n2($ytd['net_pay']) ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="ps-footer">
        <span>Directpath HRMS Philippines — Official Payroll Document</span>
        <span>Status: <?= $isReleased ? 'Released' : 'Pending Release' ?><?= $isReleased && $p['released_at'] ? ' · Date Released: ' . formatDate($p['released_at']) : '' ?></span>
    </div>
</div>

<script src="../../assets/js/a4-fit.js"></script>
</body>
</html>
