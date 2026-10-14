-- ============================================================
-- Directpath EMPS — HR releases payroll after CEO approval
-- Safe to run more than once. Needs MariaDB 10.4+ (XAMPP) for IF NOT EXISTS.
-- Run on directpath_db after migration_team_performance_approval.sql
-- ============================================================

-- 1. Which employee(s) each payroll submission covers (what the CEO approved).
CREATE TABLE IF NOT EXISTS payroll_approval_items (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    approval_id    INT NOT NULL,
    payroll_id     INT NOT NULL,
    employee_id    VARCHAR(20) NOT NULL,
    employee_name  VARCHAR(150) NOT NULL,
    department     VARCHAR(100) NULL,
    position       VARCHAR(100) NULL,
    gross_pay      DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_salary     DECIMAL(12,2) NOT NULL DEFAULT 0,
    KEY idx_pai_approval (approval_id),
    KEY idx_pai_payroll  (payroll_id),
    KEY idx_pai_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Who released a payslip (HR) — shown to Payroll Manager / CEO.
ALTER TABLE payroll ADD COLUMN IF NOT EXISTS released_by_name VARCHAR(150) NULL AFTER released_at;
