<?php
// Employee dashboard - one page.
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/dashboard_ui.php';
requireRole('employee');

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$db = getDB();
payrollReleaseDue($db);
$empId = $_SESSION['employee_id'];
$first = explode(' ', trim($_SESSION['name'] ?? ''))[0] ?: 'there';
$hour  = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$one = function (string $sql, array $p = []) use ($db) { try { $s = $db->prepare($sql); $s->execute($p); return $s->fetchColumn(); } catch (Throwable $e) { return 0; } };

$present = (int)$one("SELECT COUNT(*) FROM attendance WHERE employee_id=? AND status='present' AND DATE_FORMAT(date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')", [$empId]);
$absent  = (int)$one("SELECT COUNT(*) FROM attendance WHERE employee_id=? AND status='absent' AND DATE_FORMAT(date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')", [$empId]);
$pendingLeaves = (int)$one("SELECT COUNT(*) FROM leave_requests WHERE employee_id=? AND status IN ('pending','approved_pending_documents','documents_submitted','documents_rejected')", [$empId]);

// latest payslip the employee is allowed to see (released, pay date reached, CEO approved)
$latestPay = null;
$st = $db->prepare("SELECT * FROM payroll WHERE employee_id=? AND status='released' ORDER BY payroll_period_end DESC LIMIT 6");
$st->execute([$empId]);
foreach ($st->fetchAll() as $cand) { if (payslipAvailable($cand)) { $latestPay = $cand; break; } }

// attendance - last 4 months
$mLabels = $mPresent = $mAbsent = [];
for ($i = 3; $i >= 0; $i--) {
    $k = date('Y-m', strtotime("first day of -$i month"));
    $mLabels[]  = date('M', strtotime($k . '-01'));
    $mPresent[] = (int)$one("SELECT COUNT(*) FROM attendance WHERE employee_id=? AND status='present' AND DATE_FORMAT(date,'%Y-%m')=?", [$empId, $k]);
    $mAbsent[]  = (int)$one("SELECT COUNT(*) FROM attendance WHERE employee_id=? AND status='absent' AND DATE_FORMAT(date,'%Y-%m')=?", [$empId, $k]);
}
if (array_sum($mPresent) + array_sum($mAbsent) === 0) { // nothing in the last 4 months: show the latest 4 months that have records
    $mLabels = $mPresent = $mAbsent = [];
    $st = $db->prepare("SELECT DATE_FORMAT(date,'%Y-%m') k, SUM(status='present') p, SUM(status='absent') a FROM attendance WHERE employee_id=? GROUP BY k ORDER BY k DESC LIMIT 4");
    $st->execute([$empId]);
    foreach (array_reverse($st->fetchAll()) as $r) { $mLabels[] = date('M', strtotime($r['k'] . '-01')); $mPresent[] = (int)$r['p']; $mAbsent[] = (int)$r['a']; }
}

// leave credits (remaining)
$year = (int)date('Y');
$st = $db->prepare("SELECT * FROM leave_balances WHERE employee_id=? AND year=?"); $st->execute([$empId, $year]);
$bal = $st->fetch() ?: ['vacation_total' => 15, 'vacation_used' => 0, 'sick_total' => 15, 'sick_used' => 0, 'emergency_total' => 5, 'emergency_used' => 0];
$cLabels = ['Vacation', 'Sick', 'Emergency'];
$cVals = [max(0, $bal['vacation_total'] - $bal['vacation_used']), max(0, $bal['sick_total'] - $bal['sick_used']), max(0, $bal['emergency_total'] - $bal['emergency_used'])];
$cShown = [$cVals[0] . ' / ' . $bal['vacation_total'], $cVals[1] . ' / ' . $bal['sick_total'], $cVals[2] . ' / ' . $bal['emergency_total']];

// today + recent attendance
$st = $db->prepare("SELECT * FROM attendance WHERE employee_id=? AND date=CURDATE()"); $st->execute([$empId]); $todayAtt = $st->fetch();
$st = $db->prepare("SELECT date, time_in, time_out, hours_worked, status FROM attendance WHERE employee_id=? ORDER BY date DESC LIMIT 4"); $st->execute([$empId]);
$attRows = [];
foreach ($st->fetchAll() as $a) {
    $attRows[] = ['<span>' . date('M j', strtotime($a['date'])) . '</span>', '<span class="muted">' . ($a['time_in'] ? formatTimeFull($a['time_in']) : '-') . '</span>', '<span class="muted">' . ($a['time_out'] ? formatTimeFull($a['time_out']) : '-') . '</span>', dBadge((string)($a['status'] ?: ($a['time_out'] ? 'present' : 'in_progress')))];
}
if ($todayAtt && $todayAtt['time_in']) {
    $todayHtml = '<div class="d-feature"><div><div class="t">Time in ' . dEsc(formatTimeFull($todayAtt['time_in'])) . '</div><div class="s">' . ($todayAtt['time_out'] ? 'Time out ' . dEsc(formatTimeFull($todayAtt['time_out'])) : 'Not timed out yet') . '</div></div>' . dBadge((string)$todayAtt['status']) . '</div>';
} else {
    $todayHtml = '<div class="d-feature"><div><div class="t">No record for today yet</div><div class="s">Record your attendance to start your day.</div></div><a class="d-link" href="attendance.php">Record</a></div>';
}
$attBody = $todayHtml . dTable(['Date', 'Time in', 'Time out', 'Status'], $attRows, 'No attendance records yet.', [22, 28, 28, 22]);

// payroll / leave card
$cut = currentCutoff();
$daysToPay = max(0, (int)floor((strtotime($cut['pay_date']) - strtotime(date('Y-m-d'))) / 86400));
$st = $db->prepare("SELECT id, leave_type, start_date, end_date, status FROM leave_requests WHERE employee_id=? ORDER BY created_at DESC, id DESC LIMIT 1"); $st->execute([$empId]); $lastLeave = $st->fetch();
$leaveLine = $lastLeave
    ? '<a href="leave.php" title="Open in Leave module">' . dBadge($lastLeave['status']) . '</a>'
    : '<span class="muted">No requests</span>';
$payBody = '<div class="d-feature"><div><div class="t">' . dEsc($cut['cutoff'] . ' cut-off') . '</div><div class="s">' . dEsc($cut['label']) . '</div></div><div class="t">' . ($daysToPay === 0 ? 'Today' : $daysToPay . ' days') . '</div></div>'
    . '<div class="d-note"><div class="row-i"><span>Salary release</span><b>' . date('M j, Y (D)', strtotime($cut['pay_date'])) . '</b></div>'
    . '<div class="row-i"><span>Latest net pay</span><b>' . ($latestPay ? dMoney($latestPay['net_salary']) : '-') . '</b></div>'
    . '<div class="row-i"><span>Last leave request</span><b>' . $leaveLine . ($lastLeave ? ' <span class="muted" style="font-weight:400">' . dEsc(ucfirst($lastLeave['leave_type'])) . ', ' . date('M j', strtotime($lastLeave['start_date'])) . '-' . date('j', strtotime($lastLeave['end_date'])) . '</span>' : '') . '</b></div></div>';

$activities = [];
$st = $db->prepare("SELECT action, created_at FROM audit_logs WHERE user_id=? ORDER BY id DESC LIMIT 6"); $st->execute([$empId]);
foreach ($st->fetchAll() as $a) $activities[] = [$a['action'], dAgo($a['created_at'])];

$cfg = [
    'title' => $greet . ', ' . $first . '!', 'sub' => 'Your attendance, pay and requests at a glance.',
    'kpis' => [
        dKpi('bx-check-circle', 'Present this month', (string)$present, 'Days', '', 'attendance.php'),
        dKpi('bx-x-circle', 'Absent this month', (string)$absent, 'Days', $absent ? 'down' : '', 'attendance.php'),
        dKpi('bx-calendar-exclamation', 'Pending leaves', (string)$pendingLeaves, 'Open in My Leave', '', 'leave.php'),
        dKpi('bx-money', 'Latest net pay', $latestPay ? dMoneyShort($latestPay['net_salary']) : '-', $latestPay ? date('M j', strtotime($latestPay['payroll_period_start'])) . ' - ' . date('M j', strtotime($latestPay['payroll_period_end'])) : 'No payslip yet', '', 'payroll.php'),
    ],
    'c1' => ['icon' => 'bx-bar-chart-alt-2', 'title' => 'Attendance - by month', 'link' => 'attendance.php', 'body' => dChartBody('ch1')],
    'c2' => ['icon' => 'bx-pie-chart-alt-2', 'title' => 'Leave credits ' . $year, 'link' => 'leave.php', 'linkLabel' => 'Open', 'body' => dDonutBody('ch2', $cLabels, $cShown)],
    'b1' => ['icon' => 'bx-time-five', 'title' => "Today's attendance", 'link' => 'attendance.php', 'linkLabel' => 'Open', 'body' => $attBody],
    'b2' => ['icon' => 'bx-wallet', 'title' => 'Payroll cut-off', 'link' => 'payroll.php', 'linkLabel' => 'My payslip', 'body' => $payBody],
    'rail' => [
        'actions' => [['bx-fingerprint', 'Record attendance', 'attendance.php'], ['bx-calendar-plus', 'Request leave', 'leave.php'], ['bx-receipt', 'View payslip', 'payroll.php'], ['bx-file', 'Request COE', 'requests.php']],
        'activities' => $activities, 'actTitle' => 'My recent activity', 'events' => dCommonEvents($db, 3),
    ],
    'charts' => [
        ['id' => 'ch1', 'type' => 'bar', 'thick' => 16, 'labels' => $mLabels, 'datasets' => [['label' => 'Present', 'data' => $mPresent, 'color' => '#CD1E27'], ['label' => 'Absent', 'data' => $mAbsent, 'color' => '#1A1D26']]],
        ['id' => 'ch2', 'type' => 'doughnut', 'labels' => $cLabels, 'datasets' => [['data' => $cVals]], 'center' => [(string)array_sum($cVals), 'Days left']],
    ],
];
include '../../includes/header.php';
dashRender($cfg);
echo '</div></div>';
