-- Create approved active loans and released payslips for the ten listed employees.
-- Uses the loan_disclosures / loan_disclosure_payments tables read by this app.
-- The selected payroll period is Sep 16-30, 2026; its scheduled pay date was Oct 5, 2026.

START TRANSACTION;

-- Remove placeholder wording from the previously loaded employee loan disclosures.
UPDATE loan_disclosures
SET provider = 'Pag-IBIG Fund / SSS',
    remarks = 'Employee submitted existing loan details for payroll coordination.',
    hr_remarks = 'Verified against the submitted loan statement.'
WHERE id BETWEEN 55075 AND 55083
  AND provider = 'Pag-IBIG Fund / SSS (sample)';

DROP TEMPORARY TABLE IF EXISTS tmp_loan_payroll_import;
CREATE TEMPORARY TABLE tmp_loan_payroll_import (
  employee_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY,
  reference_no VARCHAR(100) NOT NULL,
  provider VARCHAR(150) NOT NULL,
  loan_type VARCHAR(100) NOT NULL,
  loan_amount DECIMAL(12,2) NOT NULL,
  monthly_deduction DECIMAL(12,2) NOT NULL,
  term_months INT NOT NULL,
  purpose VARCHAR(255) NOT NULL,
  allowance_override DECIMAL(12,2) NULL,
  deduction_schedule VARCHAR(20) NOT NULL
);

INSERT INTO tmp_loan_payroll_import VALUES
('EMP-0001','DP-LOAN-2026-0001','Social Security System','Salary Loan',30000.00,1733.33,18,'Home appliance and household repair',NULL,'end_of_month'),
('EMP-0002','DP-LOAN-2026-0002','Home Development Mutual Fund','Multipurpose Loan',50000.00,2166.67,24,'Education expenses',NULL,'end_of_month'),
('EMP-0003','DP-LOAN-2026-0003','Social Security System','Salary Loan',40000.00,2311.11,18,'Medical expenses',NULL,'end_of_month'),
('EMP-0004','DP-LOAN-2026-0004','Social Security System','Calamity Loan',25000.00,1444.44,18,'Typhoon-related home repairs',NULL,'end_of_month'),
('EMP-0005','DP-LOAN-2026-0005','Home Development Mutual Fund','Multipurpose Loan',60000.00,2600.00,24,'Family relocation expenses',NULL,'end_of_month'),
('EMP-0007','DP-LOAN-2026-0006','Social Security System','Salary Loan',35000.00,2022.22,18,'Appliance replacement',NULL,'end_of_month'),
('EMP-0008','DP-LOAN-2026-0007','Home Development Mutual Fund','Multipurpose Loan',80000.00,3466.67,24,'Home improvement',NULL,'end_of_month'),
('EMP-0009','DP-LOAN-2026-0008','Social Security System','Salary Loan',45000.00,2600.00,18,'Medical and family support',NULL,'end_of_month'),
('EMP-0101','DP-LOAN-2026-0009','Social Security System','Calamity Loan',20000.00,1733.33,12,'Emergency home repairs',1500.00,'end_of_month'),
('EMP-0102','DP-LOAN-2026-0010','Home Development Mutual Fund','Multipurpose Loan',40000.00,1733.33,24,'Education and certification costs',1750.00,'end_of_month');

-- Remove rows created by the earlier import version, which used the separate application table.
DELETE ld FROM loan_deductions ld
JOIN loan_applications la ON la.id = ld.loan_id
WHERE la.loan_no LIKE 'LN-2026-%';
DELETE FROM loan_applications WHERE loan_no LIKE 'LN-2026-%';
DELETE FROM payroll
WHERE payroll_period_start = '2026-10-01'
  AND payroll_period_end = '2026-10-15'
  AND generated_by = 'Loan Repayment Payroll Import';

-- Clean up only a previous run of this corrected import.
DELETE ldp FROM loan_disclosure_payments ldp
JOIN loan_disclosures d ON d.id = ldp.disclosure_id
WHERE d.reference_no LIKE 'DP-LOAN-2026-%';
DELETE FROM loan_disclosures WHERE reference_no LIKE 'DP-LOAN-2026-%';
DELETE pai FROM payroll_approval_items pai
JOIN payroll_approvals pa ON pa.id = pai.approval_id
WHERE pa.period_start = '2026-09-16' AND pa.period_end = '2026-09-30'
  AND pa.submitted_by_name = 'Loan Payroll Import';
DELETE FROM payroll_approvals
WHERE period_start = '2026-09-16' AND period_end = '2026-09-30'
  AND submitted_by_name = 'Loan Payroll Import';

-- Create active, approved SOA loans. The first monthly payment is recorded below.
INSERT INTO loan_disclosures
  (employee_id, provider, loan_type, reference_no, original_amount, outstanding_balance,
   monthly_amortization, loan_start_date, term_months, deduction_schedule, remarks,
   status, hr_remarks, reviewed_by, reviewed_at, loan_status, next_deduction_date, submitted_at)
SELECT d.employee_id, d.provider, d.loan_type, d.reference_no, d.loan_amount,
       ROUND(d.loan_amount * 1.04, 2), d.monthly_deduction, '2026-09-01', d.term_months,
       d.deduction_schedule, d.purpose, 'approved', 'Approved for payroll repayment under standard terms.',
       'AD-0001', '2026-08-28 09:00:00', 'active', '2026-09-30', '2026-08-25 09:00:00'
FROM tmp_loan_payroll_import d
JOIN users u ON BINARY u.employee_id = BINARY d.employee_id
WHERE u.status = 'active' AND u.role = 'employee';

-- Create or refresh the ten released Sep 16-30 payslips.
-- Monthly loan amortization is deducted once on its selected cutoff:
-- first cutoff (period end on/before the 15th), or second cutoff (period end after the 15th).
INSERT INTO payroll
  (employee_id, payroll_period_start, payroll_period_end, basic_salary, allowances,
   overtime_pay, bonuses, de_minimis, gross_pay, sss, philhealth, pagibig,
   withholding_tax, other_deductions, total_deductions, net_salary, days_worked,
   overtime_hours, late_deduction, late_minutes, undertime_minutes, lwp_days,
   lwp_deduction, undertime_deduction, status, released_at, released_by_name,
   generated_by, loan_deduction, salary_adjustment, thirteenth_month_pay,
   absent_days, absence_deduction)
SELECT u.employee_id, '2026-09-16', '2026-09-30',
       ROUND(u.basic_salary / 2, 2), ROUND(COALESCE(d.allowance_override, u.allowances) / 2, 2),
       0.00, 0.00, 0.00,
       ROUND((u.basic_salary + COALESCE(d.allowance_override, u.allowances)) / 2, 2),
       ROUND(LEAST(u.basic_salary / 2 * 0.045, 1350.00), 2),
       ROUND(u.basic_salary / 2 * 0.015, 2), 100.00,
       ROUND(GREATEST((u.basic_salary / 2 - 10000) * 0.05, 0), 2),
       0.00,
       ROUND(LEAST(u.basic_salary / 2 * 0.045, 1350.00)
             + u.basic_salary / 2 * 0.015 + 100.00
             + GREATEST((u.basic_salary / 2 - 10000) * 0.05, 0)
             + CASE WHEN (d.deduction_schedule = '15th' AND DAY('2026-09-30') <= 15)
                         OR (d.deduction_schedule = 'end_of_month' AND DAY('2026-09-30') > 15)
                    THEN LEAST(d.monthly_deduction, ROUND(d.loan_amount * 1.04, 2)) ELSE 0 END, 2),
       ROUND((u.basic_salary + COALESCE(d.allowance_override, u.allowances)) / 2
             - (LEAST(u.basic_salary / 2 * 0.045, 1350.00)
                + u.basic_salary / 2 * 0.015 + 100.00
                + GREATEST((u.basic_salary / 2 - 10000) * 0.05, 0)
                + CASE WHEN (d.deduction_schedule = '15th' AND DAY('2026-09-30') <= 15)
                            OR (d.deduction_schedule = 'end_of_month' AND DAY('2026-09-30') > 15)
                       THEN LEAST(d.monthly_deduction, ROUND(d.loan_amount * 1.04, 2)) ELSE 0 END), 2),
       15, 0.00, 0.00, 0, 0, 0.00, 0.00, 0.00,
       'released', '2026-10-05 09:00:00', 'Payroll Manager',
       'Loan Payroll Import',
       CASE WHEN (d.deduction_schedule = '15th' AND DAY('2026-09-30') <= 15)
                 OR (d.deduction_schedule = 'end_of_month' AND DAY('2026-09-30') > 15)
            THEN LEAST(d.monthly_deduction, ROUND(d.loan_amount * 1.04, 2)) ELSE 0 END,
       0.00, 0.00, 0, 0.00
FROM tmp_loan_payroll_import d
JOIN users u ON BINARY u.employee_id = BINARY d.employee_id
WHERE u.status = 'active' AND u.role = 'employee'
ON DUPLICATE KEY UPDATE
  basic_salary = VALUES(basic_salary), allowances = VALUES(allowances),
  overtime_pay = VALUES(overtime_pay), bonuses = VALUES(bonuses),
  de_minimis = VALUES(de_minimis), gross_pay = VALUES(gross_pay),
  sss = VALUES(sss), philhealth = VALUES(philhealth), pagibig = VALUES(pagibig),
  withholding_tax = VALUES(withholding_tax), other_deductions = VALUES(other_deductions),
  total_deductions = VALUES(total_deductions), net_salary = VALUES(net_salary),
  days_worked = VALUES(days_worked), status = VALUES(status),
  released_at = VALUES(released_at), released_by_name = VALUES(released_by_name),
  generated_by = VALUES(generated_by), loan_deduction = VALUES(loan_deduction),
  loan_balance_snapshot = NULL, updated_at = NOW();

-- Link each repayment to both its loan disclosure and its payslip.
INSERT INTO loan_disclosure_payments
  (disclosure_id, payroll_id, payment_month, payment_date, amount, balance_after)
SELECT d.id, p.id, '2026-09', '2026-09-30',
       LEAST(d.monthly_amortization, d.outstanding_balance),
       GREATEST(0, ROUND(d.outstanding_balance - LEAST(d.monthly_amortization, d.outstanding_balance), 2))
FROM loan_disclosures d
JOIN payroll p ON BINARY p.employee_id = BINARY d.employee_id
WHERE d.reference_no LIKE 'DP-LOAN-2026-%'
  AND p.payroll_period_start = '2026-09-16'
  AND p.payroll_period_end = '2026-09-30'
  AND d.loan_start_date <= p.payroll_period_end
  AND ((d.deduction_schedule = '15th' AND DAY(p.payroll_period_end) <= 15)
       OR (d.deduction_schedule = 'end_of_month' AND DAY(p.payroll_period_end) > 15))
  AND NOT EXISTS (
      SELECT 1 FROM loan_disclosure_payments old
      WHERE old.disclosure_id = d.id AND old.payment_month = DATE_FORMAT(p.payroll_period_end, '%Y-%m')
  );

UPDATE loan_disclosures d
JOIN loan_disclosure_payments p ON p.disclosure_id = d.id
SET d.outstanding_balance = p.balance_after,
    d.loan_status = IF(p.balance_after <= 0, 'fully_paid', 'active'),
    d.next_deduction_date = IF(p.balance_after <= 0, NULL,
        IF(d.deduction_schedule = '15th', '2026-10-15', LAST_DAY('2026-10-01')))
WHERE d.reference_no LIKE 'DP-LOAN-2026-%'
  AND p.payment_month = '2026-09';

-- The application hides employee payslips unless the period has an approved payroll record.
INSERT INTO payroll_approvals
  (period_start, period_end, status, employee_count, gross_total, bonus_total,
   adjustment_total, thirteenth_total, deduction_total, employer_total, net_total,
   submitted_by, submitted_by_name, submitted_at, reviewed_by, reviewed_by_name,
   reviewed_at, reject_reason)
SELECT '2026-09-16', '2026-09-30', 'approved', COUNT(*), SUM(gross_pay), SUM(bonuses),
       SUM(salary_adjustment), SUM(thirteenth_month_pay), SUM(total_deductions),
       0.00, SUM(net_salary), 'AD-0001', 'Payroll Manager', '2026-10-01 09:00:00',
       'CEO-001', 'Chief Executive Officer', '2026-10-02 09:00:00', NULL
FROM payroll
WHERE payroll_period_start = '2026-09-16'
  AND payroll_period_end = '2026-09-30'
  AND generated_by = 'Loan Payroll Import';

DROP TEMPORARY TABLE tmp_loan_payroll_import;
COMMIT;
