-- Stores previous-employer annual amounts for the payslip YTD summary.
-- Safe to apply to databases that already contain payroll data.
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

ALTER TABLE payroll_previous_employer_ytd
    ADD COLUMN IF NOT EXISTS withholding_tax DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER hdmf;
