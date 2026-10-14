<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('employee');

$pageTitle  = 'Payroll';
$activePage = 'payroll';
$db = getDB();
payrollReleaseDue($db);
$empId = $_SESSION['employee_id'];

$stmt = $db->prepare("
    SELECT id, employee_id, payroll_period_start, payroll_period_end, basic_salary, allowances,
           overtime_pay, bonuses, de_minimis, gross_pay, sss, sss_mpf, philhealth, pagibig, withholding_tax,
           other_deductions, total_deductions, net_salary, days_worked, late_deduction,
           undertime_deduction, late_minutes, undertime_minutes, lwp_days, lwp_deduction,
           payroll_calculation_snapshot, government_contributions_snapshot, sss_employer, sss_ec_employer, philhealth_employer, pagibig_employer,
           loan_balance_snapshot, status, released_at, loan_deduction, salary_adjustment, adjustment_reason,
           thirteenth_month_pay
    FROM payroll
    WHERE employee_id=?
    ORDER BY payroll_period_start DESC
");
$stmt->execute([$empId]);
$payrolls = array_values(array_filter($stmt->fetchAll(), 'payslipAvailable'));   // only CEO-approved, released payslips
$fYear   = $_GET['fy'] ?? '';
$fMonth  = $_GET['fm'] ?? '';
$fCut    = $_GET['fc'] ?? '';
$yearsAvailable = array_values(array_unique(array_map(fn($p) => date('Y', strtotime($p['payroll_period_end'])), $payrolls)));
rsort($yearsAvailable);
$payrolls = array_values(array_filter($payrolls, function ($p) use ($fYear, $fMonth, $fCut) {
    $end = strtotime($p['payroll_period_end']);
    if ($fYear !== '' && date('Y', $end) !== $fYear) return false;
    if ($fMonth !== '' && date('n', $end) !== $fMonth) return false;
    if ($fCut === '1' && (int)date('j', strtotime($p['payroll_period_start'])) > 15) return false;
    if ($fCut === '2' && (int)date('j', strtotime($p['payroll_period_start'])) <= 15) return false;
    return true;
}));
$demoPayrollCount = count(array_filter(
    $payrolls,
    static fn(array $payroll): bool => payrollSnapshotIsDemo($payroll['payroll_calculation_snapshot'] ?? null)
));

$stmt = $db->prepare("SELECT basic_salary, allowances FROM users WHERE employee_id=?");
$stmt->execute([$empId]);
$me = $stmt->fetch() ?: ['basic_salary' => 0, 'allowances' => 0];
$rates = payrollRates((float)$me['basic_salary']);

// Attendance deductions are calculated per record so the daily figures reconcile to payroll.
$attStmt = $db->prepare("SELECT date, time_in, time_out, hours_worked, late_minutes, undertime_mins, status
                         FROM attendance WHERE employee_id=? AND date BETWEEN ? AND ? ORDER BY date");

// Build the per-period breakdown shown when a row is clicked
$year = (int)date('Y');
$breakdowns = [];
foreach ($payrolls as $p) {
    if (!payslipAvailable($p)) continue; // unreleased payslip data never reaches the browser
    $attRows = $attStmt->fetchAll();
    $basic = (float)$p['basic_salary'];
    $r     = payrollRates($basic);
    $calculationSnapshot = null;
    if (!empty($p['payroll_calculation_snapshot'])) {
        $calculationSnapshot = json_decode($p['payroll_calculation_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($calculationSnapshot) || ($calculationSnapshot['version'] ?? null) !== 1) {
            throw new UnexpectedValueException('The saved payroll calculation snapshot is not supported.');
        }
    }
    $attendanceDed = computeAttendanceDeductionsForRecords(
        $basic,
        $attRows,
        approvedLeaveRangesForPeriod($db, $empId, $p['payroll_period_start'], $p['payroll_period_end'])
    );
    if ($calculationSnapshot !== null) {
        $r['daily'] = (float)$calculationSnapshot['daily_rate'];
        $r['hourly'] = (float)$calculationSnapshot['hourly_rate'];
        $r['per_minute'] = (float)$calculationSnapshot['per_minute_rate'];
        $attendanceDed['rates'] = $r;
        $attendanceDed['late_minutes'] = (int)$calculationSnapshot['late_minutes'];
        $attendanceDed['late_hours'] = round($attendanceDed['late_minutes'] / 60, 2);
        $attendanceDed['late_billable_minutes'] = (int)$calculationSnapshot['late_billable_minutes'];
        $attendanceDed['late_deduction'] = (float)$p['late_deduction'];
        $attendanceDed['undertime_minutes'] = (int)$calculationSnapshot['undertime_minutes'];
        $attendanceDed['undertime_hours'] = round($attendanceDed['undertime_minutes'] / 60, 2);
        $attendanceDed['undertime_billable_minutes'] = (int)$calculationSnapshot['undertime_billable_minutes'];
        $attendanceDed['undertime_deduction'] = (float)$p['undertime_deduction'];
        $attendanceDed['lwp_days'] = (float)$calculationSnapshot['lwp_days'];
        $attendanceDed['lwp_deduction'] = (float)$calculationSnapshot['lwp_deduction'];
        $attendanceDed['daily_rows'] = $calculationSnapshot['daily_rows'];
    }
    $adj   = (float)($p['salary_adjustment'] ?? 0);
    $taxableIncome = $calculationSnapshot !== null
        ? (float)$calculationSnapshot['taxable_income']
        : round((float)$p['gross_pay'] + (float)$p['bonuses'] + $adj - (float)$p['total_deductions'], 2);
    $deMinimis = (float)($p['de_minimis'] ?? 0);
    $loanBalanceData = payrollLoanBalancesForRecord(
        $db,
        (int)$p['id'],
        $p['loan_balance_snapshot'] ?? null,
        (float)($p['loan_deduction'] ?? 0)
    );
    $yearToDate = payrollYearToDateSummary($db, (string)$empId, (string)$p['payroll_period_end']);
    $employerShares = payrollEmployerShares($p);
    $itemised = $calculationSnapshot !== null
        ? round($taxableIncome + $deMinimis - (float)$p['total_deductions'], 2)
        : round((float)$p['gross_pay'] + (float)$p['bonuses'] + $adj - (float)$p['total_deductions'], 2);
    $breakdowns[$p['id']] = [
        'period'   => formatDate($p['payroll_period_start']) . ' – ' . formatDate($p['payroll_period_end']),
        'period_end' => formatDate($p['payroll_period_end']),
        'released' => !empty($p['released_at']) ? formatDate($p['released_at']) : '—',
        'status'   => ucfirst($p['status']),
        'rates'    => $r,
        'late_grace_minutes' => (int)($calculationSnapshot['late_grace_minutes'] ?? ATT_LATE_GRACE_MINUTES),
        'rounding_mode' => (string)($calculationSnapshot['rounding_mode'] ?? PAYROLL_DEDUCTION_ROUNDING_MODE),
        'rounding_block_minutes' => (int)($calculationSnapshot['rounding_block_minutes'] ?? PAYROLL_DEDUCTION_BLOCK_MINUTES),
        'days'     => (int)$p['days_worked'],
        'period_basic' => round($basic / 2, 2),
        'allowances'   => (float)$p['allowances'],
        'overtime'     => (float)($p['overtime_pay'] ?? 0),
        'bonus'        => (float)$p['bonuses'],
        'adjustment'   => $adj,
        'adjustment_reason' => $p['adjustment_reason'] ?? '',
        'new_calculation' => $calculationSnapshot !== null,
        'demo_record' => payrollSnapshotIsDemo($p['payroll_calculation_snapshot'] ?? null),
        'taxable_income' => $taxableIncome,
        'de_minimis' => $deMinimis,
        'loan_balances' => $loanBalanceData['loans'],
        'total_loan_balance' => $loanBalanceData['total_balance'],
        'total_loan_cutoff_deduction' => $loanBalanceData['total_cutoff_deduction'],
        'unitemized_loan_deduction' => $loanBalanceData['unitemized_deduction'],
        'loan_balance_available' => $loanBalanceData['balance_available'],
        'year_to_date' => $yearToDate,
        'thirteenth'   => (float)($p['thirteenth_month_pay'] ?? 0),
        'gross'        => (float)$p['gross_pay'],
        'late_min'     => $attendanceDed['late_minutes'],
        'under_min'    => $attendanceDed['undertime_minutes'],
        'late_days'    => $attendanceDed['late_days'],
        'absent_days'  => $attendanceDed['absent_days'],
        'late_ded'     => (float)($p['late_deduction'] ?? 0),
        'under_ded'    => (float)($p['undertime_deduction'] ?? 0),
        'lwp_days'     => (float)$attendanceDed['lwp_days'],
        'lwp_ded'      => (float)$attendanceDed['lwp_deduction'],
        'late_billable_minutes' => (int)$attendanceDed['late_billable_minutes'],
        'under_billable_minutes' => (int)$attendanceDed['undertime_billable_minutes'],
        'calculated_late_ded' => $attendanceDed['late_deduction'],
        'calculated_under_ded' => $attendanceDed['undertime_deduction'],
        'daily_rows'   => $attendanceDed['daily_rows'],
        'deduction_mismatch' => $calculationSnapshot === null && (abs($attendanceDed['late_deduction'] - (float)($p['late_deduction'] ?? 0)) >= 0.01
            || abs($attendanceDed['undertime_deduction'] - (float)($p['undertime_deduction'] ?? 0)) >= 0.01),
        'sss' => (float)$p['sss'], 'sss_mpf' => (float)($p['sss_mpf'] ?? 0), 'philhealth' => (float)$p['philhealth'], 'pagibig' => (float)$p['pagibig'],
        'employer_shares' => $employerShares,
        'tax' => (float)$p['withholding_tax'], 'loan' => (float)($p['loan_deduction'] ?? 0), 'other' => (float)$p['other_deductions'],
        'total_ded' => (float)$p['total_deductions'],
        'net' => (float)$p['net_salary'],
        'unitemised' => round((float)$p['net_salary'] - $itemised, 2),
    ];
}

$thirteenthAccrued = computeThirteenthMonthAccrued($empId, $year);

include '../../includes/header.php';

?>
<?php $currentCut = currentCutoff(); ?>
<div class="page-body">

<div class="card" style="border-left:4px solid var(--red);">
    <div style="display:flex;gap:28px;flex-wrap:wrap;font-size:13px;">
        <div><div class="stat-label">Current payroll period</div><strong><?= htmlspecialchars($currentCut['label']) ?></strong></div>
        <div><div class="stat-label">Cut-off</div><strong><?= htmlspecialchars($currentCut['cutoff']) ?></strong></div>
        <div><div class="stat-label">Salary release date</div><strong><?= formatDate($currentCut['pay_date']) ?></strong></div>
        <div style="color:var(--text-muted);font-size:12px;align-self:end;max-width:420px;">Cut-off is 1st-15th and 16th-end of month. Salary is released every 5th and 20th (Friday when it falls on a weekend). Your payslip appears automatically on the release date.</div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-8">
        <div class="card" style="height:100%;">
            <div class="card-title">My pay rates <span style="font-weight:400;font-size:11px;color:#6B7280;">how your daily, hourly and per-minute deductions are computed</span></div>
            <div class="row g-2">
                <div class="col-6 col-md-3"><div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:10px;text-align:center;"><div style="font-weight:700;"><?= formatMoney($rates['monthly']) ?></div><div style="font-size:11px;color:#6B7280;">Monthly basic</div></div></div>
                <div class="col-6 col-md-3"><div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:10px;text-align:center;"><div style="font-weight:700;"><?= formatMoney($rates['daily']) ?></div><div style="font-size:11px;color:#6B7280;">Daily rate</div></div></div>
                <div class="col-6 col-md-3"><div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:10px;text-align:center;"><div style="font-weight:700;"><?= formatMoney($rates['hourly']) ?></div><div style="font-size:11px;color:#6B7280;">Hourly rate</div></div></div>
                <div class="col-6 col-md-3"><div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:10px;text-align:center;"><div style="font-weight:700;">₱<?= number_format($rates['per_minute'], 4) ?></div><div style="font-size:11px;color:#6B7280;">Per minute</div></div></div>
            </div>
            <div style="font-size:11px;color:#9CA3AF;margin-top:8px;">Daily = (monthly × 12) ÷ <?= PAYROLL_WORKDAYS_PER_YEAR ?> working days · Hourly = daily ÷ <?= PAYROLL_HOURS_PER_DAY ?> · Per minute = hourly ÷ 60.</div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card" style="height:100%;">
            <div class="card-title">13th month pay (<?= $year ?>)</div>
            <div style="font-size:22px;font-weight:700;color:#16A34A;"><?= formatMoney($thirteenthAccrued) ?></div>
            <div style="font-size:11px;color:#6B7280;margin-top:4px;">Accrued so far: total basic pay earned this year ÷ 12. It is finalised and paid by Payroll.</div>
        </div>
    </div>
</div>

<div class="card">
    <form method="GET" class="filter-bar" style="margin:0;">
        <b style="font-size:13px;">Filter payslips</b>
        <select name="fy" class="form-select" style="width:auto;"><option value="">All years</option><?php foreach ($yearsAvailable as $y): ?><option value="<?= $y ?>" <?= $fYear === (string)$y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select>
        <select name="fm" class="form-select" style="width:auto;"><option value="">All months</option><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $fMonth === (string)$m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option><?php endfor; ?></select>
        <select name="fc" class="form-select" style="width:auto;"><option value="">Both cut-offs</option><option value="1" <?= $fCut === '1' ? 'selected' : '' ?>>1st - 15th</option><option value="2" <?= $fCut === '2' ? 'selected' : '' ?>>16th - end of month</option></select>
        <button type="submit" class="btn-primary">Apply</button>
        <a href="payroll.php" class="btn-secondary">Reset</a>
    </form>
</div>

<div class="card">
    <div class="card-title">Payroll Records</div>
    <?php if ($demoPayrollCount > 0): ?>
    <div style="background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;border-radius:7px;padding:9px 12px;margin-bottom:12px;font-size:12px;">
        This view includes <?= $demoPayrollCount ?> simulated payroll record(s). Record history retains their source classification.
    </div>
    <?php endif; ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Payroll Period / Cut-off</th>
                    <th>Release Date</th>
                    <th>Basic Salary</th>
                    <th>Gross Pay</th>
                    <th>Total Ded.</th>
                    <th>Net Pay</th>
                    <th>Employer Share</th>
                    <th>Status</th>
                    <th>Released</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payrolls as $p): $ci = payCutoffInfo($p['payroll_period_start'], $p['payroll_period_end']); $canSee = payslipAvailable($p); ?>
                <tr style="cursor:<?= $canSee ? 'pointer' : 'default' ?>;" <?= $canSee ? 'onclick="showBreakdown(' . (int)$p['id'] . ')" title="Click to see the full breakdown"' : '' ?>>
                    <td style="font-size:12px;"><?= formatDate($p['payroll_period_start']) ?> – <?= formatDate($p['payroll_period_end']) ?><div style="color:var(--text-muted);">Cut-off <?= htmlspecialchars($ci['cutoff']) ?></div></td>
                    <td style="font-size:12px;"><?= formatDate($ci['pay_date']) ?></td>

                    <?php if ($canSee): ?>
                        <!-- Show actual values -->
                        <td><?= formatMoney((float)$p['basic_salary']) ?></td>
                        <td><?= formatMoney((float)$p['gross_pay'] + (float)($p['bonuses'] ?? 0)) ?></td>
                        <td style="color:#CD1E27;"><?= formatMoney((float)$p['total_deductions']) ?></td>
                        <td style="font-weight:700; color:#16A34A;"><?= formatMoney((float)$p['net_salary']) ?></td>
                        <td><?= formatMoney(payrollEmployerShares($p)['total']) ?></td>
                    <?php else: ?>
                        <!-- Hide values but keep columns -->
                        <td style="color:#9CA3AF; font-size:12px;">Hidden</td>
                        <td style="color:#9CA3AF; font-size:12px;">Hidden</td>
                        <td style="color:#9CA3AF; font-size:12px;">Hidden</td>
                        <td style="color:#9CA3AF; font-size:12px;">Hidden</td>
                        <td style="color:#9CA3AF; font-size:12px;">Hidden</td>
                    <?php endif; ?>

                    <td>
                        <span class="badge <?= statusBadge($p['status']) ?>">
                            <?= ucfirst($p['status']) ?>
                        </span>
                    </td>
                    <td style="font-size:12px;">
                        <?= !empty($p['released_at']) ? formatDate($p['released_at']) : '<span style="color:#9CA3AF;">—</span>' ?>
                    </td>
                    <td>
                        <?php if ($p['status'] === 'released'): ?>
                            <a href="../admin/payslip.php?id=<?= $p['id'] ?>" target="_self" class="btn-primary btn-sm" onclick="event.stopPropagation();">View Payslip</a>
                        <?php elseif ($p['status'] === 'generated'): ?>
                            <span style="font-size:12px; color:#9CA3AF;">Releases on <?= formatDate($p['payroll_period_end']) ?></span>
                        <?php else: ?>
                            <span style="font-size:12px; color:#9CA3AF;">Not yet available</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php $visiblePayrolls = array_values(array_filter($payrolls, static fn($p) => payslipAvailable($p))); if ($visiblePayrolls): ?>
                <tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="2">Total available</td>
                    <td><?= formatMoney(array_sum(array_map(static fn($p) => (float)$p['basic_salary'], $visiblePayrolls))) ?></td>
                    <td><?= formatMoney(array_sum(array_map(static fn($p) => (float)$p['gross_pay'] + (float)($p['bonuses'] ?? 0), $visiblePayrolls))) ?></td>
                    <td><?= formatMoney(array_sum(array_column($visiblePayrolls, 'total_deductions'))) ?></td>
                    <td><?= formatMoney(array_sum(array_column($visiblePayrolls, 'net_salary'))) ?></td>
                    <td><?= formatMoney(array_sum(array_map(static fn($p) => payrollEmployerShares($p)['total'], $visiblePayrolls))) ?></td><td colspan="3"></td></tr>
                <?php endif; ?>
                <?php if (empty($payrolls)): ?>
                <tr>
                    <td colspan="10" style="text-align:center; color:#9CA3AF; padding:24px;">
                        No payroll records.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<!-- Breakdown modal -->
<div class="modal-overlay" id="breakdownModal">
    <div class="modal-box" style="max-width:640px;">
        <div class="modal-header">
            <div class="modal-title">Pay breakdown</div>
            <button class="modal-close" onclick="closeModal('breakdownModal')">&times;</button>
        </div>
        <div id="breakdownBody" style="font-size:12.5px;"></div>
        <div style="margin-top:14px;"><button class="btn-secondary" onclick="closeModal('breakdownModal')">Close</button></div>
    </div>
</div>

<script>
const BREAKDOWNS = <?= json_encode($breakdowns, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const esc  = t => { const d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; };

function line(label, value, opts) {
    opts = opts || {};
    return '<div style="display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px dotted #E5E7EB;' +
        (opts.bold ? 'font-weight:700;border-bottom:1.5px solid #D1D5DB;' : '') + '">' +
        '<span style="color:' + (opts.muted ? '#6B7280' : '#1A1D26') + ';">' + label + (opts.note ? '<div style="font-size:10.5px;color:#9CA3AF;">' + opts.note + '</div>' : '') + '</span>' +
        '<span style="white-space:nowrap;color:' + (opts.color || '#1A1D26') + ';">' + value + '</span></div>';
}
function section(t) { return '<div style="font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#9CA3AF;margin:14px 0 4px;">' + t + '</div>'; }

function showBreakdown(id) {
    const b = BREAKDOWNS[id]; if (!b) return;
    const r = b.rates;
    let h = '<div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;"><strong>' + esc(b.period) + '</strong><span class="badge badge-blue">' + esc(b.status) + '</span></div>';
    h += '<div style="font-size:11px;color:#6B7280;margin-top:4px;">Released on ' + esc(b.released) + '</div>';
    if (b.demo_record) h += '<div style="background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;border-radius:6px;padding:8px;margin-top:8px;">DEMO DATA — This synthetic payroll record is for demonstration only.</div>';

    h += section('Rates used');
    h += line('Monthly basic', peso(r.monthly), { muted: true });
    h += line('Daily rate', peso(r.daily), { muted: true, note: '(monthly × 12) ÷ 261' });
    h += line('Hourly rate', peso(r.hourly), { muted: true, note: 'daily ÷ 8' });
    h += line('Per-minute rate', '₱' + Number(r.per_minute).toFixed(4), { muted: true });

    h += section(b.new_calculation ? 'Taxable earnings and adjustments' : 'Earnings');
    h += line('Basic pay for the period', peso(b.period_basic), { note: 'half of monthly basic · ' + b.days + ' day(s) worked' });
    if (b.allowances)  h += line(b.new_calculation ? 'Allowances (taxable)' : 'Allowances', peso(b.allowances));
    if (b.overtime)    h += line('Overtime pay', peso(b.overtime));
    if (b.bonus)       h += line('Bonus', peso(b.bonus), { color: '#16A34A' });
    if (b.adjustment)  h += line('Salary adjustment', (b.adjustment >= 0 ? '' : '−') + peso(Math.abs(b.adjustment)),
        { color: b.adjustment < 0 ? '#CD1E27' : '#1A1D26', note: b.adjustment_reason ? esc(b.adjustment_reason) : '' });
    if (b.new_calculation) {
        if (b.late_ded) h += line('Tardiness (Late)', '−' + peso(b.late_ded), {
            color: '#CD1E27',
            note: b.late_min + ' min (' + Number(b.late_min / 60).toFixed(2) + ' hr); daily amounts use ' + peso(r.hourly) + '/hr ÷ 60 and are rounded to cents'
        });
        if (b.under_ded) h += line('Undertime', '−' + peso(b.under_ded), {
            color: '#CD1E27',
            note: b.under_min + ' min (' + Number(b.under_min / 60).toFixed(2) + ' hr); daily amounts use ' + peso(r.hourly) + '/hr ÷ 60 and are rounded to cents'
        });
        if (b.lwp_ded) h += line('Leave Without Pay', '−' + peso(b.lwp_ded), {
            color: '#CD1E27',
            note: Number(b.lwp_days).toFixed(2) + ' day(s) × ' + peso(r.daily) + '/day'
        });
        h += line('Total taxable income', peso(b.taxable_income), { bold: true });
        h += section('Non-taxable earnings');
        h += line('De Minimis', peso(b.de_minimis));
    } else {
        h += line('Gross pay', peso(b.gross + b.bonus), { bold: true });
        if (b.thirteenth) h += line('13th month pay', peso(b.thirteenth), { color: '#16A34A' });
    }

    h += section(b.new_calculation ? 'Attendance adjustments included above' : 'Attendance-based deductions');
    if (!b.new_calculation) {
        h += line('Late', b.late_min + ' min (' + b.late_days + ' day' + (b.late_days === 1 ? '' : 's') + ') × ₱' + Number(r.per_minute).toFixed(4),
                  { note: 'Arriving after 8:00 AM' });
        h += line('&nbsp;&nbsp;Late deduction', peso(b.late_ded), { color: '#CD1E27' });
        h += line('Undertime', b.under_min + ' min × ₱' + Number(r.per_minute).toFixed(4), { note: 'Leaving before 5:00 PM' });
        h += line('&nbsp;&nbsp;Undertime deduction', peso(b.under_ded), { color: '#CD1E27' });
    }
    if (b.deduction_mismatch) {
        h += '<div style="background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;border-radius:6px;padding:8px;margin:8px 0;font-size:11px;">Current attendance calculations differ from the deductions saved on this payroll record. Historical/finalized payroll is not changed; contact Payroll if you have questions.</div>';
    }
    if (b.absent_days && !b.new_calculation) h += line('Absences', b.absent_days + ' day(s) × ' + peso(r.daily), { muted: true });

    h += section('Statutory deductions');
    h += line('SSS EE', peso(b.sss - b.sss_mpf), { color: '#CD1E27' });
    if (b.sss_mpf > 0) h += line('SSS Provident Fund (Employee)', peso(b.sss_mpf), { color: '#CD1E27' });
    h += line('PhilHealth (employee share)', peso(b.philhealth), { color: '#CD1E27' });
    h += line('Pag-IBIG (employee share)', peso(b.pagibig), { color: '#CD1E27' });
    h += line('Withholding tax', peso(b.tax), { color: '#CD1E27' });
    if (b.loan)  h += line('Loan deduction', peso(b.loan), { color: '#CD1E27' });
    if (b.other) h += line('Other deductions', peso(b.other), { color: '#CD1E27' });
    h += line('Total deductions', peso(b.total_ded), { bold: true, color: '#CD1E27' });

    h += section('Employer statutory contributions (company paid)');
    h += line('SSS employer share', peso(b.employer_shares.sss));
    h += line('SSS Employees’ Compensation (ECP)', peso(b.employer_shares.ec));
    h += line('PhilHealth employer share', peso(b.employer_shares.philhealth));
    h += line('Pag-IBIG employer share', peso(b.employer_shares.pagibig));
    h += line('Total employer contributions', peso(b.employer_shares.total), { bold: true });

    h += section('Loan / Deduction Balances');
    if (b.loan_balances.length) {
        b.loan_balances.forEach(loan => {
            const loanLabel = esc(loan.loan_no) + ' — ' + esc(String(loan.loan_type).replace(/_/g, ' '));
            const loanNote = 'Total amount ' + peso(loan.loan_amount) + ' · This cutoff deduction ' + peso(loan.per_cutoff_deduction);
            h += line(loanLabel, 'Remaining ' + peso(loan.remaining_balance), { note: loanNote });
        });
    } else if (b.unitemized_loan_deduction > 0) {
        h += line('Previously recorded loan deduction', peso(b.unitemized_loan_deduction), {
            note: 'Individual loan details were not saved on this historical payroll.'
        });
    } else {
        h += line('No loan deductions recorded for this cutoff', peso(0));
    }
    h += line('Total Loan Balance', b.loan_balance_available ? peso(b.total_loan_balance) : 'Unavailable for this historical record', {
        bold: true
    });

    h += section('Year-to-Date Summary through ' + esc(b.period_end));
    h += line('Taxable income (current employer)', peso(b.year_to_date.taxable_income));
    h += line('Non-taxable income', peso(b.year_to_date.non_taxable_income));
    h += line('Previous employer income (taxable)', peso(b.year_to_date.previous_employer_income));
    h += line('Previous employer income (non-taxable)', peso(b.year_to_date.previous_employer_non_taxable_income));
    h += line('SSS (employee, including Provident Fund)', peso(b.year_to_date.sss));
    h += line('PhilHealth (employee)', peso(b.year_to_date.philhealth));
    h += line('HDMF / Pag-IBIG (employee)', peso(b.year_to_date.hdmf));
    h += line('Withholding tax (current + previous employer)', peso(b.year_to_date.withholding_tax));
    h += line('Previous employer withholding tax (credited)', peso(b.year_to_date.previous_employer_withholding_tax));
    h += line('Previous employer SSS / PhilHealth / HDMF',
        peso(b.year_to_date.previous_employer_sss) + ' / ' +
        peso(b.year_to_date.previous_employer_philhealth) + ' / ' +
        peso(b.year_to_date.previous_employer_hdmf));
    h += line('Net pay (current employer)', peso(b.year_to_date.net_pay), { bold: true });

    if (Math.abs(b.unitemised) >= 0.01) {
        h += line('Other adjustments not itemised above', (b.unitemised < 0 ? '−' : '') + peso(Math.abs(b.unitemised)), { muted: true, note: 'Difference between the listed items and the saved net pay. Ask Payroll if unsure.' });
    }
    h += '<div style="display:flex;justify-content:space-between;align-items:center;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:12px 14px;margin-top:12px;"><strong>Net pay</strong><strong style="font-size:18px;color:#16A34A;">' + peso(b.net) + '</strong></div>';

    document.getElementById('breakdownBody').innerHTML = h;
    openModal('breakdownModal');
}
</script>
