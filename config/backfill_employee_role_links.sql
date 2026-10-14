-- Restore employee role mappings for accounts created before user_roles was maintained.
-- Safe to run more than once; existing employee-role links are left unchanged.
INSERT IGNORE INTO user_roles (employee_id, role_id)
SELECT u.employee_id, r.id
FROM users u
JOIN roles r ON r.role_name = 'employee'
WHERE u.role = 'employee'
  AND NOT EXISTS (
      SELECT 1 FROM user_roles ur WHERE ur.employee_id = u.employee_id
  );
