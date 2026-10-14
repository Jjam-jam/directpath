<?php

// Directpath HRMS — Employee Leave Module

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once __DIR__ . '/leave_rules.php';
requireRole('employee');

$pageTitle  = 'Leave';
$activePage = 'leave';
$db  = getDB();
$empId = $_SESSION['employee_id'];
$employeeInfoStmt = $db->prepare("SELECT gender, civil_status, date_hired, is_team_leader FROM users WHERE employee_id=?");
$employeeInfoStmt->execute([$empId]);
$empInfo = $employeeInfoStmt->fetch() ?: [];
$gender = strtolower((string)($empInfo['gender'] ?? ''));
$leaveLabels = [
    'solo_parent' => 'Solo Parent Leave',
    'special_women' => 'Special Leave for Women',
    'vawc' => 'VAWC Leave',
    'sil' => 'Service Incentive Leave',
];
$leaveDefinitions = getLeaveTypeDefinitions($db);
$activeLeaveDefinitions = getLeaveTypeDefinitions($db, true);
$leaveLabels = array_replace($leaveLabels, array_map(static fn($def) => $def['name'], $leaveDefinitions));
$leaveAllotments = array_map(static fn($def) => (int)$def['annual_days'], $leaveDefinitions);
$requiredDocumentTypes = ['maternity', 'paternity', 'solo_parent', 'special_women', 'vawc'];
$customLeaveGuidance = [];
foreach ($activeLeaveDefinitions as $key => $definition) {
    if (empty($definition['is_custom'])) continue;
    if (!empty($definition['document_required'])) $requiredDocumentTypes[] = $key;
    $customLeaveGuidance[$key] = '<strong>Description:</strong> ' . htmlspecialchars($definition['description'], ENT_QUOTES, 'UTF-8')
        . (!empty($definition['document_required']) ? '<br><strong>Required document:</strong> ' . htmlspecialchars($definition['document_guidance'], ENT_QUOTES, 'UTF-8') : '<br><strong>Documents:</strong> none normally required.')
        . '<br><strong>Entitlement:</strong> ' . (int)$definition['annual_days'] . ' day(s) per year.';
}

$success = '';
$error   = '';

// ── Handle POST Actions ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'request_leave_type') {
        $requestName = trim(strip_tags((string)($_POST['name'] ?? '')));
        $requestDays = (int)($_POST['annual_days'] ?? 0);
        $requestDescription = trim(strip_tags((string)($_POST['description'] ?? '')));
        $requestReason = trim(strip_tags((string)($_POST['request_reason'] ?? '')));
        $requestDoc = isset($_POST['document_required']) ? 1 : 0;
        $requestDocGuidance = trim(strip_tags((string)($_POST['document_guidance'] ?? '')));
        if ($requestName === '' || mb_strlen($requestName) > 80 || $requestDays < 1 || $requestDays > 366 || $requestReason === '') {
            $error = 'Enter a leave name, proposed annual days from 1 to 366, and a reason for your request.';
        } elseif ($requestDoc && $requestDocGuidance === '') {
            $error = 'Please describe the supporting document you propose.';
        } else {
            try {
                $conflictingType = findLeaveTypeNameConflict($db, $requestName);
                $similarType = $conflictingType !== null;
                $reviewNote = $similarType
                    ? "Automatically rejected: this proposal is the same as or too similar to '{$conflictingType}', which already exists or is under review."
                    : null;
                $db->prepare("INSERT INTO custom_leave_type_requests
                    (employee_id,name,annual_days,description,document_required,document_guidance,request_reason,status,review_remarks)
                    VALUES (?,?,?,?,?,?,?, ?, ?)")
                   ->execute([$empId,$requestName,$requestDays,$requestDescription,$requestDoc,$requestDocGuidance,$requestReason,
                       $similarType ? 'rejected' : 'pending', $reviewNote]);
                auditLog($similarType ? 'Auto-Reject Leave Type Proposal' : 'Request Leave Type', 'Leave',
                    ($similarType ? 'Automatically rejected similar proposal: ' : 'Requested leave type: ') . $requestName);
                $success = $similarType
                    ? "Proposal rejected: '{$requestName}' matches or is similar to '{$conflictingType}', which already exists or is under review."
                    : 'Your new leave type proposal was sent to HR for review. It will not be available until approved.';
            } catch (Throwable $e) {
                error_log('Leave type proposal failed: ' . $e->getMessage());
                $error = 'The request could not be submitted. Please try again.';
            }
        }
    }

    // === APPLY FOR LEAVE ===
    if ($action === 'apply') {
        $type      = sanitize($_POST['leave_type'] ?? '');
        $startDate = sanitize($_POST['start_date'] ?? '');
        $endDate   = sanitize($_POST['end_date'] ?? '');
        $reason    = sanitize($_POST['reason'] ?? '');
        $today     = date('Y-m-d');
        $urgent    = $type === 'emergency' || isset($_POST['urgent']) ? 1 : 0;

        // Emergency leave is not schedulable: it always starts today (server-enforced).
        if ($type === 'emergency') {
            $startDate = $today;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)
                || !checkdate((int)substr($endDate, 5, 2), (int)substr($endDate, 8, 2), (int)substr($endDate, 0, 4))
                || $endDate < $today) {
                $endDate = $today;
            }
        }

        $attachName = $attachMime = $attachStored = null;
        $dateStart = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $dateEnd = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        $days = ($dateStart && $dateEnd && $dateEnd >= $dateStart) ? employeeLeaveRequestedDays($db, $type, $startDate, $endDate) : 0;
        $requiredDocTypes = ['maternity', 'paternity', 'solo_parent', 'special_women', 'vawc'];
        $needsProof = ($type === 'sick' && $days >= 3) || in_array($type, $requiredDocTypes, true)
            || (!empty($leaveDefinitions[$type]['is_custom']) && !empty($leaveDefinitions[$type]['document_required']));
        $hasUpload  = !empty($_FILES['attachment']['name']) && ($_FILES['attachment']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE;
        if ($type === 'vacation' && $dateStart && $dateEnd) {
            $businessDays = employeeVacationNoticeBusinessDays($db, $today, $startDate);
            if ($businessDays < 3) $error = 'Vacation leave must be filed at least three business days in advance.';
        }

        if ($error) {
            // A prior rule (such as vacation notice) already rejected this request.
        } elseif ($type === 'others') {
            $error = 'This legacy leave type is no longer available for new requests.';
        } elseif (!isset($leaveAllotments[$type]) || empty($activeLeaveDefinitions[$type])) {
            $error = 'Please choose a valid leave type.';
        } elseif (!$startDate || !$endDate || !$reason) {
            $error = 'All fields are required.';
        } elseif ($startDate > $endDate) {
            $error = 'End date must be after or equal to start date.';
        } elseif ($type === 'sick' && $startDate < date('Y-m-d', strtotime('-7 days'))) {
            $error = 'Sick leave can only be filed for today, the last 7 days, or future dates.';
        } elseif ($type !== 'emergency' && $type !== 'sick' && $startDate < $today) {
            $error = 'You cannot file leave for past dates.';
        } elseif (in_array($type, ['maternity','special_women'], true) && $gender !== 'female') {
            $error = 'This leave type is available only to eligible female employees.';
        } elseif ($type === 'paternity' && ($gender !== 'male' || strtolower((string)($empInfo['civil_status'] ?? '')) !== 'married')) {
            $error = 'Paternity leave is available to qualified married male employees.';
        } elseif ($type === 'paternity' && $days > 7) {
            $error = 'Paternity leave is limited to seven days per qualifying delivery.';
        } elseif ($type === 'sil' && (empty($empInfo['date_hired']) || $empInfo['date_hired'] > date('Y-m-d', strtotime('-1 year')))) {
            $error = 'Service Incentive Leave requires at least one year of service.';
        } elseif ($needsProof && !$hasUpload) {
            $error = 'Sick leave of three or more consecutive days requires a medical certificate signed by a licensed physician.';
        } else {
            if ($hasUpload) {
                try {
                    $saved = storeLeaveAttachment($_FILES['attachment'], $empId);
                    $attachStored = $saved['path'];
                    $attachName = $saved['name'];
                    $attachMime = $saved['mime'];
                } catch (InvalidArgumentException $e) {
                    $error = $e->getMessage();
                } catch (Throwable $e) {
                    error_log('Leave attachment save failed: ' . $e->getMessage());
                    $error = 'Failed to save the attachment.';
                }
            }

            if (!$error) {
                $leaveYear = (int)date('Y', strtotime($startDate));
                $db->beginTransaction();
                try {
                    $overlap = $db->prepare("SELECT id FROM leave_requests WHERE employee_id=? AND status<>'rejected' AND start_date<=? AND end_date>=? LIMIT 1 FOR UPDATE");
                    $overlap->execute([$empId, $endDate, $startDate]);
                    if ($overlap->fetchColumn()) {
                        $db->rollBack();
                        $error = 'This request overlaps an existing pending or approved leave request.';
                        if ($attachStored && is_file(LEAVE_ATTACH_PATH . $attachStored)) unlink(LEAVE_ATTACH_PATH . $attachStored);
                    } else {
                    $balance   = getLeaveBalance($empId, $leaveYear);
                    $available = $balance[$type]['available'] ?? 0;
                    if ($type !== 'others' && $days > $available) {
                        $db->rollBack();
                        $error = "Insufficient leave credits. You have $available available $type leave day(s) (after pending requests), but requested $days.";
                        if ($attachStored) unlink(LEAVE_ATTACH_PATH . $attachStored);
                    } else {
                    $db->prepare("
                        INSERT INTO leave_requests
                            (employee_id, leave_type, start_date, end_date, total_days, reason, urgent, auto_approval_after, attachment_path, attachment_name, attachment_mime)
                        VALUES (?,?,?,?,?,?,?,CASE WHEN ? = 1 THEN DATE_ADD(NOW(), INTERVAL 2 HOUR) ELSE NULL END,?,?,?)
                    ")->execute([
                        $empId, $type, $startDate, $endDate, $days, $reason, $urgent,
                        $type === 'emergency' ? 1 : 0, $attachStored, $attachName, $attachMime
                    ]);
                    $leaveId = (int)$db->lastInsertId();
                    logLeaveRequestEvent($db, $leaveId, 'filed', $_SESSION['name'], $urgent ? 'Urgent leave request filed.' : null);
                    $tlId = getDepartmentTeamLeaderId($db, $empId);
                    if ($tlId) {
                        createLeaveNotification($db, $leaveId, "{$_SESSION['name']} filed a {$type} leave request that needs your review.", $tlId);
                    }
                    $db->commit();
                    auditLog('Apply Leave', 'Leave', "Applied $type leave $startDate to $endDate ($days days)" . ($attachStored ? ' with attachment' : ''));
                    $success = $tlId
                        ? 'Leave request submitted. It is waiting for your Team Manager’s review.'
                        : 'Leave request submitted, but no Team Manager is assigned to your department. HR will not receive it until a Team Manager endorses it.';
                    }
                    }
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    if ($attachStored && is_file(LEAVE_ATTACH_PATH . $attachStored)) unlink(LEAVE_ATTACH_PATH . $attachStored);
                    error_log('Leave application failed: ' . $e->getMessage());
                    $error = 'The leave request could not be saved. Please try again.';
                }
            }
        }
    }

    // === SUBMIT OR RE-SUBMIT URGENT LEAVE DOCUMENT ===
    if ($action === 'submit_document') {
        $lid = (int)($_POST['leave_id'] ?? 0);
        $file = $_FILES['document'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error = 'Please choose a document to submit.';
        } else {
            $storedDocument = null;
            try {
                $saved = storeLeaveAttachment($file, $empId, 'leave_doc_');
                $storedDocument = $saved['path'];
                $db->beginTransaction();
                $stmt = $db->prepare("
                    SELECT id, status, documents_due_at, documents_due_at >= NOW() AS deadline_open
                    FROM leave_requests
                    WHERE id = ? AND employee_id = ?
                    FOR UPDATE
                ");
                $stmt->execute([$lid, $empId]);
                $request = $stmt->fetch();
                if (!$request
                    || !in_array($request['status'], ['approved_pending_documents', 'documents_rejected'], true)
                    || !$request['documents_due_at']
                    || !(int)$request['deadline_open']) {
                    throw new DomainException('The document submission deadline has passed or this request is not awaiting documents.');
                }
                $update = $db->prepare("
                    UPDATE leave_requests
                    SET status='documents_submitted', document_path=?, document_name=?, document_mime=?
                    WHERE id=? AND status IN ('approved_pending_documents','documents_rejected') AND documents_due_at >= NOW()
                ");
                $update->execute([$saved['path'], $saved['name'], $saved['mime'], $lid]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('The document submission deadline has passed or this request is not awaiting documents.');
                }
                logLeaveRequestEvent($db, $lid, 'document_submitted', $_SESSION['name'], $saved['name']);
                createLeaveNotification($db, $lid, "Documents submitted for leave request #$lid; HR review is required.", null, 'hr_admin');
                $db->commit();
                auditLog('Submit Leave Document', 'Leave', "Submitted document for leave request ID: $lid");
                $success = 'Document submitted. It is awaiting supervisor/HR review.';
            } catch (InvalidArgumentException $e) {
                if ($db->inTransaction()) $db->rollBack();
                if ($storedDocument && is_file(LEAVE_ATTACH_PATH . $storedDocument)) unlink(LEAVE_ATTACH_PATH . $storedDocument);
                $error = $e->getMessage();
            } catch (DomainException $e) {
                if ($db->inTransaction()) $db->rollBack();
                if ($storedDocument && is_file(LEAVE_ATTACH_PATH . $storedDocument)) unlink(LEAVE_ATTACH_PATH . $storedDocument);
                $error = $e->getMessage();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                if ($storedDocument && is_file(LEAVE_ATTACH_PATH . $storedDocument)) unlink(LEAVE_ATTACH_PATH . $storedDocument);
                error_log('Leave document submission failed: ' . $e->getMessage());
                $error = 'The document could not be submitted. Please try again.';
            }
        }
    }

    // === CANCEL LEAVE REQUEST ===
    if ($action === 'cancel') {
        $lid  = (int)($_POST['leave_id'] ?? 0);
        $stmt = $db->prepare("SELECT status FROM leave_requests WHERE id=? AND employee_id=?");
        $stmt->execute([$lid, $empId]);
        $lr = $stmt->fetch();

        // Pending lang ang pwedeng i-cancel — hindi na pwede kapag approved/rejected na
        if ($lr && $lr['status'] === 'pending') {
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE leave_requests SET status='rejected', reviewed_by=?, review_date=NOW(), review_remarks='Cancelled by applicant' WHERE id=? AND employee_id=? AND status='pending'")
                   ->execute([$_SESSION['name'], $lid, $empId]);
                if (!auditLog('Cancel Leave Request', 'Leave', "Applicant cancelled pending leave request ID: $lid")) {
                    throw new RuntimeException('Audit log write failed.');
                }
                $db->commit();
                $success = 'Leave request cancelled.';
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Leave request was not cancelled because its history could not be recorded.';
            }
        } else {
            $error = 'Cannot cancel this request. It may have already been reviewed.';
        }
    }
}

// Kunin lahat ng leave requests ng employee, pinaka-bago muna
$stmt = $db->prepare("SELECT * FROM leave_requests WHERE employee_id=? ORDER BY created_at DESC");
$stmt->execute([$empId]);
$leaves = $stmt->fetchAll();
$myLeaveTypeRequests = [];
$customLeaveMigrationMissing = false;
try {
    $typeRequestStmt = $db->prepare("SELECT name,annual_days,status,review_remarks,created_at FROM custom_leave_type_requests WHERE employee_id=? ORDER BY created_at DESC LIMIT 5");
    $typeRequestStmt->execute([$empId]);
    $myLeaveTypeRequests = $typeRequestStmt->fetchAll();
} catch (PDOException $e) {
    if ((int)($e->errorInfo[1] ?? 0) === 1146) $customLeaveMigrationMissing = true;
    else { error_log('Load employee leave type proposals failed: ' . $e->getMessage()); $error = 'Could not load your leave type proposals. Check the database error log.'; }
}

// Stats para sa summary cards
$approved = count(array_filter($leaves, fn($l) => $l['status'] === 'approved'));
$pending  = count(array_filter($leaves, fn($l) => in_array($l['status'], ['pending', 'approved_pending_documents', 'documents_submitted', 'documents_rejected'], true)));

// Kunin ang leave balance para sa current year — gagamitin sa JS para sa date picker limits
$thisYear     = (int)date('Y');
$currentYear  = (int)($_GET['year'] ?? $thisYear);
if ($currentYear < $thisYear - 3 || $currentYear > $thisYear + 1) $currentYear = $thisYear;
$leaveBalance = getLeaveBalance($empId, $currentYear);
$standardLeaveCardKeys = ['vacation','sick','emergency','maternity','paternity','solo_parent','special_women','vawc','sil'];
$otherLeaveBalance = ['total'=>0, 'used'=>0, 'pending'=>0, 'available'=>0, 'type_count'=>0];
foreach ($leaveBalance as $type => $balance) {
    if (in_array($type, $standardLeaveCardKeys, true)) continue;
    if ($type !== 'others' && empty($activeLeaveDefinitions[$type])) continue;
    $otherLeaveBalance['total'] += (int)$balance['total'];
    $otherLeaveBalance['used'] += (int)$balance['used'];
    $otherLeaveBalance['pending'] += (int)$balance['pending'];
    $otherLeaveBalance['available'] += (int)$balance['available'];
    if (!empty($leaveDefinitions[$type]['is_custom']) && !empty($activeLeaveDefinitions[$type])) $otherLeaveBalance['type_count']++;
}
// Credits used by the apply form are always for the year being filed (this year)
$applyBalance = $currentYear === $thisYear ? $leaveBalance : getLeaveBalance($empId, $thisYear);
$holidayDates = [];
$holidayQuery = $db->prepare('SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?');
$holidayQuery->execute([($thisYear - 3) . '-01-01', ($thisYear + 1) . '-12-31']);
$holidayDates = array_values($holidayQuery->fetchAll(PDO::FETCH_COLUMN));

include '../../includes/header.php';
?>

<!-- Boxicons CDN -->

<style>
.stat-icon-box {
    width: 48px; height: 48px; min-width: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px;
}
.leave-balance-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 16px;
}

.leave-bal-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 16px;
    text-align: center;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.leave-bal-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 12px rgba(0,0,0,0.1);
}

.leave-bal-card .bal-type {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
    margin-bottom: 6px;
}

.leave-bal-card .bal-number {
    font-size: 22px;
    font-weight: 700;
    color: var(--text-dark);
    margin-bottom: 4px;
}

.leave-bal-card .bal-sub {
    font-size: 12px;
    color: #6B7280;
}

/* Highlight states */
.leave-bal-card.zero-balance {
    background: #FEF2F2;
    border-color: #DC2626;
    color: #DC2626;
}

.leave-bal-card.low-balance {
    background: #FFFBEB;
    border-color: #D97706;
    color: #92400E;
}
.disabled-leave {
    filter: grayscale(100%);
    opacity: 0.5;
    pointer-events: none;
    cursor: not-allowed;
}

/* Date picker highlight styling */
.days-counter {
    background: #EFF6FF;
    border: 1px solid #BFDBFE;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    color: #1D4ED8;
    margin-top: 8px;
    display: none;
}
.days-counter.warning { background: #FEF3C7; border-color: #FCD34D; color: #D97706; }
.days-counter.danger  { background: #FEF2F2; border-color: #FECACA; color: #DC2626; }
</style>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($customLeaveMigrationMissing): ?><div class="alert alert-error">New leave type requests are not enabled yet. HR/Admin must apply <code>config/custom_leave_types_migration.sql</code> first.</div><?php endif; ?>

<!-- Leave Balance Cards — same allotment rules for every employee, shown per type and year -->
<div class="card mb-4">
    <div class="card-title">
        <span>Leave Credits (<?= $currentYear ?>)</span>
        <form method="GET" style="display:inline;">
            <select name="year" class="form-select" style="width:auto;padding:3px 8px;font-size:12px;" onchange="this.form.submit()">
                <?php for ($y = $thisYear + 1; $y >= $thisYear - 3; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $currentYear ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </div>
    <div class="leave-balance-grid">
<?php foreach ($standardLeaveCardKeys as $type):
    if (!isset($leaveBalance[$type])) continue;
    $bal = $leaveBalance[$type];
    $cssClass = '';
    if ($bal['available'] === 0) $cssClass = 'zero-balance';
    elseif ($bal['available'] <= 3) $cssClass = 'low-balance';
    if (($gender === 'male' && $type === 'maternity') || ($gender === 'female' && $type === 'paternity')) $cssClass .= ' disabled-leave';
?>
<div class="leave-bal-card <?= $cssClass ?>">
    <div class="bal-type"><?= htmlspecialchars($leaveLabels[$type] ?? (ucfirst(str_replace('_', ' ', $type)) . ' Leave')) ?></div>
    <div class="bal-number"><?= $bal['available'] ?></div>
    <div class="bal-sub">
        available of <?= $bal['total'] ?><br>
        <?= $bal['used'] ?> used<?= $bal['pending'] ? ' · ' . $bal['pending'] . ' pending' : '' ?>
    </div>
</div>
<?php endforeach; ?>
<div class="leave-bal-card <?= $otherLeaveBalance['available'] === 0 ? 'zero-balance' : ($otherLeaveBalance['available'] <= 3 ? 'low-balance' : '') ?>">
    <div class="bal-type">Other Leaves</div>
    <div class="bal-number"><?= $otherLeaveBalance['available'] ?></div>
    <div class="bal-sub">
       available of <?= $otherLeaveBalance['available'] ?> <br> 
       <?= $otherLeaveBalance['used'] ?> used<?= $otherLeaveBalance['pending'] ? ' · ' . $otherLeaveBalance['pending'] . ' pending' : '' ?>
    </div>
</div>
    </div>
    <div style="font-size:11px;color:#9CA3AF;margin-top:10px;">Available = total credits − approved days − days in pending requests.</div>
</div>

<!-- Stats Summary -->
<div class="row g-3 mb-4">
    <div class="col-4">
        <div class="stat-card">
            <div class="stat-icon green"><i class="bx bx-check-circle"></i></div>
            <div>
                <div class="stat-value"><?= $approved ?></div>
                <div class="stat-label">Approved</div>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="stat-card">
            <div class="stat-icon amber"><i class="bx bx-time-five"></i></div>
            <div>
                <div class="stat-value"><?= $pending ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bx bx-file"></i></div>
            <div>
                <div class="stat-value"><?= count($leaves) ?></div>
                <div class="stat-label">Total Requests</div>
            </div>
        </div>
    </div>
</div>

<div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
    <div style="display:flex;gap:8px;">
        <button class="btn-secondary" type="button" onclick="openModal('requestLeaveTypeModal')">+ Propose New Leave Type</button>
        <button class="btn-primary" type="button" onclick="openModal('applyModal')">+ Apply for Leave</button>
    </div>
</div>

<?php if ($myLeaveTypeRequests): ?>
<div class="card">
    <div class="card-title">My New Leave Type Proposals</div>
    <div class="table-wrapper"><table><thead><tr><th>Proposed type</th><th>Annual days</th><th>Requested</th><th>Status</th><th>HR remarks</th></tr></thead><tbody>
    <?php foreach ($myLeaveTypeRequests as $typeRequest): ?>
    <tr><td><?= htmlspecialchars($typeRequest['name']) ?></td><td><?= (int)$typeRequest['annual_days'] ?></td><td><?= date('M d, Y', strtotime($typeRequest['created_at'])) ?></td><td><?= htmlspecialchars(ucfirst($typeRequest['status'])) ?></td><td><?= htmlspecialchars($typeRequest['review_remarks'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-title">Leave Requests</div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Proof</th><th>Status</th><th>Remarks</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php foreach ($leaves as $lr): ?>
                <tr class="leave-row" style="cursor:pointer;" title="Click for details" onclick='showLeaveDetail(<?= json_encode([
                        "id" => (int)$lr["id"], "type" => $leaveLabels[$lr["leave_type"]] ?? (ucfirst(str_replace('_', ' ', $lr["leave_type"])) . ' Leave'), "from" => formatDate($lr["start_date"]), "to" => formatDate($lr["end_date"]),
                        "days" => (int)$lr["total_days"], "reason" => $lr["reason"], "status" => formatLeaveStatus($lr["status"]), "status_key" => $lr["status"],
                        "employee" => $_SESSION["name"], "employee_id" => $_SESSION["employee_id"] ?? "", "endorsement" => $lr["endorsement_status"] ?? "none", "endorsement_comment" => $lr["endorsement_comment"] ?? "", "urgent" => (bool)$lr["urgent"], "documents_due_at" => $lr["documents_due_at"] ?? "",
                        "remarks" => $lr["review_remarks"] ?? "", "reviewed_by" => $lr["reviewed_by"] ?? "",
                        "reviewed_on" => !empty($lr["review_date"]) ? formatDate($lr["review_date"]) : "", "filed" => formatDate($lr["created_at"]),
                        "attachment" => !empty($lr["attachment_path"]), "attachment_name" => $lr["attachment_name"] ?? "", "attachment_mime" => $lr["attachment_mime"] ?? "",
                        "document" => !empty($lr["document_path"]), "document_name" => $lr["document_name"] ?? "", "document_mime" => $lr["document_mime"] ?? "",
                        "document_reviewed_by" => $lr["document_reviewed_by"] ?? "", "document_reviewed_at" => !empty($lr["document_reviewed_at"]) ? formatDate($lr["document_reviewed_at"]) : "",
                        "document_review_remarks" => $lr["document_review_remarks"] ?? "", "documents_flagged" => !empty($lr["documents_flagged_at"])
                    ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>
                    <td>
                        <span class="badge badge-blue"><?= htmlspecialchars($leaveLabels[$lr['leave_type']] ?? (ucfirst(str_replace('_', ' ', $lr['leave_type'])) . ' Leave')) ?></span>
                        <?php if (!empty($lr['urgent'])): ?><span class="badge badge-red">URGENT</span><?php endif; ?>
                    </td>
                    <td><?= formatDate($lr['start_date']) ?></td>
                    <td><?= formatDate($lr['end_date']) ?></td>
                    <td style="text-align:center;"><?= $lr['total_days'] ?></td>
                    <td style="font-size:12px;"><?= htmlspecialchars(mb_strimwidth($lr['reason'], 0, 50, '…')) ?></td>
                    <td>
                        <?= !empty($lr['attachment_path']) ? '<span class="badge badge-blue">Application proof</span>' : '<span style="color:#9CA3AF;">—</span>' ?>
                        <?= !empty($lr['document_path']) ? '<br><span class="badge badge-blue">Submitted document</span>' : '' ?>
                    </td>
                    <td><?php if ($lr['status'] === 'pending' && ($lr['endorsement_status'] ?? 'none') === 'none' && getDepartmentTeamLeaderId($db, $empId)): ?>
                            <span class="badge badge-amber">Awaiting Team Manager</span>
                        <?php elseif ($lr['status'] === 'pending' && ($lr['endorsement_status'] ?? 'none') === 'endorsed'): ?>
                            <span class="badge badge-blue">Awaiting HR</span>
                        <?php else: ?><span class="badge <?= statusBadge($lr['status']) ?>"><?= htmlspecialchars(formatLeaveStatus($lr['status'])) ?></span><?php endif; ?>
                        <?php if (!empty($lr['documents_flagged_at'])): ?><br><span class="badge badge-red">HR follow-up overdue</span><?php endif; ?>
                    </td>
                    <td style="font-size:12px; color:#6B7280;"><?= htmlspecialchars($lr['review_remarks'] ?? '—') ?></td>
                    <td>
                        <?php if ($lr['status'] === 'pending'): ?>
                        <form method="POST" style="display:inline;" onclick="event.stopPropagation();">
                            <input type="hidden" name="action"   value="cancel">
                            <input type="hidden" name="leave_id" value="<?= $lr['id'] ?>">
                            <button type="submit" class="btn-secondary btn-sm" style="color:#CD1E27;"
                                    onclick="return confirm('Cancel this request?')">Cancel</button>
                        </form>
                        <?php elseif (in_array($lr['status'], ['approved_pending_documents', 'documents_rejected'], true)
                            && !empty($lr['documents_due_at']) && strtotime($lr['documents_due_at']) >= time()): ?>
                        <form method="POST" enctype="multipart/form-data" style="display:grid;gap:4px;min-width:180px;" onclick="event.stopPropagation();">
                            <input type="hidden" name="action" value="submit_document">
                            <input type="hidden" name="leave_id" value="<?= (int)$lr['id'] ?>">
                            <input type="file" name="document" accept="image/jpeg,image/png,application/pdf" required style="font-size:11px;">
                            <button type="submit" class="btn-primary btn-sm">Submit proof</button>
                            <span style="font-size:10px;color:#6B7280;">Due <?= htmlspecialchars(formatDate($lr['documents_due_at'])) ?></span>
                        </form>
                        <?php else: ?>
                        <span style="font-size:11px; color:#9CA3AF;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($leaves)): ?>
                <tr><td colspan="9" style="text-align:center; color:#9CA3AF; padding:24px;">No leave requests yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>
<!-- ============================================================ -->
<!-- Apply Leave Modal -->
<!-- ============================================================ -->
<div class="modal-overlay" id="applyModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Apply for Leave</div>
            <button class="modal-close" onclick="closeModal('applyModal')">&times;</button>
        </div>
        <form method="POST" id="leaveForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="apply">

            <div class="form-group">
                <label class="form-label">Leave Type *</label>
                <select name="leave_type" id="leaveType" class="form-select" required onchange="onLeaveTypeChange()">
                    <option value="">-- Select Type --</option>
                    <?php foreach ($applyBalance as $type => $bal):
                        if (!isset($activeLeaveDefinitions[$type])) continue;
                        if ($type === 'others') continue;
                        $typeLower = strtolower($type);
                        $disabled = $typeLower !== 'others' && (int)$bal['available'] <= 0;

                        // Gender restriction logic
                        $isRestricted = false;
                        $restrictReason = '';

                        if (in_array($typeLower, ['maternity','special_women'], true) && $gender !== 'female') {
                            $isRestricted = true;
                            $restrictReason = 'Eligible female employees only';
                        }
                        if ($typeLower === 'paternity' && ($gender !== 'male' || strtolower((string)($empInfo['civil_status'] ?? '')) !== 'married')) {
                            $isRestricted = true;
                            $restrictReason = 'Qualified married male employees only';
                        }
                        if ($typeLower === 'sil' && (empty($empInfo['date_hired']) || $empInfo['date_hired'] > date('Y-m-d', strtotime('-1 year')))) {
                            $isRestricted = true;
                            $restrictReason = 'Requires one year of service';
                        }
                    ?>
                    <option value="<?= $type ?>"
                        <?= ($disabled || $isRestricted) ? 'disabled' : '' ?>
                        data-remaining="<?= $bal['available'] ?>"
                        data-total="<?= $bal['total'] ?>"
                        data-restricted="<?= $isRestricted ? '1' : '0' ?>"
                        <?= $isRestricted ? 'style="color:#9CA3AF; background:#F3F4F6;"' : '' ?>>
                        <?= htmlspecialchars($leaveLabels[$type] ?? (ucfirst(str_replace('_', ' ', $type)) . ' Leave')) ?>
                        (<?= $isRestricted ? 'N/A' : ($typeLower === 'others' ? 'no cap' : $bal['available'] . ' available') ?>)
                        <?= $isRestricted ? ' — ' . $restrictReason : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div id="balanceHint" style="margin-top:6px; font-size:12px; color:#6B7280; display:none;"></div>
            </div>

            <div class="row g-2">
                <div class="col-6">
                    <div class="form-group">
                        <label class="form-label">Start Date *</label>
                        <input type="date" name="start_date" id="startDate" class="form-input" required
                               onchange="onDateChange()">
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-group">
                        <label class="form-label">End Date *</label>
                        <input type="date" name="end_date" id="endDate" class="form-input" required
                               onchange="onDateChange()">
                    </div>
                </div>
            </div>

            <div id="leaveDocuments" style="display:none;margin-top:8px;padding:8px 12px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;font-size:12px;color:#4B5563;"></div>
            <div id="emergencyNote" style="display:none;margin-top:8px;padding:8px 12px;background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;font-size:12px;color:#92400E;">
                Emergency leave starts <strong>today</strong> and cannot be scheduled ahead. Supporting documents may be provided after you return; they are not required to submit this request.
            </div>

            <div class="days-counter" id="daysCounter">
                <span id="daysCountText">0 days selected</span>
            </div>

            <div class="form-group" style="margin-top:12px;">
                <label class="form-label">Reason *</label>
                <textarea name="reason" class="form-textarea" rows="3" required
                          placeholder="Please provide a reason..."></textarea>
            </div>

            <div class="form-group" id="urgentFlagGroup">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="urgent" id="urgentCheck" value="1">
                    <span>Mark this request urgent (proof will be due within 3 days after approval)</span>
                </label>
            </div>

            <div class="form-group" id="proofGroup" style="display:none;">
                <label class="form-label">Supporting document <span id="proofReq" style="color:#CD1E27;"></span></label>
                <input type="file" name="attachment" id="attachment" class="form-input" accept="image/jpeg,image/png,application/pdf">
                <div style="font-size:11px;color:#6B7280;margin-top:4px;" id="proofHint">JPG, PNG or PDF · max 5 MB. Combine multiple required documents into one PDF if needed.</div>
                <img id="proofPreview" alt="" style="display:none;max-width:100%;max-height:160px;margin-top:8px;border-radius:8px;border:1px solid #E5E7EB;">
            </div>

            <div style="display:flex; gap:10px; margin-top:12px;">
                <button type="submit" class="btn-primary" id="submitLeaveBtn">Submit Application</button>
                <button type="button" class="btn-secondary" onclick="closeModal('applyModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="requestLeaveTypeModal">
    <div class="modal-box" style="max-width:540px;max-height:92vh;overflow:auto;">
        <div class="modal-header"><div class="modal-title">Request a New Leave Type</div><button class="modal-close" type="button" onclick="closeModal('requestLeaveTypeModal')">&times;</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="request_leave_type">
            <p style="font-size:12px;color:#6B7280;margin:0 0 14px;">This sends a proposal to HR. It will not create leave credits or be selectable until HR approves it.</p>
            <div class="form-group"><label class="form-label">Proposed leave name *</label><input class="form-input" name="name" maxlength="80" required></div>
            <div class="form-group"><label class="form-label">Proposed annual days *</label><input class="form-input" type="number" name="annual_days" min="1" max="366" value="5" required></div>
            <div class="form-group"><label class="form-label">Description / eligibility</label><textarea class="form-textarea" name="description" maxlength="500" rows="2"></textarea></div>
            <div class="form-group"><label style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="document_required" value="1" onchange="document.getElementById('requestDocRule').hidden=!this.checked"> Proposed type requires a supporting document</label></div>
            <div class="form-group" id="requestDocRule" hidden><label class="form-label">Document guidance *</label><textarea class="form-textarea" name="document_guidance" maxlength="500" rows="2"></textarea></div>
            <div class="form-group"><label class="form-label">Why is this leave needed? *</label><textarea class="form-textarea" name="request_reason" maxlength="500" rows="3" required></textarea></div>
            <div style="display:flex;gap:10px;"><button class="btn-primary" type="submit">Send to HR</button><button class="btn-secondary" type="button" onclick="closeModal('requestLeaveTypeModal')">Cancel</button></div>
        </form>
    </div>
</div>

<!-- Leave detail modal (opened by clicking any row) -->
<div class="modal-overlay" id="detailModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Leave Request Details</div>
            <button class="modal-close" onclick="closeModal('detailModal')">&times;</button>
        </div>
        <div id="detailBody" style="font-size:13px;"></div>
        <div style="margin-top:14px;"><button class="btn-secondary" onclick="closeModal('detailModal')">Close</button></div>
    </div>
</div>

<!-- leave day limits -->

<script>
// ── Leave Balance Data mula sa PHP ──────────────────────────
// Ini-inject natin ang PHP data sa JavaScript para magamit sa client-side validation
// Hindi na kailangan ng AJAX call para makuha ang balance — nandito na lahat
const leaveBalanceData = <?= json_encode($applyBalance) ?>;
const REQUIRED_DOC_TYPES = <?= json_encode(array_values(array_unique($requiredDocumentTypes))) ?>;
const BUSINESS_DAY_LEAVE_TYPES = ['solo_parent', 'sil'];
const LEAVE_HOLIDAYS = new Set(<?= json_encode($holidayDates) ?>);
const MAX_BYTES   = <?= (int)LEAVE_ATTACH_MAX_BYTES ?>;

function localISO(d) {   // local date, not UTC (toISOString shifts the day in +08:00)
    const m = String(d.getMonth() + 1).padStart(2, '0'), day = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + m + '-' + day;
}
function avail(type) { return leaveBalanceData[type] ? leaveBalanceData[type].available : 0; }
function selectedLeaveDays(type, start, end) {
    if (!BUSINESS_DAY_LEAVE_TYPES.includes(type)) {
        return Math.floor((new Date(end + 'T00:00:00') - new Date(start + 'T00:00:00')) / 86400000) + 1;
    }
    let count = 0;
    const d = new Date(start + 'T00:00:00'), last = new Date(end + 'T00:00:00');
    for (; d <= last; d.setDate(d.getDate() + 1)) {
        const iso = localISO(d);
        if (d.getDay() !== 0 && d.getDay() !== 6 && !LEAVE_HOLIDAYS.has(iso)) count++;
    }
    return count;
}
function endDateForCredits(type, start, credits) {
    const d = new Date(start + 'T00:00:00');
    const requestCap = type === 'paternity' ? Math.min(7, credits) : credits;
    if (!BUSINESS_DAY_LEAVE_TYPES.includes(type)) { d.setDate(d.getDate() + Math.max(0, requestCap - 1)); return localISO(d); }
    let count = 0;
    while (count < requestCap) {
        const iso = localISO(d);
        if (d.getDay() !== 0 && d.getDay() !== 6 && !LEAVE_HOLIDAYS.has(iso)) count++;
        if (count < requestCap) d.setDate(d.getDate() + 1);
    }
    return localISO(d);
}

function onLeaveTypeChange() {
    const type       = document.getElementById('leaveType').value;
    const hint       = document.getElementById('balanceHint');
    const startDate  = document.getElementById('startDate');
    const endDate    = document.getElementById('endDate');
    const counter    = document.getElementById('daysCounter');
    const submitBtn  = document.getElementById('submitLeaveBtn');
    const proofGroup = document.getElementById('proofGroup');
    const proofInput = document.getElementById('attachment');
    const emNote     = document.getElementById('emergencyNote');
    const urgentCheck = document.getElementById('urgentCheck');

    startDate.value = ''; endDate.value = '';
    startDate.disabled = false; endDate.disabled = false; startDate.readOnly = false;
    startDate.removeAttribute('max'); endDate.removeAttribute('max');
    counter.style.display = 'none'; submitBtn.disabled = false;
    emNote.style.display = 'none';
    urgentCheck.checked = false;
    urgentCheck.disabled = false;

    const hasDocumentRule = type === 'sick' || type === 'emergency' || REQUIRED_DOC_TYPES.includes(type);
    proofGroup.style.display = hasDocumentRule ? 'block' : 'none';
    proofInput.required = REQUIRED_DOC_TYPES.includes(type);
    document.getElementById('proofReq').textContent = REQUIRED_DOC_TYPES.includes(type) ? '(required)' : (type === 'emergency' ? '(optional)' : '(required for 3+ consecutive days)');
    if (!hasDocumentRule) { proofInput.value = ''; document.getElementById('proofPreview').style.display = 'none'; }
    const docs = {
        maternity: '<strong>Documents:</strong> MAT-1 or SSS confirmation and ultrasound/medical certificate with EDD; after delivery, birth documentation; for miscarriage/emergency termination, applicable medical or fetal-death documentation.<br><strong>Entitlement:</strong> 105 paid days; possible 30-day unpaid extension; additional 15 days for qualified solo parents; 60 days for qualifying miscarriage or emergency termination.',
        paternity: '<strong>Documents:</strong> PSA/LCR certified marriage certificate and medical/live-birth certificate showing relationship to newborn.<br><strong>Entitlement:</strong> up to seven paid days per qualifying delivery, for up to four deliveries, subject to eligibility.',
        solo_parent: '<strong>Documents:</strong> valid Solo Parent ID and Solo Parent Certificate or other eligibility proof.<br><strong>Entitlement:</strong> seven paid working days per year for qualified solo parents.',
        special_women: '<strong>Documents:</strong> OB-GYN medical certificate/discharge summary and operative or histopathological/biopsy report.<br><strong>Entitlement:</strong> up to 60 paid days for qualifying gynecological surgery.',
        vawc: '<strong>Documents:</strong> BPO/TPO/PPO, or legally accepted certification from an authorized barangay official, court, police officer, or qualified social worker.<br><strong>Entitlement:</strong> up to 10 paid days for qualified employees.',
        sick: '<strong>Entitlement:</strong> five days. 1–2 consecutive days: no certificate required. 3 or more consecutive days: physician-signed medical certificate required.',
        emergency: '<strong>Purpose:</strong> sudden unforeseen emergencies. No document is required at filing. HR may request supporting proof after your return.',
        vacation: '<strong>Documents:</strong> none normally required.<br><strong>Filing:</strong> at least three business days in advance, excluding weekends and holidays.',
        sil: '<strong>Documents:</strong> none normally required.<br><strong>Entitlement:</strong> five paid days per year after one year of service for qualified rank-and-file employees; unused credits follow applicable cash-conversion rules.'
    };
    const customDocs = <?= json_encode($customLeaveGuidance) ?>;
    Object.assign(docs, customDocs);
    const documentNote = document.getElementById('leaveDocuments');
    documentNote.innerHTML = docs[type] || (type ? 'Supporting documents are not normally required.' : '');
    documentNote.style.display = type ? 'block' : 'none';

    if (!type) { hint.style.display = 'none'; return; }

    const today = new Date(), todayStr = localISO(today);
    const remaining = avail(type);

    if (type !== 'others' && remaining <= 0) {
        hint.innerHTML = '<span style="color:#DC2626;font-weight:600;">No ' + type + ' leave credits available.</span>';
        hint.style.display = 'block';
        startDate.disabled = true; endDate.disabled = true; submitBtn.disabled = true;
        return;
    }

    if (type === 'emergency') {
        // Auto-set to the current date; the start date cannot be changed
        startDate.value = todayStr; startDate.min = todayStr; startDate.max = todayStr; startDate.disabled = true;
        urgentCheck.checked = true;
        urgentCheck.disabled = true;
        endDate.value = todayStr;   endDate.min = todayStr;
        const maxEnd = new Date(today); maxEnd.setDate(maxEnd.getDate() + remaining - 1);
        endDate.max = localISO(maxEnd);
        emNote.style.display = 'block';
        onDateChange();
    } else {
        // sick leave may be filed for a day already missed; others start today or later
        if (type === 'sick') { const past = new Date(today); past.setDate(past.getDate() - 7); startDate.min = localISO(past); }
        else startDate.min = todayStr;
        endDate.min = startDate.min;
    }

    hint.innerHTML = type === 'others'
        ? '<span style="color:#6B7280;">No credit cap for "Others" leave.</span>'
        : '<span style="color:#059669;font-weight:600;">' + remaining + ' day(s) available</span> — maximum for ' + type + ' leave.';
    hint.style.display = 'block';
}

function onDateChange() {
    const type = document.getElementById('leaveType').value;
    const s = document.getElementById('startDate').value, e = document.getElementById('endDate').value;
    const counter = document.getElementById('daysCounter'), text = document.getElementById('daysCountText');
    const submitBtn = document.getElementById('submitLeaveBtn');
    const proofInput = document.getElementById('attachment');
    if (!s || !e || !type) { counter.style.display = 'none'; return; }
    document.getElementById('endDate').min = s;
    const start = new Date(s + 'T00:00:00'), end = new Date(e + 'T00:00:00');
    if (end < start) { document.getElementById('endDate').value = ''; counter.style.display = 'none'; return; }
    if (type !== 'others') {
        const maxDate = endDateForCredits(type, s, avail(type));
        document.getElementById('endDate').max = maxDate;
        if (e > maxDate) {
            document.getElementById('endDate').value = maxDate;
            return onDateChange();
        }
    }
    const days = selectedLeaveDays(type, s, e);
    if (type === 'paternity' && days > 7) {
        text.textContent = 'Paternity leave is limited to seven days per qualifying delivery.';
        counter.className = 'days-counter danger'; submitBtn.disabled = true; return;
    }
    const sickProofRequired = type === 'sick' && days >= 3;
    proofInput.required = REQUIRED_DOC_TYPES.includes(type) || sickProofRequired;
    document.getElementById('proofReq').textContent = REQUIRED_DOC_TYPES.includes(type) || sickProofRequired ? '(required)' : (type === 'emergency' ? '(optional)' : '(optional for this duration)');
    counter.style.display = 'block';
    if (type !== 'others') {
        const r = avail(type);
        if (days > r) {
            text.textContent = days + ' days selected — exceeds your ' + r + ' available day(s)!';
            counter.className = 'days-counter danger'; submitBtn.disabled = true; return;
        }
        text.textContent = days + ' day(s) selected (' + (r - days) + ' will remain after approval)';
        counter.className = days >= r * 0.8 ? 'days-counter warning' : 'days-counter';
    } else {
        text.textContent = days + ' day(s) selected'; counter.className = 'days-counter';
    }
    submitBtn.disabled = false;
}

// Attachment: size check + image preview
document.getElementById('attachment').addEventListener('change', function () {
    const f = this.files[0], img = document.getElementById('proofPreview');
    img.style.display = 'none';
    if (!f) return;
    if (f.size > MAX_BYTES) { alert('File is too large (max 5 MB).'); this.value = ''; return; }
    if (f.type.startsWith('image/')) { img.src = URL.createObjectURL(f); img.style.display = 'block'; }
});

document.getElementById('leaveForm').addEventListener('submit', function (e) {
    const type = document.getElementById('leaveType').value;
    const s = document.getElementById('startDate').value, en = document.getElementById('endDate').value;
    if (!type || !s || !en) { e.preventDefault(); alert('Please fill in all required fields.'); return; }
    const days = selectedLeaveDays(type, s, en);
    if ((REQUIRED_DOC_TYPES.includes(type) || (type === 'sick' && days >= 3)) && !document.getElementById('attachment').files.length) {
        e.preventDefault(); alert('Please attach the required supporting document for ' + type + ' leave.'); return;
    }
    if (type !== 'others' && days > avail(type)) {
        e.preventDefault(); alert('You only have ' + avail(type) + ' ' + type + ' leave day(s) available.');
    }
    if (type === 'paternity' && days > 7) {
        e.preventDefault(); alert('Paternity leave is limited to seven days per qualifying delivery.');
    }
});

// ── Clickable request rows → details ────────────────────────
function esc(t) { const d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
function showLeaveDetail(r) {
    const badge = r.status_key === 'approved' ? 'badge-green'
        : (['pending', 'approved_pending_documents', 'documents_submitted'].includes(r.status_key) ? 'badge-amber'
        : (['rejected', 'documents_rejected'].includes(r.status_key) ? 'badge-red' : 'badge-blue'));
    let proof = '<span style="color:#9CA3AF;">No attachment</span>';
    if (r.attachment) {
        const url = '../../api/leave_attachment.php?id=' + r.id;
        proof = r.attachment_mime && r.attachment_mime.startsWith('image/')
            ? '<a href="' + url + '" target="_blank"><img src="' + url + '" alt="Proof" style="max-width:100%;max-height:220px;border-radius:8px;border:1px solid #E5E7EB;"></a>'
            : '<a class="btn-secondary btn-sm" href="' + url + '" target="_blank">Open ' + esc(r.attachment_name || 'attachment') + '</a>';
    }
    let documentProof = '<span style="color:#9CA3AF;">No post-approval document</span>';
    if (r.document) {
        const url = '../../api/leave_attachment.php?id=' + r.id + '&kind=document';
        documentProof = '<a class="btn-secondary btn-sm" href="' + url + '" target="_blank" rel="noopener">Open ' + esc(r.document_name || 'document') + '</a>';
    }
    const row = (l, v) => '<div style="display:flex;gap:10px;padding:6px 0;border-bottom:1px solid #F3F4F6;"><div style="width:150px;color:#6B7280;flex-shrink:0;">' + l + '</div><div style="flex:1;">' + v + '</div></div>';
    // Order: Status, Employee Name, Employee Number, Leave Type, Leave Date, Days, Reason, Attachment,
    // Date Filed, Team Manager remarks, Processed/Approved By, Final Remarks
    const days = r.days + ' Day' + (r.days > 1 ? 's' : '');
    const dates = r.from === r.to ? esc(r.from) : esc(r.from) + ' → ' + esc(r.to);
    const tm = [r.endorsement === 'endorsed' ? 'Endorsed' : (r.endorsement === 'not_recommended' ? 'Not recommended' : ''), r.endorsement_comment].filter(Boolean).join(' — ');
    const none = '<span style="color:#9CA3AF;">—</span>';
    document.getElementById('detailBody').innerHTML =
        '<div style="padding:10px 0 12px;"><span style="color:#6B7280;font-size:11px;text-transform:uppercase;">Status</span><div><span class="badge ' + badge + '" style="font-size:14px;padding:5px 14px;">' + esc(r.status).toUpperCase() + '</span>' + (r.urgent ? ' <span class="badge badge-red">URGENT</span>' : '') + '</div></div>' +
        row('Employee Name', esc(r.employee)) + row('Employee Number', esc(r.employee_id)) +
        row('Leave Type', esc(r.type) + ' Leave') +
        row('Leave Date', dates) + row('Days', days) +
        row('Reason', '<div style="white-space:pre-wrap;">' + esc(r.reason) + '</div>') +
        row('Attachment', proof) +
        row('Date Filed', esc(r.filed)) +
        row('Team Manager Remarks', tm ? esc(tm) : none) +
        row('Processed / Approved By', r.reviewed_by ? esc(r.reviewed_by) + (r.reviewed_on ? ' · ' + esc(r.reviewed_on) : '') : '<span style="color:#9CA3AF;">Not yet processed</span>') +
        row('Final Remarks', r.remarks ? esc(r.remarks) : none) +
        (r.document || r.documents_due_at ? row('Post-approval document', documentProof) : '') +
        (r.documents_due_at ? row('Documents due', esc(r.documents_due_at)) : '') +
        (r.documents_flagged ? row('HR follow-up', 'Document deadline passed; flagged for HR') : '') +
        (r.document_reviewed_by ? row('Document reviewed by', esc(r.document_reviewed_by) + (r.document_reviewed_at ? ' · ' + esc(r.document_reviewed_at) : '')) : '') +
        (r.document_review_remarks ? row('Document review remarks', esc(r.document_review_remarks)) : '');
    openModal('detailModal');
}
</script>
