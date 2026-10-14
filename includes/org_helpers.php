<?php
// ============================================================
// Directpath EMPS — Organization helpers
// Departments / positions, Team Manager designation, performance scoring,
// attendance summary and the CEO payroll-approval gate.
// Loaded from config/config.php, so every page can use these functions.
// ============================================================

// ── Departments & positions ───────────────────────────────
function getDepartments(): array {
    try {
        return getDB()->query("SELECT * FROM departments ORDER BY sort_order, name")->fetchAll();
    } catch (Throwable $e) { return []; }
}

// Position titles of one department. $tlOnly: true = only TL position, false = only regular positions, null = all
function getDepartmentPositions(string $dept, ?bool $tlOnly = null): array {
    $sql = "SELECT p.position_title, p.is_tl_position FROM department_positions p
            JOIN departments d ON d.id = p.department_id WHERE d.name = ? AND p.is_active = 1";
    if ($tlOnly === true)  $sql .= " AND p.is_tl_position = 1";
    if ($tlOnly === false) $sql .= " AND p.is_tl_position = 0";
    $sql .= " ORDER BY p.is_tl_position DESC, p.id";
    try {
        $st = getDB()->prepare($sql);
        $st->execute([$dept]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return []; }
}

// Base salary (monthly) of a position; 0 when unknown
function getPositionBaseSalary(string $dept, string $position): float {
    try {
        $st = getDB()->prepare("SELECT p.base_salary FROM department_positions p JOIN departments d ON d.id=p.department_id
                                WHERE d.name=? AND p.position_title=? LIMIT 1");
        $st->execute([$dept, $position]);
        return (float)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) { return 0.0; }
}

// position title => base salary for one department (active positions)
function positionSalaryMap(string $dept): array {
    try {
        $st = getDB()->prepare("SELECT p.position_title, p.base_salary FROM department_positions p
                                JOIN departments d ON d.id=p.department_id WHERE d.name=? AND p.is_active=1");
        $st->execute([$dept]);
        return $st->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) { return []; }
}

function departmentTlPosition(string $dept): ?string {
    $p = getDepartmentPositions($dept, true);
    return $p[0] ?? null;
}

// Map for the HR form's JavaScript: dept => ['tl' => 'TL position', 'staff' => [...]]
function departmentPositionMap(): array {
    $map = [];
    foreach (getDepartments() as $d) {
        $map[$d['name']] = [
            'tl'    => $d['tl_position'],
            'staff' => getDepartmentPositions($d['name'], false),
            'salary'=> positionSalaryMap($d['name']),
        ];
    }
    return $map;
}

// ── Designations (custom, other than Team Manager) ────────
// A designation carries its own monthly base salary. The salary is copied to users.basic_salary, which payroll,
// payslips, contributions, tax, 13th month and COE already read. Needs config/designation_migration.sql.
function designationsReady(PDO $db): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->query("SELECT id, name, base_salary, is_active FROM designations LIMIT 0");
        $db->query("SELECT designation FROM users LIMIT 0");
        return $ok = true;
    } catch (Throwable $e) { return $ok = false; }
}

function getDesignations(PDO $db): array {
    if (!designationsReady($db)) return [];
    try { return $db->query("SELECT id, name, base_salary FROM designations WHERE is_active = 1 ORDER BY name")->fetchAll(); }
    catch (Throwable $e) { return []; }
}

// Label shown for an employee's designation everywhere (profile, payslip, lists).
function designationLabel(array $u): string {
    if (!empty($u['is_team_leader'])) return 'Team Manager';
    return trim((string)($u['designation'] ?? '')) !== '' ? (string)$u['designation'] : 'Employee';
}

// ── HR department guard ────────────────────────────────────
// True for the HR department ("Human Resources", ...).
// HR people are only created by the Super Admin (User Management), so the HR Admin's
// Employee Management must never add anyone to, or move anyone into, this department.
function isHrDepartment(string $dept): bool {
    $d = strtolower(trim(html_entity_decode($dept)));
    if ($d === '') return false;
    return $d === 'human resources' || (bool)preg_match('/(^|[^a-z])hr([^a-z]|$)/', $d);
}

// ── Team Manager designation ───────────────────────────────
// A Team Manager is an EMPLOYEE (role stays "employee") with users.is_team_leader = 1.
function isTeamLeaderSession(): bool {
    return !empty($_SESSION['is_team_leader']) && ($_SESSION['role'] ?? '') === 'employee';
}

// Resolve the active Team Manager by the same department rule used by team views.
function getDepartmentTeamLeaderId(PDO $db, string $employeeId): ?string {
    $st = $db->prepare("SELECT leader.employee_id
                        FROM users member
                        JOIN users leader ON leader.department = member.department
                        WHERE member.employee_id = ? AND member.role = 'employee'
                          AND member.status = 'active' AND member.department <> ''
                          AND leader.is_team_leader = 1 AND leader.status = 'active'
                        ORDER BY leader.id LIMIT 1");
    $st->execute([$employeeId]);
    $id = $st->fetchColumn();
    return $id ? (string)$id : null;
}

// Server-side guard for every Team Manager page/API (never trust the hidden menu).
function requireTeamLeader(): void {
    requireRole('employee');
    if (!isTeamLeaderSession()) {
        header('Location: ' . SITE_URL . '/modules/employee/dashboard.php?denied=1');
        exit;
    }
}

// Active employee teammates in the Team Manager's department (never includes the leader).
function getTeamMembers(string $leaderId): array {
    $st = getDB()->prepare("SELECT member.employee_id, member.first_name, member.middle_name, member.last_name,
                                   member.department, member.position, member.email, member.status
                            FROM users leader
                            JOIN users member ON member.department = leader.department
                            WHERE leader.employee_id = ? AND leader.is_team_leader = 1 AND leader.status = 'active'
                              AND leader.department <> '' AND member.employee_id <> leader.employee_id
                              AND member.role = 'employee' AND member.status = 'active'
                            ORDER BY member.last_name, member.first_name");
    $st->execute([$leaderId]);
    return $st->fetchAll();
}

// True when $empId belongs to the team of $leaderId (used to block URL manipulation).
function isTeamMemberOf(string $leaderId, string $empId): bool {
    $st = getDB()->prepare("SELECT 1 FROM users leader JOIN users member ON member.department = leader.department
                            WHERE leader.employee_id = ? AND leader.is_team_leader = 1 AND leader.status = 'active'
                              AND leader.department <> '' AND member.employee_id = ? AND member.employee_id <> leader.employee_id
                              AND member.role = 'employee' AND member.status = 'active'");
    $st->execute([$leaderId, $empId]);
    return (bool)$st->fetchColumn();
}

// Puts every active employee of a department under that department's Team Manager.
function syncDepartmentTeam(PDO $db, string $dept): void {
    $st = $db->prepare("SELECT employee_id FROM users WHERE department = ? AND is_team_leader = 1 AND status = 'active' ORDER BY id LIMIT 1");
    $st->execute([$dept]);
    $leader = $st->fetchColumn();
    if ($leader) {
        $db->prepare("UPDATE users SET team_leader_id = ? WHERE department = ? AND employee_id <> ? AND role = 'employee'")
           ->execute([$leader, $dept, $leader]);
        $db->prepare("UPDATE users SET team_leader_id = NULL WHERE employee_id = ?")->execute([$leader]);
    } else {
        $db->prepare("UPDATE users SET team_leader_id = NULL WHERE department = ?")->execute([$dept]);
    }
}

// ── Attendance summary (used by performance evaluation & dashboards) ──
// Working days = Mon–Fri that are not holidays, up to today. Absent = working day with no
// time-in and no approved leave.
function attendanceSummary(PDO $db, string $empId, string $start, string $end): array {
    $end = min($end, date('Y-m-d'));
    $hol = [];
    try {
        $h = $db->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
        $h->execute([$start, $end]);
        $hol = $h->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {}

    $a = $db->prepare("SELECT date, time_in, late_minutes, undertime_mins, status FROM attendance
                       WHERE employee_id = ? AND date BETWEEN ? AND ?");
    $a->execute([$empId, $start, $end]);
    $att = [];
    foreach ($a->fetchAll() as $r) $att[$r['date']] = $r;

    $l = $db->prepare("SELECT start_date, end_date FROM leave_requests
                       WHERE employee_id = ? AND status IN ('approved','approved_pending_documents','documents_submitted')
                         AND start_date <= ? AND end_date >= ?");
    $l->execute([$empId, $end, $start]);
    $leaves = $l->fetchAll();

    $work = $present = $late = $under = $absent = 0;
    if ($start <= $end) {
        for ($t = strtotime($start); $t <= strtotime($end); $t += 86400) {
            $d = date('Y-m-d', $t);
            if ((int)date('N', $t) > 5 || in_array($d, $hol, true)) continue;
            $onLeave = false;
            foreach ($leaves as $lv) if ($d >= $lv['start_date'] && $d <= $lv['end_date']) { $onLeave = true; break; }
            if ($onLeave) continue;               // excused day: not counted for or against
            $work++;
            if (isset($att[$d]) && $att[$d]['time_in']) {
                $present++;
                if ((int)$att[$d]['late_minutes'] > 0)   $late++;
                if ((int)$att[$d]['undertime_mins'] > 0) $under++;
            } else {
                $absent++;
            }
        }
    }
    return [
        'working_days' => $work,
        'present'      => $present,
        'pct'          => $work > 0 ? round($present / $work * 100, 2) : 100.00,
        'late'         => $late,
        'undertime'    => $under,
        'absent'       => $absent,
    ];
}

// ── Performance scoring ───────────────────────────────────
const PERF_RATING_LABELS = [1 => 'Poor', 2 => 'Needs Improvement', 3 => 'Satisfactory', 4 => 'Very Good', 5 => 'Excellent'];

// Weighted score = (rating / 5) x weight
function performanceWeightedScore(int $rating, float $weight): float {
    return round(($rating / 5) * $weight, 2);
}

function performanceLevel(float $score): string {
    if ($score >= 90) return 'Outstanding';
    if ($score >= 80) return 'Very Good';
    if ($score >= 70) return 'Satisfactory';
    if ($score >= 60) return 'Needs Improvement';
    return 'Unsatisfactory';
}

function performanceLevelColor(string $level): string {
    return ['Outstanding' => '#16A34A', 'Very Good' => '#2563EB', 'Satisfactory' => '#CA8A04',
            'Needs Improvement' => '#EA580C', 'Unsatisfactory' => '#DC2626'][$level] ?? '#6B7280';
}

// ── Payroll approval gate ─────────────────────────────────
// Latest CEO-approval record for a payroll period (or null when never submitted).
function getPayrollApproval(PDO $db, string $start, string $end): ?array {
    try {
        $st = $db->prepare("SELECT * FROM payroll_approvals WHERE period_start = ? AND period_end = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$start, $end]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

// ── Per-employee payroll approval (HR releases what the CEO approved) ──────────
// Every submission to the CEO stores WHICH employee(s) it covers (payroll_approval_items), so the CEO,
// Payroll Manager and HR all see exactly who is in a payroll. A payroll row can only be released by HR when
// it appears in an APPROVED submission with the same net pay (so a payroll generated or regenerated after the
// CEO decided is never released by accident). Submissions made before this feature (no stored items) keep the
// old behaviour: an approved period approves every row of that period.
function payrollItemsReady(PDO $db): bool {
    static $ready = null;
    if ($ready === null) {
        try { $db->query('SELECT 1 FROM payroll_approval_items LIMIT 1'); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

function payrollReleasedByReady(PDO $db): bool {
    static $ready = null;
    if ($ready === null) {
        try { $db->query('SELECT released_by_name FROM payroll LIMIT 1'); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

// SQL condition (table alias p = payroll) true when that payroll row is covered by a CEO-approved submission.
function payrollApprovedCoverSql(PDO $db): string {
    $legacy = "EXISTS (SELECT 1 FROM payroll_approvals la WHERE la.status = 'approved'
                        AND la.period_start = p.payroll_period_start AND la.period_end = p.payroll_period_end"
            . (payrollItemsReady($db) ? " AND NOT EXISTS (SELECT 1 FROM payroll_approval_items li WHERE li.approval_id = la.id)" : '') . ")";
    if (!payrollItemsReady($db)) return "($legacy)";
    return "(EXISTS (SELECT 1 FROM payroll_approval_items i JOIN payroll_approvals a ON a.id = i.approval_id
                     WHERE i.payroll_id = p.id AND a.status = 'approved' AND i.net_salary = p.net_salary) OR $legacy)";
}

// Generated payroll rows of a period that no approved submission covers yet (with employee name / department).
function payrollUncoveredRows(PDO $db, string $start, string $end): array {
    $cover = payrollApprovedCoverSql($db);
    $st = $db->prepare("SELECT p.*, u.first_name, u.middle_name, u.last_name, u.department, u.position
                        FROM payroll p JOIN users u ON u.employee_id = p.employee_id
                        WHERE p.payroll_period_start = ? AND p.payroll_period_end = ? AND p.status = 'generated'
                          AND NOT $cover
                        ORDER BY u.last_name, u.first_name");
    $st->execute([$start, $end]);
    return $st->fetchAll();
}

// Generated rows of a period that ARE covered by an approved submission (HR can release these).
function payrollReleasableRows(PDO $db, string $start, string $end): array {
    $cover = payrollApprovedCoverSql($db);
    $st = $db->prepare("SELECT p.*, u.first_name, u.middle_name, u.last_name, u.department, u.position
                        FROM payroll p JOIN users u ON u.employee_id = p.employee_id
                        WHERE p.payroll_period_start = ? AND p.payroll_period_end = ? AND p.status = 'generated'
                          AND p.government_contributions_snapshot IS NOT NULL AND $cover
                        ORDER BY u.last_name, u.first_name");
    $st->execute([$start, $end]);
    return $st->fetchAll();
}

// Employees a submission covers: the stored list, or (older submissions) the period's live payroll rows.
function payrollApprovalEmployees(PDO $db, array $apr): array {
    if (payrollItemsReady($db)) {
        $st = $db->prepare("SELECT i.payroll_id, i.employee_id, i.employee_name, i.department, i.position,
                                   i.gross_pay, i.total_deductions, i.net_salary, p.status AS payroll_status
                            FROM payroll_approval_items i LEFT JOIN payroll p ON p.id = i.payroll_id
                            WHERE i.approval_id = ? ORDER BY i.employee_name");
        $st->execute([(int)$apr['id']]);
        $rows = $st->fetchAll();
        if ($rows) return $rows;
    }
    $st = $db->prepare("SELECT p.id AS payroll_id, p.employee_id, CONCAT(u.first_name,' ',u.last_name) AS employee_name,
                               u.department, u.position, p.gross_pay, p.total_deductions, p.net_salary, p.status AS payroll_status
                        FROM payroll p JOIN users u ON u.employee_id = p.employee_id
                        WHERE p.payroll_period_start = ? AND p.payroll_period_end = ? AND p.status IN ('generated','released')
                        ORDER BY u.last_name, u.first_name");
    $st->execute([$apr['period_start'], $apr['period_end']]);
    return $st->fetchAll();
}

// "Juan Cruz (EMP-0001)" for one employee, "Juan Cruz (EMP-0001), Ana Reyes (EMP-0002) +3 more" for several.
function payrollEmployeeLabel(array $employees, int $max = 3): string {
    $names = array_map(static fn($e) => trim((string)$e['employee_name']) . ' (' . $e['employee_id'] . ')', $employees);
    if (count($names) <= $max) return implode(', ', $names);
    return implode(', ', array_slice($names, 0, $max)) . ' +' . (count($names) - $max) . ' more';
}

// Stores the employees of a new submission. $rows come from payrollUncoveredRows().
function payrollSaveApprovalItems(PDO $db, int $approvalId, array $rows): void {
    if (!payrollItemsReady($db)) return;
    $ins = $db->prepare("INSERT INTO payroll_approval_items
        (approval_id, payroll_id, employee_id, employee_name, department, position, gross_pay, total_deductions, net_salary)
        VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($rows as $r) {
        $ins->execute([$approvalId, (int)$r['id'], $r['employee_id'],
            getFullName((string)$r['first_name'], $r['middle_name'] ?? null, (string)$r['last_name']),
            $r['department'] ?? null, $r['position'] ?? null,
            (float)$r['gross_pay'] + (float)($r['bonuses'] ?? 0), (float)$r['total_deductions'], (float)$r['net_salary']]);
    }
}

function submitPayrollPeriodForCeo(PDO $db, string $start, string $end, string $employeeId, string $employeeName): ?array {
    $latest = getPayrollApproval($db, $start, $end);
    if ($latest && $latest['status'] === 'pending_ceo') return null;
    $rows = payrollUncoveredRows($db, $start, $end);
    if (!$rows) return null;
    $totals = payrollTotalsFromRows($rows);
    $names = payrollEmployeeLabel(array_map(static fn($r) => [
        'employee_id' => $r['employee_id'],
        'employee_name' => getFullName((string)$r['first_name'], $r['middle_name'] ?? null, (string)$r['last_name']),
    ], $rows), 5);
    $db->prepare("INSERT INTO payroll_approvals (period_start, period_end, status, employee_count, gross_total, bonus_total, adjustment_total, thirteenth_total, deduction_total, employer_total, net_total, submitted_by, submitted_by_name)
                  VALUES (?,?, 'pending_ceo', ?,?,?,?,?,?,?,?,?,?)")
       ->execute([$start, $end, $totals['employee_count'], $totals['gross'], $totals['bonus'], $totals['adjustment'], $totals['thirteenth'], $totals['deductions'], $totals['employer'], $totals['net'], $employeeId, $employeeName]);
    $approvalId = (int)$db->lastInsertId();
    payrollSaveApprovalItems($db, $approvalId, $rows);
    if (!auditLog('Submit Payroll for CEO Review', 'Payroll', "Approval #$approvalId | Period: $start to $end | Employee(s): $names | Count: {$totals['employee_count']} | Net: {$totals['net']}")) {
        throw new RuntimeException('Payroll approval audit record could not be saved.');
    }
    createNotification($db, "Payroll for $names is waiting for your review.", 'modules/ceo/payroll_approval.php', null, 'ceo');
    return ['id' => $approvalId, 'employees' => $names];
}

// Releases the CEO-approved payroll of one period (optionally one payroll row). HR only; the caller checks the role.
// Returns the released rows. Throws DomainException when nothing can be released.
function payrollReleaseApproved(PDO $db, string $start, string $end, ?int $payrollId, string $byName): array {
    $rows = payrollReleasableRows($db, $start, $end);
    if ($payrollId !== null) $rows = array_values(array_filter($rows, static fn($r) => (int)$r['id'] === $payrollId));
    if (!$rows) throw new DomainException('Nothing to release: no generated payroll in this period is covered by a CEO approval.');
    $setBy = payrollReleasedByReady($db) ? ', released_by_name = ?' : '';
    $up = $db->prepare("UPDATE payroll SET status = 'released', released_at = NOW()$setBy WHERE id = ? AND status = 'generated'");
    $done = [];
    foreach ($rows as $r) {
        $up->execute($setBy ? [$byName, (int)$r['id']] : [(int)$r['id']]);
        if ($up->rowCount() === 1) {
            $done[] = $r;
            createNotification($db, 'Your payslip is now available.', 'modules/admin/payslip.php?id=' . (int)$r['id'], (string)$r['employee_id']);
            createNotification(
                $db,
                'Payroll released for ' . (string)$r['employee_id'] . ' (' . (string)$r['payroll_period_start'] . ' to ' . (string)$r['payroll_period_end'] . ').',
                'modules/admin/payroll.php?emp=' . rawurlencode((string)$r['employee_id']),
                null,
                'payroll_manager'
            );
        }
    }
    return $done;
}

// Totals snapshot shown on the CEO review screen.
function payrollPeriodTotals(PDO $db, string $start, string $end): array {
    $st = $db->prepare("SELECT p.* FROM payroll p
                        WHERE p.payroll_period_start = ? AND p.payroll_period_end = ? AND p.status IN ('generated','released')");
    $st->execute([$start, $end]);
    return payrollTotalsFromRows($st->fetchAll());
}

// Same totals for any set of payroll rows (used for a submission that covers only some employees).
function payrollTotalsFromRows(array $rows): array {
    $t = ['employee_count' => 0, 'gross' => 0.0, 'bonus' => 0.0, 'adjustment' => 0.0, 'thirteenth' => 0.0,
          'deductions' => 0.0, 'employer' => 0.0, 'net' => 0.0];
    foreach ($rows as $p) {
        $t['employee_count']++;
        $t['gross']      += (float)$p['gross_pay'];
        $t['bonus']      += (float)$p['bonuses'];
        $t['adjustment'] += (float)($p['salary_adjustment'] ?? 0);
        $t['thirteenth'] += (float)($p['thirteenth_month_pay'] ?? 0);
        $t['deductions'] += (float)$p['total_deductions'];
        $t['net']        += (float)$p['net_salary'];
        $t['employer']   += payrollEmployerShares($p)['total'];
    }
    foreach ($t as $k => $v) if ($k !== 'employee_count') $t[$k] = round($v, 2);
    return $t;
}

// Employer share of the statutory contributions exactly as saved on a payroll row
// (computed by GovernmentContributionEngine when the payroll was generated).
function payrollEmployerShares(array $p): array {
    static $calculatedCache = [];
    $sss = (float)($p['sss_employer'] ?? 0);
    $ec  = (float)($p['sss_ec_employer'] ?? 0);
    $ph  = (float)($p['philhealth_employer'] ?? 0);
    $pi  = (float)($p['pagibig_employer'] ?? 0);
    // Legacy payroll rows may have zero-filled employer columns. Prefer their
    // original contribution snapshot; otherwise use the approved schedule that
    // was effective on that payroll date.
    if ($sss + $ec + $ph + $pi <= 0) {
        $snapshot = $p['government_contributions_snapshot'] ?? null;
        if (is_string($snapshot) && $snapshot !== '') {
            $saved = json_decode($snapshot, true);
            $contributions = is_array($saved) ? ($saved['contributions'] ?? []) : [];
            if (is_array($contributions)) {
                $sss = (float)($contributions['SSS']['employer'] ?? 0);
                $ec  = (float)($contributions['SSS']['ecp'] ?? 0);
                $ph  = (float)($contributions['PhilHealth']['employer'] ?? 0);
                $pi  = (float)($contributions['Pag-IBIG']['employer'] ?? 0);
            }
        }
    }
    if ($sss + $ec + $ph + $pi <= 0 && !empty($p['basic_salary']) && !empty($p['payroll_period_end'])) {
        try {
            $date = (string)$p['payroll_period_end'];
            $cacheKey = number_format((float)$p['basic_salary'], 2, '.', '') . '|' . $date;
            if (isset($calculatedCache[$cacheKey])) {
                [$sss, $ec, $ph, $pi] = $calculatedCache[$cacheKey];
            } else {
                $calculated = calculateGovernmentContributions(
                    getDB(), (string)$p['basic_salary'], $date, 2,
                    GovernmentContributionEngine::shareIndexForPayrollDate($date)
                );
                $sss = (float)$calculated['SSS']['employer'];
                $ec  = (float)$calculated['SSS']['ecp'];
                $ph  = (float)$calculated['PhilHealth']['employer'];
                $pi  = (float)$calculated['Pag-IBIG']['employer'];
                $calculatedCache[$cacheKey] = [$sss, $ec, $ph, $pi];
            }
        } catch (Throwable $e) {
            // A read-only fallback supports legacy payslips when schedule rows
            // have not yet been activated. New payroll generation still requires
            // the normal approved and active schedule workflow.
            $date = (string)$p['payroll_period_end'];
            $scheduleNotActive = $e instanceof DomainException
                && str_contains($e->getMessage(), 'has no verified, active contribution schedule');
            if ($scheduleNotActive && $date >= '2025-01-01' && GovernmentContributionEngine::isValidDate($date)) {
                try {
                    $salaryCents = GovernmentContributionEngine::validateMonthlySalary((string)$p['basic_salary']);
                    $salary = $salaryCents / 100;
                    $msc = max(5000, min(35000, (int)(floor(($salary + 250) / 500) * 500)));
                    $shareIndex = GovernmentContributionEngine::shareIndexForPayrollDate($date);
                    $splitMonthly = static function (int $monthlyCents) use ($shareIndex): int {
                        $base = intdiv($monthlyCents, 2);
                        $remainder = $monthlyCents % 2;
                        return $base + ($shareIndex > 1 ? $remainder : 0);
                    };
                    $sss = $splitMonthly(GovernmentContributionEngine::toCents(number_format($msc * 0.10, 2, '.', '')));
                    $sss = (float)GovernmentContributionEngine::fromCents($sss);
                    $ec = (float)GovernmentContributionEngine::fromCents($splitMonthly($msc <= 14500 ? 1000 : 3000));
                    $phBase = max(10000, min(100000, $salary));
                    $ph = (float)GovernmentContributionEngine::fromCents($splitMonthly(
                        GovernmentContributionEngine::toCents(number_format($phBase * 0.025, 2, '.', ''))
                    ));
                    $pi = (float)GovernmentContributionEngine::fromCents($splitMonthly(
                        GovernmentContributionEngine::toCents(number_format(min($salary, 10000) * 0.02, 2, '.', ''))
                    ));
                    $calculatedCache[$cacheKey] = [$sss, $ec, $ph, $pi];
                } catch (Throwable $fallbackError) {
                    // Keep the stored values if the row does not have a valid basis.
                }
            }
        }
    }
    return ['sss' => round($sss, 2), 'ec' => round($ec, 2), 'philhealth' => round($ph, 2), 'pagibig' => round($pi, 2),
            'total' => round($sss + $ec + $ph + $pi, 2)];
}

// ── Bi-monthly payroll cut-off and salary release ─────────
// Cut-off  : 1st-15th and 16th-end of month.
// Release  : cut-off 1-15 is paid on the 20th of the same month;
//            cut-off 16-end is paid on the 5th of the next month.
// When the release date falls on a weekend, it moves back to the Friday before.
function payrollReleaseDateFor(string $date): string {
    $ts = strtotime($date);
    $dow = (int)date('N', $ts);          // 6 = Saturday, 7 = Sunday
    if ($dow === 6) $ts -= 86400;        // Saturday -> Friday
    elseif ($dow === 7) $ts -= 2 * 86400; // Sunday -> Friday
    return date('Y-m-d', $ts);
}

function payCutoffInfo(string $periodStart, string $periodEnd): array {
    $first = (int)date('j', strtotime($periodStart)) <= 15;
    $nominal = $first
        ? date('Y-m-20', strtotime($periodStart))
        : date('Y-m-05', strtotime(date('Y-m-01', strtotime($periodStart)) . ' +1 month'));
    $payDate = payrollReleaseDateFor($nominal);
    return [
        'cutoff'     => $first ? '1st-15th' : '16th-' . date('jS', strtotime($periodEnd)),
        'cutoff_no'  => $first ? 1 : 2,
        'label'      => date('F j', strtotime($periodStart)) . '-' . date('j, Y', strtotime($periodEnd)),
        'pay_date'   => $payDate,
        'paid_today' => date('Y-m-d') >= $payDate,   // payday reached (or passed)
    ];
}

// The cut-off containing $date (default today).
function currentCutoff(?string $date = null): array {
    $ts = strtotime($date ?? date('Y-m-d'));
    $start = (int)date('j', $ts) <= 15 ? date('Y-m-01', $ts) : date('Y-m-16', $ts);
    $end   = (int)date('j', $ts) <= 15 ? date('Y-m-15', $ts) : date('Y-m-t', $ts);
    return ['start' => $start, 'end' => $end] + payCutoffInfo($start, $end);
}

// A released payroll is visible to the employee once its pay date has arrived.
function payslipAvailable(array $payroll): bool {
    if (($payroll['status'] ?? '') !== 'released') return false;
    return payCutoffInfo($payroll['payroll_period_start'], $payroll['payroll_period_end'])['paid_today'];
}

// Attendance summary for ONE payroll period (payslip section).
function payrollAttendanceSummary(PDO $db, string $empId, string $start, string $end): array {
    $st = $db->prepare("SELECT date, time_in, time_out, hours_worked, late_minutes, undertime_mins, status
                        FROM attendance WHERE employee_id = ? AND date BETWEEN ? AND ?");
    $st->execute([$empId, $start, $end]);
    $worked = $absent = $late = $lateMin = $under = $underMin = 0; $hours = 0.0;
    foreach ($st->fetchAll() as $r) {
        if (in_array($r['status'], ['present','late','half-day'], true)) $worked++;
        if ($r['status'] === 'absent') $absent++;
        if ((int)$r['late_minutes'] > 0)   { $late++;  $lateMin  += (int)$r['late_minutes']; }
        if ((int)$r['undertime_mins'] > 0) { $under++; $underMin += (int)$r['undertime_mins']; }
        $hours += (float)$r['hours_worked'];
    }
    // days on leave = working days covered by approved leave inside the period
    $l = $db->prepare("SELECT start_date, end_date FROM leave_requests WHERE employee_id = ?
                       AND status IN ('approved','approved_pending_documents','documents_submitted')
                       AND start_date <= ? AND end_date >= ?");
    $l->execute([$empId, $end, $start]);
    $leaveDays = 0;
    foreach ($l->fetchAll() as $lv) {
        $from = max($start, $lv['start_date']); $to = min($end, $lv['end_date']);
        for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) if ((int)date('N', $t) <= 5) $leaveDays++;
    }
    return ['days_worked' => $worked, 'days_absent' => $absent, 'days_leave' => $leaveDays, 'late_count' => $late,
            'late_minutes' => $lateMin, 'undertime_count' => $under, 'undertime_minutes' => $underMin,
            'hours' => round($hours, 2)];
}

// ── Loans disclosed by employees ──────────────────────────
const LOAN_DOC_PATH = __DIR__ . '/../uploads/loan_documents/';

function loanStatusLabel(string $s): string {
    return ['for_review' => 'For HR Review', 'verified' => 'Verified', 'approved' => 'Approved for Payroll Deduction',
            'rejected' => 'Rejected', 'needs_info' => 'Needs Additional Document/Information'][$s] ?? ucfirst($s);
}

function storeLoanDocument(array $file, string $empId): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('The document could not be uploaded. Please try again.');
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('The uploaded document is invalid.');
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) throw new InvalidArgumentException('Document is too large (maximum 5 MB).');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    if (!isset($allowed[$mime])) throw new InvalidArgumentException('Document must be a PDF, JPG/JPEG or PNG file.');
    if (!is_dir(LOAN_DOC_PATH) && !mkdir(LOAN_DOC_PATH, 0755, true) && !is_dir(LOAN_DOC_PATH)) throw new RuntimeException('Failed to create the document folder.');
    $stored = 'loan_' . preg_replace('/[^A-Za-z0-9_-]/', '', $empId) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], LOAN_DOC_PATH . $stored)) throw new RuntimeException('Failed to save the document.');
    return ['path' => $stored, 'name' => substr(preg_replace('/[^\w.\- ]/u', '_', basename($file['name'] ?? 'document')), 0, 200), 'mime' => $mime];
}

/**
 * Canonical loan deduction schedule: '15th' (first cutoff, 1st-15th) or
 * 'end_of_month' (second cutoff, 16th-end). Older rows and imports used other
 * spellings ("15", "First Cutoff", "EOM", ...); those used to match no cutoff at all.
 */
function normalizeLoanSchedule(?string $schedule): string {
    $v = strtolower(trim((string)$schedule));
    $v = preg_replace('/[\s\-\/]+/', '_', $v);
    $first  = ['15th', '15', 'every_15th', 'first_cutoff', '1st_cutoff', 'first', '1st', 'mid_month', 'midmonth', '1_15', '1st_15th', 'cutoff_1'];
    $second = ['end_of_month', 'eom', 'end_month', 'month_end', 'last_day', 'last_day_of_month', '30th', '30', '31', 'second_cutoff', '2nd_cutoff', 'second', '2nd', '16_end', '16th_end', 'cutoff_2'];
    if (in_array($v, $first, true)) return '15th';
    if (in_array($v, $second, true)) return 'end_of_month';
    return '';
}

// Next deduction date for a disclosed loan from a given month.
function loanNextDeductionDate(string $schedule, ?string $after = null): string {
    $schedule = normalizeLoanSchedule($schedule) ?: 'end_of_month';
    $ts = strtotime($after ?? date('Y-m-d'));
    $cand = $schedule === '15th' ? date('Y-m-15', $ts) : date('Y-m-t', $ts);
    if ($cand <= date('Y-m-d', $ts) && $after !== null) {
        $next = strtotime('first day of next month', $ts);
        $cand = $schedule === '15th' ? date('Y-m-15', $next) : date('Y-m-t', $next);
    }
    return $cand;
}

/** Match a loan schedule to the first (1st-15th) or second (16th-end) payroll cutoff, using the PERIOD END date. */
function loanScheduleMatchesPayrollPeriod(string $schedule, string $periodEnd): bool {
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd);
    if (!$end || $end->format('Y-m-d') !== $periodEnd) return false;
    $day = (int)$end->format('j');
    $schedule = normalizeLoanSchedule($schedule);
    if ($schedule === '15th') return $day <= 15;
    if ($schedule === 'end_of_month') return $day > 15;
    return false;
}

/**
 * Payments whose payroll row was deleted (e.g. a payroll was removed and re-created)
 * still occupied the loan's month (UNIQUE disclosure_id+payment_month) and had already
 * reduced outstanding_balance, so every later generation silently skipped the deduction.
 * Remove them and give the amount back to the loan. Call inside the payroll transaction.
 */
function purgeOrphanLoanPayments(PDO $db, string $empId): void {
    try {
        $st = $db->prepare("SELECT ldp.id, ldp.disclosure_id, ldp.amount
                            FROM loan_disclosure_payments ldp
                            JOIN loan_disclosures ld ON ld.id = ldp.disclosure_id
                            LEFT JOIN payroll p ON p.id = ldp.payroll_id
                            WHERE ld.employee_id = ? AND ldp.payroll_id IS NOT NULL AND p.id IS NULL
                            FOR UPDATE");
        $st->execute([$empId]);
        $orphans = $st->fetchAll();
    } catch (Throwable $e) { return; }
    foreach ($orphans as $o) {
        $db->prepare("DELETE FROM loan_disclosure_payments WHERE id = ?")->execute([$o['id']]);
        $db->prepare("UPDATE loan_disclosures SET outstanding_balance = outstanding_balance + ?, loan_status = 'active' WHERE id = ?")
           ->execute([$o['amount'], $o['disclosure_id']]);
    }
}

/** True when the loan already has a repayment for this month (optionally ignoring one payroll's own payment). */
function loanPaymentRecordedForMonth(PDO $db, int $loanId, string $month, ?int $excludePayrollId = null): bool {
    $sql = "SELECT 1 FROM loan_disclosure_payments ldp
            LEFT JOIN payroll p ON p.id = ldp.payroll_id
            WHERE ldp.disclosure_id = ? AND ldp.payment_month = ?
              AND (ldp.payroll_id IS NULL OR p.id IS NOT NULL)";
    $args = [$loanId, $month];
    if ($excludePayrollId) { $sql .= " AND (ldp.payroll_id IS NULL OR ldp.payroll_id <> ?)"; $args[] = $excludePayrollId; }
    $st = $db->prepare($sql . " LIMIT 1");
    $st->execute($args);
    return (bool)$st->fetchColumn();
}

// Called inside the payroll-generation transaction. Deducts each APPROVED, active disclosed loan
// once per month, only on the payroll period that matches its schedule (15th / end of month),
// capped at the remaining balance and the payroll's net pay.
// Returns the amount deducted by THIS call (0 when nothing applies or it was already applied).
function applyAutoLoanDeductions(PDO $db, string $empId, int $payrollId, string $periodEnd): float {
    try {
        purgeOrphanLoanPayments($db, $empId);
        $st = $db->prepare("SELECT * FROM loan_disclosures WHERE employee_id = ? AND status = 'approved' AND loan_status = 'active' AND outstanding_balance > 0 ORDER BY id FOR UPDATE");
        $st->execute([$empId]);
        $loans = $st->fetchAll();
        $np = $db->prepare("SELECT net_salary FROM payroll WHERE id = ? FOR UPDATE");
        $np->execute([$payrollId]);
        $available = max(0.0, (float)$np->fetchColumn());
    } catch (Throwable $e) { return 0.0; }   // table not migrated
    $month = date('Y-m', strtotime($periodEnd));
    $total = 0.0;
    foreach ($loans as $ln) {
        if (!loanScheduleMatchesPayrollPeriod((string)$ln['deduction_schedule'], $periodEnd)) continue;
        if (!empty($ln['loan_start_date']) && $ln['loan_start_date'] > $periodEnd) continue;
        if (loanPaymentRecordedForMonth($db, (int)$ln['id'], $month)) continue;          // already deducted this month
        $amt = round(min((float)$ln['monthly_amortization'], (float)$ln['outstanding_balance'], $available), 2);
        if ($amt <= 0) continue;
        $bal = max(0.0, round((float)$ln['outstanding_balance'] - $amt, 2));
        $db->prepare("INSERT INTO loan_disclosure_payments (disclosure_id, payroll_id, payment_month, payment_date, amount, balance_after) VALUES (?,?,?,?,?,?)")
           ->execute([$ln['id'], $payrollId, $month, $periodEnd, $amt, $bal]);
        $db->prepare("UPDATE loan_disclosures SET outstanding_balance = ?, loan_status = ?, next_deduction_date = ? WHERE id = ?")
           ->execute([$bal, $bal <= 0 ? 'fully_paid' : 'active',
                      $bal <= 0 ? null : loanNextDeductionDate($ln['deduction_schedule'], $periodEnd), $ln['id']]);
        $available = round($available - $amt, 2);
        $total += $amt;
    }
    if ($total > 0) {
        $db->prepare("UPDATE payroll SET loan_deduction = loan_deduction + ?, total_deductions = total_deductions + ?, net_salary = net_salary - ?, loan_balance_snapshot = NULL WHERE id = ?")
           ->execute([$total, $total, $total, $payrollId]);
    }
    return round($total, 2);
}


// Tab bar used on every list page (same look everywhere): [key => [label, count]]
function renderTabBar(array $tabs, string $active, string $param = 'tab', array $keep = []): string {
    $out = '<div class="tab-bar">';
    foreach ($tabs as $key => [$label, $count]) {
        $qs = http_build_query(array_merge($keep, [$param => $key]));
        $out .= '<a href="?' . htmlspecialchars($qs) . '" class="tab-link' . ($active === $key ? ' active' : '') . '">'
              . htmlspecialchars($label) . ' <span class="tab-count">' . (int)$count . '</span></a>';
    }
    return $out . '</div>';
}

/**
 * "Search anything" clause for live search.
 * Splits $search into words; every word must appear in at least one of $columns (SQL expressions).
 * Appends the bound values to $params and returns " AND (...) AND (...)" (empty string when no search).
 */
function searchClause(array $columns, string $search, array &$params): string {
    $search = trim(htmlspecialchars_decode($search, ENT_QUOTES));   // sanitize() HTML-encodes quotes
    if ($search === '') return '';
    $sql = '';
    foreach (preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) as $word) {
        $like = '%' . addcslashes($word, '\\%_') . '%';
        $parts = [];
        foreach ($columns as $c) { $parts[] = "$c LIKE ?"; $params[] = $like; }
        $sql .= ' AND (' . implode(' OR ', $parts) . ')';
    }
    return $sql;
}
