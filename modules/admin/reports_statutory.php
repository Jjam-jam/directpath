<?php
// ============================================================
// Directpath EMPS — Statutory & payroll reports
//   SSS · PhilHealth · Pag-IBIG contribution reports (employee / employer / total)
//   Withholding Tax Summary · Loan Deduction Summary · Payslip Records
// Payroll Manager: employee-level detail.  CEO: company / department totals only (view-only).
// HR Admin has no access to payroll data.
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('payroll_manager', 'ceo');

$pageTitle  = 'Statutory Reports';
$activePage = 'statutory';
$db    = getDB();
$isCeo = ($_SESSION['role'] ?? '') === 'ceo';

$types = ['sss' => 'SSS Contributions', 'philhealth' => 'PhilHealth Contributions', 'pagibig' => 'Pag-IBIG Contributions',
          'tax' => 'Withholding Tax Summary', 'loans' => 'Loan Deduction Summary', 'payslips' => 'Payslip Records'];
$type  = $_GET['type'] ?? 'sss';           if (!isset($types[$type])) $type = 'sss';
$month = trim($_GET['month'] ?? date('Y-m')); if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$dept  = trim($_GET['dept'] ?? '');
$self  = basename($_SERVER['SCRIPT_NAME']);

function q(PDO $db, string $sql, array $p = []): array {
    try { $st = $db->prepare($sql); $st->execute($p); return $st->fetchAll(); } catch (Throwable $e) { return []; }
}

// ── Payroll rows of the month (generated + released) ──────
$sql = "SELECT p.*, u.first_name, u.last_name, u.department, u.position, u.sss_no, u.philhealth_no, u.pagibig_no, u.tin_no
        FROM payroll p JOIN users u ON u.employee_id = p.employee_id
        WHERE DATE_FORMAT(p.payroll_period_start,'%Y-%m') = ? AND p.status IN ('generated','released')";
$par = [$month];
if ($dept !== '') { $sql .= " AND u.department = ?"; $par[] = $dept; }
$sql .= " ORDER BY u.department, u.last_name, u.first_name, p.payroll_period_start";
$rows = q($db, $sql, $par);

// Employer share: stored value when payroll saved one, otherwise the standard computation
foreach ($rows as &$r) {
    $shares = payrollEmployerShares($r);
    $r['er_sss'] = $shares['sss'];
    $r['er_ph']  = $shares['philhealth'];
    $r['er_pi']  = $shares['pagibig'];
    $r['er_ec']  = $shares['ec'];
    $r['name']   = $r['last_name'] . ', ' . $r['first_name'];
} unset($r);

// ── Build the table for the chosen report ─────────────────
$cols = []; $out = []; $totals = []; $note = '';
$cfg = [
  'sss'        => ['no' => 'sss_no',        'ee' => 'sss',        'er' => 'er_sss', 'label' => 'SSS No.'],
  'philhealth' => ['no' => 'philhealth_no', 'ee' => 'philhealth', 'er' => 'er_ph',  'label' => 'PhilHealth No.'],
  'pagibig'    => ['no' => 'pagibig_no',    'ee' => 'pagibig',    'er' => 'er_pi',  'label' => 'Pag-IBIG No.'],
];

if (isset($cfg[$type])) {
    $c = $cfg[$type];
    if ($isCeo) {
        $cols = ['Department', 'Employees', 'Employee share', 'Employer share', 'Total remittance'];
        $g = [];
        foreach ($rows as $r) {
            $d = $r['department'] ?: '—';
            $g[$d]['n'][$r['employee_id']] = 1;
            $g[$d]['ee'] = ($g[$d]['ee'] ?? 0) + (float)$r[$c['ee']];
            $g[$d]['er'] = ($g[$d]['er'] ?? 0) + (float)$r[$c['er']];
        }
        foreach ($g as $d => $v) $out[] = [$d, count($v['n']), $v['ee'], $v['er'], $v['ee'] + $v['er']];
        $moneyFrom = 2;
    } else {
        $cols = ['Employee ID', 'Name', 'Department', $c['label'], 'Period', 'Employee share', 'Employer share', 'Total remittance'];
        foreach ($rows as $r) {
            $out[] = [$r['employee_id'], $r['name'], $r['department'], $r[$c['no']] ?: '—',
                      date('M j', strtotime($r['payroll_period_start'])) . '–' . date('M j', strtotime($r['payroll_period_end'])),
                      (float)$r[$c['ee']], (float)$r[$c['er']], (float)$r[$c['ee']] + (float)$r[$c['er']]];
        }
        $moneyFrom = 5;
    }
    if ($type === 'sss') $note = 'SSS employer EC contribution is reported separately: ' . formatMoney(array_sum(array_column($rows, 'er_ec'))) . '.';
    $note .= ' Employer share uses the amount saved with each payslip, or the standard computation when none was saved.';
} elseif ($type === 'tax') {
    if ($isCeo) {
        $cols = ['Department', 'Employees', 'Taxable-gross pay', 'Withholding tax'];
        $g = [];
        foreach ($rows as $r) { $d = $r['department'] ?: '—'; $g[$d]['n'][$r['employee_id']] = 1;
            $g[$d]['gross'] = ($g[$d]['gross'] ?? 0) + (float)$r['gross_pay']; $g[$d]['tax'] = ($g[$d]['tax'] ?? 0) + (float)$r['withholding_tax']; }
        foreach ($g as $d => $v) $out[] = [$d, count($v['n']), $v['gross'], $v['tax']];
        $moneyFrom = 2;
    } else {
        $cols = ['Employee ID', 'Name', 'Department', 'TIN', 'Period', 'Gross pay', 'Withholding tax'];
        foreach ($rows as $r) $out[] = [$r['employee_id'], $r['name'], $r['department'], $r['tin_no'] ?: '—',
            date('M j', strtotime($r['payroll_period_start'])) . '–' . date('M j', strtotime($r['payroll_period_end'])),
            (float)$r['gross_pay'], (float)$r['withholding_tax']];
        $moneyFrom = 5;
    }
} elseif ($type === 'loans') {
    // Payroll loan deductions of the month + the loan balances behind them
    $bal = [];
    foreach (q($db, "SELECT employee_id, COALESCE(SUM(outstanding_balance),0) b FROM loan_disclosures WHERE status='approved' AND loan_status='active' GROUP BY employee_id") as $b) $bal[$b['employee_id']] = (float)$b['b'];
    $disc = [];
    foreach (q($db, "SELECT d.employee_id, COALESCE(SUM(pm.amount),0) a, MIN(pm.balance_after) mb FROM loan_disclosure_payments pm JOIN loan_disclosures d ON d.id=pm.disclosure_id WHERE pm.payment_month=? GROUP BY d.employee_id", [$month]) as $b) $disc[$b['employee_id']] = (float)$b['a'];
    $g = [];
    foreach ($rows as $r) {
        $k = $isCeo ? ($r['department'] ?: '—') : $r['employee_id'];
        $g[$k]['r'] = $r; $g[$k]['n'][$r['employee_id']] = 1;
        $g[$k]['ded'] = ($g[$k]['ded'] ?? 0) + (float)$r['loan_deduction'];
    }
    if ($isCeo) {
        $cols = ['Department', 'Employees with loans', 'Loan deductions'];
        foreach ($g as $k => $v) if ($v['ded'] > 0) $out[] = [$k, count($v['n']), $v['ded']];
        $moneyFrom = 2;
    } else {
        $cols = ['Employee ID', 'Name', 'Department', 'Loan deducted via payroll', 'Employee-loan repayments', 'Outstanding balance'];
        foreach ($g as $k => $v) {
            $r = $v['r']; $dd = $disc[$k] ?? 0;
            if ($v['ded'] > 0 || $dd > 0) $out[] = [$k, $r['name'], $r['department'], $v['ded'], $dd, $bal[$k] ?? 0];
        }
        $moneyFrom = 3;
    }
    $note = '"Employee-loan repayments" are automatic deductions recorded against approved company loan disclosures; a loan shows Fully Paid once its balance reaches zero.';
} else { // payslips
    if ($isCeo) {
        $cols = ['Department', 'Payslips', 'Released', 'Pending release', 'Net pay'];
        $g = [];
        foreach ($rows as $r) { $d = $r['department'] ?: '—';
            $g[$d]['n'] = ($g[$d]['n'] ?? 0) + 1; $g[$d]['rel'] = ($g[$d]['rel'] ?? 0) + ($r['status'] === 'released');
            $g[$d]['net'] = ($g[$d]['net'] ?? 0) + (float)$r['net_salary']; }
        foreach ($g as $d => $v) $out[] = [$d, $v['n'], $v['rel'], $v['n'] - $v['rel'], $v['net']];
        $moneyFrom = 4;
    } else {
        $cols = ['Employee ID', 'Name', 'Department', 'Period', 'Pay date', 'Gross', 'Deductions', 'Net pay', 'Status', 'Payslip'];
        foreach ($rows as $r) {
            $ci = payCutoffInfo($r['payroll_period_start'], $r['payroll_period_end']);
            $out[] = [$r['employee_id'], $r['name'], $r['department'],
                date('M j', strtotime($r['payroll_period_start'])) . '–' . date('M j', strtotime($r['payroll_period_end'])),
                date('M j, Y', strtotime($ci['pay_date'] ?? $r['payroll_period_end'])),
                (float)$r['gross_pay'], (float)$r['total_deductions'], (float)$r['net_salary'], ucfirst($r['status']),
                'LINK:payslip.php?id=' . (int)$r['id']];
        }
        $moneyFrom = 5; $moneyTo = 7;
    }
}
$moneyTo = $moneyTo ?? (count($cols) - 1);
if ($type === 'payslips' && !$isCeo) $moneyTo = 7;
if ($type === 'payslips' && $isCeo) $moneyTo = 4;
if ($type === 'loans' && !$isCeo) $moneyTo = 5;

// Totals row
$isMoney = fn($i) => $i >= $moneyFrom && $i <= $moneyTo && !($type === 'payslips' && $isCeo && $i < 4 && $i >= 1);
foreach ($out as $row) foreach ($row as $i => $v) if (is_float($v) || (is_int($v) && $isMoney($i) && $i >= $moneyFrom)) $totals[$i] = ($totals[$i] ?? 0) + $v;

// ── CSV export ────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $type . '_' . $month . '.csv"');
    $f = fopen('php://output', 'w'); fputcsv($f, $cols);
    foreach ($out as $row) { foreach ($row as &$v) if (is_string($v) && str_starts_with($v, 'LINK:')) $v = ''; unset($v); fputcsv($f, $row); }
    fclose($f);
    auditLog('Export Report', 'Reports', $types[$type] . ' ' . $month);
    exit;
}

$depts = array_column(q($db, "SELECT name FROM departments ORDER BY sort_order, name"), 'name');
include '../../includes/header.php';
?>
<style>@media print{.sidebar,.topbar,.no-print{display:none!important}.main-content{margin:0!important;width:100%!important}}</style>
<div class="page-body">
<div class="card no-print">
  <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <select name="type" class="form-input" style="width:auto;"><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>" <?= $k === $type ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <input type="month" name="month" class="form-input" style="width:auto;" value="<?= htmlspecialchars($month) ?>">
    <select name="dept" class="form-input" style="width:auto;"><option value="">All departments</option>
      <?php foreach ($depts as $d): ?><option <?= $d === $dept ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option><?php endforeach; ?></select>
    <button class="btn-primary" type="submit">Load</button>
    <a class="btn-secondary" style="text-decoration:none;" href="?<?= htmlspecialchars(http_build_query(['type'=>$type,'month'=>$month,'dept'=>$dept,'export'=>1])) ?>">CSV</a>
    <button class="btn-secondary" type="button" onclick="window.print()">Print</button>
    <?php if ($isCeo): ?><span style="margin-left:auto;font-size:11px;color:#6B7280;">View-only · totals per department</span><?php endif; ?>
  </form>
</div>
<div class="a4-stage rpt-fluid"><div class="a4-sheet">
<div class="rpt-head">
    <div><div class="nm">Directpath Canada Consultation Inc.</div><div class="ttl">Statutory Report</div></div>
    <div class="meta">Period: <b><?= date('F Y', strtotime($month . '-01')) ?></b><br>Generated: <?= date('F j, Y g:i A') ?></div>
</div>
<div class="card">
  <div class="card-title"><?= htmlspecialchars($types[$type]) ?> — <?= date('F Y', strtotime($month . '-01')) ?><?= $dept ? ' · ' . htmlspecialchars($dept) : '' ?></div>
  <div class="table-wrapper" data-table-search="Search this report..."><table>
    <thead><tr><?php foreach ($cols as $i => $c): ?><th style="<?= $isMoney($i) ? 'text-align:right' : '' ?>"><?= htmlspecialchars($c) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($out as $row): ?><tr>
      <?php foreach ($row as $i => $v): ?>
        <?php if (is_string($v) && str_starts_with($v, 'LINK:')): ?><td><a href="<?= htmlspecialchars(substr($v, 5)) ?>" target="_blank">View</a></td>
        <?php elseif ($isMoney($i) && (is_float($v) || is_int($v))): ?><td style="text-align:right;"><?= formatMoney((float)$v) ?></td>
        <?php else: ?><td><?= htmlspecialchars((string)$v) ?></td><?php endif; ?>
      <?php endforeach; ?></tr>
    <?php endforeach; if (!$out): ?><tr><td colspan="<?= count($cols) ?>" style="text-align:center;color:#9CA3AF;padding:20px;">No payroll data for this month.</td></tr><?php endif; ?>
    <?php if ($out): ?><tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><?php foreach ($cols as $i => $c): ?>
      <td style="<?= $isMoney($i) ? 'text-align:right' : '' ?>"><?= $i === 0 ? 'TOTAL' : ($isMoney($i) && isset($totals[$i]) ? formatMoney((float)$totals[$i]) : '') ?></td><?php endforeach; ?></tr><?php endif; ?>
    </tbody></table></div>
  <?php if ($note): ?><div style="font-size:11px;color:#9CA3AF;margin-top:8px;"><?= htmlspecialchars(trim($note)) ?></div><?php endif; ?>
</div>
<div class="rpt-foot"><span>Directpath Canada Consultation Inc. - system generated report</span><span>Confidential</span></div>
</div></div>
</div>
