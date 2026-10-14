-- ============================================================
-- Directpath EMPS — Migration: Departments / Team Leader / Leave endorsement /
--                              Payroll CEO approval / Performance Evaluation
-- Run ONCE (it is safe to re-run) on directpath_db AFTER the earlier migrations
-- (role_separation, leave_workflow, migration_ceo_employee).
-- Nothing is dropped, no rows are deleted, existing employees are untouched.
-- Works on XAMPP MariaDB/MySQL (no ADD COLUMN IF NOT EXISTS needed).
-- ============================================================

SET @db = DATABASE();
SET @cs = (SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='employee_id');
SET @co = (SELECT COLLATION_NAME FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='employee_id');
SET @eid = CONCAT('VARCHAR(20) CHARACTER SET ', @cs, ' COLLATE ', @co);

-- ------------------------------------------------------------
-- 1. DEPARTMENTS + POSITIONS (single source for the HR dropdowns)
-- ------------------------------------------------------------
-- Same character set / collation as the users table so department names can be compared and joined safely
SET @tbl = CONCAT(' ENGINE=InnoDB DEFAULT CHARSET=', @cs, ' COLLATE=', @co);
SET @s = CONCAT('CREATE TABLE IF NOT EXISTS departments (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(100) NOT NULL UNIQUE,
    tl_position      VARCHAR(100) NOT NULL,
    target_headcount INT NOT NULL DEFAULT 0,
    sort_order       INT NOT NULL DEFAULT 0
)', @tbl);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = CONCAT('CREATE TABLE IF NOT EXISTS department_positions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    department_id  INT NOT NULL,
    position_title VARCHAR(100) NOT NULL,
    is_tl_position TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_dept_pos (department_id, position_title),
    CONSTRAINT fk_deptpos_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
)', @tbl);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Extra position columns: base salary, headcount, active flag (idempotent)
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='department_positions' AND COLUMN_NAME='base_salary')=0,
  'ALTER TABLE department_positions ADD COLUMN base_salary DECIMAL(12,2) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='department_positions' AND COLUMN_NAME='headcount')=0,
  'ALTER TABLE department_positions ADD COLUMN headcount INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='department_positions' AND COLUMN_NAME='is_active')=0,
  'ALTER TABLE department_positions ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

INSERT IGNORE INTO departments (name, tl_position, target_headcount, sort_order) VALUES
 ('Programs & Settlement',      'Supervisor, Program and Settlement', 12, 1),
 ('Human Resources',    'Director, Employee Relations',         8, 2),
 ('Finance & Accounting',       'Manager, Accounting Department',      10, 3),
 ('Legal',                      'Legal Counsel',                        6, 4),
 ('Immigration Consulting',     'Designated RCIC',                     19, 5),
 ('Client & Case Services',     'Supervisor, Case Specialist',         19, 6),
 ('Marketing & Communication',  'Marketing Coordinator',               10, 7),
 ('Sales',                      'Sales Team Leader',                   12, 8),
 ('Executive Support',          'Executive Assistant to CEO',           3, 9),
 ('Engineering',                'Designated Engineering Lead',          3, 10);

-- Seed only once: marker = Settlement Service Representative exists. Afterwards HR edits via Positions page and re-runs never overwrite.
SET @seeded = (SELECT COUNT(*) FROM department_positions WHERE position_title='Settlement Service Representative');
SET @s = IF(@seeded=0, 'UPDATE department_positions SET is_active=0, is_tl_position=0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Official position table: dept, position, is_TM, headcount, base salary
DROP TEMPORARY TABLE IF EXISTS tmp_pos;
CREATE TEMPORARY TABLE tmp_pos (dn VARCHAR(100), title VARCHAR(100), tm TINYINT, hc INT, sal DECIMAL(12,2));
INSERT INTO tmp_pos VALUES
 ('Programs & Settlement','Supervisor, Program and Settlement',1,1,45000),
 ('Programs & Settlement','Settlement Service Representative',0,11,22000),
 ('Human Resources','Director, Employee Relations',1,1,70000),
 ('Human Resources','HR / Employee Relations Staff',0,7,25000),
 ('Finance & Accounting','Manager, Accounting Department',1,1,55000),
 ('Finance & Accounting','Accounting Staff',0,7,25000),
 ('Finance & Accounting','Controller',0,1,75000),
 ('Finance & Accounting','Client Certified Public Accountant',0,1,40000),
 ('Legal','Legal Counsel',1,1,65000),
 ('Legal','Legal Specialist',0,5,30000),
 ('Immigration Consulting','Designated RCIC',1,1,60000),
 ('Immigration Consulting','Regulated Canadian Immigration Consultant',0,5,50000),
 ('Immigration Consulting','Immigration Case Staff',0,13,25000),
 ('Client & Case Services','Supervisor, Case Specialist',1,1,45000),
 ('Client & Case Services','Case Specialist',0,18,25000),
 ('Marketing & Communication','Marketing Coordinator',1,1,35000),
 ('Marketing & Communication','Marketing Campaign Administrator',0,2,24000),
 ('Marketing & Communication','Digital Media Producer',0,2,30000),
 ('Marketing & Communication','Social Media Support',0,5,22000),
 ('Sales','Sales Team Leader',1,1,40000),
 ('Sales','Sales Representative',0,10,25000),
 ('Sales','Independent Sales Representative',0,1,28000),
 ('Executive Support','Executive Assistant to CEO',1,1,40000),
 ('Executive Support','Junior Executive Assistant',0,2,25000),
 ('Engineering','Designated Engineering Lead',1,1,50000),
 ('Engineering','Engineering Staff',0,2,30000);

INSERT INTO department_positions (department_id, position_title, is_tl_position, base_salary, headcount, is_active)
SELECT d.id, t.title, t.tm, t.sal, t.hc, 1 FROM tmp_pos t JOIN departments d ON d.name COLLATE utf8mb4_general_ci = t.dn COLLATE utf8mb4_general_ci
WHERE @seeded=0
ON DUPLICATE KEY UPDATE is_tl_position=VALUES(is_tl_position), base_salary=VALUES(base_salary), headcount=VALUES(headcount), is_active=1;
DROP TEMPORARY TABLE IF EXISTS tmp_pos;

-- Clean legacy HTML-escaped department names ("Finance &amp; Accounting" -> "Finance & Accounting")
UPDATE users SET department = REPLACE(department, '&amp;', '&') WHERE department LIKE '%&amp;%';

-- Keep departments in step with the active TM position / headcount
UPDATE departments d SET
  tl_position = COALESCE((SELECT p.position_title FROM department_positions p WHERE p.department_id=d.id AND p.is_tl_position=1 AND p.is_active=1 ORDER BY p.id LIMIT 1), d.tl_position),
  target_headcount = (SELECT COALESCE(SUM(p.headcount),0) FROM department_positions p WHERE p.department_id=d.id AND p.is_active=1);


-- ------------------------------------------------------------
-- Payroll: absence / LWOP columns (Option A rules; lwp_* may already exist in the live DB)
-- ------------------------------------------------------------
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll' AND COLUMN_NAME='absent_days')=0,
  'ALTER TABLE payroll ADD COLUMN absent_days INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll' AND COLUMN_NAME='absence_deduction')=0,
  'ALTER TABLE payroll ADD COLUMN absence_deduction DECIMAL(12,2) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll' AND COLUMN_NAME='lwp_days')=0,
  'ALTER TABLE payroll ADD COLUMN lwp_days INT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll' AND COLUMN_NAME='lwp_deduction')=0,
  'ALTER TABLE payroll ADD COLUMN lwp_deduction DECIMAL(12,2) NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ------------------------------------------------------------
-- 2. USERS: Team Leader designation (NOT a new role) + team assignment
-- ------------------------------------------------------------
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='is_team_leader')=0,
  'ALTER TABLE users ADD COLUMN is_team_leader TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='team_leader_id')=0,
  CONCAT('ALTER TABLE users ADD COLUMN team_leader_id ', @eid, ' NULL DEFAULT NULL'), 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND CONSTRAINT_NAME='fk_users_team_leader')=0,
  'ALTER TABLE users ADD CONSTRAINT fk_users_team_leader FOREIGN KEY (team_leader_id) REFERENCES users(employee_id) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ------------------------------------------------------------
-- 3. LEAVE: Team Leader endorsement + HR credit assignment
-- ------------------------------------------------------------
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leave_requests' AND COLUMN_NAME='endorsement_status')=0,
  'ALTER TABLE leave_requests ADD COLUMN endorsement_status VARCHAR(20) NOT NULL DEFAULT ''none''', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leave_requests' AND COLUMN_NAME='endorsed_by')=0,
  CONCAT('ALTER TABLE leave_requests ADD COLUMN endorsed_by ', @eid, ' NULL DEFAULT NULL'), 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leave_requests' AND COLUMN_NAME='endorsed_at')=0,
  'ALTER TABLE leave_requests ADD COLUMN endorsed_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leave_requests' AND COLUMN_NAME='endorsement_comment')=0,
  'ALTER TABLE leave_requests ADD COLUMN endorsement_comment VARCHAR(500) NULL DEFAULT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leave_requests' AND COLUMN_NAME='requested_by')=0,
  CONCAT('ALTER TABLE leave_requests ADD COLUMN requested_by ', @eid, ' NULL DEFAULT NULL'), 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leave_requests' AND COLUMN_NAME='hr_override')=0,
  'ALTER TABLE leave_requests ADD COLUMN hr_override TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = CONCAT('CREATE TABLE IF NOT EXISTS leave_credit_adjustments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    employee_id ', @eid, ' NOT NULL,
    year        INT NOT NULL,
    leave_type  VARCHAR(20) NOT NULL,
    days        INT NOT NULL,
    reason      VARCHAR(255) NOT NULL,
    added_by    ', @eid, ' NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lca_emp (employee_id, year),
    CONSTRAINT fk_lca_emp FOREIGN KEY (employee_id) REFERENCES users(employee_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ------------------------------------------------------------
-- 4. PAYROLL: Submit for CEO Review -> Approve / Reject
-- ------------------------------------------------------------
SET @s = CONCAT('CREATE TABLE IF NOT EXISTS payroll_approvals (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    period_start      DATE NOT NULL,
    period_end        DATE NOT NULL,
    status            ENUM(''pending_ceo'',''approved'',''rejected'') NOT NULL DEFAULT ''pending_ceo'',
    employee_count    INT NOT NULL DEFAULT 0,
    gross_total       DECIMAL(14,2) NOT NULL DEFAULT 0,
    bonus_total       DECIMAL(14,2) NOT NULL DEFAULT 0,
    adjustment_total  DECIMAL(14,2) NOT NULL DEFAULT 0,
    thirteenth_total  DECIMAL(14,2) NOT NULL DEFAULT 0,
    deduction_total   DECIMAL(14,2) NOT NULL DEFAULT 0,
    employer_total    DECIMAL(14,2) NOT NULL DEFAULT 0,
    net_total         DECIMAL(14,2) NOT NULL DEFAULT 0,
    submitted_by      ', @eid, ' NULL,
    submitted_by_name VARCHAR(150) NULL,
    submitted_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by       ', @eid, ' NULL,
    reviewed_by_name  VARCHAR(150) NULL,
    reviewed_at       DATETIME NULL,
    reject_reason     TEXT NULL,
    KEY idx_pa_period (period_start, period_end),
    CONSTRAINT fk_pa_submitter FOREIGN KEY (submitted_by) REFERENCES users(employee_id) ON DELETE SET NULL,
    CONSTRAINT fk_pa_reviewer  FOREIGN KEY (reviewed_by)  REFERENCES users(employee_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- payroll.loan_deduction is used by payroll.php; add it when the DB does not have it yet
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='payroll' AND COLUMN_NAME='loan_deduction')=0,
  'ALTER TABLE payroll ADD COLUMN loan_deduction DECIMAL(12,2) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ------------------------------------------------------------
-- 5. PERFORMANCE EVALUATION
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS performance_criteria (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    weight      DECIMAL(5,2) NOT NULL,
    description TEXT NULL,
    sort_order  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO performance_criteria (name, weight, description, sort_order) VALUES
 ('Work Quality & Accuracy',        25, 'Accuracy of documents, records, applications, reports and assigned work.', 1),
 ('Productivity & Task Completion', 20, 'Completes assigned tasks on time and handles workload effectively.', 2),
 ('Attendance & Punctuality',       20, 'Attendance, lateness, undertime and punctuality.', 3),
 ('Client Service & Communication', 20, 'Professional communication, responsiveness and proper handling of clients.', 4),
 ('Professionalism & Teamwork',     15, 'Cooperation, responsibility, attitude, confidentiality and following company policies.', 5);

SET @s = CONCAT('CREATE TABLE IF NOT EXISTS performance_evaluations (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    employee_id      ', @eid, ' NOT NULL,
    period_label     VARCHAR(60) NOT NULL,
    period_start     DATE NOT NULL,
    period_end       DATE NOT NULL,
    status           ENUM(''draft'',''finalized'') NOT NULL DEFAULT ''draft'',
    total_score      DECIMAL(5,2) NOT NULL DEFAULT 0,
    performance_level VARCHAR(30) NULL,
    hr_remarks       TEXT NULL,
    attendance_pct   DECIMAL(5,2) NULL,
    late_count       INT NULL,
    undertime_count  INT NULL,
    absent_count     INT NULL,
    evaluated_by     ', @eid, ' NULL,
    evaluated_by_name VARCHAR(150) NULL,
    evaluated_at     DATETIME NULL,
    finalized_at     DATETIME NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_eval_period (employee_id, period_start, period_end),
    CONSTRAINT fk_eval_emp  FOREIGN KEY (employee_id) REFERENCES users(employee_id) ON DELETE CASCADE,
    CONSTRAINT fk_eval_by   FOREIGN KEY (evaluated_by) REFERENCES users(employee_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS performance_ratings (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    evaluation_id  INT NOT NULL,
    criterion_id   INT NOT NULL,
    rating         TINYINT NOT NULL,
    weight         DECIMAL(5,2) NOT NULL,
    weighted_score DECIMAL(5,2) NOT NULL,
    UNIQUE KEY uq_eval_crit (evaluation_id, criterion_id),
    CONSTRAINT fk_rating_eval FOREIGN KEY (evaluation_id) REFERENCES performance_evaluations(id) ON DELETE CASCADE,
    CONSTRAINT fk_rating_crit FOREIGN KEY (criterion_id)  REFERENCES performance_criteria(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 6. EMPLOYEE LOAN DISCLOSURE (SOA upload -> HR verification -> payroll deduction)
--    An uploaded SOA never creates a deduction by itself: only status
--    'approved' (set by HR/Admin) makes the loan eligible for payroll.
-- ------------------------------------------------------------
SET @s = CONCAT('CREATE TABLE IF NOT EXISTS loan_disclosures (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    employee_id          ', @eid, ' NOT NULL,
    provider             VARCHAR(150) NOT NULL,
    loan_type            VARCHAR(100) NOT NULL,
    reference_no         VARCHAR(100) NULL,
    original_amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
    outstanding_balance  DECIMAL(12,2) NOT NULL DEFAULT 0,
    monthly_amortization DECIMAL(12,2) NOT NULL DEFAULT 0,
    loan_start_date      DATE NULL,
    term_months          INT NULL,
    deduction_schedule   VARCHAR(20) NOT NULL DEFAULT ''end_of_month'',
    remarks              TEXT NULL,
    doc_path             VARCHAR(255) NULL,
    doc_name             VARCHAR(200) NULL,
    doc_mime             VARCHAR(100) NULL,
    status               ENUM(''for_review'',''verified'',''approved'',''rejected'',''needs_info'') NOT NULL DEFAULT ''for_review'',
    hr_remarks           TEXT NULL,
    reviewed_by          ', @eid, ' NULL,
    reviewed_at          DATETIME NULL,
    loan_status          ENUM(''active'',''fully_paid'') NOT NULL DEFAULT ''active'',
    next_deduction_date  DATE NULL,
    submitted_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ld_emp (employee_id, status),
    CONSTRAINT fk_ld_emp FOREIGN KEY (employee_id) REFERENCES users(employee_id) ON DELETE CASCADE,
    CONSTRAINT fk_ld_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(employee_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS loan_disclosure_payments (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    disclosure_id INT NOT NULL,
    payroll_id    INT NULL,
    payment_month CHAR(7) NOT NULL,
    payment_date  DATE NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ldp_month (disclosure_id, payment_month),
    CONSTRAINT fk_ldp_disc FOREIGN KEY (disclosure_id) REFERENCES loan_disclosures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
