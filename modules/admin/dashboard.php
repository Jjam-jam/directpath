<?php
// HR Admin / Payroll Manager dashboard - one page, role-aware.
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/dashboard_ui.php';
requireRole('hr_admin', 'payroll_manager', 'ceo');

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$db   = getDB();
$role = $_SESSION['role'];
$isPayroll = in_array($role, ['payroll_manager', 'ceo'], true);
$first = explode(' ', trim($_SESSION['name'] ?? ''))[0] ?: 'there';
$hour  = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$one = fn(string $sql, array $p = []) => (function () use ($db, $sql, $p) { try { $s = $db->prepare($sql); $s->execute($p); return $s->fetchColumn(); } catch (Throwable $e) { return 0; } })();

$effRole = "COALESCE(r.role_name, CASE u.role WHEN 'hr_admin' THEN 'hr_admin' WHEN 'admin' THEN 'hr_admin' WHEN 'payroll_manager' THEN 'payroll_manager' WHEN 'payroll' THEN 'payroll_manager' END)";
$empJoin = "FROM users u LEFT JOIN user_roles ur ON ur.employee_id=u.employee_id LEFT JOIN roles r ON r.id=ur.role_id WHERE $effRole IN ('employee','payroll_manager','hr_admin') AND u.status='active'";
$totalEmployees = (int)$one("SELECT COUNT(*) $empJoin");
$newHires       = (int)$one("SELECT COUNT(*) $empJoin AND DATE_FORMAT(u.date_hired,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')");
$cut = currentCutoff();
$apr = getPayrollApproval($db, $cut['start'], $cut['end']);
$daysToPay = max(0, (int)floor((strtotime($cut['pay_date']) - strtotime(date('Y-m-d'))) / 86400));

// Activity feed = system audit trail. Audit data is for the CEO and Super Admin only,
// so HR Admin and Payroll Manager do not get the feed.
$activities = [];
$showActivity = ($role === 'ceo');
if ($showActivity) {
    try {
        foreach ($db->query("SELECT user_name, action, created_at FROM audit_logs ORDER BY id DESC LIMIT 6")->fetchAll() as $a) {
            $activities[] = [$a['user_name'] . ': ' . $a['action'], dAgo($a['created_at'])];
        }
    } catch (Throwable $e) {}
}
$events = dCommonEvents($db, 3);

if (!$isPayroll) {
    // ------------------------------ HR ADMIN ------------------------------
    $pendingLeaves = (int)$one("SELECT COUNT(*) FROM leave_requests WHERE status IN ('pending','documents_submitted') OR documents_flagged_at IS NOT NULL");
    $loanReview    = (int)$one("SELECT COUNT(*) FROM loan_disclosures WHERE status IN ('for_review','needs_info')");
    $presentToday  = (int)$one("SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE date=CURDATE() AND status='present'");
    $rate = $totalEmployees ? round($presentToday / $totalEmployees * 100) : 0;

    $labels = $present = $late = [];
    // the 7 most recent days that actually have attendance records
    $recentDays = array_reverse($db->query("SELECT DISTINCT date FROM attendance WHERE date <= CURDATE() ORDER BY date DESC LIMIT 7")->fetchAll(PDO::FETCH_COLUMN));
    foreach ($recentDays as $d) {
        $labels[]  = date('M j', strtotime($d));
        $present[] = (int)$one("SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE date=? AND status='present'", [$d]);
        $late[]    = (int)$one("SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE date=? AND status='present' AND late_minutes>0", [$d]);
    }
    $depts = $db->query("SELECT u.department d, COUNT(*) c $empJoin AND u.department<>'' GROUP BY u.department ORDER BY c DESC")->fetchAll();
    $dLabels = array_column($depts, 'd'); $dVals = array_map('intval', array_column($depts, 'c'));
    if (count($dLabels) > 6) { $rest = array_sum(array_slice($dVals, 5)); $dLabels = array_slice($dLabels, 0, 5); $dVals = array_slice($dVals, 0, 5); $dLabels[] = 'Others'; $dVals[] = $rest; }

    $leaveRows = [];
    foreach ($db->query("SELECT lr.leave_type, lr.start_date, lr.end_date, lr.status, u.first_name, u.last_name FROM leave_requests lr JOIN users u ON u.employee_id=lr.employee_id ORDER BY lr.created_at DESC, lr.id DESC LIMIT 4")->fetchAll() as $l) {
        $leaveRows[] = [dEsc($l['first_name'] . ' ' . $l['last_name']), dEsc(ucfirst($l['leave_type'])), '<span class="muted">' . date('M j', strtotime($l['start_date'])) . '-' . date('j', strtotime($l['end_date'])) . '</span>', dBadge($l['status'])];
    }
    $payBody = '<div class="d-feature"><div><div class="t">' . dEsc($cut['cutoff'] . ' cut-off') . '</div><div class="s">' . dEsc($cut['label']) . '</div></div>' . dBadge($daysToPay === 0 ? 'pending' : 'generated') . '</div>'
        . '<div class="d-note"><div class="row-i"><span>Salary release</span><b>' . date('M j, Y (D)', strtotime($cut['pay_date'])) . '</b></div>'
        . '<div class="row-i"><span>Days to release</span><b>' . $daysToPay . '</b></div>'
        . '<div class="row-i"><span>CEO approval</span><b>' . dEsc($apr ? ucwords(str_replace('_', ' ', $apr['status'])) : 'Not submitted') . '</b></div>'
        . '<div class="row-i"><span>Active employees</span><b>' . $totalEmployees . '</b></div></div>';

    $cfg = [
        'title' => $greet . ', ' . $first . '!', 'sub' => 'Here is what is happening with your team today.',
        'kpis' => [
            dKpi('bx-group', 'Total employees', (string)$totalEmployees, $newHires . ' hired this month', $newHires ? 'up' : '', 'employees.php'),
            dKpi('bx-calendar-exclamation', 'Pending leaves', (string)$pendingLeaves, 'Awaiting review', '', 'leave.php'),
            dKpi('bx-time-five', 'Attendance today', $rate . '%', $presentToday . ' of ' . $totalEmployees . ' present', $rate >= 90 ? 'up' : '', 'attendance.php'),
            dKpi('bx-credit-card', 'Loan documents', (string)$loanReview, 'Awaiting review', '', 'loan_review.php'),
        ],
        'c1' => ['icon' => 'bx-bar-chart-alt-2', 'title' => 'Attendance - recent days', 'link' => 'attendance.php', 'body' => dChartBody('ch1')],
        'c2' => ['icon' => 'bx-pie-chart-alt-2', 'title' => 'Employees by department', 'body' => dDonutBody('ch2', $dLabels, $dVals)],
        'b1' => ['icon' => 'bx-calendar-check', 'title' => 'Recent leave requests', 'link' => 'leave.php', 'body' => dTable(['Employee', 'Type', 'Dates', 'Status'], $leaveRows, 'No leave requests yet.', [30, 18, 28, 24])],
        'b2' => ['icon' => 'bx-wallet', 'title' => 'Upcoming payroll', 'body' => $payBody],
        'rail' => [
            'actions' => [['bx-user-plus', 'Employee management', 'employees.php'], ['bx-calendar-check', 'Review leave', 'leave.php'], ['bx-file', 'Requests', 'requests.php'], ['bx-bar-chart-alt-2', 'Reports', 'reports.php']],
            'activities' => $activities, 'noActivity' => !$showActivity, 'actTitle' => 'Recent activity', 'events' => $events,
        ],
        'charts' => [
            ['id' => 'ch1', 'type' => 'bar', 'labels' => $labels, 'datasets' => [['label' => 'Present', 'data' => $present, 'color' => '#CD1E27'], ['label' => 'Late', 'data' => $late, 'color' => '#F4B6BA']]],
            ['id' => 'ch2', 'type' => 'doughnut', 'labels' => $dLabels, 'datasets' => [['data' => $dVals]], 'center' => [(string)array_sum($dVals), 'Employees']],
        ],
    ];
} else {
    // --------------------------- PAYROLL MANAGER ---------------------------
    $periods = $db->query("SELECT payroll_period_start s, payroll_period_end e, SUM(gross_pay) g, SUM(net_salary) n, SUM(total_deductions) d, COUNT(*) c
                           FROM payroll GROUP BY payroll_period_start, payroll_period_end ORDER BY payroll_period_end DESC LIMIT 6")->fetchAll();
    $latest = $periods[0] ?? ['s' => $cut['start'], 'e' => $cut['end'], 'g' => 0, 'n' => 0, 'd' => 0, 'c' => 0];
    $periods = array_reverse($periods);
    $pLabels = array_map(fn($p) => date('M j', strtotime($p['s'])) . '-' . date('j', strtotime($p['e'])), $periods);
    $pending = (int)$one("SELECT COUNT(*) FROM payroll_approvals WHERE status='pending_ceo'");
    $sum = function (string $col) use ($db, $latest) { $s = $db->prepare("SELECT COALESCE(SUM($col),0) FROM payroll WHERE payroll_period_start=? AND payroll_period_end=?"); $s->execute([$latest['s'], $latest['e']]); return (float)$s->fetchColumn(); };
    $dedLabels = ['SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding tax', 'Loans', 'Other'];
    $dedVals = [$sum('sss'), $sum('philhealth'), $sum('pagibig'), $sum('withholding_tax'), $sum('loan_deduction'), max(0, (float)$latest['d'] - $sum('sss') - $sum('philhealth') - $sum('pagibig') - $sum('withholding_tax') - $sum('loan_deduction'))];
    $dedShown = array_map(fn($v) => dMoneyShort($v), $dedVals);

    $recent = [];
    foreach ($db->query("SELECT p.payroll_period_start s, p.payroll_period_end e, p.net_salary, p.status, u.first_name, u.last_name FROM payroll p JOIN users u ON u.employee_id=p.employee_id ORDER BY p.id DESC LIMIT 4")->fetchAll() as $r) {
        $recent[] = [dEsc($r['first_name'] . ' ' . $r['last_name']), '<span class="muted">' . date('M j', strtotime($r['s'])) . '-' . date('j', strtotime($r['e'])) . '</span>', dMoney($r['net_salary']), dBadge($r['status'])];
    }
    $payBody = '<div class="d-feature"><div><div class="t">' . dEsc($cut['cutoff'] . ' cut-off') . '</div><div class="s">' . dEsc($cut['label']) . '</div></div>' . dBadge($apr ? $apr['status'] : 'pending') . '</div>'
        . '<div class="d-note"><div class="row-i"><span>Salary release</span><b>' . date('M j, Y (D)', strtotime($cut['pay_date'])) . '</b></div>'
        . '<div class="row-i"><span>Latest gross pay</span><b>' . dMoney($latest['g']) . '</b></div>'
        . '<div class="row-i"><span>Latest deductions</span><b>' . dMoney($latest['d']) . '</b></div>'
        . '<div class="row-i"><span>Latest net pay</span><b>' . dMoney($latest['n']) . '</b></div></div>';

    $cfg = [
        'title' => $greet . ', ' . $first . '!', 'sub' => 'Payroll overview for the latest processed period.',
        'kpis' => [
            dKpi('bx-money', 'Gross payroll', dMoneyShort($latest['g']), (int)$latest['c'] . ' employees paid', '', 'payroll.php'),
            dKpi('bx-wallet', 'Net pay', dMoneyShort($latest['n']), date('M j', strtotime($latest['s'])) . ' - ' . date('M j', strtotime($latest['e'])), '', 'payroll.php'),
            dKpi('bx-receipt', 'Total deductions', dMoneyShort($latest['d']), 'Contributions, tax, loans', '', 'government_contributions.php'),
            dKpi('bx-hourglass', 'Awaiting CEO approval', (string)$pending, 'Payroll periods', $pending ? 'down' : '', 'payroll.php'),
        ],
        'c1' => ['icon' => 'bx-bar-chart-alt-2', 'title' => 'Payroll overview - gross vs net', 'link' => 'payroll.php', 'body' => dChartBody('ch1')],
        'c2' => ['icon' => 'bx-pie-chart-alt-2', 'title' => 'Deductions breakdown', 'body' => dDonutBody('ch2', $dedLabels, $dedShown)],
        'b1' => ['icon' => 'bx-list-ul', 'title' => 'Recent payroll records', 'link' => 'payroll.php', 'body' => dTable(['Employee', 'Period', 'Net pay', 'Status'], $recent, 'No payroll generated yet.', [30, 22, 26, 22])],
        'b2' => ['icon' => 'bx-wallet', 'title' => 'Upcoming payroll', 'body' => $payBody],
        'rail' => [
            'actions' => [['bx-cog', 'Generate payroll', 'payroll.php'], ['bx-shield', 'Government contributions', 'government_contributions.php'], ['bx-file', 'Payslip requests', 'requests.php'], ['bx-bar-chart-alt-2', 'Reports', 'reports.php']],
            'activities' => $activities, 'noActivity' => !$showActivity, 'actLink' => $showActivity ? '../ceo/audit.php' : '', 'actTitle' => 'Recent activity', 'events' => $events,
        ],
        'charts' => [
            ['id' => 'ch1', 'type' => 'bar', 'money' => true, 'labels' => $pLabels, 'datasets' => [['label' => 'Gross pay', 'data' => array_map(fn($p) => round((float)$p['g'], 2), $periods), 'color' => '#CD1E27'], ['label' => 'Net pay', 'data' => array_map(fn($p) => round((float)$p['n'], 2), $periods), 'color' => '#F4B6BA']]],
            ['id' => 'ch2', 'type' => 'doughnut', 'labels' => $dedLabels, 'datasets' => [['data' => array_map(fn($v) => round($v, 2), $dedVals)]], 'center' => [dMoneyShort(array_sum($dedVals)), 'Deductions']],
        ],
    ];
}

include '../../includes/header.php';
dashRender($cfg);
echo '</div></div>';
