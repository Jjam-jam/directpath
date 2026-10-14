<?php
// ============================================================
// CEO — Executive summaries (VIEW ONLY): employees, attendance, leave, payroll, performance
// ?type=employees|attendance|leave|payroll|performance   (performance also takes ?eval=ID)
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('ceo');

$type = $_GET['type'] ?? 'employees';
if (!in_array($type, ['employees', 'attendance', 'leave', 'payroll', 'performance'], true)) $type = 'employees';
$titles = ['employees' => 'Employee Overview', 'attendance' => 'Attendance Summary', 'leave' => 'Leave Summary',
           'payroll' => 'Payroll Summary', 'performance' => 'Performance Summary'];
$pageTitle  = $titles[$type];
$activePage = 'sum_' . $type;
$db = getDB();
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');

function sm(PDO $db, string $sql, array $p = []): array {
    try { $s = $db->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (Throwable $e) { return []; }
}
$active = "u.role = 'employee' AND u.status = 'active'";

include '../../includes/header.php';
?>
<div class="page-body">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
    <div style="font-size:18px;font-weight:700;"><?= $titles[$type] ?></div>
    <?php if (in_array($type, ['attendance', 'leave'], true)): ?>
    <form method="GET"><input type="hidden" name="type" value="<?= $type ?>"><input type="month" name="month" class="form-input" style="width:auto;" value="<?= htmlspecialchars($month) ?>" onchange="this.form.submit()"></form>
    <?php endif; ?>
    <span class="ceo-viewonly" style="background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;">View-only</span>
</div>

<?php if ($type === 'employees'):
    $tot = (int)sm($db, "SELECT COUNT(*) c FROM users u WHERE u.role='employee'")[0]['c'];
    $act = (int)sm($db, "SELECT COUNT(*) c FROM users u WHERE $active")[0]['c'];
    $tls = (int)sm($db, "SELECT COUNT(*) c FROM users u WHERE $active AND u.is_team_leader=1")[0]['c'];
    $byDept = sm($db, "SELECT u.department, COUNT(*) total, SUM(u.status='active') active_n,
                       MAX(CASE WHEN u.is_team_leader=1 THEN CONCAT(u.first_name,' ',u.last_name) END) leader,
                       MAX(CASE WHEN u.is_team_leader=1 THEN u.position END) leader_pos
                       FROM users u WHERE u.role='employee' GROUP BY u.department ORDER BY total DESC"); ?>
    <div class="row g-3 mb-3">
        <?php foreach ([['Total employees', $tot], ['Active', $act], ['Inactive', $tot - $act], ['Team Managers', $tls]] as [$l, $v]): ?>
        <div class="col-6 col-md-3"><div class="card" style="margin:0;text-align:center;"><div style="font-size:26px;font-weight:700;"><?= $v ?></div><div style="font-size:12px;color:#6B7280;"><?= $l ?></div></div></div>
        <?php endforeach; ?>
    </div>
    <div class="card"><div class="card-title">Employees per department</div><div class="table-wrapper"><table>
        <thead><tr><th>Department</th><th>Team Manager</th><th>Total</th><th>Active</th></tr></thead><tbody>
        <?php foreach ($byDept as $d): ?><tr><td><?= htmlspecialchars($d['department']) ?></td>
            <td><?= $d['leader'] ? htmlspecialchars($d['leader']) . '<div style="font-size:11px;color:#6B7280;">' . htmlspecialchars($d['leader_pos']) . '</div>' : '—' ?></td>
            <td><?= (int)$d['total'] ?></td><td><?= (int)$d['active_n'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>

<?php elseif ($type === 'attendance'):
    $a = sm($db, "SELECT SUM(a.status IN ('present','late','half-day') AND a.time_in IS NOT NULL) present, SUM(a.status='absent') absent, SUM(a.status='on-leave') onleave,
                  SUM(a.late_minutes>0) late_n, COALESCE(SUM(a.late_minutes),0) late_min, SUM(a.undertime_mins>0) under_n, COALESCE(SUM(a.undertime_mins),0) under_min
                  FROM attendance a WHERE DATE_FORMAT(a.date,'%Y-%m') = ?", [$month])[0];
    $byDept = sm($db, "SELECT u.department, SUM(a.status IN ('present','late','half-day')) present, SUM(a.status='absent') absent, SUM(a.late_minutes>0) late_n, SUM(a.undertime_mins>0) under_n
                       FROM attendance a JOIN users u ON u.employee_id = a.employee_id WHERE DATE_FORMAT(a.date,'%Y-%m') = ? GROUP BY u.department ORDER BY u.department", [$month]); ?>
    <div class="row g-3 mb-3">
        <?php foreach ([['Present (days)', $a['present']], ['Absent (days)', $a['absent']], ['On leave (days)', $a['onleave']], ['Late (times)', $a['late_n'] . ' / ' . $a['late_min'] . ' min'], ['Undertime (times)', $a['under_n'] . ' / ' . $a['under_min'] . ' min']] as [$l, $v]): ?>
        <div class="col-6 col-md"><div class="card" style="margin:0;text-align:center;"><div style="font-size:20px;font-weight:700;"><?= (int)$v == $v ? (int)$v : $v ?></div><div style="font-size:12px;color:#6B7280;"><?= $l ?></div></div></div>
        <?php endforeach; ?>
    </div>
    <div class="card"><div class="card-title">By department — <?= date('F Y', strtotime($month . '-01')) ?></div><div class="table-wrapper"><table>
        <thead><tr><th>Department</th><th>Present</th><th>Absent</th><th>Late</th><th>Undertime</th></tr></thead><tbody>
        <?php foreach ($byDept as $d): ?><tr><td><?= htmlspecialchars($d['department']) ?></td><td><?= (int)$d['present'] ?></td><td><?= (int)$d['absent'] ?></td><td><?= (int)$d['late_n'] ?></td><td><?= (int)$d['under_n'] ?></td></tr><?php endforeach; ?>
        <?php if (!$byDept): ?><tr><td colspan="5" style="text-align:center;color:#9CA3AF;">No attendance for this month.</td></tr><?php endif; ?>
        </tbody></table></div></div>

<?php elseif ($type === 'leave'):
    $s = sm($db, "SELECT SUM(status IN ('pending','documents_submitted')) pending, SUM(status IN ('approved','approved_pending_documents','documents_rejected')) approved, SUM(status='rejected') rejected
                  FROM leave_requests WHERE DATE_FORMAT(start_date,'%Y-%m') = ?", [$month])[0];
    $byDept = sm($db, "SELECT u.department, COUNT(*) n, SUM(l.status IN ('pending','documents_submitted')) pending, SUM(l.status IN ('approved','approved_pending_documents','documents_rejected')) approved, SUM(l.status='rejected') rejected, COALESCE(SUM(l.total_days),0) days
                       FROM leave_requests l JOIN users u ON u.employee_id = l.employee_id WHERE DATE_FORMAT(l.start_date,'%Y-%m') = ? GROUP BY u.department ORDER BY u.department", [$month]); ?>
    <div class="row g-3 mb-3">
        <?php foreach ([['Pending', $s['pending']], ['Approved', $s['approved']], ['Rejected', $s['rejected']]] as [$l, $v]): ?>
        <div class="col-4"><div class="card" style="margin:0;text-align:center;"><div style="font-size:26px;font-weight:700;"><?= (int)$v ?></div><div style="font-size:12px;color:#6B7280;"><?= $l ?></div></div></div>
        <?php endforeach; ?>
    </div>
    <div class="card"><div class="card-title">Leave by department — <?= date('F Y', strtotime($month . '-01')) ?></div><div class="table-wrapper"><table>
        <thead><tr><th>Department</th><th>Requests</th><th>Days</th><th>Pending</th><th>Approved</th><th>Rejected</th></tr></thead><tbody>
        <?php foreach ($byDept as $d): ?><tr><td><?= htmlspecialchars($d['department']) ?></td><td><?= (int)$d['n'] ?></td><td><?= (int)$d['days'] ?></td><td><?= (int)$d['pending'] ?></td><td><?= (int)$d['approved'] ?></td><td><?= (int)$d['rejected'] ?></td></tr><?php endforeach; ?>
        <?php if (!$byDept): ?><tr><td colspan="6" style="text-align:center;color:#9CA3AF;">No leave for this month.</td></tr><?php endif; ?>
        </tbody></table></div></div>

<?php elseif ($type === 'payroll'):
    $periods = sm($db, "SELECT p.payroll_period_start s, p.payroll_period_end e, COUNT(*) n, SUM(p.gross_pay) gross, SUM(p.total_deductions) ded, SUM(p.net_salary) net,
                        SUM(p.sss) sss, SUM(p.philhealth) ph, SUM(p.pagibig) pi, SUM(p.withholding_tax) tax, SUM(p.status='released') rel
                        FROM payroll p GROUP BY p.payroll_period_start, p.payroll_period_end ORDER BY p.payroll_period_start DESC LIMIT 12"); ?>
    <div class="card"><div class="card-title"><span>Payroll by period</span><a class="card-link" href="payroll_approval.php">Open approvals</a></div><div class="table-wrapper"><table>
        <thead><tr><th>Period</th><th>Employees</th><th>Gross</th><th>Deductions</th><th>Net</th><th>Employer share</th><th>Status</th><th>CEO approval</th></tr></thead><tbody>
        <?php $periodTotals = ['gross'=>0.0,'ded'=>0.0,'net'=>0.0,'employer'=>0.0]; foreach ($periods as $p): $a = getPayrollApproval($db, $p['s'], $p['e']); $er = payrollPeriodTotals($db, $p['s'], $p['e'])['employer'];
            $periodTotals['gross'] += (float)$p['gross']; $periodTotals['ded'] += (float)$p['ded']; $periodTotals['net'] += (float)$p['net']; $periodTotals['employer'] += (float)$er;
            $pe = sm($db, "SELECT p2.employee_id, CONCAT(u2.first_name,' ',u2.last_name) AS employee_name FROM payroll p2 JOIN users u2 ON u2.employee_id = p2.employee_id WHERE p2.payroll_period_start = ? AND p2.payroll_period_end = ? ORDER BY u2.last_name, u2.first_name", [$p['s'], $p['e']]); ?>
        <tr><td><?= formatDate($p['s']) ?> – <?= formatDate($p['e']) ?></td><td><?= (int)$p['n'] ?><div style="font-size:11px;color:#6B7280;max-width:220px;"><?= htmlspecialchars(payrollEmployeeLabel($pe, 3)) ?></div></td><td><?= formatMoney((float)$p['gross']) ?></td><td><?= formatMoney((float)$p['ded']) ?></td><td><?= formatMoney((float)$p['net']) ?></td><td><?= formatMoney((float)$er) ?></td>
            <td><?= (int)$p['rel'] === (int)$p['n'] ? 'Released' : 'Generated' ?></td>
            <td><?= !$a ? '<span class="badge badge-gray">Not submitted</span>' : ($a['status']==='approved' ? '<span class="badge badge-green">Approved</span>' : ($a['status']==='rejected' ? '<span class="badge badge-red">Rejected</span>' : '<span class="badge badge-amber">Pending</span>')) ?></td></tr>
        <?php endforeach; if (!$periods): ?><tr><td colspan="8" style="text-align:center;color:#9CA3AF;">No payroll yet.</td></tr><?php endif; ?>
        <?php if ($periods): ?><tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="2">Total</td><td><?= formatMoney($periodTotals['gross']) ?></td><td><?= formatMoney($periodTotals['ded']) ?></td><td><?= formatMoney($periodTotals['net']) ?></td><td><?= formatMoney($periodTotals['employer']) ?></td><td colspan="2"></td></tr><?php endif; ?>
        </tbody></table></div></div>

<?php else: /* performance */
    $evalId = (int)($_GET['eval'] ?? 0);
    if ($evalId):
        $ev = sm($db, "SELECT e.*, CONCAT(u.first_name,' ',u.last_name) name, u.department, u.position FROM performance_evaluations e JOIN users u ON u.employee_id = e.employee_id WHERE e.id = ? AND e.status='finalized'", [$evalId])[0] ?? null;
        $rat = $ev ? sm($db, "SELECT c.name, r.rating, r.weight, r.weighted_score FROM performance_ratings r JOIN performance_criteria c ON c.id = r.criterion_id WHERE r.evaluation_id = ? ORDER BY c.sort_order", [$evalId]) : [];
        if (!$ev): ?><div class="card">Evaluation not found.</div>
        <?php else: ?>
        <div class="card"><div class="card-title"><span><?= htmlspecialchars($ev['name']) ?> — <?= htmlspecialchars($ev['period_label']) ?></span><a class="card-link" href="summary.php?type=performance">Back</a></div>
            <div style="font-size:12px;color:#6B7280;margin-bottom:10px;"><?= htmlspecialchars($ev['department']) ?> · <?= htmlspecialchars($ev['position']) ?></div>
            <table><thead><tr><th>Criteria</th><th>Rating</th><th>Weight</th><th>Weighted score</th></tr></thead><tbody>
            <?php foreach ($rat as $r): ?><tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= (int)$r['rating'] ?> / 5</td><td><?= rtrim(rtrim($r['weight'], '0'), '.') ?>%</td><td><?= $r['weighted_score'] ?></td></tr><?php endforeach; ?>
            <tr><th colspan="3">Total score</th><th><?= $ev['total_score'] ?> / 100</th></tr></tbody></table>
            <div style="margin-top:10px;">Performance level: <strong style="color:<?= performanceLevelColor($ev['performance_level']) ?>"><?= htmlspecialchars($ev['performance_level']) ?></strong></div>
        </div>
        <?php endif;
    else:
        $levels = sm($db, "SELECT performance_level l, COUNT(*) n FROM performance_evaluations WHERE status='finalized' GROUP BY performance_level");
        $avg = sm($db, "SELECT ROUND(AVG(total_score),2) a, COUNT(*) n FROM performance_evaluations WHERE status='finalized'")[0];
        $byDept = sm($db, "SELECT COALESCE(NULLIF(TRIM(u.department), ''), 'Unassigned') department, COUNT(*) n, ROUND(AVG(e.total_score),2) a FROM performance_evaluations e JOIN users u ON u.employee_id = e.employee_id WHERE e.status='finalized' GROUP BY COALESCE(NULLIF(TRIM(u.department), ''), 'Unassigned') ORDER BY a DESC");
        $list = sm($db, "SELECT e.id, e.period_label, e.total_score, e.performance_level, CONCAT(u.first_name,' ',u.last_name) name, u.department FROM performance_evaluations e JOIN users u ON u.employee_id = e.employee_id WHERE e.status='finalized' ORDER BY e.finalized_at DESC LIMIT 100"); ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card" style="margin:0;text-align:center;"><div style="font-size:26px;font-weight:700;"><?= $avg['a'] ?? '—' ?></div><div style="font-size:12px;color:#6B7280;">Average score (<?= (int)$avg['n'] ?> evaluations)</div></div></div>
        <?php foreach ($levels as $l): ?><div class="col-6 col-md-2"><div class="card" style="margin:0;text-align:center;"><div style="font-size:22px;font-weight:700;color:<?= performanceLevelColor($l['l']) ?>"><?= (int)$l['n'] ?></div><div style="font-size:12px;color:#6B7280;"><?= htmlspecialchars($l['l']) ?></div></div></div><?php endforeach; ?>
    </div>
    <div class="row g-3">
        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-title">Department performance</div>
                    <table style="width:100%;table-layout:fixed;font-size:12px;">
                        <thead><tr><th scope="col" style="width:45%;padding-left:2px;padding-right:2px;">Department</th><th scope="col" style="width:20%;text-align:center;padding-left:2px;padding-right:2px;">Evals</th><th scope="col" style="width:35%;text-align:center;padding-left:2px;padding-right:2px;">Avg</th></tr></thead>
                        <tbody>
                            <?php foreach ($byDept as $d): ?>
                            <tr>
                                <td style="padding:6px 2px;overflow-wrap:anywhere;"><?= htmlspecialchars_decode($d['department']) ?></td>
                                <td style="text-align:center;padding:6px 2px;"><?= (int)$d['n'] ?></td>
                                <td style="text-align:center;padding:6px 2px;"><strong><?= number_format((float)$d['a'], 2) ?></strong></td>
                            </tr>
                            <?php endforeach; if (!$byDept): ?>
                            <tr><td colspan="3" style="text-align:center;color:#9CA3AF;">No finalized evaluations.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
            </div>
        </div>
        <div class="col-12 col-lg-7"><div class="card"><div class="card-title">Individual results</div><div class="table-wrapper"><table><thead><tr><th>Employee</th><th>Department</th><th>Period</th><th>Score</th><th>Level</th><th></th></tr></thead><tbody>
            <?php foreach ($list as $r): ?><tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= htmlspecialchars($r['department']) ?></td><td><?= htmlspecialchars($r['period_label']) ?></td><td><?= $r['total_score'] ?></td><td style="color:<?= performanceLevelColor($r['performance_level']) ?>;font-weight:600;"><?= htmlspecialchars($r['performance_level']) ?></td><td><a href="summary.php?type=performance&eval=<?= (int)$r['id'] ?>">View</a></td></tr><?php endforeach; ?>
            </tbody></table></div></div></div>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>
