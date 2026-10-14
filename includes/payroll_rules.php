<?php
// ============================================================
// Directpath EMPS — Absence / LWOP / holiday payroll rules
//   * Working day  = Monday–Friday that is not a holiday.
//   * Absent day   = working day (up to today) with NO time-in and NO approved leave.
//   * Approved leave of a paid type (vacation, sick, emergency, maternity, paternity) = paid, never absent.
//   * Approved leave type "Others" = LWOP (leave without pay) -> deducted.
//   * Regular holidays and company/special holidays = paid, never absent
//     (special non-working days: no deduction even when the employee did not report).
//   * Deduction per day = daily rate = (monthly basic x 12) / 261.
// ============================================================

const PAYROLL_PAID_LEAVE_TYPES = ['vacation', 'sick', 'emergency', 'maternity', 'paternity'];
const PAYROLL_APPROVED_LEAVE_STATUSES = ['approved', 'approved_pending_documents', 'documents_submitted'];

function payrollHasAbsenceColumns(PDO $db): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $n = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payroll'
                              AND COLUMN_NAME IN ('absent_days','absence_deduction','lwp_days','lwp_deduction')")->fetchColumn();
        return $has = ($n === 4);
    } catch (Throwable $e) { return $has = false; }
}

// Returns absent/LWOP day counts and their deductions for one employee and period.
function computeAbsenceAndLwop(PDO $db, string $empId, string $start, string $end, float $monthlyBasic, ?string $dateHired = null, ?string $today = null): array {
    $today = $today ?: date('Y-m-d');
    $daily = round(($monthlyBasic * 12) / 261, 2);
    $last  = min($end, $today);

    $holidays = [];
    try { foreach ($db->query("SELECT holiday_date FROM holidays")->fetchAll(PDO::FETCH_COLUMN) as $h) $holidays[$h] = true; } catch (Throwable $e) {}

    $worked = [];
    $st = $db->prepare("SELECT date FROM attendance WHERE employee_id=? AND date BETWEEN ? AND ? AND time_in IS NOT NULL");
    $st->execute([$empId, $start, $end]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) $worked[$d] = true;

    // Approved leave days: date => leave_type
    $leave = [];
    $in = implode(',', array_fill(0, count(PAYROLL_APPROVED_LEAVE_STATUSES), '?'));
    $st = $db->prepare("SELECT leave_type, start_date, end_date FROM leave_requests WHERE employee_id=? AND status IN ($in) AND start_date<=? AND end_date>=?");
    $st->execute(array_merge([$empId], PAYROLL_APPROVED_LEAVE_STATUSES, [$end, $start]));
    foreach ($st->fetchAll() as $lv) {
        for ($t = strtotime(max($lv['start_date'], $start)); $t <= strtotime(min($lv['end_date'], $end)); $t += 86400) {
            $d = date('Y-m-d', $t);
            // a paid leave type wins if two requests overlap the same day
            if (!isset($leave[$d]) || in_array($lv['leave_type'], PAYROLL_PAID_LEAVE_TYPES, true)) $leave[$d] = $lv['leave_type'];
        }
    }

    $absent = []; $lwop = [];
    for ($t = strtotime($start); $t <= strtotime($last); $t += 86400) {
        $d = date('Y-m-d', $t);
        if ((int)date('N', $t) >= 6) continue;                 // weekend
        if (isset($holidays[$d])) continue;                    // regular / special holiday: paid
        if ($dateHired && $d < $dateHired) continue;           // not yet employed
        if (isset($worked[$d])) continue;                      // reported for work
        if (isset($leave[$d])) {
            if ($leave[$d] === 'others') $lwop[] = $d;         // LWOP: deducted
            continue;                                          // paid leave: not absent
        }
        $absent[] = $d;
    }
    return [
        'daily_rate'        => $daily,
        'absent_days'       => count($absent),
        'absent_dates'      => $absent,
        'lwop_days'         => count($lwop),
        'lwop_dates'        => $lwop,
        'absence_deduction' => round(count($absent) * $daily, 2),
        'lwop_deduction'    => round(count($lwop) * $daily, 2),
    ];
}
