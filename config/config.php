<?php
// Directpath EMPS — Database Configuration

date_default_timezone_set('Asia/Manila');

define('DB_HOST',    'localhost');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_NAME',    'directpath_db');
define('DB_CHARSET', 'utf8mb4');

define('SITE_NAME',  'Directpath EMPS');
define('SITE_URL',   'http://localhost/directpath');

// ── SMTP email settings for password-reset verification ─────
// Configure these with your Gmail account and a Google App Password.
// Do NOT use your normal Gmail password or commit real credentials.
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: '');
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: '');
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: '');
define('SMTP_FROM_NAME', 'Directpath EMPS');
define('SMTP_SECURE', 'tls');

define('UPLOAD_PATH',          __DIR__ . '/../uploads/');
define('REFERENCE_PHOTO_PATH', __DIR__ . '/../uploads/reference_photos/');
define('CAPTURED_PHOTO_PATH',  __DIR__ . '/../uploads/captured_photos/');
define('PROFILE_PHOTO_PATH',   __DIR__ . '/../uploads/profile_photos/');
define('PAYROLL_AUTO_RELEASE', false);   // Payroll becomes employee-visible only after HR manually releases it.


// Use a specific Python executable for facial recognition commands.
// Update this to the Python installation that has numpy and face_recognition installed.
define('PYTHON_BINARY', 'C:\\Program Files\\Python311\\python.exe');

// Session timeout (8 hours)
define('SESSION_TIMEOUT', 28800);

// ── Attendance rules (single source of truth) ─────────────
// Shift is 8:00 AM - 5:00 PM. Time-in is only accepted inside the window below.
define('ATT_SHIFT_START',   '08:00:00'); // on time up to 8:00:59, late from 8:01
define('ATT_SHIFT_END',     '17:00:00'); // leaving earlier = undertime
define('ATT_LATE_GRACE_MINUTES', 0);
define('ATT_TIMEIN_OPEN',   '06:30:00'); // early-morning time-in opens
define('ATT_TIMEIN_CLOSE',  '17:00:00'); // no time-in from 5:00 PM onwards
define('ATT_WORK_DAYS',     [1, 2, 3, 4, 5]); // Mon-Fri (date('N'))
define('ATT_MAX_HOURS',     9);          // 8AM-5PM, no overtime credit

// ── WFH verification ──────────────────────────────────────
define('WFH_CHALLENGE_TTL',   120); // seconds a verification session stays valid
define('WFH_MAX_FAILED',      5);   // failed attempts allowed ...
define('WFH_LOCK_MINUTES',    15);  // ... within this many minutes before lock-out

// ── Leave rules ───────────────────────────────────────────
// Standard yearly credits per leave type. Every employee/year row is created from this.
define('LEAVE_ALLOTMENTS', [
    'vacation'  => 15,
    'sick'      => 5,
    'emergency' => 5,
    'maternity' => 105,
    'paternity' => 28,
    'solo_parent' => 7,
    'special_women' => 60,
    'vawc'      => 10,
    'sil'       => 5,
    'others'    => 0,   // no cap
]);
define('LEAVE_PROOF_REQUIRED_TYPES', ['sick']); // required only when sick leave lasts 3+ consecutive days
define('LEAVE_ATTACH_MAX_BYTES', 5 * 1024 * 1024);
define('LEAVE_ATTACH_PATH', __DIR__ . '/../uploads/leave_attachments/');

// ── Payroll rate basis ────────────────────────────────────
define('PAYROLL_WORKDAYS_PER_YEAR', 261); // Mon-Fri factor
define('PAYROLL_HOURS_PER_DAY',     8);
define('PAYROLL_DEDUCTION_ROUNDING_MODE', 'per_minute'); // per_minute or block
define('PAYROLL_DEDUCTION_BLOCK_MINUTES', 15);
define('PAYROLL_AUTO_RELEASE_TIME', '17:00:00'); // payslips auto-release on the cutoff date at this time (use '00:00:00' for start of day)

require_once __DIR__ . '/../includes/GovernmentContributionEngine.php';
require_once __DIR__ . '/../includes/AnnualizedTaxEngine.php';

// ── Database Connection ────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die(json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// ── Full Name Helper ─────────────────────────────────────────
// Ginagamit sa buong system para magpakita ng full name ng empleyado
// Sinasama ang first, middle (optional), at last name
function getFullName(string $first, ?string $middle, string $last): string {
    // Kapag may middle name, isama. Kapag wala, presto lang.
    if (!empty($middle)) {
        // Format: Juan D. dela Cruz (first + middle initial + last)
        $middleInitial = strtoupper(substr(trim($middle), 0, 1)) . '.';
        return trim("$first $middleInitial $last");
    }
    return trim("$first $last");
}

// ── Audit Log ─────────────────────────────────────────────
function auditLog(string $action, string $module, string $details = ''): bool {
    if (!isset($_SESSION['employee_id'])) return false;
    try {
        $db   = getDB();
        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, user_name, action, module, details, ip_address) VALUES (?,?,?,?,?,?)");
        $roleDetails = 'Role: ' . ($_SESSION['role'] ?? 'unknown');
        if ($details !== '') $roleDetails .= ' | ' . $details;
        $stmt->execute([
            $_SESSION['employee_id'],
            $_SESSION['name'],
            $action,
            $module,
            $roleDetails,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// ── Payroll Computations ──────────────────────────────────

// Gross Pay = Semi-monthly basic salary + allowances
function computeGrossPay(float $basicSalary, int $daysWorked, float $allowances, float ...$legacyValues): float {
    if ($daysWorked <= 0) return 0.00;
    if ($legacyValues) $allowances = (float)end($legacyValues);
    // For semi-monthly (15-30 basis), half the monthly salary is the base per period
    $periodBase = $basicSalary / 2;
    return round($periodBase + $allowances, 2);
}

function computeTaxableIncome(
    float $taxableEarnings,
    float $lateDeduction,
    float $undertimeDeduction,
    float $leaveWithoutPayDeduction
): float {
    return round($taxableEarnings - $lateDeduction - $undertimeDeduction - $leaveWithoutPayDeduction, 2);
}

function computeNetSalary(float $taxableIncome, float $nonTaxableEarnings, float $totalDeductions): float {
    return round($taxableIncome + $nonTaxableEarnings - $totalDeductions, 2);
}

function computeLoanCutoffDeduction(
    string|int|float $monthlyDeduction,
    string|int|float $outstandingBalance,
    int $shareIndex,
    string|int|float|null $availablePay = null
): float {
    if (!in_array($shareIndex, [1, 2], true)) {
        throw new InvalidArgumentException('Loan deduction share must be the first or second semi-monthly cutoff.');
    }
    $monthlyCents = GovernmentContributionEngine::toCents($monthlyDeduction);
    $balanceCents = GovernmentContributionEngine::toCents($outstandingBalance);
    $cutoffCents = intdiv($monthlyCents, 2) + ($shareIndex === 2 ? $monthlyCents % 2 : 0);
    if ($availablePay !== null) {
        $cutoffCents = min($cutoffCents, GovernmentContributionEngine::toCents($availablePay));
    }
    return (float)GovernmentContributionEngine::fromCents(min($cutoffCents, $balanceCents));
}

function payrollLoanBalancesForRecord(PDO $db, int $payrollId, ?string $snapshot, float $savedLoanDeduction = 0.0): array {
    if ($snapshot !== null && $snapshot !== '') {
        $data = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['loans'] ?? null)) {
            throw new UnexpectedValueException('The saved payroll loan balance snapshot is not supported.');
        }
        $loans = $data['loans'];
        $unitemizedDeduction = (float)($data['unitemized_deduction'] ?? 0);
        // The payment history is the source of truth. A snapshot saved before a repayment
        // was recorded (or repaired) shows 0.00 for this cutoff; take the recorded payment instead.
        try {
            $pm = $db->prepare("SELECT ldp.disclosure_id, SUM(ldp.amount) AS amount, MIN(ldp.balance_after) AS balance_after,
                                       COALESCE(NULLIF(ld.reference_no, ''), CONCAT('SOA-', ld.id)) AS loan_no,
                                       TRIM(CONCAT(ld.provider, ' ', ld.loan_type)) AS loan_type, ld.original_amount
                                FROM loan_disclosure_payments ldp
                                JOIN loan_disclosures ld ON ld.id = ldp.disclosure_id
                                WHERE ldp.payroll_id = ?
                                GROUP BY ldp.disclosure_id, ld.reference_no, ld.id, ld.provider, ld.loan_type, ld.original_amount");
            $pm->execute([$payrollId]);
            $paid = [];
            foreach ($pm->fetchAll() as $row) $paid[(int)$row['disclosure_id']] = $row;
        } catch (Throwable $e) {
            $paid = [];
        }
        foreach ($loans as &$snapLoan) {
            $lid = (int)($snapLoan['loan_id'] ?? 0);
            if (isset($paid[$lid])) {
                $snapLoan['per_cutoff_deduction'] = round((float)$paid[$lid]['amount'], 2);
                $snapLoan['remaining_balance'] = round((float)$paid[$lid]['balance_after'], 2);
                unset($paid[$lid]);
            }
        }
        unset($snapLoan);
        foreach ($paid as $lid => $row) {
            $loans[] = [
                'loan_id' => $lid, 'loan_no' => (string)$row['loan_no'], 'loan_type' => (string)$row['loan_type'],
                'loan_amount' => (float)$row['original_amount'],
                'per_cutoff_deduction' => round((float)$row['amount'], 2),
                'remaining_balance' => round((float)$row['balance_after'], 2),
            ];
        }
        if ($paid !== [] && $unitemizedDeduction > 0) $unitemizedDeduction = 0.0;
    } else {
        // Older payrolls without a snapshot: carry each approved loan's balance forward
        // from payment history as of this payroll cutoff. A later cutoff with no payment
        // must still show the outstanding balance while its deduction remains zero.
        try {
            $stmt = $db->prepare("
                SELECT loan_id, loan_no, loan_type, loan_amount,
                       per_cutoff_deduction, remaining_balance
                FROM (
                    SELECT ld.id AS loan_id,
                           COALESCE(NULLIF(ld.reference_no, ''), CONCAT('SOA-', ld.id)) AS loan_no,
                           TRIM(CONCAT(ld.provider, ' ', ld.loan_type)) AS loan_type,
                           ld.original_amount AS loan_amount,
                           COALESCE((
                               SELECT SUM(pm.amount)
                               FROM loan_disclosure_payments pm
                               WHERE pm.disclosure_id = ld.id AND pm.payroll_id = pr.id
                           ), 0) AS per_cutoff_deduction,
                           COALESCE((
                               SELECT pm.balance_after
                               FROM loan_disclosure_payments pm
                               WHERE pm.disclosure_id = ld.id
                                 AND pm.payment_date <= pr.payroll_period_end
                               ORDER BY pm.payment_date DESC, pm.id DESC LIMIT 1
                           ), (
                               SELECT pm.balance_after + pm.amount
                               FROM loan_disclosure_payments pm
                               WHERE pm.disclosure_id = ld.id
                                 AND pm.payment_date > pr.payroll_period_end
                               ORDER BY pm.payment_date ASC, pm.id ASC LIMIT 1
                           ), ld.outstanding_balance) AS remaining_balance
                    FROM payroll pr
                    JOIN loan_disclosures ld ON ld.employee_id = pr.employee_id
                    WHERE pr.id = ?
                      AND ld.status = 'approved'
                      AND (ld.loan_start_date IS NULL OR ld.loan_start_date <= pr.payroll_period_end)
                ) AS balances
                WHERE remaining_balance > 0 OR per_cutoff_deduction > 0
                ORDER BY loan_id
            ");
            $stmt->execute([$payrollId]);
            $loans = $stmt->fetchAll();
        } catch (Throwable $e) {
            $loans = [];
        }
        $unitemizedDeduction = $loans === [] && $savedLoanDeduction > 0 ? round($savedLoanDeduction, 2) : 0.0;
    }

    $totalBalance = 0.0;
    $totalCutoffDeduction = $unitemizedDeduction;
    foreach ($loans as $loan) {
        foreach (['loan_id','loan_no','loan_type','loan_amount','per_cutoff_deduction','remaining_balance'] as $field) {
            if (!array_key_exists($field, $loan)) {
                throw new UnexpectedValueException("A saved payroll loan balance is missing $field.");
            }
        }
        $totalBalance += (float)$loan['remaining_balance'];
        $totalCutoffDeduction += (float)$loan['per_cutoff_deduction'];
    }

    return [
        'loans' => $loans,
        'total_balance' => round($totalBalance, 2),
        'total_cutoff_deduction' => round($totalCutoffDeduction, 2),
        'unitemized_deduction' => $unitemizedDeduction,
        'balance_available' => $unitemizedDeduction === 0.0,
    ];
}

function summarizePayrollYearToDate(array $payrollRows, ?array $previousEmployer): array {
    $summary = [
        'taxable_income' => 0.0,
        'non_taxable_income' => 0.0,
        'previous_employer_income' => 0.0,
        'previous_employer_non_taxable_income' => 0.0,
        'sss' => 0.0,
        'philhealth' => 0.0,
        'hdmf' => 0.0,
        'withholding_tax' => 0.0,
        'previous_employer_sss' => 0.0,
        'previous_employer_philhealth' => 0.0,
        'previous_employer_hdmf' => 0.0,
        'previous_employer_withholding_tax' => 0.0,
        'net_pay' => 0.0,
    ];

    foreach ($payrollRows as $payroll) {
        $calculation = null;
        if (!empty($payroll['payroll_calculation_snapshot'])) {
            $calculation = json_decode($payroll['payroll_calculation_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($calculation) || ($calculation['version'] ?? null) !== 1
                || !array_key_exists('taxable_income', $calculation)) {
                throw new UnexpectedValueException('A saved payroll calculation snapshot is not supported for year-to-date totals.');
            }
        }
        $taxableIncome = $calculation !== null
            ? (float)$calculation['taxable_income']
            : (float)$payroll['gross_pay'] + (float)$payroll['bonuses'] + (float)$payroll['overtime_pay']
                + (float)$payroll['salary_adjustment'] - (float)$payroll['late_deduction']
                - (float)$payroll['undertime_deduction'] - (float)$payroll['lwp_deduction'];
        $summary['taxable_income'] += round($taxableIncome, 2);
        $summary['non_taxable_income'] += (float)$payroll['de_minimis'];
        $summary['sss'] += (float)$payroll['sss'] + (float)$payroll['sss_mpf'];
        $summary['philhealth'] += (float)$payroll['philhealth'];
        $summary['hdmf'] += (float)$payroll['pagibig'];
        $summary['withholding_tax'] += (float)$payroll['withholding_tax'];
        $summary['net_pay'] += (float)$payroll['net_salary'];
    }

    if ($previousEmployer) {
        $summary['previous_employer_income'] = (float)$previousEmployer['taxable_income'];
        $summary['previous_employer_non_taxable_income'] = (float)$previousEmployer['non_taxable_income'];
        $summary['previous_employer_sss'] = (float)$previousEmployer['sss'];
        $summary['previous_employer_philhealth'] = (float)$previousEmployer['philhealth'];
        $summary['previous_employer_hdmf'] = (float)$previousEmployer['hdmf'];
        $summary['previous_employer_withholding_tax'] = (float)$previousEmployer['withholding_tax'];
        $summary['withholding_tax'] += (float)$previousEmployer['withholding_tax'];
        $summary['non_taxable_income'] += (float)$previousEmployer['non_taxable_income'];
    }

    foreach ($summary as $key => $amount) {
        $summary[$key] = round($amount, 2);
    }
    return $summary;
}

function payrollSnapshotIsDemo(?string $snapshot): bool {
    if ($snapshot === null || $snapshot === '') {
        return false;
    }
    $data = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new UnexpectedValueException('A saved payroll calculation snapshot must be an object.');
    }
    return ($data['data_classification'] ?? null) === 'synthetic_demo_seed';
}

function payrollYearToDateSummary(PDO $db, string $employeeId, string $payrollDate): array {
    if (!GovernmentContributionEngine::isValidDate($payrollDate)) {
        throw new InvalidArgumentException('A valid payroll date is required for the year-to-date summary.');
    }

    $year = (int)substr($payrollDate, 0, 4);
    $stmt = $db->prepare("
        SELECT payroll_calculation_snapshot, gross_pay, bonuses, overtime_pay, salary_adjustment,
               late_deduction, undertime_deduction, lwp_deduction, de_minimis,
               sss, sss_mpf, philhealth, pagibig, withholding_tax, net_salary
        FROM payroll
        WHERE employee_id = ? AND payroll_period_end BETWEEN ? AND ?
          AND status IN ('generated', 'released')
        ORDER BY payroll_period_end, id
    ");
    $stmt->execute([$employeeId, sprintf('%04d-01-01', $year), $payrollDate]);
    $previousStmt = $db->prepare("
        SELECT taxable_income, non_taxable_income, sss, philhealth, hdmf, withholding_tax
        FROM payroll_previous_employer_ytd
        WHERE employee_id = ? AND tax_year = ?
    ");
    $previousStmt->execute([$employeeId, $year]);
    $previous = $previousStmt->fetch();
    return summarizePayrollYearToDate($stmt->fetchAll(), $previous ?: null);
}

function calculateAnnualizedWithholdingTax(PDO $db, string $employeeId, float $periodTaxableIncome, string $payrollDate): float {
    return AnnualizedTaxEngine::calculateForPayroll($db, $employeeId, $periodTaxableIncome, $payrollDate);
}

// 13th Month Pay = Total Basic na natanggap sa buong taon ÷ 12
function computeThirteenthMonth(float $totalBasicForYear): float {
    return round($totalBasicForYear / 12, 2);
}

// Maternity Benefit (SSS) = ADSC × 105 days
// ADSC = Average Daily Salary Credit = Sum ng 6 pinakamataas na monthly salary ÷ 180
function computeMaternityBenefit(array $sixHighestMonthlySalaries): float {
    $adsc = array_sum($sixHighestMonthlySalaries) / 180;
    return round($adsc * 105, 2);
}

// Paternity Pay = (Monthly Salary ÷ 30) × 7 araw
function computePaternityPay(float $monthlySalary): float {
    return round(($monthlySalary / 30) * 7, 2);
}

// ── Leave Balance Helpers ─────────────────────────────────
// Returns per-type credits for one employee + year.
// total     = standard yearly credits (LEAVE_ALLOTMENTS, unless HR overrode the row)
// used      = approved days (the column HR's approval step maintains)
// pending   = days in requests still waiting for approval (reserved)
// remaining = total - used
// available = total - used - pending  (what the employee can still file for)
function getLeaveTypeDefinitions(PDO $db, bool $activeOnly = false): array {
    $labels = [
        'vacation'=>'Vacation Leave','sick'=>'Sick Leave','emergency'=>'Emergency Leave','maternity'=>'Maternity Leave',
        'paternity'=>'Paternity Leave','solo_parent'=>'Solo Parent Leave','special_women'=>'Special Leave for Women',
        'vawc'=>'VAWC Leave','sil'=>'Service Incentive Leave','others'=>'Other Leaves',
    ];
    $defs = [];
    foreach (LEAVE_ALLOTMENTS as $key => $days) $defs[$key] = [
        'type_key'=>$key,'name'=>$labels[$key] ?? (ucfirst(str_replace('_',' ',$key)).' Leave'),
        'annual_days'=>(int)$days,'description'=>'','document_required'=>in_array($key,['maternity','paternity','solo_parent','special_women','vawc'],true),
        'document_guidance'=>'','is_custom'=>false,'is_active'=>true,
    ];
    try {
        $sql = 'SELECT type_key,name,annual_days,description,document_required,document_guidance,is_active FROM custom_leave_types';
        if ($activeOnly) $sql .= ' WHERE is_active=1';
        $sql .= ' ORDER BY name';
        foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['annual_days']=(int)$row['annual_days']; $row['document_required']=(bool)$row['document_required'];
            $row['is_custom']=true; $row['is_active']=(bool)$row['is_active']; $defs[$row['type_key']]=$row;
        }
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '42S02' && (int)($e->errorInfo[1] ?? 0) !== 1146) throw $e;
    }
    return $defs;
}

function normalizeLeaveTypeName(string $name): string {
    $name = strtolower(trim($name));
    $name = preg_replace('/\bleaves?\b/u', '', $name);
    return trim(preg_replace('/[^a-z0-9]+/u', ' ', $name));
}

function leaveTypeNameIsSimilar(string $candidate, string $existing): bool {
    $candidate = normalizeLeaveTypeName($candidate);
    $existing = normalizeLeaveTypeName($existing);
    if ($candidate === '' || $existing === '') return false;
    if ($candidate === $existing) return true;
    $a = str_replace(' ', '', $candidate);
    $b = str_replace(' ', '', $existing);
    $maxLength = max(strlen($a), strlen($b));
    return $maxLength >= 5 && (levenshtein($a, $b) <= 1 || similar_text($a, $b) / $maxLength >= 0.85);
}

function findLeaveTypeNameConflict(PDO $db, string $candidate, ?int $excludeProposalId = null): ?string {
    foreach (getLeaveTypeDefinitions($db, true) as $definition) {
        if (leaveTypeNameIsSimilar($candidate, (string)$definition['name'])) return (string)$definition['name'];
    }
    try {
        $sql = "SELECT id, name FROM custom_leave_type_requests WHERE status IN ('pending','approved')";
        $params = [];
        if ($excludeProposalId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeProposalId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $proposal) {
            if (leaveTypeNameIsSimilar($candidate, (string)$proposal['name'])) return (string)$proposal['name'];
        }
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '42S02' && (int)($e->errorInfo[1] ?? 0) !== 1146) throw $e;
    }
    return null;
}

function leaveTypeNameConflicts(PDO $db, string $candidate, ?int $excludeProposalId = null): bool {
    return findLeaveTypeNameConflict($db, $candidate, $excludeProposalId) !== null;
}

// Keep older installations compatible when built-in or custom leave types are added.
// Call before opening a transaction because MySQL ALTER TABLE commits implicitly.
function ensureLeaveBalanceColumns(PDO $db): void {
    $columns = $db->query('SHOW COLUMNS FROM leave_balances')->fetchAll(PDO::FETCH_COLUMN);
    $known = array_fill_keys(array_map('strtolower', $columns), true);
    foreach (getLeaveTypeDefinitions($db) as $type => $definition) {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', (string)$type)) continue;
        foreach (['total' => (int)$definition['annual_days'], 'used' => 0] as $suffix => $default) {
            $column = $type . '_' . $suffix;
            $normalizedColumn = strtolower($column);
            if (isset($known[$normalizedColumn])) continue;
            try {
                $db->exec('ALTER TABLE leave_balances ADD COLUMN `' . $column . '` INT NOT NULL DEFAULT ' . $default);
            } catch (PDOException $e) {
                // A parallel request may have added this case-insensitive column already.
                if ((int)($e->errorInfo[1] ?? 0) !== 1060) throw $e;
            }
            $known[$normalizedColumn] = true;
        }
    }
}

function getLeaveBalance(string $empId, int $year = 0): array {
    if ($year === 0) $year = (int)date('Y');
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM leave_balances WHERE employee_id = ? AND year = ? FOR UPDATE");
    $stmt->execute([$empId, $year]);
    $balance = $stmt->fetch();

    if (!$balance) {
        // Auto-create the row from the standard allotments
        $cols = ['employee_id', 'year'];
        $vals = [$empId, $year];
        $insertedColumns = ['employee_id' => true, 'year' => true];
        foreach (getLeaveTypeDefinitions($db) as $type => $definition) {
            $column = $type . '_total';
            $normalizedColumn = strtolower($column);
            if (isset($insertedColumns[$normalizedColumn])) continue;
            $cols[] = $column;
            $vals[] = $definition['annual_days'];
            $insertedColumns[$normalizedColumn] = true;
        }
        $marks = implode(',', array_fill(0, count($cols), '?'));
        $db->prepare("INSERT IGNORE INTO leave_balances (" . implode(',', $cols) . ") VALUES ($marks)")->execute($vals);
        $stmt->execute([$empId, $year]);
        $balance = $stmt->fetch() ?: [];
    }

    // Keep existing totals intact so HR-issued leave credits are not overwritten on every page view.
    // New employee/year rows above receive the current standard allotments.
    $stmt->execute([$empId, $year]);
    $balance = $stmt->fetch() ?: [];

    $pend = $db->prepare("SELECT leave_type, COALESCE(SUM(total_days),0) FROM leave_requests
                          WHERE employee_id = ? AND status = 'pending' AND YEAR(start_date) = ? GROUP BY leave_type");
    $pend->execute([$empId, $year]);
    $pending = $pend->fetchAll(PDO::FETCH_KEY_PAIR);

    $out = [];
    foreach (getLeaveTypeDefinitions($db) as $type => $definition) {
        $std = (int)$definition['annual_days'];
        $total = isset($balance[$type . '_total']) ? (int)$balance[$type . '_total'] : $std;
        $used  = (int)($balance[$type . '_used'] ?? 0);
        $pend  = (int)($pending[$type] ?? 0);
        $out[$type] = [
            'total'     => $total,
            'used'      => $used,
            'pending'   => $pend,
            'remaining' => max(0, $total - $used),
            'available' => max(0, $total - $used - $pend),
            'available_raw' => $total - $used - $pend,
        ];
    }
    return $out;
}

function storeLeaveAttachment(array $file, string $empId, string $prefix = 'leave_'): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The attachment could not be uploaded. Please try again.');
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('The uploaded attachment is invalid.');
    }
    if (($file['size'] ?? 0) > LEAVE_ATTACH_MAX_BYTES) {
        throw new InvalidArgumentException('Attachment is too large (maximum 5 MB).');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    if (!isset($allowed[$mime])) {
        throw new InvalidArgumentException('Attachment must be a JPG, PNG image or a PDF.');
    }
    if (!is_dir(LEAVE_ATTACH_PATH) && !mkdir(LEAVE_ATTACH_PATH, 0755, true) && !is_dir(LEAVE_ATTACH_PATH)) {
        throw new RuntimeException('Failed to create the attachment folder.');
    }

    $storedName = $prefix . preg_replace('/[^A-Za-z0-9_-]/', '', $empId) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], LEAVE_ATTACH_PATH . $storedName)) {
        throw new RuntimeException('Failed to save the attachment.');
    }

    return [
        'path' => $storedName,
        'name' => substr(preg_replace('/[^\w.\- ]/u', '_', basename($file['name'] ?? 'attachment')), 0, 200),
        'mime' => $mime,
    ];
}

function logLeaveRequestEvent(PDO $db, int $leaveId, string $action, string $actor, ?string $details = null): void {
    $db->prepare("INSERT INTO leave_request_events (leave_request_id, action, actor, details) VALUES (?,?,?,?)")
       ->execute([$leaveId, $action, $actor, $details]);
}

function createLeaveNotification(PDO $db, int $leaveId, string $message, ?string $employeeId = null, ?string $role = null): void {
    $db->prepare("INSERT INTO leave_notifications (leave_request_id, recipient_employee_id, recipient_role, message) VALUES (?,?,?,?)")
       ->execute([$leaveId, $employeeId, $role, $message]);
}

// Persistent cross-module notifications. Targets are app-relative page paths with optional query strings.
function createNotification(PDO $db, string $message, string $target, ?string $employeeId = null, ?string $role = null): void {
    if (($employeeId === null) === ($role === null)) throw new InvalidArgumentException('Choose one notification recipient.');
    $db->prepare("INSERT INTO notifications (recipient_employee_id, recipient_role, message, target_url) VALUES (?,?,?,?)")
       ->execute([$employeeId, $role, substr($message, 0, 255), $target]);
}

function notifyActiveEmployees(PDO $db, string $message, string $target): void {
    $db->prepare("INSERT INTO notifications (recipient_employee_id, message, target_url)
                  SELECT employee_id, ?, ? FROM users WHERE role='employee' AND status='active'")
       ->execute([substr($message, 0, 255), $target]);
}

// ── Payroll rate helpers ──────────────────────────────────

// ── Payroll auto-release (safety net) ─────────────────────
// HR normally releases payroll (modules/admin/hr_payroll.php) after the CEO approved it. If HR forgets, a
// generated payslip is released automatically once its pay date (5th / 20th, moved to Friday on weekends)
// has arrived AND the cut-off has ended. Only payroll covered by a CEO-APPROVED submission (same employee,
// same net pay) is ever auto-released; unapproved or changed payroll is never touched.
// Safe to call many times. Returns how many payslips were released.
function payrollReleaseDue(PDO $db): int {
    if (!defined('PAYROLL_AUTO_RELEASE') || !PAYROLL_AUTO_RELEASE) return 0;
    $today = date('Y-m-d');
    $cover = payrollApprovedCoverSql($db);
    $stmt = $db->query("SELECT p.id, p.employee_id, p.payroll_period_start, p.payroll_period_end,
                               CONCAT(u.first_name, ' ', u.last_name) AS employee_name
                        FROM payroll p JOIN users u ON u.employee_id = p.employee_id
                        WHERE p.status = 'generated' AND p.government_contributions_snapshot IS NOT NULL AND $cover");
    $due = [];
    foreach ($stmt->fetchAll() as $row) {
        $ci = payCutoffInfo($row['payroll_period_start'], $row['payroll_period_end']);
        if ($today < $ci['pay_date'] || $row['payroll_period_end'] > $today) continue;
        $due[] = $row;
    }
    $released = 0;
    $now = date('Y-m-d H:i:s');
    $setBy = payrollReleasedByReady($db) ? ", released_by_name='System (auto-release)'" : '';
    foreach ($due as $row) {
        $upd = $db->prepare("UPDATE payroll SET status='released', released_at=?$setBy WHERE id=? AND status='generated'");
        $upd->execute([$now, $row['id']]);
        if ($upd->rowCount() === 1) {
            $released++;
            try { createNotification($db, 'Your payslip is now available.', 'modules/admin/payslip.php?id=' . (int)$row['id'], (string)$row['employee_id']); }
            catch (Throwable $e) { error_log('Payslip notification failed: ' . $e->getMessage()); }
            try {
                createNotification(
                    $db,
                    'Payroll released for ' . (string)$row['employee_id'] . ' (' . (string)$row['payroll_period_start'] . ' to ' . (string)$row['payroll_period_end'] . ').',
                    'modules/admin/payroll.php?emp=' . rawurlencode((string)$row['employee_id']),
                    null,
                    'payroll_manager'
                );
            } catch (Throwable $e) { error_log('Payroll manager notification failed: ' . $e->getMessage()); }
            try {
                $db->prepare("INSERT INTO audit_logs (user_id, user_name, action, module, details, ip_address)
                              VALUES ('SYSTEM', 'System (auto-release)', 'Auto-release Payroll', 'Payroll', ?, ?)")
                   ->execute(["HR did not release it by the pay date | Payroll ID: {$row['id']} | Employee: {$row['employee_name']} ({$row['employee_id']}) | Period: {$row['payroll_period_start']} to {$row['payroll_period_end']}",
                              $_SERVER['REMOTE_ADDR'] ?? 'cli']);
            } catch (Throwable $e) { /* never block a release because of the log */ }
        }
    }
    return $released;
}

// Daily = (monthly x 12) / 261, hourly = daily / 8, per-minute = hourly / 60
function payrollRates(float $monthlyBasic): array {
    $daily  = ($monthlyBasic * 12) / PAYROLL_WORKDAYS_PER_YEAR;
    $hourly = $daily / PAYROLL_HOURS_PER_DAY;
    return [
        'monthly'    => round($monthlyBasic, 2),
        'daily'      => round($daily, 2),
        'hourly'     => round($hourly, 2),
        'per_minute' => round($hourly / 60, 4),
    ];
}

function computeAttendanceDeductions(float $monthlyBasic, int $lateMinutes, int $undertimeMinutes): array {
    $rates = payrollRates($monthlyBasic);
    $lateBillableMinutes = payrollRoundedDeductionMinutes($lateMinutes);
    $undertimeBillableMinutes = payrollRoundedDeductionMinutes($undertimeMinutes);
    return [
        'rates' => $rates,
        'late_hours' => round($lateMinutes / 60, 2),
        'undertime_hours' => round($undertimeMinutes / 60, 2),
        'late_billable_minutes' => $lateBillableMinutes,
        'undertime_billable_minutes' => $undertimeBillableMinutes,
        'late_deduction' => round($rates['hourly'] * $lateBillableMinutes / 60, 2),
        'undertime_deduction' => round($rates['hourly'] * $undertimeBillableMinutes / 60, 2),
    ];
}

function payrollRoundedDeductionMinutes(int $minutes): int {
    if ($minutes < 0) {
        throw new InvalidArgumentException('Attendance deduction minutes cannot be negative.');
    }
    if (PAYROLL_DEDUCTION_ROUNDING_MODE === 'per_minute') return $minutes;
    if (PAYROLL_DEDUCTION_ROUNDING_MODE !== 'block' || PAYROLL_DEDUCTION_BLOCK_MINUTES <= 0) {
        throw new LogicException('Payroll deduction rounding configuration is invalid.');
    }
    return $minutes === 0
        ? 0
        : (int)(ceil($minutes / PAYROLL_DEDUCTION_BLOCK_MINUTES) * PAYROLL_DEDUCTION_BLOCK_MINUTES);
}

function approvedLeaveRangesForPeriod(PDO $db, string $employeeId, string $periodStart, string $periodEnd): array {
    $stmt = $db->prepare("
        SELECT start_date, end_date
        FROM leave_requests
        WHERE employee_id = ?
          AND status IN ('approved','approved_pending_documents','documents_submitted','documents_rejected')
          AND start_date <= ? AND end_date >= ?
        ORDER BY start_date
    ");
    $stmt->execute([$employeeId, $periodEnd, $periodStart]);
    return $stmt->fetchAll();
}

function computeAttendanceDeductionsForRecords(float $monthlyBasic, array $attendanceRows, array $approvedLeaveRanges = []): array {
    $rates = payrollRates($monthlyBasic);
    $lateMinutes = 0;
    $undertimeMinutes = 0;
    $lateDeduction = 0.0;
    $undertimeDeduction = 0.0;
    $lateDays = 0;
    $absentDays = 0;
    $leaveWithoutPayDays = 0;
    $leaveWithoutPayDeduction = 0.0;
    $lateBillableMinutesTotal = 0;
    $undertimeBillableMinutesTotal = 0;
    $dailyRows = [];

    foreach ($attendanceRows as $row) {
        $late = (int)($row['late_minutes'] ?? 0);
        $undertime = (int)($row['undertime_mins'] ?? 0);
        $lateBillableMinutes = payrollRoundedDeductionMinutes($late);
        $undertimeBillableMinutes = payrollRoundedDeductionMinutes($undertime);
        $lateDed = round($rates['hourly'] * $lateBillableMinutes / 60, 2);
        $undertimeDed = round($rates['hourly'] * $undertimeBillableMinutes / 60, 2);
        $date = (string)($row['date'] ?? '');
        $isApprovedLeave = false;
        if (($row['status'] ?? '') === 'absent') {
            foreach ($approvedLeaveRanges as $leaveRange) {
                if ($date >= (string)($leaveRange['start_date'] ?? '') && $date <= (string)($leaveRange['end_date'] ?? '')) {
                    $isApprovedLeave = true;
                    break;
                }
            }
        }
        $lwpDays = ($row['status'] ?? '') === 'absent' && !$isApprovedLeave ? 1 : 0;
        $lwpDeduction = round($rates['daily'] * $lwpDays, 2);
        $lateMinutes += $late;
        $undertimeMinutes += $undertime;
        $lateBillableMinutesTotal += $lateBillableMinutes;
        $undertimeBillableMinutesTotal += $undertimeBillableMinutes;
        $lateDeduction += $lateDed;
        $undertimeDeduction += $undertimeDed;
        if ($late > 0) $lateDays++;
        if (($row['status'] ?? '') === 'absent') $absentDays++;
        $leaveWithoutPayDays += $lwpDays;
        $leaveWithoutPayDeduction += $lwpDeduction;
        $dailyRows[] = [
            'date' => $date,
            'time_in' => $row['time_in'] ?? null,
            'time_out' => $row['time_out'] ?? null,
            'hours_worked' => (float)($row['hours_worked'] ?? 0),
            'late_minutes' => $late,
            'late_billable_minutes' => $lateBillableMinutes,
            'late_hours' => round($late / 60, 2),
            'late_deduction' => $lateDed,
            'undertime_minutes' => $undertime,
            'undertime_billable_minutes' => $undertimeBillableMinutes,
            'undertime_hours' => round($undertime / 60, 2),
            'undertime_deduction' => $undertimeDed,
            'status' => (string)($row['status'] ?? ''),
            'lwp_days' => $lwpDays,
            'lwp_deduction' => $lwpDeduction,
        ];
    }

    return [
        'rates' => $rates,
        'days_worked' => count(array_filter($attendanceRows, static fn($row) => in_array($row['status'] ?? '', ['present', 'late'], true))),
        'late_minutes' => $lateMinutes,
        'late_hours' => round($lateMinutes / 60, 2),
        'late_billable_minutes' => $lateBillableMinutesTotal,
        'late_days' => $lateDays,
        'late_deduction' => round($lateDeduction, 2),
        'undertime_minutes' => $undertimeMinutes,
        'undertime_hours' => round($undertimeMinutes / 60, 2),
        'undertime_billable_minutes' => $undertimeBillableMinutesTotal,
        'undertime_deduction' => round($undertimeDeduction, 2),
        'absent_days' => $absentDays,
        'lwp_days' => $leaveWithoutPayDays,
        'lwp_deduction' => round($leaveWithoutPayDeduction, 2),
        'daily_rows' => $dailyRows,
    ];
}

// 13th month accrued for a year = basic actually earned / 12.
// Each payroll row is one semi-monthly period, so it contributes half the monthly basic
// minus any late/undertime deduction already applied to that row.
function computeThirteenthMonthAccrued(string $empId, int $year): float {
    $stmt = getDB()->prepare("SELECT basic_salary, late_deduction, undertime_deduction FROM payroll
                              WHERE employee_id = ? AND YEAR(payroll_period_start) = ? AND status IN ('generated','released')");
    $stmt->execute([$empId, $year]);
    $earned = 0.0;
    foreach ($stmt->fetchAll() as $r) {
        $earned += ((float)$r['basic_salary'] / 2) - (float)($r['late_deduction'] ?? 0) - (float)($r['undertime_deduction'] ?? 0);
    }
    return computeThirteenthMonth(max(0, $earned));
}

// ── Sanitize Input ────────────────────────────────────────
function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)));
}

// ── Format Helpers ────────────────────────────────────────
function formatMoney(float $amount): string {
    return '₱' . number_format($amount, 2);
}

function formatDate(?string $date): string {
    return $date ? date('M d, Y', strtotime($date)) : '—';
}

function formatTime(?string $time): string {
    return $time ? date('h:i A', strtotime($time)) : '—';
}

function getInitials(string $name): string {
    $parts = explode(' ', trim($name));
    $initials = '';
    foreach ($parts as $p) {
        if (!empty($p)) $initials .= strtoupper($p[0]);
        if (strlen($initials) >= 2) break;
    }
    return $initials ?: 'U';
}

function statusBadge(string $status): string {
    return match($status) {
        'active','approved','present','released' => 'badge-green',
        'pending','approved_pending_documents','documents_submitted','draft','half-day' => 'badge-amber',
        'inactive','rejected','documents_rejected','absent' => 'badge-red',
        default                                  => 'badge-blue',
    };
}

function formatLeaveStatus(string $status): string {
    return match($status) {
        'approved_pending_documents' => 'Approved - Pending Documents',
        'documents_submitted'        => 'Documents Submitted',
        'documents_rejected'         => 'Document Rejected - Re-upload Required',
        default                      => ucfirst($status),
    };
}

// Reasons an employee can pick when requesting a Certificate of Employment
const COE_REASONS = [
    'Car loan application',
    'Phone line application',
    'Bank loan application',
    'Housing loan application',
    'Visa application',
    'Reference purposes',
    'Travel',
    'Motorcycle loan application',
];

function formatTimeFull(?string $time): string {
    return $time ? date('h:i:s A', strtotime($time)) : '—';
}
function formatDateDay(?string $date): string {
    return $date ? date('F j, Y', strtotime($date)) . ' — ' . date('l', strtotime($date)) : '—';
}

require_once __DIR__ . '/../includes/org_helpers.php';
require_once __DIR__ . '/../includes/payroll_rules.php';
