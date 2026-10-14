<?php
// ============================================================
// Directpath HRMS — Payroll API
// Philippines 2026 Payroll Computation
// ============================================================
session_start();
require_once '../config/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['employee_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';
$db     = getDB();

// ── GET EMPLOYEE INFO ─────────────────────────────────────────
if ($action === 'get_employee') {
    apiRequireRole('payroll_manager');

    $empId = sanitize($input['employee_id'] ?? '');
    $stmt  = $db->prepare("SELECT employee_id, CONCAT(first_name, ' ', last_name) AS name, position, department, basic_salary, allowances FROM users WHERE employee_id=? AND role='employee'");
    $stmt->execute([$empId]);
    $emp = $stmt->fetch();

    if (!$emp) {
        echo json_encode(['success' => false, 'message' => 'Employee not found.']);
        exit;
    }

    echo json_encode([
        'success'  => true,
        'employee' => $emp,
    ]);
    exit;
}

// ── COMPUTE PAYROLL PREVIEW ───────────────────────────────────
if ($action === 'preview') {
    apiRequireRole('payroll_manager');

    $empId  = sanitize($input['employee_id'] ?? '');
    $pStart = sanitize($input['period_start'] ?? '');
    $pEnd   = sanitize($input['period_end'] ?? '');
    if (!GovernmentContributionEngine::isValidDate($pStart) || !GovernmentContributionEngine::isValidDate($pEnd) || $pEnd < $pStart) {
        echo json_encode(['success' => false, 'message' => 'A valid payroll period is required.']);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE employee_id=? AND role='employee' AND status='active'");
    $stmt->execute([$empId]);
    $emp = $stmt->fetch();

    if (!$emp) {
        echo json_encode(['success' => false, 'message' => 'Employee not found.']);
        exit;
    }

    // Count days worked and keep lateness and undertime separate.
    $stmt = $db->prepare("SELECT date, time_in, time_out, hours_worked, late_minutes, undertime_mins, status
                          FROM attendance WHERE employee_id=? AND date BETWEEN ? AND ? ORDER BY date");
    $stmt->execute([$empId, $pStart, $pEnd]);
    $attendanceRows = $stmt->fetchAll();
    $approvedLeaveRanges = approvedLeaveRangesForPeriod($db, $empId, $pStart, $pEnd);

    try {
        $contributions = calculateGovernmentContributions($db, (string)$emp['basic_salary'], $pEnd, 2, GovernmentContributionEngine::shareIndexForPayrollDate($pEnd));
    } catch (DomainException|InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
    $basic      = (float)$emp['basic_salary'];
    $allowances = (float)$emp['allowances'];
    $deMinimisInput = $input['de_minimis'] ?? '0';
    if (!is_string($deMinimisInput)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'De Minimis must be a non-negative amount with at most two decimal places.']);
        exit;
    }
    try {
        $deMinimis = (float)GovernmentContributionEngine::fromCents(GovernmentContributionEngine::toCents($deMinimisInput));
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }

    // Philippines 2026 formula
    $attendanceDed = computeAttendanceDeductionsForRecords($basic, $attendanceRows, $approvedLeaveRanges);
    $daysWorked = $attendanceDed['days_worked'];
    $grossPay   = computeGrossPay($basic, $daysWorked, $allowances);
    $sss        = (float)$contributions['SSS']['employee'];
    $sssMpf     = (float)($contributions['SSS']['mpf_employee'] ?? 0);
    $philhealth = (float)$contributions['PhilHealth']['employee'];
    $pagibig    = (float)$contributions['Pag-IBIG']['employee'];
    $taxableIncome = computeTaxableIncome(
        $grossPay,
        $attendanceDed['late_deduction'],
        $attendanceDed['undertime_deduction'],
        $attendanceDed['lwp_deduction']
    );
    try {
        $tax = calculateAnnualizedWithholdingTax($db, $empId, $taxableIncome, $pEnd);
    } catch (DomainException|InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
    $deductionsBeforeLoans = round($sss + $philhealth + $pagibig + $tax, 2);
    $availablePayForLoans = max(0.0, computeNetSalary($taxableIncome, $deMinimis, $deductionsBeforeLoans));
    // Loans come only from approved SOA submissions; each is deducted once a month on its schedule (15th / end of month).
    $loanBalances = [];
    $loanDeduction = 0.0;
    $totalLoanBalance = 0.0;
    $payMonth = date('Y-m', strtotime($pEnd));
    try {
        $loanStmt = $db->prepare("SELECT id, provider, loan_type, reference_no, original_amount, monthly_amortization, outstanding_balance, deduction_schedule, loan_start_date
                                  FROM loan_disclosures WHERE employee_id=? AND status='approved' AND loan_status='active' AND outstanding_balance > 0 ORDER BY id");
        $loanStmt->execute([$empId]);
        $loanRows = $loanStmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Payroll preview approved-loan lookup failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Approved loan deductions could not be loaded. Check the loan tables and try again.']);
        exit;
    }
    foreach ($loanRows as $loan) {
        $cutoffAmount = 0.0;
        if (loanScheduleMatchesPayrollPeriod((string)($loan['deduction_schedule'] ?? ''), $pEnd) && (empty($loan['loan_start_date']) || $loan['loan_start_date'] <= $pEnd)) {
            if (!loanPaymentRecordedForMonth($db, (int)$loan['id'], $payMonth)) {
                $cutoffAmount = max(0.0, round(min((float)$loan['monthly_amortization'], (float)$loan['outstanding_balance'], $availablePayForLoans), 2));
            }
        }
        $balanceAfter = max(0.0, round((float)$loan['outstanding_balance'] - $cutoffAmount, 2));
        $availablePayForLoans = round($availablePayForLoans - $cutoffAmount, 2);
        $loanDeduction = round($loanDeduction + $cutoffAmount, 2);
        $totalLoanBalance = round($totalLoanBalance + $balanceAfter, 2);
        $loanBalances[] = [
            'loan_id' => (int)$loan['id'],
            'loan_no' => (string)($loan['reference_no'] ?: ('SOA-' . $loan['id'])),
            'loan_type' => trim($loan['provider'] . ' ' . $loan['loan_type']),
            'loan_amount' => (float)$loan['original_amount'],
            'per_cutoff_deduction' => $cutoffAmount,
            'remaining_balance' => $balanceAfter,
        ];
    }
    $totalDed = round($deductionsBeforeLoans + $loanDeduction, 2);
    $netPay = computeNetSalary($taxableIncome, $deMinimis, $totalDed);

    echo json_encode([
        'success'    => true,
        'preview'    => [
            'employee_name' => getFullName($emp['first_name'], $emp['middle_name'] ?? null, $emp['last_name']),
            'days_worked'   => $daysWorked,
            'basic_salary'  => $basic,
            'allowances'    => $allowances,
            'gross_pay'     => $grossPay,
            'sss'           => $sss,
            'sss_employer'  => $contributions['SSS']['employer'],
            'sss_ecp_employer' => $contributions['SSS']['ecp'],
            'philhealth'    => $philhealth,
            'philhealth_employer' => $contributions['PhilHealth']['employer'],
            'pagibig'       => $pagibig,
            'pagibig_employer' => $contributions['Pag-IBIG']['employer'],
            'contribution_details' => $contributions,
            'tax'           => $tax,
            'late_minutes'  => $attendanceDed['late_minutes'],
            'late_hours'    => $attendanceDed['late_hours'],
            'late_deduction' => $attendanceDed['late_deduction'],
            'undertime_minutes' => $attendanceDed['undertime_minutes'],
            'undertime_hours' => $attendanceDed['undertime_hours'],
            'undertime_deduction' => $attendanceDed['undertime_deduction'],
            'lwp_days' => $attendanceDed['lwp_days'],
            'lwp_deduction' => $attendanceDed['lwp_deduction'],
            'taxable_income' => $taxableIncome,
            'de_minimis' => $deMinimis,
            'sss_mpf' => $sssMpf,
            'loan_deduction' => $loanDeduction,
            'loan_balances' => $loanBalances,
            'total_loan_balance' => $totalLoanBalance,
            'total_deductions' => $totalDed,
            'net_pay'       => $netPay,
        ]
    ]);
    exit;
}

// ── 13TH MONTH PAY COMPUTATION ───────────────────────────────
if ($action === 'thirteenth_month') {
    apiRequireRole('payroll_manager');

    $empId = sanitize($input['employee_id'] ?? '');
    $year  = (int)($input['year'] ?? date('Y'));

    // Sum of all basic salaries released this year
    $stmt = $db->prepare("SELECT SUM(basic_salary) FROM payroll WHERE employee_id=? AND YEAR(payroll_period_start)=? AND status IN ('generated','released')");
    $stmt->execute([$empId, $year]);
    $totalBasic = (float)$stmt->fetchColumn();

    $thirteenthMonth = computeThirteenthMonth($totalBasic);

    echo json_encode([
        'success' => true,
        'year'    => $year,
        'total_basic_year' => $totalBasic,
        'thirteenth_month' => $thirteenthMonth,
    ]);
    exit;
}

// ── MATERNITY BENEFIT ─────────────────────────────────────────
if ($action === 'maternity') {
    apiRequireRole('payroll_manager');

    $empId = sanitize($input['employee_id'] ?? '');

    // Get 6 highest monthly salaries
    $stmt = $db->prepare("SELECT basic_salary FROM payroll WHERE employee_id=? AND status IN ('generated','released') ORDER BY basic_salary DESC LIMIT 6");
    $stmt->execute([$empId]);
    $salaries = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($salaries) < 1) {
        echo json_encode(['success' => false, 'message' => 'Not enough payroll records.']);
        exit;
    }

    $benefit = computeMaternityBenefit($salaries);

    echo json_encode([
        'success' => true,
        'salaries_used' => $salaries,
        'maternity_benefit' => $benefit,
        'formula' => 'ADSC = Sum of 6 highest / 180 | Benefit = ADSC × 105 days',
    ]);
    exit;
}

// ── PATERNITY PAY ─────────────────────────────────────────────
if ($action === 'paternity') {
    apiRequireRole('payroll_manager');

    $empId = sanitize($input['employee_id'] ?? '');
    $stmt  = $db->prepare("SELECT basic_salary FROM users WHERE employee_id=?");
    $stmt->execute([$empId]);
    $salary = (float)$stmt->fetchColumn();

    $paternityPay = computePaternityPay($salary);

    echo json_encode([
        'success'      => true,
        'monthly_salary' => $salary,
        'paternity_pay'  => $paternityPay,
        'formula'        => '(Monthly Salary / 30) × 7 days',
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
