<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/attendance_rules.php';
requireRole('employee');

$pageTitle  = 'Attendance';
$activePage = 'attendance';
$db         = getDB();
$empId      = $_SESSION['employee_id'];

$filterMonth = sanitize($_GET['month'] ?? date('Y-m'));

$stmt = $db->prepare("SELECT * FROM attendance WHERE employee_id=? AND DATE_FORMAT(date,'%Y-%m')=? ORDER BY date DESC");
$stmt->execute([$empId, $filterMonth]);
$records = $stmt->fetchAll();
$demoAttendanceCount = count(array_filter(
    $records,
    static fn(array $record): bool => str_starts_with((string)($record['notes'] ?? ''), 'DEMO_SEED:AUG_OCT_2026:')
));

$present  = count(array_filter($records, fn($r) => in_array($r['status'], ['present','late'], true)));
$absent   = count(array_filter($records, fn($r) => $r['status'] === 'absent'));
$onLeave  = count(array_filter($records, fn($r) => $r['status'] === 'on-leave'));
$totalHrs = array_sum(array_column($records, 'hours_worked'));
$totalLate  = (int)array_sum(array_column($records, 'late_minutes'));
$totalUnder = (int)array_sum(array_column($records, 'undertime_mins'));

// Today's record
$stmt = $db->prepare("SELECT * FROM attendance WHERE employee_id=? AND date=CURDATE()");
$stmt->execute([$empId]);
$todayAtt = $stmt->fetch() ?: null;
if ($todayAtt) {
    foreach (['time_in','time_out'] as $col) {
        if (isset($todayAtt[$col]) && $todayAtt[$col] === '') {
            $todayAtt[$col] = null;
        }
    }
}

// ── Read work_mode from DB ─────────────────────────────────────
$stmt = $db->prepare("SELECT work_mode FROM users WHERE employee_id = ?");
$stmt->execute([$empId]);
$userRow     = $stmt->fetch();
$workMode    = $userRow['work_mode'] ?? 'wfh';
$isOnsiteDay = ($workMode === 'onsite');

// ── Business rules (shared with the APIs: includes/attendance_rules.php) ──
// Time-in: weekdays only, not on holidays, from 6:30 AM until 5:00 PM.
$holidayToday   = attGetHoliday($db, date('Y-m-d'));
$isHoliday      = (bool)$holidayToday;
$isWeekend      = !in_array((int)date('N'), ATT_WORK_DAYS, true);
$timeInBlocked  = attTimeInBlockReason($db);          // string|null
$upcomingHolidays = attUpcomingHolidays($db, 4);

// ── Button state ───────────────────────────────────────────────
$hasTimedIn  = $todayAtt && !empty($todayAtt['time_in']);
$hasTimedOut = $todayAtt && !empty($todayAtt['time_out']);

$disableTimeIn  = $isOnsiteDay || $hasTimedIn || $timeInBlocked !== null;
// Time-out is only allowed after a time-in. The Time-Out button stays clickable without one
// so the employee gets the clear "not timed in" error instead of a silently dead button.
$disableTimeOut = $isOnsiteDay || $hasTimedOut;
$workBlocked    = $timeInBlocked !== null && !$hasTimedIn;

include '../../includes/header.php';
?>


<style>
.att-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    padding: 14px;
    border-radius: 10px;
    border: 2px solid var(--border);
    background: var(--card-bg);
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-dark);
    text-align: center;
    transition: background .12s, border-color .12s, opacity .12s, transform .1s;
}
.att-btn:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.att-btn:disabled {
    opacity: .35;
    cursor: not-allowed;
    transform: none;
}
.att-btn.btn-timein {
    background: #16A34A;
    border-color: #16A34A;
    color: #fff;
}
.att-btn.btn-timein:hover:not(:disabled) {
    background: #15803D;
    border-color: #15803D;
}
.att-btn.btn-timeout {
    background: #CD1E27;
    border-color: #CD1E27;
    color: #fff;
}
.att-btn.btn-timeout:hover:not(:disabled) {
    background: #b91c1c;
    border-color: #b91c1c;
}
.att-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    flex-shrink: 0;
}
.time-row {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--text-muted);
}
.time-row strong {
    color: var(--text-dark);
    font-weight: 500;
}
.face-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.75);
    z-index: 99999;
    align-items: center;
    justify-content: center;
}
.face-modal.show {
    display: flex;
}
.face-modal-card {
    width: min(92vw, 680px);
    max-height: 90vh;
    overflow-y: auto;
    padding: 24px;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.4);
    text-align: center;
}
.face-camera-video {
    width: 100%;
    height: min(58vh, 420px);
    display: block;
    object-fit: cover;
    border-radius: 12px;
}
.face-oval-guide {
    width: 220px;
    height: 280px;
    border: 2px dashed rgba(255,255,255,.6);
    border-radius: 50%;
    box-shadow: 0 0 0 2000px rgba(0,0,0,0.25);
}
.face-scan-frame { position: relative; }
.face-scan-frame::after {
    content: ''; display: none; position: absolute; z-index: 2;
    left: 8%; right: 8%; top: 5%; height: 2px;
    background: #68e6a7; box-shadow: 0 0 12px 3px rgba(44, 210, 132, .7);
    pointer-events: none;
}
.face-scan-frame.is-scanning::after {
    display: block; animation: attendance-face-scan 1.2s ease-in-out infinite alternate;
}
@keyframes attendance-face-scan { from { top: 5%; } to { top: 95%; } }
@media (prefers-reduced-motion: reduce) {
    .face-scan-frame.is-scanning::after { animation: none; top: 50%; }
}
@media (max-width: 576px) {
    .att-grid { grid-template-columns: 1fr !important; }
    .face-modal-card { width: 94vw; padding: 16px; }
    .face-camera-video { height: min(48vh, 360px); }
    .face-oval-guide { width: 180px; height: 230px; }
    .att-status-panel {
        border-right: none !important;
        border-bottom: 1px solid var(--border) !important;
    }
}
</style>

<div class="page-body">

<!-- ── Attendance Card ── -->
<div class="card" style="padding:0;overflow:hidden;margin-bottom:20px;">

    <!-- Card Header -->
    <div style="padding:14px 20px;border-bottom:1px solid var(--border);
                display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:4px;height:20px;background:var(--red);border-radius:2px;"></div>
            <div>
                <div style="font-size:14px;font-weight:600;color:var(--text-dark);">Attendance</div>
                <div style="font-size:12px;color:var(--text-muted);"><?= date('l, F j, Y') ?></div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <?php if ($isOnsiteDay): ?>
            <div style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;
                        background:#FEF3C7;border:1px solid #FDE68A;border-radius:20px;
                        font-size:11px;font-weight:600;color:#92400E;">
                <i class="bx bx-building-house" style="font-size:13px;"></i>
                Onsite
            </div>
            <?php else: ?>
            <div style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;
                        background:#DCFCE7;border:1px solid #BBF7D0;border-radius:20px;
                        font-size:11px;font-weight:600;color:#166534;">
                <i class="bx bx-home-smile" style="font-size:13px;"></i>
                Work From Home
            </div>
            <?php endif; ?>
            <div id="liveClock" style="font-size:13px;font-weight:500;
                                        color:var(--text-muted);font-variant-numeric:tabular-nums;">
            </div>
        </div>
    </div>

    <!-- Onsite Notice Banner -->
    <?php if ($isOnsiteDay): ?>
    <div style="padding:10px 20px;background:#FFFBEB;border-bottom:1px solid #FDE68A;
                display:flex;align-items:center;gap:8px;font-size:12px;color:#92400E;">
        <i class="bx bx-info-circle" style="font-size:15px;"></i>
        <span>You are set to <strong>Onsite</strong>. Your attendance is recorded by the admin on-site.</span>
    </div>
    <?php endif; ?>

    <?php if ($isHoliday): ?>
    <div style="padding:10px 20px;background:#FEF2F2;border-bottom:1px solid #FECACA;display:flex;align-items:center;gap:8px;font-size:12px;color:#991B1B;">
        <i class="bx bx-party" style="font-size:15px;"></i>
        <span>Holiday today: <strong><?= htmlspecialchars($holidayToday['name']) ?></strong>. Time-in is disabled.</span>
    </div>
    <?php endif; ?>

    <!-- Body -->
    <div class="att-grid" style="display:grid;grid-template-columns:1fr 1fr;">

        <!-- Status Panel -->
        <div class="att-status-panel" style="padding:20px;border-right:1px solid var(--border);">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);
                        text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;">
                Today's Status
            </div>

            <?php if ($isOnsiteDay && !$todayAtt): ?>
                <div style="display:flex;flex-direction:column;align-items:center;
                            justify-content:center;min-height:130px;gap:8px;color:var(--text-muted);">
                    <i class="bx bx-building-house" style="font-size:36px;opacity:.3;"></i>
                    <span style="font-size:12px;text-align:center;line-height:1.6;">
                        Onsite — waiting for<br>admin to record attendance
                    </span>
                </div>

            <?php elseif ($workBlocked && !$todayAtt): ?>
                <div style="display:flex;flex-direction:column;align-items:center;
                            justify-content:center;min-height:130px;gap:8px;color:var(--text-muted);">
                    <i class="bx bx-calendar-x" style="font-size:36px;opacity:.3;"></i>
                    <span style="font-size:12px;text-align:center;line-height:1.5;">
                        <?= htmlspecialchars($timeInBlocked) ?>
                    </span>
                </div>

            <?php elseif ($todayAtt): ?>
                <span class="badge <?= statusBadge($todayAtt['status']) ?>"
                      style="font-size:12px;padding:5px 12px;border-radius:20px;
                             margin-bottom:14px;display:inline-block;">
                    <?= ucfirst($todayAtt['status']) ?>
                </span>

                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php if (!empty($todayAtt['time_in'])): ?>
                    <div class="time-row">
                        <span class="att-dot" style="background:#16A34A;"></span>
                        Time In &nbsp;<strong><?= formatTimeFull($todayAtt['time_in']) ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($todayAtt['time_out'])): ?>
                    <div class="time-row">
                        <span class="att-dot" style="background:#CD1E27;"></span>
                        Time Out &nbsp;<strong><?= formatTimeFull($todayAtt['time_out']) ?></strong>
                    </div>
                    <div style="display:inline-flex;align-items:center;gap:5px;margin-top:4px;
                                padding:5px 10px;background:var(--body-bg);border-radius:6px;
                                font-size:12px;color:var(--text-muted);">
                        <i class="bx bx-time" style="font-size:14px;"></i>
                        <strong style="color:var(--text-dark);">
                            <?= $todayAtt['hours_worked'] ?> hrs
                        </strong>&nbsp;worked
                    </div>
                    <?php endif; ?>
                </div>

                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px;">
                <?php if (!empty($todayAtt['late_minutes']) && $todayAtt['late_minutes'] > 0): ?>
                <div style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:6px;font-size:11px;color:#92400E;">
                    <i class="bx bx-error-circle" style="font-size:13px;"></i> Late: <?= (int)$todayAtt['late_minutes'] ?> min
                </div>
                <?php endif; ?>
                <?php if (!empty($todayAtt['undertime_mins']) && $todayAtt['undertime_mins'] > 0): ?>
                <div style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;font-size:11px;color:#1E40AF;">
                    <i class="bx bx-log-out-circle" style="font-size:13px;"></i> Undertime: <?= (int)$todayAtt['undertime_mins'] ?> min
                </div>
                <?php endif; ?>
                </div>

            <?php else: ?>
                <div style="display:flex;flex-direction:column;align-items:center;
                            justify-content:center;min-height:130px;gap:8px;color:var(--text-muted);">
                    <i class="bx bx-time-five" style="font-size:36px;opacity:.3;"></i>
                    <span style="font-size:12px;">No record yet today</span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Actions Panel -->
        <div style="padding:20px;display:flex;flex-direction:column;
                    gap:12px;justify-content:center;">

            <?php if ($isOnsiteDay): ?>
            <!-- Onsite: locked -->
            <div style="display:flex;flex-direction:column;align-items:center;
                        justify-content:center;height:100%;gap:10px;
                        color:var(--text-muted);text-align:center;">
                <i class="bx bx-lock" style="font-size:36px;opacity:.3;"></i>
                <span style="font-size:12px;line-height:1.6;">
                    Face recognition is disabled<br>on onsite days.
                </span>
            </div>

            <?php else: ?>
            <!-- WFH: Face Recognition only -->

            <div style="font-size:11px;font-weight:700;color:var(--text-muted);
                        text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">
                Face Recognition
            </div>

            <!-- Time In button -->
            <button class="att-btn btn-timein"
                    onclick="startFaceRecog('time_in')"
                    <?= $disableTimeIn ? 'disabled' : '' ?>>
                <i class="bx bx-scan" style="font-size:18px;"></i>
                <?php if ($hasTimedIn): ?>
                    Timed In — <?= formatTimeFull($todayAtt['time_in']) ?>
                <?php else: ?>
                    Face Time In
                <?php endif; ?>
            </button>

            <!-- Time Out button -->
            <button class="att-btn btn-timeout"
                    onclick="startFaceRecog('time_out')"
                    <?= $disableTimeOut ? 'disabled' : '' ?>>
                <i class="bx bx-scan" style="font-size:18px;"></i>
                <?php if ($hasTimedOut): ?>
                    Timed Out — <?= formatTimeFull($todayAtt['time_out']) ?>
                <?php else: ?>
                    Face Time Out
                <?php endif; ?>
            </button>

            <?php if (!$hasTimedIn && !$workBlocked): ?>
            <p style="font-size:11px;color:var(--text-muted);text-align:center;margin:0;">
                <i class="bx bx-shield-quarter"></i>
                Your face scan identifies and verifies your account automatically.
            </p>
            <?php elseif ($workBlocked && !$todayAtt): ?>
            <p style="font-size:11px;color:#92400E;text-align:center;margin:0;"><?= htmlspecialchars($timeInBlocked) ?></p>
            <?php endif; ?>
            <p style="font-size:10.5px;color:var(--text-muted);text-align:center;margin:0;">
                Time-in window: <?= attFormat12h(ATT_TIMEIN_OPEN) ?> – <?= attFormat12h(ATT_TIMEIN_CLOSE) ?> · On time until 8:00 AM
            </p>

            <?php endif; // end WFH ?>

        </div>
    </div>

    <!-- Message Bar -->
    <div id="attMsg" style="margin:0 20px 16px;padding:10px 14px;border-radius:8px;
                             font-size:12px;display:none;"></div>
</div>

<!-- ── Monthly Stats ── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon green"><i class="bx bx-check-circle"></i></div>
            <div>
                <div class="stat-value"><?= $present ?></div>
                <div class="stat-label">Present</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon red"><i class="bx bx-x-circle"></i></div>
            <div>
                <div class="stat-value"><?= $absent ?></div>
                <div class="stat-label">Absent</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon amber"><i class="bx bx-file"></i></div>
            <div>
                <div class="stat-value"><?= $onLeave ?></div>
                <div class="stat-label">On Leave</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bx bx-time"></i></div>
            <div>
                <div class="stat-value"><?= number_format($totalHrs, 1) ?></div>
                <div class="stat-label">Total Hours</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon amber"><i class="bx bx-error-circle"></i></div>
            <div>
                <div class="stat-value"><?= $totalLate ?><span style="font-size:12px;font-weight:500;"> min</span></div>
                <div class="stat-label">Late (arrived after 8:00)</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bx bx-log-out-circle"></i></div>
            <div>
                <div class="stat-value"><?= $totalUnder ?><span style="font-size:12px;font-weight:500;"> min</span></div>
                <div class="stat-label">Undertime (left before 5:00)</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Filter ── -->
<div class="card">
    <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="month" name="month" class="form-input" style="width:auto;"
               value="<?= $filterMonth ?>">
        <button type="submit" class="btn-primary">
            <i class="bx bx-filter-alt"></i> Filter
        </button>
        <a href="attendance.php" class="btn-secondary">
            <i class="bx bx-reset"></i> Reset
        </a>
    </form>
</div>

<!-- ── Attendance Table ── -->
<div class="card">
    <div class="card-title">
        Attendance — <?= date('F Y', strtotime($filterMonth . '-01')) ?>
    </div>
    <?php if ($demoAttendanceCount > 0): ?>
    <div style="background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;border-radius:7px;padding:9px 12px;margin-bottom:12px;font-size:12px;">
        This view includes <?= $demoAttendanceCount ?> simulated attendance record(s). Record history retains their source classification.
    </div>
    <?php endif; ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Hours</th>
                    <th>Late (min)</th>
                    <th>Undertime (min)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $a): ?>
                <tr>
                    <td><?= formatDateDay($a['date']) ?></td>
                    <td><?= !empty($a['time_in'])  ? formatTimeFull($a['time_in'])  : '—' ?></td>
                    <td><?= !empty($a['time_out']) ? formatTimeFull($a['time_out']) : '—' ?></td>
                    <td><?= $a['hours_worked'] ?> hrs</td>
                    <td>
                        <?= (!empty($a['late_minutes']) && $a['late_minutes'] > 0)
                            ? '<span style="color:#D97706;font-weight:500;">'
                              . $a['late_minutes'] . '</span>'
                            : '—'
                        ?>
                    </td>
                    <td>
                        <?= (!empty($a['undertime_mins']) && $a['undertime_mins'] > 0)
                            ? '<span style="color:#2563EB;font-weight:500;">' . (int)$a['undertime_mins'] . '</span>'
                            : '—' ?>
                    </td>
                    <td>
                        <span class="badge <?= statusBadge($a['status']) ?>">
                            <?= ucfirst($a['status']) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($records)): ?>
                <tr>
                    <td colspan="7" style="text-align:center;color:var(--text-muted);padding:32px;">
                        <i class="bx bx-calendar-x"
                           style="font-size:24px;display:block;margin-bottom:6px;opacity:.4;"></i>
                        No records for this month.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($upcomingHolidays): ?>
<div class="card">
    <div class="card-title">Upcoming holidays <span style="font-weight:400;font-size:11px;color:var(--text-muted);">— time-in is disabled on these days</span></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <?php foreach ($upcomingHolidays as $h): ?>
        <div style="border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-size:12px;">
            <strong><?= htmlspecialchars($h['name']) ?></strong><br>
            <span style="color:var(--text-muted);"><?= date('D, M j, Y', strtotime($h['holiday_date'])) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

</div><!-- /.page-body -->

<!-- ══════════════════════════════════════════
     Face Recognition Modal
══════════════════════════════════════════ -->
<div id="faceModal" class="face-modal">
    <div class="face-modal-card">

        <div style="margin-bottom:16px;">
            <h5 id="faceModalTitle" style="margin:0 0 4px;font-weight:700;font-size:16px;">
                Face Verification
            </h5>
            <p style="font-size:12px;color:#6B7280;margin:0;">
                WFH attendance — your face scan identifies your account automatically.
            </p>
        </div>

        <!-- Camera -->
        <div id="faceScanFrame" class="face-scan-frame" style="position:relative;border-radius:12px;overflow:hidden;
                    background:#111;margin-bottom:16px;">
                 <video id="faceVideo" class="face-camera-video" autoplay playsinline muted
                     style="transform:scaleX(-1);"></video>
            <canvas id="faceCanvas" style="display:none;"></canvas>
            <!-- Oval guide -->
            <div style="position:absolute;inset:0;display:flex;align-items:center;
                        justify-content:center;pointer-events:none;">
                <div class="face-oval-guide"></div>
            </div>
        </div>

        <!-- Status -->
        <div id="faceStatus"
             style="font-size:13px;color:#6B7280;margin-bottom:16px;min-height:18px;">
            Position your face inside the oval.
        </div>

        <!-- Buttons -->
        <div style="display:flex;gap:10px;justify-content:center;">
            <button onclick="closeFaceModal()"
                    style="flex:1;padding:10px;border:1px solid #D1D5DB;border-radius:8px;
                           background:#fff;cursor:pointer;font-size:14px;font-family:'Poppins',sans-serif;">
                Cancel
            </button>
            <button id="verifyBtn" onclick="captureAndVerify()"
                    style="flex:1;padding:10px;border:none;border-radius:8px;
                           background:#CD1E27;color:#fff;cursor:pointer;
                           font-size:14px;font-weight:600;font-family:'Poppins',sans-serif;">
                Verify &amp; Record
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     JavaScript
══════════════════════════════════════════ -->
<script>
/* ── Live Clock ─────────────────────────────────────────────── */
(function() {
    function tick() {
        var el = document.getElementById('liveClock');
        if (el) el.textContent = new Date().toLocaleTimeString('en-US', {
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        });
    }
    tick();
    setInterval(tick, 1000);
})();

/* ── WFH verification: authenticated session → face scan ─────── */
var faceAction = null;
var faceStream = null;
var wfhToken   = null;
var wfhGeo     = null;

function startFaceRecog(action) {
    faceAction = action;
    wfhToken   = null;
    wfhGeo     = null;
    if (faceStream) { faceStream.getTracks().forEach(function (t) { t.stop(); }); faceStream = null; }

    document.getElementById('faceModalTitle').textContent = action === 'time_in' ? 'Face Time In' : 'Face Time Out';
    var btn = document.getElementById('verifyBtn');
    btn.disabled = true;
    btn.textContent = 'Verify & Record';
    setFaceStatus('Starting verification…', 'info');
    document.getElementById('faceModal').classList.add('show');

    // 1) ask the server for a one-time verification session (also runs the time/holiday rules)
    fetch('../../api/face_verify.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'challenge', 'for': action })
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (!d.success) {
            setFaceStatus('' + d.message, 'error');
            showMsg(d.message, 'error');
            btn.textContent = 'Not allowed';
            return;
        }
        wfhToken = d.token;
        // 2) optional location (recorded for HR review; never blocks the punch)
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (pos) {
                wfhGeo = { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy };
            }, function () {}, { timeout: 6000, maximumAge: 60000 });
        }
        // 3) camera
        return navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }
        }).then(function (stream) {
            faceStream = stream;
            document.getElementById('faceVideo').srcObject = stream;
            btn.disabled = false;
            setFaceStatus('Face the camera, then click Verify & Record.', 'info');
        });
    })
    .catch(function (err) { setFaceStatus('Camera / server error: ' + err.message, 'error'); });
}

function closeFaceModal() {
    if (faceStream) {
        faceStream.getTracks().forEach(function (t) { t.stop(); });
        faceStream = null;
    }
    var video = document.getElementById('faceVideo');
    if (video) video.srcObject = null;
    wfhToken = null;
    document.getElementById('faceModal').classList.remove('show');
    document.getElementById('faceScanFrame').classList.remove('is-scanning');
    setFaceStatus('Position your face inside the oval.', 'info');
    document.getElementById('verifyBtn').disabled    = false;
    document.getElementById('verifyBtn').textContent = 'Verify & Record';
}

function captureAndVerify() {
    var video     = document.getElementById('faceVideo');
    var canvas    = document.getElementById('faceCanvas');
    var btn       = document.getElementById('verifyBtn');
    var scanFrame = document.getElementById('faceScanFrame');
    if (!wfhToken) { setFaceStatus('Verification session is not active. Close and try again.', 'error'); return; }
    if (!faceStream || !video.videoWidth) { setFaceStatus('Camera not ready yet. Please wait.', 'error'); return; }

    canvas.width  = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    var imageData = canvas.toDataURL('image/jpeg', 0.85);

    btn.disabled    = true;
    btn.textContent = 'Scanning…';
    scanFrame.classList.add('is-scanning');
    setFaceStatus('Verifying…', 'info');

    var body = { action: faceAction, image: imageData, token: wfhToken };
    if (wfhGeo) { body.lat = wfhGeo.lat; body.lng = wfhGeo.lng; body.accuracy = wfhGeo.accuracy; }
    wfhToken = null; // single use — a retry needs a fresh session

    fetch('../../api/face_verify.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    })
    .then(function (r) { return r.text(); })
    .then(function (text) {
        var data;
        try   { data = JSON.parse(text); }
        catch (e) { throw new Error('Server error: ' + text.substring(0, 200)); }

        scanFrame.classList.remove('is-scanning');
        if (data.success) {
            setFaceStatus('' + data.message, 'success');
            showMsg(data.message, 'success');
            setTimeout(function () { closeFaceModal(); location.reload(); }, 900);
        } else {
            setFaceStatus('' + (data.message || 'Verification failed.'), 'error');
            // the session was consumed — start a new one so "Try again" works
            btn.textContent = 'Try again';
            btn.disabled = false;
            btn.onclick = function () { btn.onclick = captureAndVerify; startFaceRecog(faceAction); };
        }
    })
    .catch(function (err) {
        scanFrame.classList.remove('is-scanning');
        setFaceStatus('' + err.message, 'error');
        btn.textContent = 'Try again';
        btn.disabled = false;
        btn.onclick = function () { btn.onclick = captureAndVerify; startFaceRecog(faceAction); };
    });
}

function setFaceStatus(msg, type) {
    var el = document.getElementById('faceStatus');
    if (!el) return;
    var colors = { success: '#166534', error: '#991B1B', info: '#6B7280' };
    el.textContent = msg;
    el.style.color = colors[type] || '#6B7280';
}

/* ── Message bar ────────────────────────────────────────────── */
function showMsg(msg, type) {
    var el = document.getElementById('attMsg');
    if (!el) return;
    var styles = {
        success: { bg:'#F0FDF4', color:'#166534', border:'1px solid #BBF7D0' },
        error:   { bg:'#FEE2E2', color:'#991B1B', border:'1px solid #FECACA' },
        info:    { bg:'#EFF6FF', color:'#1D4ED8', border:'1px solid #BFDBFE' }
    };
    var s = styles[type] || styles.info;
    el.style.background = s.bg;
    el.style.color      = s.color;
    el.style.border     = s.border;
    el.textContent      = msg;
    el.style.display    = 'block';
}

/* Close modal on backdrop click */
document.getElementById('faceModal').addEventListener('click', function(e) {
    if (e.target === this) closeFaceModal();
});
</script>