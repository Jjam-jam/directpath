-- Additive migration for directpath_db. Back up the database before applying.
-- Existing payroll rows and salary history are preserved; their new snapshots stay
-- NULL because their exact historical employer shares/rules cannot be reconstructed.
-- No agency-rate seed is included: rule values must first be verified against
-- official publications, then entered and explicitly activated by an authorized user.

CREATE TABLE IF NOT EXISTS government_contribution_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agency ENUM('SSS','PhilHealth','Pag-IBIG') NOT NULL,
    schedule_name VARCHAR(150) NOT NULL,
    effective_from DATE NOT NULL,
    effective_until DATE DEFAULT NULL,
    status ENUM('pending','active','inactive') NOT NULL DEFAULT 'pending',
    source_reference VARCHAR(500) NOT NULL,
    source_url VARCHAR(1000) DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    verified_by VARCHAR(20) DEFAULT NULL,
    created_by VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_contribution_schedule_lookup (agency, status, effective_from, effective_until),
    KEY idx_contribution_schedule_effective (effective_from, effective_until),
    CONSTRAINT fk_contribution_schedule_creator
        FOREIGN KEY (created_by) REFERENCES users(employee_id) ON DELETE RESTRICT,
    CONSTRAINT fk_contribution_schedule_verifier
        FOREIGN KEY (verified_by) REFERENCES users(employee_id) ON DELETE SET NULL,
    CONSTRAINT chk_contribution_schedule_dates
        CHECK (effective_until IS NULL OR effective_until >= effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade schedules created by an earlier version of this additive migration.
-- Existing schedule rows and their rule records remain unchanged.
ALTER TABLE government_contribution_schedules
    ADD COLUMN IF NOT EXISTS source_url VARCHAR(1000) DEFAULT NULL AFTER source_reference;

CREATE TABLE IF NOT EXISTS government_contribution_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    salary_min DECIMAL(12,2) NOT NULL,
    salary_max DECIMAL(12,2) DEFAULT NULL,
    contribution_base_mode ENUM('salary','salary_capped','fixed') NOT NULL,
    contribution_base DECIMAL(12,2) DEFAULT NULL,
    contribution_base_floor DECIMAL(12,2) DEFAULT NULL,
    contribution_base_cap DECIMAL(12,2) DEFAULT NULL,
    employee_rate DECIMAL(9,5) NOT NULL DEFAULT 0.00000,
    employer_rate DECIMAL(9,5) NOT NULL DEFAULT 0.00000,
    employee_fixed_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    employer_fixed_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_government_rule_lookup (schedule_id, salary_min, salary_max),
    CONSTRAINT fk_government_rule_schedule
        FOREIGN KEY (schedule_id) REFERENCES government_contribution_schedules(id) ON DELETE RESTRICT,
    CONSTRAINT chk_government_rule_salary_range
        CHECK (salary_min >= 0 AND (salary_max IS NULL OR salary_max >= salary_min)),
    CONSTRAINT chk_government_rule_rates
        CHECK (employee_rate BETWEEN 0 AND 100 AND employer_rate BETWEEN 0 AND 100),
    CONSTRAINT chk_government_rule_amounts
        CHECK (employee_fixed_amount >= 0 AND employer_fixed_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sss_contribution_table (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    salary_min DECIMAL(12,2) NOT NULL,
    salary_max DECIMAL(12,2) DEFAULT NULL,
    msc DECIMAL(12,2) NOT NULL,
    regular_ss_employee DECIMAL(12,2) NOT NULL,
    regular_ss_employer DECIMAL(12,2) NOT NULL,
    mpf_employee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    mpf_employer DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ec_employee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ec_employer DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sss_bracket_lookup (schedule_id, salary_min, salary_max),
    CONSTRAINT fk_sss_bracket_schedule
        FOREIGN KEY (schedule_id) REFERENCES government_contribution_schedules(id) ON DELETE RESTRICT,
    CONSTRAINT chk_sss_bracket_range
        CHECK (salary_min >= 0 AND (salary_max IS NULL OR salary_max >= salary_min)),
    CONSTRAINT chk_sss_bracket_amounts
        CHECK (
            msc > 0 AND regular_ss_employee >= 0 AND regular_ss_employer >= 0
            AND mpf_employee >= 0 AND mpf_employer >= 0
            AND ec_employee >= 0 AND ec_employer >= 0
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll
    ADD COLUMN IF NOT EXISTS sss_employer DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER sss,
    ADD COLUMN IF NOT EXISTS sss_ec_employer DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER sss_employer,
    ADD COLUMN IF NOT EXISTS philhealth_employer DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER philhealth,
    ADD COLUMN IF NOT EXISTS pagibig_employer DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pagibig,
    ADD COLUMN IF NOT EXISTS government_contributions_snapshot LONGTEXT DEFAULT NULL AFTER pagibig_employer;
