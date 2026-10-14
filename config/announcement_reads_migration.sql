-- Tracks whether each employee has opened an announcement's details.
CREATE TABLE IF NOT EXISTS announcement_reads (
    announcement_id INT NOT NULL,
    employee_id     VARCHAR(20) NOT NULL,
    read_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (announcement_id, employee_id),
    CONSTRAINT fk_announcement_reads_announcement FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE
);
