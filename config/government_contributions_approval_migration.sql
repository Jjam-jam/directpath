-- ============================================================
-- Directpath EMPS — CEO approval for Government Contribution Schedules
-- Safe to run more than once (MariaDB 10.4+). Back up the database first.
-- Run AFTER government_contributions_migration.sql
-- ============================================================
-- Existing schedules default to 'approved' so schedules that are already active keep working.
-- Every schedule added or edited from now on is submitted as 'pending_ceo'. HR activates it after CEO approval.
ALTER TABLE government_contribution_schedules
    ADD COLUMN IF NOT EXISTS approval_status ENUM('pending_ceo','approved','rejected') NOT NULL DEFAULT 'approved' AFTER status,
    ADD COLUMN IF NOT EXISTS submitted_at DATETIME DEFAULT NULL AFTER approval_status,
    ADD COLUMN IF NOT EXISTS reviewed_by VARCHAR(20) DEFAULT NULL AFTER submitted_at,
    ADD COLUMN IF NOT EXISTS reviewed_by_name VARCHAR(150) DEFAULT NULL AFTER reviewed_by,
    ADD COLUMN IF NOT EXISTS reviewed_at DATETIME DEFAULT NULL AFTER reviewed_by_name,
    ADD COLUMN IF NOT EXISTS reject_reason VARCHAR(1000) DEFAULT NULL AFTER reviewed_at;

CREATE TABLE IF NOT EXISTS government_contribution_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    recipient_role VARCHAR(30) NOT NULL,
    message VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME DEFAULT NULL,
    KEY idx_gcn_role_read (recipient_role, read_at, created_at),
    KEY idx_gcn_schedule (schedule_id),
    CONSTRAINT fk_gcn_schedule FOREIGN KEY (schedule_id)
        REFERENCES government_contribution_schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
