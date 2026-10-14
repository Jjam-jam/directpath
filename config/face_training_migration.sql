USE directpath_db;

CREATE TABLE IF NOT EXISTS face_encodings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    encoding    TEXT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_face_encoding_employee (employee_id),
    FOREIGN KEY (employee_id) REFERENCES users(employee_id) ON DELETE CASCADE
);
