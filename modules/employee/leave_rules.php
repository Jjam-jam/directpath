<?php
function employeeLeaveRequestedDays(PDO $db, string $type, string $start, string $end): int {
    try {
        $from = new DateTimeImmutable($start);
        $through = new DateTimeImmutable($end);
    } catch (Throwable $e) {
        return 0;
    }
    if ($through < $from) return 0;
    if (!in_array($type, ['solo_parent', 'sil'], true)) return $from->diff($through)->days + 1;
    $holidays = $db->prepare('SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?');
    $holidays->execute([$from->format('Y-m-d'), $through->format('Y-m-d')]);
    $excluded = array_fill_keys($holidays->fetchAll(PDO::FETCH_COLUMN), true);
    $days = 0;
    for ($day = $from; $day <= $through; $day = $day->modify('+1 day')) {
        if ((int)$day->format('N') <= 5 && !isset($excluded[$day->format('Y-m-d')])) $days++;
    }
    return $days;
}

function employeeVacationNoticeBusinessDays(PDO $db, string $today, string $start): int {
    $from = new DateTimeImmutable($today);
    $through = new DateTimeImmutable($start);
    if ($through <= $from) return 0;
    $last = $through->modify('-1 day');
    $holidays = $db->prepare('SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?');
    $holidays->execute([$from->format('Y-m-d'), $last->format('Y-m-d')]);
    $excluded = array_fill_keys($holidays->fetchAll(PDO::FETCH_COLUMN), true);
    $days = 0;
    for ($day = $from; $day < $through; $day = $day->modify('+1 day')) {
        if ((int)$day->format('N') <= 5 && !isset($excluded[$day->format('Y-m-d')])) $days++;
    }
    return $days;
}
