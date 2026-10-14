<?php
require_once '../../includes/auth.php';
require_once '../../config/config.php';
requireRole('hr_admin');

$pageTitle = 'Schedule Manager';
$activePage = 'schedule_manager';
$success = '';
$error = '';

$db = getDB();
$hasWorkMode = (bool) $db->query("SHOW COLUMNS FROM users LIKE 'work_mode'")->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_mode') {
    $employeeId = trim($_POST['employee_id'] ?? '');
    $workMode = trim($_POST['work_mode'] ?? 'onsite');
    $allowedModes = ['onsite', 'wfh'];

    if (!$hasWorkMode) {
        $error = 'The database does not include the required work_mode column. Run the migration before updating schedules.';
    } elseif (empty($employeeId) || !in_array($workMode, $allowedModes, true)) {
        $error = 'Invalid employee or schedule mode.';
    } else {
        $stmt = $db->prepare('UPDATE users SET work_mode = ? WHERE employee_id = ?');
        if ($stmt->execute([$workMode, $employeeId])) {
            $success = 'Employee schedule updated successfully.';
            auditLog('Update Schedule', "Set {$employeeId} to {$workMode}");
        } else {
            $error = 'Unable to update the schedule. Please try again.';
        }
    }
}

$employees = [];
if ($hasWorkMode) {
    $stmt = $db->prepare(
        "SELECT u.employee_id, u.first_name, u.middle_name, u.last_name, u.department, u.position, u.work_mode
         FROM users u
         JOIN user_roles ur ON ur.employee_id = u.employee_id
         JOIN roles r ON r.id = ur.role_id
         WHERE r.role_name = 'employee' AND u.status = 'active'
         ORDER BY u.last_name, u.first_name"
    );
    $stmt->execute();
    $employees = $stmt->fetchAll();
}

include '../../includes/header.php';
?>

<div class="page-body">
    <div class="page-title">
        <h1>Schedule Manager</h1>
        <p>Manage employee work modes for onsite vs remote attendance.</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$hasWorkMode): ?>
        <div class="alert alert-warning">
            The schedule manager requires the <code>users.work_mode</code> column in the database.
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Employee Schedule List</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive" data-table-search="Search employees...">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Employee ID</th>
                                <th>Name</th>
                                <th>Department</th>
                                <th>Position</th>
                                <th>Current Mode</th>
                                <th>Update</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $employee): ?>
                                <tr>
                                    <td><?= htmlspecialchars($employee['employee_id']) ?></td>
                                    <td><?= htmlspecialchars(trim($employee['first_name'] . ' ' . $employee['middle_name'] . ' ' . $employee['last_name'])) ?></td>
                                    <td><?= htmlspecialchars($employee['department']) ?></td>
                                    <td><?= htmlspecialchars($employee['position']) ?></td>
                                    <td>
                                        <span class="badge badge-pill badge-<?= $employee['work_mode'] === 'onsite' ? 'success' : 'secondary' ?> text-dark">
                                            <?= strtoupper(htmlspecialchars($employee['work_mode'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="post" class="d-flex gap-2 align-items-center">
                                            <input type="hidden" name="action" value="update_mode">
                                            <input type="hidden" name="employee_id" value="<?= htmlspecialchars($employee['employee_id']) ?>">
                                            <select name="work_mode" class="form-select form-select-sm" style="width:120px;">
                                                <option value="onsite" <?= $employee['work_mode'] === 'onsite' ? 'selected' : '' ?>>Onsite</option>
                                                <option value="wfh" <?= $employee['work_mode'] === 'wfh' ? 'selected' : '' ?>>WFH</option>
                                            </select>
                                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
