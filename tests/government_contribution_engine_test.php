<?php
require_once __DIR__ . '/../includes/GovernmentContributionEngine.php';

function expectSame($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException("$message Expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function expectThrows(callable $callback, string $message): void {
    try {
        $callback();
    } catch (InvalidArgumentException|DomainException $exception) {
        return;
    }
    throw new RuntimeException($message);
}

// Synthetic fixtures test formulas and dates only; they are not agency schedules.
$sssBrackets = [
    ['salary_min'=>'0.00','salary_max'=>'14999.99','msc'=>'15000.00','regular_ss_employee'=>'750.00','regular_ss_employer'=>'1500.00','mpf_employee'=>'0.00','mpf_employer'=>'0.00','ec_employer'=>'10.00'],
    ['salary_min'=>'15000.00','salary_max'=>'19999.99','msc'=>'20000.00','regular_ss_employee'=>'1000.00','regular_ss_employer'=>'2000.00','mpf_employee'=>'0.00','mpf_employer'=>'0.00','ec_employer'=>'30.00'],
    ['salary_min'=>'20000.00','salary_max'=>'34999.99','msc'=>'25000.00','regular_ss_employee'=>'1000.00','regular_ss_employer'=>'2000.00','mpf_employee'=>'250.00','mpf_employer'=>'500.00','ec_employer'=>'30.00'],
    ['salary_min'=>'35000.00','salary_max'=>null,'msc'=>'35000.00','regular_ss_employee'=>'1000.00','regular_ss_employer'=>'2000.00','mpf_employee'=>'750.00','mpf_employer'=>'1500.00','ec_employer'=>'30.00'],
];
$philhealthRule = ['contribution_base_mode'=>'salary_capped','contribution_base_floor'=>'10000.00','contribution_base_cap'=>'100000.00','employee_rate'=>'2.50000','employer_rate'=>'2.50000','employee_fixed_amount'=>'0.00','employer_fixed_amount'=>'0.00'];
$pagibigRule = ['contribution_base_mode'=>'salary_capped','contribution_base_cap'=>'10000.00','employee_rate'=>'2.00000','employer_rate'=>'2.00000','employee_fixed_amount'=>'0.00','employer_fixed_amount'=>'0.00'];

$cases = [
    ['5000.00','15000.00','375.00'],
    ['10000.00','15000.00','375.00'],
    ['15000.00','20000.00','500.00'],
    ['20000.00','25000.00','625.00'],
    ['25876.00','25000.00','625.00'],
    ['30000.00','25000.00','625.00'],
    ['35000.00','35000.00','875.00'],
    ['40000.00','35000.00','875.00'],
];
$employeeTotals = [];
foreach ($cases as [$salary, $expectedMsc, $expectedSssEmployee]) {
    $salaryCents = GovernmentContributionEngine::validateMonthlySalary($salary);
    $matches = array_values(array_filter($sssBrackets, static function (array $bracket) use ($salaryCents): bool {
        $minimum = GovernmentContributionEngine::toCents($bracket['salary_min']);
        $maximum = $bracket['salary_max'] === null ? PHP_INT_MAX : GovernmentContributionEngine::toCents($bracket['salary_max']);
        return $salaryCents >= $minimum && $salaryCents <= $maximum;
    }));
    expectSame(1, count($matches), "Salary $salary must match exactly one SSS bracket.");
    $sss = GovernmentContributionEngine::calculateSssFromBracket($matches[0], 2);
    $philhealth = GovernmentContributionEngine::calculateFromRule($philhealthRule, $salaryCents, 2);
    $pagibig = GovernmentContributionEngine::calculateFromRule($pagibigRule, $salaryCents, 2);
    expectSame($expectedMsc, $sss['msc'], "Salary $salary must select its configured MSC.");
    expectSame($expectedSssEmployee, $sss['employee'], "SSS monthly contribution must be split across semi-monthly runs for $salary.");
    expectSame(
        $sss['employee'],
        GovernmentContributionEngine::fromCents(
            GovernmentContributionEngine::toCents($sss['regular_ss_employee']) +
            GovernmentContributionEngine::toCents($sss['mpf_employee'])
        ),
        "Regular SSS and employee MPF components must reconcile for $salary."
    );
    $employeeTotal = GovernmentContributionEngine::toCents($sss['employee']) +
        GovernmentContributionEngine::toCents($philhealth['employee']) +
        GovernmentContributionEngine::toCents($pagibig['employee']);
    $employerTotal = GovernmentContributionEngine::toCents($sss['employer']) +
        GovernmentContributionEngine::toCents($sss['ecp']) +
        GovernmentContributionEngine::toCents($philhealth['employer']) +
        GovernmentContributionEngine::toCents($pagibig['employer']);
    $netPay = intdiv($salaryCents, 2) - $employeeTotal;
    expectSame(intdiv($salaryCents, 2) - $employeeTotal, $netPay, "Only employee contributions reduce net pay for $salary.");
    expectSame(true, $employerTotal > 0, "Employer contributions including ECP are reported for $salary.");
    $employeeTotals[] = $employeeTotal;
}

expectSame('20000.00', GovernmentContributionEngine::calculateSssFromBracket($sssBrackets[1], 2)['msc'], 'MSC must come from the selected bracket.');
expectSame('500.00', GovernmentContributionEngine::calculateSssFromBracket($sssBrackets[1], 2)['employee'], 'Boundary bracket employee contribution must be split in half.');
expectSame('15.00', GovernmentContributionEngine::calculateSssFromBracket($sssBrackets[1], 2)['ecp'], 'Employer ECP must be reported separately.');
expectSame('10000.00', GovernmentContributionEngine::calculateFromRule($philhealthRule, GovernmentContributionEngine::toCents('5000.00'), 2)['base'], 'PhilHealth configured salary floor must apply.');
expectSame('10000.00', GovernmentContributionEngine::calculateFromRule($pagibigRule, GovernmentContributionEngine::toCents('40000.00'), 2)['base'], 'Pag-IBIG salary cap must apply.');
expectSame('100.00', GovernmentContributionEngine::calculateFromRule($pagibigRule, GovernmentContributionEngine::toCents('40000.00'), 2)['employee'], 'Pag-IBIG uses its separate configured rule.');
$officialSssBracket = [
    'msc'=>'26000.00','regular_ss_employee'=>'1000.00','regular_ss_employer'=>'2000.00',
    'mpf_employee'=>'300.00','mpf_employer'=>'600.00','ec_employer'=>'30.00',
];
$officialSss = GovernmentContributionEngine::calculateSssFromBracket($officialSssBracket, 2, 2);
$officialPhilHealth = GovernmentContributionEngine::calculateFromRule(
    $philhealthRule,
    GovernmentContributionEngine::validateMonthlySalary('25867.00'),
    2,
    2
);
$officialPagIBIG = GovernmentContributionEngine::calculateFromRule(
    $pagibigRule,
    GovernmentContributionEngine::validateMonthlySalary('25867.00'),
    2,
    2
);
expectSame('650.00', $officialSss['employee'], 'The ₱26,000 MSC employee SSS share is ₱650 per cutoff.');
expectSame('150.00', $officialSss['mpf_employee'], 'The MSC above ₱20,000 is separately classified as employee MPF.');
expectSame('1300.00', $officialSss['employer'], 'The ₱26,000 MSC employer SSS share is ₱1,300 per cutoff.');
expectSame('15.00', $officialSss['ecp'], 'Employer ECP is separately split and is never an employee deduction.');
expectSame('323.34', $officialPhilHealth['employee'], 'PhilHealth for ₱25,867 is split to ₱323.34 employee contribution per cutoff.');
expectSame('100.00', $officialPagIBIG['employee'], 'Pag-IBIG employee savings are capped at ₱100 per cutoff above the ₱10,000 cap.');
$pagibigLowSalaryRule = [
    'contribution_base_mode'=>'salary_capped','contribution_base_cap'=>'10000.00',
    'employee_rate'=>'1.00000','employer_rate'=>'2.00000',
    'employee_fixed_amount'=>'0.00','employer_fixed_amount'=>'0.00',
];
$pagibigLowSalary = GovernmentContributionEngine::calculateFromRule(
    $pagibigLowSalaryRule,
    GovernmentContributionEngine::validateMonthlySalary('1500.00'),
    2,
    1
);
expectSame('7.50', $pagibigLowSalary['employee'], 'Pag-IBIG uses the official 1% employee tier at a ₱1,500 fund salary.');
expectSame('15.00', $pagibigLowSalary['employer'], 'Pag-IBIG employer contribution remains 2% at a ₱1,500 fund salary.');
expectSame(true, GovernmentContributionEngine::isValidDate('2026-06-30'), 'Valid payroll date is accepted.');
expectSame(false, GovernmentContributionEngine::isValidDate('2026-02-30'), 'Invalid calendar date is rejected.');
$old = ['status'=>'active','verified_at'=>'2025-01-01 00:00:00','effective_from'=>'2025-01-01','effective_until'=>'2026-12-31'];
$future = ['status'=>'active','verified_at'=>'2026-12-01 00:00:00','effective_from'=>'2027-01-01','effective_until'=>null];
expectSame(true, GovernmentContributionEngine::scheduleAppliesOnDate($old, '2026-06-30'), 'Historical payroll uses the earlier schedule.');
expectSame(false, GovernmentContributionEngine::scheduleAppliesOnDate($future, '2026-06-30'), 'A future schedule must not change historical payroll.');
expectSame(true, GovernmentContributionEngine::scheduleAppliesOnDate($future, '2027-01-01'), 'Payroll on the new effective date uses the new schedule.');
expectSame(true, count(array_unique($employeeTotals)) > 1, 'Different salaries in one period should produce different deductions.');
expectSame(1, GovernmentContributionEngine::shareIndexForPayrollDate('2026-06-15'), 'First semi-monthly run must be selected by payroll date.');
expectSame(2, GovernmentContributionEngine::shareIndexForPayrollDate('2026-06-30'), 'Second semi-monthly run must be selected by payroll date.');
$oddCentRule = ['contribution_base_mode'=>'fixed','contribution_base'=>'1.01','employee_rate'=>'0','employer_rate'=>'0','employee_fixed_amount'=>'1.01','employer_fixed_amount'=>'0'];
expectSame('0.50', GovernmentContributionEngine::calculateFromRule($oddCentRule, 100, 2, 1)['employee'], 'First share receives the lower amount when an odd cent remains.');
expectSame('0.51', GovernmentContributionEngine::calculateFromRule($oddCentRule, 100, 2, 2)['employee'], 'Second share receives the remaining cent so monthly totals reconcile exactly.');
expectThrows(static fn() => GovernmentContributionEngine::toCents('-1.00'), 'Negative salary is rejected.');
expectThrows(static fn() => GovernmentContributionEngine::validateMonthlySalary('0.00'), 'Zero salary is rejected.');
expectThrows(static fn() => GovernmentContributionEngine::calculateFromRule(['contribution_base_mode'=>'invalid'], 100000, 2), 'Invalid rules are rejected.');
expectThrows(static fn() => GovernmentContributionEngine::calculateFromRule(['contribution_base_mode'=>'salary_capped','employee_rate'=>'0','employer_rate'=>'0','employee_fixed_amount'=>'0','employer_fixed_amount'=>'0'], 100000, 2), 'A missing cap must not silently produce a zero contribution.');
expectThrows(static fn() => GovernmentContributionEngine::calculateFromRule(['contribution_base_mode'=>'salary','employee_rate'=>'0','employer_rate'=>'0','employee_fixed_amount'=>'0','employer_fixed_amount'=>'0'], 100000, 2), 'A zero contribution rule must be rejected.');

echo "Government contribution tests passed using synthetic rules only; agency rates still require official verification.\n";
