USE directpath_db;

ALTER TABLE leave_requests
    MODIFY status ENUM('pending','approved_pending_documents','documents_submitted','documents_rejected','approved','rejected') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS urgent TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS auto_approval_after DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS approved_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS documents_due_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS document_path VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS document_name VARCHAR(200) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS document_mime VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS document_reviewed_by VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS document_reviewed_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS document_review_remarks TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS documents_flagged_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(200) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS attachment_mime VARCHAR(100) DEFAULT NULL;

UPDATE leave_requests
SET urgent = 1
WHERE leave_type = 'emergency';

UPDATE leave_requests
SET approved_at = review_date
WHERE status = 'approved' AND approved_at IS NULL AND review_date IS NOT NULL;

INSERT IGNORE INTO leave_balances (employee_id, year)
SELECT employee_id, YEAR(CURDATE()) FROM users;

INSERT IGNORE INTO leave_balances (employee_id, year)
SELECT DISTINCT employee_id, YEAR(start_date)
FROM leave_requests
WHERE status IN ('approved', 'approved_pending_documents', 'documents_submitted', 'documents_rejected');

UPDATE leave_balances SET vacation_total = 15, sick_total = 15, emergency_total = 5,
    maternity_total = 105, paternity_total = 7, others_total = 0;

UPDATE leave_balances b SET
    vacation_used = (SELECT COALESCE(SUM(lr.total_days), 0) FROM leave_requests lr WHERE lr.employee_id=b.employee_id AND YEAR(lr.start_date)=b.year AND lr.leave_type='vacation' AND lr.status IN ('approved','approved_pending_documents','documents_submitted','documents_rejected')),
    sick_used = (SELECT COALESCE(SUM(lr.total_days), 0) FROM leave_requests lr WHERE lr.employee_id=b.employee_id AND YEAR(lr.start_date)=b.year AND lr.leave_type='sick' AND lr.status IN ('approved','approved_pending_documents','documents_submitted','documents_rejected')),
    emergency_used = (SELECT COALESCE(SUM(lr.total_days), 0) FROM leave_requests lr WHERE lr.employee_id=b.employee_id AND YEAR(lr.start_date)=b.year AND lr.leave_type='emergency' AND lr.status IN ('approved','approved_pending_documents','documents_submitted','documents_rejected')),
    maternity_used = (SELECT COALESCE(SUM(lr.total_days), 0) FROM leave_requests lr WHERE lr.employee_id=b.employee_id AND YEAR(lr.start_date)=b.year AND lr.leave_type='maternity' AND lr.status IN ('approved','approved_pending_documents','documents_submitted','documents_rejected')),
    paternity_used = (SELECT COALESCE(SUM(lr.total_days), 0) FROM leave_requests lr WHERE lr.employee_id=b.employee_id AND YEAR(lr.start_date)=b.year AND lr.leave_type='paternity' AND lr.status IN ('approved','approved_pending_documents','documents_submitted','documents_rejected')),
    others_used = (SELECT COALESCE(SUM(lr.total_days), 0) FROM leave_requests lr WHERE lr.employee_id=b.employee_id AND YEAR(lr.start_date)=b.year AND lr.leave_type='others' AND lr.status IN ('approved','approved_pending_documents','documents_submitted','documents_rejected'));

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
