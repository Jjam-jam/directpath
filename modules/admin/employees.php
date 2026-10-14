<?php
// ============================================================
// Directpath HRMS — Employee Management (Superadmin)
// Pinagbago: Gumagamit na ng 'users' table (normalized schema)
// First name, middle name, at last name na ang ginagamit
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin');

$pageTitle  = 'Employee Management';
$activePage = 'employees';
$db = getDB();

$success = '';
$error   = '';

// ── Department / position / Team Manager rules ─────────────
// Department comes from the fixed list (departments table). Position is NEVER typed:
// a Team Manager gets the department's TL position; everyone else picks from that
// department's staff positions (anything invalid falls back to the first one).
$deptMap = departmentPositionMap();
// HR is NOT assignable here: HR people are created only by the Super Admin (User Management).
// $deptMap stays complete so existing HR-department staff can still be viewed and edited.
$assignableDepts = array_values(array_filter(array_keys($deptMap), static fn($d) => !isHrDepartment((string)$d)));

$desReady     = designationsReady($db);
$designations = getDesignations($db);          // custom designations (other than Team Manager)

// HR people are filed under ONE department, "Human Resources", in the list / filter / headcount, whatever
// the HR department is called in the departments table or on the account record.
$hrDeptName = '';
foreach (array_keys($deptMap) as $d) { if (isHrDepartment((string)$d)) { $hrDeptName = 'Human Resources'; break; } }
function listDept(string $dept, string $role, string $hrDeptName): string {
    $dept = html_entity_decode($dept);
    return ($hrDeptName !== '' && ($role === 'hr_admin' || isHrDepartment($dept))) ? $hrDeptName : $dept;
}

// Returns [position, error]
function resolveDeptPosition(array $deptMap, string $dept, bool $isTl, string $postedPos): array {
    if (!isset($deptMap[$dept])) return [null, 'Please choose a valid department from the list.'];
    if ($isTl) return [$deptMap[$dept]['tl'], null];
    $staff = $deptMap[$dept]['staff'];
    if (!$staff) return [null, 'No staff positions are configured for this department.'];
    return [in_array($postedPos, $staff, true) ? $postedPos : $staff[0], null];
}

// Name of another active Team Manager in $dept (excluding $exceptId), or null.
function otherTeamLeader(PDO $db, string $dept, string $exceptId = ''): ?string {
    $st = $db->prepare("SELECT CONCAT(first_name,' ',last_name) FROM users WHERE department=? AND is_team_leader=1 AND status='active' AND employee_id<>? LIMIT 1");
    $st->execute([$dept, $exceptId]);
    return $st->fetchColumn() ?: null;
}

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
        $dept       = trim(strip_tags($_POST['department'] ?? ''));
        // Department / position / designation can each be a NEW value typed in the form.
        // Designation = None (regular employee) | Team Manager | a custom designation (own base salary).
        $desRaw     = (string)($_POST['is_team_leader'] ?? 'no');
        $isTl       = ($desRaw === 'yes');
        $postedPos  = trim($_POST['position'] ?? '');
        $newPosName = trim(strip_tags($_POST['new_position']    ?? ''));
        $newDesName = trim(strip_tags($_POST['new_designation'] ?? ''));
        $newDesSalary = (float)str_replace(',', '', (string)($_POST['new_designation_salary'] ?? '0'));   // salary of a NEW designation
        $newPosSalary = (float)str_replace(',', '', (string)($_POST['new_position_salary'] ?? '0'));      // salary of a NEW position
        $isNewDept  = false;
        $newPosition = null;          // ['title' => ...] when a position must be created
        $desRow = null;               // existing / reused designation row (id, name, base_salary)
        $newDesig = false;            // true when a brand-new designation must be created
        $pos = null; $posError = null;

        if ($dept === '__new__') {
            $dept = trim(strip_tags($_POST['new_department'] ?? ''));
            foreach (array_keys($deptMap) as $dn) {            // same name as an existing department -> use that one
                if (mb_strtolower(html_entity_decode((string)$dn)) === mb_strtolower($dept)) { $dept = (string)$dn; break; }
            }
            $isNewDept = ($dept !== '' && !isset($deptMap[$dept]));
            if ($dept === '')                $posError = 'Enter the name of the new department.';
            elseif (mb_strlen($dept) > 100)  $posError = 'The department name is too long (100 characters max).';
        }

        // Designation
        if (!$posError && ($desRaw === '__new__' || strpos($desRaw, 'd:') === 0)) {
            if (!designationsReady($db)) {
                $posError = 'Custom designations need the database update: import config/designation_migration.sql once, then try again.';
            } elseif ($desRaw === '__new__') {
                if ($newDesName === '')                $posError = 'Enter the new designation.';
                elseif (mb_strlen($newDesName) > 100)  $posError = 'The designation name is too long (100 characters max).';
                elseif (mb_strtolower($newDesName) === 'team manager') $posError = 'Team Manager is already a designation - pick it from the list.';
                else {
                    $q = $db->prepare("SELECT id, name, base_salary FROM designations WHERE LOWER(name) = LOWER(?) LIMIT 1");
                    $q->execute([$newDesName]);
                    $desRow = $q->fetch() ?: null;            // typed an existing one -> reuse it with its saved salary
                    $newDesig = !$desRow;
                }
            } else {
                $q = $db->prepare("SELECT id, name, base_salary FROM designations WHERE id = ? AND is_active = 1");
                $q->execute([(int)substr($desRaw, 2)]);
                $desRow = $q->fetch() ?: null;
                if (!$desRow) $posError = 'That designation no longer exists. Please pick another one.';
            }
        }

        // Position. "+ Add new position" is available with every designation, Team Manager included.
        if (!$posError) {
            if ($isTl && !$isNewDept && $postedPos !== '__new__') {
                [$pos, $posError] = resolveDeptPosition($deptMap, $dept, true, $postedPos);      // the department's Team Manager position
                if (!$posError && !$pos) $posError = 'This department has no Team Manager position yet. Choose "Add new position".';
            } elseif ($postedPos === '__new__' || $isNewDept) {
                $pos = $newPosName;
                if ($pos === '') $posError = 'Enter the new position.';
                else $newPosition = ['title' => $pos];
            } else {
                [$pos, $posError] = resolveDeptPosition($deptMap, $dept, false, $postedPos);
            }
        }
        if (!$posError && $newPosition && mb_strlen($newPosition['title']) > 100) $posError = 'The position name is too long (100 characters max).';

        // A "new" position that already exists in the department is simply reused.
        $existingPosRow = null;
        if (!$posError && $newPosition && !$isNewDept) {
            $q = $db->prepare("SELECT p.id, p.base_salary FROM department_positions p JOIN departments d ON d.id = p.department_id
                               WHERE d.name = ? AND LOWER(p.position_title) = LOWER(?) LIMIT 1");
            $q->execute([$dept, $newPosition['title']]);
            $existingPosRow = $q->fetch() ?: null;
            if ($existingPosRow) $pos = $newPosition['title'];
        }
        // A new position needs its own base salary, EXCEPT when a new designation is added at the same time:
        // then only ONE salary is entered (the designation's) and it is used for both.
        $needPosSalary = ($newPosition && !$existingPosRow && !$newDesig);
        $hired      = sanitize($_POST['date_hired']  ?? '') ?: date('Y-m-d');
        $phone      = sanitize($_POST['phone']        ?? '');
        $address    = sanitize($_POST['address']      ?? '');
        $civil      = sanitize($_POST['civil_status'] ?? 'single');
        $sss        = sanitize($_POST['sss_no']       ?? '');
        $ph         = sanitize($_POST['philhealth_no']?? '');
        $pagibig    = sanitize($_POST['pagibig_no']   ?? '');
        $tin        = sanitize($_POST['tin_no']       ?? '');
        $pw         = trim((string)($_POST['password'] ?? '')) ?: 'password';

        // First name at last name ay required — middle name ay optional
        $tlConflict = ($isTl && !$posError && !$isNewDept) ? otherTeamLeader($db, $dept) : null;
        if (!$firstName || !$lastName || !$email || !$dept) {
            $error = 'First name, last name, email, and department are required.';
        } elseif (isHrDepartment($dept)) {
            $error = 'Adding HR is not allowed here. HR accounts are created by the Super Admin (User Management).';
        } elseif ($posError) {
            $error = $posError;
        } elseif ($newDesig && ($newDesSalary <= 0 || $newDesSalary > 10000000)) {
            $error = 'Enter a base salary (monthly, greater than 0) for the new designation.';
        } elseif ($needPosSalary && ($newPosSalary <= 0 || $newPosSalary > 10000000)) {
            $error = 'Enter a base salary (monthly, greater than 0) for the new position.';
        } elseif ($tlConflict) {
            $error = "$dept already has a Team Manager ($tlConflict). Remove that designation first (one Team Manager per department).";
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
                if (in_array($ext, $allowed, true) && @getimagesize($_FILES['profile_photo']['tmp_name']) !== false) {
                    $photoName = 'profile_' . $empId . '_' . time() . '.' . $ext;
                    $dest      = PROFILE_PHOTO_PATH . $photoName;
                    if (!is_dir(PROFILE_PHOTO_PATH)) mkdir(PROFILE_PHOTO_PATH, 0755, true);
                    move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dest);
                }
            }

            // I-insert sa USERS table (hindi na employees — normalized na)
            $gender = sanitize($_POST['gender'] ?? '');
            $gender = in_array($gender, ['male', 'female', 'other'], true) ? $gender : null;

            $fullName = getFullName($firstName, $middleName, $lastName);
            $db->beginTransaction();
            try {
                // New department / position / designation are created first so the salary lookup below finds them.
                $deptId = 0;
                if ($isNewDept) {
                    $so = (int)$db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM departments")->fetchColumn();
                    $db->prepare("INSERT INTO departments (name, tl_position, target_headcount, sort_order) VALUES (?,?,?,?)")
                       ->execute([$dept, '', 0, $so]);
                    $deptId = (int)$db->lastInsertId();
                } elseif ($newPosition) {
                    $q = $db->prepare("SELECT id FROM departments WHERE name = ? LIMIT 1");
                    $q->execute([$dept]);
                    $deptId = (int)$q->fetchColumn();
                    if (!$deptId) throw new RuntimeException('Department not found.');
                }
                if ($newDesig) {
                    $db->prepare("INSERT INTO designations (name, base_salary, is_active) VALUES (?,?,1)")->execute([$newDesName, round($newDesSalary, 2)]);
                    $desRow = ['id' => (int)$db->lastInsertId(), 'name' => $newDesName, 'base_salary' => round($newDesSalary, 2)];
                }
                if ($newPosition) {
                    $posSalary = $newDesig ? round($newDesSalary, 2) : round($newPosSalary, 2);   // one shared salary when both are new
                    if ($existingPosRow) {
                        $db->prepare("UPDATE department_positions SET is_active = 1 WHERE id = ?")->execute([$existingPosRow['id']]);
                    } else {
                        $db->prepare("INSERT INTO department_positions (department_id, position_title, is_tl_position, base_salary, headcount, is_active)
                                      VALUES (?,?,0,?,0,1)")->execute([$deptId, $newPosition['title'], $posSalary]);
                    }
                }
                // Payroll, payslip, contributions, tax, 13th month and COE all read users.basic_salary, so this is the
                // single place the salary has to be right: a designation's salary wins, else the position's base salary
                // (a new position's salary was saved just above, so the lookup finds it).
                $startSalary = $desRow ? (float)$desRow['base_salary'] : getPositionBaseSalary($dept, (string)$pos);
                $stmt = $db->prepare("INSERT INTO users
                    (employee_id, first_name, middle_name, last_name, email, password,
                     department, position, date_hired, phone, address, civil_status, gender,
                     sss_no, philhealth_no, pagibig_no, tin_no, work_mode, profile_photo, role, status, is_team_leader, basic_salary)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'wfh',?,'employee','active',?,?)");
                $stmt->execute([$empId, $firstName, $middleName ?: null, $lastName, $email, $hash,
                                $dept, $pos, $hired, $phone, $address, $civil, $gender, $sss, $ph, $pagibig, $tin, $photoName, $isTl ? 1 : 0,
                                $startSalary]);
                if ($desRow) $db->prepare("UPDATE users SET designation = ? WHERE employee_id = ?")->execute([$desRow['name'], $empId]);
                $roleStmt = $db->prepare("SELECT id FROM roles WHERE role_name = 'employee'");
                $roleStmt->execute();
                $roleId = $roleStmt->fetchColumn();
                if (!$roleId) throw new RuntimeException('Employee role is unavailable.');
                $db->prepare("INSERT INTO user_roles (employee_id, role_id) VALUES (?, ?)")
                   ->execute([$empId, $roleId]);
                $db->prepare("INSERT IGNORE INTO leave_balances (employee_id, year) VALUES (?, ?)")
                   ->execute([$empId, date('Y')]);
                syncDepartmentTeam($db, $dept);   // puts the new employee under the department's Team Manager
                if (!auditLog('Create Employee', 'Employee', "Created: $fullName ($empId) | $dept / $pos" . ($isTl ? ' | Team Manager' : '')
                    . ($desRow ? ' | Designation: ' . $desRow['name'] : '') . ' | Base salary: ' . number_format($startSalary, 2)
                    . ($isNewDept ? ' | new department' : '') . ($newPosition && !$existingPosRow ? ' | new position' : '') . ($newDesig ? ' | new designation' : ''))) {
                    throw new RuntimeException('Audit log write failed.');
                }
                $db->commit();
                if ($isNewDept || $newPosition || $newDesig) {         // refresh the lists used further down the page
                    $designations = getDesignations($db);
                    $deptMap = departmentPositionMap();
                    $assignableDepts = array_values(array_filter(array_keys($deptMap), static fn($d) => !isHrDepartment((string)$d)));
                }
                $success = "Employee $fullName added successfully (ID: $empId)."
                         . ($isNewDept ? " New department \"$dept\" created." : '')
                         . ($newPosition && !$existingPosRow ? " New position \"$pos\" created." : '')
                         . ($newDesig ? " New designation \"$newDesName\" created." : '');
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Add employee failed: ' . $e->getMessage());
                $error = 'Employee was not created because the account, role, or audit record could not be saved.';
            }
        }
    }

    // === EDIT EMPLOYEE ===
    if ($action === 'edit') {
        $editId     = sanitize($_POST['edit_id']     ?? '');
        $employeeCheck = $db->prepare("SELECT 1 FROM users u JOIN user_roles ur ON ur.employee_id=u.employee_id JOIN roles r ON r.id=ur.role_id WHERE u.employee_id=? AND r.role_name='employee'");
        $employeeCheck->execute([$editId]);
        if (!$employeeCheck->fetchColumn()) {
            $error = 'Employee not found.';
        } else {
        $firstName  = sanitize($_POST['first_name']  ?? '');
        $middleName = sanitize($_POST['middle_name'] ?? '');
        $lastName   = sanitize($_POST['last_name']   ?? '');
        $email      = sanitize($_POST['email']       ?? '');
        $dept       = trim(strip_tags($_POST['department'] ?? ''));
        $desRaw     = (string)($_POST['is_team_leader'] ?? 'no');
        $isTl       = ($desRaw === 'yes');
        $editDes    = null;                                   // custom designation chosen in the edit form
        if (strpos($desRaw, 'd:') === 0 && designationsReady($db)) {
            $q = $db->prepare("SELECT id, name, base_salary FROM designations WHERE id = ? AND is_active = 1");
            $q->execute([(int)substr($desRaw, 2)]);
            $editDes = $q->fetch() ?: null;
        }
        $hired      = sanitize($_POST['date_hired']  ?? '');
        $phone      = sanitize($_POST['phone']        ?? '');
        $address    = sanitize($_POST['address']      ?? '');
        $civil      = sanitize($_POST['civil_status'] ?? 'single');
        $sss        = sanitize($_POST['sss_no']       ?? '');
        $ph         = sanitize($_POST['philhealth_no']?? '');
        $pagibig    = sanitize($_POST['pagibig_no']   ?? '');
        $tin        = sanitize($_POST['tin_no']       ?? '');
        $status     = sanitize($_POST['status']       ?? 'active');
        if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';

        // Optional: Update ang profile photo
        $photoName = null;
        if (!empty($_FILES['profile_photo']['name'])) {
            $ext     = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed, true) && @getimagesize($_FILES['profile_photo']['tmp_name']) !== false) {
                $photoName = 'profile_' . $editId . '_' . time() . '.' . $ext;
                $dest      = PROFILE_PHOTO_PATH . $photoName;
                if (!is_dir(PROFILE_PHOTO_PATH)) mkdir(PROFILE_PHOTO_PATH, 0755, true);
                move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dest);
            }
        }

        $oldStmt = $db->prepare("SELECT first_name, middle_name, last_name, department, position, date_hired, status, is_team_leader" . (designationsReady($db) ? ", designation, basic_salary" : "") . " FROM users WHERE employee_id=? AND role='employee'");
        $oldStmt->execute([$editId]);
        $old = $oldStmt->fetch();
        $posError = null; $pos = null; $tlConflict = null;
        if ($old) {
            if (!isset($deptMap[$dept]) && $dept === $old['department']) {
                // Legacy department that is not in the official list: keep as-is until HR moves the employee
                $pos = $old['position']; $isTl = false;
            } else {
                [$pos, $posError] = resolveDeptPosition($deptMap, $dept, $isTl, trim($_POST['position'] ?? ''));
                if (!$posError && $isTl) $tlConflict = otherTeamLeader($db, $dept, $editId);
                // Keep the current position when the department is unchanged and HR did not touch it
                if (!$posError && !$isTl && $dept === $old['department'] && !isset($_POST['position'])) $pos = $old['position'];
            }
        }
        $gender = sanitize($_POST['gender'] ?? '');
        $gender = in_array($gender, ['male', 'female', 'other'], true) ? $gender : null;
        if ($old && $hired === '') $hired = (string)$old['date_hired'];   // blank date would break the UPDATE

        if (!$old) {
            $error = 'Employee not found.';
        } elseif (isHrDepartment($dept) && html_entity_decode($dept) !== html_entity_decode((string)$old['department'])) {
            $error = 'Employees cannot be moved into the HR department here. HR accounts are managed by the Super Admin (User Management).';
        } elseif ($posError) {
            $error = $posError;
        } elseif ($tlConflict) {
            $error = "$dept already has a Team Manager ($tlConflict). Remove that designation first (one Team Manager per department).";
        } else {
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE users SET is_team_leader=? WHERE employee_id=?")->execute([$isTl ? 1 : 0, $editId]);
                $desNote = '';
                if (designationsReady($db)) {
                    $newDesName = $editDes ? $editDes['name'] : null;      // Team Manager / None clear the custom designation
                    if (($old['designation'] ?? null) !== $newDesName) {
                        $db->prepare("UPDATE users SET designation=? WHERE employee_id=?")->execute([$newDesName, $editId]);
                        $desNote = ' | Designation: ' . ($old['designation'] ?: 'none') . ' -> ' . ($newDesName ?: 'none');
                        // A newly chosen custom designation brings its base salary; payroll and all payslips read users.basic_salary.
                        if ($editDes && (float)$editDes['base_salary'] > 0 && (float)($old['basic_salary'] ?? 0) !== (float)$editDes['base_salary']) {
                            $db->prepare("UPDATE users SET basic_salary=? WHERE employee_id=?")->execute([$editDes['base_salary'], $editId]);
                            $desNote .= ' | Basic salary: ' . number_format((float)($old['basic_salary'] ?? 0), 2) . ' -> ' . number_format((float)$editDes['base_salary'], 2);
                        }
                    }
                }
                if (!empty($photoName)) {
                    $stmt = $db->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=?, email=?, department=?, position=?, date_hired=?, phone=?, address=?, civil_status=?, gender=?, sss_no=?, philhealth_no=?, pagibig_no=?, tin_no=?, status=?, profile_photo=? WHERE employee_id=? AND role='employee'");
                    $stmt->execute([$firstName, $middleName ?: null, $lastName, $email, $dept, $pos, $hired, $phone, $address, $civil, $gender, $sss, $ph, $pagibig, $tin, $status, $photoName, $editId]);
                } else {
                    $stmt = $db->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=?, email=?, department=?, position=?, date_hired=?, phone=?, address=?, civil_status=?, gender=?, sss_no=?, philhealth_no=?, pagibig_no=?, tin_no=?, status=? WHERE employee_id=? AND role='employee'");
                    $stmt->execute([$firstName, $middleName ?: null, $lastName, $email, $dept, $pos, $hired, $phone, $address, $civil, $gender, $sss, $ph, $pagibig, $tin, $status, $editId]);
                }
                $fullName = getFullName($firstName, $middleName, $lastName);
                $oldName = getFullName($old['first_name'], $old['middle_name'], $old['last_name']);
                // Re-assign teams (old and new department) so nobody is left pointing at a wrong leader
                syncDepartmentTeam($db, $dept);
                if ($old['department'] !== $dept) syncDepartmentTeam($db, $old['department']);
                $tlNote = ((int)$old['is_team_leader'] !== ($isTl ? 1 : 0)) ? ' | Team Manager: ' . ($isTl ? 'designated' : 'removed') : '';
                if (!auditLog('Update Employee', 'Employee', "Employee: $editId | Name: $oldName -> $fullName | Department: {$old['department']} -> $dept | Position: {$old['position']} -> $pos | Status: {$old['status']} -> $status$tlNote$desNote")) {
                    throw new RuntimeException('Audit log write failed.');
                }
                $db->commit();
                $success = "Employee $fullName updated successfully.";
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Employee was not updated because the audit record could not be written.';
            }
        }
        }
    }

    // === DELETE EMPLOYEE ===
    if ($action === 'delete') {
        $delId = sanitize($_POST['del_id'] ?? '');
        if ($delId) {
            // Preserve employee and related operational records by deactivating the account.
            $s = $db->prepare("SELECT u.first_name, u.middle_name, u.last_name FROM users u JOIN user_roles ur ON ur.employee_id=u.employee_id JOIN roles r ON r.id=ur.role_id WHERE u.employee_id=? AND r.role_name='employee'");
            $s->execute([$delId]);
            $emp = $s->fetch();
            if ($emp) {
                $fullName = getFullName($emp['first_name'], $emp['middle_name'], $emp['last_name']);
                $db->beginTransaction();
                try {
                    $db->prepare("UPDATE users SET status='inactive' WHERE employee_id=? AND role='employee'")->execute([$delId]);
                    if (!auditLog('Deactivate Employee', 'Employee', "Deactivated: $fullName ($delId)")) {
                        throw new RuntimeException('Audit log write failed.');
                    }
                    $db->commit();
                    $success = "Employee $fullName deactivated; related records were preserved.";
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Employee was not deactivated because the audit record could not be written.';
                }
            }
        }
    }

    // === RESET PASSWORD ===
    if ($action === 'reset_pw') {
        $empId  = sanitize($_POST['emp_id'] ?? '');
        $employeeCheck = $db->prepare("SELECT 1 FROM users u JOIN user_roles ur ON ur.employee_id=u.employee_id JOIN roles r ON r.id=ur.role_id WHERE u.employee_id=? AND r.role_name='employee'");
        $employeeCheck->execute([$empId]);
        if (!$employeeCheck->fetchColumn()) {
            $error = 'Employee not found.';
        } else {
        $newPw  = trim((string)($_POST['new_password'] ?? '')) ?: 'password';
        $hash   = password_hash($newPw, PASSWORD_DEFAULT);
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE users SET password=? WHERE employee_id=? AND role='employee'")->execute([$hash, $empId]);
            if (!auditLog('Reset Password', 'Employee', "Password reset for $empId")) {
                throw new RuntimeException('Audit log write failed.');
            }
            $db->commit();
            $success = 'Password reset successfully.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = 'Password was not reset because the audit record could not be written.';
        }
        }
    }
}



// ── Filters ───────────────────────────────────────────────
$search = sanitize($_GET['search'] ?? '');
$dept   = sanitize($_GET['dept']   ?? '');
$status = sanitize($_GET['status'] ?? '');

// Base query: kunin lahat ng employees (role = 'employee') mula sa users table
// JOIN sa user_roles at roles para malaman ang role ng bawat user
// HR Admin and Payroll Manager accounts are listed too, but VIEW-ONLY (no edit / reset / deactivate): every
// POST action above is still limited to role_name = 'employee', so they cannot be changed from here.
// Some accounts (e.g. DP-2026-002 HR, DP-2026-003 Payroll) have no user_roles row and only carry the legacy
// users.role value, so the role is taken from user_roles first and falls back to users.role.
$effRole = "COALESCE(r.role_name, CASE u.role WHEN 'hr_admin' THEN 'hr_admin' WHEN 'admin' THEN 'hr_admin'
                                              WHEN 'payroll_manager' THEN 'payroll_manager' WHEN 'payroll' THEN 'payroll_manager' END)";
$where  = "WHERE $effRole IN ('employee','payroll_manager','hr_admin')";
$params = [];
// Search / department / status are applied live in the browser (see script at the bottom),
// so all employees are loaded here and $search / $dept / $status only set the starting state.

// I-assemble ang full name sa SQL para sa sorting
$desCol = $desReady ? 'u.designation' : 'NULL';
$stmt = $db->prepare("
        SELECT u.employee_id, u.first_name, u.middle_name, u.last_name, u.email,
            u.department, u.position, u.date_hired, u.status, u.profile_photo,
            u.phone, u.address, u.civil_status, u.gender, u.sss_no,
            u.philhealth_no, u.pagibig_no, u.tin_no, u.is_team_leader, u.team_leader_id,
           $effRole AS account_role, $desCol AS designation,
           CONCAT(u.first_name,
                  IF(u.middle_name IS NOT NULL AND u.middle_name != '', CONCAT(' ', SUBSTRING(u.middle_name,1,1), '.'), ''),
                  ' ', u.last_name) AS full_name
    FROM users u
    LEFT JOIN user_roles ur ON ur.employee_id = u.employee_id
    LEFT JOIN roles r ON r.id = ur.role_id
    $where
    ORDER BY u.last_name ASC, u.first_name ASC
");
$stmt->execute($params);
$employees = $stmt->fetchAll();

// Filter list = official departments + any legacy department still used by existing employees
$departments = array_keys($deptMap);
foreach ($db->query("SELECT DISTINCT u.department FROM users u LEFT JOIN user_roles ur ON ur.employee_id = u.employee_id
    LEFT JOIN roles r ON r.id = ur.role_id WHERE $effRole IN ('employee','payroll_manager','hr_admin') AND u.department <> '' ORDER BY u.department")->fetchAll(PDO::FETCH_COLUMN) as $d) {
    if ($hrDeptName !== '' && isHrDepartment((string)$d)) continue;   // HR variants are merged into the HR department
    if (!in_array($d, $departments, true)) $departments[] = $d;
}
$departments = array_map(static fn($d) => ($hrDeptName !== '' && isHrDepartment((string)$d)) ? $hrDeptName : $d, $departments);
$departments = array_values(array_unique(array_map('html_entity_decode', $departments)));
// Team Manager name per employee (for the table)
$leaderNames = [];
foreach ($db->query("SELECT employee_id, CONCAT(first_name,' ',last_name) n FROM users WHERE is_team_leader=1")->fetchAll() as $l) $leaderNames[$l['employee_id']] = $l['n'];

// Live headcount per department and per position (active employees only; no fixed cap)
$deptStats = [];
foreach ($db->query("SELECT u.department, u.position, $effRole AS account_role, COUNT(*) c FROM users u
                     LEFT JOIN user_roles ur ON ur.employee_id = u.employee_id LEFT JOIN roles r ON r.id = ur.role_id
                     WHERE $effRole IN ('employee','payroll_manager','hr_admin') AND u.status = 'active' AND u.department <> ''
                     GROUP BY u.department, u.position, account_role ORDER BY u.department, c DESC, u.position")->fetchAll() as $r) {
    $dk = listDept((string)$r['department'], (string)$r['account_role'], $hrDeptName);
    $deptStats[$dk]['total'] = ($deptStats[$dk]['total'] ?? 0) + (int)$r['c'];
    $t = $r['position'] ?: 'Unassigned';
    $found = false;
    if (isset($deptStats[$dk]['positions'])) {
        foreach (array_keys($deptStats[$dk]['positions']) as $k) {
            if ($deptStats[$dk]['positions'][$k]['title'] === $t) { $deptStats[$dk]['positions'][$k]['count'] += (int)$r['c']; $found = true; break; }
        }
    }
    if (!$found) $deptStats[$dk]['positions'][] = ['title' => $t, 'count' => (int)$r['c']];
}
ksort($deptStats);
$totalActive = array_sum(array_column($deptStats, 'total'));

include '../../includes/header.php';
?>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Departments, headcount and positions (live from current employees) -->
<div class="card">
    <div class="card-title">Departments and Headcount
        <span class="muted-note"><?= (int)$totalActive ?> active employee(s), including HR and Payroll Manager accounts, across <?= count($deptStats) ?> department(s)</span>
    </div>
    <div class="dept-grid">
        <?php foreach ($deptStats as $dn => $ds): ?>
        <div class="dept-card">
            <div class="dept-head">
                <span class="dept-name"><?= htmlspecialchars(html_entity_decode((string)$dn), ENT_QUOTES) ?></span>
                <span class="dept-count"><?= (int)$ds['total'] ?></span>
            </div>
            <ul class="dept-positions">
                <?php foreach ($ds['positions'] as $pp): ?>
                <li><span><?= htmlspecialchars($pp['title']) ?></span><b><?= (int)$pp['count'] ?></b></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
        <?php if (!$deptStats): ?><div class="muted-note">No active employees yet.</div><?php endif; ?>
    </div>
</div>

<!-- Search + Filter dropdown (live, no page reload) -->
<div class="card">
    <div class="emp-toolbar">
        <div class="emp-toolbar-left">
            <div class="emp-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" id="empSearch" class="search-input" autocomplete="off"
                       placeholder="Search anything: name, ID, email, department, position..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="emp-filter" id="empFilter">
                <button type="button" class="btn-secondary emp-filter-btn" id="empFilterBtn" aria-haspopup="true" aria-expanded="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                    Filter <span class="emp-filter-count" id="empFilterCount" hidden>0</span>
                </button>
                <div class="emp-filter-menu" id="empFilterMenu" role="menu" hidden>
                    <label class="form-label" for="fDept">Department</label>
                    <select id="fDept" class="form-select">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dn): ?>
                        <option value="<?= htmlspecialchars($dn) ?>" <?= html_entity_decode($dept) === $dn ? 'selected' : '' ?>><?= htmlspecialchars($dn) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="form-label" for="fStatus" style="margin-top:12px;">Status</label>
                    <select id="fStatus" class="form-select">
                        <option value="">All Status</option>
                        <option value="active"   <?= $status==='active'  ?'selected':'' ?>>Active</option>
                        <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
                    </select>
                    <div class="emp-filter-foot">
                        <button type="button" class="btn-secondary btn-sm" id="empClear">Clear all</button>
                    </div>
                </div>
            </div>
        </div>
        <button class="btn-primary" onclick="openModal('addModal')">+ Add Employee</button>
    </div>
</div>

<style>
.emp-toolbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
.emp-toolbar-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex:1 1 420px;min-width:0;}
.emp-search{position:relative;flex:1 1 280px;max-width:460px;}
.emp-search svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:#9CA3AF;pointer-events:none;}
.emp-search .search-input{width:100%;padding-left:34px!important;}
.emp-filter{position:relative;}
.emp-filter-btn{gap:7px!important;}
.emp-filter-btn svg{width:14px;height:14px;}
.emp-filter-btn::after{content:"";width:5px;height:5px;border:solid currentColor;border-width:0 2px 2px 0;transform:rotate(45deg);margin:-3px 0 0 2px;transition:transform .15s;}
.emp-filter-btn[aria-expanded="true"]::after{transform:rotate(-135deg);margin:3px 0 0 2px;}
.emp-filter-count{background:var(--red);color:#fff;border-radius:20px;padding:0 7px;font-size:10px;font-weight:700;line-height:17px;}
.emp-filter-menu{position:absolute;top:calc(100% + 6px);left:0;z-index:60;width:260px;background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,.12);padding:14px;}
.emp-filter-menu[hidden]{display:none;}
.emp-filter-menu .form-select{width:100%;}
.emp-filter-foot{display:flex;justify-content:flex-end;margin-top:14px;padding-top:12px;border-top:1px solid var(--border);}
.emp-row[hidden]{display:none;}
</style>

<!-- Employee Table -->
<div class="card">
    <div class="card-title"><span>Employees (<span id="empCount"><?= count($employees) ?></span>)</span></div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Photo</th>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Department</th>
                    <th>Position</th>
                    <th>Designation</th>
                    <th>Team Manager</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $emp):
                    // I-assemble ang full name para sa display
                    $fullName = getFullName($emp['first_name'], $emp['middle_name'] ?? '', $emp['last_name']);
                    $viewOnlyRoles = ['payroll_manager' => 'Payroll Manager', 'hr_admin' => 'HR Admin'];
                    $rowDept = listDept((string)$emp['department'], (string)($emp['account_role'] ?? ''), $hrDeptName);
                    $isPayrollMgr = isset($viewOnlyRoles[$emp['account_role'] ?? '']);   // true for any view-only account
                    $roleLabel    = $viewOnlyRoles[$emp['account_role'] ?? ''] ?? '';
                    $leaderTxt = (int)$emp['is_team_leader'] === 1 ? 'team manager' : ($leaderNames[$emp['team_leader_id'] ?? ''] ?? '');
                    $searchTxt = mb_strtolower(implode(' ', [
                        $emp['employee_id'], $emp['first_name'], $emp['middle_name'] ?? '', $emp['last_name'], $fullName,
                        (string)$emp['email'], $rowDept, (string)$emp['position'], $leaderTxt, (string)$emp['status'],
                        strtolower($roleLabel), (string)($emp['designation'] ?? ''),
                        $emp['phone'] ?? '', $emp['gender'] ?? '', $emp['civil_status'] ?? '', $emp['date_hired'] ?? '',
                    ]));
                ?>
                <tr class="emp-row" data-search="<?= htmlspecialchars($searchTxt) ?>"
                    data-dept="<?= htmlspecialchars($rowDept) ?>"
                    data-status="<?= htmlspecialchars((string)$emp['status']) ?>">
                    <td>
                        <?php if (!empty($emp['profile_photo']) && file_exists(PROFILE_PHOTO_PATH . $emp['profile_photo'])): ?>
                            <img src="../../uploads/profile_photos/<?= htmlspecialchars($emp['profile_photo']) ?>"
                                 style="width:36px; height:36px; border-radius:50%; object-fit:cover;">
                        <?php else: ?>
                            <div style="width:36px; height:36px; border-radius:50%; background:#CD1E27; color:#fff;
                                        display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:600;">
                                <?= htmlspecialchars(getInitials($fullName)) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-family:monospace; font-size:12px;"><?= htmlspecialchars((string)$emp['employee_id']) ?></td>
                    <td>
                        <!-- Pinakita ang full name: First MI. Last format -->
                        <div style="font-weight:600;"><?= htmlspecialchars($fullName) ?>
                            <?php if ($isPayrollMgr): ?><span class="badge badge-purple" style="margin-left:6px;"><?= htmlspecialchars($roleLabel) ?></span><?php endif; ?>
                        </div>
                        <div style="font-size:11px; color:#9CA3AF;"><?= htmlspecialchars((string)$emp['email']) ?></div>
                    </td>
                    <td><?= htmlspecialchars($rowDept) ?></td>
                    <td><?= htmlspecialchars((string)$emp['position']) ?></td>
                    <td style="font-size:12px;">
                        <?php if ($isPayrollMgr): ?><span style="color:#9CA3AF;">&mdash;</span>
                        <?php elseif ((int)$emp['is_team_leader'] === 1): ?><span class="badge badge-purple">Team Manager</span>
                        <?php elseif (!empty($emp['designation'])): ?><span class="badge badge-blue"><?= htmlspecialchars($emp['designation']) ?></span>
                        <?php else: ?><span style="color:#6B7280;">Employee</span><?php endif; ?>
                    </td>
                    <td style="font-size:12px;">
                        <?php if ((int)$emp['is_team_leader'] === 1): ?>
                            <span class="badge badge-purple">Team Manager</span>
                        <?php elseif (!empty($emp['team_leader_id']) && isset($leaderNames[$emp['team_leader_id']])): ?>
                            <?= htmlspecialchars($leaderNames[$emp['team_leader_id']]) ?>
                        <?php else: ?><span style="color:#9CA3AF;">—</span><?php endif; ?>
                    </td>
                    <td><span class="badge <?= statusBadge((string)$emp['status']) ?>"><?= htmlspecialchars(ucfirst((string)$emp['status'])) ?></span></td>
                    <td>
                        <?php if ($isPayrollMgr): ?>
                        <span class="muted-note" title="HR Admin and Payroll Manager accounts are managed by the Super Admin">View only</span>
                        <?php else: ?>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <!-- Ipinapasa ang emp data sa JS para ma-populate ang edit modal -->
                            <button class="btn-secondary btn-sm"
                                    onclick="openEditModal(<?= htmlspecialchars(json_encode($emp, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES) ?>)">Edit</button>
                            <button class="btn-secondary btn-sm"
                                    onclick="resetPw(<?= htmlspecialchars(json_encode((string)$emp['employee_id']) , ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($fullName, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">Reset PW</button>
                            <button class="btn-secondary btn-sm" style="color:#CD1E27;"
                                    onclick="deleteEmployee(<?= htmlspecialchars(json_encode((string)$emp['employee_id']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($fullName, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">Deactivate</button>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="empNoMatch" <?= $employees ? 'hidden' : '' ?>><td colspan="9" style="text-align:center; color:#9CA3AF; padding:24px;">No employees found.</td></tr>
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
                        <select name="department" id="a_dept" class="form-select" required onchange="syncPosition('a')">
                            <option value="">-- Select department --</option>
                            <?php foreach ($assignableDepts as $dn): ?>
                            <option value="<?= htmlspecialchars($dn) ?>"><?= htmlspecialchars($dn) ?></option>
                            <?php endforeach; ?>
                            <option value="__new__">+ Add new department...</option>
                        </select>
                        <input type="text" name="new_department" id="a_newdept" class="form-input" maxlength="100"
                               placeholder="New department name" style="display:none;margin-top:6px;">
                        <div style="font-size:11px;color:#6B7280;margin-top:4px;">HR and Payroll Manager accounts can only be created by the Super Admin.</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">Designation</label>
                        <select name="is_team_leader" id="a_tl" class="form-select" onchange="syncPosition('a')">
                            <option value="no">None — regular employee</option>
                            <option value="yes">Team Manager of this department</option>
                            <?php foreach ($designations as $ds): ?>
                            <option value="d:<?= (int)$ds['id'] ?>" data-salary="<?= htmlspecialchars((string)$ds['base_salary']) ?>"><?= htmlspecialchars($ds['name']) ?></option>
                            <?php endforeach; ?>
                            <option value="__new__">+ Add new designation...</option>
                        </select>
                        <?php if (!$desReady): ?><div style="font-size:11px;color:#B45309;margin-top:4px;">Custom designations need <code>config/designation_migration.sql</code> to be imported once.</div><?php endif; ?>
                        <input type="text" name="new_designation" id="a_newdesig" class="form-input" maxlength="100"
                               placeholder="New designation name (e.g. Senior Officer)" style="display:none;margin-top:6px;">
                        <input type="number" name="new_designation_salary" id="a_desig_salary" class="form-input" min="0.01" step="0.01"
                               placeholder="Base salary of this designation (monthly)" style="display:none;margin-top:6px;">
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="form-group">
                        <label class="form-label">Position <span style="color:#9CA3AF;font-size:11px;">(set automatically from the department)</span></label>
                        <select name="position" id="a_pos" class="form-select"></select>
                        <input type="text" name="new_position" id="a_newpos" class="form-input" maxlength="100"
                               placeholder="New position name" style="display:none;margin-top:6px;">
                        <input type="number" name="new_position_salary" id="a_pos_salary" class="form-input" min="0.01" step="0.01"
                               placeholder="Base salary of this position (monthly)" style="display:none;margin-top:6px;">
                        <div id="a_pos_note" style="font-size:11px;color:#6B7280;margin-top:4px;"></div>
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
                        <label class="form-label">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
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
                <div class="col-md-6"><div class="form-group"><label class="form-label">Department</label>
                    <select name="department" id="e_dept" class="form-select" onchange="syncPosition('e')">
                        <?php foreach ($assignableDepts as $dn): ?><option value="<?= htmlspecialchars($dn) ?>"><?= htmlspecialchars($dn) ?></option><?php endforeach; ?>
                    </select></div></div>
                <div class="col-md-6"><div class="form-group"><label class="form-label">Designation</label>
                    <select name="is_team_leader" id="e_tl" class="form-select" onchange="syncPosition('e')"><option value="no">None — regular employee</option><option value="yes">Team Manager</option>
                    <?php foreach ($designations as $ds): ?><option value="d:<?= (int)$ds['id'] ?>" data-name="<?= htmlspecialchars($ds['name']) ?>"><?= htmlspecialchars($ds['name']) ?> (&#8369;<?= number_format((float)$ds['base_salary'], 2) ?>)</option><?php endforeach; ?>
                    </select>
                    <div style="font-size:11px;color:#6B7280;margin-top:4px;">Choosing a custom designation sets the employee's base salary to that designation's salary.</div></div></div>
                <div class="col-md-12"><div class="form-group"><label class="form-label">Position <span style="color:#9CA3AF;font-size:11px;">(set automatically from the department)</span></label>
                    <select name="position" id="e_pos" class="form-select"></select>
                    <div id="e_pos_note" style="font-size:11px;color:#6B7280;margin-top:4px;"></div></div></div>
                <div class="col-md-6"><div class="form-group"><label class="form-label">Date Hired</label><input type="date" name="date_hired" id="e_hired" class="form-input"></div></div>
                <div class="col-md-6"><div class="form-group"><label class="form-label">Status</label><select name="status" id="e_status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div>
                <div class="col-md-6"><div class="form-group"><label class="form-label">Civil Status</label><select name="civil_status" id="e_civil" class="form-select"><option value="single">Single</option><option value="married">Married</option><option value="widowed">Widowed</option><option value="separated">Separated</option></select></div></div>
                                <div class="col-md-6">
                    <div class="form-group">
                        <label class="form-label">Gender</label>
                        <select name="gender" id="e_gender" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
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
// Department -> positions (from the departments / department_positions tables)
var DEPT_MAP = <?= json_encode($deptMap) ?>;

// Fills the Position dropdown from the chosen department. A Team Manager is locked to the
// department's TL position; other employees pick from the department's staff positions.
function syncPosition(prefix, current) {
    if (prefix === 'a') { syncAdd(); return; }     // Add form has its own logic (new department / position / designation)
    var dept = document.getElementById(prefix + '_dept').value;
    var isTl = document.getElementById(prefix + '_tl').value === 'yes';
    var sel  = document.getElementById(prefix + '_pos');
    var note = document.getElementById(prefix + '_pos_note');
    sel.innerHTML = '';
    var info = DEPT_MAP[dept];
    if (!info) {
        if (dept && current) { sel.add(new Option(current, current)); sel.disabled = true; note.textContent = 'Legacy department: position is kept until the employee is moved to an official department.'; }
        else { sel.disabled = false; note.textContent = 'Choose a department first.'; }
        return;
    }
    if (isTl) {
        sel.add(new Option(info.tl, info.tl)); sel.disabled = true;
        note.textContent = 'Team Manager position for ' + dept + ' (automatic) — base salary ₱' + Number((info.salary||{})[info.tl]||0).toLocaleString();
    } else {
        info.staff.forEach(function (p) { sel.add(new Option(p, p)); });
        sel.disabled = false;
        if (current && info.staff.indexOf(current) >= 0) sel.value = current;
        var upd = function () { note.textContent = 'Base salary for this position: ₱' + Number((info.salary||{})[sel.value]||0).toLocaleString() + ' / month'; };
        sel.onchange = upd; upd();
    }
}

// ── Add Employee form: pick existing OR type a new department / position / designation ──
// Designation = None | Team Manager | a custom designation (carries its own base salary).
// The base-salary box appears only while a NEW designation (or a new position with no designation salary) is added.
function syncAdd(fromPos) {   // fromPos = true when the Position dropdown itself changed
    var $ = function (id) { return document.getElementById(id); };
    var show = function (id, on, req) { var el = $(id); el.style.display = on ? '' : 'none'; if (req !== undefined) { el.required = !!(on && req); } };
    var deptSel = $('a_dept'), tl = $('a_tl'), pos = $('a_pos'), note = $('a_pos_note');
    var isNewDept = deptSel.value === '__new__';
    var info = (!isNewDept && deptSel.value) ? DEPT_MAP[deptSel.value] : null;
    var money = function (v) { return '\u20B1' + Number(v || 0).toLocaleString(); };

    show('a_newdept', isNewDept, true);

    // "Team Manager" needs an existing department that already has a Team Manager position
    var yes = tl.querySelector('option[value="yes"]');
    yes.disabled = !deptSel.value;
    if (yes.disabled && tl.value === 'yes') tl.value = 'no';
    var mode = tl.value, isCustom = mode.indexOf('d:') === 0, isNewDes = mode === '__new__';
    show('a_newdesig', isNewDes, true);
    show('a_desig_salary', isNewDes, true);              // salary box under a NEW designation
    var desSalary = isCustom ? Number(tl.options[tl.selectedIndex].getAttribute('data-salary') || 0) : 0;

    var keep = pos.value, isNewPos = false;
    $('a_desig_salary').placeholder = 'Base salary of this designation (monthly)';
    pos.innerHTML = ''; pos.disabled = false;
    if (!deptSel.value) {
        show('a_newpos', false, false);
        show('a_pos_salary', false, false);
        note.textContent = 'Choose a department first.';
    } else {
        // Team Manager: its own position (if the department has one); everyone else: the department's staff positions.
        var list = (mode === 'yes') ? ((info && info.tl) ? [info.tl] : []) : ((info && info.staff) || []);
        list.forEach(function (p) { pos.add(new Option(p, p)); });
        pos.add(new Option('+ Add new position...', '__new__'));
        // Keep what the user just picked in the Position dropdown (incl. "+ Add new position"); a department or
        // designation change falls back to the first position of that list.
        pos.value = ((fromPos === true && keep === '__new__') || list.indexOf(keep) >= 0) ? keep : (list.length ? list[0] : '__new__');
        isNewPos = pos.value === '__new__';
        show('a_newpos', isNewPos, true);
        // Both new -> only ONE salary box (the designation's); it is used for the designation and the new position.
        show('a_pos_salary', isNewPos && !isNewDes, true);   // salary box under a NEW position
        if (isNewDes && isNewPos) $('a_desig_salary').placeholder = 'Enter the base salary (monthly)';
        if (isCustom)      note.textContent = 'The employee is paid the designation salary: ' + money(desSalary) + ' / month'
                                              + (isNewPos ? ' (the new position keeps its own base salary below)' : '');
        else if (isNewDes) note.textContent = isNewPos ? 'New designation and new position: enter ONE base salary above \u2014 it applies to both.'
                                                       : 'The employee is paid the new designation salary entered above.';
        else if (isNewPos) note.textContent = 'Enter the new position and its base salary.';
        else if (mode === 'yes') note.textContent = 'Team Manager position for ' + deptSel.value + ' \u2014 base salary ' + money((info.salary || {})[pos.value]) + ' / month';
        else               note.textContent = 'Base salary for this position: ' + money((info.salary || {})[pos.value]) + ' / month';
    }
}
document.addEventListener('DOMContentLoaded', function () {
    var p = document.getElementById('a_pos');
    if (p) { p.addEventListener('change', function () { syncAdd(true); }); syncAdd(); }
});

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
    // Current department that is not selectable (legacy department, or the HR department which only
    // the Super Admin can assign): keep it as a "current" option so the employee can still be edited
    var dsel = document.getElementById('e_dept');
    Array.from(dsel.querySelectorAll('[data-legacy]')).forEach(function (o) { o.remove(); });
    var hasDept = Array.from(dsel.options).some(function (o) { return o.value === emp.department; });
    if (emp.department && !hasDept) {
        var lo = new Option(emp.department + (DEPT_MAP[emp.department] ? ' (current)' : ' (current — legacy)'), emp.department);
        lo.setAttribute('data-legacy', '1'); dsel.add(lo, dsel.options[0]);
    }
    dsel.value = emp.department || '';
    var etl = document.getElementById('e_tl'), etlVal = (parseInt(emp.is_team_leader) === 1) ? 'yes' : 'no';
    if (etlVal === 'no' && emp.designation) {
        Array.from(etl.options).forEach(function (o) { if (o.getAttribute('data-name') === emp.designation) etlVal = o.value; });
    }
    etl.value = etlVal;
    syncPosition('e', emp.position);
    document.getElementById('e_hired').value         = emp.date_hired  || '';
    document.getElementById('e_phone').value         = emp.phone       || '';
    document.getElementById('e_address').value       = emp.address     || '';
    document.getElementById('e_civil').value         = emp.civil_status|| 'single';
    document.getElementById('e_gender').value = emp.gender || '';
    document.getElementById('e_sss').value           = emp.sss_no      || '';
    document.getElementById('e_ph').value            = emp.philhealth_no|| '';
    document.getElementById('e_pagibig').value       = emp.pagibig_no  || '';
    document.getElementById('e_tin').value           = emp.tin_no      || '';
    document.getElementById('e_status').value        = emp.status      || 'active';
    openModal('editModal');
}

syncPosition('a');

// ── deactivateEmployee() ──────────────────────────────────
function deleteEmployee(id, name) {
    if (confirm('Deactivate employee ' + name + '? Existing records will be retained.')) {
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
// ── Live search + filter dropdown ─────────────────────────
(function () {
    var input = document.getElementById('empSearch'),
        fDept = document.getElementById('fDept'),
        fStat = document.getElementById('fStatus'),
        btn   = document.getElementById('empFilterBtn'),
        menu  = document.getElementById('empFilterMenu'),
        badge = document.getElementById('empFilterCount'),
        count = document.getElementById('empCount'),
        none  = document.getElementById('empNoMatch'),
        rows  = Array.prototype.slice.call(document.querySelectorAll('.emp-row'));

    function apply() {
        // every typed word must appear somewhere in the row (name, ID, email, department, position, ...)
        var words = input.value.toLowerCase().split(/\s+/).filter(Boolean),
            dept = fDept.value, stat = fStat.value, shown = 0;
        rows.forEach(function (tr) {
            var hay = tr.getAttribute('data-search'),
                ok = (!dept || tr.getAttribute('data-dept') === dept) &&
                     (!stat || tr.getAttribute('data-status') === stat) &&
                     words.every(function (w) { return hay.indexOf(w) !== -1; });
            tr.hidden = !ok;
            if (ok) shown++;
        });
        count.textContent = shown;
        none.hidden = shown > 0;
        var active = (dept ? 1 : 0) + (stat ? 1 : 0);
        badge.textContent = active; badge.hidden = !active;
        // keep the filters across a page refresh
        try {
            var q = new URLSearchParams();
            if (input.value.trim()) q.set('search', input.value.trim());
            if (dept) q.set('dept', dept);
            if (stat) q.set('status', stat);
            history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
        } catch (e) {}
    }
    function setMenu(open) { menu.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }

    input.addEventListener('input', apply);
    fDept.addEventListener('change', apply);
    fStat.addEventListener('change', apply);
    btn.addEventListener('click', function (e) { e.stopPropagation(); setMenu(menu.hidden); });
    menu.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', function () { setMenu(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setMenu(false); });
    document.getElementById('empClear').addEventListener('click', function () { fDept.value = ''; fStat.value = ''; apply(); });
    apply();
})();
</script>

