-- Custom leave type proposals and HR-managed leave types.
-- Run this against the directpath_db database. CREATE TABLE IF NOT EXISTS
-- makes it safe to re-run on installations where these tables already exist.
USE directpath_db;

CREATE TABLE IF NOT EXISTS custom_leave_types (
    type_key VARCHAR(48) NOT NULL,
    name VARCHAR(80) NOT NULL,
    annual_days SMALLINT UNSIGNED NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    document_required TINYINT(1) NOT NULL DEFAULT 0,
    document_guidance VARCHAR(500) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (type_key),
    UNIQUE KEY uq_custom_leave_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS custom_leave_type_requests (
    id INT NOT NULL AUTO_INCREMENT,
    employee_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    name VARCHAR(80) NOT NULL,
    annual_days SMALLINT UNSIGNED NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    document_required TINYINT(1) NOT NULL DEFAULT 0,
    document_guidance VARCHAR(500) NOT NULL DEFAULT '',
    request_reason VARCHAR(500) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_remarks VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_leave_type_request_status (status, created_at),
    KEY idx_leave_type_request_employee (employee_id, created_at),
    CONSTRAINT fk_cltr_employee FOREIGN KEY (employee_id)
        REFERENCES users (employee_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
