<?php

final class GovernmentContributionEngine {
    private const AGENCIES = ['SSS', 'PhilHealth', 'Pag-IBIG'];

    public static function calculate(PDO $db, string|int|float $monthlySalary, string $payrollDate, int $payrollShareCount = 2, int $payrollShareIndex = 1): array {
        $salaryCents = self::validateMonthlySalary($monthlySalary);
        if (!self::isValidDate($payrollDate)) {
            throw new InvalidArgumentException('A valid payroll date is required to calculate contributions.');
        }
        if ($payrollShareCount < 1 || $payrollShareCount > 2) {
            throw new InvalidArgumentException('Payroll contribution split must be one monthly run or two semi-monthly runs.');
        }
        if ($payrollShareIndex < 1 || $payrollShareIndex > $payrollShareCount) {
            throw new InvalidArgumentException('Payroll contribution split index is outside the configured monthly run count.');
        }
        self::requireContributionSchema($db);

        $result = [];
        foreach (self::AGENCIES as $agency) {
            $schedule = self::findSchedule($db, $agency, $payrollDate);
            if (!$schedule) {
                throw new DomainException("$agency has no verified, active contribution schedule for payroll date $payrollDate.");
            }
            $result[$agency] = $agency === 'SSS'
                ? self::calculateSss($db, $schedule, $salaryCents, $payrollShareCount, $payrollShareIndex)
                : self::calculateRuleAgency($db, $agency, $schedule, $salaryCents, $payrollShareCount, $payrollShareIndex);
        }

        $result['totalEmployeeContribution'] = self::fromCents(
            self::toCents($result['SSS']['employee']) +
            self::toCents($result['PhilHealth']['employee']) +
            self::toCents($result['Pag-IBIG']['employee'])
        );
        $result['totalEmployerContribution'] = self::fromCents(
            self::toCents($result['SSS']['employer']) +
            self::toCents($result['SSS']['ecp']) +
            self::toCents($result['PhilHealth']['employer']) +
            self::toCents($result['Pag-IBIG']['employer'])
        );
        $result['payroll_share_count'] = $payrollShareCount;
        $result['payroll_share_index'] = $payrollShareIndex;
        return $result;
    }

    public static function calculateFromRule(array $rule, int $salaryCents, int $payrollShareCount, int $payrollShareIndex = 1): array {
        self::validateShare($payrollShareCount, $payrollShareIndex);
        if ($salaryCents <= 0) {
            throw new InvalidArgumentException('Employee monthly salary must be greater than zero.');
        }
        $baseMode = $rule['contribution_base_mode'] ?? '';
        if (!array_key_exists('employee_rate', $rule) || !array_key_exists('employer_rate', $rule) ||
            !array_key_exists('employee_fixed_amount', $rule) || !array_key_exists('employer_fixed_amount', $rule)) {
            throw new DomainException('Contribution rule is missing employee or employer amounts.');
        }
        if ($baseMode === 'salary_capped' && (!isset($rule['contribution_base_cap']) || self::toCents($rule['contribution_base_cap']) === 0)) {
            throw new DomainException('Capped contribution rule is missing a valid salary cap.');
        }
        if ($baseMode === 'fixed' && (!isset($rule['contribution_base']) || self::toCents($rule['contribution_base']) === 0)) {
            throw new DomainException('Fixed contribution rule is missing a valid contribution base.');
        }
        $baseCents = match ($baseMode) {
            'salary' => $salaryCents,
            'salary_capped' => min($salaryCents, self::toCents($rule['contribution_base_cap'])),
            'fixed' => self::toCents($rule['contribution_base']),
            default => throw new DomainException('Contribution rule has an invalid contribution-base mode.'),
        };
        if (isset($rule['contribution_base_floor']) && $rule['contribution_base_floor'] !== null) {
            $baseCents = max($baseCents, self::toCents($rule['contribution_base_floor']));
        }
        if (isset($rule['contribution_base_floor'], $rule['contribution_base_cap']) &&
            self::toCents($rule['contribution_base_floor']) > self::toCents($rule['contribution_base_cap'])) {
            throw new DomainException('Contribution rule has a floor higher than its cap.');
        }

        $employeeMonthlyCents = self::applyRateAndFixed($baseCents, $rule['employee_rate'], $rule['employee_fixed_amount']);
        $employerMonthlyCents = self::applyRateAndFixed($baseCents, $rule['employer_rate'], $rule['employer_fixed_amount']);
        if ($employeeMonthlyCents + $employerMonthlyCents <= 0) {
            throw new DomainException('Contribution rule results in zero employee and employer contributions.');
        }
        $employeeCents = self::splitCents($employeeMonthlyCents, $payrollShareCount, $payrollShareIndex);
        $employerCents = self::splitCents($employerMonthlyCents, $payrollShareCount, $payrollShareIndex);

        return [
            'base' => self::fromCents($baseCents),
            'employee_monthly' => self::fromCents($employeeMonthlyCents),
            'employer_monthly' => self::fromCents($employerMonthlyCents),
            'employee' => self::fromCents($employeeCents),
            'employer' => self::fromCents($employerCents),
            'total' => self::fromCents($employeeCents + $employerCents),
            'monthly_total' => self::fromCents($employeeMonthlyCents + $employerMonthlyCents),
        ];
    }

    public static function calculateSssFromBracket(array $bracket, int $payrollShareCount, int $payrollShareIndex = 1): array {
        self::validateShare($payrollShareCount, $payrollShareIndex);
        foreach (['msc','regular_ss_employee','regular_ss_employer','mpf_employee','mpf_employer','ec_employer'] as $field) {
            if (!array_key_exists($field, $bracket)) {
                throw new DomainException("SSS bracket is missing required field $field.");
            }
        }
        $mscCents = self::toCents($bracket['msc']);
        $regularEmployeeMonthlyCents = self::toCents($bracket['regular_ss_employee']);
        $mpfEmployeeMonthlyCents = self::toCents($bracket['mpf_employee']);
        $employeeMonthlyCents = $regularEmployeeMonthlyCents + $mpfEmployeeMonthlyCents;
        $employerMonthlyCents = self::toCents($bracket['regular_ss_employer']) + self::toCents($bracket['mpf_employer']);
        $ecEmployerMonthlyCents = self::toCents($bracket['ec_employer']);
        if ($mscCents <= 0 || $employeeMonthlyCents + $employerMonthlyCents + $ecEmployerMonthlyCents <= 0) {
            throw new DomainException('SSS bracket has an invalid MSC or zero contribution amounts.');
        }
        $employeeCents = self::splitCents($employeeMonthlyCents, $payrollShareCount, $payrollShareIndex);
        $mpfEmployeeCents = self::splitCents($mpfEmployeeMonthlyCents, $payrollShareCount, $payrollShareIndex);
        $employerCents = self::splitCents($employerMonthlyCents, $payrollShareCount, $payrollShareIndex);
        $ecpCents = self::splitCents($ecEmployerMonthlyCents, $payrollShareCount, $payrollShareIndex);

        return [
            'base' => self::fromCents($mscCents),
            'msc' => self::fromCents($mscCents),
            'employee_monthly' => self::fromCents($employeeMonthlyCents),
            'regular_ss_employee_monthly' => self::fromCents($regularEmployeeMonthlyCents),
            'mpf_employee_monthly' => self::fromCents($mpfEmployeeMonthlyCents),
            'employer_monthly' => self::fromCents($employerMonthlyCents),
            'ecp_monthly' => self::fromCents($ecEmployerMonthlyCents),
            'employee' => self::fromCents($employeeCents),
            'regular_ss_employee' => self::fromCents($employeeCents - $mpfEmployeeCents),
            'mpf_employee' => self::fromCents($mpfEmployeeCents),
            'employer' => self::fromCents($employerCents),
            'ecp' => self::fromCents($ecpCents),
            'total' => self::fromCents($employeeCents + $employerCents + $ecpCents),
        ];
    }

    public static function isValidDate(string $date): bool {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    public static function shareIndexForPayrollDate(string $payrollDate, int $shareCount = 2): int {
        if (!self::isValidDate($payrollDate) || $shareCount < 1 || $shareCount > 2) {
            throw new InvalidArgumentException('A valid payroll date and supported contribution split are required.');
        }
        return $shareCount === 1 || (int)substr($payrollDate, 8, 2) <= 15 ? 1 : 2;
    }

    public static function validateMonthlySalary(string|int|float $salary): int {
        $salaryCents = self::toCents($salary);
        if ($salaryCents <= 0) {
            throw new InvalidArgumentException('Employee monthly salary must be greater than zero.');
        }
        return $salaryCents;
    }

    public static function scheduleAppliesOnDate(array $schedule, string $date): bool {
        return self::isValidDate($date) &&
            ($schedule['status'] ?? '') === 'active' &&
            ($schedule['approval_status'] ?? 'approved') === 'approved' &&
            !empty($schedule['verified_at']) &&
            ($schedule['effective_from'] ?? '') <= $date &&
            (empty($schedule['effective_until']) || $schedule['effective_until'] >= $date);
    }

    public static function toCents(string|int|float $amount): int {
        $value = trim((string)$amount);
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException('Monetary values must be non-negative amounts with at most two decimal places.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');
        $cents = ((int)$whole * 100) + (int)substr($fraction, 0, 2);
        if ((int)$fraction[2] >= 5) $cents++;
        return $cents;
    }

    public static function fromCents(int $cents): string {
        $sign = $cents < 0 ? '-' : '';
        $absoluteCents = abs($cents);
        return $sign . intdiv($absoluteCents, 100) . '.' . str_pad((string)($absoluteCents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function findSchedule(PDO $db, string $agency, string $payrollDate): ?array {
        $stmt = $db->prepare(
            "SELECT * FROM government_contribution_schedules
             WHERE agency = ? AND status = 'active' AND approval_status = 'approved' AND verified_at IS NOT NULL
               AND effective_from <= ? AND (effective_until IS NULL OR effective_until >= ?)
             ORDER BY effective_from DESC LIMIT 2"
        );
        $stmt->execute([$agency, $payrollDate, $payrollDate]);
        $schedules = $stmt->fetchAll();
        if (count($schedules) > 1) {
            throw new DomainException("$agency has overlapping active schedules for payroll date $payrollDate.");
        }
        return $schedules[0] ?? null;
    }

    private static function requireContributionSchema(PDO $db): void {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN (?, ?, ?)
        ");
        $stmt->execute([
            'government_contribution_schedules',
            'government_contribution_rules',
            'sss_contribution_table',
        ]);
        if ((int)$stmt->fetchColumn() !== 3) {
            throw new DomainException('Government contribution tables are not installed. Apply config/government_contributions_migration.sql before generating payroll.');
        }
    }

    private static function calculateSss(PDO $db, array $schedule, int $salaryCents, int $payrollShareCount, int $payrollShareIndex): array {
        $salary = self::fromCents($salaryCents);
        $stmt = $db->prepare(
            'SELECT * FROM sss_contribution_table
             WHERE schedule_id = ? AND salary_min <= ?
               AND (salary_max IS NULL OR salary_max >= ?)
             ORDER BY salary_min DESC LIMIT 2'
        );
        $stmt->execute([$schedule['id'], $salary, $salary]);
        $brackets = $stmt->fetchAll();
        if (count($brackets) !== 1) {
            throw new DomainException("No unique SSS compensation bracket is configured for salary ₱$salary.");
        }
        $result = self::calculateSssFromBracket($brackets[0], $payrollShareCount, $payrollShareIndex);
        $result['schedule'] = self::scheduleSnapshot($schedule);
        return $result;
    }

    private static function calculateRuleAgency(PDO $db, string $agency, array $schedule, int $salaryCents, int $payrollShareCount, int $payrollShareIndex): array {
        $salary = self::fromCents($salaryCents);
        $stmt = $db->prepare(
            'SELECT * FROM government_contribution_rules
             WHERE schedule_id = ? AND salary_min <= ?
               AND (salary_max IS NULL OR salary_max >= ?)
             ORDER BY salary_min DESC LIMIT 2'
        );
        $stmt->execute([$schedule['id'], $salary, $salary]);
        $rules = $stmt->fetchAll();
        if (count($rules) !== 1) {
            throw new DomainException("No unique $agency contribution rule is configured for salary ₱$salary.");
        }
        $result = self::calculateFromRule($rules[0], $salaryCents, $payrollShareCount, $payrollShareIndex);
        $result['schedule'] = self::scheduleSnapshot($schedule);
        return $result;
    }

    private static function scheduleSnapshot(array $schedule): array {
        return [
            'id' => (int)$schedule['id'],
            'effective_from' => $schedule['effective_from'],
            'effective_until' => $schedule['effective_until'],
            'source_reference' => $schedule['source_reference'],
        ];
    }

    private static function applyRateAndFixed(int $baseCents, string|int|float $rate, string|int|float $fixed): int {
        $rateText = trim((string)$rate);
        if (!preg_match('/^\d{1,3}(?:\.\d{1,5})?$/', $rateText)) {
            throw new DomainException('Contribution rule percentage must be between 0 and 100.');
        }
        [$whole, $fraction] = array_pad(explode('.', $rateText, 2), 2, '');
        $rateUnits = ((int)$whole * 100000) + (int)str_pad($fraction, 5, '0');
        if ($rateUnits > 10000000) {
            throw new DomainException('Contribution rule percentage must be between 0 and 100.');
        }
        $denominator = 10000000;
        $percentageCents =
            (intdiv($baseCents, $denominator) * $rateUnits) +
            intdiv((($baseCents % $denominator) * $rateUnits) + intdiv($denominator, 2), $denominator);
        return $percentageCents + self::toCents($fixed);
    }

    private static function splitCents(int $monthlyCents, int $shareCount, int $shareIndex): int {
        $base = intdiv($monthlyCents, $shareCount);
        $remainder = $monthlyCents % $shareCount;
        return $base + ($shareIndex > $shareCount - $remainder ? 1 : 0);
    }

    private static function validateShare(int $shareCount, int $shareIndex): void {
        if ($shareCount < 1 || $shareCount > 2 || $shareIndex < 1 || $shareIndex > $shareCount) {
            throw new InvalidArgumentException('Payroll contribution split and run index are invalid.');
        }
    }
}

function calculateGovernmentContributions(PDO $db, string|int|float $monthlySalary, string $payrollDate, int $payrollShareCount = 2, int $payrollShareIndex = 1): array {
    return GovernmentContributionEngine::calculate($db, $monthlySalary, $payrollDate, $payrollShareCount, $payrollShareIndex);
}
