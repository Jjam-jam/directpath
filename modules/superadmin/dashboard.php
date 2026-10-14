<?php
// Superadmin dashboard - one page.
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/dashboard_ui.php';
requireRole('superadmin');

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$db = getDB();
$first = explode(' ', trim($_SESSION['name'] ?? ''))[0] ?: 'there';
$hour  = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$one = function (string $sql, array $p = []) use ($db) { try { $s = $db->prepare($sql); $s->execute($p); return $s->fetchColumn(); } catch (Throwable $e) { return 0; } };

$totalUsers = (int)$one("SELECT COUNT(*) FROM users WHERE status='active'");
$adminAccts = (int)$one("SELECT COUNT(DISTINCT u.employee_id) FROM users u JOIN user_roles ur ON ur.employee_id=u.employee_id JOIN roles r ON r.id=ur.role_id WHERE r.role_name<>'employee' AND u.status='active'");
$openTickets = (int)$one("SELECT COUNT(*) FROM bug_tickets WHERE status NOT IN ('resolved','closed')");
$eventsToday = (int)$one("SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at)=CURDATE()");

$labels = $vals = [];
foreach ($db->query("SELECT DATE(created_at) d, COUNT(*) c FROM audit_logs GROUP BY DATE(created_at) ORDER BY d DESC LIMIT 7")->fetchAll() as $r) { array_unshift($labels, date('M j', strtotime($r['d']))); array_unshift($vals, (int)$r['c']); }
$roles = $db->query("SELECT r.role_name n, COUNT(DISTINCT u.employee_id) c FROM roles r JOIN user_roles ur ON ur.role_id=r.id JOIN users u ON u.employee_id=ur.employee_id WHERE u.status='active' GROUP BY r.role_name ORDER BY c DESC")->fetchAll();
$rLabels = array_map(fn($r) => ucwords(str_replace('_', ' ', $r['n'])), $roles); $rVals = array_map(fn($r) => (int)$r['c'], $roles);

$logRows = [];
foreach ($db->query("SELECT user_name, action, module, created_at FROM audit_logs ORDER BY id DESC LIMIT 5")->fetchAll() as $a) {
    $logRows[] = [dEsc($a['user_name']), dEsc($a['action']), '<span class="muted">' . dEsc($a['module']) . '</span>', '<span class="muted">' . dEsc(dAgo($a['created_at'])) . '</span>'];
}
$ticketRows = [];
foreach ($db->query("SELECT title, priority, status FROM bug_tickets ORDER BY (status IN ('resolved','closed')), id DESC LIMIT 4")->fetchAll() as $t) {
    $ticketRows[] = [dEsc($t['title']), '<span class="muted">' . dEsc(ucfirst((string)$t['priority'])) . '</span>', dBadge((string)$t['status'])];
}
$activities = [];
foreach ($db->query("SELECT user_name, action, created_at FROM audit_logs ORDER BY id DESC LIMIT 6")->fetchAll() as $a) $activities[] = [$a['user_name'] . ': ' . $a['action'], dAgo($a['created_at'])];

$cfg = [
    'title' => $greet . ', ' . $first . '!', 'sub' => 'System-wide overview.',
    'kpis' => [
        dKpi('bx-group', 'Active users', (string)$totalUsers, 'All accounts', '', 'admins.php'),
        dKpi('bx-shield-quarter', 'Admin accounts', (string)$adminAccts, 'HR, payroll, CEO, superadmin', '', 'admins.php'),
        dKpi('bx-bug', 'Open tickets', (string)$openTickets, 'Reported issues', $openTickets ? 'down' : '', 'tickets.php'),
        dKpi('bx-history', 'Events today', (string)$eventsToday, 'Audit trail entries', '', 'logs.php'),
    ],
    'c1' => ['icon' => 'bx-bar-chart-alt-2', 'title' => 'System activity - recent days', 'link' => 'logs.php', 'body' => dChartBody('ch1')],
    'c2' => ['icon' => 'bx-pie-chart-alt-2', 'title' => 'Users by role', 'body' => dDonutBody('ch2', $rLabels, $rVals)],
    'b1' => ['icon' => 'bx-bug', 'title' => 'Support tickets', 'link' => 'tickets.php', 'body' => dTable(['Issue', 'Priority', 'Status'], $ticketRows, 'No tickets.', [52, 22, 26])],
    'b2' => ['icon' => 'bx-list-ul', 'title' => 'Latest audit entries', 'link' => 'logs.php', 'body' => dTable(['User', 'Action', 'Module', 'When'], $logRows, 'No entries.', [26, 34, 20, 20])],
    'rail' => [
        'actions' => [['bx-user-plus', 'User management', 'admins.php'], ['bx-bug', 'Tickets', 'tickets.php'], ['bx-cog', 'System settings', 'settings.php'], ['bx-bar-chart-alt-2', 'Reports', '../admin/reports.php']],
        'activities' => $activities, 'actLink' => 'logs.php', 'events' => dCommonEvents($db, 3),
    ],
    'charts' => [
        ['id' => 'ch1', 'type' => 'bar', 'labels' => $labels, 'datasets' => [['label' => 'Events', 'data' => $vals, 'color' => '#CD1E27']]],
        ['id' => 'ch2', 'type' => 'doughnut', 'labels' => $rLabels, 'datasets' => [['data' => $rVals]], 'center' => [(string)array_sum($rVals), 'Roles']],
    ],
];
include '../../includes/header.php';
dashRender($cfg);
echo '</div></div>';
