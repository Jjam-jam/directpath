<?php
// ============================================================
// Directpath HRMS — Leave Management (Admin)
// Pinagbago: Kapag nag-approve ng leave, babawasan ang leave_balances
// Kapag nag-reject, hindi mababawasan (nandito pa rin ang full days)
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin');

$pageTitle  = 'Leave Management';
$activePage = 'leave';
$db = getDB();
ensureLeaveBalanceColumns($db);

$success = '';
$error   = '';
$proposalFilter = $_GET['tab'] ?? $_POST['tab'] ?? 'pending';
if (!in_array($proposalFilter, ['pending','approved','rejected','all'], true)) $proposalFilter = 'pending';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $lid    = (int)($_POST['leave_id'] ?? 0);
    $remark = sanitize($_POST['remarks'] ?? '');

    if ($action === 'review_leave_type_request') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        $reviewRemark = trim(strip_tags((string)($_POST['review_remarks'] ?? '')));
        $requestStmt = $db->prepare("SELECT * FROM custom_leave_type_requests WHERE id=? AND status='pending' FOR UPDATE");
        $requestStmt->execute([$requestId]);
        $typeRequest = $requestStmt->fetch(PDO::FETCH_ASSOC);
        if (!$typeRequest) {
            $error = 'This leave type proposal was already reviewed or no longer exists.';
        } elseif (!in_array($decision, ['approve','reject'], true) || ($decision === 'reject' && $reviewRemark === '')) {
            $error = 'Choose approve or provide a reason when rejecting the proposal.';
        } elseif ($decision === 'reject') {
            $db->prepare("UPDATE custom_leave_type_requests SET status='rejected',reviewed_by=?,reviewed_at=NOW(),review_remarks=? WHERE id=? AND status='pending'")
               ->execute([$_SESSION['employee_id'],$reviewRemark,$requestId]);
            auditLog('Reject Leave Type Proposal', 'Leave', "Rejected proposal #$requestId: {$typeRequest['name']}");
            $success = 'Leave type proposal rejected.';
        } else {
            $typeName = $typeRequest['name'];
            $annualDays = (int)$typeRequest['annual_days'];
            $known = getLeaveTypeDefinitions($db);
            $asciiName = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $typeName);
            if ($asciiName === false) $asciiName = $typeName;
            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $asciiName), '_'));
            $typeKey = 'custom_' . substr($slug ?: 'leave', 0, 40);
            $conflictingType = findLeaveTypeNameConflict($db, $typeName, $requestId);
            if (isset($known[$typeKey]) || normalizeLeaveTypeName($typeName) === 'other'
                || $conflictingType !== null) {
                $conflictingType = $conflictingType ?? 'an existing leave type';
                $autoRejectReason = "Automatically rejected: '{$typeName}' matches or is similar to '{$conflictingType}', which already exists or is under review.";
                $db->prepare("UPDATE custom_leave_type_requests SET status='rejected',reviewed_at=NOW(),review_remarks=? WHERE id=? AND status='pending'")
                   ->execute([$autoRejectReason, $requestId]);
                auditLog('Auto-Reject Leave Type Proposal', 'Leave', "Automatically rejected similar proposal #$requestId: $typeName");
                $success = "Proposal rejected: '{$typeName}' matches or is similar to '{$conflictingType}', which already exists or is under review.";
            } else {
                try {
                    $columns = $db->query('SHOW COLUMNS FROM leave_balances')->fetchAll(PDO::FETCH_COLUMN);
                    foreach (['total'=>$annualDays,'used'=>0] as $suffix=>$default) {
                        $column = $typeKey . '_' . $suffix;
                        if (!in_array($column, $columns, true)) $db->exec("ALTER TABLE leave_balances ADD COLUMN `$column` INT NOT NULL DEFAULT " . (int)$default);
                    }
                    $db->beginTransaction();
                    $db->prepare('INSERT INTO custom_leave_types (type_key,name,annual_days,description,document_required,document_guidance,created_by) VALUES (?,?,?,?,?,?,?)')
                       ->execute([$typeKey,$typeName,$annualDays,$typeRequest['description'],(int)$typeRequest['document_required'],$typeRequest['document_guidance'],$_SESSION['employee_id']]);
                    $db->prepare("UPDATE custom_leave_type_requests SET status='approved',reviewed_by=?,reviewed_at=NOW(),review_remarks=? WHERE id=? AND status='pending'")
                       ->execute([$_SESSION['employee_id'],$reviewRemark,$requestId]);
                    auditLog('Approve Leave Type Proposal', 'Leave', "Approved {$typeName} ({$annualDays} days/year)");
                    $db->commit();
                    $success = "Leave type '$typeName' approved and added to employee leave requests.";
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log('Approve leave type request failed: ' . $e->getMessage());
                    $error = 'The proposal could not be approved. Check for a duplicate name or migration issue.';
                }
            }
        }
    }

    // === HR ASSIGNS EXTRA LEAVE CREDIT (e.g. employee has no credit left) ===
    if ($action === 'add_credit') {
        $credEmp  = sanitize($_POST['employee_id'] ?? '');
        $credType = sanitize($_POST['leave_type'] ?? '');
        $credDays = (int)($_POST['days'] ?? 0);
        $credWhy  = sanitize($_POST['reason'] ?? '');
        $credYear = (int)($_POST['year'] ?? date('Y'));
        $chk = $db->prepare("SELECT 1 FROM users WHERE employee_id=? AND role='employee' AND status='active'");
        $chk->execute([$credEmp]);
        if (!$chk->fetchColumn() || !isset(getLeaveTypeDefinitions($db, true)[$credType]) || $credType === 'others'
            || $credDays < 1 || $credDays > 30 || $credWhy === '' || $credYear < 2020 || $credYear > 2100) {
            $error = 'Choose an active employee, a leave type with credits, 1–30 days and give a reason.';
        } else {
            $db->beginTransaction();
            try {
                $db->prepare("INSERT INTO leave_credit_adjustments (employee_id, year, leave_type, days, reason, added_by) VALUES (?,?,?,?,?,?)")
                   ->execute([$credEmp, $credYear, $credType, $credDays, $credWhy, $_SESSION['employee_id']]);
                if (!auditLog('Add Leave Credit', 'Leave', "Employee: $credEmp | +$credDays $credType day(s) for $credYear | Reason: $credWhy")) {
                    throw new RuntimeException('Audit log write failed.');
                }
                $db->commit();
                $success = "Added $credDays $credType leave credit day(s) to $credEmp for $credYear. The employee can now file the request.";
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Leave credit was not saved because the audit record could not be written.';
            }
        }
    }

    if (in_array($action, ['approve', 'reject', 'verify_document', 'reject_document'], true)) {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM leave_requests WHERE id=? FOR UPDATE");
            $stmt->execute([$lid]);
            $lr = $stmt->fetch();
            if (!$lr) throw new DomainException('Leave request not found.');

            if ($action === 'approve') {
                if ($lr['status'] !== 'pending') throw new DomainException('This leave request has already been reviewed.');

                // Enforce the sequence: employee -> department Team Manager -> HR.
                $teamLeaderId = getDepartmentTeamLeaderId($db, $lr['employee_id']);
                if ($lr['endorsement_status'] !== 'endorsed') {
                    throw new DomainException('This request must be reviewed by the Team Manager before HR can approve it.');
                }

                $leaveYear = (int)date('Y', strtotime($lr['start_date']));
                $days      = (int)$lr['total_days'];
                $leaveType = $lr['leave_type'];
                if (in_array($leaveType, ['sick', 'vacation', 'emergency'], true)) {
                    $hireStmt = $db->prepare("SELECT date_hired FROM users WHERE employee_id=?");
                    $hireStmt->execute([$lr['employee_id']]);
                    $dateHired = $hireStmt->fetchColumn();
                    $eligibleAfter = (new DateTimeImmutable('today'))->modify('-3 months')->format('Y-m-d');
                    if (!$dateHired || $dateHired > $eligibleAfter) {
                        throw new DomainException('Cannot approve: sick, vacation, and emergency leave require at least three months of company service.');
                    }
                }
                $balance   = getLeaveBalance($lr['employee_id'], $leaveYear);
                $available = ($balance[$leaveType]['available_raw'] ?? 0) + $days;
                if ($leaveType !== 'others' && $days > $available) {
                    throw new DomainException("Cannot approve — only $available $leaveType leave day(s) remain available.");
                }

                $urgent = (int)$lr['urgent'] === 1 || $leaveType === 'emergency';
                if ($urgent) {
                    $db->prepare("
                        UPDATE leave_requests
                        SET status='approved_pending_documents', urgent=1, reviewed_by=?, review_date=NOW(),
                            approved_at=NOW(), documents_due_at=DATE_ADD(NOW(), INTERVAL 3 DAY), review_remarks=?
                        WHERE id=? AND status='pending'
                    ")->execute([$_SESSION['name'], $remark, $lid]);
                } else {
                    $db->prepare("
                        UPDATE leave_requests
                        SET status='approved', reviewed_by=?, review_date=NOW(), approved_at=NOW(), review_remarks=?
                        WHERE id=? AND status='pending'
                    ")->execute([$_SESSION['name'], $remark, $lid]);
                }

                if ($leaveType !== 'others') {
                    $usedColumn = $leaveType . '_used';
                    $db->prepare("UPDATE leave_balances SET $usedColumn = $usedColumn + ? WHERE employee_id=? AND year=?")
                       ->execute([$days, $lr['employee_id'], $leaveYear]);
                }
                logLeaveRequestEvent($db, $lid, 'approved', $_SESSION['name'], $urgent ? 'Approved - Pending Documents; proof due within 3 days.' : 'Approved.');
                createLeaveNotification(
                    $db,
                    $lid,
                    $urgent ? "Leave request #$lid approved. Submit proof within 3 days." : "Leave request #$lid approved.",
                    $lr['employee_id']
                );
                if ($teamLeaderId) createLeaveNotification($db, $lid, "HR approved leave request #$lid for {$lr['employee_id']}.", $teamLeaderId);
                if (!auditLog('Approve Leave', 'Leave', "Approved leave ID: $lid ({$leaveType}, {$days} days)")) {
                    throw new RuntimeException('Audit log write failed.');
                }
                $success = $urgent
                    ? 'Leave approved. The employee has 3 days to submit required documents.'
                    : "Leave request approved. {$days} {$leaveType} day(s) deducted from employee balance.";
            } elseif ($action === 'reject') {
                if ($lr['status'] !== 'pending') throw new DomainException('This leave request has already been reviewed.');
                $teamLeaderId = getDepartmentTeamLeaderId($db, $lr['employee_id']);
                if ($lr['endorsement_status'] !== 'endorsed') {
                    throw new DomainException('This request must be reviewed by the Team Manager before HR can reject it.');
                }
                if ($remark === '') throw new DomainException('A rejection reason is required.');
                $db->prepare("UPDATE leave_requests SET status='rejected', reviewed_by=?, review_date=NOW(), review_remarks=? WHERE id=? AND status='pending'")
                   ->execute([$_SESSION['name'], $remark, $lid]);
                logLeaveRequestEvent($db, $lid, 'rejected', $_SESSION['name'], $remark);
                createLeaveNotification($db, $lid, "Leave request #$lid was rejected.", $lr['employee_id']);
                if ($teamLeaderId) createLeaveNotification($db, $lid, "HR rejected leave request #$lid for {$lr['employee_id']}.", $teamLeaderId);
                if (!auditLog('Reject Leave', 'Leave', "Rejected leave ID: $lid | Remarks: $remark")) {
                    throw new RuntimeException('Audit log write failed.');
                }
                $success = 'Leave request rejected.';
            } else {
                if ($lr['status'] !== 'documents_submitted') {
                    throw new DomainException('This request has no submitted document awaiting review.');
                }
                $verified = $action === 'verify_document';
                if (!$verified && $remark === '') throw new DomainException('A rejection reason is required.');
                $nextStatus = $verified ? 'approved' : 'documents_rejected';
                $db->prepare("
                    UPDATE leave_requests
                    SET status=?, document_reviewed_by=?, document_reviewed_at=NOW(), document_review_remarks=?,
                        documents_flagged_at=CASE WHEN ?='approved' THEN NULL ELSE documents_flagged_at END
                    WHERE id=? AND status='documents_submitted'
                ")->execute([$nextStatus, $_SESSION['name'], $remark, $nextStatus, $lid]);
                logLeaveRequestEvent($db, $lid, $verified ? 'document_verified' : 'document_rejected', $_SESSION['name'], $remark);
                createLeaveNotification(
                    $db,
                    $lid,
                    $verified ? "Documents for leave request #$lid were verified; leave is approved."
                        : "Documents for leave request #$lid were rejected. " . ($remark ?: 'Please review and re-submit before the deadline.'),
                    $lr['employee_id']
                );
                if (!auditLog($verified ? 'Verify Leave Document' : 'Reject Leave Document', 'Leave', "Leave request ID: $lid | Remarks: $remark")) {
                    throw new RuntimeException('Document review was not recorded.');
                }
                $success = $verified ? 'Document verified. Leave is now approved.' : 'Document rejected. The employee was notified.';
            }
            $db->commit();
        } catch (DomainException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = $e->getMessage();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Leave action failed: ' . $e->getMessage());
            $error = 'The leave action was not saved because its history could not be recorded.';
        }
    }
}

// Filters para sa leave table
$filterType   = sanitize($_GET['type']   ?? '');
$leaveTabGroups = [
    'pending'  => ['Pending',              ['pending']],
    'approved' => ['Approved / Processed', ['approved', 'approved_pending_documents', 'documents_submitted', 'documents_rejected']],
    'rejected' => ['Rejected',             ['rejected']],
    'all'      => ['All Requests',         null],
];
$leaveTab = $_GET['tab'] ?? $_POST['tab'] ?? 'pending';
if (!isset($leaveTabGroups[$leaveTab])) $leaveTab = 'pending';
$proposalFilter = $leaveTab;
$hrVisibleLeaveRule = "lr.endorsement_status = 'endorsed'";
$leaveCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
foreach ($db->query("SELECT lr.status, COUNT(*) c FROM leave_requests lr WHERE $hrVisibleLeaveRule GROUP BY lr.status")->fetchAll() as $cr) {
    foreach ($leaveTabGroups as $k => [$lbl, $sts]) if ($sts && in_array($cr['status'], $sts, true)) $leaveCounts[$k] += (int)$cr['c'];
    $leaveCounts['all'] += (int)$cr['c'];
}

$where  = "WHERE $hrVisibleLeaveRule";
$params = [];
if ($leaveTabGroups[$leaveTab][1]) {
    $in = implode(',', array_fill(0, count($leaveTabGroups[$leaveTab][1]), '?'));
    $where .= " AND lr.status IN ($in)"; array_push($params, ...$leaveTabGroups[$leaveTab][1]);
}
if ($filterType)   { $where .= " AND lr.leave_type=?"; $params[] = $filterType; }

// Kinukuha ang full name ng employee mula sa users table (first + middle + last)
$stmt = $db->prepare("
    SELECT lr.*,
           CONCAT(u.first_name,
                  IF(u.middle_name IS NOT NULL AND u.middle_name != '',
                     CONCAT(' ', SUBSTRING(u.middle_name,1,1), '.'), ''),
                  ' ', u.last_name) AS employee_name,
              u.department, u.position, u.email, u.team_leader_id, u.date_hired,
              CONCAT(tl.first_name, ' ', tl.last_name) AS team_leader_name,
              CONCAT(eb.first_name, ' ', eb.last_name) AS endorsed_by_name,
              CONCAT(rb.first_name, ' ', rb.last_name) AS requested_by_name
    FROM leave_requests lr
    JOIN users u ON lr.employee_id = u.employee_id
    LEFT JOIN users tl ON tl.employee_id = u.team_leader_id
    LEFT JOIN users eb ON eb.employee_id = lr.endorsed_by
    LEFT JOIN users rb ON rb.employee_id = lr.requested_by
    $where
    ORDER BY
        FIELD(lr.status,
              'pending',
              'approved_pending_documents',
              'documents_submitted',
              'documents_rejected',
              'approved',
              'rejected'),
        IF(lr.urgent = 1 OR lr.leave_type = 'emergency', 0, 1),
        lr.created_at DESC, lr.id DESC
");
$stmt->execute($params);
$leaves = $stmt->fetchAll();
$allCustomTypeRequests = [];
$customTypeRequests = [];
$customLeaveMigrationMissing = false;
$customLeaveAutoRejected = [];
try {
    $pendingProposals = $db->query("SELECT id,name FROM custom_leave_type_requests WHERE status='pending' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pendingProposals as $pendingProposal) {
        $conflictingType = findLeaveTypeNameConflict($db, (string)$pendingProposal['name'], (int)$pendingProposal['id']);
        if ($conflictingType === null) continue;
        $autoRejectReason = "Automatically rejected: '{$pendingProposal['name']}' matches or is similar to '{$conflictingType}', which already exists or is under review.";
        $update = $db->prepare("UPDATE custom_leave_type_requests SET status='rejected',reviewed_at=NOW(),review_remarks=? WHERE id=? AND status='pending'");
        $update->execute([$autoRejectReason, (int)$pendingProposal['id']]);
        if ($update->rowCount()) {
            $customLeaveAutoRejected[] = $pendingProposal['name'] . ' (similar to ' . $conflictingType . ')';
            auditLog('Auto-Reject Leave Type Proposal', 'Leave', "Automatically rejected similar proposal #{$pendingProposal['id']}: {$pendingProposal['name']} matches {$conflictingType}");
        }
    }
    $allCustomTypeRequests = $db->query("SELECT r.*, CONCAT(u.first_name,' ',u.last_name) AS employee_name,
        CONCAT(hr.first_name,' ',hr.last_name) AS reviewer_name
        FROM custom_leave_type_requests r
        JOIN users u ON u.employee_id=r.employee_id
        LEFT JOIN users hr ON hr.employee_id=(r.reviewed_by COLLATE utf8mb4_unicode_ci)
        ORDER BY FIELD(r.status,'pending','approved','rejected'), r.created_at DESC, r.id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    if ((int)($e->errorInfo[1] ?? 0) === 1146) $customLeaveMigrationMissing = true;
    else { error_log('Load custom leave proposals failed: ' . $e->getMessage()); $error = 'Could not load leave type proposals. Check the database error log.'; }
}
$customTypeRequests = $proposalFilter === 'all'
    ? $allCustomTypeRequests
    : array_values(array_filter($allCustomTypeRequests, static fn($proposalRow) => $proposalRow['status'] === $proposalFilter));

// Audit trail (who filed / endorsed / processed, when, remarks) + credit left for open requests
$eventsByLeave = [];
if ($leaves) {
    $ids = implode(',', array_map('intval', array_column($leaves, 'id')));
    foreach ($db->query("SELECT leave_request_id, action, actor, details, created_at FROM leave_request_events WHERE leave_request_id IN ($ids) ORDER BY id")->fetchAll() as $ev) {
        $eventsByLeave[$ev['leave_request_id']][] = $ev;
    }
}
foreach ($leaves as &$lrRow) {
    $lrRow['events'] = $eventsByLeave[$lrRow['id']] ?? [];
    $lrRow['credit_available'] = null;
    if ($lrRow['status'] === 'pending' && $lrRow['leave_type'] !== 'others') {
        $bal = getLeaveBalance($lrRow['employee_id'], (int)date('Y', strtotime($lrRow['start_date'])));
        $lrRow['credit_available'] = ($bal[$lrRow['leave_type']]['available_raw'] ?? 0) + (int)$lrRow['total_days'];
    }
}
unset($lrRow);
$empOptions = $db->query("SELECT employee_id, CONCAT(first_name,' ',last_name) n FROM users WHERE role='employee' AND status='active' ORDER BY first_name")->fetchAll();

include '../../includes/header.php';
?>

<style>
.leave-detail-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px 20px; }
.leave-detail-item { min-width:0; }
.leave-detail-item dt { margin-bottom:4px; color:#6B7280; font-size:11px; font-weight:600; text-transform:uppercase; }
.leave-detail-item dd { margin:0; color:#1F2937; font-size:14px; overflow-wrap:anywhere; white-space:pre-wrap; }
.leave-details-button { color:#2563EB; }
.leave-proposals-table { width:100%; table-layout:fixed; }
.leave-proposals-table th, .leave-proposals-table td { overflow-wrap:anywhere; vertical-align:top; }
.leave-proposal-details { display:grid; gap:5px; font-size:12px; color:#4B5563; }
.leave-proposal-detail { display:grid; grid-template-columns:74px minmax(0,1fr); gap:6px; }
.leave-proposal-detail strong { color:#374151; }
.leave-proposal-actions { display:flex; flex-direction:row; flex-wrap:nowrap; gap:6px; }
.leave-proposal-row { cursor:pointer; }
.leave-proposal-row:hover { background:#F8FAFC; }
</style>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($customLeaveAutoRejected): ?><div class="alert alert-error">Duplicate leave type proposal(s) were automatically rejected because similar leave types already exist: <?= htmlspecialchars(implode(', ', $customLeaveAutoRejected)) ?>.</div><?php endif; ?>
<?php if ($customLeaveMigrationMissing): ?><div class="alert alert-error">Leave type proposals are not enabled. Apply <code>config/custom_leave_types_migration.sql</code> to the database.</div><?php endif; ?>

<!-- Request tabs (newest first) -->
<?php $lt = []; foreach ($leaveTabGroups as $k => [$lbl, $sts]) $lt[$k] = [$lbl, $leaveCounts[$k]]; ?>
<?php $leaveTabParams = []; if ($filterType) $leaveTabParams['type'] = $filterType; ?>
<?= renderTabBar($lt, $leaveTab, 'tab', $leaveTabParams) ?>

<?php if ($allCustomTypeRequests): ?>
<div class="card">
    <div class="card-title">Employee Leave Type Proposals <span style="font-size:12px;color:#6B7280;font-weight:400;">Click a row to view the full proposal and review history</span></div>
    <input type="search" id="leaveProposalSearch" class="form-input" placeholder="Search leave type proposals..." aria-label="Search leave type proposals" style="display:block;width:min(100%,340px);margin:0 0 12px;">
    <div class="table-wrapper"><table class="leave-proposals-table"><colgroup><col style="width:20%;"><col style="width:8%;"><col style="width:42%;"><col style="width:12%;"><col style="width:18%;"></colgroup><thead><tr><th>Employee / proposal</th><th>Days / year</th><th>Proposal details</th><th>Status</th><th>Decision</th></tr></thead><tbody>
    <?php foreach ($customTypeRequests as $proposal): ?>
    <?php $proposalRowData = [
        'name'=>$proposal['name'], 'employee_name'=>$proposal['employee_name'], 'employee_id'=>$proposal['employee_id'],
        'annual_days'=>(int)$proposal['annual_days'], 'description'=>$proposal['description'], 'request_reason'=>$proposal['request_reason'],
        'document_required'=>(int)$proposal['document_required'], 'document_guidance'=>$proposal['document_guidance'],
        'status'=>$proposal['status'], 'review_remarks'=>$proposal['review_remarks'], 'reviewed_by'=>$proposal['reviewed_by'],
        'reviewer_name'=>$proposal['reviewer_name'], 'created_at'=>$proposal['created_at'], 'reviewed_at'=>$proposal['reviewed_at']
    ]; ?>
    <tr class="leave-proposal-row" tabindex="0" role="button" data-proposal="<?= htmlspecialchars(json_encode($proposalRowData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>" onclick="viewLeaveTypeProposal(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();viewLeaveTypeProposal(this);}">
        <td><strong><?= htmlspecialchars($proposal['name']) ?></strong><br><small><?= htmlspecialchars($proposal['employee_name'] . ' (' . $proposal['employee_id'] . ')') ?></small></td>
        <td style="white-space:nowrap;"><?= (int)$proposal['annual_days'] ?></td>
        <td><div class="leave-proposal-details">
            <div class="leave-proposal-detail"><strong>Reason</strong><span><?= nl2br(htmlspecialchars($proposal['request_reason'])) ?></span></div>
            <div class="leave-proposal-detail"><strong>Description</strong><span><?= nl2br(htmlspecialchars($proposal['description'] ?: 'None provided')) ?></span></div>
            <div class="leave-proposal-detail"><strong>Documents</strong><span><?= (int)$proposal['document_required'] ? 'Required: ' . htmlspecialchars($proposal['document_guidance']) : 'None proposed' ?></span></div>
        </div></td>
        <td><span class="badge <?= $proposal['status']==='approved' ? 'badge-green' : ($proposal['status']==='rejected' ? 'badge-red' : 'badge-amber') ?>"><?= htmlspecialchars(ucfirst($proposal['status'])) ?></span></td>
        <td>
          <?php if ($proposal['status'] === 'pending'): ?>
          <div class="leave-proposal-actions">
            <button class="btn-primary btn-sm" type="button" data-proposal-id="<?= (int)$proposal['id'] ?>" data-proposal-name="<?= htmlspecialchars($proposal['name'], ENT_QUOTES, 'UTF-8') ?>" onclick="event.stopPropagation();reviewLeaveTypeProposal(this.dataset.proposalId,'approve',this.dataset.proposalName)">Approve</button>
            <button class="btn-secondary btn-sm" type="button" style="color:#DC2626;" data-proposal-id="<?= (int)$proposal['id'] ?>" data-proposal-name="<?= htmlspecialchars($proposal['name'], ENT_QUOTES, 'UTF-8') ?>" onclick="event.stopPropagation();reviewLeaveTypeProposal(this.dataset.proposalId,'reject',this.dataset.proposalName)">Reject</button>
          </div>
          <?php else: ?><span style="color:#9CA3AF;">Reviewed</span><?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$customTypeRequests): ?><tr><td colspan="5" style="text-align:center;color:#9CA3AF;padding:18px;">No <?= htmlspecialchars($proposalFilter) ?> proposals.</td></tr><?php endif; ?>
    <tr id="leaveProposalNoSearchResults" hidden><td colspan="5" style="text-align:center;color:#9CA3AF;padding:18px;">No proposals match your search.</td></tr>
    </tbody></table></div>
</div>
<?php endif; ?>

<div class="modal-overlay" id="leaveTypeProposalDetailsModal" role="dialog" aria-modal="true" aria-labelledby="leaveTypeProposalDetailsTitle">
    <div class="modal-box" style="max-width:640px;max-height:88vh;overflow:auto;">
        <div class="modal-header">
            <div class="modal-title" id="leaveTypeProposalDetailsTitle">Leave Type Proposal Details</div>
            <button class="modal-close" type="button" aria-label="Close details" onclick="closeModal('leaveTypeProposalDetailsModal')">&times;</button>
        </div>
        <dl id="leaveTypeProposalDetailsGrid" class="leave-detail-grid"></dl>
        <div style="display:flex;justify-content:flex-end;margin-top:16px;">
            <button type="button" class="btn-secondary" onclick="closeModal('leaveTypeProposalDetailsModal')">Close</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="leaveTypeProposalModal" role="dialog" aria-modal="true" aria-labelledby="leaveTypeProposalTitle">
    <div class="modal-box" style="max-width:460px;">
        <div class="modal-header">
            <div class="modal-title" id="leaveTypeProposalTitle">Review Leave Type Proposal</div>
            <button class="modal-close" type="button" aria-label="Close" onclick="closeModal('leaveTypeProposalModal')">&times;</button>
        </div>
        <form method="POST" id="leaveTypeProposalForm">
            <input type="hidden" name="action" value="review_leave_type_request">
            <input type="hidden" name="request_id" id="proposalReviewId">
            <input type="hidden" name="decision" id="proposalReviewDecision">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($leaveTab, ENT_QUOTES, 'UTF-8') ?>">
            <p id="leaveTypeProposalPrompt" style="font-size:14px;color:#4B5563;"></p>
            <div class="form-group">
                <label class="form-label" id="proposalRemarksLabel" for="proposalReviewRemarks">HR remarks</label>
                <textarea class="form-textarea" name="review_remarks" id="proposalReviewRemarks" maxlength="500" rows="3" placeholder="Add a note for the employee"></textarea>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">
                <button class="btn-secondary" type="button" onclick="closeModal('leaveTypeProposalModal')">Cancel</button>
                <button class="btn-primary" type="submit" id="proposalReviewProceed">Proceed</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <form method="GET" class="filter-bar" style="margin:0;">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($leaveTab) ?>">
        <select name="type" class="form-select" style="width:auto;">
            <option value="">All Types</option>
            <option value="vacation"  <?= $filterType==='vacation' ?'selected':'' ?>>Vacation</option>
            <option value="sick"      <?= $filterType==='sick'     ?'selected':'' ?>>Sick</option>
            <option value="emergency" <?= $filterType==='emergency'?'selected':'' ?>>Emergency</option>
            <option value="maternity" <?= $filterType==='maternity'?'selected':'' ?>>Maternity</option>
            <option value="paternity" <?= $filterType==='paternity'?'selected':'' ?>>Paternity</option>
            <?php foreach (getLeaveTypeDefinitions($db, true) as $typeKey => $typeDef): if (empty($typeDef['is_custom'])) continue; ?>
            <option value="<?= htmlspecialchars($typeKey) ?>" <?= $filterType===$typeKey?'selected':'' ?>><?= htmlspecialchars($typeDef['name']) ?></option>
            <?php endforeach; ?>
            <option value="others"    <?= $filterType==='others'   ?'selected':'' ?>>Other Leaves (legacy requests)</option>
        </select>
        <button type="submit" class="btn-primary">Filter</button>
        <a href="leave.php?tab=<?= htmlspecialchars($leaveTab) ?>" class="btn-secondary">Reset</a>
        <button type="button" class="btn-secondary" style="margin-left:auto;" onclick="openModal('creditModal')">+ Add Leave Credit</button>
    </form>
</div>

<!-- Leave Requests Table -->
<div class="card">
    <div class="card-title">Leave Requests (<?= count($leaves) ?>)</div>
    <div class="table-wrapper" data-table-search="Search leave requests...">
        <table>
            <thead>
                <tr><th>Employee</th><th>Department</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>TM Endorsement</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($leaves as $lr): ?>
                <?php
                    $requiresThreeMonths = in_array($lr['leave_type'], ['sick', 'vacation', 'emergency'], true);
                    $serviceThreshold = (new DateTimeImmutable('today'))->modify('-3 months')->format('Y-m-d');
                    $serviceEligible = !$requiresThreeMonths || (!empty($lr['date_hired']) && $lr['date_hired'] <= $serviceThreshold);
                ?>
                <tr style="<?= !empty($lr['urgent']) ? 'background:#FEF2F2;' : '' ?>">
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($lr['employee_name']) ?></div>
                        <div style="font-size:11px; color:#9CA3AF;"><?= htmlspecialchars($lr['employee_id']) ?></div>
                    </td>
                    <td><?= htmlspecialchars($lr['department']) ?></td>
                    <td>
                        <span class="badge badge-blue"><?= ucfirst($lr['leave_type']) ?></span>
                        <?php if ($requiresThreeMonths && !$serviceEligible): ?><div style="font-size:11px;color:#B45309;font-weight:600;">Ineligible: requires 3 months of service</div><?php endif; ?>
                        <?php if (!empty($lr['urgent'])): ?><span class="badge badge-red">URGENT</span><?php endif; ?>
                    </td>
                    <td><?= formatDate($lr['start_date']) ?></td>
                    <td><?= formatDate($lr['end_date']) ?></td>
                    <td style="text-align:center;"><?= $lr['total_days'] ?></td>
                    <td style="font-size:12px;"><?= htmlspecialchars(substr($lr['reason'], 0, 40)) ?>...</td>
                    <td style="font-size:12px;">
                        <?php if ($lr['endorsement_status'] === 'endorsed'): ?>
                            <span class="badge badge-green">Endorsed</span><div style="color:#6B7280;"><?= htmlspecialchars($lr['endorsed_by_name'] ?? '') ?></div>
                        <?php elseif ($lr['endorsement_status'] === 'not_recommended'): ?>
                            <span class="badge badge-red">Not recommended</span><div style="color:#6B7280;"><?= htmlspecialchars($lr['endorsed_by_name'] ?? '') ?></div>
                        <?php elseif (getDepartmentTeamLeaderId($db, $lr['employee_id'])): ?>
                            <span class="badge badge-amber">Awaiting Team Manager</span>
                        <?php else: ?><span style="color:#9CA3AF;">No Team Manager</span><?php endif; ?>
                        <?php if ($lr['credit_available'] !== null && $lr['credit_available'] < 0): ?><div style="color:#DC2626;font-weight:600;">No credit</div><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($lr['status'] === 'pending' && $lr['endorsement_status'] === 'endorsed'): ?>
                            <span class="badge badge-blue">Awaiting HR decision</span>
                        <?php elseif ($lr['status'] === 'pending' && getDepartmentTeamLeaderId($db, $lr['employee_id'])): ?>
                            <span class="badge badge-amber">Not yet at HR</span>
                        <?php else: ?><span class="badge <?= statusBadge($lr['status']) ?>"><?= htmlspecialchars(formatLeaveStatus($lr['status'])) ?></span><?php endif; ?>
                        <?php if (!empty($lr['documents_flagged_at'])): ?><br><span class="badge badge-red">HR follow-up overdue</span><?php endif; ?>
                        <?php if (!empty($lr['documents_due_at']) && in_array($lr['status'], ['approved_pending_documents', 'documents_rejected'], true)): ?>
                            <div style="font-size:10px;color:#6B7280;">Due <?= htmlspecialchars(formatDate($lr['documents_due_at'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <button class="btn-secondary btn-sm leave-details-button" type="button"
                                    data-leave="<?= htmlspecialchars(json_encode($lr, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>"
                                    onclick="viewLeaveDetails(this)">Details</button>
                            <?php if ($lr['status'] === 'pending'): ?>
                            <!-- Approve Button — may remarks modal -->
                            <button class="btn-secondary btn-sm" style="color:#059669;" <?= !$serviceEligible ? 'disabled title="Employee must complete three months of service"' : '' ?>
                                    onclick="reviewLeave(<?= $lr['id'] ?>, 'approve', '<?= htmlspecialchars($lr['employee_name']) ?>')">
                                Approve
                            </button>
                            <button class="btn-secondary btn-sm" style="color:#DC2626;"
                                    onclick="reviewLeave(<?= $lr['id'] ?>, 'reject', '<?= htmlspecialchars($lr['employee_name']) ?>')">
                                Reject
                            </button>
                            <?php endif; ?>
                            <?php if ($lr['status'] === 'documents_submitted'): ?>
                            <button class="btn-secondary btn-sm" style="color:#059669;"
                                    onclick="reviewLeave(<?= $lr['id'] ?>, 'verify_document', '<?= htmlspecialchars($lr['employee_name'], ENT_QUOTES) ?>')">
                                Verify document
                            </button>
                            <button class="btn-secondary btn-sm" style="color:#DC2626;"
                                    onclick="reviewLeave(<?= $lr['id'] ?>, 'reject_document', '<?= htmlspecialchars($lr['employee_name'], ENT_QUOTES) ?>')">
                                Reject document
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($leaves)): ?>
                <tr><td colspan="10" style="text-align:center; color:#9CA3AF; padding:24px;">No leave requests found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<div class="modal-overlay" id="leaveDetailsModal" role="dialog" aria-modal="true" aria-labelledby="leaveDetailsTitle">
    <div class="modal-box" style="max-width:680px;max-height:90vh;overflow:auto;">
        <div class="modal-header">
            <div class="modal-title" id="leaveDetailsTitle">Leave Request Details</div>
            <button class="modal-close" type="button" aria-label="Close details" onclick="closeModal('leaveDetailsModal')">&times;</button>
        </div>
        <dl id="leaveDetailsGrid" class="leave-detail-grid"></dl>
        <div style="display:flex;justify-content:flex-end;margin-top:20px;">
            <button type="button" class="btn-secondary" onclick="closeModal('leaveDetailsModal')">Close</button>
        </div>
    </div>
</div>

<!-- Add Leave Credit (HR only) -->
<div class="modal-overlay" id="creditModal">
    <div class="modal-box" style="max-width:440px;">
        <div class="modal-header">
            <div class="modal-title">Add Leave Credit</div>
            <button class="modal-close" onclick="closeModal('creditModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_credit">
            <div class="form-group"><label class="form-label">Employee</label>
                <select name="employee_id" class="form-select" required>
                    <option value="">-- Select employee --</option>
                    <?php foreach ($empOptions as $eo): ?><option value="<?= htmlspecialchars($eo['employee_id']) ?>"><?= htmlspecialchars($eo['n'] . ' (' . $eo['employee_id'] . ')') ?></option><?php endforeach; ?>
                </select></div>
            <div class="form-group"><label class="form-label">Leave type</label>
                <select name="leave_type" class="form-select" required>
                    <?php $creditLeaveTypes = getLeaveTypeDefinitions($db, true); foreach ($creditLeaveTypes as $typeKey => $typeDef):
                        if ($typeKey === 'others') continue;
                        $duplicateCreditType = false;
                        if (!empty($typeDef['is_custom'])) foreach ($creditLeaveTypes as $otherKey => $otherDef) {
                            if ($typeKey !== $otherKey && leaveTypeNameIsSimilar($typeDef['name'], $otherDef['name'])) { $duplicateCreditType = true; break; }
                        }
                        if ($duplicateCreditType) continue;
                    ?>
                    <option value="<?= htmlspecialchars($typeKey) ?>"><?= htmlspecialchars($typeDef['name']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group"><label class="form-label">Days to add (1–30)</label><input type="number" name="days" min="1" max="30" value="1" class="form-input" required></div>
            <div class="form-group"><label class="form-label">Year</label><input type="number" name="year" value="<?= date('Y') ?>" class="form-input" required></div>
            <div class="form-group"><label class="form-label">Reason *</label><textarea name="reason" class="form-textarea" rows="2" required placeholder="e.g. Approved extra credit after Team Manager coordination"></textarea></div>
            <div style="display:flex; gap:10px; margin-top:12px;">
                <button type="submit" class="btn-primary">Add Credit</button>
                <button type="button" class="btn-secondary" onclick="closeModal('creditModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Review Modal (para sa approve at reject) -->
<div class="modal-overlay" id="reviewModal">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-header">
            <div class="modal-title" id="reviewModalTitle">Review Leave</div>
            <button class="modal-close" onclick="closeModal('reviewModal')">&times;</button>
        </div>
        <form method="POST" id="reviewForm">
            <input type="hidden" name="action"   id="reviewAction">
            <input type="hidden" name="leave_id" id="reviewLeaveId">
            <div class="form-group">
                <label class="form-label">Employee</label>
                <input type="text" id="reviewEmpName" class="form-input" readonly>
            </div>
            <div class="form-group">
                <label class="form-label" id="remarksLabel">Remarks <span style="color:#9CA3AF;font-size:11px;">(optional)</span></label>
                <textarea name="remarks" id="reviewRemarks" class="form-textarea" rows="3"
                          placeholder="Enter remarks or reason..."></textarea>
            </div>
            <div style="display:flex; gap:10px; margin-top:12px;">
                <button type="submit" class="btn-primary" id="reviewSubmitBtn">Submit</button>
                <button type="button" class="btn-secondary" onclick="closeModal('reviewModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
const leaveProposalSearch = document.getElementById('leaveProposalSearch');
if (leaveProposalSearch) {
    leaveProposalSearch.addEventListener('input', function () {
        const query = this.value.trim().toLocaleLowerCase();
        const rows = Array.from(document.querySelectorAll('.leave-proposal-row'));
        let visibleCount = 0;
        rows.forEach(row => {
            const matches = row.textContent.toLocaleLowerCase().includes(query);
            row.hidden = !matches;
            if (matches) visibleCount++;
        });
        const noResults = document.getElementById('leaveProposalNoSearchResults');
        if (noResults) noResults.hidden = !query || visibleCount > 0;
    });
}

function viewLeaveTypeProposal(row) {
    const proposal = JSON.parse(row.dataset.proposal);
    const grid = document.getElementById('leaveTypeProposalDetailsGrid');
    grid.replaceChildren();
    const add = (label, value) => {
        const item = document.createElement('div');
        item.className = 'leave-detail-item';
        const term = document.createElement('dt'); term.textContent = label;
        const detail = document.createElement('dd'); detail.textContent = value || '—';
        item.append(term, detail); grid.appendChild(item);
    };
    const reviewer = proposal.reviewer_name || proposal.reviewed_by
        || (proposal.status === 'rejected' && String(proposal.review_remarks || '').startsWith('Automatically rejected:') ? 'System (automatic)' : '—');
    add('Status', proposal.status.toUpperCase());
    add('Leave type', proposal.name);
    add('Employee', proposal.employee_name + ' (' + proposal.employee_id + ')');
    add('Annual days', String(proposal.annual_days));
    add('Proposal description', proposal.description || 'None provided');
    add('Supporting documents', Number(proposal.document_required) ? ('Required: ' + (proposal.document_guidance || 'Guidance not provided')) : 'None proposed');
    add('Employee reason', proposal.request_reason || '—');
    add('HR decision remarks', proposal.review_remarks || 'No review remarks');
    add('Reviewed by', reviewer);
    add('Date proposed', proposal.created_at || '—');
    add('Date decided', proposal.reviewed_at || 'Not reviewed yet');
    openModal('leaveTypeProposalDetailsModal');
}

function reviewLeaveTypeProposal(id, decision, name) {
    const approving = decision === 'approve';
    document.getElementById('proposalReviewId').value = id;
    document.getElementById('proposalReviewDecision').value = decision;
    document.getElementById('leaveTypeProposalTitle').textContent = approving ? 'Approve Leave Type Proposal' : 'Reject Leave Type Proposal';
    document.getElementById('leaveTypeProposalPrompt').textContent = (approving ? 'Approve and add "' : 'Reject "') + name + '" as a leave type?';
    const remarks = document.getElementById('proposalReviewRemarks');
    remarks.value = '';
    remarks.required = !approving;
    document.getElementById('proposalRemarksLabel').textContent = approving ? 'HR remarks (optional)' : 'Reason for rejection (required)';
    document.getElementById('proposalReviewProceed').textContent = 'Proceed';
    document.getElementById('proposalReviewProceed').style.background = approving ? '#059669' : '#DC2626';
    openModal('leaveTypeProposalModal');
}

function viewLeaveDetails(button) {
    const request = JSON.parse(button.dataset.leave);
    const grid = document.getElementById('leaveDetailsGrid');
    grid.replaceChildren();
    const add = (label, value, wide) => {
        const item = document.createElement('div');
        item.className = 'leave-detail-item';
        if (wide) item.style.gridColumn = '1 / -1';
        const term = document.createElement('dt'); term.textContent = label;
        const dd = document.createElement('dd');
        if (value instanceof Node) dd.appendChild(value);
        else dd.textContent = (value === null || value === '' || value === undefined) ? '—' : String(value);
        item.append(term, dd); grid.appendChild(item);
    };
    const fileLink = (path, name, query) => {
        if (!path) return null;
        const a = document.createElement('a');
        a.href = '../../api/leave_attachment.php?id=' + encodeURIComponent(request.id) + query;
        a.target = '_blank'; a.rel = 'noopener'; a.textContent = name || 'Open attachment';
        return a;
    };
    const dates = request.start_date === request.end_date ? request.start_date : request.start_date + ' to ' + request.end_date;
    const tm = [request.endorsement_status && request.endorsement_status !== 'none' ? request.endorsement_status.replace(/_/g, ' ') : '', request.endorsement_comment].filter(Boolean).join(' — ');
    const processed = request.reviewed_by ? request.reviewed_by + (request.review_date ? ' · ' + request.review_date : '') : '';
    // Required order: Status, Employee Name, Employee Number, Leave Type, Leave Date, Days, Reason, Attachment,
    // Date Filed, Team Manager remarks, Processed/Approved By, Final Remarks
    add('Status', request.status ? request.status.replace(/_/g, ' ').toUpperCase() : '');
    add('Employee Name', request.employee_name);
    add('Employee Number', request.employee_id);
    add('Leave Type', request.leave_type);
    add('Leave Date', dates);
    add('Days', request.total_days);
    add('Reason', request.reason, true);
    add('Attachment', fileLink(request.attachment_path, request.attachment_name, ''));
    add('Date Filed', request.created_at);
    add('Team Manager Remarks', tm);
    add('Processed / Approved By', processed);
    add('Final Remarks', request.review_remarks, true);
    // Additional information
    add('Request ID', request.id);
    add('Department', request.department);
    add('Position', request.position);
    add('Urgent', Number(request.urgent) === 1 ? 'Yes' : 'No');
    add('Filed by', request.requested_by_name ? request.requested_by_name + ' (Team Manager, on behalf)' : 'Employee');
    add('Team Manager', request.team_leader_name);
    add('Endorsed by', request.endorsed_by_name);
    add('Endorsed at', request.endorsed_at);
    add('Credit left (after this request)', request.credit_available === null ? '—' : request.credit_available);
    add('Post-approval document', fileLink(request.document_path, request.document_name, '&kind=document'));
    add('Documents due', request.documents_due_at);
    add('Document reviewed by', request.document_reviewed_by);
    add('Document review date', request.document_reviewed_at);
    add('Document review remarks', request.document_review_remarks);
    add('Overdue follow-up flagged', request.documents_flagged_at);
    // Audit trail: who did what, when, with which remarks
    if (request.events && request.events.length) {
        const dd = document.createElement('div');
        request.events.forEach(ev => {
            const line = document.createElement('div');
            line.textContent = ev.created_at + ' — ' + ev.action.replace(/_/g, ' ') + ' by ' + ev.actor + (ev.details ? ': ' + ev.details : '');
            dd.appendChild(line);
        });
        add('History', dd, true);
    }
    openModal('leaveDetailsModal');
}

// Binubuksan ang review modal para sa approve o reject
function reviewLeave(id, action, empName) {
    document.getElementById('reviewLeaveId').value = id;
    document.getElementById('reviewAction').value  = action;
    document.getElementById('reviewEmpName').value = empName;

    var isReject = (action === 'reject' || action === 'reject_document');
    document.getElementById('reviewRemarks').required = isReject;   // rejection reason is mandatory
    document.getElementById('remarksLabel').innerHTML = isReject
        ? 'Rejection reason <span style="color:#DC2626;">* required</span>'
        : 'Remarks <span style="color:#9CA3AF;font-size:11px;">(optional)</span>';
    // I-update ang modal title at button color depende sa action
    if (action === 'approve' || action === 'verify_document') {
        document.getElementById('reviewModalTitle').textContent = action === 'approve' ? 'Approve Leave Request' : 'Verify Submitted Document';
        document.getElementById('reviewSubmitBtn').textContent = action === 'approve' ? 'Approve' : 'Verify and Approve';
        document.getElementById('reviewSubmitBtn').style.background = '#059669';
    } else {
        document.getElementById('reviewModalTitle').textContent = action === 'reject' ? 'Reject Leave Request' : 'Reject Submitted Document';
        document.getElementById('reviewSubmitBtn').textContent = action === 'reject' ? 'Reject Request' : 'Reject Document';
        document.getElementById('reviewSubmitBtn').style.background = '#DC2626';
    }
    openModal('reviewModal');
}

</script>
