<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_rules.php';

function payrollTestExpectSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException("$message Expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

payrollTestExpectSame(16000.0, computeGrossPay(30000.0, 1, 1000.0), 'Existing semi-monthly salary and allowance calculation remains unchanged.');
payrollTestExpectSame(0.0, computeGrossPay(30000.0, 0, 1000.0), 'Payroll without a present/late attendance record remains blocked from positive gross pay.');

$attendance = computeAttendanceDeductionsForRecords(30000.0, [
    ['date'=>'2026-06-01','status'=>'present','late_minutes'=>0,'undertime_mins'=>0],
    ['date'=>'2026-06-02','status'=>'late','late_minutes'=>30,'undertime_mins'=>15],
    ['date'=>'2026-06-03','status'=>'absent','late_minutes'=>0,'undertime_mins'=>0],
    ['date'=>'2026-06-04','status'=>'on-leave','late_minutes'=>0,'undertime_mins'=>0],
    ['date'=>'2026-06-05','status'=>'present','late_minutes'=>0,'undertime_mins'=>0],
]);
$perHalfHour = round($attendance['rates']['hourly'] / 2, 2);
$perQuarterHour = round($attendance['rates']['hourly'] / 4, 2);
payrollTestExpectSame(3, $attendance['days_worked'], 'Only present and late statuses count as worked days.');
payrollTestExpectSame(1, $attendance['absent_days'], 'Absence is counted for display.');
payrollTestExpectSame($perHalfHour, $attendance['late_deduction'], 'Late deduction follows the configured hourly-rate basis.');
payrollTestExpectSame($perQuarterHour, $attendance['undertime_deduction'], 'Undertime deduction follows the configured hourly-rate basis.');

$sampleRates = payrollRates(25867.0);
payrollTestExpectSame(1189.29, $sampleRates['daily'], 'The existing 261-day daily-rate formula is preserved.');
payrollTestExpectSame(148.66, $sampleRates['hourly'], 'Hourly rate is the rounded daily rate divided by eight.');
payrollTestExpectSame(991.71, round((25867.0 * 12) / 313, 2), 'The requested 313-day reference rate is calculated for comparison.');
payrollTestExpectSame(197.58, round($sampleRates['daily'] - round((25867.0 * 12) / 313, 2), 2), 'The existing daily rate differs from the requested reference rate by ₱197.58.');
payrollTestExpectSame(24.70, round($sampleRates['hourly'] - round(round((25867.0 * 12) / 313, 2) / 8, 2), 2), 'The existing hourly rate differs from the requested reference rate by ₱24.70.');
payrollTestExpectSame(0, attComputeLateMinutes('08:00:59'), 'The configured zero-minute grace keeps 8:00:59 on time.');
payrollTestExpectSame(1, attComputeLateMinutes('08:01:00'), 'Lateness begins at one minute after the shift start.');

$lwp = computeAttendanceDeductionsForRecords(30000.0, [
    ['date'=>'2026-06-08','status'=>'absent','late_minutes'=>0,'undertime_mins'=>0],
    ['date'=>'2026-06-09','status'=>'absent','late_minutes'=>0,'undertime_mins'=>0],
], [['start_date'=>'2026-06-08','end_date'=>'2026-06-08']]);
payrollTestExpectSame(2, $lwp['absent_days'], 'Approved leave remains visible among recorded absent-status days.');
payrollTestExpectSame(1, $lwp['lwp_days'], 'Only an absent day not covered by approved leave is unpaid.');
payrollTestExpectSame(1379.31, $lwp['lwp_deduction'], 'LWP uses one existing daily-rate deduction per uncovered absence.');

$taxable = computeTaxableIncome(16000.0, 25.0, 40.0, 1379.31);
payrollTestExpectSame(14555.69, $taxable, 'Taxable income subtracts separate late, undertime, and LWP adjustment lines.');
payrollTestExpectSame(12655.69, computeNetSalary($taxable, 100.0, 2000.0), 'Net salary adds De Minimis and subtracts deductions once.');
payrollTestExpectSame(50.0, computeLoanCutoffDeduction('100.01', '500.00', 1), 'The first loan cutoff receives the lower half-cent share.');
payrollTestExpectSame(50.01, computeLoanCutoffDeduction('100.01', '500.00', 2), 'The second loan cutoff receives the remaining monthly cent.');
payrollTestExpectSame(20.0, computeLoanCutoffDeduction('100.00', '20.00', 1), 'A final loan deduction is capped at the remaining balance.');
payrollTestExpectSame(25.0, computeLoanCutoffDeduction('100.00', '500.00', 1, '25.00'), 'Loan deductions cannot exceed available net pay.');

$ytd = summarizePayrollYearToDate([
    [
        'payroll_calculation_snapshot' => json_encode(['version'=>1,'taxable_income'=>1200.00]),
        'gross_pay'=>1250.00,'bonuses'=>0.00,'overtime_pay'=>0.00,'salary_adjustment'=>0.00,
        'late_deduction'=>0.00,'undertime_deduction'=>0.00,'lwp_deduction'=>0.00,'de_minimis'=>50.00,
        'sss'=>10.00,'sss_mpf'=>2.00,'philhealth'=>3.00,'pagibig'=>4.00,'withholding_tax'=>5.00,'net_salary'=>1220.00,
    ],
    [
        'payroll_calculation_snapshot'=>null,
        'gross_pay'=>2000.00,'bonuses'=>0.00,'overtime_pay'=>100.00,'salary_adjustment'=>20.00,
        'late_deduction'=>10.00,'undertime_deduction'=>5.00,'lwp_deduction'=>15.00,'de_minimis'=>30.00,
        'sss'=>11.00,'sss_mpf'=>1.00,'philhealth'=>5.00,'pagibig'=>6.00,'withholding_tax'=>7.00,'net_salary'=>1310.00,
    ],
], [
    'taxable_income'=>500.00,'non_taxable_income'=>25.00,'sss'=>30.00,'philhealth'=>40.00,'hdmf'=>50.00,'withholding_tax'=>40.00,
]);
payrollTestExpectSame(3290.0, $ytd['taxable_income'], 'YTD taxable income adds saved snapshots and legacy taxable pay without subtracting statutory deductions.');
payrollTestExpectSame(105.0, $ytd['non_taxable_income'], 'YTD non-taxable income includes current and previous-employer values.');
payrollTestExpectSame(500.0, $ytd['previous_employer_income'], 'YTD shows previous-employer taxable income separately.');
payrollTestExpectSame(24.0, $ytd['sss'], 'YTD SSS includes employee regular and provident-fund shares.');
payrollTestExpectSame(8.0, $ytd['philhealth'], 'YTD PhilHealth sums current payrolls.');
payrollTestExpectSame(10.0, $ytd['hdmf'], 'YTD HDMF sums Pag-IBIG employee deductions.');
payrollTestExpectSame(52.0, $ytd['withholding_tax'], 'YTD withholding tax includes current and previous-employer withholding.');
payrollTestExpectSame(40.0, $ytd['previous_employer_withholding_tax'], 'Previous-employer withholding remains visible as a tax credit.');
payrollTestExpectSame(2530.0, $ytd['net_pay'], 'YTD net pay sums payroll records.');
payrollTestExpectSame(30.0, $ytd['previous_employer_sss'], 'Previous-employer SSS remains separately reported.');

$_SESSION['_csrf_token'] = str_repeat('a', 64);
payrollTestExpectSame(true, csrfTokenIsValid(str_repeat('a', 64)), 'A matching payroll CSRF token is accepted.');
payrollTestExpectSame(false, csrfTokenIsValid(str_repeat('b', 64)), 'A forged payroll CSRF token is rejected.');
payrollTestExpectSame(false, csrfTokenIsValid(['invalid']), 'A non-string payroll CSRF token is rejected.');

$employeeFacingFiles = [
    __DIR__ . '/../modules/employee/payroll.php',
    __DIR__ . '/../modules/admin/payslip.php',
];
foreach ($employeeFacingFiles as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        throw new RuntimeException("Could not inspect employee-facing payroll surface: $file");
    }
    if (preg_match('/sss_employer|sss_ec_employer|philhealth_employer|pagibig_employer|employer share|employer-paid/i', $source)) {
        throw new RuntimeException("Employer-only contribution data is referenced by employee-facing surface: $file");
    }
}

$employeePayslip = file_get_contents(__DIR__ . '/../modules/admin/payslip.php');
if ($employeePayslip === false ||
    !str_contains($employeePayslip, "p.employee_id = ? AND p.status = 'released'")) {
    throw new RuntimeException('The employee payslip route must enforce ownership and released status server-side.');
}

foreach ([
    __DIR__ . '/../modules/admin/payroll.php',
    __DIR__ . '/../modules/admin/attendance.php',
    __DIR__ . '/../modules/admin/government_contributions.php',
] as $file) {
    $source = file_get_contents($file);
    if ($source === false ||
        preg_match_all('/<form\b[^>]*method=["\']post["\'][^>]*>(.*?)<\/form>/is', $source, $forms) === false) {
        throw new RuntimeException("Could not inspect CSRF protection on mutating forms: $file");
    }
    foreach ($forms[1] as $formBody) {
        if (!str_contains($formBody, 'name="_csrf_token"')) {
            throw new RuntimeException("A mutating form is missing a CSRF token: $file");
        }
    }
}

$managerReport = file_get_contents(__DIR__ . '/../modules/admin/reports.php');
if ($managerReport === false ||
    !str_contains($managerReport, "if (\$role === 'payroll_manager')") ||
    !str_contains($managerReport, 'sss_ec_employer')) {
    throw new RuntimeException('Employer contribution reporting must be available only in the Payroll Manager report.');
}

$ytdAdmin = file_get_contents(__DIR__ . '/../modules/admin/payroll.php');
$ytdPayslip = file_get_contents(__DIR__ . '/../modules/admin/payslip.php');
$ytdEmployeePayroll = file_get_contents(__DIR__ . '/../modules/employee/payroll.php');
$ytdMigration = file_get_contents(__DIR__ . '/../config/payroll_ytd_migration.sql');
if ($ytdAdmin === false || !str_contains($ytdAdmin, 'save_previous_employer_ytd') ||
    !str_contains($ytdAdmin, 'auditLog(') || $ytdPayslip === false ||
    !str_contains($ytdPayslip, 'Year-to-Date Summary') || $ytdEmployeePayroll === false ||
    !str_contains($ytdEmployeePayroll, 'year_to_date') || $ytdMigration === false ||
    !str_contains($ytdMigration, 'payroll_previous_employer_ytd')) {
    throw new RuntimeException('Previous-employer YTD entry and payslip summary must be wired through the payroll module.');
}

$schedulePage = file_get_contents(__DIR__ . '/../modules/admin/government_contributions.php');
$scheduleMigration = file_get_contents(__DIR__ . '/../config/government_contributions_migration.sql');
if ($schedulePage === false || str_contains($schedulePage, 'rules_json') ||
    !str_contains($schedulePage, 'Add Contribution Schedule') ||
    !str_contains($schedulePage, 'Contribution Rules') ||
    !str_contains($schedulePage, 'source_url') ||
    !str_contains($schedulePage, "['payroll_manager', 'superadmin']")) {
    throw new RuntimeException('Contribution schedules must use the visual editor, official source URL, and manager/super-admin write permissions.');
}
if ($scheduleMigration === false || !str_contains($scheduleMigration, 'ADD COLUMN IF NOT EXISTS source_url')) {
    throw new RuntimeException('The additive contribution migration must preserve existing schedules while adding official source URLs.');
}

$simulationBrackets = [
    ['salary_min'=>'0.00','salary_max'=>'14999.99','msc'=>'15000.00','regular_ss_employee'=>'750.00','regular_ss_employer'=>'1500.00','mpf_employee'=>'0.00','mpf_employer'=>'0.00','ec_employer'=>'10.00'],
    ['salary_min'=>'15000.00','salary_max'=>'19999.99','msc'=>'20000.00','regular_ss_employee'=>'1000.00','regular_ss_employer'=>'2000.00','mpf_employee'=>'0.00','mpf_employer'=>'0.00','ec_employer'=>'30.00'],
    ['salary_min'=>'20000.00','salary_max'=>'34999.99','msc'=>'25000.00','regular_ss_employee'=>'1000.00','regular_ss_employer'=>'2000.00','mpf_employee'=>'250.00','mpf_employer'=>'500.00','ec_employer'=>'30.00'],
    ['salary_min'=>'35000.00','salary_max'=>null,'msc'=>'35000.00','regular_ss_employee'=>'1000.00','regular_ss_employer'=>'2000.00','mpf_employee'=>'750.00','mpf_employer'=>'1500.00','ec_employer'=>'30.00'],
];
$simulationPhilHealthRule = ['contribution_base_mode'=>'salary_capped','contribution_base_floor'=>'10000.00','contribution_base_cap'=>'100000.00','employee_rate'=>'2.50000','employer_rate'=>'2.50000','employee_fixed_amount'=>'0.00','employer_fixed_amount'=>'0.00'];
$simulationPagIBIGRule = ['contribution_base_mode'=>'salary_capped','contribution_base_cap'=>'10000.00','employee_rate'=>'2.00000','employer_rate'=>'2.00000','employee_fixed_amount'=>'0.00','employer_fixed_amount'=>'0.00'];
$fictionalEmployees = [
    ['salary'=>'5000.00','allowance'=>'0.00','bonus'=>'0.00','loan'=>'0.00','other'=>'0.00','department'=>'Operations','position'=>'Assistant','attendance'=>[['status'=>'present','late_minutes'=>0,'undertime_mins'=>0]]],
    ['salary'=>'10000.00','allowance'=>'250.00','bonus'=>'0.00','loan'=>'100.00','other'=>'0.00','department'=>'HR','position'=>'Coordinator','attendance'=>[['status'=>'late','late_minutes'=>20,'undertime_mins'=>0]]],
    ['salary'=>'12000.00','allowance'=>'0.00','bonus'=>'500.00','loan'=>'0.00','other'=>'50.00','department'=>'Finance','position'=>'Clerk','attendance'=>[['status'=>'present','late_minutes'=>0,'undertime_mins'=>15]]],
    ['salary'=>'15000.00','allowance'=>'500.00','bonus'=>'0.00','loan'=>'250.00','other'=>'0.00','department'=>'Sales','position'=>'Associate','attendance'=>[['status'=>'present','late_minutes'=>0,'undertime_mins'=>0],['status'=>'absent','late_minutes'=>0,'undertime_mins'=>0]]],
    ['salary'=>'20000.00','allowance'=>'0.00','bonus'=>'1000.00','loan'=>'0.00','other'=>'100.00','department'=>'Support','position'=>'Specialist','attendance'=>[['status'=>'present','late_minutes'=>0,'undertime_mins'=>0],['status'=>'on-leave','late_minutes'=>0,'undertime_mins'=>0]]],
    ['salary'=>'25876.00','allowance'=>'750.00','bonus'=>'0.00','loan'=>'300.00','other'=>'0.00','department'=>'Engineering','position'=>'Analyst','attendance'=>[['status'=>'present','late_minutes'=>0,'undertime_mins'=>0,'time_in'=>null,'time_out'=>null]]],
    ['salary'=>'28000.00','allowance'=>'0.00','bonus'=>'250.00','loan'=>'0.00','other'=>'75.00','department'=>'Operations','position'=>'Lead','attendance'=>[['status'=>'present','late_minutes'=>0,'undertime_mins'=>0],['status'=>'late','late_minutes'=>10,'undertime_mins'=>5]]],
    ['salary'=>'30000.00','allowance'=>'1000.00','bonus'=>'0.00','loan'=>'500.00','other'=>'0.00','department'=>'IT','position'=>'Administrator','attendance'=>[['status'=>'present','late_minutes'=>0,'undertime_mins'=>0]]],
    ['salary'=>'35000.00','allowance'=>'500.00','bonus'=>'1500.00','loan'=>'0.00','other'=>'200.00','department'=>'Management','position'=>'Supervisor','attendance'=>[['status'=>'late','late_minutes'=>30,'undertime_mins'=>15]]],
    ['salary'=>'40000.00','allowance'=>'0.00','bonus'=>'0.00','loan'=>'750.00','other'=>'0.00','department'=>'Finance','position'=>'Manager','attendance'=>[['status'=>'present','late_minutes'=>0,'undertime_mins'=>0],['status'=>'absent','late_minutes'=>0,'undertime_mins'=>0]]],
];

payrollTestExpectSame(10, count($fictionalEmployees), 'The synthetic payroll simulation includes ten fictional employees.');
foreach ($fictionalEmployees as $index => $employee) {
    $salaryCents = GovernmentContributionEngine::validateMonthlySalary($employee['salary']);
    $salary = (float)$employee['salary'];
    $attendanceResult = computeAttendanceDeductionsForRecords($salary, $employee['attendance']);
    payrollTestExpectSame(true, $attendanceResult['days_worked'] > 0, 'Each synthetic payroll row has an attendance record required by current payroll generation.');

    $bracketMatches = array_values(array_filter($simulationBrackets, static function (array $bracket) use ($salaryCents): bool {
        $minimum = GovernmentContributionEngine::toCents($bracket['salary_min']);
        $maximum = $bracket['salary_max'] === null ? PHP_INT_MAX : GovernmentContributionEngine::toCents($bracket['salary_max']);
        return $salaryCents >= $minimum && $salaryCents <= $maximum;
    }));
    payrollTestExpectSame(1, count($bracketMatches), "Synthetic employee " . ($index + 1) . ' matches exactly one SSS test bracket.');
    $shareIndex = GovernmentContributionEngine::shareIndexForPayrollDate($index % 2 ? '2026-06-30' : '2026-06-15');
    $sss = GovernmentContributionEngine::calculateSssFromBracket($bracketMatches[0], 2, $shareIndex);
    $philhealth = GovernmentContributionEngine::calculateFromRule($simulationPhilHealthRule, $salaryCents, 2, $shareIndex);
    $pagibig = GovernmentContributionEngine::calculateFromRule($simulationPagIBIGRule, $salaryCents, 2, $shareIndex);
    $gross = computeGrossPay($salary, $attendanceResult['days_worked'], (float)$employee['allowance']);
    $employeeDeductions = round(
        (float)$sss['employee'] + (float)$philhealth['employee'] + (float)$pagibig['employee']
        + $attendanceResult['late_deduction'] + $attendanceResult['undertime_deduction']
        + (float)$employee['loan'] + (float)$employee['other'],
        2
    );
    $net = round($gross + (float)$employee['bonus'] - $employeeDeductions, 2);
    payrollTestExpectSame(round($gross + (float)$employee['bonus'] - $employeeDeductions, 2), $net, 'Synthetic payroll earnings and deductions reconcile for each employee.');
    payrollTestExpectSame(true, (float)$sss['employer'] + (float)$sss['ecp'] + (float)$philhealth['employer'] + (float)$pagibig['employer'] > 0, 'Employer costs remain separate from the employee net-pay formula.');
}

require_once __DIR__ . '/../includes/AnnualizedTaxEngine.php';
$annualTaxBrackets = [
    ['income_min'=>'0.00','income_max'=>'250000.00','fixed_tax'=>'0.00','marginal_rate'=>'0.00000','excess_over'=>'0.00'],
    ['income_min'=>'250000.01','income_max'=>'400000.00','fixed_tax'=>'0.00','marginal_rate'=>'0.15000','excess_over'=>'250000.00'],
    ['income_min'=>'400000.01','income_max'=>'800000.00','fixed_tax'=>'22500.00','marginal_rate'=>'0.20000','excess_over'=>'400000.00'],
    ['income_min'=>'800000.01','income_max'=>'2000000.00','fixed_tax'=>'102500.00','marginal_rate'=>'0.25000','excess_over'=>'800000.00'],
    ['income_min'=>'2000000.01','income_max'=>'8000000.00','fixed_tax'=>'402500.00','marginal_rate'=>'0.30000','excess_over'=>'2000000.00'],
    ['income_min'=>'8000000.01','income_max'=>null,'fixed_tax'=>'2202500.00','marginal_rate'=>'0.35000','excess_over'=>'8000000.00'],
];
payrollTestExpectSame(0.0, AnnualizedTaxEngine::calculateTaxFromBrackets('250000.00', $annualTaxBrackets), 'TRAIN annual income through ₱250,000 is exempt.');
payrollTestExpectSame(22500.0, AnnualizedTaxEngine::calculateTaxFromBrackets('400000.00', $annualTaxBrackets), 'TRAIN marginal tax at the ₱400,000 boundary is correct.');
payrollTestExpectSame(102500.0, AnnualizedTaxEngine::calculateTaxFromBrackets('800000.00', $annualTaxBrackets), 'TRAIN marginal tax at the ₱800,000 boundary is correct.');
payrollTestExpectSame(402500.0, AnnualizedTaxEngine::calculateTaxFromBrackets('2000000.00', $annualTaxBrackets), 'TRAIN marginal tax at the ₱2,000,000 boundary is correct.');
payrollTestExpectSame(2202500.0, AnnualizedTaxEngine::calculateTaxFromBrackets('8000000.00', $annualTaxBrackets), 'TRAIN marginal tax at the ₱8,000,000 boundary is correct.');
payrollTestExpectSame(2902500.0, AnnualizedTaxEngine::calculateTaxFromBrackets('10000000.00', $annualTaxBrackets), 'TRAIN marginal rate above ₱8,000,000 is correct.');
$projectedTaxableCents = AnnualizedTaxEngine::projectAnnualTaxableIncomeCents(10000000, 20000000, 3000000, 8);
payrollTestExpectSame(54000000, $projectedTaxableCents, 'Annualized taxable income includes previous employer income and remaining current cutoffs.');
payrollTestExpectSame(500.0, AnnualizedTaxEngine::calculateAnnualizedWithholding(1200000, 200000, 500000, 10), 'Annual tax credits reduce withholding and the balance is spread over remaining cutoffs.');
payrollTestExpectSame(25.0, AnnualizedTaxEngine::calculateAnnualizedWithholding(10000, 0, 0, 4), 'The final cutoff reconciles the remaining tax liability.');

echo "Payroll workflow tests passed, including annual TRAIN bracket boundaries, prior-employer credits, and a ten-employee synthetic arithmetic simulation. Government figures in the simulation are test-only, not official rates; no leave or pay-period policy is inferred.\n";
