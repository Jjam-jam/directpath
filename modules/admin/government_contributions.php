<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin', 'payroll_manager', 'superadmin');

$pageTitle = 'Government Contribution Schedules';
$activePage = 'government_contributions';
$db = getDB();
$agencies = ['SSS', 'PhilHealth', 'Pag-IBIG'];
$role = $_SESSION['role'] ?? '';
$canManage = in_array($role, ['hr_admin', 'superadmin'], true);
$canActivate = in_array($role, ['hr_admin', 'superadmin'], true);
$success = $_SESSION['contribution_schedule_success'] ?? '';
unset($_SESSION['contribution_schedule_success']);
$error = '';

$requiredTables = ['government_contribution_schedules', 'government_contribution_rules', 'sss_contribution_table'];
$tableCheck = $db->prepare("
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name IN (?, ?, ?)
");
$tableCheck->execute($requiredTables);
$contributionTablesReady = (int)$tableCheck->fetchColumn() === count($requiredTables);
$sourceUrlReady = false;
$payrollSnapshotReady = false;
if ($contributionTablesReady) {
    $columnCheck = $db->prepare("
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'government_contribution_schedules'
          AND column_name = 'source_url'
    ");
    $columnCheck->execute();
    $sourceUrlReady = (int)$columnCheck->fetchColumn() === 1;
    $snapshotCheck = $db->query("
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'payroll'
          AND column_name IN ('sss_employer','sss_ec_employer','philhealth_employer','pagibig_employer','government_contributions_snapshot')
    ");
    $payrollSnapshotReady = (int)$snapshotCheck->fetchColumn() === 5;
}
$approvalReady = false;
if ($contributionTablesReady) {
    $approvalCheck = $db->query("
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'government_contribution_schedules'
          AND column_name IN ('approval_status','submitted_at','reviewed_by','reviewed_by_name','reviewed_at','reject_reason')
    ");
    $notificationTableCheck = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='government_contribution_notifications'");
    $approvalReady = (int)$approvalCheck->fetchColumn() === 6 && (int)$notificationTableCheck->fetchColumn() === 1;
}
$schemaReady = $contributionTablesReady && $sourceUrlReady && $payrollSnapshotReady && $approvalReady;
if ($schemaReady && isset($_GET['notification_id'])) {
    $db->prepare("UPDATE government_contribution_notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND recipient_role=?")
       ->execute([(int)$_GET['notification_id'], $role]);
}

function contributionScheduleStatus(array $schedule): array {
    $today = date('Y-m-d');
    $approval = $schedule['approval_status'] ?? 'approved';
    if ($approval === 'rejected') {
        return ['label' => 'Rejected', 'class' => 'badge-red'];
    }
    if (!empty($schedule['effective_until']) && $schedule['effective_until'] < $today) {
        return ['label' => 'Expired', 'class' => 'badge-gray'];
    }
    if ($approval === 'pending_ceo') {
        return ['label' => 'Pending CEO Approval', 'class' => 'badge-amber'];
    }
    if ($schedule['status'] === 'active') {
        return ['label' => 'Active', 'class' => 'badge-green'];
    }
    if ($schedule['status'] === 'pending') {
        return ['label' => 'Approved – Ready to Activate', 'class' => 'badge-blue'];
    }
    return ['label' => 'Inactive', 'class' => 'badge-gray'];
}

/** Text shown to the Payroll Manager / HR about the CEO's decision. */
function contributionDecisionText(array $schedule): string {
    $approval = $schedule['approval_status'] ?? 'approved';
    if ($approval === 'pending_ceo') return 'Waiting for CEO review';
    if (empty($schedule['reviewed_at'])) return '—';
    $who = trim((string)($schedule['reviewed_by_name'] ?? '')) ?: 'CEO';
    return ($approval === 'rejected' ? 'Rejected by ' : 'Approved by ') . $who . ' on ' . date('M j, Y g:i A', strtotime($schedule['reviewed_at']));
}

function contributionRangesOverlap(array $left, array $right): bool {
    $leftMax = $left['salary_max'] === null ? PHP_INT_MAX : GovernmentContributionEngine::toCents($left['salary_max']);
    $rightMax = $right['salary_max'] === null ? PHP_INT_MAX : GovernmentContributionEngine::toCents($right['salary_max']);
    return GovernmentContributionEngine::toCents($left['salary_min']) <= $rightMax
        && GovernmentContributionEngine::toCents($right['salary_min']) <= $leftMax;
}

function contributionRuleInputs(array $input, string $agency): array {
    if (!$input || !array_is_list($input)) {
        throw new InvalidArgumentException('Add at least one contribution rule before saving.');
    }

    $rules = [];
    foreach ($input as $index => $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException('Contribution rules contain an invalid row.');
        }

        $min = GovernmentContributionEngine::toCents((string)($row['salary_min'] ?? ''));
        $maxText = trim((string)($row['salary_max'] ?? ''));
        $max = $maxText === '' ? null : GovernmentContributionEngine::toCents($maxText);
        if ($max !== null && $max < $min) {
            throw new InvalidArgumentException('Rule ' . ($index + 1) . ': Maximum compensation must be greater than or equal to minimum compensation.');
        }

        if ($agency === 'SSS') {
            $rule = [
                'salary_min' => GovernmentContributionEngine::fromCents($min),
                'salary_max' => $max === null ? null : GovernmentContributionEngine::fromCents($max),
            ];
            foreach ([
                'msc', 'regular_ss_employee', 'regular_ss_employer',
                'mpf_employee', 'mpf_employer', 'ec_employer',
            ] as $field) {
                $rule[$field] = GovernmentContributionEngine::fromCents(
                    GovernmentContributionEngine::toCents((string)($row[$field] ?? ''))
                );
            }
            $rule['ec_employee'] = '0.00';
            if (GovernmentContributionEngine::toCents($rule['msc']) <= 0) {
                throw new InvalidArgumentException('Rule ' . ($index + 1) . ': MSC must be greater than zero.');
            }
            if (array_sum(array_map(
                static fn(string $field): int => GovernmentContributionEngine::toCents($rule[$field]),
                ['regular_ss_employee', 'regular_ss_employer', 'mpf_employee', 'mpf_employer', 'ec_employer']
            )) <= 0) {
                throw new InvalidArgumentException('Rule ' . ($index + 1) . ': Enter at least one employee or employer contribution amount.');
            }
        } else {
            $mode = (string)($row['contribution_base_mode'] ?? '');
            if (!in_array($mode, ['salary', 'salary_capped', 'fixed'], true)) {
                throw new InvalidArgumentException('Rule ' . ($index + 1) . ': Choose a valid contribution basis.');
            }
            $rule = [
                'salary_min' => GovernmentContributionEngine::fromCents($min),
                'salary_max' => $max === null ? null : GovernmentContributionEngine::fromCents($max),
                'contribution_base_mode' => $mode,
            ];
            foreach (['contribution_base', 'contribution_base_floor', 'contribution_base_cap'] as $field) {
                $value = trim((string)($row[$field] ?? ''));
                $rule[$field] = $value === '' ? null : GovernmentContributionEngine::fromCents(
                    GovernmentContributionEngine::toCents($value)
                );
            }
            if ($mode === 'salary_capped' && ($rule['contribution_base_cap'] === null || $rule['contribution_base_cap'] === '0.00')) {
                throw new InvalidArgumentException('Rule ' . ($index + 1) . ': Enter a positive maximum contribution basis.');
            }
            if ($mode === 'fixed' && ($rule['contribution_base'] === null || $rule['contribution_base'] === '0.00')) {
                throw new InvalidArgumentException('Rule ' . ($index + 1) . ': Enter a positive fixed contribution basis.');
            }
            if ($rule['contribution_base_floor'] !== null && $rule['contribution_base_cap'] !== null &&
                GovernmentContributionEngine::toCents($rule['contribution_base_floor']) > GovernmentContributionEngine::toCents($rule['contribution_base_cap'])) {
                throw new InvalidArgumentException('Rule ' . ($index + 1) . ': Minimum contribution basis cannot exceed the maximum basis.');
            }

            foreach (['employee_rate', 'employer_rate'] as $field) {
                $rate = trim((string)($row[$field] ?? ''));
                if ($rate === '') {
                    $rate = '0';
                }
                if (!preg_match('/^\d{1,3}(?:\.\d{1,5})?$/', $rate) || (float)$rate > 100) {
                    throw new InvalidArgumentException('Rule ' . ($index + 1) . ': Contribution rates must be between 0 and 100 percent, with up to five decimal places.');
                }
                $rule[$field] = $rate;
            }
            foreach (['employee_fixed_amount', 'employer_fixed_amount'] as $field) {
                $rule[$field] = GovernmentContributionEngine::fromCents(
                    GovernmentContributionEngine::toCents((string)($row[$field] ?? ''))
                );
            }
            if ((float)$rule['employee_rate'] === 0.0 && (float)$rule['employer_rate'] === 0.0 &&
                GovernmentContributionEngine::toCents($rule['employee_fixed_amount']) === 0 &&
                GovernmentContributionEngine::toCents($rule['employer_fixed_amount']) === 0) {
                throw new InvalidArgumentException('Rule ' . ($index + 1) . ': Enter an employee or employer contribution rate or fixed amount.');
            }
        }

        $rules[] = $rule;
    }

    for ($i = 0; $i < count($rules); $i++) {
        for ($j = $i + 1; $j < count($rules); $j++) {
            if (contributionRangesOverlap($rules[$i], $rules[$j])) {
                throw new InvalidArgumentException('Compensation ranges overlap. Please review the ranges before saving.');
            }
        }
    }
    return $rules;
}

function loadContributionRules(PDO $db, array $schedule): array {
    if ($schedule['agency'] === 'SSS') {
        $stmt = $db->prepare('SELECT * FROM sss_contribution_table WHERE schedule_id = ? ORDER BY salary_min, id');
    } else {
        $stmt = $db->prepare('SELECT * FROM government_contribution_rules WHERE schedule_id = ? ORDER BY salary_min, id');
    }
    $stmt->execute([$schedule['id']]);
    return $stmt->fetchAll();
}

function contributionScheduleInFinalizedPayroll(PDO $db, int $scheduleId): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM payroll
        WHERE status = 'released'
          AND (
            JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(government_contributions_snapshot) THEN government_contributions_snapshot ELSE NULL END, '$.contributions.SSS.schedule.id')) = ?
            OR JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(government_contributions_snapshot) THEN government_contributions_snapshot ELSE NULL END, '$.contributions.PhilHealth.schedule.id')) = ?
            OR JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(government_contributions_snapshot) THEN government_contributions_snapshot ELSE NULL END, '$.\"contributions\".\"Pag-IBIG\".\"schedule\".\"id\"')) = ?
          )
    ");
    $id = (string)$scheduleId;
    $stmt->execute([$id, $id, $id]);
    return (int)$stmt->fetchColumn() > 0;
}

function persistContributionRules(PDO $db, int $scheduleId, string $agency, array $rules): void {
    if ($agency === 'SSS') {
        $insert = $db->prepare("
            INSERT INTO sss_contribution_table
                (schedule_id,salary_min,salary_max,msc,regular_ss_employee,regular_ss_employer,
                 mpf_employee,mpf_employer,ec_employee,ec_employer)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        foreach ($rules as $rule) {
            $insert->execute([
                $scheduleId, $rule['salary_min'], $rule['salary_max'], $rule['msc'],
                $rule['regular_ss_employee'], $rule['regular_ss_employer'],
                $rule['mpf_employee'], $rule['mpf_employer'], $rule['ec_employee'], $rule['ec_employer'],
            ]);
        }
        return;
    }

    $insert = $db->prepare("
        INSERT INTO government_contribution_rules
            (schedule_id,salary_min,salary_max,contribution_base_mode,contribution_base,
             contribution_base_floor,contribution_base_cap,employee_rate,employer_rate,
             employee_fixed_amount,employer_fixed_amount)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
    foreach ($rules as $rule) {
        $insert->execute([
            $scheduleId, $rule['salary_min'], $rule['salary_max'], $rule['contribution_base_mode'],
            $rule['contribution_base'], $rule['contribution_base_floor'], $rule['contribution_base_cap'],
            $rule['employee_rate'], $rule['employer_rate'], $rule['employee_fixed_amount'], $rule['employer_fixed_amount'],
        ]);
    }
}

$scheduleId = (int)($_GET['id'] ?? 0);
$viewOnly = isset($_GET['view']) && $scheduleId > 0;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$adding = isset($_GET['add']) && $canManage;
$editing = $editId > 0 && $canManage;
$formValues = [
    'agency' => '',
    'schedule_name' => '',
    'effective_from' => '',
    'effective_until' => '',
    'source_reference' => '',
    'source_url' => '',
];
$formRules = [];
$currentSchedule = null;
$currentRules = [];

if ($schemaReady && ($viewOnly || $editing)) {
    $scheduleQuery = $db->prepare('SELECT * FROM government_contribution_schedules WHERE id = ?');
    $scheduleQuery->execute([$scheduleId ?: $editId]);
    $currentSchedule = $scheduleQuery->fetch() ?: null;
    if (!$currentSchedule) {
        $error = 'Contribution schedule not found.';
        $viewOnly = false;
        $editing = false;
    } else {
        $currentRules = loadContributionRules($db, $currentSchedule);
        if ($editing) {
            $formValues = [
                'agency' => $currentSchedule['agency'],
                'schedule_name' => $currentSchedule['schedule_name'],
                'effective_from' => $currentSchedule['effective_from'],
                'effective_until' => $currentSchedule['effective_until'] ?? '',
                'source_reference' => $currentSchedule['source_reference'],
                'source_url' => $currentSchedule['source_url'] ?? '',
            ];
            $formRules = $currentRules;
            if ($currentSchedule['status'] === 'active' || contributionScheduleInFinalizedPayroll($db, (int)$currentSchedule['id'])) {
                $error = 'This schedule is active or was used in finalized payroll. Add a new effective-dated schedule instead.';
                $editing = false;
                $viewOnly = true;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        http_response_code(403);
        $error = 'Only HR Admins and Super Admins can manage contribution schedules.';
    } elseif (!$schemaReady) {
        http_response_code(503);
        $error = 'Apply the contribution schedule migration before managing schedules.';
    } elseif (!csrfTokenIsValid($_POST['_csrf_token'] ?? null)) {
        http_response_code(403);
        $error = 'Your session validation failed. Refresh the page and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'save_schedule') {
                $agency = (string)($_POST['agency'] ?? '');
                $name = trim((string)($_POST['schedule_name'] ?? ''));
                $from = trim((string)($_POST['effective_from'] ?? ''));
                $until = trim((string)($_POST['effective_until'] ?? ''));
                $sourceReference = trim((string)($_POST['source_reference'] ?? ''));
                $sourceUrl = trim((string)($_POST['source_url'] ?? ''));
                $targetId = (int)($_POST['schedule_id'] ?? 0);
                $isEdit = $targetId > 0;
                if (!in_array($agency, $agencies, true)) {
                    throw new InvalidArgumentException('Choose SSS, PhilHealth, or Pag-IBIG.');
                }
                if ($name === '' || strlen($name) > 150) {
                    throw new InvalidArgumentException('Enter a schedule name (up to 150 characters).');
                }
                if (!GovernmentContributionEngine::isValidDate($from) ||
                    ($until !== '' && (!GovernmentContributionEngine::isValidDate($until) || $until < $from))) {
                    throw new InvalidArgumentException('Enter valid effective dates. Effective Until cannot be before Effective From.');
                }
                if (strlen($sourceReference) > 500) {
                    throw new InvalidArgumentException('Official Source Reference must be 500 characters or fewer.');
                }
                if ($sourceUrl !== '' && (strlen($sourceUrl) > 1000 || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false ||
                    !in_array(strtolower((string)parse_url($sourceUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                    throw new InvalidArgumentException('Enter a valid official source URL beginning with http:// or https://.');
                }
                $rules = contributionRuleInputs($_POST['rules'] ?? [], $agency);

                $db->beginTransaction();
                if ($targetId > 0) {
                    $lock = $db->prepare('SELECT * FROM government_contribution_schedules WHERE id = ? FOR UPDATE');
                    $lock->execute([$targetId]);
                    $previous = $lock->fetch();
                    if (!$previous) {
                        throw new DomainException('Contribution schedule not found.');
                    }
                    if ($previous['status'] === 'active' || contributionScheduleInFinalizedPayroll($db, $targetId)) {
                        throw new DomainException('This schedule is active or was used in finalized payroll. Add a new effective-dated schedule instead.');
                    }
                    if ($previous['agency'] !== $agency) {
                        throw new DomainException('The agency cannot be changed when editing a schedule. Create a new schedule for a different agency.');
                    }
                    $oldRules = loadContributionRules($db, $previous);
                    $collision = $db->prepare("
                        SELECT id FROM government_contribution_schedules
                        WHERE agency = ? AND status = 'pending' AND approval_status <> 'rejected' AND id <> ?
                          AND (effective_until IS NULL OR effective_until >= ?)
                          AND (? IS NULL OR effective_from <= ?)
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $endDate = $until === '' ? null : $until;
                    $collision->execute([$agency, $targetId, $from, $endDate, $endDate ?? '9999-12-31']);
                    if ($collision->fetchColumn()) {
                        throw new DomainException('Another draft or scheduled contribution schedule conflicts with these effective dates.');
                    }
                    $db->prepare("
                        UPDATE government_contribution_schedules
                        SET schedule_name=?, effective_from=?, effective_until=?, source_reference=?, source_url=?,
                            status='pending', approval_status='pending_ceo', submitted_at=NOW(),
                            reviewed_by=NULL, reviewed_by_name=NULL, reviewed_at=NULL, reject_reason=NULL,
                            verified_at=NULL, verified_by=NULL
                        WHERE id=?
                    ")->execute([$name, $from, $endDate, $sourceReference, $sourceUrl ?: null, $targetId]);
                    $ruleTable = $agency === 'SSS' ? 'sss_contribution_table' : 'government_contribution_rules';
                    $db->prepare("DELETE FROM $ruleTable WHERE schedule_id=?")->execute([$targetId]);
                    persistContributionRules($db, $targetId, $agency, $rules);
                    $auditAction = 'Edit and Resubmit Contribution Schedule for CEO Approval';
                    $auditDetails = [
                        'agency' => $agency,
                        'schedule_id' => $targetId,
                        'previous_values' => [
                            'schedule_name' => $previous['schedule_name'],
                            'effective_from' => $previous['effective_from'],
                            'effective_until' => $previous['effective_until'],
                            'source_reference' => $previous['source_reference'],
                            'source_url' => $previous['source_url'] ?? null,
                            'rules' => $oldRules,
                        ],
                        'new_values' => [
                            'schedule_name' => $name,
                            'effective_from' => $from,
                            'effective_until' => $endDate,
                            'source_reference' => $sourceReference,
                            'source_url' => $sourceUrl ?: null,
                            'rules' => $rules,
                        ],
                    ];
                } else {
                    $overlap = $db->prepare("
                        SELECT id FROM government_contribution_schedules
                        WHERE agency=? AND status='pending' AND approval_status <> 'rejected'
                          AND (effective_until IS NULL OR effective_until >= ?)
                          AND (? IS NULL OR effective_from <= ?)
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $endDate = $until === '' ? null : $until;
                    $overlap->execute([$agency, $from, $endDate, $endDate ?? '9999-12-31']);
                    if ($overlap->fetchColumn()) {
                        throw new DomainException('Another draft or scheduled contribution schedule conflicts with these effective dates. Adjust the dates before saving.');
                    }
                    $db->prepare("
                        INSERT INTO government_contribution_schedules
                            (agency,schedule_name,effective_from,effective_until,status,approval_status,submitted_at,source_reference,source_url,created_by)
                        VALUES (?,?,?,?, 'pending','pending_ceo',NOW(),?,?,?)
                    ")->execute([$agency, $name, $from, $endDate, $sourceReference, $sourceUrl ?: null, $_SESSION['employee_id']]);
                    $targetId = (int)$db->lastInsertId();
                    persistContributionRules($db, $targetId, $agency, $rules);
                    $auditAction = 'Submit Contribution Schedule for CEO Approval';
                    $auditDetails = [
                        'agency' => $agency,
                        'schedule_name' => $name,
                        'effective_from' => $from,
                        'effective_until' => $endDate,
                        'source_reference' => $sourceReference,
                        'source_url' => $sourceUrl ?: null,
                        'new_values' => $rules,
                    ];
                }

                $db->prepare("INSERT INTO government_contribution_notifications (schedule_id, recipient_role, message) VALUES (?, 'ceo', ?)")
                   ->execute([$targetId, 'Contribution schedule submitted for CEO approval: ' . $name . ' (' . $agency . ').']);
                $db->prepare("INSERT INTO government_contribution_notifications (schedule_id, recipient_role, message) VALUES (?, 'payroll_manager', ?)")
                   ->execute([$targetId, 'Contribution schedule awaiting CEO approval: ' . $name . ' (' . $agency . ').']);

                if (!auditLog($auditAction, 'Government Contributions', json_encode($auditDetails, JSON_THROW_ON_ERROR))) {
                    throw new RuntimeException('The schedule was not saved because the audit log could not be written.');
                }
                $db->commit();
                $_SESSION['contribution_schedule_success'] = $isEdit
                    ? 'Contribution schedule updated and resubmitted to the CEO for approval. HR can activate it after approval.'
                    : 'Contribution schedule submitted to the CEO for approval. After approval, HR can activate it.';
                header('Location: government_contributions.php');
                exit;
            }

            if ($action === 'activate' || $action === 'deactivate') {
                if (!$canActivate) throw new DomainException('Only HR Admins can activate or deactivate contribution schedules.');
                $targetId = (int)($_POST['schedule_id'] ?? 0);
                $db->beginTransaction();
                $lock = $db->prepare('SELECT * FROM government_contribution_schedules WHERE id=? FOR UPDATE');
                $lock->execute([$targetId]);
                $schedule = $lock->fetch();
                if (!$schedule) {
                    throw new DomainException('Contribution schedule not found.');
                }
                if ($action === 'activate') {
                    if (($schedule['approval_status'] ?? '') !== 'approved') {
                        throw new DomainException(($schedule['approval_status'] ?? '') === 'rejected'
                            ? 'This schedule was rejected by the CEO and cannot be activated.'
                            : 'The CEO must approve this schedule before it can be activated.');
                    }
                    if (!empty($schedule['effective_until']) && $schedule['effective_until'] < date('Y-m-d')) {
                        throw new DomainException('Expired schedules cannot be activated.');
                    }
                    $ruleTable = $schedule['agency'] === 'SSS' ? 'sss_contribution_table' : 'government_contribution_rules';
                    $ruleCount = $db->prepare("SELECT COUNT(*) FROM $ruleTable WHERE schedule_id=?");
                    $ruleCount->execute([$targetId]);
                    if ((int)$ruleCount->fetchColumn() === 0) {
                        throw new DomainException('Add at least one contribution rule before activating this schedule.');
                    }
                    if (trim((string)$schedule['source_reference']) === '' && trim((string)($schedule['source_url'] ?? '')) === '') {
                        throw new DomainException('Add an official source reference or URL before verifying and activating this schedule.');
                    }
                    $collision = $db->prepare("
                        SELECT id,schedule_name,effective_from,effective_until
                        FROM government_contribution_schedules
                        WHERE agency=? AND status='active' AND id<>?
                          AND (effective_until IS NULL OR effective_until >= ?)
                          AND (? IS NULL OR effective_from <= ?)
                        FOR UPDATE
                    ");
                    $collision->execute([
                        $schedule['agency'], $targetId, $schedule['effective_from'],
                        $schedule['effective_until'], $schedule['effective_until'] ?: '9999-12-31',
                    ]);
                    $previousSchedules = $collision->fetchAll();
                    foreach ($previousSchedules as $previousSchedule) {
                        if ($previousSchedule['effective_from'] >= $schedule['effective_from']) {
                            throw new DomainException('Activation conflicts with an active schedule that starts on or after this schedule.');
                        }
                        $previousUntil = (new DateTimeImmutable($schedule['effective_from']))->modify('-1 day')->format('Y-m-d');
                        if ($previousSchedule['effective_from'] > $previousUntil) {
                            throw new DomainException('The previous active schedule cannot be closed before its effective date.');
                        }
                        $db->prepare('UPDATE government_contribution_schedules SET effective_until=? WHERE id=?')
                            ->execute([$previousUntil, $previousSchedule['id']]);
                        if (!auditLog('Close Superseded Contribution Schedule', 'Government Contributions',
                            json_encode([
                                'agency' => $schedule['agency'],
                                'schedule_name' => $previousSchedule['schedule_name'],
                                'previous_effective_until' => $previousSchedule['effective_until'],
                                'new_effective_until' => $previousUntil,
                                'superseded_by' => $schedule['schedule_name'],
                            ], JSON_THROW_ON_ERROR))) {
                            throw new RuntimeException('Activation failed because the previous schedule audit could not be saved.');
                        }
                    }
                    $db->prepare("UPDATE government_contribution_schedules SET status='active',verified_at=NOW(),verified_by=? WHERE id=?")
                        ->execute([$_SESSION['employee_id'], $targetId]);
                    $auditAction = 'Verify and Activate Contribution Schedule (CEO-approved)';
                    $newStatus = 'active';
                } else {
                    if ($schedule['status'] !== 'active') {
                        throw new DomainException('Only active schedules can be deactivated.');
                    }
                    $db->prepare("UPDATE government_contribution_schedules SET status='inactive' WHERE id=?")->execute([$targetId]);
                    $auditAction = 'Deactivate Contribution Schedule';
                    $newStatus = 'inactive';
                }
                if (!auditLog($auditAction, 'Government Contributions', json_encode([
                    'agency' => $schedule['agency'],
                    'schedule_name' => $schedule['schedule_name'],
                    'previous_status' => $schedule['status'],
                    'new_status' => $newStatus,
                    'effective_from' => $schedule['effective_from'],
                    'effective_until' => $schedule['effective_until'],
                ], JSON_THROW_ON_ERROR))) {
                    throw new RuntimeException('The schedule status was not changed because the audit log could not be written.');
                }
                if ($action === 'activate') {
                    $db->prepare("INSERT INTO government_contribution_notifications (schedule_id, recipient_role, message) VALUES (?, 'payroll_manager', ?)")
                       ->execute([$targetId, 'HR activated the contribution schedule: ' . $schedule['schedule_name'] . ' (' . $schedule['agency'] . '). It is available to payroll by effective date.']);
                }
                $db->commit();
                $_SESSION['contribution_schedule_success'] = $newStatus === 'active'
                    ? 'Contribution schedule activated successfully and will be used by payroll for its effective dates.'
                    : 'Contribution schedule deactivated and archived.';
                header('Location: government_contributions.php');
                exit;
            }

            throw new InvalidArgumentException('Choose a valid contribution schedule action.');
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $exception instanceof InvalidArgumentException || $exception instanceof DomainException
                ? $exception->getMessage()
                : 'The contribution schedule could not be saved. Review the values and try again.';
            if (isset($action) && $action === 'save_schedule') {
                $adding = $editId === 0;
                $editing = $editId > 0 && $canManage;
                $formValues = [
                    'agency' => (string)($_POST['agency'] ?? ''),
                    'schedule_name' => (string)($_POST['schedule_name'] ?? ''),
                    'effective_from' => (string)($_POST['effective_from'] ?? ''),
                    'effective_until' => (string)($_POST['effective_until'] ?? ''),
                    'source_reference' => (string)($_POST['source_reference'] ?? ''),
                    'source_url' => (string)($_POST['source_url'] ?? ''),
                ];
                $formRules = is_array($_POST['rules'] ?? null) ? $_POST['rules'] : [];
            }
        }
    }
}

$schedules = [];
if ($schemaReady) {
    $schedules = $db->query("
        SELECT s.*,
            (SELECT COUNT(*) FROM government_contribution_rules r WHERE r.schedule_id=s.id) +
            (SELECT COUNT(*) FROM sss_contribution_table b WHERE b.schedule_id=s.id) AS rule_count
        FROM government_contribution_schedules s
        ORDER BY FIELD(s.agency,'SSS','PhilHealth','Pag-IBIG'), s.effective_from DESC
    ")->fetchAll();
}

if ($adding && !$formRules) {
    $formRules = [[]];
}
if ($editing && !$formRules) {
    $formRules = [[]];
}
$awaitingActivation = $rejectedSchedules = $pendingCeoSchedules = [];
foreach ($schedules as $sc0) {
    $ap0 = $sc0['approval_status'] ?? 'approved';
    $expired0 = !empty($sc0['effective_until']) && $sc0['effective_until'] < date('Y-m-d');
    if ($ap0 === 'rejected') $rejectedSchedules[] = $sc0;
    elseif ($ap0 === 'pending_ceo') $pendingCeoSchedules[] = $sc0;
    elseif ($ap0 === 'approved' && $sc0['status'] === 'pending' && !$expired0 && !empty($sc0['reviewed_at'])) $awaitingActivation[] = $sc0;
}
$agTab = $_GET['agency'] ?? 'all';
$agCounts = ['all' => count($schedules)];
foreach ($schedules as $sc0) $agCounts[$sc0['agency']] = ($agCounts[$sc0['agency']] ?? 0) + 1;
if (in_array($agTab, ['SSS', 'PhilHealth', 'Pag-IBIG'], true)) $schedules = array_values(array_filter($schedules, fn($sc0) => $sc0['agency'] === $agTab));
else $agTab = 'all';
include '../../includes/header.php';
?>
<style>
.contribution-page{max-width:1240px;margin:auto}
.contribution-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap;margin-bottom:18px}
.contribution-heading h1{font-size:23px;margin:0 0 6px;color:#1A1D26}
.contribution-heading p,.helper{font-size:13px;color:#6B7280;margin:0;line-height:1.55}
.schedule-table{min-width:760px}
.schedule-table td{vertical-align:middle}
.rule-card{border:1px solid #E5E7EB;border-radius:10px;padding:14px;margin:12px 0;background:#fff}
.rule-card-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px;font-weight:700}
.rule-grid{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:12px}
.rule-grid .form-group{margin:0}
.rule-grid .wide{grid-column:span 2}
.schedule-form-actions{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:18px}
.source-link{overflow-wrap:anywhere}
.review-table{width:100%;border-collapse:collapse;margin-top:10px}
.review-table th,.review-table td{padding:8px;border-bottom:1px solid #E5E7EB;text-align:right}
.review-table th:first-child,.review-table td:first-child{text-align:left}
.overlap-warning{color:#92400E;background:#FFFBEB;border:1px solid #FDE68A;padding:9px 12px;border-radius:7px;margin-top:10px;display:none}
@media(max-width:760px){.rule-grid{grid-template-columns:repeat(2,minmax(120px,1fr))}.rule-grid .wide{grid-column:span 2}}
@media(max-width:480px){.rule-grid{grid-template-columns:1fr}.rule-grid .wide{grid-column:span 1}.schedule-form-actions>*{width:100%}}
</style>

<div class="page-body contribution-page">
    <?php if ($success): ?><div class="alert alert-success"><strong><?= htmlspecialchars($success) ?></strong><div>Payroll uses the CEO-approved schedule after HR activates it, for payroll dates within its effective period.</div></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (!$contributionTablesReady || !$sourceUrlReady || !$payrollSnapshotReady): ?><div class="alert alert-error">The contribution schedule or payroll snapshot schema is incomplete. Apply the additive <code>config/government_contributions_migration.sql</code>; existing schedules and payroll records are preserved.</div><?php elseif (!$approvalReady): ?><div class="alert alert-error">The CEO approval columns are missing. Apply <code>config/government_contributions_approval_migration.sql</code>; existing schedules are kept and marked as approved.</div><?php endif; ?>

    <?php if (!$viewOnly && !$adding && !$editing): echo renderTabBar(['all' => ['All Agencies', $agCounts['all']], 'SSS' => ['SSS', $agCounts['SSS'] ?? 0], 'PhilHealth' => ['PhilHealth', $agCounts['PhilHealth'] ?? 0], 'Pag-IBIG' => ['Pag-IBIG', $agCounts['Pag-IBIG'] ?? 0]], $agTab, 'agency'); endif; ?>
    <?php if ($viewOnly && $currentSchedule): ?>
        <?php $viewStatus = contributionScheduleStatus($currentSchedule); ?>
        <div class="contribution-heading">
            <div>
                <h1><?= htmlspecialchars($currentSchedule['schedule_name']) ?></h1>
                <p>Government contribution schedule details and stored rules.</p>
            </div>
            <a class="btn-secondary" href="government_contributions.php">Back to schedules</a>
        </div>
        <div class="card">
            <div class="row g-3">
                <div class="col-md-4"><strong>Agency</strong><div><?= htmlspecialchars($currentSchedule['agency']) ?></div></div>
                <div class="col-md-4"><strong>Effective From</strong><div><?= formatDate($currentSchedule['effective_from']) ?></div></div>
                <div class="col-md-4"><strong>Effective Until</strong><div><?= $currentSchedule['effective_until'] ? formatDate($currentSchedule['effective_until']) : '—' ?></div></div>
                <div class="col-md-4"><strong>Status</strong><div><span class="badge <?= $viewStatus['class'] ?>"><?= htmlspecialchars($viewStatus['label']) ?></span></div></div>
                <div class="col-md-4"><strong>Official Source</strong><div><?= htmlspecialchars($currentSchedule['source_reference'] ?: 'Not provided') ?></div></div>
                <div class="col-md-4"><strong>Official Source URL</strong><div class="source-link"><?php if (!empty($currentSchedule['source_url'])): ?><a href="<?= htmlspecialchars($currentSchedule['source_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($currentSchedule['source_url']) ?></a><?php else: ?>Not provided<?php endif; ?></div></div>
            </div>
        </div>
        <?php $viewApproval = $currentSchedule['approval_status'] ?? 'approved'; ?>
        <?php if ($viewApproval === 'rejected'): ?>
            <div class="alert alert-error"><strong>Rejected by the CEO — this schedule cannot be activated or used for payroll.</strong>
                <div style="margin-top:6px"><?= htmlspecialchars(contributionDecisionText($currentSchedule)) ?></div>
                <div style="margin-top:6px"><strong>Reason:</strong> <?= nl2br(htmlspecialchars((string)($currentSchedule['reject_reason'] ?? 'No reason was recorded.'))) ?></div>
                <div style="margin-top:6px" class="helper">To correct it, add a new contribution schedule with the right rules and submit it for approval again.</div>
            </div>
        <?php elseif ($viewApproval === 'pending_ceo'): ?>
            <div class="alert alert-info"><strong>Waiting for CEO approval.</strong> This schedule is not used for payroll until the CEO approves it.</div>
        <?php elseif (!empty($currentSchedule['reviewed_at'])): ?>
            <div class="alert alert-success"><strong><?= htmlspecialchars(contributionDecisionText($currentSchedule)) ?>.</strong>
                <?php if ($currentSchedule['status'] !== 'active'): ?> HR can activate this approved schedule after reviewing its details.<?php endif; ?></div>
        <?php endif; ?>
        <div class="card">
            <div class="card-title">Contribution Rules</div>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <?php if ($currentSchedule['agency'] === 'SSS'): ?>
                        <tr><th>Minimum Compensation</th><th>Maximum Compensation</th><th>MSC</th><th>Regular SS Employee</th><th>Regular SS Employer</th><th>MPF Employee</th><th>MPF Employer</th><th>ECP (Employer-paid)</th></tr>
                    <?php else: ?>
                        <tr><th>Minimum Compensation</th><th>Maximum Compensation</th><th>Contribution Basis</th><th>Minimum Basis</th><th>Maximum Basis</th><th>Employee Rate</th><th>Employer Rate</th><th>Employee Fixed</th><th>Employer Fixed</th></tr>
                    <?php endif; ?>
                    </thead>
                    <tbody>
                    <?php foreach ($currentRules as $rule): ?>
                        <tr>
                            <td><?= formatMoney((float)$rule['salary_min']) ?></td>
                            <td><?= $rule['salary_max'] === null ? 'No limit' : formatMoney((float)$rule['salary_max']) ?></td>
                            <?php if ($currentSchedule['agency'] === 'SSS'): ?>
                                <td><?= formatMoney((float)$rule['msc']) ?></td>
                                <td><?= formatMoney((float)$rule['regular_ss_employee']) ?></td>
                                <td><?= formatMoney((float)$rule['regular_ss_employer']) ?></td>
                                <td><?= formatMoney((float)$rule['mpf_employee']) ?></td>
                                <td><?= formatMoney((float)$rule['mpf_employer']) ?></td>
                                <td><?= formatMoney((float)$rule['ec_employer']) ?></td>
                            <?php else: ?>
                                <td><?= htmlspecialchars(str_replace('_', ' ', ucfirst($rule['contribution_base_mode']))) ?><?= $rule['contribution_base'] !== null ? ': ' . formatMoney((float)$rule['contribution_base']) : '' ?></td>
                                <td><?= $rule['contribution_base_floor'] === null ? '—' : formatMoney((float)$rule['contribution_base_floor']) ?></td>
                                <td><?= $rule['contribution_base_cap'] === null ? '—' : formatMoney((float)$rule['contribution_base_cap']) ?></td>
                                <td><?= htmlspecialchars($rule['employee_rate']) ?>%</td>
                                <td><?= htmlspecialchars($rule['employer_rate']) ?>%</td>
                                <td><?= formatMoney((float)$rule['employee_fixed_amount']) ?></td>
                                <td><?= formatMoney((float)$rule['employer_fixed_amount']) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$currentRules): ?><tr><td colspan="9" style="text-align:center">No rules have been entered.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
                    <div class="schedule-form-actions">
                <?php $viewExpired = !empty($currentSchedule['effective_until']) && $currentSchedule['effective_until'] < date('Y-m-d'); ?>
                <?php if ($canActivate && $viewApproval === 'approved' && $currentSchedule['status'] !== 'active' && !$viewExpired): ?>
                    <form method="post" onsubmit="return confirm('Activate this CEO-approved schedule? Any earlier active schedule will end the day before this schedule starts.');">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="activate"><input type="hidden" name="schedule_id" value="<?= (int)$currentSchedule['id'] ?>">
                        <button class="btn-primary" type="submit">Activate Schedule</button>
                    </form>
                <?php endif; ?>
                <?php if ($canActivate && $currentSchedule['status'] === 'active'): ?>
                    <form method="post" onsubmit="return confirm('Deactivate this schedule? Finalized payroll snapshots will remain unchanged.');">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="deactivate"><input type="hidden" name="schedule_id" value="<?= (int)$currentSchedule['id'] ?>">
                        <button class="btn-secondary" type="submit">Deactivate / Archive</button>
                    </form>
                <?php endif; ?>
                <?php if ($canManage && $currentSchedule['status'] !== 'active' && !contributionScheduleInFinalizedPayroll($db, (int)$currentSchedule['id'])): ?>
                    <a class="btn-secondary" href="government_contributions.php?edit=<?= (int)$currentSchedule['id'] ?>">Edit and Resubmit for CEO Approval</a>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif (($adding || $editing) && $canManage && $schemaReady): ?>
        <div class="contribution-heading">
            <div>
                <h1><?= $editing ? 'Edit Contribution Schedule' : 'Add Contribution Schedule' ?></h1>
                <p>Create a government contribution schedule that will be used by the payroll system to calculate employee deductions. Enter the official contribution rules exactly as provided by the government agency.</p>
            </div>
            <a class="btn-secondary" href="government_contributions.php">Cancel</a>
        </div>
        <?php if ($editing && $currentSchedule && ($currentSchedule['status'] === 'active' || contributionScheduleInFinalizedPayroll($db, (int)$currentSchedule['id']))): ?>
            <div class="alert alert-error">This schedule is active or has been used in finalized payroll. It cannot be changed; add a new schedule with its own effective date instead.</div>
        <?php else: ?>
        <form method="post" id="scheduleForm">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_schedule">
            <input type="hidden" name="schedule_id" value="<?= $editing ? (int)$editId : 0 ?>">
            <div class="card">
                <div class="card-title">Basic Information</div>
                <div class="row g-3">
                    <div class="col-md-4 form-group">
                        <label class="form-label" for="agency">Agency *</label>
                        <select class="form-select" id="agency" name="agency" required <?= $editing ? 'disabled' : '' ?>>
                            <option value="">Select an agency</option>
                            <?php foreach ($agencies as $agency): ?><option value="<?= htmlspecialchars($agency) ?>" <?= $formValues['agency'] === $agency ? 'selected' : '' ?>><?= htmlspecialchars($agency) ?></option><?php endforeach; ?>
                        </select>
                        <?php if ($editing): ?><input type="hidden" name="agency" value="<?= htmlspecialchars($formValues['agency']) ?>"><?php endif; ?>
                    </div>
                    <div class="col-md-8 form-group"><label class="form-label" for="schedule_name">Schedule Name *</label><input class="form-input" id="schedule_name" name="schedule_name" required maxlength="150" placeholder="SSS Contribution Schedule – 2025" value="<?= htmlspecialchars($formValues['schedule_name']) ?>"></div>
                    <div class="col-md-6 form-group"><label class="form-label" for="effective_from">Effective From *</label><input type="date" class="form-input" id="effective_from" name="effective_from" required value="<?= htmlspecialchars($formValues['effective_from']) ?>"><small class="helper">The payroll system will use this schedule starting on this date.</small></div>
                    <div class="col-md-6 form-group"><label class="form-label" for="effective_until">Effective Until (Optional)</label><input type="date" class="form-input" id="effective_until" name="effective_until" value="<?= htmlspecialchars($formValues['effective_until']) ?>"><small class="helper">Leave blank if no end date is known. End the prior schedule before activating a replacement.</small></div>
                    <div class="col-md-6 form-group"><label class="form-label" for="source_reference">Official Source Reference</label><input class="form-input" id="source_reference" name="source_reference" maxlength="500" placeholder="SSS Circular No. XXXX" value="<?= htmlspecialchars($formValues['source_reference']) ?>"></div>
                    <div class="col-md-6 form-group"><label class="form-label" for="source_url">Official Source URL</label><input type="url" class="form-input" id="source_url" name="source_url" maxlength="1000" placeholder="https://..." value="<?= htmlspecialchars($formValues['source_url']) ?>"><small class="helper">Enter the official government webpage, circular, or contribution table used as the source for this schedule.</small></div>
                </div>
            </div>
            <div class="card">
                <div class="card-title">Contribution Rules</div>
                <p class="helper">Add the official contribution brackets or rules from the selected government agency. These values are stored as system contribution rules.</p>
                <div id="rulesEmpty" class="alert alert-info" style="margin-top:12px">Select an agency to enter its contribution rules.</div>
                <div id="rulesContainer"></div>
                <div id="overlapWarning" class="overlap-warning" role="alert">This compensation range overlaps with another rule. Please review the ranges before saving.</div>
                <button type="button" id="addRuleButton" class="btn-secondary" style="margin-top:12px;display:none">+ Add Rule</button>
            </div>
            <div class="schedule-form-actions">
                <a class="btn-secondary" href="government_contributions.php">Cancel</a>
                <button type="button" id="reviewButton" class="btn-primary">Review Contribution Schedule</button>
            </div>
        </form>
        <?php endif; ?>

    <?php else: ?>
        <div class="contribution-heading">
            <div><h1>Government Contribution Schedules</h1><p><?= $canManage ? 'HR manages official SSS, PhilHealth, and Pag-IBIG schedules. CEO approval is required before HR activates changes.' : 'View-only schedule history and approval progress. Active schedules are applied to payroll by effective date.' ?></p></div>
            <?php if ($canManage && $schemaReady): ?><a class="btn-primary" href="government_contributions.php?add=1">Add Contribution Schedule</a><?php endif; ?>
        </div>
        <?php if ($canManage && $schemaReady): ?>
            <div class="alert alert-info"><strong>How it works:</strong> HR adds or edits a schedule and submits it to the CEO. CEO approval returns it to HR to activate. Payroll Managers can view the full status history and receive updates; only active, effective schedules are used in payroll. A schedule rejected by the CEO cannot be activated.</div>
            <?php if ($rejectedSchedules): ?><div class="alert alert-error"><strong><?= count($rejectedSchedules) ?> schedule(s) rejected by the CEO:</strong>
                <?php foreach ($rejectedSchedules as $rj): ?><div style="margin-top:4px"><a href="government_contributions.php?view=1&amp;id=<?= (int)$rj['id'] ?>"><?= htmlspecialchars($rj['schedule_name']) ?></a> — <?= htmlspecialchars((string)$rj['reject_reason']) ?></div><?php endforeach; ?></div><?php endif; ?>
            <?php if ($awaitingActivation): ?><div class="alert alert-success"><strong><?= count($awaitingActivation) ?> schedule(s) approved by the CEO — ready for HR activation:</strong>
                <?php foreach ($awaitingActivation as $aw): ?><div style="margin-top:4px"><a href="government_contributions.php?view=1&amp;id=<?= (int)$aw['id'] ?>"><?= htmlspecialchars($aw['schedule_name']) ?></a> (<?= htmlspecialchars(contributionDecisionText($aw)) ?>)</div><?php endforeach; ?></div><?php endif; ?>
        <?php endif; ?>
        <?php if ($schemaReady): ?>
        <div class="card">
            <div class="table-wrapper">
                <table class="schedule-table">
                    <thead><tr><th>Agency</th><th>Schedule Name</th><th>Effective From</th><th>Effective Until</th><th>Status</th><th>CEO Decision</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($schedules as $schedule): $scheduleStatus = contributionScheduleStatus($schedule); ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($schedule['agency']) ?></strong></td>
                            <td><?= htmlspecialchars($schedule['schedule_name']) ?></td>
                            <td><?= formatDate($schedule['effective_from']) ?></td>
                            <td><?= $schedule['effective_until'] ? formatDate($schedule['effective_until']) : '—' ?></td>
                            <td><span class="badge <?= $scheduleStatus['class'] ?>"><?= htmlspecialchars($scheduleStatus['label']) ?></span></td>
                            <td style="font-size:12px;color:#6B7280"><?= htmlspecialchars(contributionDecisionText($schedule)) ?><?php if (($schedule['approval_status'] ?? '') === 'rejected' && !empty($schedule['reject_reason'])): ?><div style="color:#991B1B;margin-top:2px">Reason: <?= htmlspecialchars(mb_strimwidth((string)$schedule['reject_reason'], 0, 80, '…')) ?></div><?php endif; ?></td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    <a class="btn-secondary btn-sm" href="government_contributions.php?view=1&amp;id=<?= (int)$schedule['id'] ?>"><?= ($schedule['approval_status'] ?? '') === 'rejected' ? 'View Details' : 'View' ?></a>
                                    <?php if ($canActivate && ($schedule['approval_status'] ?? '') === 'approved' && $schedule['status'] !== 'active' && $scheduleStatus['label'] !== 'Expired'): ?>
                                        <form method="post" onsubmit="return confirm('Activate this CEO-approved schedule? Any earlier active schedule will end the day before this schedule starts.');">
                                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="activate"><input type="hidden" name="schedule_id" value="<?= (int)$schedule['id'] ?>">
                                            <button type="submit" class="btn-primary btn-sm">Activate</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canActivate && $schedule['status'] === 'active'): ?>
                                        <form method="post" onsubmit="return confirm('Deactivate this schedule? Finalized payroll snapshots will remain unchanged.');">
                                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="deactivate"><input type="hidden" name="schedule_id" value="<?= (int)$schedule['id'] ?>">
                                            <button type="submit" class="btn-secondary btn-sm">Deactivate</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canManage && $schedule['status'] !== 'active' && !contributionScheduleInFinalizedPayroll($db, (int)$schedule['id'])): ?>
                                        <a class="btn-secondary btn-sm" href="government_contributions.php?edit=<?= (int)$schedule['id'] ?>">Edit</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$schedules): ?><tr><td colspan="7" style="text-align:center;color:#6B7280;padding:20px">No contribution schedules have been entered.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<dialog id="reviewDialog" style="border:0;border-radius:12px;max-width:900px;width:calc(100% - 24px);padding:0;box-shadow:0 18px 60px #0003">
    <div style="padding:20px 24px;border-bottom:1px solid #E5E7EB"><h2 style="margin:0">Review Contribution Schedule</h2></div>
    <div id="reviewContent" style="padding:20px 24px;max-height:65vh;overflow:auto"></div>
    <div style="display:flex;justify-content:flex-end;gap:10px;padding:16px 24px;border-top:1px solid #E5E7EB">
        <button type="button" class="btn-secondary" id="backToEdit">Back to Edit</button>
        <button type="button" class="btn-primary" id="confirmSave">Submit for CEO Approval</button>
    </div>
</dialog>

<script>
(() => {
    const form = document.getElementById('scheduleForm');
    if (!form) return;
    const agencySelect = document.getElementById('agency');
    const rulesContainer = document.getElementById('rulesContainer');
    const addButton = document.getElementById('addRuleButton');
    const emptyState = document.getElementById('rulesEmpty');
    const warning = document.getElementById('overlapWarning');
    const reviewDialog = document.getElementById('reviewDialog');
    const editingRules = <?= json_encode($formRules, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const money = value => value === '' || value == null ? '—' : '₱' + Number(value).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    const fields = (label, key, value, options = {}) => {
        const type = options.type || 'number';
        const step = options.step || '0.01';
        const required = options.required === false ? '' : 'required';
        const min = options.min ?? '0';
        const max = options.max == null ? '' : `max="${options.max}"`;
        const suffix = options.suffix ? `<span style="position:absolute;right:12px;top:10px;color:#6B7280">${esc(options.suffix)}</span>` : '';
        return `<div class="form-group ${options.wide ? 'wide' : ''}"><label class="form-label">${esc(label)}${options.required === false ? '' : ' *'}</label><div style="position:relative"><input class="form-input" type="${type}" name="rules[${options.index}][${key}]" value="${esc(value)}" ${type === 'number' ? `min="${min}" ${max} step="${step}"` : ''} ${required} ${options.suffix ? 'style="padding-right:35px"' : ''} placeholder="${esc(options.placeholder || '')}" data-rule-field="${key}">${suffix}</div>${options.help ? `<small class="helper">${esc(options.help)}</small>` : ''}</div>`;
    };
    const selectField = (label, key, value, options) => `<div class="form-group"><label class="form-label">${esc(label)} *</label><select class="form-select" name="rules[${options.index}][${key}]" required data-rule-field="${key}">${options.items.map(([optionValue, text]) => `<option value="${esc(optionValue)}" ${value === optionValue ? 'selected' : ''}>${esc(text)}</option>`).join('')}</select></div>`;

    function renderRow(data = {}, index) {
        const agency = agencySelect.value;
        let content = '<div class="rule-grid">';
        content += fields('Minimum Compensation', 'salary_min', data.salary_min ?? '', {index});
        content += fields('Maximum Compensation', 'salary_max', data.salary_max ?? '', {index, required:false, placeholder:'No upper limit'});
        if (agency === 'SSS') {
            content += fields('Monthly Salary Credit (MSC)', 'msc', data.msc ?? '', {index});
            content += fields('Regular SS — Employee Share', 'regular_ss_employee', data.regular_ss_employee ?? '', {index});
            content += fields('Regular SS — Employer Share', 'regular_ss_employer', data.regular_ss_employer ?? '', {index});
            content += fields('MPF — Employee Share', 'mpf_employee', data.mpf_employee ?? '0.00', {index});
            content += fields('MPF — Employer Share', 'mpf_employer', data.mpf_employer ?? '0.00', {index});
            content += fields('ECP (Employer-paid)', 'ec_employer', data.ec_employer ?? '', {index});
        } else {
            const mode = data.contribution_base_mode ?? 'salary';
            content += selectField('Contribution Basis', 'contribution_base_mode', mode, {index, items:[['salary','Salary compensation'],['salary_capped','Salary with cap'],['fixed','Fixed contribution basis']]});
            content += fields('Fixed Contribution Basis', 'contribution_base', data.contribution_base ?? '', {index, required:false});
            content += fields('Minimum Contribution Basis', 'contribution_base_floor', data.contribution_base_floor ?? '', {index, required:false});
            content += fields('Maximum Contribution Basis / Cap', 'contribution_base_cap', data.contribution_base_cap ?? '', {index, required: mode === 'salary_capped'});
            content += fields('Employee Contribution Rate', 'employee_rate', data.employee_rate ?? '0', {index, step:'0.00001', max:'100', required:false, suffix:'%', help:'Enter the official percentage, or zero when using a fixed amount.'});
            content += fields('Employer Contribution Rate', 'employer_rate', data.employer_rate ?? '0', {index, step:'0.00001', max:'100', required:false, suffix:'%', help:'Enter the official percentage, or zero when using a fixed amount.'});
            content += fields('Employee Fixed Amount', 'employee_fixed_amount', data.employee_fixed_amount ?? '0.00', {index});
            content += fields('Employer Fixed Amount', 'employer_fixed_amount', data.employer_fixed_amount ?? '0.00', {index});
        }
        content += '</div>';
        const card = document.createElement('div');
        card.className = 'rule-card';
        card.dataset.ruleIndex = String(index);
        card.innerHTML = `<div class="rule-card-head"><span>Rule ${index + 1}</span><button type="button" class="btn-secondary btn-sm remove-rule">Delete Rule</button></div>${content}`;
        const modeSelect = card.querySelector('[data-rule-field="contribution_base_mode"]');
        if (modeSelect) modeSelect.addEventListener('change', () => updateConditionalFields(card));
        card.querySelector('.remove-rule').addEventListener('click', () => {
            card.remove();
            renumberRows();
            checkRanges();
        });
        updateConditionalFields(card);
        return card;
    }

    function updateConditionalFields(card) {
        const mode = card.querySelector('[data-rule-field="contribution_base_mode"]')?.value;
        const fixed = card.querySelector('[data-rule-field="contribution_base"]');
        const cap = card.querySelector('[data-rule-field="contribution_base_cap"]');
        if (fixed) fixed.closest('.form-group').style.display = mode === 'fixed' ? '' : 'none';
        if (cap) cap.closest('.form-group').style.display = mode === 'salary_capped' ? '' : 'none';
        if (fixed && mode !== 'fixed') fixed.value = '';
        if (cap && mode !== 'salary_capped') cap.value = '';
        if (fixed) fixed.required = mode === 'fixed';
        if (cap) cap.required = mode === 'salary_capped';
    }

    function renumberRows() {
        [...rulesContainer.children].forEach((card, index) => {
            card.querySelector('.rule-card-head span').textContent = `Rule ${index + 1}`;
            card.querySelectorAll('[name]').forEach(field => {
                field.name = field.name.replace(/rules\[\d+\]/, `rules[${index}]`);
            });
        });
        emptyState.style.display = rulesContainer.children.length ? 'none' : 'block';
    }

    function checkRanges() {
        const rows = [...rulesContainer.children].map(card => ({
            card,
            min: Number(card.querySelector('[data-rule-field="salary_min"]')?.value),
            maxText: card.querySelector('[data-rule-field="salary_max"]')?.value ?? '',
        }));
        let overlap = false;
        rows.forEach((row, index) => {
            const max = row.maxText === '' ? Infinity : Number(row.maxText);
            if (row.maxText !== '' && row.min > max) overlap = true;
            rows.slice(index + 1).forEach(other => {
                const otherMax = other.maxText === '' ? Infinity : Number(other.maxText);
                if (Number.isFinite(row.min) && Number.isFinite(other.min) && row.min <= otherMax && other.min <= max) overlap = true;
            });
        });
        warning.style.display = overlap ? 'block' : 'none';
        return !overlap;
    }

    function setAgency(keepRules = false) {
        const agency = agencySelect.value;
        const oldValues = keepRules ? [...rulesContainer.children].map(card => {
            const row = {};
            card.querySelectorAll('[data-rule-field]').forEach(field => row[field.dataset.ruleField] = field.value);
            return row;
        }) : editingRules;
        rulesContainer.replaceChildren();
        if (!agency) {
            emptyState.style.display = 'block';
            addButton.style.display = 'none';
            return;
        }
        addButton.textContent = agency === 'SSS' ? '+ Add SSS Rule' : '+ Add Rule';
        addButton.style.display = 'inline-flex';
        const initialRows = oldValues.length ? oldValues : [{}];
        initialRows.forEach((row, index) => rulesContainer.appendChild(renderRow(row, index)));
        renumberRows();
        checkRanges();
    }

    addButton.addEventListener('click', () => {
        const index = rulesContainer.children.length;
        rulesContainer.appendChild(renderRow({}, index));
        renumberRows();
    });
    rulesContainer.addEventListener('input', checkRanges);
    if (!agencySelect.disabled) agencySelect.addEventListener('change', () => setAgency(false));
    setAgency();

    const reviewButton = document.getElementById('reviewButton');
    reviewButton.addEventListener('click', () => {
        const until = document.getElementById('effective_until');
        until.min = document.getElementById('effective_from').value;
        if (!form.reportValidity()) return;
        if (!checkRanges()) {
            warning.scrollIntoView({behavior:'smooth',block:'center'});
            return;
        }
        const agency = agencySelect.value;
        const rows = [...rulesContainer.children].map(card => {
            const row = {};
            card.querySelectorAll('[data-rule-field]').forEach(field => row[field.dataset.ruleField] = field.value);
            return row;
        });
        if (!rows.length) {
            alert('Add at least one contribution rule before continuing.');
            return;
        }
        const columns = agency === 'SSS'
            ? ['salary_min','salary_max','msc','regular_ss_employee','regular_ss_employer','mpf_employee','mpf_employer','ec_employer']
            : ['salary_min','salary_max','contribution_base_mode','contribution_base','contribution_base_floor','contribution_base_cap','employee_rate','employer_rate','employee_fixed_amount','employer_fixed_amount'];
        const labels = agency === 'SSS'
            ? ['Minimum','Maximum','MSC','Regular SS Employee','Regular SS Employer','MPF Employee','MPF Employer','ECP (Employer-paid)']
            : ['Minimum','Maximum','Basis','Fixed Basis','Minimum Basis','Maximum Basis','Employee Rate','Employer Rate','Employee Fixed','Employer Fixed'];
        const formatValue = (key, value) => {
            if (key === 'salary_max' && value === '') return 'No limit';
            if (key === 'contribution_base_mode') return ({salary:'Salary',salary_capped:'Salary with cap',fixed:'Fixed basis'})[value] || value;
            if (key.endsWith('_floor') || key.endsWith('_cap') || key === 'contribution_base') return value === '' ? '—' : money(value);
            if (key.endsWith('_rate')) return `${value}%`;
            return money(value);
        };
        const table = `<div class="table-wrapper"><table class="review-table"><thead><tr>${labels.map(label=>`<th>${esc(label)}</th>`).join('')}</tr></thead><tbody>${rows.map(row=>`<tr>${columns.map(key=>`<td>${esc(formatValue(key,row[key] ?? ''))}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
        document.getElementById('reviewContent').innerHTML = `
            <p><strong>Agency:</strong> ${esc(agency)}</p>
            <p><strong>Schedule:</strong> ${esc(document.getElementById('schedule_name').value)}</p>
            <p><strong>Effective:</strong> ${esc(new Date(`${document.getElementById('effective_from').value}T00:00:00`).toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'}))} ${until.value ? `to ${esc(new Date(`${until.value}T00:00:00`).toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'}))}` : 'onward'}</p>
            <p><strong>Source:</strong> ${esc(document.getElementById('source_reference').value || 'Not provided')}${document.getElementById('source_url').value ? `<br><strong>Source URL:</strong> ${esc(document.getElementById('source_url').value)}` : ''}</p>
            <h3>Contribution Rules</h3>${table}`;
        reviewDialog.showModal();
    });
    document.getElementById('backToEdit').addEventListener('click', () => reviewDialog.close());
    document.getElementById('confirmSave').addEventListener('click', () => {
        if (!form.reportValidity()) {
            reviewDialog.close();
            return;
        }
        document.getElementById('confirmSave').disabled = true;
        form.submit();
    });
    form.addEventListener('submit', event => {
        if (event.submitter?.id !== 'confirmSave') event.preventDefault();
    });
})();
</script>
