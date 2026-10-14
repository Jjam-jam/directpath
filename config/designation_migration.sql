-- Custom designations (other than Team Manager), each with its own monthly base salary.
-- Run once in phpMyAdmin on directpath_db (safe to run twice; MariaDB 10.4+). Back up first.

CREATE TABLE IF NOT EXISTS designations (
  id          INT(11) NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  base_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_designation_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN IF NOT EXISTS designation VARCHAR(100) NULL DEFAULT NULL AFTER position;
