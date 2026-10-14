<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/attendance_rules.php';

$apply = in_array('--apply', $argv, true);
$db = getDB();
$db->beginTransaction();

try {
    $actorStmt = $db->query("
        SELECT employee_id, CONCAT(first_name, ' ', last_name) AS name
        FROM users
        WHERE role='superadmin'
        ORDER BY employee_id
        LIMIT 1
    ");
    $actor = $actorStmt->fetch(PDO::FETCH_ASSOC);
    if (!$actor) {
        throw new RuntimeException('A Super Admin account is required to audit the historical payroll backfill.');
    }

    $payrollIds = $db->query("
        SELECT id
        FROM payroll
        WHERE status IN ('generated','released')
          AND payroll_calculation_snapshot IS NULL
        ORDER BY payroll_period_end, id
    ")->fetchAll(PDO::FETCH_COLUMN);
    $payrollLock = $db->prepare('SELECT * FROM payroll WHERE id=? FOR UPDATE');
    $attendanceStmt = $db->prepare("
        SELECT id, date, time_in, time_out, hours_worked, late_minutes, undertime_mins, status
        FROM attendance
        WHERE employee_id=? AND date BETWEEN ? AND ?
        ORDER BY date, id
    ");
    $updateAttendance = $db->prepare("
        UPDATE attendance
        SET late_minutes=?, updated_at=NOW()
        WHERE id=?
    ");
    $updatePayroll = $db->prepare("
        UPDATE payroll
        SET late_deduction=?, undertime_deduction=?, late_minutes=?, undertime_minutes=?,
            lwp_days=?, lwp_deduction=?, payroll_calculation_snapshot=?, net_salary=?, updated_at=NOW()
        WHERE id=? AND payroll_calculation_snapshot IS NULL
    ");
    $audit = $db->prepare("
        INSERT INTO audit_logs (user_id, user_name, action, module, details, ip_address)
        VALUES (?, ?, 'Backfill Historical Payroll Adjustments', 'Payroll', ?, '127.0.0.1')
    ");

    $attendanceUpdated = 0;
    $lwpDaysTotal = 0.0;
    $lateMinutesTotal = 0;
    $undertimeMinutesTotal = 0;
    $lateDeductionTotal = 0.0;
    $undertimeDeductionTotal = 0.0;
    $lwpDeductionTotal = 0.0;
    foreach ($payrollIds as $payrollId) {
        $payrollLock->execute([(int)$payrollId]);
        $payroll = $payrollLock->fetch(PDO::FETCH_ASSOC);
        if (!$payroll || !empty($payroll['payroll_calculation_snapshot'])) {
            continue;
        }

        $attendanceStmt->execute([
            $payroll['employee_id'],
            $payroll['payroll_period_start'],
            $payroll['payroll_period_end'],
        ]);
        $attendanceRows = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);
        $updatedAttendanceIds = [];
        foreach ($attendanceRows as &$row) {
            $lateMinutes = (int)($row['late_minutes'] ?? 0);

            if (!empty($row['time_in'])) {
                $lateMinutes = attComputeLateMinutes((string)$row['time_in']);
            } elseif ($row['status'] === 'absent' && empty($row['time_out'])) {
                $lateMinutes = 0;
            }

            // Historical 4:00 PM clock-outs conflict with the current 5:00 PM shift end;
            // preserve their recorded undertime values as explicitly approved.
            if ($lateMinutes !== (int)($row['late_minutes'] ?? 0)) {
                $updateAttendance->execute([$lateMinutes, $row['id']]);
                $updatedAttendanceIds[] = (int)$row['id'];
                $row['late_minutes'] = $lateMinutes;
            }
        }
        unset($row);

        $attendanceDed = computeAttendanceDeductionsForRecords(
            (float)$payroll['basic_salary'],
            $attendanceRows,
            approvedLeaveRangesForPeriod(
                $db,
                (string)$payroll['employee_id'],
                (string)$payroll['payroll_period_start'],
                (string)$payroll['payroll_period_end']
            )
        );
        $taxableIncome = computeTaxableIncome(
            (float)$payroll['gross_pay'] + (float)$payroll['bonuses'] +
                (float)$payroll['overtime_pay'] + (float)$payroll['salary_adjustment'],
            $attendanceDed['late_deduction'],
            $attendanceDed['undertime_deduction'],
            $attendanceDed['lwp_deduction']
        );
        $previousTaxableIncome = computeTaxableIncome(
            (float)$payroll['gross_pay'] + (float)$payroll['bonuses'] +
                (float)$payroll['overtime_pay'] + (float)$payroll['salary_adjustment'],
            (float)$payroll['late_deduction'],
            (float)$payroll['undertime_deduction'],
            (float)($payroll['lwp_deduction'] ?? 0)
        );
        $netSalary = computeNetSalary(
            $taxableIncome,
            (float)$payroll['de_minimis'],
            (float)$payroll['total_deductions']
        );
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
            'de_minimis' => (float)$payroll['de_minimis'],
            'sss_regular_employee' => round((float)$payroll['sss'] - (float)$payroll['sss_mpf'], 2),
            'sss_mpf_employee' => (float)$payroll['sss_mpf'],
            'daily_rows' => $attendanceDed['daily_rows'],
        ], JSON_THROW_ON_ERROR);

        $updatePayroll->execute([
            number_format($attendanceDed['late_deduction'], 2, '.', ''),
            number_format($attendanceDed['undertime_deduction'], 2, '.', ''),
            $attendanceDed['late_minutes'],
            $attendanceDed['undertime_minutes'],
            number_format($attendanceDed['lwp_days'], 2, '.', ''),
            number_format($attendanceDed['lwp_deduction'], 2, '.', ''),
            $calculationSnapshot,
            number_format($netSalary, 2, '.', ''),
            (int)$payroll['id'],
        ]);
        if ($updatePayroll->rowCount() !== 1) {
            throw new RuntimeException("Historical payroll row {$payroll['id']} changed during backfill; no partial update was committed.");
        }

        $lwpDaysTotal += $attendanceDed['lwp_days'];
        $lateMinutesTotal += $attendanceDed['late_minutes'];
        $undertimeMinutesTotal += $attendanceDed['undertime_minutes'];
        $lateDeductionTotal += $attendanceDed['late_deduction'];
        $undertimeDeductionTotal += $attendanceDed['undertime_deduction'];
        $lwpDeductionTotal += $attendanceDed['lwp_deduction'];
        $audit->execute([
            $actor['employee_id'],
            $actor['name'],
            json_encode([
                'payroll_id' => (int)$payroll['id'],
                'employee_id' => $payroll['employee_id'],
                'period_start' => $payroll['payroll_period_start'],
                'period_end' => $payroll['payroll_period_end'],
                'attendance_rows_recomputed' => count($attendanceRows),
                'attendance_rows_updated' => $updatedAttendanceIds,
                'previous' => [
                    'late_deduction' => $payroll['late_deduction'],
                    'undertime_deduction' => $payroll['undertime_deduction'],
                    'lwp_days' => $payroll['lwp_days'],
                    'lwp_deduction' => $payroll['lwp_deduction'],
                    'taxable_income' => $previousTaxableIncome,
                    'net_salary' => $payroll['net_salary'],
                ],
                'new' => [
                    'late_minutes' => $attendanceDed['late_minutes'],
                    'late_deduction' => $attendanceDed['late_deduction'],
                    'undertime_minutes' => $attendanceDed['undertime_minutes'],
                    'undertime_deduction' => $attendanceDed['undertime_deduction'],
                    'lwp_days' => $attendanceDed['lwp_days'],
                    'lwp_deduction' => $attendanceDed['lwp_deduction'],
                    'taxable_income' => $taxableIncome,
                    'net_salary' => $netSalary,
                ],
                'preserved' => [
                    'historical_undertime_minutes' => $attendanceDed['undertime_minutes'],
                    'sss' => $payroll['sss'],
                    'sss_mpf' => $payroll['sss_mpf'],
                    'philhealth' => $payroll['philhealth'],
                    'pagibig' => $payroll['pagibig'],
                    'withholding_tax' => $payroll['withholding_tax'],
                    'loan_deduction' => $payroll['loan_deduction'],
                    'other_deductions' => $payroll['other_deductions'],
                    'total_deductions' => $payroll['total_deductions'],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        $attendanceUpdated += count($updatedAttendanceIds);
    }

    if ($apply) {
        $db->commit();
        echo 'Backfill applied: ';
    } else {
        $db->rollBack();
        echo 'Dry run only: ';
    }
    echo count($payrollIds) . ' payroll rows reviewed, ' . $attendanceUpdated .
        ' attendance rows recalculated, ' . number_format($lateMinutesTotal / 60, 2) .
        ' late hours (' . number_format($lateDeductionTotal, 2) . '), ' .
        number_format($undertimeMinutesTotal / 60, 2) . ' undertime hours (' .
        number_format($undertimeDeductionTotal, 2) . '), ' .
        number_format($lwpDaysTotal, 2) . ' LWP days (' .
        number_format($lwpDeductionTotal, 2) . '). Government contributions, loans, and withholding tax were preserved.' . PHP_EOL;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Historical payroll backfill failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
