USE directpath_db;

START TRANSACTION;
SET FOREIGN_KEY_CHECKS = 0;

UPDATE attendance SET employee_id = 'CEO-001' WHERE employee_id = 'AD-0001';
UPDATE attendance SET employee_id = 'AD-001' WHERE employee_id = 'AD-0002';

UPDATE bug_tickets SET reported_by_id = 'CEO-001' WHERE reported_by_id = 'AD-0001';
UPDATE bug_tickets SET reported_by_id = 'AD-001' WHERE reported_by_id = 'AD-0002';

UPDATE certificates SET employee_id = 'CEO-001' WHERE employee_id = 'AD-0001';
UPDATE certificates SET employee_id = 'AD-001' WHERE employee_id = 'AD-0002';

UPDATE document_requests SET employee_id = 'CEO-001' WHERE employee_id = 'AD-0001';
UPDATE document_requests SET employee_id = 'AD-001' WHERE employee_id = 'AD-0002';

UPDATE employee_requests SET employee_id = 'CEO-001' WHERE employee_id = 'AD-0001';
UPDATE employee_requests SET employee_id = 'AD-001' WHERE employee_id = 'AD-0002';

UPDATE face_encodings SET employee_id = 'CEO-001' WHERE employee_id = 'AD-0001';
UPDATE face_encodings SET employee_id = 'AD-001' WHERE employee_id = 'AD-0002';

UPDATE leave_balances SET employee_id = 'CEO-001' WHERE employee_id = 'AD-0001';
UPDATE leave_balances SET employee_id = 'AD-001' WHERE employee_id = 'AD-0002';

UPDATE leave_requests SET employee_id = 'CEO-001' WHERE employee_id = 'AD-0001';
UPDATE leave_requests SET employee_id = 'AD-001' WHERE employee_id = 'AD-0002';

UPDATE loan_applications SET employee_id = 'CEO-001' WHERE employee_id = 'AD-0001';
UPDATE loan_applications SET employee_id = 'AD-001' WHERE employee_id = 'AD-0002';

UPDATE loan_deductions SET employee_id = 'CEO-001' WHERE employee_id = 'AD-0001';
UPDATE loan_deductions SET employee_id = 'AD-001' WHERE employee_id = 'AD-0002';

UPDATE payroll SET employee_id = 'CEO-001' WHERE employee_id = 'AD-0001';
UPDATE payroll SET employee_id = 'AD-001' WHERE employee_id = 'AD-0002';

UPDATE user_roles SET employee_id = 'CEO-001' WHERE employee_id = 'AD-0001';
UPDATE user_roles SET employee_id = 'AD-001' WHERE employee_id = 'AD-0002';
UPDATE user_roles SET assigned_by = 'CEO-001' WHERE assigned_by = 'AD-0001';
UPDATE user_roles SET assigned_by = 'AD-001' WHERE assigned_by = 'AD-0002';

UPDATE wfh_verification_log SET employee_id = 'CEO-001' WHERE employee_id = 'AD-0001';
UPDATE wfh_verification_log SET employee_id = 'AD-001' WHERE employee_id = 'AD-0002';

UPDATE audit_logs SET user_id = 'CEO-001' WHERE user_id = 'AD-0001';
UPDATE audit_logs SET user_id = 'AD-001' WHERE user_id = 'AD-0002';

UPDATE users SET employee_id = 'CEO-001'
WHERE employee_id = 'AD-0001' AND role = 'ceo' AND email = 'jon.ceo@gmail.com';

UPDATE users SET employee_id = 'AD-001'
WHERE employee_id = 'AD-0002' AND role = 'payroll_manager' AND email = 'jammurillo.pm@gmail.com';

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
