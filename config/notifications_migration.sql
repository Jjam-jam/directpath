-- Persistent notifications with a direct link to the related module page/record.
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
