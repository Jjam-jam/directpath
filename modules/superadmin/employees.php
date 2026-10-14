<?php
// Directpath HRMS — Employee Management (Superadmin)
// Pinagbago: Gumagamit na ng 'users' table (normalized schema)
// First name, middle name, at last name na ang ginagamit

require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin');
header('Location: ../admin/employees.php');
exit;

$pageTitle  = 'Employee Management';
$activePage = 'employees';
$db = getDB();

$success = '';
$error   = '';

// ── Handle POST Actions ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // === ADD EMPLOYEE ===
    if ($action === 'add') {
        // Kinukuha na ngayon ang first, middle, at last name ng empleyado
        $firstName  = sanitize($_POST['first_name']  ?? '');
        $middleName = sanitize($_POST['middle_name'] ?? '');   // optional
        $lastName   = sanitize($_POST['last_name']   ?? '');
        $email      = sanitize($_POST['email']       ?? '');
        $dept       = sanitize($_POST['department']  ?? '');
        $pos        = sanitize($_POST['position']    ?? '');
        $hired      = sanitize($_POST['date_hired']  ?? '');
        $salary     = (float)($_POST['basic_salary']  ?? 0);
        $allowances = (float)($_POST['allowances']    ?? 0);
        $phone      = sanitize($_POST['phone']        ?? '');
        $address    = sanitize($_POST['address']      ?? '');
        $civil      = sanitize($_POST['civil_status'] ?? 'single');
        $sss        = sanitize($_POST['sss_no']       ?? '');
        $ph         = sanitize($_POST['philhealth_no']?? '');
        $pagibig    = sanitize($_POST['pagibig_no']   ?? '');
        $tin        = sanitize($_POST['tin_no']       ?? '');
        $pw         = $_POST['password'] ?? 'password';

        // First name at last name ay required — middle name ay optional
        if (!$firstName || !$lastName || !$email || !$dept || !$pos) {
            $error = 'First name, last name, email, department, and position are required.';
        } else {
            // Auto-generate ang Employee ID: EMP-0001, EMP-0002, etc.
            $lastId = $db->query("SELECT employee_id FROM users WHERE employee_id LIKE 'EMP-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $num    = $lastId ? (int)substr($lastId, 4) + 1 : 1;
            $empId  = 'EMP-' . str_pad($num, 4, '0', STR_PAD_LEFT);
            $hash   = password_hash($pw, PASSWORD_DEFAULT); // Naka-hash ang password para hindi readable

            // Handle profile photo upload
            $photoName = null;
            if (!empty($_FILES['profile_photo']['name'])) {
                $ext     = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','webp'];
                if (in_array($ext, $allowed)) {
                    $photoName = 'profile_' . $empId . '_' . time() . '.' . $ext;
                    $dest      = PROFILE_PHOTO_PATH . $photoName;
                    if (!is_dir(PROFILE_PHOTO_PATH)) mkdir(PROFILE_PHOTO_PATH, 0755, true);
                    move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dest);
                }
            }

            // I-insert sa USERS table (hindi na employees — normalized na)
            $stmt = $db->prepare("INSERT INTO users
                (employee_id, first_name, middle_name, last_name, email, password,
                 department, position, date_hired, basic_salary, allowances,
                 phone, address, civil_status, sss_no, philhealth_no, pagibig_no, tin_no,
                 profile_photo, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active')");
            $stmt->execute([$empId, $firstName, $middleName ?: null, $lastName, $email, $hash,
                            $dept, $pos, $hired, $salary, $allowances,
                            $phone, $address, $civil, $sss, $ph, $pagibig, $tin, $photoName]);

            // I-assign ang 'employee' role sa user_roles table (join table)
            $roleStmt = $db->prepare("SELECT id FROM roles WHERE role_name = 'employee'");
            $roleStmt->execute();
            $roleId = $roleStmt->fetchColumn();
            $db->prepare("INSERT INTO user_roles (employee_id, role_id) VALUES (?, ?)")
               ->execute([$empId, $roleId]);

            // Gumawa ng leave_balances record para sa bagong empleyado
            // Para agad may leave days siya kapag nag-apply
            $db->prepare("INSERT IGNORE INTO leave_balances (employee_id, year) VALUES (?, ?)")
               ->execute([$empId, date('Y')]);

            $fullName = getFullName($firstName, $middleName, $lastName);
            auditLog('Add Employee', 'Employee', "Added: $fullName ($empId)");
            $success = "Employee $fullName added successfully (ID: $empId).";
        }
    }

    // === EDIT EMPLOYEE ===
    if ($action === 'edit') {
        $editId     = sanitize($_POST['edit_id']     ?? '');
        $firstName  = sanitize($_POST['first_name']  ?? '');
        $middleName = sanitize($_POST['middle_name'] ?? '');
        $lastName   = sanitize($_POST['last_name']   ?? '');
        $email      = sanitize($_POST['email']       ?? '');
        $dept       = sanitize($_POST['department']  ?? '');
        $pos        = sanitize($_POST['position']    ?? '');
        $hired      = sanitize($_POST['date_hired']  ?? '');
        $salary     = (float)($_POST['basic_salary']  ?? 0);
        $allw       = (float)($_POST['allowances']    ?? 0);
        $phone      = sanitize($_POST['phone']        ?? '');
        $address    = sanitize($_POST['address']      ?? '');
        $civil      = sanitize($_POST['civil_status'] ?? 'single');
        $sss        = sanitize($_POST['sss_no']       ?? '');
        $ph         = sanitize($_POST['philhealth_no']?? '');
        $pagibig    = sanitize($_POST['pagibig_no']   ?? '');
        $tin        = sanitize($_POST['tin_no']       ?? '');
        $status     = sanitize($_POST['status']       ?? 'active');

        // Optional: Update ang profile photo
        $photoName = null;
        if (!empty($_FILES['profile_photo']['name'])) {
            $ext     = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed)) {
                $photoName = 'profile_' . $editId . '_' . time() . '.' . $ext;
                $dest      = PROFILE_PHOTO_PATH . $photoName;
                if (!is_dir(PROFILE_PHOTO_PATH)) mkdir(PROFILE_PHOTO_PATH, 0755, true);
                move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dest);
            }
        }

        // I-update ang users table — dynamic query depende kung may bagong photo o wala
        if (!empty($photoName)) {
            $stmt = $db->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=?, email=?, department=?, position=?, date_hired=?, basic_salary=?, allowances=?, phone=?, address=?, civil_status=?, sss_no=?, philhealth_no=?, pagibig_no=?, tin_no=?, status=?, profile_photo=? WHERE employee_id=?");
            $stmt->execute([$firstName, $middleName ?: null, $lastName, $email, $dept, $pos, $hired, $salary, $allw, $phone, $address, $civil, $sss, $ph, $pagibig, $tin, $status, $photoName, $editId]);
        } else {
            $stmt = $db->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=?, email=?, department=?, position=?, date_hired=?, basic_salary=?, allowances=?, phone=?, address=?, civil_status=?, sss_no=?, philhealth_no=?, pagibig_no=?, tin_no=?, status=? WHERE employee_id=?");
            $stmt->execute([$firstName, $middleName ?: null, $lastName, $email, $dept, $pos, $hired, $salary, $allw, $phone, $address, $civil, $sss, $ph, $pagibig, $tin, $status, $editId]);
        }

        $fullName = getFullName($firstName, $middleName, $lastName);
        auditLog('Edit Employee', 'Employee', "Updated: $fullName ($editId)");
        $success = "Employee $fullName updated successfully.";
    }

    // === DELETE EMPLOYEE ===
    if ($action === 'delete') {
        $delId = sanitize($_POST['del_id'] ?? '');
        if ($delId) {
            // Preserve the account and related records by deactivating it.
            $s = $db->prepare("SELECT first_name, middle_name, last_name FROM users WHERE employee_id=?");
            $s->execute([$delId]);
            $emp = $s->fetch();
            if ($emp) {
                $fullName = getFullName($emp['first_name'], $emp['middle_name'], $emp['last_name']);
                $db->prepare("UPDATE users SET status='inactive' WHERE employee_id=?")->execute([$delId]);
                auditLog('Deactivate Employee', 'Employee', "Deactivated: $fullName ($delId)");
                $success = "Employee $fullName deactivated; related records were preserved.";
            }
        }
    }

    // === RESET PASSWORD ===
    if ($action === 'reset_pw') {
        $empId  = sanitize($_POST['emp_id'] ?? '');
        $newPw  = $_POST['new_password'] ?? 'password';
        $hash   = password_hash($newPw, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password=? WHERE employee_id=?")->execute([$hash, $empId]);
        auditLog('Reset Password', 'Employee', "Password reset for $empId");
        $success = 'Password reset successfully.';
    }
}

// ── Filters ───────────────────────────────────────────────
$search = sanitize($_GET['search'] ?? '');
$dept   = sanitize($_GET['dept']   ?? '');
$status = sanitize($_GET['status'] ?? '');

// Base query: kunin lahat ng employees (role = 'employee') mula sa users table
// JOIN sa user_roles at roles para malaman ang role ng bawat user
$where  = "WHERE ur.role_id = (SELECT id FROM roles WHERE role_name = 'employee')";
$params = [];

// Live search: any word, matched against name, ID, email, department, position, phone, status, dates...
$where .= searchClause([
    "u.employee_id", "u.first_name", "u.middle_name", "u.last_name",
    "CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)", "u.email", "u.department", "u.position",
    "u.phone", "u.address", "u.status", "u.gender", "u.civil_status", "CAST(u.date_hired AS CHAR)",
    "u.sss_no", "u.philhealth_no", "u.pagibig_no", "u.tin_no", "IF(u.is_team_leader=1,'team manager','')",
], $search, $params);
if ($dept)   { $where .= " AND u.department=?"; $params[] = $dept; }
if ($status) { $where .= " AND u.status=?";     $params[] = $status; }

// I-assemble ang full name sa SQL para sa sorting
$stmt = $db->prepare("
    SELECT u.*,
           CONCAT(u.first_name,
                  IF(u.middle_name IS NOT NULL AND u.middle_name != '', CONCAT(' ', SUBSTRING(u.middle_name,1,1), '.'), ''),
                  ' ', u.last_name) AS full_name
    FROM users u
    JOIN user_roles ur ON ur.employee_id = u.employee_id
    $where
    ORDER BY u.last_name ASC, u.first_name ASC
");
$stmt->execute($params);
$employees = $stmt->fetchAll();

$departments = $db->query("
    SELECT DISTINCT u.department FROM users u
    JOIN user_roles ur ON ur.employee_id = u.employee_id
    JOIN roles r ON r.id = ur.role_id WHERE r.role_name = 'employee'
    ORDER BY u.department
")->fetchAll(PDO::FETCH_COLUMN);

include '../../includes/header.php';
?>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Filter Bar -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <form method="GET" class="filter-bar" style="margin:0;">
            <input type="text" name="search" class="search-input"
                   placeholder="Search anything: name, ID, email, department, position..."
                   value="<?= htmlspecialchars($search) ?>">
            <select name="dept" class="form-select" style="width:auto;">
                <option value="">All Departments</option>
                <?php foreach ($departments as $d): ?>
                <option value="<?= htmlspecialchars($d) ?>" <?= $dept===$d?'selected':'' ?>>
                    <?= htmlspecialchars($d) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-select" style="width:auto;">
                <option value="">All Status</option>
                <option value="active"   <?= $status==='active'  ?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
            </select>
            <button type="submit" class="btn-primary">Filter</button>
            <a href="employees.php" class="btn-secondary">Reset</a>
        </form>
        <button class="btn-primary" onclick="openModal('addModal')">+ Add Employee</button>
    </div>
</div>

<!-- Employee Table -->
<div class="card">
    <div class="card-title">Employees (<?= count($employees) ?>)</div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Photo</th>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Department</th>
                    <th>Position</th>
                    <th>Basic Salary</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $emp):
                    // I-assemble ang full name para sa display
                    $fullName = getFullName($emp['first_name'], $emp['middle_name'] ?? '', $emp['last_name']);
                ?>
                <tr>
                    <td>
                        <?php if (!empty($emp['profile_photo']) && file_exists(PROFILE_PHOTO_PATH . $emp['profile_photo'])): ?>
                            <img src="../../uploads/profile_photos/<?= htmlspecialchars($emp['profile_photo']) ?>"
                                 style="width:36px; height:36px; border-radius:50%; object-fit:cover;">
                        <?php else: ?>
                            <div style="width:36px; height:36px; border-radius:50%; background:#CD1E27; color:#fff;
                                        display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:600;">
                                <?= getInitials($fullName) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-family:monospace; font-size:12px;"><?= htmlspecialchars($emp['employee_id']) ?></td>
                    <td>
                        <!-- Pinakita ang full name: First MI. Last format -->
                        <div style="font-weight:600;"><?= htmlspecialchars($fullName) ?></div>
                        <div style="font-size:11px; color:#9CA3AF;"><?= htmlspecialchars($emp['email']) ?></div>
                    </td>
                    <td><?= htmlspecialchars($emp['department']) ?></td>
                    <td><?= htmlspecialchars($emp['position']) ?></td>
                    <td><?= formatMoney((float)$emp['basic_salary']) ?></td>
                    <td><span class="badge <?= statusBadge($emp['status']) ?>"><?= ucfirst($emp['status']) ?></span></td>
                    <td>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <!-- Ipinapasa ang emp data sa JS para ma-populate ang edit modal -->
                            <button class="btn-secondary btn-sm"
                                    onclick="openEditModal(<?= htmlspecialchars(json_encode($emp)) ?>)">Edit</button>
                            <button class="btn-secondary btn-sm"
                                    onclick="resetPw('<?= $emp['employee_id'] ?>', '<?= htmlspecialchars($fullName) ?>')">Reset PW</button>
                            <button class="btn-secondary btn-sm" style="color:#CD1E27;"
                                    onclick="deleteEmployee('<?= $emp['employee_id'] ?>', '<?= htmlspecialchars($fullName) ?>')">Delete</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if ($employees): ?><tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="5">Total basic salary</td><td><?= formatMoney(array_sum(array_map(static fn($emp) => (float)$emp['basic_salary'], $employees))) ?></td><td colspan="2"></td></tr><?php endif; ?>
                <?php if (empty($employees)): ?>
                <tr><td colspan="8" style="text-align:center; color:#9CA3AF; padding:24px;">No employees found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- end page-body -->

<!-- ===== ADD EMPLOYEE MODAL ===== -->
<div class="modal-overlay" id="addModal">
    <div class="modal-box" style="max-width:720px;">
        <div class="modal-header">
            <div class="modal-title">Add New Employee</div>
            <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add">
            <div class="row g-3">
                <!-- Tatlong hiwalay na field para sa pangalan -->
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" class="form-input" required placeholder="e.g. Juan">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label">Middle Name <span style="color:#9CA3AF;font-size:11px;">(optional)</span></label>
                        <input type="text" name="middle_name" class="form-input" placeholder="e.g. Dela">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" class="form-input" required placeholder="e.g. Cruz">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" class="form-input" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input type="text" name="password" class="form-input" value="password">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">Department *</label>
                        <input type="text" name="department" class="form-input" required list="dept-list">
                        <datalist id="dept-list">
                            <?php foreach ($departments as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">Position *</label>
                        <input type="text" name="position" class="form-input" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">Date Hired</label>
                        <input type="date" name="date_hired" class="form-input" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">Civil Status</label>
                        <select name="civil_status" class="form-select">
                            <option value="single">Single</option>
                            <option value="married">Married</option>
                            <option value="widowed">Widowed</option>
                            <option value="separated">Separated</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">Basic Salary (₱)</label>
                        <input type="number" name="basic_salary" class="form-input" step="0.01" min="0" value="0">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">Allowances (₱)</label>
                        <input type="number" name="allowances" class="form-input" step="0.01" min="0" value="0">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-input" placeholder="+63">
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-input">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label">SSS No.</label>
                        <input type="text" name="sss_no" class="form-input">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label">PhilHealth No.</label>
                        <input type="text" name="philhealth_no" class="form-input">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label">Pag-IBIG No.</label>
                        <input type="text" name="pagibig_no" class="form-input">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">TIN No.</label>
                        <input type="text" name="tin_no" class="form-input">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">Profile Photo</label>
                        <input type="file" name="profile_photo" class="form-input" accept="image/*">
                    </div>
                </div>
            </div>
            <div style="display:flex; gap:10px; margin-top:16px;">
                <button type="submit" class="btn-primary">Add Employee</button>
                <button type="button" class="btn-secondary" onclick="closeModal('addModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== EDIT EMPLOYEE MODAL ===== -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box" style="max-width:720px;">
        <div class="modal-header">
            <div class="modal-title">Edit Employee</div>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="edit_id" id="edit_id">
            <div class="row g-3">
                <!-- First, middle, last name fields para sa edit -->
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" id="e_first_name" class="form-input" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label">Middle Name <span style="color:#9CA3AF;font-size:11px;">(optional)</span></label>
                        <input type="text" name="middle_name" id="e_middle_name" class="form-input">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" id="e_last_name" class="form-input" required>
                    </div>
                </div>
                <div class="col-md-6"><div class="form-group"><label class="form-label">Email</label><input type="email" name="email" id="e_email" class="form-input" required></div></div>
                <div class="col-md-6"><div class="form-group"><label class="form-label">Department</label><input type="text" name="department" id="e_dept" class="form-input"></div></div>
                <div class="col-md-6"><div class="form-group"><label class="form-label">Position</label><input type="text" name="position" id="e_pos" class="form-input"></div></div>
                <div class="col-md-6"><div class="form-group"><label class="form-label">Date Hired</label><input type="date" name="date_hired" id="e_hired" class="form-input"></div></div>
                <div class="col-md-6"><div class="form-group"><label class="form-label">Status</label><select name="status" id="e_status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div>
                <div class="col-md-6"><div class="form-group"><label class="form-label">Civil Status</label><select name="civil_status" id="e_civil" class="form-select"><option value="single">Single</option><option value="married">Married</option><option value="widowed">Widowed</option><option value="separated">Separated</option></select></div></div>
                <div class="col-md-6"><div class="form-group"><label class="form-label">Basic Salary (₱)</label><input type="number" name="basic_salary" id="e_salary" class="form-input" step="0.01"></div></div>
                <div class="col-md-6"><div class="form-group"><label class="form-label">Allowances (₱)</label><input type="number" name="allowances" id="e_allowances" class="form-input" step="0.01"></div></div>
                <div class="col-md-6"><div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" id="e_phone" class="form-input"></div></div>
                <div class="col-12"><div class="form-group"><label class="form-label">Address</label><input type="text" name="address" id="e_address" class="form-input"></div></div>
                <div class="col-md-4"><div class="form-group"><label class="form-label">SSS No.</label><input type="text" name="sss_no" id="e_sss" class="form-input"></div></div>
                <div class="col-md-4"><div class="form-group"><label class="form-label">PhilHealth No.</label><input type="text" name="philhealth_no" id="e_ph" class="form-input"></div></div>
                <div class="col-md-4"><div class="form-group"><label class="form-label">Pag-IBIG No.</label><input type="text" name="pagibig_no" id="e_pagibig" class="form-input"></div></div>
                <div class="col-md-6"><div class="form-group"><label class="form-label">TIN No.</label><input type="text" name="tin_no" id="e_tin" class="form-input"></div></div>
                <div class="col-md-6"><div class="form-group"><label class="form-label">Profile Photo (optional)</label><input type="file" name="profile_photo" class="form-input" accept="image/*"></div></div>
            </div>
            <div style="display:flex; gap:10px; margin-top:16px;">
                <button type="submit" class="btn-primary">Save Changes</button>
                <button type="button" class="btn-secondary" onclick="closeModal('editModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE FORM — hidden, ini-submit ng JS -->
<form method="POST" id="deleteForm">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="del_id" id="del_id">
</form>

<!-- RESET PASSWORD MODAL -->
<div class="modal-overlay" id="resetPwModal">
    <div class="modal-box" style="max-width:360px;">
        <div class="modal-header">
            <div class="modal-title">Reset Password</div>
            <button class="modal-close" onclick="closeModal('resetPwModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action"  value="reset_pw">
            <input type="hidden" name="emp_id"  id="rp_id">
            <div class="form-group">
                <label class="form-label">Employee</label>
                <input type="text" id="rp_name" class="form-input" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">New Password</label>
                <input type="text" name="new_password" class="form-input" value="password" required>
            </div>
            <div style="display:flex; gap:10px; margin-top:12px;">
                <button type="submit" class="btn-primary">Reset Password</button>
                <button type="button" class="btn-secondary" onclick="closeModal('resetPwModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── openEditModal() ───────────────────────────────────────
// Ipinapasa ang employee data (JSON) para ma-populate ang edit form
// Gamit ang first_name, middle_name, last_name na (hindi na 'name')
function openEditModal(emp) {
    document.getElementById('edit_id').value         = emp.employee_id;
    // Populate ang tatlong separate name fields
    document.getElementById('e_first_name').value    = emp.first_name  || '';
    document.getElementById('e_middle_name').value   = emp.middle_name || '';
    document.getElementById('e_last_name').value     = emp.last_name   || '';
    document.getElementById('e_email').value         = emp.email       || '';
    document.getElementById('e_dept').value          = emp.department  || '';
    document.getElementById('e_pos').value           = emp.position    || '';
    document.getElementById('e_hired').value         = emp.date_hired  || '';
    document.getElementById('e_salary').value        = emp.basic_salary|| '';
    document.getElementById('e_allowances').value    = emp.allowances  || '';
    document.getElementById('e_phone').value         = emp.phone       || '';
    document.getElementById('e_address').value       = emp.address     || '';
    document.getElementById('e_civil').value         = emp.civil_status|| 'single';
    document.getElementById('e_sss').value           = emp.sss_no      || '';
    document.getElementById('e_ph').value            = emp.philhealth_no|| '';
    document.getElementById('e_pagibig').value       = emp.pagibig_no  || '';
    document.getElementById('e_tin').value           = emp.tin_no      || '';
    document.getElementById('e_status').value        = emp.status      || 'active';
    openModal('editModal');
}

// ── deleteEmployee() ──────────────────────────────────────
// Nagtatanong muna sa user bago i-delete (para hindi aksidente)
function deleteEmployee(id, name) {
    if (confirm('Delete employee ' + name + '? This cannot be undone.')) {
        document.getElementById('del_id').value = id;
        document.getElementById('deleteForm').submit(); // I-submit ang hidden form
    }
}

// ── resetPw() ─────────────────────────────────────────────
// Binubuksan ang reset password modal at pinupunan ang hidden fields
function resetPw(id, name) {
    document.getElementById('rp_id').value   = id;
    document.getElementById('rp_name').value = name;
    openModal('resetPwModal');
}
</script>
