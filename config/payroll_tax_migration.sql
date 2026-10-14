-- Additive migration for effective-dated annual BIR withholding-tax rules.
-- Existing payroll values and the legacy monthly tax_brackets table are preserved.

CREATE TABLE IF NOT EXISTS income_tax_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_name VARCHAR(150) NOT NULL,
    effective_from DATE NOT NULL,
    effective_until DATE DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    source_reference VARCHAR(500) NOT NULL,
    source_url VARCHAR(1000) NOT NULL,
    created_by VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_income_tax_schedule_start (schedule_name, effective_from),
    KEY idx_income_tax_effective (status, effective_from, effective_until),
    CONSTRAINT fk_income_tax_schedule_creator
        FOREIGN KEY (created_by) REFERENCES users(employee_id) ON DELETE RESTRICT,
    CONSTRAINT chk_income_tax_schedule_dates
        CHECK (effective_until IS NULL OR effective_until >= effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS income_tax_brackets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    income_min DECIMAL(12,2) NOT NULL,
    income_max DECIMAL(12,2) DEFAULT NULL,
    fixed_tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    marginal_rate DECIMAL(7,5) NOT NULL DEFAULT 0.00000,
    excess_over DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_income_tax_bracket_min (schedule_id, income_min),
    KEY idx_income_tax_bracket_lookup (schedule_id, income_min, income_max),
    CONSTRAINT fk_income_tax_bracket_schedule
        FOREIGN KEY (schedule_id) REFERENCES income_tax_schedules(id) ON DELETE RESTRICT,
    CONSTRAINT chk_income_tax_bracket_range
        CHECK (income_min >= 0 AND (income_max IS NULL OR income_max >= income_min)),
    CONSTRAINT chk_income_tax_bracket_amounts
        CHECK (fixed_tax >= 0 AND marginal_rate BETWEEN 0 AND 1 AND excess_over >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
