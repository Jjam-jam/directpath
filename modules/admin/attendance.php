<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/attendance_rules.php';
requireRole('hr_admin', 'payroll_manager');



$pageTitle  = 'Attendance Management';
$activePage = 'attendance';
$db = getDB();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !csrfTokenIsValid($_POST['_csrf_token'] ?? null)) {
    http_response_code(403);
    $error = 'Your session validation failed. Refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireRole('hr_admin');
    $action = $_POST['action'];

    if ($action === 'add' || $action === 'edit') {
        $empId  = sanitize($_POST['employee_id'] ?? '');
        $date   = sanitize($_POST['date'] ?? '');
        $timeIn = sanitize($_POST['time_in'] ?? '');
        $timeOut= sanitize($_POST['time_out'] ?? '');
        $status = sanitize($_POST['status'] ?? 'present');
        $notes  = sanitize($_POST['notes'] ?? '');
        $reason = sanitize($_POST['reason'] ?? '');

        $late = $timeIn ? attComputeLateMinutes($timeIn . ':00') : 0;
        $hours = 0;
        $undertime = 0;
        if ($timeIn && $timeOut) {
            [$hours, $undertime] = attComputeOut($timeIn . ':00', $timeOut . ':00');
        } elseif ($timeOut) {
            [, $undertime] = attComputeOut(ATT_SHIFT_START, $timeOut . ':00');
        }

        $exists = $db->prepare("SELECT id FROM attendance WHERE employee_id=? AND date=?");
        if ($action === 'add') {
            $exists->execute([$empId, $date]);
            if ($exists->fetchColumn()) {
                $error = 'An attendance record already exists for this employee and date. Use Edit to correct it.';
            } else {
                $db->beginTransaction();
                try {
                    $stmt = $db->prepare("INSERT INTO attendance (employee_id, date, time_in, time_out, late_minutes, undertime_mins, hours_worked, status, notes) VALUES (?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$empId, $date, $timeIn ?: null, $timeOut ?: null, $late, $undertime, $hours, $status, $notes]);
                    $recordId = (int)$db->lastInsertId();
                    if (!auditLog('Create Attendance', 'Attendance', "Record ID: $recordId | Employee: $empId | Date: $date | Time In: " . ($timeIn ?: 'none') . " | Time Out: " . ($timeOut ?: 'none') . " | Status: $status | Reason: " . ($reason ?: 'new attendance record'))) {
                        throw new RuntimeException('Audit log write failed.');
                    }
                    $db->commit();
                    $success = 'Attendance record saved.';
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Attendance was not saved because the audit record could not be written.';
                }
            }
        } else {
            $attId = (int)($_POST['att_id'] ?? 0);
            $oldStmt = $db->prepare("SELECT employee_id, date, time_in, time_out, status, notes FROM attendance WHERE id=?");
            $oldStmt->execute([$attId]);
            $old = $oldStmt->fetch();
            if (!$old) {
                $error = 'Attendance record not found.';
            } elseif (!$reason) {
                $error = 'A reason is required for attendance corrections.';
            } else {
                $db->beginTransaction();
                try {
                    $stmt = $db->prepare("UPDATE attendance SET time_in=?, time_out=?, late_minutes=?, undertime_mins=?, hours_worked=?, status=?, notes=? WHERE id=?");
                    $stmt->execute([$timeIn ?: null, $timeOut ?: null, $late, $undertime, $hours, $status, $notes, $attId]);
                    if (!auditLog('Correct Attendance', 'Attendance', "Record ID: $attId | Employee: {$old['employee_id']} | Date: {$old['date']} | Time In: {$old['time_in']} -> " . ($timeIn ?: 'none') . " | Time Out: {$old['time_out']} -> " . ($timeOut ?: 'none') . " | Status: {$old['status']} -> $status | Reason: $reason")) {
                        throw new RuntimeException('Audit log write failed.');
                    }
                    $db->commit();
                    $success = 'Attendance updated.';
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Attendance was not updated because the audit record could not be written.';
                }
            }
        }
    }

}

$filterEmp  = sanitize($_GET['emp']  ?? '');
$filterDate = sanitize($_GET['date'] ?? date('Y-m'));

$employees = $db->query("
    SELECT 
        u.employee_id,
        CONCAT(u.first_name, ' ', u.last_name) AS name
    FROM users u
    JOIN user_roles ur ON ur.employee_id = u.employee_id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.role_name = 'employee'
    AND u.status = 'active'
    ORDER BY u.first_name, u.last_name
")->fetchAll();

$stmt = $db->prepare("
    SELECT a.*, 
           CONCAT(u.first_name, ' ', u.last_name) AS name
    FROM attendance a
    JOIN users u ON a.employee_id = u.employee_id
    WHERE DATE_FORMAT(a.date,'%Y-%m') = ?
    " . ($filterEmp ? "AND a.employee_id = ?" : "") . "
    ORDER BY a.date DESC, u.first_name
");

$params = [$filterDate];
if ($filterEmp) $params[] = $filterEmp;

$stmt->execute($params);
$records = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <form method="GET" class="filter-bar" style="margin:0;">
            <input type="month" name="date" class="form-input" style="width:auto;" value="<?= $filterDate ?>">
            <select name="emp" class="form-select" style="width:auto;">
                <option value="">All Employees</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?= $e['employee_id'] ?>" <?= $filterEmp===$e['employee_id']?'selected':'' ?>><?= htmlspecialchars($e['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-primary">Filter</button>
            <a href="attendance.php" class="btn-secondary">Reset</a>
        </form>
        <?php if ($_SESSION['role'] === 'hr_admin'): ?>
        <button class="btn-primary" onclick="openModal('addAttModal')">+ Add Record</button>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-title">Attendance Records (<?= count($records) ?>)</div>
    <div class="table-wrapper" data-table-search="Search attendance records...">
        <table>
            <thead>
                <tr><th>Employee</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Late</th><th>Undertime</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($records as $a): ?>
                <tr>
                    <td style="font-weight:500;">
    <?= htmlspecialchars($a['name'] ?? 'Unknown') ?>
</td>

<td><?= formatDate($a['date'] ?? '') ?></td>
<td><?= formatTime($a['time_in'] ?? '') ?></td>
<td><?= formatTime($a['time_out'] ?? '') ?></td>

<td><?= (float)($a['hours_worked'] ?? 0) ?> hrs</td>

<td>
    <?= (!empty($a['late_minutes']))
        ? '<span style="color:#D97706;">'.(int)$a['late_minutes'].' min</span>'
        : '—' ?>
</td>

<td>
    <?= (!empty($a['undertime_mins']))
        ? '<span style="color:#2563EB;">'.(int)$a['undertime_mins'].' min</span>'
        : '—' ?>
</td>

<td>
    <span class="badge <?= statusBadge($a['status'] ?? 'unknown') ?>">
        <?= ucfirst($a['status'] ?? 'Unknown') ?>
    </span>
</td>
<td>
                        <div style="display:flex; gap:6px;">
                            <?php if ($_SESSION['role'] === 'hr_admin'): ?>
                            <button class="btn-secondary btn-sm" onclick="openEditAtt(<?= htmlspecialchars(json_encode($a)) ?>)">Edit</button>
                            <?php else: ?>View only<?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($records)): ?>
                <tr><td colspan="9" style="text-align:center; color:#9CA3AF; padding:24px;">No records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<!-- Add Attendance Modal -->
<?php if ($_SESSION['role'] === 'hr_admin'): ?>
<div class="modal-overlay" id="addAttModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Add Attendance Record</div>
            <button class="modal-close" onclick="closeModal('addAttModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="add">
            <div class="row g-2">
                <div class="col-12"><div class="form-group"><label class="form-label">Employee</label>
                    <select name="employee_id" class="form-select" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($employees as $e): ?><option value="<?= $e['employee_id'] ?>"><?= htmlspecialchars($e['name']) ?></option><?php endforeach; ?>
                    </select>
                </div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Date</label><input type="date" name="date" class="form-input" value="<?= date('Y-m-d') ?>" required></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Status</label><select name="status" class="form-select"><option value="present">Present</option><option value="late">Late</option><option value="absent">Absent</option><option value="half-day">Half-day</option><option value="on-leave">On Leave</option></select></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Time In</label><input type="time" name="time_in" class="form-input" value="08:00"></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Time Out</label><input type="time" name="time_out" class="form-input" value="17:00"></div></div>
                <div class="col-12"><div class="form-group"><label class="form-label">Notes</label><input type="text" name="notes" class="form-input" placeholder="Optional notes..."></div></div>
                <div class="col-12"><div class="form-group"><label class="form-label">Reason</label><input type="text" name="reason" class="form-input" placeholder="Reason for adding this record"></div></div>
            </div>
            <div style="display:flex; gap:10px; margin-top:12px;">
                <button type="submit" class="btn-primary">Save</button>
                <button type="button" class="btn-secondary" onclick="closeModal('addAttModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Attendance Modal -->
<div class="modal-overlay" id="editAttModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Edit Attendance</div>
            <button class="modal-close" onclick="closeModal('editAttModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="att_id" id="ea_id">
            <div class="row g-2">
                <div class="col-6"><div class="form-group"><label class="form-label">Time In</label><input type="time" name="time_in" id="ea_ti" class="form-input"></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Time Out</label><input type="time" name="time_out" id="ea_to" class="form-input"></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Status</label><select name="status" id="ea_status" class="form-select"><option value="present">Present</option><option value="late">Late</option><option value="absent">Absent</option><option value="half-day">Half-day</option><option value="on-leave">On Leave</option></select></div></div>
                <div class="col-12"><div class="form-group"><label class="form-label">Notes</label><input type="text" name="notes" id="ea_notes" class="form-input"></div></div>
                <div class="col-12"><div class="form-group"><label class="form-label">Correction Reason *</label><input type="text" name="reason" class="form-input" required></div></div>
            </div>
            <div style="display:flex; gap:10px; margin-top:12px;">
                <button type="submit" class="btn-primary">Update</button>
                <button type="button" class="btn-secondary" onclick="closeModal('editAttModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openEditAtt(a) {
    document.getElementById('ea_id').value     = a.id;
    document.getElementById('ea_ti').value     = a.time_in ? a.time_in.substr(0,5) : '';
    document.getElementById('ea_to').value     = a.time_out ? a.time_out.substr(0,5) : '';
    document.getElementById('ea_status').value = a.status;
    document.getElementById('ea_notes').value  = a.notes || '';
    openModal('editAttModal');
}
</script>
