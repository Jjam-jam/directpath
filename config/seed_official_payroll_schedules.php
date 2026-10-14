<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';

$db = getDB();
$db->beginTransaction();

try {
    $adminStmt = $db->query("
        SELECT employee_id, CONCAT(first_name, ' ', last_name) AS name
        FROM users
        WHERE role='superadmin'
        ORDER BY employee_id
        LIMIT 1
    ");
    $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        throw new RuntimeException('A Super Admin account is required to seed auditable payroll schedules.');
    }

    $auditInsert = $db->prepare("
        INSERT INTO audit_logs (user_id, user_name, action, module, details, ip_address)
        VALUES (?, ?, ?, ?, ?, '127.0.0.1')
    ");
    $seedSchedule = static function (
        string $agency,
        string $name,
        string $effectiveFrom,
        string $sourceReference,
        string $sourceUrl
    ) use ($db, $admin, $auditInsert): int {
        $find = $db->prepare("
            SELECT id FROM government_contribution_schedules
            WHERE agency=? AND schedule_name=? AND effective_from=?
        ");
        $find->execute([$agency, $name, $effectiveFrom]);
        $scheduleId = $find->fetchColumn();
        if ($scheduleId !== false) {
            return (int)$scheduleId;
        }
        $db->prepare("
            INSERT INTO government_contribution_schedules
                (agency, schedule_name, effective_from, effective_until, status,
                 source_reference, source_url, created_by)
            VALUES (?, ?, ?, NULL, 'pending', ?, ?, ?)
        ")->execute([$agency, $name, $effectiveFrom, $sourceReference, $sourceUrl, $admin['employee_id']]);
        $scheduleId = (int)$db->lastInsertId();
        $auditInsert->execute([
            $admin['employee_id'],
            $admin['name'],
            'Seed Contribution Schedule',
            'Government Contributions',
            json_encode([
                'agency' => $agency,
                'schedule_name' => $name,
                'effective_from' => $effectiveFrom,
                'status' => 'pending',
                'source_reference' => $sourceReference,
                'source_url' => $sourceUrl,
            ], JSON_THROW_ON_ERROR),
        ]);
        return $scheduleId;
    };

    $sssId = $seedSchedule(
        'SSS',
        'SSS Contribution Schedule 2025',
        '2025-01-01',
        'SSS Circular No. 2024-006 (January 2025 schedule; applicability of later 2026 amendments requires confirmation)',
        'https://www.sss.gov.ph/wp-content/uploads/2024/12/Cir-2024-006-Employers-scaled.jpg'
    );
    $sssCountStmt = $db->prepare('SELECT COUNT(*) FROM sss_contribution_table WHERE schedule_id=?');
    $sssCountStmt->execute([$sssId]);
    $sssCount = (int)$sssCountStmt->fetchColumn();
    if ($sssCount === 0) {
        $insertSss = $db->prepare("
            INSERT INTO sss_contribution_table
                (schedule_id, salary_min, salary_max, msc, regular_ss_employee,
                 regular_ss_employer, mpf_employee, mpf_employer, ec_employee, ec_employer)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?)
        ");
        for ($msc = 5000; $msc <= 35000; $msc += 500) {
            if ($msc === 5000) {
                $salaryMin = 0;
                $salaryMax = 524999;
            } elseif ($msc === 35000) {
                $salaryMin = 3475000;
                $salaryMax = null;
            } else {
                $salaryMin = ($msc - 250) * 100;
                $salaryMax = ($msc + 249) * 100 + 99;
            }
            $regularBase = min($msc, 20000);
            $mpfBase = max(0, $msc - 20000);
            $insertSss->execute([
                $sssId,
                number_format($salaryMin / 100, 2, '.', ''),
                $salaryMax === null ? null : number_format($salaryMax / 100, 2, '.', ''),
                number_format($msc, 2, '.', ''),
                number_format($regularBase * 0.05, 2, '.', ''),
                number_format($regularBase * 0.10, 2, '.', ''),
                number_format($mpfBase * 0.05, 2, '.', ''),
                number_format($mpfBase * 0.10, 2, '.', ''),
                number_format($msc < 15000 ? 10 : 30, 2, '.', ''),
            ]);
        }
    } elseif ($sssCount !== 61) {
        throw new RuntimeException("The seeded SSS schedule has $sssCount brackets; expected 61. No existing rows were changed.");
    }

    $philhealthId = $seedSchedule(
        'PhilHealth',
        'PhilHealth Contribution Schedule 2025',
        '2025-01-01',
        'PhilHealth Circular No. 2019-0009; official premium contribution table (later 2026 amendments require confirmation)',
        'https://www.philhealth.gov.ph/partners/employers/ContributionTable_v2.pdf'
    );
    $seedRule = static function (int $scheduleId, int $expectedCount, array $rows) use ($db): void {
        $countStmt = $db->prepare('SELECT COUNT(*) FROM government_contribution_rules WHERE schedule_id=?');
        $countStmt->execute([$scheduleId]);
        $count = (int)$countStmt->fetchColumn();
        if ($count === $expectedCount) {
            return;
        }
        if ($count !== 0) {
            throw new RuntimeException("A seeded contribution schedule has $count rule rows; expected $expectedCount. Existing rows were preserved.");
        }
        $insert = $db->prepare("
            INSERT INTO government_contribution_rules
                (schedule_id, salary_min, salary_max, contribution_base_mode,
                 contribution_base_floor, contribution_base_cap, employee_rate, employer_rate,
                 employee_fixed_amount, employer_fixed_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0.00, 0.00)
        ");
        foreach ($rows as $row) {
            $insert->execute(array_merge([$scheduleId], $row));
        }
    };

    $seedRule($philhealthId, 1, [[
        '0.00', null, 'salary_capped', '10000.00', '100000.00', '2.50000', '2.50000',
    ]]);

    $pagibigId = $seedSchedule(
        'Pag-IBIG',
        'Pag-IBIG Contribution Schedule 2024',
        '2024-02-01',
        'Pag-IBIG Fund Circular No. 460; DBM Circular Letter No. 2024-2 (₱10,000 cap and later 2026 amendments require confirmation)',
        'https://www.dbm.gov.ph/wp-content/uploads/Issuances/2024/Circular-Letter/CIRCULAR-LETTER-NO-2024-2-DATED-FEBRUARY-01-2024.pdf'
    );
    $seedRule($pagibigId, 2, [
        ['0.00', '1500.00', 'salary_capped', null, '10000.00', '1.00000', '2.00000'],
        ['1500.01', null, 'salary_capped', null, '10000.00', '2.00000', '2.00000'],
    ]);

    $taxName = 'BIR TRAIN Annual Income Tax Schedule 2023 Onward';
    $taxStart = '2023-01-01';
    $findTax = $db->prepare('SELECT id FROM income_tax_schedules WHERE schedule_name=? AND effective_from=?');
    $findTax->execute([$taxName, $taxStart]);
    $taxScheduleId = $findTax->fetchColumn();
    if ($taxScheduleId === false) {
        $db->prepare("
            INSERT INTO income_tax_schedules
                (schedule_name, effective_from, effective_until, status, source_reference, source_url, created_by)
            VALUES (?, ?, NULL, 'active', ?, ?, ?)
        ")->execute([
            $taxName,
            $taxStart,
            'Republic Act No. 10963 (TRAIN), Section 5; BIR Revenue Regulations No. 8-2018; annual rates effective taxable year 2023',
            'https://www.officialgazette.gov.ph/downloads/2017/12dec/20171219-RA-10963-RRD.pdf',
            $admin['employee_id'],
        ]);
        $taxScheduleId = (int)$db->lastInsertId();
        $auditInsert->execute([
            $admin['employee_id'],
            $admin['name'],
            'Seed Annual Income Tax Schedule',
            'Payroll Tax',
            json_encode([
                'schedule_name' => $taxName,
                'effective_from' => $taxStart,
                'status' => 'active',
                'source_reference' => 'Republic Act No. 10963, Section 5; BIR Revenue Regulations No. 8-2018',
            ], JSON_THROW_ON_ERROR),
        ]);
    } else {
        $taxScheduleId = (int)$taxScheduleId;
    }
    $taxBracketStmt = $db->prepare('SELECT COUNT(*) FROM income_tax_brackets WHERE schedule_id=?');
    $taxBracketStmt->execute([$taxScheduleId]);
    $taxBracketCount = (int)$taxBracketStmt->fetchColumn();
    if ($taxBracketCount === 0) {
        $insertTaxBracket = $db->prepare("
            INSERT INTO income_tax_brackets
                (schedule_id, income_min, income_max, fixed_tax, marginal_rate, excess_over)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $taxBrackets = [
            ['0.00', '250000.00', '0.00', '0.00000', '0.00'],
            ['250000.01', '400000.00', '0.00', '0.15000', '250000.00'],
            ['400000.01', '800000.00', '22500.00', '0.20000', '400000.00'],
            ['800000.01', '2000000.00', '102500.00', '0.25000', '800000.00'],
            ['2000000.01', '8000000.00', '402500.00', '0.30000', '2000000.00'],
            ['8000000.01', null, '2202500.00', '0.35000', '8000000.00'],
        ];
        foreach ($taxBrackets as $bracket) {
            $insertTaxBracket->execute(array_merge([$taxScheduleId], $bracket));
        }
    } elseif ($taxBracketCount !== 6) {
        throw new RuntimeException("The seeded annual tax schedule has $taxBracketCount brackets; expected 6. Existing rows were preserved.");
    }

    $db->commit();
    echo "Official schedule records seeded idempotently. Government contribution schedules are drafts pending confirmation of applicability to 2026; the sourced TRAIN annual schedule is active.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Payroll schedule seeding failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
