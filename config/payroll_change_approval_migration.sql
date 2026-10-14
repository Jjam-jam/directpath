-- Adds the approval and HR release queue for compensation and previous-employer YTD changes.
-- Safe to run on an existing Directpath database.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
