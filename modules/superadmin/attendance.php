<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin', 'payroll_manager');
header('Location: ../admin/attendance.php');
exit;

$pageTitle  = 'Attendance Management';
$activePage = 'attendance';
$db = getDB();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add' || $action === 'edit') {
        $empId  = sanitize($_POST['employee_id'] ?? '');
        $date   = sanitize($_POST['date'] ?? '');
        $timeIn = sanitize($_POST['time_in'] ?? '');
        $timeOut= sanitize($_POST['time_out'] ?? '');
        $status = sanitize($_POST['status'] ?? 'present');
        $notes  = sanitize($_POST['notes'] ?? '');

        $late = 0;
        if ($timeIn) {
            $scheduled = strtotime($date . ' 08:00:00');
            $actual    = strtotime($date . ' ' . $timeIn);
            if ($actual > $scheduled) $late = (int)(($actual - $scheduled) / 60);
        }

        $hours = 0;
        if ($timeIn && $timeOut) {
            $h = (strtotime($date . ' ' . $timeOut) - strtotime($date . ' ' . $timeIn)) / 3600;
            $hours = max(0, round($h, 2));
        }

        if ($action === 'add') {
            $stmt = $db->prepare("INSERT INTO attendance (employee_id, date, time_in, time_out, late_minutes, hours_worked, status, notes) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE time_in=VALUES(time_in), time_out=VALUES(time_out), late_minutes=VALUES(late_minutes), hours_worked=VALUES(hours_worked), status=VALUES(status), notes=VALUES(notes)");
            $stmt->execute([$empId, $date, $timeIn ?: null, $timeOut ?: null, $late, $hours, $status, $notes]);
            auditLog('Add Attendance','Attendance',"$empId on $date");
            $success = 'Attendance record saved.';
        } else {
            $attId = (int)($_POST['att_id'] ?? 0);
            $stmt  = $db->prepare("UPDATE attendance SET time_in=?, time_out=?, late_minutes=?, hours_worked=?, status=?, notes=? WHERE id=?");
            $stmt->execute([$timeIn ?: null, $timeOut ?: null, $late, $hours, $status, $notes, $attId]);
            auditLog('Edit Attendance','Attendance',"ID: $attId");
            $success = 'Attendance updated.';
        }
    }

}

$filterEmp  = sanitize($_GET['emp']  ?? '');
$filterDate = sanitize($_GET['date'] ?? date('Y-m'));

$stmt = $db->prepare("SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) FROM attendance a JOIN users e ON a.employee_id=e.employee_id WHERE DATE_FORMAT(a.date,'%Y-%m')=? " . ($filterEmp ? "AND a.employee_id=?" : "") . " ORDER BY a.date DESC, CONCAT(u.first_name, ' ', u.last_name) LIMIT 200");
$params = [$filterDate];
if ($filterEmp) $params[] = $filterEmp;
$stmt->execute($params);
$records = $stmt->fetchAll();

$employees = $db->query("SELECT employee_id, name FROM users WHERE role='employee' AND status='active' ORDER BY name")->fetchAll();

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
        <button class="btn-primary" onclick="openModal('addAttModal')">+ Add Record</button>
    </div>
</div>

<div class="card">
    <div class="card-title">Attendance Records (<?= count($records) ?>)</div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Employee</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Late</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($records as $a): ?>
                <tr>
                    <td style="font-weight:500;"><?= htmlspecialchars($a['name']) ?></td>
                    <td><?= formatDate($a['date']) ?></td>
                    <td><?= formatTime($a['time_in']) ?></td>
                    <td><?= formatTime($a['time_out']) ?></td>
                    <td><?= $a['hours_worked'] ?> hrs</td>
                    <td><?= $a['late_minutes'] > 0 ? '<span style="color:#D97706;">'.$a['late_minutes'].' min</span>' : '—' ?></td>
                    <td><span class="badge <?= statusBadge($a['status']) ?>"><?= ucfirst($a['status']) ?></span></td>
                    <td>
                        <div style="display:flex; gap:6px;">
                            <button class="btn-secondary btn-sm" onclick="openEditAtt(<?= htmlspecialchars(json_encode($a)) ?>)">Edit</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($records)): ?>
                <tr><td colspan="8" style="text-align:center; color:#9CA3AF; padding:24px;">No records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<!-- Add Attendance Modal -->
<div class="modal-overlay" id="addAttModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Add Attendance Record</div>
            <button class="modal-close" onclick="closeModal('addAttModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="row g-2">
                <div class="col-12"><div class="form-group"><label class="form-label">Employee</label>
                    <select name="employee_id" class="form-select" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($employees as $e): ?><option value="<?= $e['employee_id'] ?>"><?= htmlspecialchars($e['name']) ?></option><?php endforeach; ?>
                    </select>
                </div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Date</label><input type="date" name="date" class="form-input" value="<?= date('Y-m-d') ?>" required></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Status</label><select name="status" class="form-select"><option value="present">Present</option><option value="absent">Absent</option><option value="half-day">Half-day</option><option value="on-leave">On Leave</option></select></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Time In</label><input type="time" name="time_in" class="form-input" value="08:00"></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Time Out</label><input type="time" name="time_out" class="form-input" value="17:00"></div></div>
                <div class="col-12"><div class="form-group"><label class="form-label">Notes</label><input type="text" name="notes" class="form-input" placeholder="Optional notes..."></div></div>
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
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="att_id" id="ea_id">
            <div class="row g-2">
                <div class="col-6"><div class="form-group"><label class="form-label">Time In</label><input type="time" name="time_in" id="ea_ti" class="form-input"></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Time Out</label><input type="time" name="time_out" id="ea_to" class="form-input"></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Status</label><select name="status" id="ea_status" class="form-select"><option value="present">Present</option><option value="absent">Absent</option><option value="half-day">Half-day</option><option value="on-leave">On Leave</option></select></div></div>
                <div class="col-12"><div class="form-group"><label class="form-label">Notes</label><input type="text" name="notes" id="ea_notes" class="form-input"></div></div>
            </div>
            <div style="display:flex; gap:10px; margin-top:12px;">
                <button type="submit" class="btn-primary">Update</button>
                <button type="button" class="btn-secondary" onclick="closeModal('editAttModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

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

<?php include '../../includes/footer.php'; ?>
