<?php
// ============================================================
// Team Manager — Team Dashboard / My Team (view-only)
// Team members' names, employee numbers, department, position, today's attendance,
// and the status of team leave requests. No payroll, salary or HR record is shown or editable.
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireTeamLeader();

$pageTitle  = 'My Team';
$activePage = 'team';
$db   = getDB();
$me   = $_SESSION['employee_id'];
$today = date('Y-m-d');
$members = getTeamMembers($me);

$att = $db->prepare("SELECT a.employee_id, a.time_in, a.time_out, a.status, a.late_minutes FROM attendance a
                     JOIN users u ON u.employee_id=a.employee_id JOIN users leader ON leader.employee_id=?
                     WHERE leader.department<>'' AND u.department=leader.department AND u.role='employee'
                       AND u.status='active' AND u.employee_id<>leader.employee_id AND a.date=?");
$att->execute([$me, $today]);
$todayAtt = [];
foreach ($att->fetchAll() as $a) $todayAtt[$a['employee_id']] = $a;

$lv = $db->prepare("SELECT lr.*, u.first_name, u.last_name FROM leave_requests lr JOIN users u ON u.employee_id=lr.employee_id
                    JOIN users leader ON leader.employee_id=? WHERE leader.department<>'' AND u.department=leader.department
                      AND u.role='employee' AND u.status='active' AND u.employee_id<>leader.employee_id
                    ORDER BY lr.created_at DESC LIMIT 8");
$lv->execute([$me]);
$recentLeaves = $lv->fetchAll();

$onLeaveToday = $db->prepare("SELECT DISTINCT lr.employee_id FROM leave_requests lr JOIN users u ON u.employee_id=lr.employee_id
    JOIN users leader ON leader.employee_id=? WHERE leader.department<>'' AND u.department=leader.department
      AND u.role='employee' AND u.status='active' AND u.employee_id<>leader.employee_id
      AND lr.status IN ('approved','approved_pending_documents','documents_submitted') AND ? BETWEEN lr.start_date AND lr.end_date");
$onLeaveToday->execute([$me, $today]);
$onLeave = $onLeaveToday->fetchAll(PDO::FETCH_COLUMN);

$awaiting = $db->prepare("SELECT COUNT(*) FROM leave_requests lr JOIN users u ON u.employee_id=lr.employee_id
    JOIN users leader ON leader.employee_id=? WHERE leader.department<>'' AND u.department=leader.department
      AND u.role='employee' AND u.status='active' AND u.employee_id<>leader.employee_id
      AND lr.status='pending' AND lr.endorsement_status='none'");
$awaiting->execute([$me]);
$awaitingCount = (int)$awaiting->fetchColumn();

$present = count(array_filter($members, fn($m) => isset($todayAtt[$m['employee_id']]) && $todayAtt[$m['employee_id']]['time_in']));
$meRow = $db->prepare("SELECT department, position FROM users WHERE employee_id=?"); $meRow->execute([$me]); $mine = $meRow->fetch();

include '../../includes/header.php';
?>
<div class="page-body">
<div class="card" style="border-left:4px solid #CD1E27;">
  <div style="font-weight:700;">Team Manager — <?= htmlspecialchars($mine['position'] ?? '') ?></div>
  <div style="font-size:12px;color:#6B7280;"><?= htmlspecialchars($mine['department'] ?? '') ?> · you are an employee with a Team Manager designation. You can review your team's leave requests before HR's final decision.</div>
</div>

<div class="row g-3 mb-3">
  <?php foreach ([['Team Members', count($members), 'blue'], ['Present Today', $present, 'green'], ['On Leave Today', count($onLeave), 'amber'], ['Awaiting My Review', $awaitingCount, 'red']] as [$l, $v, $c]): ?>
  <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-value"><?= $v ?></div><div class="stat-label"><?= $l ?></div></div></div>
  <?php endforeach; ?>
</div>
<?php if ($awaitingCount): ?><div class="alert alert-success" style="background:#FFFBEB;border-color:#FCD34D;color:#92400E;">You have <?= $awaitingCount ?> leave request(s) waiting for your review. <a href="team_leave.php?status=awaiting">Review now</a></div><?php endif; ?>

<div class="card"><div class="card-title">Team Members (<?= count($members) ?>)</div>
<div class="table-wrapper"><table>
  <thead><tr><th>Employee No.</th><th>Name</th><th>Department</th><th>Position</th><th>Today</th></tr></thead><tbody>
  <?php foreach ($members as $m): $a = $todayAtt[$m['employee_id']] ?? null; $isLeave = in_array($m['employee_id'], $onLeave, true); ?>
  <tr><td style="font-family:monospace;"><?= htmlspecialchars($m['employee_id']) ?></td>
      <td style="font-weight:600;"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></td>
      <td><?= htmlspecialchars($m['department']) ?></td><td><?= htmlspecialchars($m['position']) ?></td>
      <td><?php if ($isLeave): ?><span class="badge badge-amber">On leave</span>
          <?php elseif ($a && $a['time_in']): ?><span class="badge <?= (int)$a['late_minutes'] > 0 ? 'badge-amber' : 'badge-green' ?>"><?= (int)$a['late_minutes'] > 0 ? 'Late' : 'Present' ?></span> <span style="font-size:11px;color:#6B7280;"><?= date('h:i:s A', strtotime($a['time_in'])) ?></span>
          <?php else: ?><span class="badge badge-gray">No time-in yet</span><?php endif; ?></td></tr>
  <?php endforeach; if (!$members): ?><tr><td colspan="5" style="text-align:center;color:#9CA3AF;padding:24px;">No team members are assigned to you yet. HR assigns the department's employees to the Team Manager.</td></tr><?php endif; ?>
</tbody></table></div></div>

<div class="card"><div class="card-title">Recent Team Leave Requests <a href="team_leave.php" style="font-size:12px;font-weight:500;float:right;">View all</a></div>
<div class="table-wrapper"><table><thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th>Team Manager decision</th><th>HR Status</th></tr></thead><tbody>
<?php foreach ($recentLeaves as $r): ?><tr><td><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></td><td><?= htmlspecialchars(ucfirst(str_replace('_',' ',$r['leave_type']))) ?></td>
<td style="font-size:12px;"><?= formatDate($r['start_date']) ?> – <?= formatDate($r['end_date']) ?></td>
<td><?= $r['endorsement_status']==='none' ? '<span class="badge badge-amber">Awaiting you</span>' : ($r['endorsement_status']==='endorsed' ? '<span class="badge badge-green">Approved by Team Manager</span>' : '<span class="badge badge-red">Rejected</span>') ?></td>
<td><span class="badge <?= statusBadge($r['status']) ?>"><?= htmlspecialchars(formatLeaveStatus($r['status'])) ?></span></td></tr>
<?php endforeach; if (!$recentLeaves): ?><tr><td colspan="5" style="text-align:center;color:#9CA3AF;padding:18px;">No team leave requests yet.</td></tr><?php endif; ?>
</tbody></table></div></div>
</div>
