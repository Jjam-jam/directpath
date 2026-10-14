<?php
// CEO dashboard - one page.
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/dashboard_ui.php';
requireRole('ceo');

$pageTitle  = 'CEO Dashboard';
$activePage = 'dashboard';
$db = getDB();
$first = explode(' ', trim($_SESSION['name'] ?? ''))[0] ?: 'there';
$hour  = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$one = function (string $sql, array $p = []) use ($db) { try { $s = $db->prepare($sql); $s->execute($p); return $s->fetchColumn(); } catch (Throwable $e) { return 0; } };

$empJoin = "FROM users u JOIN user_roles ur ON ur.employee_id=u.employee_id JOIN roles r ON r.id=ur.role_id WHERE r.role_name='employee' AND u.status='active'";
$headcount = (int)$one("SELECT COUNT(*) $empJoin");
$pendingApprovals = (int)$one("SELECT COUNT(*) FROM payroll_approvals WHERE status='pending_ceo'");
$periods = $db->query("SELECT payroll_period_start s, payroll_period_end e, SUM(gross_pay) g, SUM(net_salary) n, SUM(total_deductions) d, COUNT(*) c FROM payroll GROUP BY payroll_period_start, payroll_period_end ORDER BY payroll_period_end DESC LIMIT 6")->fetchAll();
$latest  = $periods[0] ?? ['s' => date('Y-m-01'), 'e' => date('Y-m-15'), 'g' => 0, 'n' => 0, 'd' => 0, 'c' => 0];
$periods = array_reverse($periods);
$pLabels = array_map(fn($p) => date('M j', strtotime($p['s'])) . '-' . date('j', strtotime($p['e'])), $periods);
$pres = (int)$one("SELECT COUNT(*) FROM attendance WHERE status='present' AND DATE_FORMAT(date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')");
$abs  = (int)$one("SELECT COUNT(*) FROM attendance WHERE status='absent' AND DATE_FORMAT(date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')");
if ($pres + $abs === 0) { // no records this month yet: use the most recent month that has records
    $m = $one("SELECT DATE_FORMAT(MAX(date),'%Y-%m') FROM attendance");
    $pres = (int)$one("SELECT COUNT(*) FROM attendance WHERE status='present' AND DATE_FORMAT(date,'%Y-%m')=?", [$m]);
    $abs  = (int)$one("SELECT COUNT(*) FROM attendance WHERE status='absent' AND DATE_FORMAT(date,'%Y-%m')=?", [$m]);
    $attNote = $m ? date('F Y', strtotime($m . '-01')) : 'No records';
} else { $attNote = date('F Y'); }
$attRate = ($pres + $abs) ? round($pres / ($pres + $abs) * 100) : 0;

$depts = $db->query("SELECT u.department d, COUNT(*) c $empJoin AND u.department<>'' GROUP BY u.department ORDER BY c DESC")->fetchAll();
$dLabels = array_column($depts, 'd'); $dVals = array_map('intval', array_column($depts, 'c'));
if (count($dLabels) > 6) { $rest = array_sum(array_slice($dVals, 5)); $dLabels = array_slice($dLabels, 0, 5); $dVals = array_slice($dVals, 0, 5); $dLabels[] = 'Others'; $dVals[] = $rest; }

$rows = [];
foreach ($db->query("SELECT period_start, period_end, employee_count, net_total, submitted_by_name, status FROM payroll_approvals ORDER BY (status='pending_ceo') DESC, submitted_at DESC LIMIT 4")->fetchAll() as $a) {
    $rows[] = ['<span>' . date('M j', strtotime($a['period_start'])) . '-' . date('j', strtotime($a['period_end'])) . '</span>', (int)$a['employee_count'], dMoney($a['net_total']), dBadge($a['status'])];
}
$leaveRows = [];
foreach ($db->query("SELECT lr.leave_type, lr.start_date, lr.end_date, lr.status, u.first_name, u.last_name FROM leave_requests lr JOIN users u ON u.employee_id=lr.employee_id ORDER BY lr.created_at DESC, lr.id DESC LIMIT 4")->fetchAll() as $l) {
    $leaveRows[] = [dEsc($l['first_name'] . ' ' . $l['last_name']), dEsc(ucfirst($l['leave_type'])), '<span class="muted">' . date('M j', strtotime($l['start_date'])) . '-' . date('j', strtotime($l['end_date'])) . '</span>', dBadge($l['status'])];
}
$activities = [];
try { foreach ($db->query("SELECT user_name, action, created_at FROM audit_logs ORDER BY id DESC LIMIT 6")->fetchAll() as $a) $activities[] = [$a['user_name'] . ': ' . $a['action'], dAgo($a['created_at'])]; } catch (Throwable $e) {}

$cfg = [
    'title' => $greet . ', ' . $first . '!', 'sub' => 'Company overview and items waiting for your approval.',
    'kpis' => [
        dKpi('bx-group', 'Headcount', (string)$headcount, 'Active employees', '', 'summary.php?type=employees'),
        dKpi('bx-wallet', 'Net payroll', dMoneyShort($latest['n']), date('M j', strtotime($latest['s'])) . ' - ' . date('M j', strtotime($latest['e'])), '', 'summary.php?type=payroll'),
        dKpi('bx-hourglass', 'Pending approvals', (string)$pendingApprovals, 'Payroll periods', $pendingApprovals ? 'down' : '', 'payroll_approval.php'),
        dKpi('bx-time-five', 'Attendance rate', $attRate . '%', $attNote, $attRate >= 90 ? 'up' : '', 'summary.php?type=attendance'),
    ],
    'c1' => ['icon' => 'bx-bar-chart-alt-2', 'title' => 'Payroll overview - gross vs net', 'link' => 'reports.php', 'body' => dChartBody('ch1')],
    'c2' => ['icon' => 'bx-pie-chart-alt-2', 'title' => 'Headcount by department', 'body' => dDonutBody('ch2', $dLabels, $dVals)],
    'b1' => ['icon' => 'bx-check-shield', 'title' => 'Payroll approvals', 'link' => 'payroll_approval.php', 'body' => dTable(['Period', 'Staff', 'Net', 'Status'], $rows, 'No payroll submitted yet.', [26, 14, 32, 28])],
    'b2' => ['icon' => 'bx-calendar-check', 'title' => 'Recent leave requests', 'link' => 'summary.php?type=leave', 'body' => dTable(['Employee', 'Type', 'Dates', 'Status'], $leaveRows, 'No leave requests yet.', [30, 18, 26, 26])],
    'rail' => [
        'actions' => [['bx-check-shield', 'Payroll approval', 'payroll_approval.php'], ['bx-bar-chart-alt-2', 'Reports', 'reports.php'], ['bx-shield', 'Statutory reports', 'statutory.php'], ['bx-trophy', 'Performance summary', 'summary.php?type=performance']],
        'activities' => $activities, 'actLink' => 'audit.php', 'events' => dCommonEvents($db, 3),
    ],
    'charts' => [
        ['id' => 'ch1', 'type' => 'bar', 'money' => true, 'labels' => $pLabels, 'datasets' => [['label' => 'Gross pay', 'data' => array_map(fn($p) => round((float)$p['g'], 2), $periods), 'color' => '#CD1E27'], ['label' => 'Net pay', 'data' => array_map(fn($p) => round((float)$p['n'], 2), $periods), 'color' => '#F4B6BA']]],
        ['id' => 'ch2', 'type' => 'doughnut', 'labels' => $dLabels, 'datasets' => [['data' => $dVals]], 'center' => [(string)array_sum($dVals), 'Employees']],
    ],
];
include '../../includes/header.php';
dashRender($cfg);
echo '</div></div>';
