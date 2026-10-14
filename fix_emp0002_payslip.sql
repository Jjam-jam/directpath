-- Correct EMP-0002's existing October 1-15, 2025 payslip.
-- This app reads approved SOA loans from loan_disclosures and their
-- loan_disclosure_payments; loan_applications alone do not feed payslips.

START TRANSACTION;

-- Approve the existing EMP-0002 loan disclosure shown in the supplied database.
UPDATE loan_disclosures
SET status = 'approved',
    loan_status = 'active',
    deduction_schedule = '15th',
    outstanding_balance = 41666.66,
    next_deduction_date = '2025-10-15',
    hr_remarks = 'Approved for payroll deduction.',
    reviewed_by = 'AD-0001',
    reviewed_at = '2025-10-01 09:00:00'
WHERE employee_id = 'EMP-0002'
  AND reference_no = 'DISC-EMP-0002-2025';

-- Make this correction repeatable without duplicating the monthly payment.
DELETE ldp
FROM loan_disclosure_payments ldp
JOIN loan_disclosures ld ON ld.id = ldp.disclosure_id
WHERE ld.employee_id = 'EMP-0002'
  AND ld.reference_no = 'DISC-EMP-0002-2025'
  AND ldp.payment_month = '2025-10';

-- Add the loan repayment to the exact released payslip shown in the screenshot.
-- Adjust the existing totals by the difference, so rerunning this stays safe.
UPDATE payroll
SET total_deductions = total_deductions + (4166.67 - COALESCE(loan_deduction, 0)),
    net_salary = net_salary - (4166.67 - COALESCE(loan_deduction, 0)),
    loan_deduction = 4166.67
WHERE employee_id = 'EMP-0002'
  AND payroll_period_start = '2025-10-01'
  AND payroll_period_end = '2025-10-15'
  AND status = 'released';

INSERT INTO loan_disclosure_payments
  (disclosure_id, payroll_id, payment_month, payment_date, amount, balance_after)
SELECT ld.id, p.id, '2025-10', '2025-10-15', 4166.67, 37499.99
FROM loan_disclosures ld
JOIN payroll p ON p.employee_id = ld.employee_id
WHERE ld.employee_id = 'EMP-0002'
  AND ld.reference_no = 'DISC-EMP-0002-2025'
  AND p.payroll_period_start = '2025-10-01'
  AND p.payroll_period_end = '2025-10-15'
  AND p.status = 'released';

UPDATE loan_disclosures
SET outstanding_balance = 37499.99,
    next_deduction_date = '2025-11-15'
WHERE employee_id = 'EMP-0002'
  AND reference_no = 'DISC-EMP-0002-2025';

COMMIT;
