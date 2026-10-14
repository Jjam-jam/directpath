<?php
// ============================================================
// Directpath HRMS — User Management (Super Admin)
//
// The one place where HR Admin and Payroll Manager accounts are created and managed.
// Lists EVERY account (HR Admin, Payroll Manager, CEO, Super Admin, Employees) with a
// tab per role. HR's Employee Management only ever shows employees.
//
//   - Create: HR Admin, Payroll Manager, CEO (employees are added by HR)
//   - Edit: name, email, role, department / position
//   - Reset password, activate / deactivate
//   - Every change is written to the audit log inside the same transaction
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('superadmin');

$pageTitle  = 'User Management';
$activePage = 'admins';
$db = getDB();

// ── Role catalogue ─────────────────────────────────────────
// label, badge class, e-mail suffix used when creating the account, default department / position
$ROLES = [
    'hr_admin'        => ['label' => 'HR Admin',        'badge' => 'badge-blue',   'suffix' => '.hr@gmail.com',  'dept' => 'Human Resources', 'pos' => 'HR Admin'],
    'payroll_manager' => ['label' => 'Payroll Manager', 'badge' => 'badge-purple', 'suffix' => '.pm@gmail.com',  'dept' => 'Finance',         'pos' => 'Payroll Manager'],
    'ceo'             => ['label' => 'CEO',             'badge' => 'badge-amber',  'suffix' => '.ceo@gmail.com', 'dept' => 'Executive',       'pos' => 'CEO'],
    'superadmin'      => ['label' => 'Super Admin',     'badge' => 'badge-red',    'suffix' => '',               'dept' => 'IT Administration', 'pos' => 'System Administrator'],
    'employee'        => ['label' => 'Employee',        'badge' => 'badge-gray',   'suffix' => '',               'dept' => '',                'pos' => ''],
];
// Roles the Super Admin can CREATE here. Employees are added by HR (Employee Management).
$CREATABLE = ['hr_admin', 'payroll_manager', 'ceo'];
$TABS = [
    'all'             => 'All Users',
    'hr_admin'        => 'HR Admin',
    'payroll_manager' => 'Payroll Manager',
    'ceo'             => 'CEO',
    'superadmin'      => 'Super Admin',
    'employee'        => 'Employees',
];

// Names are stored HTML-encoded by sanitize(); decode for display / forms (output is escaped again).
function um_dec($v): string { return html_entity_decode((string)$v, ENT_QUOTES); }

function um_find(PDO $db, string $id): ?array {
    $st = $db->prepare("SELECT u.employee_id, u.first_name, u.middle_name, u.last_name, u.email, u.department,
                               u.position, u.status, u.role,
                               (SELECT r.role_name FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                                 WHERE ur.employee_id = u.employee_id ORDER BY ur.id LIMIT 1) AS assigned_role
                        FROM users u WHERE u.employee_id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

$success = '';
$error   = '';
if (!empty($_SESSION['um_flash'])) {
    $success = $_SESSION['um_flash']['success'] ?? '';
    $error   = $_SESSION['um_flash']['error']   ?? '';
    unset($_SESSION['um_flash']);
}

// ── POST actions (Post/Redirect/Get so a refresh never re-submits) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $me     = $_SESSION['employee_id'];

    // === ADD ACCOUNT (HR Admin / Payroll Manager / CEO) ===
    if ($action === 'add') {
        $firstName  = sanitize($_POST['first_name']  ?? '');
        $middleName = sanitize($_POST['middle_name'] ?? '');
        $lastName   = sanitize($_POST['last_name']   ?? '');
        $prefix     = trim((string)($_POST['email_prefix'] ?? ''));
        $gender     = sanitize($_POST['gender'] ?? '');
        $gender     = in_array($gender, ['male', 'female', 'other'], true) ? $gender : null;
        $roleName   = sanitize($_POST['role_name'] ?? '');
        $pw         = (string)($_POST['password'] ?? '');

        if (!in_array($roleName, $CREATABLE, true)) {
            $error = 'Only HR Admin, Payroll Manager and CEO accounts can be created here. Employees are added by HR in Employee Management.';
        } elseif (!$firstName || !$lastName) {
            $error = 'First name and last name are required.';
        } elseif (!preg_match('/^[A-Za-z0-9._%+-]+$/', $prefix)) {
            $error = 'Enter a valid email username (letters, numbers and . _ % + - only).';
        } elseif (strlen($pw) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
            $dept  = sanitize($_POST['department'] ?? '') ?: $ROLES[$roleName]['dept'];
            $pos   = sanitize($_POST['position']   ?? '') ?: $ROLES[$roleName]['pos'];
            $email = strtolower($prefix . $ROLES[$roleName]['suffix']);

            $dup = $db->prepare("SELECT 1 FROM users WHERE email = ?");
            $dup->execute([$email]);
            $roleStmt = $db->prepare("SELECT id FROM roles WHERE role_name = ?");
            $roleStmt->execute([$roleName]);
            $roleId = $roleStmt->fetchColumn();

            if ($dup->fetchColumn()) {
                $error = "The email $email is already used by another account.";
            } elseif (!$roleId) {
                $error = 'Selected role is unavailable. Run config/role_separation_migration.sql first.';
            } else {
                // Next free AD-#### id
                $lastId = $db->query("SELECT employee_id FROM users WHERE employee_id LIKE 'AD-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
                $num    = $lastId ? (int)substr($lastId, 3) + 1 : 1;
                $chk    = $db->prepare("SELECT 1 FROM users WHERE employee_id = ?");
                do {
                    $userId = 'AD-' . str_pad((string)$num, 4, '0', STR_PAD_LEFT);
                    $chk->execute([$userId]);
                    $taken = (bool)$chk->fetchColumn();
                    $num++;
                } while ($taken);

                $fullName = getFullName($firstName, $middleName ?: null, $lastName);
                $db->beginTransaction();
                try {
                    $db->prepare("INSERT INTO users
                            (employee_id, first_name, middle_name, last_name, email, password,
                             gender, department, position, date_hired, status, role, work_mode)
                            VALUES (?,?,?,?,?,?,?,?,?,?,'active',?,'wfh')")
                       ->execute([$userId, $firstName, $middleName ?: null, $lastName, $email,
                                  password_hash($pw, PASSWORD_DEFAULT), $gender, $dept, $pos, date('Y-m-d'), $roleName]);
                    $db->prepare("INSERT INTO user_roles (employee_id, role_id, assigned_by) VALUES (?, ?, ?)")
                       ->execute([$userId, $roleId, $me]);
                    if (!auditLog('Create User', 'User Management', "Created {$ROLES[$roleName]['label']} account: $fullName ($userId)")) {
                        throw new RuntimeException('Audit log write failed.');
                    }
                    $db->commit();
                    $success = "{$ROLES[$roleName]['label']} account for $fullName created (ID: $userId, login: $email).";
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log('Create user failed: ' . $e->getMessage());
                    $error = 'Account creation failed; no account or role assignment was saved.';
                }
            }
        }
    }

    // === EDIT ACCOUNT (name, email, role, department / position) ===
    if ($action === 'edit') {
        $id = sanitize($_POST['user_id'] ?? '');
        $t  = um_find($db, $id);
        $firstName  = sanitize($_POST['first_name']  ?? '');
        $middleName = sanitize($_POST['middle_name'] ?? '');
        $lastName   = sanitize($_POST['last_name']   ?? '');
        $email      = strtolower(trim((string)($_POST['email'] ?? '')));
        $roleName   = sanitize($_POST['role_name'] ?? '');

        if (!$t) {
            $error = 'User not found.';
        } elseif ($id === $me) {
            $error = 'Use My Profile to change your own account.';
        } elseif (!isset($ROLES[$roleName])) {
            $error = 'Choose a valid role.';
        } elseif (!$firstName || !$lastName) {
            $error = 'First name and last name are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } else {
            $dup = $db->prepare("SELECT 1 FROM users WHERE email = ? AND employee_id <> ?");
            $dup->execute([$email, $id]);
            $newRoleId = null;
            $oldRole   = $t['assigned_role'] ?: '';
            $roleChanged = ($roleName !== $oldRole);
            if ($roleChanged) {
                $rs = $db->prepare("SELECT id FROM roles WHERE role_name = ?");
                $rs->execute([$roleName]);
                $newRoleId = $rs->fetchColumn();
            }
            if ($dup->fetchColumn()) {
                $error = "The email $email is already used by another account.";
            } elseif ($roleChanged && !$newRoleId) {
                $error = 'Selected role is unavailable. Run config/role_separation_migration.sql first.';
            } else {
                // Employees keep the department / position HR gave them (Employee Management owns those).
                $dept = $t['department']; $pos = $t['position'];
                if ($roleName !== 'employee') {
                    $dept = sanitize($_POST['department'] ?? '') ?: $ROLES[$roleName]['dept'];
                    $pos  = sanitize($_POST['position']   ?? '') ?: $ROLES[$roleName]['pos'];
                }
                $db->beginTransaction();
                try {
                    $db->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=?, email=?, department=?, position=?, role=? WHERE employee_id=?")
                       ->execute([$firstName, $middleName ?: null, $lastName, $email, $dept, $pos, $roleName, $id]);

                    if ($roleChanged) {
                        $db->prepare("DELETE FROM user_roles WHERE employee_id = ?")->execute([$id]);
                        $db->prepare("INSERT INTO user_roles (employee_id, role_id, assigned_by) VALUES (?, ?, ?)")
                           ->execute([$id, $newRoleId, $me]);
                        if ($roleName === 'employee') {
                            $db->prepare("INSERT IGNORE INTO leave_balances (employee_id, year) VALUES (?, ?)")
                               ->execute([$id, date('Y')]);
                        }
                        if ($oldRole === 'employee') {
                            // A Team Manager who leaves the Employee role must not stay the department's leader
                            $db->prepare("UPDATE users SET is_team_leader = 0, team_leader_id = NULL WHERE employee_id = ?")->execute([$id]);
                            if ($t['department'] !== '') syncDepartmentTeam($db, (string)$t['department']);
                        }
                    }

                    $oldName  = getFullName(um_dec($t['first_name']), um_dec($t['middle_name']), um_dec($t['last_name']));
                    $fullName = getFullName(um_dec($firstName), um_dec($middleName), um_dec($lastName));
                    $note = ($roleChanged ? " | Role: " . ($oldRole ?: 'none') . " -> $roleName" : '')
                          . ($t['email'] !== $email ? " | Email: {$t['email']} -> $email" : '');
                    if (!auditLog('Update User', 'User Management', "$id | Name: $oldName -> $fullName$note")) {
                        throw new RuntimeException('Audit log write failed.');
                    }
                    $db->commit();
                    $success = "$fullName updated" . ($roleChanged ? " — role is now {$ROLES[$roleName]['label']}." : '.');
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log('Update user failed: ' . $e->getMessage());
                    $error = 'The account was not updated; no changes were saved.';
                }
            }
        }
    }

    // === RESET PASSWORD ===
    if ($action === 'reset_pw') {
        $id = sanitize($_POST['user_id'] ?? '');
        $pw = (string)($_POST['new_password'] ?? '');
        $t  = um_find($db, $id);
        if (!$t) {
            $error = 'User not found.';
        } elseif ($id === $me) {
            $error = 'Use My Profile to change your own password.';
        } elseif (strlen($pw) < 8) {
            $error = 'New password must be at least 8 characters.';
        } else {
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE users SET password = ? WHERE employee_id = ?")
                   ->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
                if (!auditLog('Reset Password', 'User Management', "Password reset for $id")) {
                    throw new RuntimeException('Audit log write failed.');
                }
                $db->commit();
                $success = 'Password reset for ' . um_dec($t['first_name']) . ' ' . um_dec($t['last_name']) . '.';
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Password was not reset because the audit record could not be written.';
            }
        }
    }

    // === ACTIVATE / DEACTIVATE ===
    if ($action === 'toggle') {
        $id = sanitize($_POST['user_id'] ?? '');
        $ns = sanitize($_POST['new_status'] ?? '');
        if ($id === $me) {
            $error = 'You cannot change your own account status.';
        } elseif (!in_array($ns, ['active', 'inactive'], true) || !um_find($db, $id)) {
            $error = 'User not found.';
        } else {
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE users SET status = ? WHERE employee_id = ?")->execute([$ns, $id]);
                if (!auditLog($ns === 'active' ? 'Activate User' : 'Deactivate User', 'User Management', "Set $id to $ns")) {
                    throw new RuntimeException('Audit log write failed.');
                }
                $db->commit();
                $success = $ns === 'active' ? 'Account activated.' : 'Account deactivated; existing records were preserved.';
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Status was not changed because the audit record could not be written.';
            }
        }
    }

    $_SESSION['um_flash'] = ['success' => $success, 'error' => $error];
    $back = [];
    $rt = (string)($_POST['ret_tab'] ?? '');
    $rq = trim((string)($_POST['ret_q'] ?? ''));
    if (isset($TABS[$rt]) && $rt !== 'all') $back['tab'] = $rt;
    if ($rq !== '') $back['q'] = $rq;
    header('Location: admins.php' . ($back ? '?' . http_build_query($back) : ''));
    exit;
}

// ── Load accounts (role = first row in user_roles, the same source login uses) ──
$all = $db->query("
    SELECT u.employee_id, u.first_name, u.middle_name, u.last_name, u.email,
           u.department, u.position, u.status, u.role AS legacy_role,
           (SELECT r.role_name FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.employee_id = u.employee_id ORDER BY ur.id LIMIT 1) AS assigned_role
    FROM users u
    ORDER BY u.last_name, u.first_name
")->fetchAll();

$counts = array_fill_keys(array_keys($TABS), 0);
foreach ($all as &$u) {
    $u['eff_role'] = $u['assigned_role'] ?: (string)$u['legacy_role'];
    $counts['all']++;
    if (isset($counts[$u['eff_role']])) $counts[$u['eff_role']]++;
}
unset($u);

$tab    = (string)($_GET['tab'] ?? 'all');
$tab    = isset($TABS[$tab]) ? $tab : 'all';
$search = trim((string)($_GET['q'] ?? ''));
$words  = $search === '' ? [] : preg_split('/\s+/u', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY);

$accounts = array_values(array_filter($all, static function ($u) use ($tab, $words, $ROLES) {
    if ($tab !== 'all' && $u['eff_role'] !== $tab) return false;
    if (!$words) return true;
    $hay = mb_strtolower(implode(' ', [
        $u['employee_id'], um_dec($u['first_name']), um_dec($u['middle_name']), um_dec($u['last_name']),
        $u['email'], um_dec($u['department']), um_dec($u['position']), $u['status'],
        $ROLES[$u['eff_role']]['label'] ?? $u['eff_role'],
    ]));
    foreach ($words as $w) if (mb_strpos($hay, $w) === false) return false;
    return true;
}));

$tabsForBar = [];
foreach ($TABS as $k => $lbl) $tabsForBar[$k] = [$lbl, $counts[$k]];

include '../../includes/header.php';
?>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?= renderTabBar($tabsForBar, $tab, 'tab', $search !== '' ? ['q' => $search] : []) ?>

<div class="card">
    <div class="um-toolbar">
        <form method="GET" class="filter-bar" style="margin:0;flex:1 1 320px;">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <input type="search" name="q" class="search-input" autocomplete="off" style="min-width:260px;max-width:460px;width:100%;"
                   placeholder="Search anything: name, ID, email, role, department..."
                   value="<?= htmlspecialchars($search) ?>">
            <?php if ($search !== ''): ?><a href="admins.php?tab=<?= htmlspecialchars($tab) ?>" class="btn-secondary">Reset</a><?php endif; ?>
        </form>
        <button type="button" class="btn-primary" onclick="openModal('addUserModal')">+ Add HR / Payroll Manager</button>
    </div>
    <div class="muted-note" style="margin-top:10px;font-size:12px;color:#6B7280;">
        This list includes HR Admin and Payroll Manager accounts. HR's Employee Management shows employees only.
        HR Admin and Payroll Manager accounts can be created only here.
    </div>
</div>
<style>.um-toolbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}</style>

<div class="card">
    <div class="card-title"><?= htmlspecialchars($TABS[$tab]) ?> (<?= count($accounts) ?>)</div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>ID</th><th>Name</th><th>Role</th><th>Department / Position</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $a):
                    $role   = $a['eff_role'];
                    $meta   = $ROLES[$role] ?? ['label' => ucwords(str_replace('_', ' ', $role ?: 'No role')), 'badge' => 'badge-gray'];
                    $name   = getFullName(um_dec($a['first_name']), um_dec($a['middle_name']), um_dec($a['last_name']));
                    $isSelf = $a['employee_id'] === $_SESSION['employee_id'];
                    $payload = [
                        'id' => $a['employee_id'], 'first' => um_dec($a['first_name']), 'middle' => um_dec($a['middle_name']),
                        'last' => um_dec($a['last_name']), 'email' => $a['email'], 'role' => $a['assigned_role'] ?: '',
                        'dept' => um_dec($a['department']), 'pos' => um_dec($a['position']), 'name' => $name,
                        // accounts with no user_roles row: start from their old users.role, not "Employee"
                        'legacy' => isset($ROLES[$a['legacy_role']]) ? $a['legacy_role']
                                  : (['admin' => 'hr_admin', 'payroll' => 'payroll_manager'][$a['legacy_role']] ?? 'employee'),
                    ];
                ?>
                <tr>
                    <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($a['employee_id']) ?></td>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($name) ?><?= $isSelf ? ' <span class="badge badge-gray">You</span>' : '' ?></div>
                        <div style="font-size:11px;color:#9CA3AF;"><?= htmlspecialchars($a['email']) ?></div>
                    </td>
                    <td>
                        <span class="badge <?= $meta['badge'] ?>"><?= htmlspecialchars($meta['label']) ?></span>
                        <?php if (!$a['assigned_role']): ?><div style="font-size:11px;color:#CD1E27;margin-top:3px;">No role assigned (cannot log in)</div><?php endif; ?>
                    </td>
                    <td>
                        <div><?= htmlspecialchars(um_dec($a['department'])) ?></div>
                        <div style="font-size:11px;color:#9CA3AF;"><?= htmlspecialchars(um_dec($a['position'])) ?></div>
                    </td>
                    <td><span class="badge <?= statusBadge($a['status']) ?>"><?= ucfirst($a['status']) ?></span></td>
                    <td>
                        <?php if ($isSelf): ?>
                            <span style="font-size:12px;color:#9CA3AF;">Manage in My Profile</span>
                        <?php else: ?>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <button type="button" class="btn-secondary btn-sm" onclick='openEditUser(<?= htmlspecialchars(json_encode($payload), ENT_QUOTES) ?>)'>Edit</button>
                            <button type="button" class="btn-secondary btn-sm" onclick='openResetPw(<?= htmlspecialchars(json_encode(['id' => $a['employee_id'], 'name' => $name]), ENT_QUOTES) ?>)'>Reset PW</button>
                            <?php $newStatus = $a['status'] === 'active' ? 'inactive' : 'active'; ?>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('<?= $newStatus === 'inactive' ? 'Deactivate' : 'Activate' ?> <?= htmlspecialchars(addslashes($name), ENT_QUOTES) ?>?<?= $newStatus === 'inactive' ? ' Existing records will be kept.' : '' ?>')">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="user_id" value="<?= htmlspecialchars($a['employee_id']) ?>">
                                <input type="hidden" name="new_status" value="<?= $newStatus ?>">
                                <input type="hidden" name="ret_tab" value="<?= htmlspecialchars($tab) ?>">
                                <input type="hidden" name="ret_q" value="<?= htmlspecialchars($search) ?>">
                                <button type="submit" class="btn-secondary btn-sm" <?= $newStatus === 'inactive' ? 'style="color:#CD1E27;"' : '' ?>>
                                    <?= $newStatus === 'inactive' ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$accounts): ?>
                <tr><td colspan="6" style="text-align:center;color:#9CA3AF;padding:24px;">No accounts found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div><!-- end page-body -->

<!-- ===== ADD ACCOUNT MODAL (HR Admin / Payroll Manager / CEO) ===== -->
<div class="modal-overlay" id="addUserModal">
    <div class="modal-box" style="max-width:680px;width:calc(100% - 32px);">
        <div class="modal-header">
            <div class="modal-title">Add Account</div>
            <button type="button" class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="ret_tab" value="<?= htmlspecialchars($tab) ?>">
            <input type="hidden" name="ret_q" value="<?= htmlspecialchars($search) ?>">
            <div class="row g-3">
                <div class="col-6"><div class="form-group"><label class="form-label">First Name *</label>
                    <input type="text" name="first_name" class="form-input" required></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Middle Name</label>
                    <input type="text" name="middle_name" class="form-input"></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Last Name *</label>
                    <input type="text" name="last_name" class="form-input" required></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Gender</label>
                    <select name="gender" class="form-input">
                        <option value="">-- Select --</option><option value="male">Male</option>
                        <option value="female">Female</option><option value="other">Other</option>
                    </select></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Role *</label>
                    <select name="role_name" id="addRole" class="form-select" required>
                        <?php foreach ($CREATABLE as $rk): ?>
                        <option value="<?= $rk ?>" data-suffix="<?= htmlspecialchars($ROLES[$rk]['suffix']) ?>" data-dept="<?= htmlspecialchars($ROLES[$rk]['dept']) ?>" data-pos="<?= htmlspecialchars($ROLES[$rk]['pos']) ?>"><?= htmlspecialchars($ROLES[$rk]['label']) ?></option>
                        <?php endforeach; ?>
                    </select></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Password *</label>
                    <input type="password" name="password" class="form-input" required minlength="8" autocomplete="new-password">
                    <div style="font-size:11px;color:#6B7280;margin-top:4px;">At least 8 characters.</div></div></div>
                <div class="col-12"><div class="form-group"><label class="form-label" for="addEmailPrefix">Email *</label>
                    <div style="display:flex;align-items:stretch;width:100%;">
                        <input type="text" name="email_prefix" id="addEmailPrefix" class="form-input" style="flex:1;min-width:0;border-top-right-radius:0;border-bottom-right-radius:0;" required pattern="[A-Za-z0-9._%+-]+" autocomplete="off">
                        <span id="addEmailSuffix" style="display:inline-flex;align-items:center;white-space:nowrap;padding:0 12px;border:1px solid #E5E7EB;border-left:0;border-radius:0 9px 9px 0;background:#F9FAFB;font-size:13px;color:#374151;"></span>
                    </div>
                    <div style="font-size:11px;color:#6B7280;margin-top:5px;">Login email: <strong id="addEmailPreview" style="font-weight:500;"></strong></div></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Department</label>
                    <input type="text" name="department" id="addDept" class="form-input"></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Position</label>
                    <input type="text" name="position" id="addPos" class="form-input"></div></div>
            </div>
            <div style="font-size:12px;color:#6B7280;margin-top:8px;">Employees are not created here. HR adds them in Employee Management.</div>
            <div style="display:flex;gap:10px;margin-top:16px;">
                <button type="submit" class="btn-primary">Create Account</button>
                <button type="button" class="btn-secondary" onclick="closeModal('addUserModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== EDIT ACCOUNT MODAL ===== -->
<div class="modal-overlay" id="editUserModal">
    <div class="modal-box" style="max-width:680px;width:calc(100% - 32px);">
        <div class="modal-header">
            <div class="modal-title">Edit Account <span id="editUserId" style="font-family:monospace;font-size:12px;color:#9CA3AF;"></span></div>
            <button type="button" class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" id="editId">
            <input type="hidden" name="ret_tab" value="<?= htmlspecialchars($tab) ?>">
            <input type="hidden" name="ret_q" value="<?= htmlspecialchars($search) ?>">
            <div class="row g-3">
                <div class="col-4"><div class="form-group"><label class="form-label">First Name *</label>
                    <input type="text" name="first_name" id="editFirst" class="form-input" required></div></div>
                <div class="col-4"><div class="form-group"><label class="form-label">Middle Name</label>
                    <input type="text" name="middle_name" id="editMiddle" class="form-input"></div></div>
                <div class="col-4"><div class="form-group"><label class="form-label">Last Name *</label>
                    <input type="text" name="last_name" id="editLast" class="form-input" required></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Email *</label>
                    <input type="email" name="email" id="editEmail" class="form-input" required></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Role *</label>
                    <select name="role_name" id="editRole" class="form-select" required>
                        <?php foreach ($ROLES as $rk => $rm): ?>
                        <option value="<?= $rk ?>" data-dept="<?= htmlspecialchars($rm['dept']) ?>" data-pos="<?= htmlspecialchars($rm['pos']) ?>"><?= htmlspecialchars($rm['label']) ?></option>
                        <?php endforeach; ?>
                    </select></div></div>
                <div class="col-6" id="editOrgDept"><div class="form-group"><label class="form-label">Department</label>
                    <input type="text" name="department" id="editDept" class="form-input"></div></div>
                <div class="col-6" id="editOrgPos"><div class="form-group"><label class="form-label">Position</label>
                    <input type="text" name="position" id="editPos" class="form-input"></div></div>
            </div>
            <div id="editEmployeeNote" style="display:none;font-size:12px;color:#6B7280;margin-top:8px;">Department and position of an employee are managed by HR in Employee Management.</div>
            <div id="editRoleWarn" class="alert alert-warning" style="display:none;margin-top:12px;">Changing the role changes what this person can open the next time a page loads.</div>
            <div style="display:flex;gap:10px;margin-top:16px;">
                <button type="submit" class="btn-primary">Save Changes</button>
                <button type="button" class="btn-secondary" onclick="closeModal('editUserModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== RESET PASSWORD MODAL ===== -->
<div class="modal-overlay" id="resetPwModal">
    <div class="modal-box" style="max-width:440px;width:calc(100% - 32px);">
        <div class="modal-header">
            <div class="modal-title">Reset Password</div>
            <button type="button" class="modal-close" onclick="closeModal('resetPwModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="reset_pw">
            <input type="hidden" name="user_id" id="resetId">
            <input type="hidden" name="ret_tab" value="<?= htmlspecialchars($tab) ?>">
            <input type="hidden" name="ret_q" value="<?= htmlspecialchars($search) ?>">
            <p style="font-size:13px;margin:0 0 12px;">Set a new password for <strong id="resetName"></strong>.</p>
            <div class="form-group"><label class="form-label">New Password *</label>
                <input type="password" name="new_password" class="form-input" required minlength="8" autocomplete="new-password">
                <div style="font-size:11px;color:#6B7280;margin-top:4px;">At least 8 characters.</div></div>
            <div style="display:flex;gap:10px;margin-top:16px;">
                <button type="submit" class="btn-primary">Reset Password</button>
                <button type="button" class="btn-secondary" onclick="closeModal('resetPwModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Add account: role drives the email suffix and the default department / position ──
(function () {
    var role = document.getElementById('addRole'), prefix = document.getElementById('addEmailPrefix'),
        suffix = document.getElementById('addEmailSuffix'), preview = document.getElementById('addEmailPreview'),
        dept = document.getElementById('addDept'), pos = document.getElementById('addPos');
    function sync(resetOrg) {
        var o = role.selectedOptions[0];
        suffix.textContent = o.dataset.suffix;
        preview.textContent = (prefix.value || 'username') + o.dataset.suffix;
        if (resetOrg) { dept.value = o.dataset.dept; pos.value = o.dataset.pos; }
    }
    role.addEventListener('change', function () { sync(true); });
    prefix.addEventListener('input', function () { sync(false); });
    sync(true);
})();

// ── Edit account ──
var editOriginalRole = '';
function editSyncRole(fromChange) {
    var sel = document.getElementById('editRole'), o = sel.selectedOptions[0], isEmp = sel.value === 'employee';
    document.getElementById('editOrgDept').style.display = isEmp ? 'none' : '';
    document.getElementById('editOrgPos').style.display  = isEmp ? 'none' : '';
    document.getElementById('editEmployeeNote').style.display = isEmp ? '' : 'none';
    var warn = document.getElementById('editRoleWarn');
    if (!editOriginalRole) {
        warn.textContent = 'This account has no role assigned, so it cannot log in. Saving assigns the role selected above.';
        warn.style.display = '';
    } else if (sel.value !== editOriginalRole) {
        warn.textContent = 'Changing the role changes what this person can open the next time a page loads.';
        warn.style.display = '';
    } else {
        warn.style.display = 'none';
    }
    // Moving someone to a staff role: start from that role's default department / position
    if (fromChange && !isEmp) {
        document.getElementById('editDept').value = o.dataset.dept;
        document.getElementById('editPos').value  = o.dataset.pos;
    }
}
document.getElementById('editRole').addEventListener('change', function () { editSyncRole(true); });
function openEditUser(u) {
    editOriginalRole = u.role || '';
    document.getElementById('editId').value     = u.id;
    document.getElementById('editUserId').textContent = u.id;
    document.getElementById('editFirst').value  = u.first;
    document.getElementById('editMiddle').value = u.middle;
    document.getElementById('editLast').value   = u.last;
    document.getElementById('editEmail').value  = u.email;
    document.getElementById('editRole').value   = u.role || u.legacy || 'employee';
    document.getElementById('editDept').value   = u.dept;
    document.getElementById('editPos').value    = u.pos;
    editSyncRole(false);
    openModal('editUserModal');
}
function openResetPw(u) {
    document.getElementById('resetId').value = u.id;
    document.getElementById('resetName').textContent = u.name;
    openModal('resetPwModal');
}
</script>
