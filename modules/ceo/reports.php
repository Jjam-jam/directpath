<?php
// ============================================================
// CEO Reports — VIEW ONLY, company totals (no individual pay data)
//   Payroll (incl. bonuses, salary adjustments, employer-side contributions, 13th month)
//   Attendance (late vs undertime kept separate) · Leave · Loans
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('ceo');

$pageTitle  = 'Reports';
$activePage = 'reports';
$db = getDB();

$month = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$year = (int)substr($month, 0, 4);

function rows(PDO $db, string $sql, array $p = []): array {
    try { $st = $db->prepare($sql); $st->execute($p); return $st->fetchAll(); }
    catch (PDOException $e) { return []; }
}

// ── Payroll: per-row read so employer shares follow each row's basic salary ──
$payRows = rows($db, "SELECT * FROM payroll WHERE DATE_FORMAT(payroll_period_start,'%Y-%m') = ? AND status IN ('generated','released')", [$month]);
$pay = ['gross'=>0,'net'=>0,'deductions'=>0,'bonus'=>0,'adjust'=>0,'late'=>0,'under'=>0,'loan'=>0,'other'=>0,
        'ee_sss'=>0,'ee_ph'=>0,'ee_pi'=>0,'tax'=>0,'er_sss'=>0,'er_ecp'=>0,'er_ph'=>0,'er_pi'=>0,'released'=>0,'generated'=>0,'headcount'=>0,'missing_contribution_snapshots'=>0];
$seen = [];
foreach ($payRows as $r) {
    $pay['gross']      += (float)$r['gross_pay'];
    $pay['net']        += (float)$r['net_salary'];
    $pay['deductions'] += (float)$r['total_deductions'];
    $pay['bonus']      += (float)($r['bonuses'] ?? 0);
    $pay['adjust']     += (float)($r['salary_adjustment'] ?? 0);
    $pay['late']       += (float)($r['late_deduction'] ?? 0);
    $pay['under']      += (float)($r['undertime_deduction'] ?? 0);
    $pay['loan']       += (float)($r['loan_deduction'] ?? 0);
    $pay['other']      += (float)($r['other_deductions'] ?? 0);
    $pay['ee_sss']     += (float)$r['sss'];
    $pay['ee_ph']      += (float)$r['philhealth'];
    $pay['ee_pi']      += (float)$r['pagibig'];
    $pay['tax']        += (float)$r['withholding_tax'];
    $employerShares = payrollEmployerShares($r);
    $pay['er_sss'] += $employerShares['sss'];
    $pay['er_ecp'] += $employerShares['ec'];
    $pay['er_ph'] += $employerShares['philhealth'];
    $pay['er_pi'] += $employerShares['pagibig'];
    if (empty($r['government_contributions_snapshot'])) $pay['missing_contribution_snapshots']++;
    $pay[$r['status'] === 'released' ? 'released' : 'generated']++;
    $seen[$r['employee_id']] = true;
}
$pay['headcount'] = count($seen);
$erTotal = $pay['er_sss'] + $pay['er_ecp'] + $pay['er_ph'] + $pay['er_pi'];
$eeTotal = $pay['ee_sss'] + $pay['ee_ph'] + $pay['ee_pi'];

// ── 13th month (year to date) ─────────────────────────────
$thirteen = rows($db, "SELECT basic_salary, late_deduction, undertime_deduction, thirteenth_month_pay
                       FROM payroll WHERE YEAR(payroll_period_start) = ? AND status IN ('generated','released')", [$year]);
if (!$thirteen) {
    // older schema without thirteenth_month_pay
    $thirteen = rows($db, "SELECT basic_salary, late_deduction, undertime_deduction FROM payroll
                           WHERE YEAR(payroll_period_start) = ? AND status IN ('generated','released')", [$year]);
}
$earnedBasic = 0.0; $thirteenPaid = 0.0;
foreach ($thirteen as $t) {
    $earnedBasic  += ((float)$t['basic_salary'] / 2) - (float)($t['late_deduction'] ?? 0) - (float)($t['undertime_deduction'] ?? 0);
    $thirteenPaid += (float)($t['thirteenth_month_pay'] ?? 0);
}
$thirteenAccrued = computeThirteenthMonth(max(0, $earnedBasic));

// ── Department payroll ────────────────────────────────────
$deptPay = rows($db, "SELECT u.department, COUNT(DISTINCT p.employee_id) AS people,
                             COALESCE(SUM(p.gross_pay),0) AS gross, COALESCE(SUM(p.net_salary),0) AS net
                      FROM payroll p JOIN users u ON u.employee_id = p.employee_id
                      WHERE DATE_FORMAT(p.payroll_period_start,'%Y-%m') = ? AND p.status IN ('generated','released')
                      GROUP BY u.department ORDER BY gross DESC", [$month]);

// ── Attendance ────────────────────────────────────────────
$att = rows($db, "SELECT
        COUNT(CASE WHEN time_in IS NOT NULL THEN 1 END)                 AS days_present,
        COUNT(CASE WHEN status = 'absent' THEN 1 END)                   AS absent_days,
        COUNT(CASE WHEN status = 'on-leave' THEN 1 END)                 AS leave_days,
        COUNT(CASE WHEN late_minutes > 0 THEN 1 END)                    AS late_incidents,
        COALESCE(SUM(late_minutes),0)                                   AS late_minutes,
        COUNT(CASE WHEN undertime_mins > 0 THEN 1 END)                  AS under_incidents,
        COALESCE(SUM(undertime_mins),0)                                 AS under_minutes,
        COALESCE(SUM(hours_worked),0)                                   AS hours
    FROM attendance WHERE DATE_FORMAT(date,'%Y-%m') = ?", [$month])[0] ?? [];
$att += ['days_present'=>0,'absent_days'=>0,'leave_days'=>0,'late_incidents'=>0,'late_minutes'=>0,'under_incidents'=>0,'under_minutes'=>0,'hours'=>0];

$attDept = rows($db, "SELECT u.department,
        COALESCE(SUM(a.late_minutes),0) AS late_min, COALESCE(SUM(a.undertime_mins),0) AS under_min,
        COUNT(CASE WHEN a.status='absent' THEN 1 END) AS absences
    FROM attendance a JOIN users u ON u.employee_id = a.employee_id
    WHERE DATE_FORMAT(a.date,'%Y-%m') = ? GROUP BY u.department ORDER BY late_min DESC", [$month]);

// ── Leave ─────────────────────────────────────────────────
$leave = rows($db, "SELECT leave_type, status, COUNT(*) AS cnt, COALESCE(SUM(total_days),0) AS days
                    FROM leave_requests WHERE DATE_FORMAT(start_date,'%Y-%m') = ?
                    GROUP BY leave_type, status ORDER BY leave_type, status", [$month]);

// ── Loans ─────────────────────────────────────────────────
$loan = rows($db, "SELECT COUNT(CASE WHEN (status='approved' AND loan_status='active') THEN 1 END) AS active_loans,
                          COALESCE(SUM(CASE WHEN (status='approved' AND loan_status='active') THEN outstanding_balance ELSE 0 END),0) AS outstanding
                   FROM loan_disclosures")[0] ?? ['active_loans' => 0, 'outstanding' => 0];

include '../../includes/header.php';
?>
<style>
.rp-kpi{background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:14px;text-align:center;height:100%}
.rp-kpi .v{font-size:19px;font-weight:700;color:#1A1D26}.rp-kpi .l{font-size:11px;color:#6B7280;margin-top:2px}
@media print{html,body,.main-content,.page-body,.rpt-fluid,.a4-stage,.a4-sheet{overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}.sidebar,.topbar,.no-print{display:none!important}.main-content{margin:0!important;width:100%!important}}
</style>
<div class="page-body">

<div class="card no-print">
    <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <label class="form-label" style="margin:0;">Report month:</label>
        <input type="month" name="month" class="form-input" style="width:auto;" value="<?= htmlspecialchars($month) ?>">
        <button class="btn-primary" type="submit">Load report</button>
        <button class="btn-secondary" type="button" onclick="window.print()">Print</button>
        <span style="margin-left:auto;font-size:11px;color:#6B7280;">View-only · company totals</span>
    </form>
</div>

<!-- Payroll -->
<div class="a4-stage rpt-fluid"><div class="a4-sheet">
<div class="rpt-head">
    <div><div class="nm">Directpath Canada Consultation Inc.</div><div class="ttl">Executive Report</div></div>
    <div class="meta">Period: <b><?= date('F Y', strtotime($month . '-01')) ?></b><br>Generated: <?= date('F j, Y g:i A') ?></div>
</div>
<div class="card">
    <div class="card-title">Payroll — <?= date('F Y', strtotime($month . '-01')) ?>
        <span style="font-weight:400;font-size:12px;color:#6B7280;"><?= $pay['headcount'] ?> employee(s) · <?= $pay['released'] ?> released · <?= $pay['generated'] ?> generated</span></div>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="rp-kpi"><div class="v" style="color:#16A34A;"><?= formatMoney($pay['gross']) ?></div><div class="l">Gross pay</div></div></div>
        <div class="col-6 col-md-3"><div class="rp-kpi"><div class="v" style="color:#CD1E27;"><?= formatMoney($pay['deductions']) ?></div><div class="l">Total deductions</div></div></div>
        <div class="col-6 col-md-3"><div class="rp-kpi"><div class="v" style="color:#2563EB;"><?= formatMoney($pay['net']) ?></div><div class="l">Net pay</div></div></div>
        <div class="col-6 col-md-3"><div class="rp-kpi"><div class="v"><?= formatMoney($pay['gross'] + $erTotal) ?></div><div class="l">Total labor cost (gross + employer shares)</div></div></div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div style="font-size:12px;font-weight:700;color:#6B7280;text-transform:uppercase;margin-bottom:6px;">Pay components</div>
            <div class="table-wrapper"><table><tbody>
                <tr><td>Bonuses</td><td style="text-align:right;"><?= formatMoney($pay['bonus']) ?></td></tr>
                <tr><td>Salary adjustments</td><td style="text-align:right;"><?= formatMoney($pay['adjust']) ?></td></tr>
                <tr><td>Late deductions</td><td style="text-align:right;"><?= formatMoney($pay['late']) ?></td></tr>
                <tr><td>Undertime deductions</td><td style="text-align:right;"><?= formatMoney($pay['under']) ?></td></tr>
                <tr><td>Loan deductions</td><td style="text-align:right;"><?= formatMoney($pay['loan']) ?></td></tr>
                <tr><td>Other deductions</td><td style="text-align:right;"><?= formatMoney($pay['other']) ?></td></tr>
                <tr><td>Withholding tax</td><td style="text-align:right;"><?= formatMoney($pay['tax']) ?></td></tr>
            </tbody></table></div>
        </div>
        <div class="col-12 col-lg-6">
            <div style="font-size:12px;font-weight:700;color:#6B7280;text-transform:uppercase;margin-bottom:6px;">Government contributions</div>
            <div class="table-wrapper"><table>
                <thead><tr><th></th><th style="text-align:right;">Employee share</th><th style="text-align:right;">Employer share</th><th style="text-align:right;">Total remittance</th></tr></thead>
                <tbody>
                <tr><td>SSS</td><td style="text-align:right;"><?= formatMoney($pay['ee_sss']) ?></td><td style="text-align:right;"><?= formatMoney($pay['er_sss']) ?></td><td style="text-align:right;"><?= formatMoney($pay['ee_sss'] + $pay['er_sss']) ?></td></tr>
                <tr><td>SSS ECP (employer-paid)</td><td style="text-align:right;"><?= formatMoney(0) ?></td><td style="text-align:right;"><?= formatMoney($pay['er_ecp']) ?></td><td style="text-align:right;"><?= formatMoney($pay['er_ecp']) ?></td></tr>
                <tr><td>PhilHealth</td><td style="text-align:right;"><?= formatMoney($pay['ee_ph']) ?></td><td style="text-align:right;"><?= formatMoney($pay['er_ph']) ?></td><td style="text-align:right;"><?= formatMoney($pay['ee_ph'] + $pay['er_ph']) ?></td></tr>
                <tr><td>Pag-IBIG</td><td style="text-align:right;"><?= formatMoney($pay['ee_pi']) ?></td><td style="text-align:right;"><?= formatMoney($pay['er_pi']) ?></td><td style="text-align:right;"><?= formatMoney($pay['ee_pi'] + $pay['er_pi']) ?></td></tr>
                <tr style="font-weight:700;"><td>Total</td><td style="text-align:right;"><?= formatMoney($eeTotal) ?></td><td style="text-align:right;"><?= formatMoney($erTotal) ?></td><td style="text-align:right;"><?= formatMoney($eeTotal + $erTotal) ?></td></tr>
                </tbody></table></div>
            <div style="font-size:10.5px;color:#9CA3AF;margin-top:6px;">Employer amounts come from saved payroll values<?= $pay['missing_contribution_snapshots'] ? '; ' . $pay['missing_contribution_snapshots'] . ' legacy record(s) have no contribution snapshot' : '' ?>.</div>
        </div>
    </div>
</div>

<!-- 13th month -->
<div class="card">
    <div class="card-title">13th month pay — <?= $year ?></div>
    <div class="row g-3">
        <div class="col-6 col-md-4"><div class="rp-kpi"><div class="v"><?= formatMoney($thirteenAccrued) ?></div><div class="l">Accrued liability to date (basic earned ÷ 12)</div></div></div>
        <div class="col-6 col-md-4"><div class="rp-kpi"><div class="v"><?= formatMoney($thirteenPaid) ?></div><div class="l">Already paid out</div></div></div>
        <div class="col-12 col-md-4"><div class="rp-kpi"><div class="v" style="color:#CD1E27;"><?= formatMoney(max(0, $thirteenAccrued - $thirteenPaid)) ?></div><div class="l">Still to be paid</div></div></div>
    </div>
</div>

<?php if ($deptPay): ?>
<div class="card">
    <div class="card-title">Payroll by department</div>
    <div class="table-wrapper"><table>
        <thead><tr><th>Department</th><th>Employees</th><th style="text-align:right;">Gross</th><th style="text-align:right;">Net</th></tr></thead>
        <tbody><?php $deptPayTotals = ['gross'=>0.0,'net'=>0.0]; foreach ($deptPay as $d): $deptPayTotals['gross'] += (float)$d['gross']; $deptPayTotals['net'] += (float)$d['net']; ?>
        <tr><td><?= htmlspecialchars($d['department']) ?></td><td><?= (int)$d['people'] ?></td>
            <td style="text-align:right;"><?= formatMoney((float)$d['gross']) ?></td><td style="text-align:right;"><?= formatMoney((float)$d['net']) ?></td></tr>
        <?php endforeach; ?><?php if ($deptPay): ?><tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="2">Total</td><td style="text-align:right;"><?= formatMoney($deptPayTotals['gross']) ?></td><td style="text-align:right;"><?= formatMoney($deptPayTotals['net']) ?></td></tr><?php endif; ?></tbody></table></div>
</div>
<?php endif; ?>

<!-- Attendance -->
<div class="card">
    <div class="card-title">Attendance — <?= date('F Y', strtotime($month . '-01')) ?></div>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-2"><div class="rp-kpi"><div class="v"><?= (int)$att['days_present'] ?></div><div class="l">Days present</div></div></div>
        <div class="col-6 col-md-2"><div class="rp-kpi"><div class="v"><?= (int)$att['absent_days'] ?></div><div class="l">Absences</div></div></div>
        <div class="col-6 col-md-2"><div class="rp-kpi"><div class="v"><?= (int)$att['leave_days'] ?></div><div class="l">Leave days</div></div></div>
        <div class="col-6 col-md-2"><div class="rp-kpi"><div class="v" style="color:#D97706;"><?= number_format((int)$att['late_minutes']) ?> min</div><div class="l">Late · <?= (int)$att['late_incidents'] ?> incident(s)</div></div></div>
        <div class="col-6 col-md-2"><div class="rp-kpi"><div class="v" style="color:#D97706;"><?= number_format((int)$att['under_minutes']) ?> min</div><div class="l">Undertime · <?= (int)$att['under_incidents'] ?> incident(s)</div></div></div>
        <div class="col-6 col-md-2"><div class="rp-kpi"><div class="v"><?= number_format((float)$att['hours'], 1) ?></div><div class="l">Hours worked</div></div></div>
    </div>
    <div class="table-wrapper"><table>
        <thead><tr><th>Department</th><th>Late (min)</th><th>Undertime (min)</th><th>Absences</th></tr></thead>
        <tbody><?php foreach ($attDept as $d): ?>
        <tr><td><?= htmlspecialchars($d['department']) ?></td><td><?= (int)$d['late_min'] ?></td><td><?= (int)$d['under_min'] ?></td><td><?= (int)$d['absences'] ?></td></tr>
        <?php endforeach; if (!$attDept): ?><tr><td colspan="4" style="text-align:center;color:#9CA3AF;padding:16px;">No attendance data.</td></tr><?php endif; ?></tbody></table></div>
</div>

<!-- Leave -->
<div class="card">
    <div class="card-title">Leave — <?= date('F Y', strtotime($month . '-01')) ?></div>
    <div class="table-wrapper"><table>
        <thead><tr><th>Type</th><th>Status</th><th>Requests</th><th>Days</th></tr></thead>
        <tbody><?php foreach ($leave as $l): ?>
        <tr><td><?= ucfirst($l['leave_type']) ?></td><td><span class="badge <?= statusBadge($l['status']) ?>"><?= ucfirst($l['status']) ?></span></td><td><?= (int)$l['cnt'] ?></td><td><?= (int)$l['days'] ?></td></tr>
        <?php endforeach; if (!$leave): ?><tr><td colspan="4" style="text-align:center;color:#9CA3AF;padding:16px;">No leave data.</td></tr><?php endif; ?></tbody></table></div>
</div>

<!-- Loans -->
<div class="card">
    <div class="card-title">Employee loans</div>
    <div style="display:flex;gap:28px;flex-wrap:wrap;">
        <div><div style="font-size:20px;font-weight:700;"><?= (int)$loan['active_loans'] ?></div><div style="font-size:12px;color:#6B7280;">Active loans</div></div>
        <div><div style="font-size:20px;font-weight:700;"><?= formatMoney((float)$loan['outstanding']) ?></div><div style="font-size:12px;color:#6B7280;">Outstanding balance</div></div>
    </div>
</div>

<div class="rpt-foot"><span>Directpath Canada Consultation Inc. - system generated report</span><span>Confidential</span></div>
</div></div>
</div>
