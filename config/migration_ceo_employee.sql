-- ============================================================
-- Directpath HRMS — Migration: CEO (view-only) + Employee modules
-- Target: MariaDB 10.4+ (uses ADD COLUMN IF NOT EXISTS). Safe to run more than once.
-- Take a database backup first.
-- Run after role_separation_migration.sql.
-- ============================================================

-- ── 1. Holiday calendar (time-in is disabled on these dates) ──────────
CREATE TABLE IF NOT EXISTS holidays (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE         NOT NULL,
    name         VARCHAR(150) NOT NULL,
    type         ENUM('regular','special','company') NOT NULL DEFAULT 'regular',
    created_by   VARCHAR(20)  DEFAULT NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_holiday_date (holiday_date)
);

-- 2026 national holidays per Proclamation No. 1006.
-- Eid'l Fitr / Eid'l Adha are proclaimed separately, so add them once declared.
INSERT IGNORE INTO holidays (holiday_date, name, type) VALUES
    ('2026-01-01', 'New Year''s Day',                'regular'),
    ('2026-02-17', 'Chinese New Year',               'special'),
    ('2026-04-02', 'Maundy Thursday',                'regular'),
    ('2026-04-03', 'Good Friday',                    'regular'),
    ('2026-04-04', 'Black Saturday',                 'special'),
    ('2026-04-09', 'Araw ng Kagitingan',             'regular'),
    ('2026-05-01', 'Labor Day',                      'regular'),
    ('2026-06-12', 'Independence Day',               'regular'),
    ('2026-08-21', 'Ninoy Aquino Day',               'special'),
    ('2026-08-31', 'National Heroes Day',            'regular'),
    ('2026-11-01', 'All Saints'' Day',               'special'),
    ('2026-11-02', 'All Souls'' Day',                'special'),
    ('2026-11-30', 'Bonifacio Day',                  'regular'),
    ('2026-12-08', 'Feast of the Immaculate Conception', 'special'),
    ('2026-12-24', 'Christmas Eve',                  'special'),
    ('2026-12-25', 'Christmas Day',                  'regular'),
    ('2026-12-30', 'Rizal Day',                      'regular'),
    ('2026-12-31', 'Last Day of the Year',           'special');

-- ── 2. Leave: proof attachment (sick & emergency) ─────────────────────
ALTER TABLE leave_requests
    ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS attachment_mime VARCHAR(100) DEFAULT NULL;

-- ── 3. Leave credits: one standard allotment per type/year ────────────
-- Keep in sync with LEAVE_ALLOTMENTS in config/config.php.
ALTER TABLE leave_balances
    MODIFY vacation_total  INT NOT NULL DEFAULT 15,
    MODIFY sick_total      INT NOT NULL DEFAULT 15,
    MODIFY emergency_total INT NOT NULL DEFAULT 5,
    MODIFY maternity_total INT NOT NULL DEFAULT 105,
    MODIFY paternity_total INT NOT NULL DEFAULT 7,
    MODIFY others_total    INT NOT NULL DEFAULT 0;

-- Fill any rows that were created with a missing/zero total for the core types.
UPDATE leave_balances SET vacation_total  = 15 WHERE vacation_total  IS NULL OR vacation_total  = 0;
UPDATE leave_balances SET sick_total      = 15 WHERE sick_total      IS NULL OR sick_total      = 0;
UPDATE leave_balances SET emergency_total = 5  WHERE emergency_total IS NULL OR emergency_total = 0;
UPDATE leave_balances SET maternity_total = 105 WHERE maternity_total IS NULL OR maternity_total = 0;
UPDATE leave_balances SET paternity_total = 7  WHERE paternity_total IS NULL OR paternity_total = 0;

-- ── 4. WFH verification log (every attempt, pass or fail) ─────────────
CREATE TABLE IF NOT EXISTS wfh_verification_log (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    employee_id  VARCHAR(20)  NOT NULL,
    action       ENUM('time_in','time_out') NOT NULL,
    result       ENUM('success','failed') NOT NULL,
    reason       VARCHAR(255) DEFAULT NULL,
    ip_address   VARCHAR(45)  DEFAULT NULL,
    user_agent   VARCHAR(255) DEFAULT NULL,
    latitude     DECIMAL(9,6) DEFAULT NULL,
    longitude    DECIMAL(9,6) DEFAULT NULL,
    accuracy_m   INT          DEFAULT NULL,
    photo        VARCHAR(255) DEFAULT NULL,
    flags        VARCHAR(100) DEFAULT NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    KEY idx_emp_time (employee_id, created_at)
);

-- ── 5. Payroll: transparent line items shown to employee & CEO ────────
ALTER TABLE payroll
    ADD COLUMN IF NOT EXISTS salary_adjustment    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS adjustment_reason    VARCHAR(255)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS thirteenth_month_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00;

-- ── 6. Attendance & users safety nets ────────────────────────────────
ALTER TABLE attendance
    MODIFY status ENUM('present','late','absent','half-day','on-leave') NULL DEFAULT 'present';

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS work_mode ENUM('onsite','wfh') NOT NULL DEFAULT 'wfh';

-- ── 7. Make sure the CEO role exists (no-op if role_separation_migration ran) ──
SELECT COALESCE(MAX(id), 0) + 1 INTO @next_role_id FROM roles;
INSERT INTO roles (id, role_name, description)
SELECT @next_role_id, 'ceo', 'CEO - view-only executive monitoring and reports'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'ceo');
