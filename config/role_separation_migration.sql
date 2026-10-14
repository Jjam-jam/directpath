-- Run against directpath_db after creating a database backup.
-- Preserves existing role IDs and user_roles assignments; no user or transaction rows are deleted.

ALTER TABLE roles
    MODIFY role_name ENUM('employee','admin','hr_admin','payroll_manager','superadmin','ceo') NOT NULL;

UPDATE roles
SET role_name = 'hr_admin',
    description = 'HR Admin - manages employee information, schedules, attendance, leave, requests, and HR operations'
WHERE role_name = 'admin';

UPDATE roles
SET description = 'HR Admin - manages employee information, schedules, attendance, leave, requests, and HR operations'
WHERE role_name = 'hr_admin';

-- The inspected live id column is not AUTO_INCREMENT; allocate new IDs explicitly.
SELECT COALESCE(MAX(id), 0) + 1 INTO @next_role_id FROM roles;
INSERT IGNORE INTO roles (id, role_name, description)
VALUES (@next_role_id, 'payroll_manager', 'Payroll Manager - manages payroll, salary processing, loans, deductions, payslips, and payroll reports');

-- Repair the unassigned id-0 row produced by an earlier migration attempt.
UPDATE roles
SET id = @next_role_id
WHERE id = 0
    AND role_name = 'payroll_manager'
    AND NOT EXISTS (SELECT 1 FROM user_roles WHERE role_id = 0);

SELECT COALESCE(MAX(id), 0) + 1 INTO @next_role_id FROM roles;
INSERT IGNORE INTO roles (id, role_name, description)
VALUES (@next_role_id, 'ceo', 'CEO - view-only executive monitoring and reports');

UPDATE roles
SET description = 'Employee - can access and submit their own employee self-service information and requests'
WHERE role_name = 'employee';

UPDATE roles
SET description = 'Payroll Manager - manages payroll, salary processing, loans, deductions, payslips, and payroll reports'
WHERE role_name = 'payroll_manager';

UPDATE roles
SET description = 'System Administrator - manages user accounts, roles, system settings, tickets, and audit logs'
WHERE role_name = 'superadmin';

UPDATE roles
SET description = 'CEO - view-only executive monitoring and reports'
WHERE role_name = 'ceo';

ALTER TABLE roles
    MODIFY role_name ENUM('employee','hr_admin','payroll_manager','superadmin','ceo') NOT NULL;

-- Keep the legacy users.role field aligned with the authoritative assignment table.
UPDATE users u
JOIN user_roles ur ON ur.employee_id = u.employee_id
JOIN roles r ON r.id = ur.role_id
SET u.role = r.role_name;

-- Attendance currently stores 'late' in PHP but the live enum omits it.
ALTER TABLE attendance
    MODIFY status ENUM('present','late','absent','half-day','on-leave') NULL DEFAULT 'present';