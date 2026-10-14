<?php
// ============================================================
// Directpath HRMS — Attendance Rules (single source of truth)
// Used by the employee attendance page, api/face_verify.php and api/attendance.php
//
//  • Time-in is accepted only between ATT_TIMEIN_OPEN (6:30 AM) and ATT_TIMEIN_CLOSE (5:00 PM)
//  • No time-in on weekends or on dates listed in the `holidays` table
//  • Late minutes are measured after the configurable ATT_LATE_GRACE_MINUTES
//  • Undertime = minutes short of 5:00 PM at time-out (tracked in undertime_mins)
//    Late and undertime are separate so the same minutes are never counted twice.
// ============================================================

require_once __DIR__ . '/../config/config.php';

function attFormat12h(string $time): string {
    return date('g:i A', strtotime($time));
}

// Returns ['name' => ..., 'type' => ...] when $date is a holiday, otherwise null.
// Safe if the holidays table has not been created yet.
function attGetHoliday(PDO $db, string $date): ?array {
    try {
        $stmt = $db->prepare("SELECT name, type FROM holidays WHERE holiday_date = ? LIMIT 1");
        $stmt->execute([$date]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        try {
            $stmt = $db->prepare("SELECT name FROM holidays WHERE holiday_date = ? LIMIT 1");
            $stmt->execute([$date]);
            $row = $stmt->fetch();
            return $row ? ['name' => $row['name'], 'type' => 'regular'] : null;
        } catch (PDOException $e2) {
            return null;
        }
    }
}

// Why time-in is not allowed right now (string), or null when it is allowed.
function attTimeInBlockReason(PDO $db, ?int $ts = null): ?string {
    $ts   = $ts ?? time();
    $date = date('Y-m-d', $ts);
    $time = date('H:i:s', $ts);

    if (!in_array((int)date('N', $ts), ATT_WORK_DAYS, true)) {
        return 'No work on weekends. Time-in is disabled.';
    }
    $holiday = attGetHoliday($db, $date);
    if ($holiday) {
        return 'Today is a holiday (' . $holiday['name'] . '). Time-in is disabled.';
    }
    if ($time < ATT_TIMEIN_OPEN) {
        return 'Time-in is unavailable now. The time-in window opens at ' . attFormat12h(ATT_TIMEIN_OPEN) . '.';
    }
    if ($time >= ATT_TIMEIN_CLOSE) {
        return 'Time-in is unavailable now. The time-in window closed at ' . attFormat12h(ATT_TIMEIN_CLOSE) . '. Please contact HR if you worked today.';
    }
    return null;
}

// Time-out only needs an open time-in for today (people may leave after 5 PM).
function attTimeOutBlockReason(?array $todayRow): ?string {
    if (!$todayRow || empty($todayRow['time_in'])) {
        return 'Error: you cannot Time Out because you have not Timed In today.';
    }
    if (!empty($todayRow['time_out'])) {
        return 'Already timed out at ' . attFormat12h($todayRow['time_out']) . '.';
    }
    return null;
}

// Whole minutes late after the configured grace period.
function attComputeLateMinutes(string $timeIn): int {
    $diff = strtotime('1970-01-01 ' . $timeIn) - strtotime('1970-01-01 ' . ATT_SHIFT_START);
    $graceSeconds = ATT_LATE_GRACE_MINUTES * 60;
    return $diff > $graceSeconds ? intdiv($diff - $graceSeconds, 60) : 0;
}

// Returns [hours_worked, undertime_minutes] for a completed day.
// Hours count from the later of (time-in, 8:00 AM) to the earlier of (time-out, 5:00 PM).
// Undertime is only the time missed before 5:00 PM — lateness is not repeated here.
function attComputeOut(string $timeIn, string $timeOut): array {
    $base  = '1970-01-01 ';
    $start = max(strtotime($base . $timeIn),  strtotime($base . ATT_SHIFT_START));
    $end   = min(strtotime($base . $timeOut), strtotime($base . ATT_SHIFT_END));
    $hours = $end > $start ? round(min(($end - $start) / 3600, ATT_MAX_HOURS), 2) : 0.00;

    $shiftEnd  = strtotime($base . ATT_SHIFT_END);
    $outTs     = strtotime($base . $timeOut);
    $undertime = $outTs < $shiftEnd ? (int)ceil(($shiftEnd - $outTs) / 60) : 0;

    return [$hours, $undertime];
}

// Upcoming holidays (for display).
function attUpcomingHolidays(PDO $db, int $limit = 5): array {
    try {
        $stmt = $db->prepare("SELECT holiday_date, name, type FROM holidays WHERE holiday_date >= CURDATE() ORDER BY holiday_date LIMIT " . (int)$limit);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

// Record a time-in / time-out for today. Returns [success(bool), message(string), data(array)].
// $photoName is the stored evidence photo file name (optional).
function attRecord(PDO $db, string $empId, string $action, ?string $photoName = null): array {
    $today = date('Y-m-d');
    $now   = date('H:i:s');

    $stmt = $db->prepare("SELECT * FROM attendance WHERE employee_id = ? AND date = ?");
    $stmt->execute([$empId, $today]);
    $row = $stmt->fetch() ?: null;
    if ($row) {
        foreach (['time_in', 'time_out'] as $c) {
            if (isset($row[$c]) && $row[$c] === '') $row[$c] = null;
        }
    }

    if ($action === 'time_in') {
        $reason = attTimeInBlockReason($db);
        if ($reason) return [false, $reason, []];
        if ($row && !empty($row['time_in'])) {
            return [false, 'Already timed in today at ' . attFormat12h($row['time_in']) . '.', []];
        }
        $late = attComputeLateMinutes($now);
        // status stays 'present' so payroll day-counts are unaffected; lateness lives in late_minutes
        if ($row) {
            $db->prepare("UPDATE attendance SET time_in=?, late_minutes=?, status='present', captured_photo=COALESCE(?, captured_photo) WHERE id=?")
               ->execute([$now, $late, $photoName, $row['id']]);
        } else {
            $db->prepare("INSERT INTO attendance (employee_id, date, time_in, late_minutes, status, captured_photo) VALUES (?,?,?,?,'present',?)")
               ->execute([$empId, $today, $now, $late, $photoName]);
        }
        $msg = 'Time In recorded at ' . attFormat12h($now) . '.';
        if ($late > 0) $msg .= ' Late by ' . $late . ' minute(s).';
        return [true, $msg, ['time' => $now, 'late' => $late]];
    }

    if ($action === 'time_out') {
        $reason = attTimeOutBlockReason($row);
        if ($reason) return [false, $reason, []];
        [$hours, $under] = attComputeOut($row['time_in'], $now);
        $db->prepare("UPDATE attendance SET time_out=?, hours_worked=?, undertime_mins=? WHERE id=?")
           ->execute([$now, $hours, $under, $row['id']]);
        $msg = 'Time Out recorded at ' . attFormat12h($now) . '. Hours worked: ' . $hours . '.';
        if ($under > 0) $msg .= ' Undertime: ' . $under . ' minute(s).';
        return [true, $msg, ['time' => $now, 'hours' => $hours, 'undertime' => $under]];
    }

    return [false, 'Invalid action.', []];
}
