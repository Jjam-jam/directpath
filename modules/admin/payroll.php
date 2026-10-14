<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('payroll_manager', 'superadmin');

$pageTitle  = 'Payroll Management';
$activePage = 'payroll';
$db = getDB();
payrollReleaseDue($db);

$success = '';
$error   = '';

// ── Handle POST Actions ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !csrfTokenIsValid($_POST['_csrf_token'] ?? null)) {
    http_response_code(403);
    $error = 'Your session validation failed. Refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'save_previous_employer_ytd') {
        $employeeId = sanitize($_POST['employee_id'] ?? '');
        $yearInput = $_POST['tax_year'] ?? '';
        $year = filter_var($yearInput, FILTER_VALIDATE_INT);
        $amountFields = [
            'taxable_income' => 'previous_employer_taxable_income',
            'non_taxable_income' => 'previous_employer_non_taxable_income',
            'sss' => 'previous_employer_sss',
            'philhealth' => 'previous_employer_philhealth',
            'hdmf' => 'previous_employer_hdmf',
            'withholding_tax' => 'previous_employer_withholding_tax',
        ];
        $amounts = [];
        $validAmounts = true;
        foreach ($amountFields as $field => $inputName) {
            $input = $_POST[$inputName] ?? null;
            if (!is_string($input)) {
                $validAmounts = false;
                break;
            }
            try {
                $cents = GovernmentContributionEngine::toCents($input);
                if ($cents < 0) {
                    $validAmounts = false;
                    break;
                }
                $amounts[$field] = (string)GovernmentContributionEngine::fromCents($cents);
            } catch (InvalidArgumentException $e) {
                $validAmounts = false;
                break;
            }
        }

        if (!$employeeId || $year === false || $year < 2000 || $year > (int)date('Y') || !$validAmounts) {
            $error = 'Select an employee and valid tax year, and enter non-negative amounts with at most two decimal places.';
        } else {
            $db->beginTransaction();
            try {
                $employeeStmt = $db->prepare("SELECT employee_id FROM users WHERE employee_id=? AND role='employee' FOR UPDATE");
                $employeeStmt->execute([$employeeId]);
                if (!$employeeStmt->fetchColumn()) {
                    throw new DomainException('The selected employee could not be found.');
                }
                $previousStmt = $db->prepare("
                    SELECT taxable_income, non_taxable_income, sss, philhealth, hdmf, withholding_tax
                    FROM payroll_previous_employer_ytd
                    WHERE employee_id=? AND tax_year=? FOR UPDATE
                ");
                $previousStmt->execute([$employeeId, $year]);
                $previousValues = $previousStmt->fetch() ?: null;
                $openRequest = $db->prepare("SELECT id FROM payroll_change_requests WHERE change_type='ytd' AND employee_id=? AND reference_year=? AND status IN ('pending_ceo','approved_pending_release') LIMIT 1");
                $openRequest->execute([$employeeId, $year]);
                if ($openRequest->fetchColumn()) throw new DomainException('A YTD change for this employee and tax year is already awaiting approval or release.');
                $payload = ['before' => $previousValues, 'after' => $amounts, 'reason' => 'Previous employer YTD update'];
                $db->prepare("INSERT INTO payroll_change_requests (change_type, employee_id, reference_year, payload, submitted_by, submitted_by_name) VALUES ('ytd',?,?,?,?,?)")
                   ->execute([$employeeId, $year, json_encode($payload, JSON_THROW_ON_ERROR), $_SESSION['employee_id'], $_SESSION['name']]);
                $changeId = (int)$db->lastInsertId();
                if (!auditLog($previousValues ? 'Submit Previous Employer YTD Change' : 'Submit Previous Employer YTD', 'Payroll', "Change request #$changeId | Employee: $employeeId | Tax year: $year | New values: " . json_encode($amounts, JSON_THROW_ON_ERROR))) {
                    throw new RuntimeException('Audit log write failed.');
                }
                createNotification($db, "Previous employer YTD change for $employeeId needs your review.", 'modules/ceo/payroll_approval.php?change=' . $changeId, null, 'ceo');
                $db->commit();
                $success = 'Previous employer YTD change submitted for CEO approval. HR will apply it after release.';
            } catch (DomainException $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = $e->getMessage();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Previous employer YTD change submission failed: ' . $e->getMessage());
                $error = 'Previous employer amounts were not saved because the audit or database update failed.';
            }
        }
    }

    if ($action === 'update_compensation') {
        $empId = sanitize($_POST['employee_id'] ?? '');
        $basicInput = $_POST['basic_salary'] ?? null;
        $allowancesInput = $_POST['allowances'] ?? null;
        $validAmounts = is_string($basicInput) && is_string($allowancesInput);
        $basicSalary = 0.0;
        $allowances = 0.0;
        if ($validAmounts) {
            try {
                $basicCents = GovernmentContributionEngine::toCents($basicInput);
                $allowanceCents = GovernmentContributionEngine::toCents($allowancesInput);
                $validAmounts = $basicCents > 0;
                $basicSalary = (float)GovernmentContributionEngine::fromCents($basicCents);
                $allowances = (float)GovernmentContributionEngine::fromCents($allowanceCents);
            } catch (InvalidArgumentException $e) {
                $validAmounts = false;
            }
        }
        $reason = sanitize($_POST['reason'] ?? '');
        $oldStmt = $db->prepare("SELECT basic_salary, allowances FROM users WHERE employee_id=? AND role='employee' AND status='active'");
        $oldStmt->execute([$empId]);
        $old = $oldStmt->fetch();

        if (!$old || !$validAmounts || !$reason) {
            $error = 'Select an active employee, enter a positive basic salary and non-negative amounts with at most two decimal places, and provide a reason.';
        } else {
            $db->beginTransaction();
            try {
                $openRequest = $db->prepare("SELECT id FROM payroll_change_requests WHERE change_type='compensation' AND employee_id=? AND status IN ('pending_ceo','approved_pending_release') LIMIT 1");
                $openRequest->execute([$empId]);
                if ($openRequest->fetchColumn()) throw new DomainException('A compensation change for this employee is already awaiting approval or release.');
                $payload = [
                    'before' => ['basic_salary' => (string)$old['basic_salary'], 'allowances' => (string)$old['allowances']],
                    'after' => ['basic_salary' => number_format($basicSalary, 2, '.', ''), 'allowances' => number_format($allowances, 2, '.', '')],
                    'reason' => $reason,
                ];
                $db->prepare("INSERT INTO payroll_change_requests (change_type, employee_id, payload, submitted_by, submitted_by_name) VALUES ('compensation',?,?,?,?)")
                   ->execute([$empId, json_encode($payload, JSON_THROW_ON_ERROR), $_SESSION['employee_id'], $_SESSION['name']]);
                $changeId = (int)$db->lastInsertId();
                if (!auditLog('Submit Compensation Change', 'Payroll', "Change request #$changeId | Employee: $empId | Basic salary: {$old['basic_salary']} -> $basicSalary | Allowances: {$old['allowances']} -> $allowances | Reason: $reason")) {
                    throw new RuntimeException('Audit log write failed.');
                }
                createNotification($db, "Compensation change for $empId needs your review.", 'modules/ceo/payroll_approval.php?change=' . $changeId, null, 'ceo');
                $db->commit();
                $success = 'Compensation change submitted for CEO approval. HR will apply it after release.';
            } catch (DomainException $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = $e->getMessage();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Compensation was not changed because the audit record could not be written.';
            }
        }
    }

    // GENERATE PAYROLL
    if ($action === 'generate') {
        $empId    = sanitize($_POST['employee_id'] ?? '');
        $pStart   = sanitize($_POST['period_start'] ?? '');
        $pEnd     = sanitize($_POST['period_end'] ?? '');
        $bonusInput = $_POST['bonuses'] ?? '0';
        $deMinimisInput = $_POST['de_minimis'] ?? '0';
        $otherDeductionInput = $_POST['other_deductions'] ?? '0';
        $validAmounts = is_string($bonusInput) && is_string($deMinimisInput) && is_string($otherDeductionInput);
        $bonuses = 0.0;
        $deMinimis = 0.0;
        $otherDed = 0.0;
        if ($validAmounts) {
            try {
                $bonuses = (float)GovernmentContributionEngine::fromCents(GovernmentContributionEngine::toCents($bonusInput));
                $deMinimis = (float)GovernmentContributionEngine::fromCents(GovernmentContributionEngine::toCents($deMinimisInput));
                $otherDed = (float)GovernmentContributionEngine::fromCents(GovernmentContributionEngine::toCents($otherDeductionInput));
            } catch (InvalidArgumentException $e) {
                $validAmounts = false;
            }
        }

        // Single or all employees
        if ($empId === 'all') {
            $listStmt = $db->prepare("SELECT * FROM users WHERE role='employee' AND status='active'");
            $listStmt->execute();
            $empList = $listStmt->fetchAll();
        } else {
            $listStmt = $db->prepare("SELECT * FROM users WHERE employee_id=? AND role='employee' AND status='active'");
            $listStmt->execute([$empId]);
            $empList = $listStmt->fetchAll();
        }

        if (!$validAmounts) {
            $error = 'Bonus, De Minimis, and other-deduction amounts must be non-negative and have at most two decimal places.';
        } elseif (!GovernmentContributionEngine::isValidDate($pStart) || !GovernmentContributionEngine::isValidDate($pEnd) || $pEnd < $pStart) {
            $error = 'Enter a valid payroll period with an end date on or after the start date.';
        } elseif (($periodApproval = getPayrollApproval($db, $pStart, $pEnd)) && in_array($periodApproval['status'], ['pending_ceo', 'approved'], true)) {
            $error = 'This payroll period is already awaiting CEO approval or has been approved. HR must release it before payroll can be changed.';
        } elseif (empty($empList)) {
            $error = 'No employees found.';
        } else {
            $generated = 0;
            $skipped   = 0;
            $generatedFor = [];   // 'Name (ID)' of every employee a payroll was generated for
            foreach ($empList as $emp) {
                $stmt = $db->prepare("SELECT date, time_in, time_out, hours_worked, late_minutes, undertime_mins, status
                                      FROM attendance WHERE employee_id=? AND date BETWEEN ? AND ? ORDER BY date");
                $stmt->execute([$emp['employee_id'], $pStart, $pEnd]);
                $attendanceRows = $stmt->fetchAll();
                if (empty($emp['basic_salary']) || (float)$emp['basic_salary'] <= 0) {
                    $skipped++;
                    $error = "Cannot generate payroll for {$emp['first_name']} {$emp['last_name']}: Basic salary is not set.";
                    continue;
                }
                $basicSalary = (float)$emp['basic_salary'];
                $approvedLeaveRanges = approvedLeaveRangesForPeriod($db, (string)$emp['employee_id'], $pStart, $pEnd);
                $attendanceDed = computeAttendanceDeductionsForRecords($basicSalary, $attendanceRows, $approvedLeaveRanges);
                $daysWorked = $attendanceDed['days_worked'];
                if ($daysWorked === 0) { $skipped++; continue; }
                try {
                    $contributions = calculateGovernmentContributions($db, $basicSalary, $pEnd, 2, GovernmentContributionEngine::shareIndexForPayrollDate($pEnd));
                } catch (DomainException|InvalidArgumentException $e) {
                    $skipped++;
                    $error = "Cannot generate payroll for {$emp['first_name']} {$emp['last_name']}: " . $e->getMessage();
                    continue;
                }
                $allowances  = (float)$emp['allowances'];
                $grossPay    = computeGrossPay($basicSalary, $daysWorked, $allowances);
                $sss         = (float)$contributions['SSS']['employee'];
                $sssMpf      = (float)($contributions['SSS']['mpf_employee'] ?? 0);
                $philhealth  = (float)$contributions['PhilHealth']['employee'];
                $pagibig     = (float)$contributions['Pag-IBIG']['employee'];
                $snapshot = json_encode([
                    'payroll_date' => $pEnd,
                    'monthly_salary' => number_format($basicSalary, 2, '.', ''),
                    'contributions' => $contributions,
                ], JSON_THROW_ON_ERROR);
                $sssEmployer = (float)$contributions['SSS']['employer'];
                $sssEcpEmployer = (float)$contributions['SSS']['ecp'];
                $philhealthEmployer = (float)$contributions['PhilHealth']['employer'];
                $pagibigEmployer = (float)$contributions['Pag-IBIG']['employer'];
                $lateDeduction = $attendanceDed['late_deduction'];
                $undertimeDeduction = $attendanceDed['undertime_deduction'];

                $db->beginTransaction();
                try {
                    $chk = $db->prepare("SELECT id, status, COALESCE(loan_deduction, 0) AS loan_deduction,
                                                gross_pay, bonuses, overtime_pay, salary_adjustment,
                                                sss, philhealth, pagibig, loan_balance_snapshot,
                                                withholding_tax, total_deductions, net_salary
                                         FROM payroll WHERE employee_id=? AND payroll_period_start=? AND payroll_period_end=? FOR UPDATE");
                    $chk->execute([$emp['employee_id'], $pStart, $pEnd]);
                    $existingRow = $chk->fetch();
                    if ($existingRow && $existingRow['status'] === 'released') {
                        throw new DomainException("Payroll for {$emp['employee_id']} is already finalized.");
                    }
                    $existing = $existingRow['id'] ?? null;
                    $overtimePay = (float)($existingRow['overtime_pay'] ?? 0);
                    $salaryAdjustment = (float)($existingRow['salary_adjustment'] ?? 0);
                    $taxableIncome = computeTaxableIncome(
                        $grossPay + $bonuses + $overtimePay + $salaryAdjustment,
                        $lateDeduction,
                        $undertimeDeduction,
                        $attendanceDed['lwp_deduction']
                    );
                    $tax = calculateAnnualizedWithholdingTax($db, (string)$emp['employee_id'], $taxableIncome, $pEnd);
                    $deductionsBeforeLoans = round($sss + $philhealth + $pagibig + $tax + $otherDed, 2);
                    $availablePayForLoans = max(0.0, computeNetSalary($taxableIncome, $deMinimis, $deductionsBeforeLoans));
                    // Release months blocked by repayments whose payroll row no longer exists.
                    purgeOrphanLoanPayments($db, (string)$emp['employee_id']);
                    $existingLoanData = $existing
                        ? payrollLoanBalancesForRecord(
                            $db,
                            (int)$existing,
                            $existingRow['loan_balance_snapshot'] ?? null,
                            (float)($existingRow['loan_deduction'] ?? 0)
                        )
                        : null;
                    $loanDetails = $existingLoanData['loans'] ?? [];
                    $loanDeduction = $existing
                        ? $existingLoanData['total_cutoff_deduction']
                        : 0.0;

                    // A generated payroll can be regenerated after HR approves a loan.
                    // Keep its saved deductions, but still look for newly approved loans
                    // that were not part of the earlier snapshot. Released payrolls are
                    // rejected above, so this cannot alter a finalized payslip.
                    $recordedLoanIndexes = [];
                    foreach ($loanDetails as $loanIndex => $recordedLoan) {
                        if (isset($recordedLoan['loan_id'])) {
                            $recordedLoanIndexes[(int)$recordedLoan['loan_id']] = $loanIndex;
                        }
                    }
                    $pendingDisclosurePayments = [];
                    {
                        // Loans come only from approved SOA submissions. Each loan is deducted ONCE a month,
                        // on the cut-off that matches its schedule (15th or end of month).
                        $payMonth   = date('Y-m', strtotime($pEnd));
                        try {
                            $loanStmt = $db->prepare("
                                SELECT id, provider, loan_type, reference_no, original_amount, monthly_amortization,
                                       outstanding_balance, deduction_schedule, loan_start_date
                                FROM loan_disclosures
                                WHERE employee_id=? AND status='approved' AND loan_status='active' AND outstanding_balance > 0
                                ORDER BY id
                                FOR UPDATE
                            ");
                            $loanStmt->execute([$emp['employee_id']]);
                            $disclosedLoans = $loanStmt->fetchAll();
                        } catch (Throwable $e) {
                            error_log('Payroll approved-loan lookup failed: ' . $e->getMessage());
                            throw new RuntimeException('Approved loan deductions could not be loaded. Payroll was not generated; check the loan tables and try again.', 0, $e);
                        }
                        foreach ($disclosedLoans as $loan) {
                            $loanId = (int)$loan['id'];
                            $recordedIndex = $recordedLoanIndexes[$loanId] ?? null;
                            // A snapshot can contain a zero-deduction balance created before the
                            // loan's scheduled cutoff. Preserve it on other cutoffs, but let a
                            // regenerated scheduled cutoff calculate the missing repayment.
                            if ($recordedIndex !== null
                                && (float)($loanDetails[$recordedIndex]['per_cutoff_deduction'] ?? 0) > 0) {
                                continue;
                            }
                            $paymentForThisPayroll = $db->prepare("SELECT 1 FROM loan_disclosure_payments WHERE disclosure_id=? AND payroll_id=? LIMIT 1");
                            $paymentForThisPayroll->execute([$loanId, $existing ?: 0]);
                            if ($paymentForThisPayroll->fetchColumn()) {
                                continue;
                            }
                            $cutoffAmount = 0.0;
                            $matchesCut = loanScheduleMatchesPayrollPeriod((string)($loan['deduction_schedule'] ?? ''), $pEnd);
                            $started    = empty($loan['loan_start_date']) || $loan['loan_start_date'] <= $pEnd;
                            if ($matchesCut && $started) {
                                if (!loanPaymentRecordedForMonth($db, $loanId, $payMonth, $existing ? (int)$existing : null)) {
                                    $cutoffAmount = round(min((float)$loan['monthly_amortization'], (float)$loan['outstanding_balance'], $availablePayForLoans), 2);
                                    if ($cutoffAmount < 0) $cutoffAmount = 0.0;
                                }
                            }
                            $balanceAfter = max(0.0, round((float)$loan['outstanding_balance'] - $cutoffAmount, 2));
                            if ($cutoffAmount > 0) {
                                $loanDeduction = round($loanDeduction + $cutoffAmount, 2);
                                $availablePayForLoans = round($availablePayForLoans - $cutoffAmount, 2);
                                $pendingDisclosurePayments[] = [
                                    'disclosure_id' => (int)$loan['id'], 'month' => $payMonth,
                                    'amount' => $cutoffAmount, 'balance_after' => $balanceAfter,
                                    'schedule' => normalizeLoanSchedule((string)$loan['deduction_schedule']) ?: 'end_of_month',
                                ];
                            }
                            $loanDetails[] = [
                                'loan_id' => (int)$loan['id'],
                                'loan_no' => (string)($loan['reference_no'] ?: ('SOA-' . $loan['id'])),
                                'loan_type' => trim($loan['provider'] . ' ' . $loan['loan_type']),
                                'loan_amount' => (float)$loan['original_amount'],
                                'per_cutoff_deduction' => $cutoffAmount,
                                'remaining_balance' => $balanceAfter,
                            ];
                            if ($recordedIndex !== null) {
                                // Replace an earlier zero-deduction snapshot row instead of
                                // duplicating the loan in the payslip balance table.
                                $loanDetails[$recordedIndex] = array_pop($loanDetails);
                            }
                            $recordedLoanIndexes[$loanId] = $recordedIndex ?? (count($loanDetails) - 1);
                        }
                    }
                    $loanBalanceSnapshot = json_encode([
                        'version' => 1,
                        'loans' => $loanDetails,
                        'unitemized_deduction' => $existingLoanData['unitemized_deduction'] ?? 0.0,
                    ], JSON_THROW_ON_ERROR);
                    $totalDed = round($deductionsBeforeLoans + $loanDeduction, 2);
                    $netPay = computeNetSalary($taxableIncome, $deMinimis, $totalDed);
                    $calculationSnapshot = json_encode([
                        'version' => 1,
                        'daily_rate' => $attendanceDed['rates']['daily'],
                        'hourly_rate' => $attendanceDed['rates']['hourly'],
                        'per_minute_rate' => $attendanceDed['rates']['per_minute'],
                        'late_grace_minutes' => ATT_LATE_GRACE_MINUTES,
                        'rounding_mode' => PAYROLL_DEDUCTION_ROUNDING_MODE,
                        'rounding_block_minutes' => PAYROLL_DEDUCTION_BLOCK_MINUTES,
                        'late_minutes' => $attendanceDed['late_minutes'],
                        'late_billable_minutes' => $attendanceDed['late_billable_minutes'],
                        'undertime_minutes' => $attendanceDed['undertime_minutes'],
                        'undertime_billable_minutes' => $attendanceDed['undertime_billable_minutes'],
                        'lwp_days' => $attendanceDed['lwp_days'],
                        'lwp_deduction' => $attendanceDed['lwp_deduction'],
                        'taxable_income' => $taxableIncome,
                        'de_minimis' => $deMinimis,
                        'sss_regular_employee' => round($sss - $sssMpf, 2),
                        'sss_mpf_employee' => $sssMpf,
                        'daily_rows' => $attendanceDed['daily_rows'],
                    ], JSON_THROW_ON_ERROR);

                    if ($existing) {
                        $stmt = $db->prepare("UPDATE payroll SET basic_salary=?,allowances=?,bonuses=?,de_minimis=?,gross_pay=?,sss=?,sss_mpf=?,philhealth=?,pagibig=?,sss_employer=?,sss_ec_employer=?,philhealth_employer=?,pagibig_employer=?,government_contributions_snapshot=?,payroll_calculation_snapshot=?,loan_balance_snapshot=?,withholding_tax=?,late_deduction=?,undertime_deduction=?,late_minutes=?,undertime_minutes=?,lwp_days=?,lwp_deduction=?,other_deductions=?,loan_deduction=?,total_deductions=?,net_salary=?,days_worked=?,status='generated',generated_by=?,updated_at=NOW() WHERE id=?");
                        $stmt->execute([$basicSalary,$allowances,$bonuses,$deMinimis,$grossPay,$sss,$sssMpf,$philhealth,$pagibig,$sssEmployer,$sssEcpEmployer,$philhealthEmployer,$pagibigEmployer,$snapshot,$calculationSnapshot,$loanBalanceSnapshot,$tax,$lateDeduction,$undertimeDeduction,$attendanceDed['late_minutes'],$attendanceDed['undertime_minutes'],$attendanceDed['lwp_days'],$attendanceDed['lwp_deduction'],$otherDed,$loanDeduction,$totalDed,$netPay,$daysWorked,$_SESSION['name'],$existing]);
                        $payrollId = (int)$existing;
                    } else {
                        $stmt = $db->prepare("INSERT INTO payroll (employee_id,payroll_period_start,payroll_period_end,basic_salary,allowances,bonuses,de_minimis,gross_pay,sss,sss_mpf,philhealth,pagibig,sss_employer,sss_ec_employer,philhealth_employer,pagibig_employer,government_contributions_snapshot,payroll_calculation_snapshot,loan_balance_snapshot,withholding_tax,late_deduction,undertime_deduction,late_minutes,undertime_minutes,lwp_days,lwp_deduction,other_deductions,loan_deduction,total_deductions,net_salary,days_worked,status,generated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'generated',?)");
                        $stmt->execute([$emp['employee_id'],$pStart,$pEnd,$basicSalary,$allowances,$bonuses,$deMinimis,$grossPay,$sss,$sssMpf,$philhealth,$pagibig,$sssEmployer,$sssEcpEmployer,$philhealthEmployer,$pagibigEmployer,$snapshot,$calculationSnapshot,$loanBalanceSnapshot,$tax,$lateDeduction,$undertimeDeduction,$attendanceDed['late_minutes'],$attendanceDed['undertime_minutes'],$attendanceDed['lwp_days'],$attendanceDed['lwp_deduction'],$otherDed,$loanDeduction,$totalDed,$netPay,$daysWorked,$_SESSION['name']]);
                        $payrollId = (int)$db->lastInsertId();
                    }
                    foreach ($pendingDisclosurePayments as $dp) {
                        $db->prepare("INSERT INTO loan_disclosure_payments (disclosure_id, payroll_id, payment_month, payment_date, amount, balance_after) VALUES (?,?,?,?,?,?)")
                           ->execute([$dp['disclosure_id'], $payrollId, $dp['month'], $pEnd, $dp['amount'], $dp['balance_after']]);
                        $db->prepare("UPDATE loan_disclosures SET outstanding_balance=?, loan_status=?, next_deduction_date=? WHERE id=?")
                           ->execute([$dp['balance_after'], $dp['balance_after'] <= 0 ? 'fully_paid' : 'active',
                                      $dp['balance_after'] <= 0 ? null : loanNextDeductionDate($dp['schedule'], $pEnd), $dp['disclosure_id']]);
                    }

                    $previousValues = $existingRow ? [
                        'gross_pay' => $existingRow['gross_pay'],
                        'bonuses' => $existingRow['bonuses'],
                        'sss_employee' => $existingRow['sss'],
                        'philhealth_employee' => $existingRow['philhealth'],
                        'pagibig_employee' => $existingRow['pagibig'],
                        'withholding_tax' => $existingRow['withholding_tax'],
                        'loan_balance_snapshot' => $existingRow['loan_balance_snapshot'],
                        'total_deductions' => $existingRow['total_deductions'],
                        'net_pay' => $existingRow['net_salary'],
                    ] : null;
                    $newValues = [
                        'gross_pay' => number_format($grossPay, 2, '.', ''),
                        'bonuses' => number_format($bonuses, 2, '.', ''),
                        'taxable_income' => number_format($taxableIncome, 2, '.', ''),
                        'de_minimis' => number_format($deMinimis, 2, '.', ''),
                        'lwp_days' => number_format($attendanceDed['lwp_days'], 2, '.', ''),
                        'lwp_deduction' => number_format($attendanceDed['lwp_deduction'], 2, '.', ''),
                        'loan_deduction' => number_format($loanDeduction, 2, '.', ''),
                        'loan_balance_snapshot' => $loanBalanceSnapshot,
                        'sss_employee' => number_format($sss, 2, '.', ''),
                        'philhealth_employee' => number_format($philhealth, 2, '.', ''),
                        'pagibig_employee' => number_format($pagibig, 2, '.', ''),
                        'withholding_tax' => number_format($tax, 2, '.', ''),
                        'total_deductions' => number_format($totalDed, 2, '.', ''),
                        'net_pay' => number_format($netPay, 2, '.', ''),
                    ];
                    $auditDetails = "Employee: {$emp['employee_id']} | Period: $pStart to $pEnd | Previous values: " .
                        json_encode($previousValues, JSON_THROW_ON_ERROR) . ' | New values: ' .
                        json_encode($newValues, JSON_THROW_ON_ERROR);
                    if (!auditLog('Generate Payroll', 'Payroll', $auditDetails)) {
                        throw new RuntimeException('Audit log write failed.');
                    }
                    $db->commit();
                    $generated++;
                    $generatedFor[] = trim($emp['first_name'] . ' ' . $emp['last_name']) . ' (' . $emp['employee_id'] . ')';
                } catch (DomainException $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $skipped++;
                    $error = $e->getMessage();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $skipped++;
                    $error = 'Payroll was not saved because the audit or database update failed.';
                }
            }

            // Always say WHICH employee(s) the payroll was generated for
            $forTxt = count($generatedFor) <= 3 ? implode(', ', $generatedFor) : implode(', ', array_slice($generatedFor, 0, 3)) . ' +' . (count($generatedFor) - 3) . ' more';
            if ($generated > 0) {
                $db->beginTransaction();
                try {
                    $submission = submitPayrollPeriodForCeo($db, $pStart, $pEnd, (string)$_SESSION['employee_id'], (string)$_SESSION['name']);
                    if (!$submission) throw new RuntimeException('Generated payroll could not be submitted for CEO review.');
                    $db->commit();
                    $success = "Payroll for {$submission['employees']} was generated and submitted for CEO approval. HR can release it after approval.";
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log('Automatic CEO payroll submission failed: ' . $e->getMessage());
                    $error = 'Payroll was generated, but could not be submitted for CEO approval. Contact an administrator before making further changes.';
                }
            }
            if ($generated > 0 && $skipped > 0) {
                if ($success) $success .= " $skipped employee(s) skipped.";
            } elseif (!$error) {
                $error = 'No payrolls generated — all selected employees have 0 days worked for this period.';
            }
        }
    }

    // SUBMIT PAYROLL FOR CEO REVIEW (Payroll Manager generates -> CEO approves/rejects -> HR releases)
    // A submission covers every generated payroll of the period that no approved submission covers yet, and it
    // stores WHICH employee(s) those are, so the CEO sees exactly who the payroll is for.
    if ($action === 'submit_ceo') {
        $pStart = sanitize($_POST['period_start'] ?? '');
        $pEnd   = sanitize($_POST['period_end'] ?? '');
        $apr    = getPayrollApproval($db, $pStart, $pEnd);
        $rows   = payrollUncoveredRows($db, $pStart, $pEnd);
        if (!$rows) {
            $error = 'There is no generated payroll waiting for approval in that period.';
        } elseif ($apr && $apr['status'] === 'pending_ceo') {
            $error = 'This period is already waiting for the CEO.';
        } else {
            $t = payrollTotalsFromRows($rows);
            $who = payrollEmployeeLabel(array_map(static fn($r) => [
                'employee_id' => $r['employee_id'],
                'employee_name' => getFullName((string)$r['first_name'], $r['middle_name'] ?? null, (string)$r['last_name']),
            ], $rows), 5);
            $db->beginTransaction();
            try {
                $db->prepare("INSERT INTO payroll_approvals (period_start, period_end, status, employee_count, gross_total, bonus_total, adjustment_total, thirteenth_total, deduction_total, employer_total, net_total, submitted_by, submitted_by_name)
                              VALUES (?,?, 'pending_ceo', ?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$pStart, $pEnd, $t['employee_count'], $t['gross'], $t['bonus'], $t['adjustment'], $t['thirteenth'], $t['deductions'], $t['employer'], $t['net'], $_SESSION['employee_id'], $_SESSION['name']]);
                $approvalId = (int)$db->lastInsertId();
                payrollSaveApprovalItems($db, $approvalId, $rows);
                if (!auditLog('Submit Payroll for CEO Review', 'Payroll', "Period: $pStart to $pEnd | Employee(s): $who | Count: {$t['employee_count']} | Net: {$t['net']}")) {
                    throw new RuntimeException('Audit log write failed.');
                }
                createNotification($db, "Payroll for $who is waiting for your review.", 'modules/ceo/payroll_approval.php', null, 'ceo');
                $db->commit();
                $success = "Payroll for $who submitted for CEO review. HR can release it after the CEO approves.";
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Submit payroll failed: ' . $e->getMessage());
                $error = 'The submission was not saved because the audit record could not be written.';
            }
        }
    }

    // NOTE: releasing payroll is no longer done here. HR releases it (modules/admin/hr_payroll.php) after the CEO approves.

} // ← closes if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']))

// ── Filters ───────────────────────────────────────────────
$filterEmp    = sanitize($_GET['emp'] ?? '');
$filterStatus = sanitize($_GET['status'] ?? '');
$filterMonth  = sanitize($_GET['month'] ?? '');

$where  = "WHERE 1=1";
$params = [];
if ($filterEmp)    { $where .= " AND p.employee_id=?"; $params[] = $filterEmp; }
if ($filterStatus) { $where .= " AND p.status=?"; $params[] = $filterStatus; }
if ($filterMonth)  { $where .= " AND DATE_FORMAT(p.payroll_period_start,'%Y-%m')=?"; $params[] = $filterMonth; }


$stmt = $db->prepare("
    SELECT 
        p.*,
        CONCAT(e.first_name, ' ', e.last_name) AS name
    FROM payroll p
    JOIN users e ON p.employee_id = e.employee_id
    $where
    ORDER BY p.created_at DESC
");

$stmt->execute($params);
$payrolls = $stmt->fetchAll();
foreach ($payrolls as &$payrollRow) {
    $payrollRow['employer_shares'] = payrollEmployerShares($payrollRow);
}
unset($payrollRow);
$demoPayrollCount = count(array_filter(
    $payrolls,
    static fn(array $payroll): bool => payrollSnapshotIsDemo($payroll['payroll_calculation_snapshot'] ?? null)
));

// Fetch employees for dropdown
$empStmt = $db->prepare("
    SELECT employee_id, basic_salary, allowances,
           CONCAT(first_name, ' ', last_name) AS name,
           department, position
    FROM users 
    WHERE role = 'employee' AND status = 'active'
    ORDER BY first_name ASC
");
$empStmt->execute();
$employees = $empStmt->fetchAll();

$previousEmployerStmt = $db->query("
    SELECT y.*, CONCAT(u.first_name, ' ', u.last_name) AS employee_name
    FROM payroll_previous_employer_ytd y
    JOIN users u ON u.employee_id = y.employee_id
    ORDER BY y.tax_year DESC, u.first_name, u.last_name
");
$previousEmployerYtd = $previousEmployerStmt->fetchAll();
$payrollChangeRequests = $db->query("SELECT pcr.*, CONCAT(u.first_name,' ',u.last_name) AS employee_name FROM payroll_change_requests pcr JOIN users u ON u.employee_id=pcr.employee_id ORDER BY pcr.submitted_at DESC LIMIT 50")->fetchAll();

// Payroll periods with their CEO-approval state
$periods = $db->query("SELECT payroll_period_start s, payroll_period_end e, COUNT(*) n, SUM(status='generated') gen, SUM(status='released') rel, SUM(net_salary) net
                       FROM payroll GROUP BY payroll_period_start, payroll_period_end ORDER BY payroll_period_start DESC LIMIT 8")->fetchAll();
foreach ($periods as &$per) {
    $per['employer'] = payrollPeriodTotals($db, $per['s'], $per['e'])['employer'];
    $per['apr'] = getPayrollApproval($db, $per['s'], $per['e']);
    $per['uncovered'] = payrollUncoveredRows($db, $per['s'], $per['e']);           // generated, not approved yet
    $emps = $db->prepare("SELECT p.employee_id, CONCAT(u.first_name,' ',u.last_name) AS employee_name FROM payroll p
                          JOIN users u ON u.employee_id = p.employee_id
                          WHERE p.payroll_period_start = ? AND p.payroll_period_end = ? ORDER BY u.last_name, u.first_name");
    $emps->execute([$per['s'], $per['e']]);
    $per['emps'] = $emps->fetchAll();                                                // who this period's payroll is for
} unset($per);

include '../../includes/header.php';
?>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Filter & Generate -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <form method="GET" class="filter-bar" style="margin:0;">
            <select name="emp" class="form-select" style="width:auto;">
                <option value="">All Employees</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?= $e['employee_id'] ?>" <?= $filterEmp===$e['employee_id']?'selected':'' ?>>
                    <?= htmlspecialchars($e['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-select" style="width:auto;">
                <option value="">All Status</option>
                <option value="draft"     <?= $filterStatus==='draft'?'selected':'' ?>>Draft</option>
                <option value="generated" <?= $filterStatus==='generated'?'selected':'' ?>>Generated</option>
                <option value="released"  <?= $filterStatus==='released'?'selected':'' ?>>Released</option>
            </select>
            <input type="month" name="month" class="form-input" style="width:auto;" value="<?= $filterMonth ?>">
            <button type="submit" class="btn-primary">Filter</button>
            <a href="payroll.php" class="btn-secondary">Reset</a>
        </form>
        <div style="display:flex;gap:8px;">
            <button class="btn-secondary" type="button" onclick="openPreviousEmployerYtdModal()">Previous Employer YTD</button>
            <button class="btn-secondary" type="button" onclick="openModal('compensationModal')">Update Compensation</button>
            <button class="btn-primary" type="button" onclick="openModal('generateModal')">Generate Payroll</button>
        </div>
    </div>
</div>

<!-- CEO Approval workflow -->
<div class="card">
    <div class="card-title">CEO Approval by Payroll Period <span class="muted-note">Payroll Manager generates and submits · CEO approves · HR releases</span></div>
    <div class="table-wrapper" data-table-search="Search payroll periods..."><table>
        <thead><tr><th>Period</th><th>Employee(s)</th><th>Cut-off / Pay date</th><th>Records</th><th>Net total</th><th>Employer shares</th><th>CEO approval</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($periods as $per): $ci = payCutoffInfo($per['s'], $per['e']); $a = $per['apr']; ?>
        <tr>
            <td><?= formatDate($per['s']) ?> – <?= formatDate($per['e']) ?></td>
            <td style="font-size:12px;max-width:240px;"><?= htmlspecialchars(payrollEmployeeLabel($per['emps'], 3)) ?></td>
            <td style="font-size:12px;"><?= $ci['cutoff'] ?> · pay date <?= formatDate($ci['pay_date']) ?></td>
            <td><?= (int)$per['n'] ?> (<?= (int)$per['gen'] ?> generated, <?= (int)$per['rel'] ?> released)</td>
            <td><?= formatMoney((float)$per['net']) ?></td>
            <td><?= formatMoney((float)$per['employer']) ?></td>
            <td>
                <?php if (!$a): ?><span class="badge badge-gray">Not submitted</span>
                <?php elseif ($a['status'] === 'pending_ceo'): ?><span class="badge badge-amber">Pending CEO review</span>
                <?php elseif ($a['status'] === 'approved'): ?><span class="badge badge-green">Approved</span> <span style="font-size:11px;color:#6B7280;"><?= htmlspecialchars($a['reviewed_by_name'] ?? '') ?></span>
                    <?php if ((int)$per['gen'] > 0 && !$per['uncovered']): ?><div style="font-size:11px;color:#6B7280;">Waiting for HR to release</div><?php endif; ?>
                <?php else: ?><span class="badge badge-red">Rejected</span><div style="font-size:11px;color:#991B1B;max-width:260px;"><?= htmlspecialchars($a['reject_reason'] ?? '') ?></div><?php endif; ?>
            </td>
            <td>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="period_start" value="<?= $per['s'] ?>"><input type="hidden" name="period_end" value="<?= $per['e'] ?>">
                    <?php if ($per['uncovered'] && (!$a || $a['status'] !== 'pending_ceo')): ?>
                        <button class="btn-primary btn-sm" name="action" value="submit_ceo" onclick="return confirm(<?= htmlspecialchars(json_encode('Submit ' . payrollEmployeeLabel(array_map(static fn($r) => ['employee_id' => $r['employee_id'], 'employee_name' => trim($r['first_name'] . ' ' . $r['last_name'])], $per['uncovered']), 3) . ' for CEO review?', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">Submit for CEO Review</button>
                    <?php elseif ((int)$per['gen'] > 0 && $a && $a['status'] === 'approved'): ?>
                        <span class="muted-note">Release: HR</span>
                    <?php endif; ?>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if ($periods): ?><tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="4">Total</td><td><?= formatMoney(array_sum(array_map(static fn($per) => (float)$per['net'], $periods))) ?></td><td><?= formatMoney(array_sum(array_map(static fn($per) => (float)$per['employer'], $periods))) ?></td><td colspan="2"></td></tr><?php endif; ?>
        <?php if (!$periods): ?><tr><td colspan="8" style="text-align:center;color:#9CA3AF;">No payroll generated yet.</td></tr><?php endif; ?>
        </tbody></table></div>
</div>

<!-- Payroll Table -->
<div class="card">
    <div class="card-title">Payroll Records (<?= count($payrolls) ?>)</div>
    <?php if ($demoPayrollCount > 0): ?>
    <div style="background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;border-radius:7px;padding:9px 12px;margin-bottom:12px;font-size:12px;">
        This view includes <?= $demoPayrollCount ?> simulated payroll record(s). Record history retains their source classification.
    </div>
    <?php endif; ?>
    <div class="table-wrapper" data-table-search="Search payroll records...">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Period</th>
                    <th>Gross Pay</th>
                    <th>SSS</th>
                    <th>PhilHealth</th>
                    <th>Pag-IBIG</th>
                    <th>Tax</th>
                    <th>Total Ded.</th>
                    <th>Net Pay</th>
                    <th>Employer Share</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payrolls as $p): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></div>
                        <div style="font-size:11px; color:#9CA3AF;"><?= htmlspecialchars($p['employee_id']) ?></div>
                    </td>
                    <td style="font-size:12px;">
                        <?= formatDate($p['payroll_period_start']) ?><br>
                        <span style="color:#9CA3AF;">to <?= formatDate($p['payroll_period_end']) ?></span>
                    </td>
                    <td><?= formatMoney((float)$p['gross_pay'] + (float)($p['bonuses'] ?? 0)) ?></td>
                    <td><?= formatMoney((float)$p['sss']) ?></td>
                    <td><?= formatMoney((float)$p['philhealth']) ?></td>
                    <td><?= formatMoney((float)$p['pagibig']) ?></td>
                    <td><?= formatMoney((float)$p['withholding_tax']) ?></td>
                    <td style="color:#CD1E27;"><?= formatMoney((float)$p['total_deductions']) ?></td>
                    <td style="font-weight:700; color:#16A34A;"><?= formatMoney((float)$p['net_salary']) ?></td>
                    <td><?= formatMoney($p['employer_shares']['total']) ?></td>
                    <td><span class="badge <?= statusBadge($p['status']) ?>"><?= ucfirst($p['status']) ?></span></td>
                    <td>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <a href="payslip.php?id=<?= $p['id'] ?>" target="_blank" class="btn-primary btn-sm">Payslip</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!empty($payrolls)): $payrollTableTotals = [0,0,0,0,0,0,0,0]; foreach ($payrolls as $p) { $payrollTableTotals[0] += (float)$p['gross_pay'] + (float)($p['bonuses'] ?? 0); $payrollTableTotals[1] += (float)$p['sss']; $payrollTableTotals[2] += (float)$p['philhealth']; $payrollTableTotals[3] += (float)$p['pagibig']; $payrollTableTotals[4] += (float)$p['withholding_tax']; $payrollTableTotals[5] += (float)$p['total_deductions']; $payrollTableTotals[6] += (float)$p['net_salary']; $payrollTableTotals[7] += $p['employer_shares']['total']; } ?>
                <tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="2">Total</td><?php foreach ($payrollTableTotals as $total): ?><td><?= formatMoney($total) ?></td><?php endforeach; ?><td colspan="2"></td></tr>
                <?php endif; ?>
                <?php if (empty($payrolls)): ?>
                <tr><td colspan="12" style="text-align:center; color:#9CA3AF; padding:24px;">No payroll records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-title">Compensation &amp; YTD Change Requests</div>
    <div class="table-wrapper" data-table-search="Search change requests..."><table>
        <thead><tr><th>Employee</th><th>Change</th><th>Status</th><th>Submitted</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($payrollChangeRequests as $change): $payload = json_decode((string)$change['payload'], true) ?: []; $after = $payload['after'] ?? []; ?>
        <tr>
            <td><?= htmlspecialchars($change['employee_name']) ?> (<?= htmlspecialchars($change['employee_id']) ?>)</td>
            <td><?= $change['change_type'] === 'ytd' ? 'Previous employer YTD · ' . (int)$change['reference_year'] : 'Compensation' ?></td>
            <td><span class="badge <?= $change['status'] === 'pending_ceo' ? 'badge-amber' : ($change['status'] === 'approved_pending_release' ? 'badge-blue' : ($change['status'] === 'released' ? 'badge-green' : 'badge-red')) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $change['status']))) ?></span>
                <?php if ($change['status'] === 'rejected' && $change['reject_reason']): ?><div style="font-size:11px;color:#991B1B;">Reason: <?= htmlspecialchars($change['reject_reason']) ?></div><?php endif; ?></td>
            <td><?= htmlspecialchars($change['submitted_at']) ?></td>
            <td style="font-size:12px;"><?php foreach ($after as $field => $value): ?><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$field))) ?>: <?= htmlspecialchars((string)$value) ?><br><?php endforeach; ?><?= htmlspecialchars((string)($payload['reason'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$payrollChangeRequests): ?><tr><td colspan="5" style="text-align:center;color:#9CA3AF;padding:18px;">No change requests submitted.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <div class="card-title">Previous Employer YTD Records</div>
    <div class="table-wrapper" data-table-search="Search YTD records...">
        <table>
            <thead><tr><th>Employee</th><th>Tax Year</th><th>Taxable Income</th><th>Non-Taxable Income</th><th>SSS</th><th>PhilHealth</th><th>HDMF</th><th>Withholding Tax</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($previousEmployerYtd as $index => $record): ?>
                <tr>
                    <td><?= htmlspecialchars($record['employee_name']) ?> (<?= htmlspecialchars($record['employee_id']) ?>)</td>
                    <td><?= (int)$record['tax_year'] ?></td>
                    <td><?= formatMoney((float)$record['taxable_income']) ?></td>
                    <td><?= formatMoney((float)$record['non_taxable_income']) ?></td>
                    <td><?= formatMoney((float)$record['sss']) ?></td>
                    <td><?= formatMoney((float)$record['philhealth']) ?></td>
                    <td><?= formatMoney((float)$record['hdmf']) ?></td>
                    <td><?= formatMoney((float)$record['withholding_tax']) ?></td>
                    <td><button class="btn-secondary btn-sm" type="button" onclick="editPreviousEmployerYtd(<?= (int)$index ?>)">Edit</button></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($previousEmployerYtd): ?>
                <tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="2">Total</td>
                    <?php foreach (['taxable_income','non_taxable_income','sss','philhealth','hdmf','withholding_tax'] as $field): ?><td><?= formatMoney(array_sum(array_map(static fn($record) => (float)$record[$field], $previousEmployerYtd))) ?></td><?php endforeach; ?><td></td>
                </tr>
                <?php endif; ?>
                <?php if (!$previousEmployerYtd): ?>
                <tr><td colspan="9" style="text-align:center;color:#9CA3AF;padding:18px;">No previous employer YTD records.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- page-body -->

<div class="modal-overlay" id="compensationModal">
    <div class="modal-box" style="max-width:520px;">
        <div class="modal-header">
            <div class="modal-title">Update Employee Compensation</div>
            <button class="modal-close" type="button" onclick="closeModal('compensationModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="update_compensation">
            <div class="form-group">
                <label class="form-label">Employee</label>
                <select name="employee_id" id="comp_employee" class="form-select" required>
                    <option value="">Select employee</option>
                    <?php foreach ($employees as $employee): ?>
                    <option value="<?= htmlspecialchars($employee['employee_id']) ?>"><?= htmlspecialchars($employee['name']) ?> (<?= htmlspecialchars($employee['employee_id']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row g-2">
                <div class="col-6"><div class="form-group"><label class="form-label">Basic Salary</label><input type="number" name="basic_salary" id="comp_salary" class="form-input" min="0" step="0.01" required></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Allowances</label><input type="number" name="allowances" id="comp_allowances" class="form-input" min="0" step="0.01" required></div></div>
            </div>
            <div class="form-group"><label class="form-label">Reason *</label><textarea name="reason" class="form-textarea" rows="3" required></textarea></div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn-primary">Submit for CEO Approval</button>
                <button type="button" class="btn-secondary" onclick="closeModal('compensationModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="previousEmployerYtdModal">
    <div class="modal-box" style="max-width:620px;">
        <div class="modal-header">
            <div class="modal-title">Previous Employer YTD</div>
            <button class="modal-close" type="button" onclick="closeModal('previousEmployerYtdModal')">&times;</button>
        </div>
        <p style="font-size:12px;color:#6B7280;margin-bottom:14px;">Enter the employee's year-to-date amounts from their previous employer. These figures are included in payslip YTD totals for the selected tax year.</p>
        <form method="POST" id="previousEmployerYtdForm">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_previous_employer_ytd">
            <div class="row g-2">
                <div class="col-8">
                    <div class="form-group">
                        <label class="form-label">Employee *</label>
                        <select name="employee_id" id="previous_ytd_employee" class="form-select" required>
                            <option value="">Select employee</option>
                            <?php foreach ($employees as $employee): ?>
                            <option value="<?= htmlspecialchars($employee['employee_id']) ?>"><?= htmlspecialchars($employee['name']) ?> (<?= htmlspecialchars($employee['employee_id']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-4">
                    <div class="form-group">
                        <label class="form-label">Tax Year *</label>
                        <input type="number" name="tax_year" id="previous_ytd_year" class="form-input" min="2000" max="<?= (int)date('Y') ?>" value="<?= (int)date('Y') ?>" required>
                    </div>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-6"><div class="form-group"><label class="form-label">Previous Employer Taxable Income</label><input type="number" name="previous_employer_taxable_income" id="previous_ytd_taxable" class="form-input" min="0" step="0.01" value="0.00" required></div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Previous Employer Non-Taxable Income</label><input type="number" name="previous_employer_non_taxable_income" id="previous_ytd_nontaxable" class="form-input" min="0" step="0.01" value="0.00" required></div></div>
                <div class="col-4"><div class="form-group"><label class="form-label">Previous Employer SSS</label><input type="number" name="previous_employer_sss" id="previous_ytd_sss" class="form-input" min="0" step="0.01" value="0.00" required></div></div>
                <div class="col-4"><div class="form-group"><label class="form-label">Previous Employer PhilHealth</label><input type="number" name="previous_employer_philhealth" id="previous_ytd_philhealth" class="form-input" min="0" step="0.01" value="0.00" required></div></div>
                <div class="col-4"><div class="form-group"><label class="form-label">Previous Employer HDMF</label><input type="number" name="previous_employer_hdmf" id="previous_ytd_hdmf" class="form-input" min="0" step="0.01" value="0.00" required></div></div>
                <div class="col-4"><div class="form-group"><label class="form-label">Previous Employer Withholding Tax</label><input type="number" name="previous_employer_withholding_tax" id="previous_ytd_withholding_tax" class="form-input" min="0" step="0.01" value="0.00" required></div></div>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn-primary">Submit for CEO Approval</button>
                <button type="button" class="btn-secondary" onclick="closeModal('previousEmployerYtdModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- GENERATE PAYROLL MODAL -->
<div class="modal-overlay" id="generateModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Generate Payroll</div>
            <button class="modal-close" onclick="closeModal('generateModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="generate">
            <div class="form-group">
                <label class="form-label">Employee *</label>
                <select name="employee_id" class="form-select" required onchange="loadSalaryPreview(this.value)">
                <option value="">-- Select Employee --</option>
                    <option value="all">Generate for ALL Employees</option>

                <?php foreach ($employees as $e): ?>
                    <option value="<?= $e['employee_id'] ?>" data-dept="<?= htmlspecialchars($e['department']) ?>">
                        <?= htmlspecialchars($e['name']) ?> (<?= $e['employee_id'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="salaryPreview" style="display:none; background:#F9FAFB; border:1px solid #E5E7EB; border-radius:8px; padding:12px; margin-bottom:16px; font-size:13px;">
                Loading salary info...
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <div class="form-group">
                        <label class="form-label">Period Start *</label>
                        <input type="date" name="period_start" class="form-input" required>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-group">
                        <label class="form-label">Period End *</label>
                        <input type="date" name="period_end" class="form-input" required>
                    </div>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <div class="form-group">
                        <label class="form-label">Bonuses (₱)</label>
                        <input type="number" name="bonuses" class="form-input" step="0.01" min="0" value="0">
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-group">
                        <label class="form-label">De Minimis (₱)</label>
                        <input type="number" name="de_minimis" class="form-input" step="0.01" min="0" value="0">
                        <small style="color:#6B7280;">Non-taxable earnings for this payroll.</small>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-group">
                        <label class="form-label">Other Deductions (₱)</label>
                        <input type="number" name="other_deductions" class="form-input" step="0.01" min="0" value="0">
                    </div>
                </div>
            </div>
            <div style="background:#FCEAEA; border-radius:8px; padding:12px; font-size:12px; color:#7A1A1A; margin-bottom:16px;">
                Government contributions use verified schedules effective on the payroll period end date. Payroll generation is blocked if any required agency schedule or salary bracket is missing.
            </div>
            <div style="background:#F9FAFB; border:1px solid #E5E7EB; border-radius:8px; padding:12px; font-size:12px; color:#4B5563; margin-bottom:16px;">
                Active loan schedules are deducted automatically by cutoff, up to each loan's remaining balance and the employee's available net pay.
            </div>
            <div style="display:flex; gap:10px;">
                <button type="submit" class="btn-primary">Generate Payroll</button>
                <button type="button" class="btn-secondary" onclick="closeModal('generateModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
// Salary preview when selecting employee
const empData = <?= json_encode(array_column($employees, null, 'employee_id')) ?>;
const previousEmployerYtdRecords = <?= json_encode($previousEmployerYtd, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;

function openPreviousEmployerYtdModal() {
    document.getElementById('previousEmployerYtdForm').reset();
    document.getElementById('previous_ytd_year').value = <?= (int)date('Y') ?>;
    openModal('previousEmployerYtdModal');
}

function editPreviousEmployerYtd(index) {
    const record = previousEmployerYtdRecords[index];
    if (!record) return;
    document.getElementById('previous_ytd_employee').value = record.employee_id;
    document.getElementById('previous_ytd_year').value = record.tax_year;
    document.getElementById('previous_ytd_taxable').value = record.taxable_income;
    document.getElementById('previous_ytd_nontaxable').value = record.non_taxable_income;
    document.getElementById('previous_ytd_sss').value = record.sss;
    document.getElementById('previous_ytd_philhealth').value = record.philhealth;
    document.getElementById('previous_ytd_hdmf').value = record.hdmf;
    document.getElementById('previous_ytd_withholding_tax').value = record.withholding_tax;
    openModal('previousEmployerYtdModal');
}

document.getElementById('comp_employee').addEventListener('change', function() {
    const employee = empData[this.value];
    document.getElementById('comp_salary').value = employee ? employee.basic_salary || 0 : '';
    document.getElementById('comp_allowances').value = employee ? employee.allowances || 0 : '';
});

function loadSalaryPreview(empId) {
    const preview = document.getElementById('salaryPreview');
    if (!empId) { preview.style.display = 'none'; return; }

    fetch('../../api/payroll.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get_employee', employee_id: empId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const e = data.employee;
            preview.innerHTML = `
                <strong>${e.name}</strong> | ${e.position}<br>
                Basic: ₱${parseFloat(e.basic_salary).toLocaleString('en-PH',{minimumFractionDigits:2})} &nbsp;|&nbsp;
                Allowances: ₱${parseFloat(e.allowances||0).toLocaleString('en-PH',{minimumFractionDigits:2})}<br>
                <span style="color:#6B7280;">Contribution amounts are calculated for the selected payroll date when you generate the payroll.</span>
            `;
            preview.style.display = 'block';
        }
    })
    .catch(() => { preview.style.display = 'none'; });
}
</script>
