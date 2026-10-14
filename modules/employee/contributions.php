<?php
// ============================================================
// Employee — Government contributions & withholding tax (VIEW ONLY)
// SSS, Pag-IBIG and PhilHealth are kept in separate tables; a summary and grand total follow.
// Figures come from the employee's own RELEASED payroll records (nothing here can be edited).
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('employee');

$pageTitle  = 'Contributions & Tax';
$activePage = 'contributions';
$db = getDB();
$empId = $_SESSION['employee_id'];
$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2020 || $year > (int)date('Y') + 1) $year = (int)date('Y');

$me = getCurrentUser();
$st = $db->prepare("SELECT * FROM payroll WHERE employee_id = ? AND status = 'released' AND YEAR(payroll_period_end) = ? ORDER BY payroll_period_start");
$st->execute([$empId, $year]);
$rows = [];
foreach ($st->fetchAll() as $p) {
    if (!payslipAvailable($p)) continue;                       // only once the pay date has arrived
    $er = payrollEmployerShares($p);
    $ci = payCutoffInfo($p['payroll_period_start'], $p['payroll_period_end']);
    $earn = (float)$p['gross_pay'] + (float)$p['bonuses'] + (float)($p['salary_adjustment'] ?? 0);
    $rows[] = ['label' => date('M j', strtotime($p['payroll_period_start'])) . '–' . date('j, Y', strtotime($p['payroll_period_end'])),
               'cutoff' => $ci['cutoff'], 'sss' => (float)$p['sss'], 'ph' => (float)$p['philhealth'], 'pi' => (float)$p['pagibig'],
               'er' => $er, 'tax' => (float)$p['withholding_tax'],
               'taxable' => round($earn - (float)$p['sss'] - (float)$p['philhealth'] - (float)$p['pagibig'], 2)];
}
$tot = ['sss' => [0, 0], 'pagibig' => [0, 0], 'philhealth' => [0, 0]];
foreach ($rows as $r) {
    $tot['sss'][0] += $r['sss'];  $tot['sss'][1] += $r['er']['sss'] + $r['er']['ec'];
    $tot['pagibig'][0] += $r['pi']; $tot['pagibig'][1] += $r['er']['pagibig'];
    $tot['philhealth'][0] += $r['ph']; $tot['philhealth'][1] += $r['er']['philhealth'];
}
$grand = 0; foreach ($tot as $t) $grand += $t[0] + $t[1];

// One reusable renderer so the three tables stay separate but look identical
function contribTable(string $title, array $rows, callable $emp, callable $er, array $totals, array $extra = []): void { ?>
    <div class="card"><div class="card-title"><?= htmlspecialchars($title) ?></div>
    <?php foreach ($extra as $l => $v): ?><div style="font-size:12px;color:#6B7280;"><b><?= htmlspecialchars($l) ?>:</b> <?= htmlspecialchars($v) ?></div><?php endforeach; ?>
    <div class="table-wrapper"><table>
        <thead><tr><th>Contribution Period</th><th style="text-align:right;">Employee Contribution</th><th style="text-align:right;">Employer Contribution</th><th style="text-align:right;">Total Contribution</th></tr></thead><tbody>
        <?php foreach ($rows as $r): $e = $emp($r); $c = $er($r); ?>
        <tr><td><?= htmlspecialchars($r['label']) ?> <span style="color:#9CA3AF;font-size:11px;">(<?= $r['cutoff'] ?>)</span></td>
            <td style="text-align:right;"><?= formatMoney($e) ?></td><td style="text-align:right;"><?= formatMoney($c) ?></td><td style="text-align:right;font-weight:600;"><?= formatMoney($e + $c) ?></td></tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="4" style="text-align:center;color:#9CA3AF;">No released payroll for this year yet.</td></tr><?php endif; ?>
        <tr style="font-weight:700;background:#F9FAFB;"><td>Total</td><td style="text-align:right;"><?= formatMoney($totals[0]) ?></td><td style="text-align:right;"><?= formatMoney($totals[1]) ?></td><td style="text-align:right;"><?= formatMoney($totals[0] + $totals[1]) ?></td></tr>
    </tbody></table></div></div>
<?php }

include '../../includes/header.php';
?>
<div class="page-body">
<div class="card"><form method="GET" class="filter-bar" style="margin:0;display:flex;gap:10px;align-items:center;">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($_GET['tab'] ?? 'sss') ?>"><b>Year</b><select name="year" class="form-select" style="width:auto;" onchange="this.form.submit()"><?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 3; $y--): ?><option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select>
    <span style="font-size:12px;color:#6B7280;">View-only. Official contribution amounts are set by Payroll and cannot be edited here.</span></form></div>

<?php
$cTab = $_GET['tab'] ?? 'sss';
if (!in_array($cTab, ['sss','pagibig','philhealth','bir','summary'], true)) $cTab = 'sss';
echo renderTabBar(['sss' => ['SSS', count($rows)], 'pagibig' => ['Pag-IBIG', count($rows)], 'philhealth' => ['PhilHealth', count($rows)], 'bir' => ['BIR / Tax', count($rows)], 'summary' => ['Summary', 0]], $cTab, 'tab', ['year' => $year]);
?>
<?php if ($cTab === 'sss'): ?>
<?php contribTable('SSS Contribution — Form / Layout', $rows, fn($r) => $r['sss'], fn($r) => $r['er']['sss'], $tot['sss'],
    ['Employee Name' => $me['name'] ?? '', 'Employee Number' => $empId, 'SSS Number' => $me['sss_no'] ?: '—']); ?>
<?php elseif ($cTab === 'pagibig'): ?>
<?php contribTable('Pag-IBIG Contribution', $rows, fn($r) => $r['pi'], fn($r) => $r['er']['pagibig'], $tot['pagibig'], ['Pag-IBIG Number' => $me['pagibig_no'] ?: '—']); ?>
<?php elseif ($cTab === 'philhealth'): ?>
<?php contribTable('PhilHealth Contribution', $rows, fn($r) => $r['ph'], fn($r) => $r['er']['philhealth'], $tot['philhealth'], ['PhilHealth Number' => $me['philhealth_no'] ?: '—']); ?>

<?php elseif ($cTab === 'summary'): ?>
<div class="card"><div class="card-title">Government Contribution Summary — <?= $year ?></div>
    <div class="table-wrapper"><table>
        <thead><tr><th>Contribution</th><th style="text-align:right;">Employee Share</th><th style="text-align:right;">Employer Share</th><th style="text-align:right;">Total</th></tr></thead><tbody>
        <?php foreach (['sss' => 'SSS', 'pagibig' => 'Pag-IBIG', 'philhealth' => 'PhilHealth'] as $k => $lbl): ?>
        <tr><td><?= $lbl ?></td><td style="text-align:right;"><?= formatMoney($tot[$k][0]) ?></td><td style="text-align:right;"><?= formatMoney($tot[$k][1]) ?></td><td style="text-align:right;"><?= formatMoney($tot[$k][0] + $tot[$k][1]) ?></td></tr>
        <?php endforeach; ?>
        <tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td>Total</td><td style="text-align:right;"><?= formatMoney(array_sum(array_column($tot, 0))) ?></td><td style="text-align:right;"><?= formatMoney(array_sum(array_column($tot, 1))) ?></td><td style="text-align:right;"><?= formatMoney($grand) ?></td></tr>
        </tbody></table></div>
    <h5 style="margin-top:14px;font-weight:700;">Grand Total Government Contributions: <?= formatMoney($grand) ?></h5>
    <div style="font-size:11px;color:#6B7280;">Only the Employee Share is deducted from your pay; the Employer Share is paid by the company.</div>
</div>

<?php elseif ($cTab === 'bir'): ?>
<div class="card"><div class="card-title">BIR / Withholding Tax</div>
    <div class="table-wrapper"><table>
        <thead><tr><th>Payroll Period</th><th style="text-align:right;">Taxable Compensation</th><th style="text-align:right;">Withholding Tax</th></tr></thead><tbody>
        <?php $taxTot = 0; foreach ($rows as $r): $taxTot += $r['tax']; ?>
        <tr><td><?= htmlspecialchars($r['label']) ?></td><td style="text-align:right;"><?= formatMoney($r['taxable']) ?></td><td style="text-align:right;"><?= formatMoney($r['tax']) ?></td></tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="3" style="text-align:center;color:#9CA3AF;">No released payroll for this year yet.</td></tr><?php endif; ?>
        <tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td>Total</td><td style="text-align:right;"><?= formatMoney(array_sum(array_column($rows, 'taxable'))) ?></td><td style="text-align:right;"><?= formatMoney($taxTot) ?></td></tr></tbody></table></div>
    <div style="font-size:11px;color:#6B7280;">Taxable compensation = gross earnings of the period less your SSS, PhilHealth and Pag-IBIG share.</div>
</div>
<?php endif; ?>
</div>
