-- =====================================================================
-- Repair missed recurring loan deductions (safe to run more than once)
-- MySQL 8 / MariaDB 10.4+
--
-- What it does, per approved loan (loan_disclosures.status = 'approved'):
--   1. Normalizes deduction_schedule spellings to '15th' / 'end_of_month'.
--   2. Deletes repayment rows whose payroll no longer exists and gives the
--      amount back to the loan (they blocked the month and lowered the balance).
--   3. Replays the loan in date order over the employee's payrolls:
--        starting balance = current balance + every recorded repayment
--      For each 'generated' or 'released' payroll whose PERIOD END matches the
--      schedule (day <= 15 -> '15th', day > 15 -> 'end_of_month'), on/after the
--      loan start date, in a month with no repayment yet, it deducts
--        LEAST(monthly amortization, remaining balance, payroll net pay)
--      and updates payroll.loan_deduction / total_deductions / net_salary.
--   4. Re-writes balance_after on every repayment, the loan's outstanding
--      balance and status (fully_paid at zero), and clears saved payslip loan
--      snapshots so payslips rebuild their loan table from payment history.
--   5. Refreshes payroll_approvals deduction / net totals of touched periods.
-- A second run finds every month already paid and changes nothing.
-- Every inserted repayment is logged in loan_deduction_repair_log.
--
-- Optional: limit to one employee by setting @only_employee, e.g.
--   SET @only_employee = 'EMP-0002';
-- Leave it NULL to repair everyone.
-- =====================================================================

SET @only_employee = IFNULL(@only_employee, NULL);

CREATE TABLE IF NOT EXISTS loan_deduction_repair_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    disclosure_id INT NOT NULL,
    payroll_id    INT NOT NULL,
    employee_id   VARCHAR(20) NOT NULL,
    payment_month CHAR(7) NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    repaired_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP PROCEDURE IF EXISTS repair_missed_loan_deductions;

DELIMITER $$
CREATE PROCEDURE repair_missed_loan_deductions(IN p_only_employee VARCHAR(20))
BEGIN
    DECLARE v_loan INT DEFAULT 0;
    DECLARE v_emp VARCHAR(20);
    DECLARE v_sched VARCHAR(20);
    DECLARE v_start DATE;
    DECLARE v_amort DECIMAL(12,2);
    DECLARE v_run DECIMAL(12,2);
    DECLARE v_pid INT;
    DECLARE v_pe DATE;
    DECLARE v_prev_pe DATE;
    DECLARE v_status VARCHAR(20);
    DECLARE v_net DECIMAL(12,2);
    DECLARE v_month CHAR(7);
    DECLARE v_cnt INT;
    DECLARE v_paid DECIMAL(12,2);
    DECLARE v_manual DECIMAL(12,2);
    DECLARE v_amt DECIMAL(12,2);
    DECLARE v_first_change DATE;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    -- 1. Canonical schedule values
    UPDATE loan_disclosures
    SET deduction_schedule = CASE
        WHEN LOWER(TRIM(deduction_schedule)) IN ('15th','15','every 15th','every_15th','first_cutoff','first cutoff','1st_cutoff','1st cutoff','mid_month','mid-month','midmonth') THEN '15th'
        ELSE 'end_of_month' END
    WHERE deduction_schedule NOT IN ('15th','end_of_month')
      AND (p_only_employee IS NULL OR employee_id = p_only_employee COLLATE utf8mb4_unicode_ci);

    -- 2. Orphaned repayments (payroll row deleted): give the money back to the loan
    UPDATE loan_disclosures ld
    JOIN (SELECT ldp.disclosure_id, SUM(ldp.amount) amt
          FROM loan_disclosure_payments ldp
          LEFT JOIN payroll p ON p.id = ldp.payroll_id
          WHERE ldp.payroll_id IS NOT NULL AND p.id IS NULL
          GROUP BY ldp.disclosure_id) o ON o.disclosure_id = ld.id
    SET ld.outstanding_balance = ld.outstanding_balance + o.amt
    WHERE (p_only_employee IS NULL OR ld.employee_id = p_only_employee COLLATE utf8mb4_unicode_ci);

    DELETE ldp FROM loan_disclosure_payments ldp
    JOIN loan_disclosures ld ON ld.id = ldp.disclosure_id
    LEFT JOIN payroll p ON p.id = ldp.payroll_id
    WHERE ldp.payroll_id IS NOT NULL AND p.id IS NULL
      AND (p_only_employee IS NULL OR ld.employee_id = p_only_employee COLLATE utf8mb4_unicode_ci);

    DROP TEMPORARY TABLE IF EXISTS tmp_touched_payroll;
    CREATE TEMPORARY TABLE tmp_touched_payroll (payroll_id INT PRIMARY KEY);

    -- 3. Replay every approved loan
    loan_loop: LOOP
        SET v_emp = NULL;
        SELECT id, employee_id, deduction_schedule, loan_start_date, monthly_amortization
          INTO v_loan, v_emp, v_sched, v_start, v_amort
        FROM loan_disclosures
        WHERE status = 'approved' AND id > v_loan
          AND (p_only_employee IS NULL OR employee_id = p_only_employee COLLATE utf8mb4_unicode_ci)
        ORDER BY id LIMIT 1
        FOR UPDATE;
        IF v_emp IS NULL THEN LEAVE loan_loop; END IF;

        SELECT ld.outstanding_balance + COALESCE((SELECT SUM(amount) FROM loan_disclosure_payments WHERE disclosure_id = ld.id), 0)
          INTO v_run
        FROM loan_disclosures ld WHERE ld.id = v_loan;

        SET v_pid = 0, v_pe = '1000-01-01', v_prev_pe = '1000-01-01', v_first_change = NULL;

        payroll_loop: LOOP
            SET v_status = NULL;
            SELECT id, payroll_period_end, status, net_salary
              INTO v_pid, v_pe, v_status, v_net
            FROM payroll
            WHERE employee_id = v_emp COLLATE utf8mb4_unicode_ci
              AND (payroll_period_end > v_pe OR (payroll_period_end = v_pe AND id > v_pid))
            ORDER BY payroll_period_end, id LIMIT 1
            FOR UPDATE;
            IF v_status IS NULL THEN LEAVE payroll_loop; END IF;

            -- manual repayments (no payroll) made since the previous payroll
            SELECT COALESCE(SUM(amount), 0) INTO v_manual
            FROM loan_disclosure_payments
            WHERE disclosure_id = v_loan AND payroll_id IS NULL
              AND payment_date > v_prev_pe AND payment_date <= v_pe;
            SET v_run = v_run - v_manual;
            SET v_prev_pe = v_pe;

            SET v_month = DATE_FORMAT(v_pe, '%Y-%m');
            SELECT COUNT(*), COALESCE(SUM(amount), 0) INTO v_cnt, v_paid
            FROM loan_disclosure_payments WHERE disclosure_id = v_loan AND payroll_id = v_pid;

            IF v_cnt > 0 THEN
                SET v_run = v_run - v_paid;
                UPDATE loan_disclosure_payments SET balance_after = GREATEST(0, v_run)
                WHERE disclosure_id = v_loan AND payroll_id = v_pid;
            ELSEIF v_status IN ('generated', 'released')
               AND v_run > 0
               AND (v_start IS NULL OR v_pe >= v_start)
               AND ((v_sched = '15th' AND DAY(v_pe) <= 15) OR (v_sched = 'end_of_month' AND DAY(v_pe) > 15))
               AND NOT EXISTS (SELECT 1 FROM loan_disclosure_payments WHERE disclosure_id = v_loan AND payment_month = v_month COLLATE utf8mb4_unicode_ci)
            THEN
                SET v_amt = ROUND(LEAST(v_amort, v_run, GREATEST(v_net, 0)), 2);
                IF v_amt > 0 THEN
                    SET v_run = v_run - v_amt;
                    INSERT INTO loan_disclosure_payments (disclosure_id, payroll_id, payment_month, payment_date, amount, balance_after)
                    VALUES (v_loan, v_pid, v_month, v_pe, v_amt, GREATEST(0, v_run));
                    UPDATE payroll
                    SET loan_deduction   = COALESCE(loan_deduction, 0) + v_amt,
                        total_deductions = total_deductions + v_amt,
                        net_salary       = net_salary - v_amt
                    WHERE id = v_pid;
                    INSERT INTO loan_deduction_repair_log (disclosure_id, payroll_id, employee_id, payment_month, amount, balance_after)
                    VALUES (v_loan, v_pid, v_emp, v_month, v_amt, GREATEST(0, v_run));
                    IF v_first_change IS NULL THEN SET v_first_change = v_pe; END IF;
                END IF;
            END IF;
        END LOOP payroll_loop;

        -- manual repayments after the last payroll
        SELECT COALESCE(SUM(amount), 0) INTO v_manual
        FROM loan_disclosure_payments
        WHERE disclosure_id = v_loan AND payroll_id IS NULL AND payment_date > v_prev_pe;
        SET v_run = GREATEST(0, v_run - v_manual);

        UPDATE loan_disclosures
        SET outstanding_balance = v_run,
            loan_status = IF(v_run <= 0, 'fully_paid', 'active'),
            next_deduction_date = IF(v_run <= 0, NULL,
                IF(v_sched = '15th',
                   IF(DAY(CURDATE()) <= 15, DATE_FORMAT(CURDATE(), '%Y-%m-15'), DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-15')),
                   LAST_DAY(CURDATE())))
        WHERE id = v_loan;

        -- payslips from the first repaired cutoff onward rebuild their loan table from payment history
        IF v_first_change IS NOT NULL THEN
            INSERT IGNORE INTO tmp_touched_payroll (payroll_id)
            SELECT id FROM payroll WHERE employee_id = v_emp COLLATE utf8mb4_unicode_ci AND payroll_period_end >= v_first_change;
        END IF;
    END LOOP loan_loop;

    UPDATE payroll p JOIN tmp_touched_payroll t ON t.payroll_id = p.id
    SET p.loan_balance_snapshot = NULL;

    -- 5. Period totals shown to CEO / approvals
    UPDATE payroll_approvals pa
    JOIN (SELECT p.payroll_period_start s, p.payroll_period_end e,
                 SUM(p.total_deductions) ded, SUM(p.net_salary) net
          FROM payroll p
          WHERE (p.payroll_period_start, p.payroll_period_end) IN (
                SELECT p2.payroll_period_start, p2.payroll_period_end
                FROM payroll p2 JOIN tmp_touched_payroll t ON t.payroll_id = p2.id)
          GROUP BY p.payroll_period_start, p.payroll_period_end) x
      ON x.s = pa.period_start AND x.e = pa.period_end
    SET pa.deduction_total = x.ded, pa.net_total = x.net;

    DROP TEMPORARY TABLE IF EXISTS tmp_touched_payroll;
    COMMIT;
END$$
DELIMITER ;

CALL repair_missed_loan_deductions(@only_employee);
DROP PROCEDURE IF EXISTS repair_missed_loan_deductions;

-- ---------------------------------------------------------------------
-- Check the result (EMP-0002 example):
-- SELECT p.payroll_period_start, p.payroll_period_end, p.status, p.loan_deduction,
--        p.total_deductions, p.net_salary, ldp.amount, ldp.balance_after
-- FROM payroll p
-- LEFT JOIN loan_disclosure_payments ldp ON ldp.payroll_id = p.id
-- WHERE p.employee_id = 'EMP-0002' ORDER BY p.payroll_period_end;
-- SELECT * FROM loan_deduction_repair_log ORDER BY id DESC;
-- ---------------------------------------------------------------------
