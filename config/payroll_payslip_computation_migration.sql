-- Additive payroll computation fields for taxable adjustments and payslip detail.
-- Existing payroll values are preserved. NULL calculation snapshots identify
-- payroll records generated before these fields were available.

ALTER TABLE payroll
    ADD COLUMN IF NOT EXISTS sss_mpf DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER sss,
    ADD COLUMN IF NOT EXISTS de_minimis DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER bonuses,
    ADD COLUMN IF NOT EXISTS late_minutes INT DEFAULT NULL AFTER late_deduction,
    ADD COLUMN IF NOT EXISTS undertime_minutes INT DEFAULT NULL AFTER late_minutes,
    ADD COLUMN IF NOT EXISTS lwp_days DECIMAL(7,2) DEFAULT NULL AFTER undertime_minutes,
    ADD COLUMN IF NOT EXISTS lwp_deduction DECIMAL(12,2) DEFAULT NULL AFTER lwp_days,
    ADD COLUMN IF NOT EXISTS payroll_calculation_snapshot LONGTEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS loan_balance_snapshot LONGTEXT DEFAULT NULL;
