-- ============================================================
-- Directpath HRMS — Normalized Database Schema (directpath_db)
-- Philippines HRMS 2026
-- Pinagbago: Relational/normalized design para mas maayos
-- ============================================================

CREATE DATABASE IF NOT EXISTS directpath_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE directpath_db;

-- ============================================================
-- ROLES TABLE
-- Nandito ang lahat ng available na roles sa system
-- (employee = regular staff, admin = HR manager, superadmin = top-level)
-- ============================================================
CREATE TABLE IF NOT EXISTS roles (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    role_name   ENUM('employee','admin','superadmin') UNIQUE NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Ilagay agad ang 3 default roles
INSERT IGNORE INTO roles (role_name, description) VALUES
    ('employee',   'Regular employee / staff'),
    ('admin',      'HR Manager — can manage payroll and leaves'),
    ('superadmin', 'System administrator — full access');

-- ============================================================
-- USERS TABLE (dating "employees" table — pinalitan ng pangalan)
-- Pinalit ang "name" ng first_name + middle_name + last_name
-- Para mas maayos ang sorting at proper display ng full name
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    employee_id     VARCHAR(20) UNIQUE NOT NULL,   -- e.g. EMP-0001

    -- Hiwalay ang pangalan: una, gitna, apelyido
    first_name      VARCHAR(80)  NOT NULL,
    middle_name     VARCHAR(80)  DEFAULT NULL,      -- optional, pwede NULL
    last_name       VARCHAR(80)  NOT NULL,

    email           VARCHAR(150) UNIQUE NOT NULL,
    password        VARCHAR(255) NOT NULL,
    department      VARCHAR(100) NOT NULL,
    position        VARCHAR(100) NOT NULL,
    date_hired      DATE         NOT NULL,
    status          ENUM('active','inactive') DEFAULT 'active',

    -- Basic salary info (nandito pa rin para madaling ma-access)
    basic_salary    DECIMAL(12,2) DEFAULT 0.00,
    allowances      DECIMAL(12,2) DEFAULT 0.00,

    -- Photos
    profile_photo   VARCHAR(255) DEFAULT NULL,
    reference_photo VARCHAR(255) DEFAULT NULL,

    -- Contact and personal info
    phone           VARCHAR(20)  DEFAULT NULL,
    address         TEXT         DEFAULT NULL,
    civil_status    ENUM('single','married','widowed','separated') DEFAULT 'single',

    -- Government IDs ng Pilipinas
    sss_no          VARCHAR(30) DEFAULT NULL,
    philhealth_no   VARCHAR(30) DEFAULT NULL,
    pagibig_no      VARCHAR(30) DEFAULT NULL,
    tin_no          VARCHAR(30) DEFAULT NULL,

    -- Session / auth tokens
    remember_token  VARCHAR(255) DEFAULT NULL,
    reset_token     VARCHAR(255) DEFAULT NULL,
    reset_expires   DATETIME     DEFAULT NULL,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- USER_ROLES TABLE (Join Table)
-- Ito ang nagkokonekta ng users at roles.
-- Pwedeng mag-assign ng maraming role sa iisang tao kung kailangan
-- Mas flexible kaysa sa single "role" column sa users table
-- ============================================================
CREATE TABLE IF NOT EXISTS user_roles (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    role_id     INT         NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by VARCHAR(20) DEFAULT NULL,           -- kung sino ang nag-assign

    -- Bawal ang duplicate role sa iisang user
    UNIQUE KEY unique_user_role (employee_id, role_id),

    FOREIGN KEY (employee_id) REFERENCES users(employee_id) ON DELETE CASCADE,
    FOREIGN KEY (role_id)     REFERENCES roles(id)          ON DELETE CASCADE
);

-- ============================================================
-- FACE ENCODINGS TABLE — one trained encoding per employee
-- ============================================================
CREATE TABLE IF NOT EXISTS face_encodings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    encoding    TEXT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_face_encoding_employee (employee_id),
    FOREIGN KEY (employee_id) REFERENCES users(employee_id) ON DELETE CASCADE
);

-- ============================================================
-- ATTENDANCE TABLE — linked sa users via employee_id
-- ============================================================
CREATE TABLE IF NOT EXISTS attendance (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    employee_id     VARCHAR(20)  NOT NULL,
    date            DATE         NOT NULL,
    time_in         TIME         DEFAULT NULL,
    time_out        TIME         DEFAULT NULL,
    late_minutes    INT          DEFAULT 0,
    undertime_mins  INT          DEFAULT 0,
    hours_worked    DECIMAL(5,2) DEFAULT 0.00,
    status          ENUM('present','absent','half-day','on-leave') DEFAULT 'present',
    captured_photo  VARCHAR(255) DEFAULT NULL,
    notes           TEXT         DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_attendance (employee_id, date),
    FOREIGN KEY (employee_id) REFERENCES users(employee_id) ON DELETE CASCADE
);

-- ============================================================
-- LEAVE_BALANCES TABLE (BAGO!)
-- Nagtatago ng kung ilang leave days ang natitira ng bawat empleyado
-- per leave type, per year.
-- Kapag nag-apply ng leave at na-approve, babawasan ang *_used
-- Kaya lalabas kung ilan pa ang natitira = total - used
-- ============================================================
CREATE TABLE IF NOT EXISTS leave_balances (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    employee_id     VARCHAR(20) NOT NULL,
    year            INT         NOT NULL DEFAULT 2026,

    -- Vacation Leave (VL): 15 araw standard
    vacation_total  INT DEFAULT 15,
    vacation_used   INT DEFAULT 0,

    -- Sick Leave (SL): 15 araw standard
    sick_total      INT DEFAULT 15,
    sick_used       INT DEFAULT 0,

    -- Emergency Leave: 5 araw
    emergency_total INT DEFAULT 5,
    emergency_used  INT DEFAULT 0,

    -- Maternity Leave: 105 araw (RA 11210 - Expanded Maternity Leave Law)
    maternity_total INT DEFAULT 105,
    maternity_used  INT DEFAULT 0,

    -- Paternity Leave: 7 araw (RA 8187)
    paternity_total INT DEFAULT 7,
    paternity_used  INT DEFAULT 0,

    -- Others: walang fixed, 0 ang default
    others_total    INT DEFAULT 0,
    others_used     INT DEFAULT 0,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Iisang record lang per employee per year
    UNIQUE KEY unique_balance (employee_id, year),
    FOREIGN KEY (employee_id) REFERENCES users(employee_id) ON DELETE CASCADE
);

-- ============================================================
-- LEAVE_REQUESTS TABLE
-- Linked sa leave_balances — kapag approved, mag-uupdate ng *_used
-- ============================================================
CREATE TABLE IF NOT EXISTS leave_requests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    employee_id     VARCHAR(20) NOT NULL,
    leave_type      ENUM('vacation','sick','emergency','maternity','paternity','others') NOT NULL,
    start_date      DATE    NOT NULL,
    end_date        DATE    NOT NULL,
    total_days      INT     DEFAULT 1,
    reason          TEXT    NOT NULL,
    status          ENUM('pending','approved_pending_documents','documents_submitted','documents_rejected','approved','rejected') NOT NULL DEFAULT 'pending',
    urgent          TINYINT(1) NOT NULL DEFAULT 0,
    auto_approval_after DATETIME DEFAULT NULL,
    reviewed_by     VARCHAR(150) DEFAULT NULL,
    review_date     TIMESTAMP NULL DEFAULT NULL,
    review_remarks  TEXT    DEFAULT NULL,
    approved_at     DATETIME DEFAULT NULL,
    documents_due_at DATETIME DEFAULT NULL,
    document_path   VARCHAR(255) DEFAULT NULL,
    document_name   VARCHAR(200) DEFAULT NULL,
    document_mime   VARCHAR(100) DEFAULT NULL,
    document_reviewed_by VARCHAR(150) DEFAULT NULL,
    document_reviewed_at DATETIME DEFAULT NULL,
    document_review_remarks TEXT DEFAULT NULL,
    documents_flagged_at DATETIME DEFAULT NULL,
    attachment_path VARCHAR(255) DEFAULT NULL,
    attachment_name VARCHAR(200) DEFAULT NULL,
    attachment_mime VARCHAR(100) DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES users(employee_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS leave_request_events (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    leave_request_id INT NOT NULL,
    action          VARCHAR(60) NOT NULL,
    actor           VARCHAR(150) NOT NULL,
    details         TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_leave_event_request (leave_request_id, created_at),
    FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS leave_notifications (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    leave_request_id      INT NOT NULL,
    recipient_employee_id VARCHAR(20) DEFAULT NULL,
    recipient_role        VARCHAR(30) DEFAULT NULL,
    message               VARCHAR(255) NOT NULL,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at               DATETIME DEFAULT NULL,
    KEY idx_leave_notification_employee (recipient_employee_id, read_at),
    KEY idx_leave_notification_role (recipient_role, read_at),
    FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE CASCADE
);

-- ============================================================
-- SALARY_GRADES TABLE (BAGO! — para mas maayos ang salary levels)
-- Hiwalay ang salary structure para madaling i-update
-- ============================================================
CREATE TABLE IF NOT EXISTS salary_grades (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    grade_name  VARCHAR(50) UNIQUE NOT NULL,
    min_salary  DECIMAL(12,2) NOT NULL,
    max_salary  DECIMAL(12,2) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- PAYROLL TABLE — hiwalay na talaga sa users table
-- Para sa bawat payroll transaction ng bawat empleyado
-- ============================================================
CREATE TABLE IF NOT EXISTS payroll (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    employee_id         VARCHAR(20) NOT NULL,
    payroll_period_start DATE NOT NULL,
    payroll_period_end   DATE NOT NULL,

    -- Kita / Earnings
    basic_salary        DECIMAL(12,2) DEFAULT 0.00,
    allowances          DECIMAL(12,2) DEFAULT 0.00,
    overtime_pay        DECIMAL(12,2) DEFAULT 0.00,
    bonuses             DECIMAL(12,2) DEFAULT 0.00,
    gross_pay           DECIMAL(12,2) DEFAULT 0.00,  -- basic + allowances + OT + bonuses

    -- Mga Bawas / Deductions (Philippines 2026 rates)
    sss                 DECIMAL(12,2) DEFAULT 0.00,  -- SSS: 5% ng basic salary
    philhealth          DECIMAL(12,2) DEFAULT 0.00,  -- PhilHealth: 2.5% ng basic salary
    pagibig             DECIMAL(12,2) DEFAULT 0.00,  -- Pag-IBIG: 2% pero max lang ₱200
    sss_employer        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    sss_ec_employer     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    philhealth_employer DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pagibig_employer    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    government_contributions_snapshot LONGTEXT DEFAULT NULL,
    withholding_tax     DECIMAL(12,2) DEFAULT 0.00,  -- BIR withholding tax (TRAIN Law)
    other_deductions    DECIMAL(12,2) DEFAULT 0.00,  -- Ibang bawas (loans, cash advance, etc.)
    total_deductions    DECIMAL(12,2) DEFAULT 0.00,  -- Kabuuang bawas

    net_salary          DECIMAL(12,2) DEFAULT 0.00,  -- Dalhin sa bahay = gross - total deductions

    -- Attendance details
    days_worked         INT          DEFAULT 0,
    overtime_hours      DECIMAL(5,2) DEFAULT 0.00,
    late_deduction      DECIMAL(12,2) DEFAULT 0.00,
    undertime_deduction DECIMAL(12,2) DEFAULT 0.00,

    status              ENUM('draft','generated','released') DEFAULT 'draft',
    generated_by        VARCHAR(150) DEFAULT NULL,
    released_at         TIMESTAMP NULL DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_payroll (employee_id, payroll_period_start, payroll_period_end),
    FOREIGN KEY (employee_id) REFERENCES users(employee_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS payroll_previous_employer_ytd (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    employee_id         VARCHAR(20) NOT NULL,
    tax_year            SMALLINT UNSIGNED NOT NULL,
    taxable_income      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    non_taxable_income  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    sss                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    philhealth          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    hdmf                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    withholding_tax     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_employee_tax_year (employee_id, tax_year),
    FOREIGN KEY (employee_id) REFERENCES users(employee_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_change_requests (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    change_type       ENUM('compensation','ytd') NOT NULL,
    employee_id       VARCHAR(20) NOT NULL,
    reference_year    SMALLINT UNSIGNED DEFAULT NULL,
    payload           JSON NOT NULL,
    status            ENUM('pending_ceo','approved_pending_release','rejected','released') NOT NULL DEFAULT 'pending_ceo',
    submitted_by      VARCHAR(20) DEFAULT NULL,
    submitted_by_name VARCHAR(150) NOT NULL,
    submitted_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by       VARCHAR(20) DEFAULT NULL,
    reviewed_by_name  VARCHAR(150) DEFAULT NULL,
    reviewed_at       DATETIME DEFAULT NULL,
    reject_reason     TEXT DEFAULT NULL,
    released_by       VARCHAR(20) DEFAULT NULL,
    released_by_name  VARCHAR(150) DEFAULT NULL,
    released_at       DATETIME DEFAULT NULL,
    KEY idx_payroll_change_status (status, submitted_at),
    KEY idx_payroll_change_employee (employee_id, change_type, reference_year)
);

-- ============================================================
-- TAX_BRACKETS TABLE (BAGO!)
-- Para ma-store ang BIR TRAIN Law tax brackets sa database
-- Mas madaling i-update kapag nagbago ang BIR rates kaysa sa hard-coded PHP
-- ============================================================
CREATE TABLE IF NOT EXISTS tax_brackets (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    min_income      DECIMAL(12,2) NOT NULL,
    max_income      DECIMAL(12,2) DEFAULT NULL,   -- NULL = walang ceiling (highest bracket)
    fixed_tax       DECIMAL(12,2) DEFAULT 0.00,
    tax_rate        DECIMAL(5,4)  DEFAULT 0.00,   -- e.g. 0.15 = 15%
    excess_over     DECIMAL(12,2) DEFAULT 0.00,
    effective_year  INT DEFAULT 2026,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- BIR TRAIN Law Monthly Tax Brackets (2023 onward)
INSERT IGNORE INTO tax_brackets (min_income, max_income, fixed_tax, tax_rate, excess_over, effective_year) VALUES
    (0,       20833,   0,         0.00,  0,      2026),
    (20833,   33332,   0,         0.15,  20833,  2026),
    (33333,   66666,   1875.00,   0.20,  33333,  2026),
    (66667,   166666,  8541.80,   0.25,  66667,  2026),
    (166667,  666666,  33541.80,  0.30,  166667, 2026),
    (666667,  NULL,    183541.80, 0.35,  666667, 2026);

-- ============================================================
-- ANNOUNCEMENTS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS announcements (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(255) NOT NULL,
    message     TEXT         NOT NULL,
    priority    ENUM('normal','important','urgent') DEFAULT 'normal',
    posted_by   VARCHAR(150) NOT NULL,
    is_active   TINYINT(1)   DEFAULT 1,
    date_posted TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS notifications (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    recipient_employee_id VARCHAR(20) DEFAULT NULL,
    recipient_role        VARCHAR(30) DEFAULT NULL,
    message               VARCHAR(255) NOT NULL,
    target_url            VARCHAR(500) NOT NULL,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at               DATETIME DEFAULT NULL,
    KEY idx_notification_employee (recipient_employee_id, read_at, created_at),
    KEY idx_notification_role (recipient_role, read_at, created_at)
);

CREATE TABLE IF NOT EXISTS announcement_reads (
    announcement_id INT NOT NULL,
    employee_id     VARCHAR(20) NOT NULL,
    read_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (announcement_id, employee_id),
    CONSTRAINT fk_announcement_reads_announcement FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE
);

-- ============================================================
-- BUG TICKETS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS bug_tickets (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    reported_by_id      VARCHAR(20)  NOT NULL,
    reported_by_name    VARCHAR(150) NOT NULL,
    title               VARCHAR(255) NOT NULL,
    description         TEXT         NOT NULL,
    priority            ENUM('low','medium','high','critical') DEFAULT 'medium',
    status              ENUM('open','in-progress','resolved','closed') DEFAULT 'open',
    resolution_notes    TEXT         DEFAULT NULL,
    date_created        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_resolved       TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (reported_by_id) REFERENCES users(employee_id) ON DELETE CASCADE
);

-- ============================================================
-- AUDIT LOGS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     VARCHAR(20)  NOT NULL,
    user_name   VARCHAR(150) NOT NULL,
    action      VARCHAR(255) NOT NULL,
    module      VARCHAR(100) NOT NULL,
    details     TEXT         DEFAULT NULL,
    ip_address  VARCHAR(45)  DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- EMPLOYEE REQUESTS TABLE (payslip + COE)
-- ============================================================
CREATE TABLE IF NOT EXISTS employee_requests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    employee_id     VARCHAR(20) NOT NULL,
    request_type    ENUM('payslip','coe') NOT NULL,
    payroll_id      INT         DEFAULT NULL,
    purpose         TEXT        DEFAULT NULL,
    status          ENUM('pending','approved','rejected') DEFAULT 'pending',
    admin_notes     TEXT        DEFAULT NULL,
    reviewed_by     VARCHAR(150) DEFAULT NULL,
    review_date     TIMESTAMP NULL DEFAULT NULL,
    coe_sent        TINYINT(1)  DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES users(employee_id) ON DELETE CASCADE
);

-- ============================================================
-- MIGRATION SCRIPT para sa lumang "employees" table:
-- Gamitin ito kung may existing data ka na.
--
-- Step 1: Ilipat ang data sa users table
-- INSERT INTO users (employee_id, first_name, middle_name, last_name, ...)
-- SELECT employee_id,
--        SUBSTRING_INDEX(name, ' ', 1),         -- first name
--        NULL,                                   -- middle name (wala sa lumang data)
--        SUBSTRING_INDEX(name, ' ', -1),         -- last name
--        email, password, department, position,
--        date_hired, status, basic_salary,
--        allowances, profile_photo, reference_photo,
--        phone, address, civil_status,
--        sss_no, philhealth_no, pagibig_no, tin_no,
--        remember_token, reset_token, reset_expires
-- FROM employees;
--
-- Step 2: I-assign ang roles gamit ang lumang "role" column
-- INSERT INTO user_roles (employee_id, role_id)
-- SELECT e.employee_id, r.id
-- FROM employees e
-- JOIN roles r ON r.role_name = e.role;
--
-- Step 3: Gumawa ng leave_balances record para sa bawat user
-- INSERT INTO leave_balances (employee_id, year)
-- SELECT employee_id, YEAR(NOW()) FROM users;
-- ============================================================
