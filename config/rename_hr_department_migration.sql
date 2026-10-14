-- Rename the HR department "Employee Relations / HR" to "Human Resources".
-- Run once in phpMyAdmin on directpath_db (safe to run twice). Back up first.

UPDATE departments
SET name = 'Human Resources'
WHERE name = 'Employee Relations / HR'
  AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM departments WHERE name = 'Human Resources') x);

UPDATE users SET department = 'Human Resources'
WHERE department IN ('Employee Relations / HR', 'Employee Relations &amp; HR');
