<?php

final class AnnualizedTaxEngine {
    public static function calculateTaxFromBrackets(string|int|float $annualTaxableIncome, array $brackets): float {
        $incomeCents = GovernmentContributionEngine::toCents($annualTaxableIncome);
        if ($incomeCents < 0 || $brackets === []) {
            throw new InvalidArgumentException('Annual taxable income and tax brackets must be valid.');
        }

        $matchingBrackets = [];
        foreach ($brackets as $bracket) {
            foreach (['income_min', 'fixed_tax', 'marginal_rate', 'excess_over'] as $field) {
                if (!array_key_exists($field, $bracket)) {
                    throw new DomainException("Annual tax bracket is missing $field.");
                }
            }
            $minimum = GovernmentContributionEngine::toCents($bracket['income_min']);
            $maximum = $bracket['income_max'] === null
                ? PHP_INT_MAX
                : GovernmentContributionEngine::toCents($bracket['income_max']);
            if ($incomeCents >= $minimum && $incomeCents <= $maximum) {
                $matchingBrackets[] = $bracket;
            }
        }
        if (count($matchingBrackets) !== 1) {
            throw new DomainException('Annual taxable income does not match exactly one effective tax bracket.');
        }

        $bracket = $matchingBrackets[0];
        $rate = trim((string)$bracket['marginal_rate']);
        if (!preg_match('/^(?:0(?:\.\d{1,5})?|1(?:\.0{1,5})?)$/', $rate)) {
            throw new DomainException('Annual tax bracket marginal rate must be between zero and one.');
        }
        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');
        $rateUnits = ((int)$whole * 100000) + (int)str_pad($fraction, 5, '0');
        $excessCents = max(0, $incomeCents - GovernmentContributionEngine::toCents($bracket['excess_over']));
        $marginalTaxCents = intdiv(($excessCents * $rateUnits) + 50000, 100000);
        return GovernmentContributionEngine::fromCents(
            GovernmentContributionEngine::toCents($bracket['fixed_tax']) + $marginalTaxCents
        );
    }

    public static function calculateAnnualizedWithholding(
        int $annualTaxCents,
        int $previousEmployerWithholdingCents,
        int $currentEmployerWithholdingYtdCents,
        int $remainingPeriods
    ): float {
        if (min(
            $annualTaxCents,
            $previousEmployerWithholdingCents,
            $currentEmployerWithholdingYtdCents
        ) < 0 || $remainingPeriods < 1) {
            throw new InvalidArgumentException('Annual tax inputs and remaining payroll periods must be non-negative.');
        }
        $remainingTaxCents = max(
            0,
            $annualTaxCents - $previousEmployerWithholdingCents - $currentEmployerWithholdingYtdCents
        );
        $withholdingCents = intdiv($remainingTaxCents + intdiv($remainingPeriods, 2), $remainingPeriods);
        return GovernmentContributionEngine::fromCents($withholdingCents);
    }

    public static function projectAnnualTaxableIncomeCents(
        int $previousEmployerIncomeCents,
        int $currentEmployerIncomeYtdCents,
        int $currentPeriodIncomeCents,
        int $remainingPeriods
    ): int {
        if (min($previousEmployerIncomeCents, $currentEmployerIncomeYtdCents, $currentPeriodIncomeCents) < 0
            || $remainingPeriods < 1) {
            throw new InvalidArgumentException('Taxable income inputs and remaining payroll periods must be non-negative.');
        }
        return $previousEmployerIncomeCents + $currentEmployerIncomeYtdCents +
            ($currentPeriodIncomeCents * $remainingPeriods);
    }

    public static function calculateForPayroll(
        PDO $db,
        string $employeeId,
        string|int|float $periodTaxableIncome,
        string $payrollDate
    ): float {
        if (!GovernmentContributionEngine::isValidDate($payrollDate)) {
            throw new InvalidArgumentException('A valid payroll date is required for annualized withholding tax.');
        }
        $periodIndex = self::semiMonthlyPeriodIndex($payrollDate);
        $remainingPeriods = 25 - $periodIndex;
        $year = (int)substr($payrollDate, 0, 4);
        $yearStart = sprintf('%04d-01-01', $year);

        $schemaStmt = $db->query("SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema=DATABASE() AND table_name IN ('income_tax_schedules','income_tax_brackets')");
        if ((int)$schemaStmt->fetchColumn() !== 2) {
            throw new DomainException('Annual income tax tables are not installed. Apply config/payroll_tax_migration.sql.');
        }

        $scheduleStmt = $db->prepare("
            SELECT s.id
            FROM income_tax_schedules s
            WHERE s.status='active' AND s.effective_from <= ?
              AND (s.effective_until IS NULL OR s.effective_until >= ?)
            ORDER BY s.effective_from DESC
            LIMIT 2
        ");
        $scheduleStmt->execute([$payrollDate, $payrollDate]);
        $schedules = $scheduleStmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($schedules) !== 1) {
            throw new DomainException("No unique active annual income tax schedule applies to payroll date $payrollDate.");
        }

        $bracketStmt = $db->prepare("
            SELECT income_min, income_max, fixed_tax, marginal_rate, excess_over
            FROM income_tax_brackets
            WHERE schedule_id=?
            ORDER BY income_min
        ");
        $bracketStmt->execute([(int)$schedules[0]]);
        $brackets = $bracketStmt->fetchAll(PDO::FETCH_ASSOC);

        $payrollStmt = $db->prepare("
            SELECT payroll_calculation_snapshot, gross_pay, bonuses, overtime_pay, salary_adjustment,
                   late_deduction, undertime_deduction, lwp_deduction, de_minimis,
                   sss, sss_mpf, philhealth, pagibig, withholding_tax, net_salary
            FROM payroll
            WHERE employee_id=? AND payroll_period_end >= ? AND payroll_period_end < ?
              AND status IN ('generated','released')
            ORDER BY payroll_period_end, id
        ");
        $payrollStmt->execute([$employeeId, $yearStart, $payrollDate]);
        $priorPayrollRows = $payrollStmt->fetchAll(PDO::FETCH_ASSOC);
        $previousStmt = $db->prepare("
            SELECT taxable_income, non_taxable_income, sss, philhealth, hdmf, withholding_tax
            FROM payroll_previous_employer_ytd
            WHERE employee_id=? AND tax_year=?
        ");
        $previousStmt->execute([$employeeId, $year]);
        $previousEmployer = $previousStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $yearToDate = summarizePayrollYearToDate($priorPayrollRows, $previousEmployer);

        $periodIncomeCents = GovernmentContributionEngine::toCents(max(0.0, (float)$periodTaxableIncome));
        $previousIncomeCents = GovernmentContributionEngine::toCents($yearToDate['previous_employer_income']);
        $currentIncomeYtdCents = GovernmentContributionEngine::toCents($yearToDate['taxable_income']);
        $annualTaxableIncomeCents = self::projectAnnualTaxableIncomeCents(
            $previousIncomeCents,
            $currentIncomeYtdCents,
            $periodIncomeCents,
            $remainingPeriods
        );
        $annualTaxableIncome = GovernmentContributionEngine::fromCents($annualTaxableIncomeCents);
        $annualTaxCents = GovernmentContributionEngine::toCents(
            self::calculateTaxFromBrackets($annualTaxableIncome, $brackets)
        );
        $previousWithholdingCents = GovernmentContributionEngine::toCents($yearToDate['previous_employer_withholding_tax']);
        $currentWithholdingStmt = $db->prepare("
            SELECT COALESCE(SUM(withholding_tax), 0)
            FROM payroll
            WHERE employee_id=? AND payroll_period_end >= ? AND payroll_period_end < ?
              AND status IN ('generated','released')
        ");
        $currentWithholdingStmt->execute([$employeeId, $yearStart, $payrollDate]);
        $currentWithholdingYtdCents = GovernmentContributionEngine::toCents((string)$currentWithholdingStmt->fetchColumn());

        return self::calculateAnnualizedWithholding(
            $annualTaxCents,
            $previousWithholdingCents,
            $currentWithholdingYtdCents,
            $remainingPeriods
        );
    }

    private static function semiMonthlyPeriodIndex(string $payrollDate): int {
        $month = (int)substr($payrollDate, 5, 2);
        $day = (int)substr($payrollDate, 8, 2);
        return (($month - 1) * 2) + ($day <= 15 ? 1 : 2);
    }
}
