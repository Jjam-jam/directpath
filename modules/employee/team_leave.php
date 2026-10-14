<?php
// ============================================================
// Team Manager — Team Leave Management
// Employee -> TEAM LEADER (endorse / coordinate) -> HR/Admin (approve) -> balance updated.
// The Team Manager never approves leave and never edits official balances.
// leave_endorsements.php includes this file with $teamLeaveMode = 'endorsements'.
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once __DIR__ . '/leave_rules.php';
requireTeamLeader();

$mode       = $teamLeaveMode ?? 'all';
$pageTitle  = 'Team Leave Management';
$activePage = 'team_leave';
$db   = getDB();
$teamLeaveLabels = ['solo_parent'=>'Solo Parent Leave','special_women'=>'Special Leave for Women','vawc'=>'VAWC Leave','sil'=>'Service Incentive Leave'];
$teamLeaveDefinitions = getLeaveTypeDefinitions($db);
$teamActiveLeaveDefinitions = getLeaveTypeDefinitions($db, true);
$teamLeaveAllotments = array_map(static fn($def) => (int)$def['annual_days'], $teamLeaveDefinitions);
$teamLeaveLabels = array_replace($teamLeaveLabels, array_map(static fn($def) => $def['name'], $teamLeaveDefinitions));
$me   = $_SESSION['employee_id'];
$self = basename($_SERVER['SCRIPT_NAME']);
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ── Endorse / not recommend a team member's pending request ──
    if ($action === 'endorse') {
        $lid     = (int)($_POST['leave_id'] ?? 0);
        $verdict = ($_POST['verdict'] ?? '') === 'not_recommended' ? 'not_recommended' : 'endorsed';
        $comment = trim(sanitize($_POST['comment'] ?? ''));
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT lr.* FROM leave_requests lr WHERE lr.id=? FOR UPDATE");
            $st->execute([$lid]);
            $lr = $st->fetch();
            // Server-side ownership check: the request must belong to MY team (blocks URL/ID tampering)
            if (!$lr || !isTeamMemberOf($me, $lr['employee_id'])) throw new DomainException('This leave request is not in your department team.');
            if ($lr['status'] !== 'pending') throw new DomainException('HR has already processed this request.');
            if ($verdict === 'not_recommended' && $comment === '') throw new DomainException('Please add a comment explaining why you do not recommend this request.');
            if ($verdict === 'endorsed') {
                $db->prepare("UPDATE leave_requests SET endorsement_status='endorsed', endorsed_by=?, endorsed_at=NOW(), endorsement_comment=? WHERE id=? AND status='pending'")
                   ->execute([$me, $comment ?: null, $lid]);
                $event = 'endorsed_by_team_leader';
                $employeeMessage = "Your Team Manager approved leave request #$lid. It is now with HR for final review.";
                $hrMessage = "Team Manager {$_SESSION['name']} approved leave request #$lid for HR review.";
            } else {
                $db->prepare("UPDATE leave_requests SET status='rejected', endorsement_status='not_recommended', endorsed_by=?, endorsed_at=NOW(), endorsement_comment=?, reviewed_by=?, review_date=NOW(), review_remarks=? WHERE id=? AND status='pending'")
                   ->execute([$me, $comment, $_SESSION['name'], $comment, $lid]);
                $event = 'rejected_by_team_leader';
                $employeeMessage = "Your Team Manager rejected leave request #$lid.";
                $hrMessage = "Team Manager {$_SESSION['name']} rejected leave request #$lid.";
            }
            logLeaveRequestEvent($db, $lid, $event, $_SESSION['name'], $comment ?: null);
            if ($verdict === 'endorsed') createLeaveNotification($db, $lid, $hrMessage, null, 'hr_admin');
            createLeaveNotification($db, $lid, $employeeMessage, $lr['employee_id']);
            if (!auditLog('Endorse Leave', 'Leave', "Leave ID: $lid | Employee: {$lr['employee_id']} | $verdict | $comment")) throw new RuntimeException('audit');
            $db->commit();
            $success = $verdict === 'endorsed' ? 'Request approved by the Team Manager and sent to HR for final review.' : 'Request rejected. It will not proceed to HR for approval.';
        } catch (DomainException $e) { if ($db->inTransaction()) $db->rollBack(); $error = $e->getMessage();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); error_log($e->getMessage()); $error = 'The endorsement could not be saved.'; }
    }

    // ── File a leave request ON BEHALF of a team member ──
    if ($action === 'file_for') {
        $target = sanitize($_POST['employee_id'] ?? '');
        $type   = sanitize($_POST['leave_type'] ?? '');
        $start  = sanitize($_POST['start_date'] ?? '');
        $end    = sanitize($_POST['end_date'] ?? '');
        $reason = sanitize($_POST['reason'] ?? '');
        $today  = date('Y-m-d');
        $stored = $aName = $aMime = null;
        if ($type === 'emergency') { $start = $today; if ($end < $today) $end = $today; }
        $rangeStart = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $rangeEnd = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        $days = ($rangeStart && $rangeEnd && $rangeEnd >= $rangeStart) ? employeeLeaveRequestedDays($db, $type, $start, $end) : 0;
        $requiredDocTypes = ['maternity', 'paternity', 'solo_parent', 'special_women', 'vawc'];
        $needsProof = ($type === 'sick' && $days >= 3) || in_array($type, $requiredDocTypes, true)
            || (!empty($teamLeaveDefinitions[$type]['is_custom']) && !empty($teamLeaveDefinitions[$type]['document_required']));
        $hasUpload  = !empty($_FILES['attachment']['name']) && ($_FILES['attachment']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE;
        $targetInfoStmt = $db->prepare('SELECT gender, civil_status, date_hired FROM users WHERE employee_id=?');
        $targetInfoStmt->execute([$target]);
        $targetInfo = $targetInfoStmt->fetch() ?: [];
        if ($type === 'vacation' && $rangeStart && $rangeEnd) {
            $businessDays = employeeVacationNoticeBusinessDays($db, $today, $start);
            if ($businessDays < 3) $error = 'Vacation leave must be filed at least three business days in advance.';
        }
        if ($error) {
            // A prior rule (such as vacation notice) already rejected this request.
        } elseif (!isTeamMemberOf($me, $target)) {
            $error = 'That employee is not in your team.';
        } elseif ($type === 'others') { $error = 'This legacy leave type is no longer available for new requests.';
        } elseif (!isset($teamLeaveAllotments[$type]) || empty($teamActiveLeaveDefinitions[$type])) { $error = 'Choose a valid leave type.';
        } elseif (!$start || !$end || !$reason) { $error = 'All fields are required.';
        } elseif ($start > $end) { $error = 'End date must be on or after the start date.';
        } elseif (!in_array($type, ['sick','emergency'], true) && $start < $today) { $error = 'You cannot file leave for past dates.';
        } elseif (in_array($type, ['maternity','special_women'], true) && strtolower((string)($targetInfo['gender'] ?? '')) !== 'female') { $error = 'This leave type is available only to eligible female employees.';
        } elseif ($type === 'paternity' && (strtolower((string)($targetInfo['gender'] ?? '')) !== 'male' || strtolower((string)($targetInfo['civil_status'] ?? '')) !== 'married')) { $error = 'Paternity leave is available to qualified married male employees.';
        } elseif ($type === 'paternity' && $days > 7) { $error = 'Paternity leave is limited to seven days per qualifying delivery.';
        } elseif ($type === 'sil' && (empty($targetInfo['date_hired']) || $targetInfo['date_hired'] > date('Y-m-d', strtotime('-1 year')))) { $error = 'Service Incentive Leave requires at least one year of service.';
        } elseif ($needsProof && !$hasUpload) { $error = 'This leave type requires its supporting document; sick leave requires a medical certificate for three or more consecutive days.';
        } else {
            try {
                if ($hasUpload) { $sv = storeLeaveAttachment($_FILES['attachment'], $target); $stored = $sv['path']; $aName = $sv['name']; $aMime = $sv['mime']; }
                $db->beginTransaction();
                $overlap = $db->prepare("SELECT id FROM leave_requests WHERE employee_id=? AND status<>'rejected' AND start_date<=? AND end_date>=? LIMIT 1 FOR UPDATE");
                $overlap->execute([$target, $end, $start]);
                if ($overlap->fetchColumn()) throw new DomainException('This request overlaps an existing pending or approved leave request.');
                $bal = getLeaveBalance($target, (int)date('Y', strtotime($start)));
                $avail = $bal[$type]['available'] ?? 0;
                if ($type !== 'others' && $days > $avail) {
                    $db->rollBack();
                    if ($stored && is_file(LEAVE_ATTACH_PATH . $stored)) unlink(LEAVE_ATTACH_PATH . $stored);
                    $error = "No sufficient credit: only $avail $type day(s) available but $days requested. Coordinate with HR to add credit, or file it as \"Others\" (leave without pay).";
                } else {
                    $urgent = $type === 'emergency' ? 1 : 0;
                    $db->prepare("INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, total_days, reason, urgent, auto_approval_after, attachment_path, attachment_name, attachment_mime, requested_by, endorsement_status, endorsed_by, endorsed_at, endorsement_comment)
                                  VALUES (?,?,?,?,?,?,?,CASE WHEN ?=1 THEN DATE_ADD(NOW(), INTERVAL 2 HOUR) ELSE NULL END,?,?,?,?,'endorsed',?,NOW(),'Filed by Team Manager on behalf of the employee')")
                       ->execute([$target, $type, $start, $end, $days, $reason, $urgent, $urgent, $stored, $aName, $aMime, $me, $me]);
                    $lid = (int)$db->lastInsertId();
                    logLeaveRequestEvent($db, $lid, 'filed_by_team_leader', $_SESSION['name'], "On behalf of $target");
                    createLeaveNotification($db, $lid, "Team Manager {$_SESSION['name']} filed a $type leave request for $target (already endorsed).", null, 'hr_admin');
                    createLeaveNotification($db, $lid, "Your Team Manager filed a $type leave request for you ($start to $end).", $target);
                    if (!auditLog('File Leave On Behalf', 'Leave', "Leave ID: $lid | Employee: $target | $type $start to $end")) throw new RuntimeException('audit');
                    $db->commit();
                    $success = 'Leave request filed on behalf of the employee and sent to HR.';
                }
            } catch (DomainException $e) {
                if ($db->inTransaction()) $db->rollBack();
                if ($stored && is_file(LEAVE_ATTACH_PATH . $stored)) unlink(LEAVE_ATTACH_PATH . $stored);
                $error = $e->getMessage();
            } catch (InvalidArgumentException $e) { $error = $e->getMessage();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                if ($stored && is_file(LEAVE_ATTACH_PATH . $stored)) unlink(LEAVE_ATTACH_PATH . $stored);
                error_log($e->getMessage()); $error = 'The leave request could not be saved.';
            }
        }
    }
}

// Team leave requests (only my team)
$filter = sanitize($_GET['status'] ?? ($mode === 'endorsements' ? 'awaiting' : ''));
$where  = "WHERE leader.department <> '' AND u.department = leader.department AND u.role = 'employee'
           AND u.status = 'active' AND u.employee_id <> leader.employee_id";
$params = [$me];
if ($filter === 'awaiting') { $where .= " AND lr.status='pending' AND lr.endorsement_status='none'"; }
elseif ($filter !== '' && $filter !== 'all') { $where .= " AND lr.status = ?"; $params[] = $filter; }
$st = $db->prepare("SELECT lr.*, u.first_name, u.last_name, u.department, u.position,
        CONCAT(hr.first_name,' ',hr.last_name) AS dummy
    FROM leave_requests lr JOIN users u ON u.employee_id = lr.employee_id
    JOIN users leader ON leader.employee_id = ?
    LEFT JOIN users hr ON hr.employee_id = lr.requested_by
    $where ORDER BY lr.created_at DESC");
$st->execute($params);
$rows = $st->fetchAll();
foreach ($rows as &$r) {
    $b = getLeaveBalance($r['employee_id'], (int)date('Y', strtotime($r['start_date'])));
    $r['bal_available'] = $r['leave_type'] === 'others' ? null : ($b[$r['leave_type']]['remaining'] ?? 0);
    $r['employee_name'] = $r['first_name'] . ' ' . $r['last_name'];
}
unset($r);
$members = getTeamMembers($me);

include '../../includes/header.php';
?>
<div class="page-body">
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <form method="GET" class="filter-bar" style="margin:0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <select name="status" class="form-select" style="width:auto;">
      <option value="all" <?= $filter==='all'||$filter===''?'selected':'' ?>>All requests</option>
      <option value="awaiting" <?= $filter==='awaiting'?'selected':'' ?>>Awaiting my review</option>
      <option value="pending" <?= $filter==='pending'?'selected':'' ?>>Pending HR</option>
      <option value="approved" <?= $filter==='approved'?'selected':'' ?>>Approved</option>
      <option value="rejected" <?= $filter==='rejected'?'selected':'' ?>>Rejected</option>
    </select>
    <button class="btn-primary" type="submit">Filter</button>
    <a class="btn-secondary" href="<?= $self ?>">Reset</a>
    <button type="button" class="btn-primary" style="margin-left:auto;" onclick="openModal('fileForModal')">+ File Leave for Team Member</button>
  </form>
</div>

<div class="card">
<div class="card-title"><?= htmlspecialchars($pageTitle) ?> (<?= count($rows) ?>)</div>
  <div class="table-wrapper"><table>
    <thead><tr><th>Employee</th><th>Dept / Position</th><th>Type</th><th>Date</th><th>Days</th><th>Balance</th><th>Team Manager decision</th><th>HR Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><div style="font-weight:600;"><?= htmlspecialchars($r['employee_name']) ?></div><div style="font-size:11px;color:#9CA3AF;"><?= htmlspecialchars($r['employee_id']) ?></div></td>
        <td style="font-size:12px;"><?= htmlspecialchars($r['department']) ?><br><span style="color:#6B7280;"><?= htmlspecialchars($r['position']) ?></span></td>
        <td><span class="badge badge-blue"><?= htmlspecialchars($teamLeaveLabels[$r['leave_type']] ?? (ucfirst(str_replace('_',' ',$r['leave_type'])) . ' Leave')) ?></span></td>
        <td style="font-size:12px;"><?= formatDate($r['start_date']) ?><?= $r['end_date'] !== $r['start_date'] ? ' – ' . formatDate($r['end_date']) : '' ?></td>
        <td style="text-align:center;"><?= (int)$r['total_days'] ?></td>
        <td style="font-size:12px;"><?php if ($r['bal_available'] === null): ?>No cap (LWOP)<?php else: ?><span style="<?= $r['bal_available'] < 1 ? 'color:#DC2626;font-weight:600;' : '' ?>"><?= (int)$r['bal_available'] ?> day(s) left</span><?php endif; ?></td>
        <td style="font-size:12px;">
          <?php if ($r['endorsement_status']==='endorsed'): ?><span class="badge badge-green">Approved by Team Manager</span>
          <?php elseif ($r['endorsement_status']==='not_recommended'): ?><span class="badge badge-red">Rejected</span>
          <?php else: ?><span class="badge badge-amber">Awaiting you</span><?php endif; ?>
          <?php if ($r['endorsement_comment']): ?><div style="color:#6B7280;"><?= htmlspecialchars($r['endorsement_comment']) ?></div><?php endif; ?>
        </td>
        <td><?php if ($r['status'] === 'pending' && $r['endorsement_status'] === 'endorsed'): ?><span class="badge badge-blue">Awaiting HR</span>
          <?php else: ?><span class="badge <?= statusBadge($r['status']) ?>"><?= htmlspecialchars(formatLeaveStatus($r['status'])) ?></span><?php endif; ?>
          <?php if ($r['reviewed_by']): ?><div style="font-size:11px;color:#6B7280;">by <?= htmlspecialchars($r['reviewed_by']) ?><?= $r['review_remarks'] ? ': ' . htmlspecialchars($r['review_remarks']) : '' ?></div><?php endif; ?></td>
        <td style="white-space:nowrap;">
          <button class="btn-secondary btn-sm" type="button" data-leave="<?= htmlspecialchars(json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP), ENT_QUOTES) ?>" onclick="viewDetails(this)">Details</button>
          <?php if ($r['status']==='pending'): ?>
          <button class="btn-secondary btn-sm" type="button" style="color:#059669;" onclick="endorse(<?= (int)$r['id'] ?>,'endorsed','<?= htmlspecialchars($r['employee_name'], ENT_QUOTES) ?>')">Approve &amp; send to HR</button>
          <button class="btn-secondary btn-sm" type="button" style="color:#DC2626;" onclick="endorse(<?= (int)$r['id'] ?>,'not_recommended','<?= htmlspecialchars($r['employee_name'], ENT_QUOTES) ?>')">Reject</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="9" style="text-align:center;color:#9CA3AF;padding:24px;">No leave requests found for your team.</td></tr><?php endif; ?>
    </tbody></table></div>
  <p style="font-size:11px;color:#6B7280;margin-top:10px;">You approve or reject requests from your team first. Approved requests proceed to HR for final approval; leave credits are managed by HR.</p>
</div>
</div>

<div class="modal-overlay" id="endorseModal"><div class="modal-box" style="max-width:420px;">
  <div class="modal-header"><div class="modal-title" id="endTitle">Endorse</div><button class="modal-close" onclick="closeModal('endorseModal')">&times;</button></div>
  <form method="POST"><input type="hidden" name="action" value="endorse"><input type="hidden" name="leave_id" id="endId"><input type="hidden" name="verdict" id="endVerdict">
    <div class="form-group"><label class="form-label">Employee</label><input id="endEmp" class="form-input" readonly></div>
    <div class="form-group"><label class="form-label" id="endLabel">Review comment</label><textarea name="comment" id="endComment" class="form-textarea" rows="3" maxlength="500" placeholder="Optional comment for HR"></textarea></div>
    <div style="display:flex;gap:10px;"><button class="btn-primary" type="submit" id="endBtn">Submit</button><button type="button" class="btn-secondary" onclick="closeModal('endorseModal')">Cancel</button></div>
  </form></div></div>

<div class="modal-overlay" id="fileForModal"><div class="modal-box" style="max-width:480px;max-height:92vh;overflow:auto;">
  <div class="modal-header"><div class="modal-title">File Leave for a Team Member</div><button class="modal-close" onclick="closeModal('fileForModal')">&times;</button></div>
  <form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="file_for">
    <div class="form-group"><label class="form-label">Team member</label><select name="employee_id" class="form-select" required>
      <option value="">-- Select --</option><?php foreach ($members as $m): ?><option value="<?= htmlspecialchars($m['employee_id']) ?>"><?= htmlspecialchars($m['first_name'].' '.$m['last_name'].' ('.$m['employee_id'].')') ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">Leave type</label><select name="leave_type" class="form-select" required>
      <?php foreach ($teamActiveLeaveDefinitions as $typeKey => $typeDef): if ($typeKey === 'others') continue; ?><option value="<?= htmlspecialchars($typeKey) ?>"><?= htmlspecialchars($typeDef['name']) ?></option><?php endforeach; ?></select></div>
    <div class="row g-2"><div class="col-6"><label class="form-label">Start</label><input type="date" name="start_date" class="form-input" required></div><div class="col-6"><label class="form-label">End</label><input type="date" name="end_date" class="form-input" required></div></div>
    <div class="form-group mt-2"><label class="form-label">Reason</label><textarea name="reason" class="form-textarea" rows="2" required></textarea></div>
    <div class="form-group"><label class="form-label">Supporting document (required by leave rules; optional for Emergency)</label><input type="file" name="attachment" class="form-input" accept=".pdf,.jpg,.jpeg,.png"><div style="font-size:11px;color:#6B7280;margin-top:4px;">For multiple required items, combine documents in one PDF. JPG, PNG or PDF · max 5 MB.</div></div>
    <div style="display:flex;gap:10px;"><button class="btn-primary" type="submit">File &amp; Send to HR</button><button type="button" class="btn-secondary" onclick="closeModal('fileForModal')">Cancel</button></div>
  </form></div></div>

<div class="modal-overlay" id="detailModal"><div class="modal-box" style="max-width:600px;max-height:90vh;overflow:auto;">
  <div class="modal-header"><div class="modal-title">Leave Request Details</div><button class="modal-close" onclick="closeModal('detailModal')">&times;</button></div>
  <dl id="detailGrid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px 18px;font-size:13px;"></dl></div></div>
<script>
function endorse(id, verdict, name) {
  document.getElementById('endId').value = id; document.getElementById('endVerdict').value = verdict; document.getElementById('endEmp').value = name;
  var rej = verdict === 'not_recommended';
  document.getElementById('endTitle').textContent = rej ? 'Reject Request' : 'Approve Request';
  document.getElementById('endComment').required = rej;
  document.getElementById('endLabel').textContent = rej ? 'Rejection reason (required)' : 'Review comment (optional)';
  document.getElementById('endBtn').textContent = rej ? 'Reject Request' : 'Approve & send to HR';
  openModal('endorseModal');
}
function viewDetails(b) {
  var r = JSON.parse(b.dataset.leave), g = document.getElementById('detailGrid'); g.replaceChildren();
  [['Status', (r.status||'').replace(/_/g,' ').toUpperCase()],['Employee Name', r.employee_name],['Employee Number', r.employee_id],
   ['Leave Type', r.leave_type.replace(/_/g,' ')],['Leave Date', r.start_date === r.end_date ? r.start_date : r.start_date + ' to ' + r.end_date],['Days', r.total_days],['Reason', r.reason],
   ['Attachment', r.attachment_path ? (r.attachment_name || 'Attached') : null],['Date Filed', r.created_at],
   ['Team Manager Remarks', [r.endorsement_status && r.endorsement_status !== 'none' ? String(r.endorsement_status).replace(/_/g,' ') : '', r.endorsement_comment].filter(Boolean).join(' — ')],
   ['Processed / Approved By', r.reviewed_by],['Final Remarks', r.review_remarks],
   ['Department', r.department],['Position', r.position],['Credit left', r.bal_available === null ? 'No cap (LWOP)' : r.bal_available + ' day(s)']].forEach(function (f) {
    var d = document.createElement('div'), t = document.createElement('dt'), v = document.createElement('dd');
    t.style.cssText = 'font-size:11px;color:#6B7280;text-transform:uppercase;'; v.style.margin = 0;
    t.textContent = f[0]; v.textContent = (f[1] === null || f[1] === '' || f[1] === undefined) ? '—' : f[1]; d.append(t, v); g.appendChild(d); });
  if (r.attachment_path) { var an = Array.from(g.querySelectorAll('dd')).find(function (x) { return x.previousSibling && x.previousSibling.textContent === 'Attachment'; });
    if (an) { var a = document.createElement('a'); a.href = '../../api/leave_attachment.php?id=' + r.id; a.target = '_blank'; a.textContent = r.attachment_name || 'Open'; an.textContent = ''; an.appendChild(a); } }
  openModal('detailModal');
}
</script>
