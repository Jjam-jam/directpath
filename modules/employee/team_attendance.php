<?php
// Team Manager — Team Attendance (view-only, own team only)
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireTeamLeader();

$pageTitle  = 'Team Attendance';
$activePage = 'team_attendance';
$db = getDB();
$me = $_SESSION['employee_id'];

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$memberStmt = $db->prepare("SELECT employee_id, first_name, middle_name, last_name, department, position, email, status
                            FROM users
                            WHERE department = (SELECT department FROM users WHERE employee_id = ?)
                              AND department <> '' AND employee_id <> ? AND role = 'employee' AND status = 'active'
                            ORDER BY last_name, first_name");
$memberStmt->execute([$me, $me]);
$members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
$memberIds = array_fill_keys(array_column($members, 'employee_id'), true);
$sel = sanitize($_GET['emp'] ?? '');
if ($sel !== '' && !isset($memberIds[$sel])) { $sel = ''; }   // cannot peek outside the department

$summary = [];
foreach ($members as $m) $summary[$m['employee_id']] = attendanceSummary($db, $m['employee_id'], $from, $to);

$q = "SELECT a.*, u.first_name, u.last_name FROM attendance a JOIN users u ON u.employee_id=a.employee_id
      JOIN users leader ON leader.employee_id=?
      WHERE leader.department<>'' AND u.department=leader.department AND u.role='employee' AND u.status='active' AND u.employee_id<>? AND a.date BETWEEN ? AND ?";
$p = [$me, $me, $from, $to];
if ($sel !== '') { $q .= " AND a.employee_id=?"; $p[] = $sel; }
$q .= " ORDER BY a.date DESC, u.last_name LIMIT 300";
$st = $db->prepare($q); $st->execute($p); $records = $st->fetchAll();

include '../../includes/header.php';
?>
<div class="page-body">
<div class="card"><form method="GET" class="filter-bar" style="margin:0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <input type="date" name="from" value="<?= $from ?>" class="form-input" style="width:auto;"> <span>to</span>
  <input type="date" name="to" value="<?= $to ?>" class="form-input" style="width:auto;">
  <select name="emp" class="form-select" style="width:auto;"><option value="">All team members</option>
    <?php foreach ($members as $m): ?><option value="<?= htmlspecialchars($m['employee_id']) ?>" <?= $sel===$m['employee_id']?'selected':'' ?>><?= htmlspecialchars($m['first_name'].' '.$m['last_name']) ?></option><?php endforeach; ?></select>
  <button class="btn-primary" type="submit">Filter</button></form></div>

<div class="card"><div class="card-title">Attendance Summary (<?= formatDate($from) ?> – <?= formatDate($to) ?>)</div>
<div class="table-wrapper"><table><thead><tr><th>Emp No.</th><th>Name</th><th>Working days</th><th>Present</th><th>Attendance %</th><th>Late</th><th>Undertime</th><th>Absent</th></tr></thead><tbody>
<?php foreach ($members as $m): $s = $summary[$m['employee_id']]; ?><tr><td style="font-family:monospace;"><?= htmlspecialchars($m['employee_id']) ?></td><td><?= htmlspecialchars($m['first_name'].' '.$m['last_name']) ?></td>
<td><?= $s['working_days'] ?></td><td><?= $s['present'] ?></td><td><?= number_format($s['pct'],1) ?>%</td><td><?= $s['late'] ?></td><td><?= $s['undertime'] ?></td><td><?= $s['absent'] ?></td></tr>
<?php endforeach; if (!$members): ?><tr><td colspan="8" style="text-align:center;color:#9CA3AF;padding:20px;">No team members assigned.</td></tr><?php endif; ?></tbody></table></div></div>

<div class="card"><div class="card-title">Daily Records (<?= count($records) ?>)</div>
<div class="table-wrapper"><table><thead><tr><th>Date</th><th>Employee</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Late (min)</th><th>Undertime (min)</th><th>Status</th></tr></thead><tbody>
<?php foreach ($records as $r): ?><tr><td><?= date('M j, Y — l', strtotime($r['date'])) ?></td><td><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?> <span style="font-size:11px;color:#9CA3AF;"><?= htmlspecialchars($r['employee_id']) ?></span></td>
<td><?= $r['time_in'] ? date('h:i:s A', strtotime($r['time_in'])) : '—' ?></td><td><?= $r['time_out'] ? date('h:i:s A', strtotime($r['time_out'])) : '—' ?></td>
<td><?= $r['hours_worked'] !== null ? number_format((float)$r['hours_worked'], 2) : '—' ?></td><td><?= (int)$r['late_minutes'] ?></td><td><?= (int)$r['undertime_mins'] ?></td>
<td><span class="badge <?= statusBadge($r['status']) ?>"><?= ucfirst($r['status']) ?></span></td></tr>
<?php endforeach; if (!$records): ?><tr><td colspan="8" style="text-align:center;color:#9CA3AF;padding:20px;">No attendance records in this range.</td></tr><?php endif; ?></tbody></table></div></div>
</div>
