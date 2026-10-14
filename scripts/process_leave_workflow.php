<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../config/config.php';

$db = getDB();
$autoApproved = 0;
$overdueFlagged = 0;

$stmt = $db->query("
    SELECT id
    FROM leave_requests
    WHERE status = 'pending'
      AND leave_type = 'emergency'
      AND urgent = 1
      AND auto_approval_after <= NOW()
    ORDER BY auto_approval_after
");
$requestIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($requestIds as $requestId) {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT *, auto_approval_after <= NOW() AS approval_due FROM leave_requests WHERE id=? FOR UPDATE");
        $stmt->execute([(int)$requestId]);
        $request = $stmt->fetch();
        if (!$request || $request['status'] !== 'pending' || $request['leave_type'] !== 'emergency'
            || !(int)$request['urgent'] || !$request['auto_approval_after']
            || !(int)$request['approval_due']) {
            $db->commit();
            continue;
        }

        $leaveYear = (int)date('Y', strtotime($request['start_date']));
        $days = (int)$request['total_days'];
        $balance = getLeaveBalance($request['employee_id'], $leaveYear);
        $available = ($balance['emergency']['available_raw'] ?? 0) + $days;
        if ($days > $available) {
            $existing = $db->prepare("SELECT id FROM leave_request_events WHERE leave_request_id=? AND action='auto_approval_blocked' LIMIT 1");
            $existing->execute([$request['id']]);
            if (!$existing->fetchColumn()) {
                logLeaveRequestEvent($db, (int)$request['id'], 'auto_approval_blocked', 'System', 'Insufficient emergency leave credits.');
                createLeaveNotification($db, (int)$request['id'], "Emergency leave request #{$request['id']} could not be auto-approved because credits are insufficient.", null, 'hr_admin');
            }
            $db->commit();
            continue;
        }

        $db->prepare("
            UPDATE leave_requests
            SET status='approved_pending_documents', reviewed_by='System (auto-approved)', review_date=NOW(),
                approved_at=NOW(), documents_due_at=DATE_ADD(NOW(), INTERVAL 3 DAY), review_remarks='Automatically approved after 2 hours without review.'
            WHERE id=? AND status='pending'
        ")->execute([$request['id']]);
        $db->prepare("UPDATE leave_balances SET emergency_used=emergency_used+? WHERE employee_id=? AND year=?")
           ->execute([$days, $request['employee_id'], $leaveYear]);
        logLeaveRequestEvent($db, (int)$request['id'], 'auto_approved', 'System (auto-approved)', 'Emergency request was unreviewed for 2 hours.');
        createLeaveNotification($db, (int)$request['id'], "Emergency leave #{$request['id']} was automatically approved. Submit proof within 3 days.", $request['employee_id']);
        createLeaveNotification($db, (int)$request['id'], "Emergency leave request #{$request['id']} was automatically approved; documents are due in 3 days.", null, 'hr_admin');
        $db->commit();
        $autoApproved++;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

$stmt = $db->query("
    SELECT id
    FROM leave_requests
    WHERE status IN ('approved_pending_documents', 'documents_rejected')
      AND documents_due_at <= NOW()
      AND documents_flagged_at IS NULL
    ORDER BY documents_due_at
");
$overdueIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($overdueIds as $requestId) {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id, status, documents_due_at, documents_due_at <= NOW() AS deadline_passed FROM leave_requests WHERE id=? FOR UPDATE");
        $stmt->execute([(int)$requestId]);
        $request = $stmt->fetch();
        if (!$request || !in_array($request['status'], ['approved_pending_documents', 'documents_rejected'], true)
            || !$request['documents_due_at'] || !(int)$request['deadline_passed']) {
            $db->commit();
            continue;
        }

        $flag = $db->prepare("UPDATE leave_requests SET documents_flagged_at=NOW() WHERE id=? AND documents_flagged_at IS NULL");
        $flag->execute([$request['id']]);
        if ($flag->rowCount() === 1) {
            logLeaveRequestEvent($db, (int)$request['id'], 'documents_overdue', 'System', 'Required proof was not accepted by the deadline; flagged for HR follow-up.');
            createLeaveNotification($db, (int)$request['id'], "Required documents for leave request #{$request['id']} are overdue. HR follow-up is required.", null, 'hr_admin');
            $overdueFlagged++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

echo "Auto-approved: $autoApproved; overdue flagged: $overdueFlagged" . PHP_EOL;
