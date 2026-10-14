<?php
if (!function_exists('dashboardIntro')) {
    function dashboardIntro(string $subtitle, string $actionHtml = ''): string {
        $name = htmlspecialchars((string)($_SESSION['name'] ?? 'there'));
        $hour = (int)date('G');
        $greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
        return '<div class="dash-intro"><div><h2>' . $greet . ', ' . $name . '</h2><p>' . htmlspecialchars($subtitle) . '</p></div>'
             . '<div class="dash-right">' . $actionHtml . '<div class="dash-date">' . date('l, F j, Y') . '</div></div></div>';
    }
}

// Shared Layout Header
// Include AFTER requireRole() in each module page.
// 

$user        = getCurrentUser();
$initials    = getInitials($user['name'] ?? 'User');
$today       = date('F j, Y');
$currentRole = $_SESSION['role'];
$currentRoleLabel = ucwords(str_replace('_', ' ', $currentRole));

// Base path: from modules/role/ → root
$base = '../../';

// Notification counts for all roles
$db = getDB();
$pendingLeaves   = 0;
$unreadMessages  = 0;
$pendingRequests = 0;
$leaveNotifications = [];
$contributionNotifications = [];
$generalNotifications = [];
$unreadGeneralNotifications = 0;
try {
    $notificationStmt = $db->prepare("SELECT id, message, target_url, created_at, read_at FROM notifications
        WHERE recipient_employee_id = ? OR recipient_role = ?
        ORDER BY read_at IS NULL DESC, created_at DESC LIMIT 8");
    $notificationStmt->execute([$_SESSION['employee_id'], $currentRole]);
    $generalNotifications = $notificationStmt->fetchAll();
    $unreadStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE read_at IS NULL AND (recipient_employee_id = ? OR recipient_role = ?)");
    $unreadStmt->execute([$_SESSION['employee_id'], $currentRole]);
    $unreadGeneralNotifications = (int)$unreadStmt->fetchColumn();
} catch (Throwable $e) {}
try {
    $contributionStmt = $db->prepare("SELECT id, schedule_id, message, created_at, read_at FROM government_contribution_notifications WHERE recipient_role=? ORDER BY read_at IS NULL DESC, created_at DESC LIMIT 5");
    $contributionStmt->execute([$currentRole]);
    $contributionNotifications = $contributionStmt->fetchAll();
} catch (Throwable $e) {}
$unreadContributionNotifications = count(array_filter($contributionNotifications, static fn($notification) => $notification['read_at'] === null));

// Admin/Superadmin only
if ($currentRole === 'hr_admin') {
    $stmt = $db->query("SELECT COUNT(*) FROM leave_requests WHERE status IN ('pending','documents_submitted') OR documents_flagged_at IS NOT NULL");
    $pendingLeaves = (int)$stmt->fetchColumn();

    $stmt = $db->query("SELECT COUNT(*) FROM employee_requests WHERE status = 'pending' AND request_type='coe'");
    $pendingRequests = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT id, leave_request_id, message, created_at, read_at FROM leave_notifications WHERE recipient_role='hr_admin' ORDER BY read_at IS NULL DESC, created_at DESC LIMIT 5");
    $stmt->execute();
    $leaveNotifications = $stmt->fetchAll();
    // Payroll the CEO approved that is still waiting for HR to release it (sidebar badge)
    $pendingRelease = 0;
    try { $pendingRelease = count(array_filter(
        $db->query("SELECT DISTINCT payroll_period_start s, payroll_period_end e FROM payroll WHERE status='generated'")->fetchAll(),
        static fn($pp) => count(payrollReleasableRows($db, $pp['s'], $pp['e'])) > 0)); } catch (Throwable $e) {}
} elseif ($currentRole === 'payroll_manager') {
    $stmt = $db->query("SELECT COUNT(*) FROM employee_requests WHERE status = 'pending' AND request_type='payslip'");
    $pendingRequests = (int)$stmt->fetchColumn();
} elseif ($currentRole === 'employee') {
    $stmt = $db->prepare("SELECT id, leave_request_id, message, created_at, read_at FROM leave_notifications WHERE recipient_employee_id=? ORDER BY read_at IS NULL DESC, created_at DESC LIMIT 5");
    $stmt->execute([$_SESSION['employee_id']]);
    $leaveNotifications = $stmt->fetchAll();
}
$unreadLeaveNotifications = count(array_filter($leaveNotifications, static fn($notification) => $notification['read_at'] === null));

// Build avatar HTML
$avatarHtml = $initials;
if (!empty($user['profile_photo']) && file_exists(PROFILE_PHOTO_PATH . $user['profile_photo'])) {
    $photoSrc   = $base . 'uploads/profile_photos/' . htmlspecialchars($user['profile_photo']);
    $avatarHtml = '<img src="' . $photoSrc . '" alt="Photo" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
}

// CSS file per role
$cssRole = match($currentRole) {
    'hr_admin', 'payroll_manager', 'ceo' => 'admin',
    'superadmin'                  => 'superadmin',
    default                       => 'employee',
};

$pendingLoans = 0;
if ($currentRole === 'payroll_manager') {
    $stmt = $db->query("SELECT COUNT(*) FROM loan_disclosures WHERE status IN ('for_review','needs_info')");
    $pendingLoans = (int)$stmt->fetchColumn();
}

// ── SVG Icon Helper ──────────────────────────────────────────
function svgIcon(string $name): string {
    $icons = [
        'loan' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><path d="M7 15h.01M11 15h2"/></svg>',
        'grid'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
        'clock'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        'dollar'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        'users'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'bell'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
        'bar-chart'=> '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        'ticket'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/></svg>',
        'log'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
        'user'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'logout'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
        'shield'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'menu'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>',
        'file'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
    ];
    return $icons[$name] ?? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>';
}

// Badge count helper
function badgeCount(int $n): string {
    if ($n <= 0) return '';
    return '<span style="background:#CD1E27;color:#fff;font-size:10px;padding:1px 6px;border-radius:20px;margin-left:auto;font-weight:600;">' . $n . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> — Directpath HRMS</title>
<!-- Bootstrap 5.3 - LOCAL COPY para gumana kahit walang internet -->
<link rel="stylesheet" href="<?= $base ?>assets/css/bootstrap.min.css">
<!-- Boxicons Icons - LOCAL COPY -->
<link rel="stylesheet" href="<?= $base ?>assets/css/boxicons.min.css">
<!-- Google Fonts - sisikaping kumonekta, kung hindi pwede ay may fallback na system font -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Bootstrap CSS loaded above as local file -->
<link rel="stylesheet" href="<?= $base ?>assets/css/<?= $cssRole ?>.css">
<?php if (($activePage ?? '') === 'dashboard'): ?>
<link rel="stylesheet" href="<?= $base ?>assets/css/dashboard.css">
<?php endif; ?>
<link rel="stylesheet" href="<?= $base ?>assets/css/theme.css?v=<?= @filemtime(__DIR__ . '/../assets/css/theme.css') ?>">
<script>
(function(){try{if(localStorage.getItem('dpTheme')==='dark')document.documentElement.classList.add('dark-theme');}catch(e){}})();
</script>
<link rel="stylesheet" href="<?= $base ?>assets/css/theme-toggle.css?v=<?= @filemtime(__DIR__ . '/../assets/css/theme-toggle.css') ?>">
<link rel="stylesheet" href="<?= $base ?>assets/css/a4.css?v=<?= @filemtime(__DIR__ . '/../assets/css/a4.css') ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Restore the pinned sidebar before first paint (no flash / no slide-in on page load)
(function(){var h=document.documentElement;h.classList.add('sb-noanim');
try{if(localStorage.getItem('sbPinned')==='1'&&window.innerWidth>768)h.classList.add('sb-pinned');}catch(e){}
window.addEventListener('load',function(){requestAnimationFrame(function(){requestAnimationFrame(function(){h.classList.remove('sb-noanim');});});});})();
</script>
<script defer src="<?= $base ?>assets/js/a4-fit.js"></script>
<script defer src="<?= $base ?>assets/js/theme-toggle.js?v=<?= @filemtime(__DIR__ . '/../assets/js/theme-toggle.js') ?>"></script>
<script defer src="<?= $base ?>assets/js/live-filter.js?v=<?= @filemtime(__DIR__ . '/../assets/js/live-filter.js') ?>"></script>
<script defer src="<?= $base ?>assets/js/table-search.js?v=<?= @filemtime(__DIR__ . '/../assets/js/table-search.js') ?>"></script>
<script defer src="<?= $base ?>assets/js/table-sort.js?v=<?= @filemtime(__DIR__ . '/../assets/js/table-sort.js') ?>"></script>
<?php
// Pagination is applied to every table except those in the Reports modules.
$__isReport = in_array($activePage ?? '', ['reports', 'statutory'], true) || stripos(basename($_SERVER['SCRIPT_NAME'] ?? ''), 'report') !== false;
if (!$__isReport): ?>
<script defer src="<?= $base ?>assets/js/table-paginate.js?v=<?= @filemtime(__DIR__ . '/../assets/js/table-paginate.js') ?>"></script>
<?php endif; ?>
<script src="<?= $base ?>assets/js/chart-theme.js?v=<?= @filemtime(__DIR__ . '/../assets/js/chart-theme.js') ?>"></script>
</head>

<style>

.sidebar-nav {
  overflow-y: scroll;   /* or auto */
  scrollbar-width: none; /* For Firefox */
}

.sidebar-nav::-webkit-scrollbar {
  display: none; /* For Chrome, Safari, Edge */
}


    

/* ===== Pinnable sidebar (desktop) =====
   Default  : collapsed to icons (68px). Hovering opens it and the page moves with it; leaving collapses it again.
   Pinned   : click the pin button -> stays open and the page stays pushed. Saved in localStorage. ===== */
.nav-badge { margin-left:auto; display:flex; align-items:center; }
.sb-pin { display:none; }
@media (min-width: 769px) {
    .sidebar { width:68px; transition:width .25s ease, box-shadow .25s ease; overflow-x:hidden; }
    .main-content { margin-left:68px; transition:margin-left .25s ease; }

    /* hover: sidebar opens and pushes the page with it (never covers any card or table) */
    .sidebar:hover { width:240px; box-shadow:none !important; }
    .sidebar:hover ~ .main-content { margin-left:240px; }

    /* pinned: stay open, content stays pushed */
    html.sb-pinned .sidebar { width:240px; box-shadow:none !important; }
    html.sb-pinned .main-content { margin-left:240px; }

    html.sb-noanim .sidebar, html.sb-noanim .main-content { transition:none !important; }

    /* keep icons at the same x-position in both states (no jumping) */
    .sidebar-logo { padding-left:16px; padding-right:12px; }
    .sidebar-user { padding-left:17px; padding-right:12px; }
    .nav-item { padding-left:25px; padding-right:14px; }
    .nav-label { padding-left:25px; white-space:nowrap; }
    .nav-text, .nav-badge, .logo-text-wrapper, .user-name, .user-role, .role-chip, .nav-label, .sb-pin { transition:opacity .15s ease; }
    html:not(.sb-pinned) .sidebar:not(:hover) .nav-text,
    html:not(.sb-pinned) .sidebar:not(:hover) .nav-badge,
    html:not(.sb-pinned) .sidebar:not(:hover) .logo-text-wrapper,
    html:not(.sb-pinned) .sidebar:not(:hover) .user-name,
    html:not(.sb-pinned) .sidebar:not(:hover) .user-role,
    html:not(.sb-pinned) .sidebar:not(:hover) .role-chip,
    html:not(.sb-pinned) .sidebar:not(:hover) .nav-label,
    html:not(.sb-pinned) .sidebar:not(:hover) .sb-pin { opacity:0; pointer-events:none; }

    /* pin button (top of sidebar, next to the logo) */
    .logo-text-wrapper { min-width:0; }
    .sb-pin { display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-left:auto;
              width:28px; height:28px; padding:0; border:0; border-radius:8px; background:transparent;
              color:#9CA3AF; cursor:pointer; transition:background .15s ease, color .15s ease, opacity .15s ease; }
    .sb-pin:hover { background:#F3F4F6; color:#374151; }
    .sb-pin svg { width:16px; height:16px; transform:rotate(45deg); transition:transform .2s ease; }
    html.sb-pinned .sb-pin { background:var(--red-light, #FCEAEA); color:var(--red, #CD1E27); }
    html.sb-pinned .sb-pin svg { transform:rotate(0); fill:currentColor; }
}
.nav-text { white-space:nowrap; }
</style>
<body>
<div class="app-wrapper">
<!-- ===== SIDEBAR ===== -->
<aside class="sidebar" id="sidebar">

    <!-- Logo -->
    <div class="sidebar-logo">
        <div class="logo-icon">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"
                      stroke="white" stroke-width="2" fill="none"
                      stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
    </div>
        <!-- Logo text — naka-align sa kaliwa -->
        <div class="logo-text-wrapper" style="text-align:left;">
            <div class="logo-text" style="text-align:left;">Directpath</div>
            <div class="logo-sub" style="text-align:left;">
                Employee Management <br>
                &amp; Payroll System
            </div>
        </div>
        <button type="button" class="sb-pin" id="sbPin" onclick="togglePin()" title="Pin sidebar" aria-label="Pin sidebar" aria-pressed="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24Z"/></svg>
        </button>
    </div>

    <!-- User Info -->
    <div class="sidebar-user">
        <div class="user-avatar"><?= $avatarHtml ?></div>
        <div style="flex:1;min-width:0;">
            <div class="user-name"><?= htmlspecialchars($user['name'] ?? 'User') ?></div>
            <div class="user-role"><?= htmlspecialchars($user['department'] ?? '') ?></div>
        </div>
        <div class="role-chip"><?= htmlspecialchars($currentRoleLabel) ?></div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
        <div class="nav-label">Main</div>
 
        <?php
        // Role-based menu. Each entry: [activePage key, link, icon, label, badge html]
        // Team Manager = employee menu + team items (is_team_leader checked server-side on each page too).
        $navItems = [];
        $isTL = ($currentRole === 'employee' && !empty($_SESSION['is_team_leader']));
        if ($currentRole === 'employee') {
            $navItems = [
                ['dashboard',  'dashboard.php',     'grid',      'Dashboard'],
                ['attendance', 'attendance.php',    'clock',     'Attendance'],
                ['leave',      'leave.php',         'calendar',  'My Leave'],
                ['payroll',    'payroll.php',       'dollar',    'My Payslip'],
                ['contributions', 'contributions.php', 'shield', 'Contributions & Tax'],
                ['loans',      'loan.php',          'loan',      'Loans'],
                ['performance','performance.php',   'bar-chart', 'Performance Evaluation'],
                ['requests',   'requests.php',      'file',      'My Request'],
                ['announcements','announcements.php','bell',     'Announcements'],
            ];
            if ($isTL) {
                $navItems[] = ['--', 'Team Manager'];
                $navItems[] = ['team',               'team.php',               'users',    'My Team'];
                $navItems[] = ['team_attendance',    'team_attendance.php',    'clock',    'Team Attendance'];
                $navItems[] = ['team_leave',         'team_leave.php',         'calendar', 'Team Leave Management'];
            }
        } elseif ($currentRole === 'hr_admin') {
            $navItems = [
                ['dashboard',  'dashboard.php',  'grid',     'Dashboard'],
                ['employees',  'employees.php',  'users',    'Employee Management'],
                ['schedule_manager', 'schedule_manager.php', 'user', 'Schedule Manager'],
                ['face_training', 'face_training.php', 'user', 'Face Training'],
                ['attendance', 'attendance.php', 'clock',    'Attendance Management'],
                ['leave',      'leave.php',      'calendar', 'Leave Management', badgeCount($pendingLeaves)],
                ['loan_review','loan_review.php','loan',     'Loan Documents', badgeCount($pendingLoans)],
                ['performance','performance.php','bar-chart','Performance Evaluation'],
                ['requests',   'requests.php',   'file',     'Requests', badgeCount($pendingRequests)],
                ['hr_payroll', 'hr_payroll.php', 'dollar',   'Payroll', badgeCount($pendingRelease ?? 0)],
                ['government_contributions', 'government_contributions.php', 'dollar', 'Government Contributions', badgeCount($unreadContributionNotifications)],
                ['reports',    'reports.php',    'bar-chart','Reports'],
                ['announcements','announcements.php','bell', 'Announcements'],
            ];
        } elseif ($currentRole === 'payroll_manager') {
            $navItems = [
                ['dashboard',  'dashboard.php',  'grid',     'Dashboard'],
                ['payroll',    'payroll.php',    'dollar',   'Payroll'],
                ['government_contributions', 'government_contributions.php', 'dollar', 'Government Contributions', badgeCount($unreadContributionNotifications)],
                ['statutory',  'reports_statutory.php', 'bar-chart','Statutory Reports'],
                ['requests',   'requests.php',   'file',     'Requests', badgeCount($pendingRequests)],
                ['reports',    'reports.php',    'bar-chart','Reports'],
                ['announcements','announcements.php','bell', 'Announcements'],
            ];
        } elseif ($currentRole === 'ceo') {
            $pendingCeo = 0;
            try { $pendingCeo = (int)$db->query("SELECT COUNT(*) FROM payroll_approvals WHERE status='pending_ceo'")->fetchColumn(); } catch (Throwable $e) {}
            $pendingContrib = 0;
            try { $pendingContrib = (int)$db->query("SELECT COUNT(*) FROM government_contribution_schedules WHERE approval_status='pending_ceo'")->fetchColumn(); } catch (Throwable $e) {}
            $navItems = [
                ['dashboard',        'dashboard.php',                 'grid',     'CEO Dashboard'],
                ['payroll_approval', 'payroll_approval.php',          'dollar',   'Payroll Approval', badgeCount($pendingCeo)],
                ['contribution_approval', 'contribution_approval.php', 'dollar', 'Contribution Approval', badgeCount($pendingContrib)],
                ['sum_payroll',      'summary.php?type=payroll',      'dollar',   'Payroll Summary'],
                ['sum_employees',    'summary.php?type=employees',    'users',    'Employee Overview'],
                ['sum_attendance',   'summary.php?type=attendance',   'clock',    'Attendance Summary'],
                ['sum_leave',        'summary.php?type=leave',        'calendar', 'Leave Summary'],
                ['sum_performance',  'summary.php?type=performance',  'bar-chart','Performance Summary'],
                ['reports',          'reports.php',                   'bar-chart','Reports'],
                ['statutory',        'statutory.php',                 'bar-chart','Statutory Reports'],
                ['audit',            'audit.php',                     'log',      'Audit Trail'],
                ['announcements',    'announcements.php',             'bell',     'Announcements'],
            ];
        } elseif ($currentRole === 'superadmin') {
            $navItems = [
                ['dashboard', 'dashboard.php', 'grid',   'Dashboard'],
                ['admins',    'admins.php',    'shield', 'User Management'],
                ['tickets',   'tickets.php',   'ticket', 'Tickets'],
                ['logs',      'logs.php',      'log',    'Audit Logs'],
                ['settings',  'settings.php',  'shield', 'System Settings'],
                ['government_contributions', 'government_contributions.php', 'dollar', 'Government Contributions'],
            ];
        }
        foreach ($navItems as $it):
            if ($it[0] === '--'): ?>
        <div class="nav-label" style="margin-top:8px;"><?= htmlspecialchars($it[1]) ?></div>
        <?php else: ?>
        <button class="nav-item <?= ($activePage ?? '') === $it[0] ? 'active' : '' ?>" onclick="goTo('<?= $it[1] ?>')" title="<?= htmlspecialchars($it[3]) ?>">
            <?= svgIcon($it[2]) ?><span class="nav-text"><?= htmlspecialchars($it[3]) ?></span><span class="nav-badge"><?= $it[4] ?? '' ?></span>
        </button>
        <?php endif; endforeach; ?>

    </nav>
    <!-- Logout -->
    <div class="sidebar-footer">
        <button class="nav-item <?= ($activePage ?? '') === 'profile' ? 'active' : '' ?>" onclick="goTo('profile.php')" title="My Profile">
            <?= svgIcon('user') ?><span class="nav-text">My Profile</span>
        </button>
        <button class="nav-item" onclick="confirmLogout()" style="color:rgba(255,120,120,0.85);" title="Logout">
            <?= svgIcon('logout') ?><span class="nav-text">Logout</span>
        </button>
    </div>
</aside>

<!-- Sidebar backdrop overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ===== MAIN CONTENT ===== -->
<div class="main-content">

    <!-- Topbar -->
    <div class="topbar">
        <div style="display:flex;align-items:center;gap:12px;">
            <button class="hamburger" onclick="toggleSidebar()" title="Menu">
                <?= svgIcon('menu') ?>
            </button>
            <div>
                <div class="topbar-title"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></div>
                <div class="topbar-date"><?= $today ?></div>
            </div>
        </div>
        <button type="button" class="theme-toggle" id="themeToggle" aria-label="Switch to dark theme" title="Switch to dark theme" aria-pressed="false">
            <svg class="theme-icon-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
            <svg class="theme-icon-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.2 15.4A8.5 8.5 0 0 1 8.6 3.8 8.6 8.6 0 1 0 20.2 15.4Z"/></svg>
        </button>
        <div class="topbar-right" style="display:flex;align-items:center;gap:12px;">
            <?php if ($generalNotifications || $currentRole === 'payroll_manager'): ?>
            <details style="position:relative;">
                <summary style="list-style:none;cursor:pointer;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;color:#374151;">
                    <?= $currentRole === 'payroll_manager' ? 'Notifications' : 'Updates' ?><?= $unreadGeneralNotifications ? ' (' . $unreadGeneralNotifications . ')' : '' ?>
                </summary>
                <div style="position:absolute;right:0;top:42px;z-index:1000;width:min(360px,85vw);background:#fff;border:1px solid #E5E7EB;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:70vh;overflow:auto;">
                    <?php foreach ($generalNotifications as $notification): ?>
                    <a href="<?= $base ?>api/notification.php?id=<?= (int)$notification['id'] ?>" style="display:block;padding:12px;border-bottom:1px solid #F3F4F6;color:#374151;text-decoration:none;font-size:12px;<?= $notification['read_at'] === null ? 'font-weight:600;' : '' ?>">
                        <?= htmlspecialchars($notification['message']) ?><br><span style="color:#9CA3AF;font-size:10px;"><?= htmlspecialchars($notification['created_at']) ?></span>
                    </a>
                    <?php endforeach; ?>
                    <?php if (!$generalNotifications): ?>
                    <div style="padding:14px;color:#6B7280;font-size:12px;">No notifications yet.</div>
                    <?php endif; ?>
                </div>
            </details>
            <?php endif; ?>
            <?php if ($leaveNotifications): ?>
            <details style="position:relative;">
                <summary style="list-style:none;cursor:pointer;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;color:#374151;">
                    Notifications<?= $unreadLeaveNotifications ? ' (' . $unreadLeaveNotifications . ')' : '' ?>
                </summary>
                <div style="position:absolute;right:0;top:42px;z-index:1000;width:min(340px,85vw);background:#fff;border:1px solid #E5E7EB;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);">
                    <?php foreach ($leaveNotifications as $notification): ?>
                    <a href="<?= $base ?>api/leave_notification.php?id=<?= (int)$notification['id'] ?>"
                       style="display:block;padding:12px;border-bottom:1px solid #F3F4F6;color:#374151;text-decoration:none;font-size:12px;<?= $notification['read_at'] === null ? 'font-weight:600;' : '' ?>">
                        <?= htmlspecialchars($notification['message']) ?><br>
                        <span style="color:#9CA3AF;font-size:10px;"><?= htmlspecialchars($notification['created_at']) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
            <?php if ($contributionNotifications): ?>
            <details style="position:relative;">
                <summary style="list-style:none;cursor:pointer;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;color:#374151;">
                    Contribution Updates<?= $unreadContributionNotifications ? ' (' . $unreadContributionNotifications . ')' : '' ?>
                </summary>
                <div style="position:absolute;right:0;top:42px;z-index:1000;width:min(360px,85vw);background:#fff;border:1px solid #E5E7EB;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);">
                    <?php foreach ($contributionNotifications as $notification):
                        $notificationHref = $currentRole === 'ceo'
                            ? $base . 'modules/ceo/contribution_approval.php?notification_id=' . (int)$notification['id']
                            : $base . 'modules/admin/government_contributions.php?notification_id=' . (int)$notification['id'] . '&view=1&id=' . (int)$notification['schedule_id'];
                    ?>
                    <a href="<?= htmlspecialchars($notificationHref, ENT_QUOTES, 'UTF-8') ?>" style="display:block;padding:12px;border-bottom:1px solid #F3F4F6;color:#374151;text-decoration:none;font-size:12px;<?= $notification['read_at'] === null ? 'font-weight:600;' : '' ?>">
                        <?= htmlspecialchars($notification['message']) ?><br><span style="color:#9CA3AF;font-size:10px;"><?= htmlspecialchars($notification['created_at']) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
            <div style="font-size:12px;color:#6B7280;text-align:right;display:none;display:block;">
                <span style="font-weight:600;font-size:13px;"><?= htmlspecialchars($user['name'] ?? '') ?></span><br>
                <span style="font-size:11px;"><?= htmlspecialchars($user['position'] ?? '') ?></span>
            </div>
        </div>
    </div>

<!-- Page body starts here — each module injects content below -->

<script>
// ── Navigate within the same role folder ──
function goTo(page) {
    window.location.href = page;
}

// ── Pin / unpin the sidebar (desktop) ──
function setPinned(pinned) {
    document.documentElement.classList.toggle('sb-pinned', pinned);
    var btn = document.getElementById('sbPin');
    if (btn) {
        btn.setAttribute('aria-pressed', pinned ? 'true' : 'false');
        var label = pinned ? 'Unpin sidebar' : 'Pin sidebar';
        btn.title = label;
        btn.setAttribute('aria-label', label);
    }
    try { localStorage.setItem('sbPinned', pinned ? '1' : '0'); } catch (e) {}
}
function togglePin() {
    setPinned(!document.documentElement.classList.contains('sb-pinned'));
}
// sync the button with the state restored in <head>
setPinned(document.documentElement.classList.contains('sb-pinned'));

// ── Toggle sidebar (mobile slide-in; on desktop the menu button toggles the pin) ──
function toggleSidebar() {
    if (window.innerWidth > 768) { togglePin(); return; }
    var sidebar  = document.getElementById('sidebar');
    var overlay  = document.getElementById('sidebarOverlay');
    var isOpen   = sidebar.classList.contains('open');
    if (isOpen) {
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('show');
    } else {
        sidebar.classList.add('open');
        if (overlay) overlay.classList.add('show');
    }
}

// ── Close sidebar (used by overlay click) ──
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    var overlay = document.getElementById('sidebarOverlay');
    if (overlay) overlay.classList.remove('show');
}

// ── Logout with confirm prompt ──
function confirmLogout() {
    if (confirm('Are you sure you want to log out?')) {
        window.location.href = '<?= $base ?>logout.php';
    }
}

// ── Escape key closes sidebar ──
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeSidebar();
});

// ── Modal open/close helpers (available to all pages) ──
function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('show');
}

function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('show');
}

// ── Close modal on overlay background click ──
document.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('show');
    }
});
</script>
