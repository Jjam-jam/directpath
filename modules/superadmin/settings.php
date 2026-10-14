<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('superadmin');

$pageTitle  = 'System Settings';
$activePage = 'settings';
$db = getDB();

function settingScalar(PDO $db, string $sql, $default = 0) {
    try { return $db->query($sql)->fetchColumn(); } catch (Throwable $e) { return $default; }
}

$databaseVersion = (string)settingScalar($db, 'SELECT VERSION()', 'unknown');
$roleCount       = (int)settingScalar($db, 'SELECT COUNT(*) FROM roles');
$activeUsers     = (int)settingScalar($db, "SELECT COUNT(*) FROM users WHERE status='active'");

// Health checks: anything red here will stop a module from working.
$agencies = ['SSS', 'PhilHealth', 'Pag-IBIG'];
$activeSchedules = [];
foreach ($agencies as $ag) {
    $q = $db->prepare("SELECT COUNT(*) FROM government_contribution_schedules WHERE agency=? AND status='active' AND effective_from <= CURDATE() AND (effective_until IS NULL OR effective_until >= CURDATE())");
    try { $q->execute([$ag]); $activeSchedules[$ag] = (int)$q->fetchColumn(); } catch (Throwable $e) { $activeSchedules[$ag] = 0; }
}
$missingAgencies = array_keys(array_filter($activeSchedules, fn($n) => $n === 0));
$pendingCeo      = (int)settingScalar($db, "SELECT COUNT(*) FROM payroll_approvals WHERE status='pending_ceo'");
$uploadDirs      = ['uploads' => __DIR__ . '/../../uploads'];
$uploadsWritable = is_dir($uploadDirs['uploads']) ? is_writable($uploadDirs['uploads']) : false;

$checks = [
    ['Database connection', true, $databaseVersion],
    ['Contribution schedules (SSS, PhilHealth, Pag-IBIG)', !$missingAgencies,
        $missingAgencies ? 'No active schedule for: ' . implode(', ', $missingAgencies) . '. Payroll cannot be generated until they are activated.' : 'All three agencies have an active schedule.'],
    ['Uploads folder writable', $uploadsWritable, $uploadsWritable ? 'Employee uploads and SOA files can be saved.' : 'The uploads folder is missing or not writable.'],
    ['Payroll awaiting CEO approval', $pendingCeo === 0, $pendingCeo === 0 ? 'No payroll is waiting.' : $pendingCeo . ' payroll period(s) waiting for the CEO.'],
];

$runtime = [
    'Application'        => SITE_NAME,
    'Site URL'           => SITE_URL,
    'Database host'      => DB_HOST,
    'Database name'      => DB_NAME,
    'Database charset'   => DB_CHARSET,
    'Database version'   => $databaseVersion,
    'PHP version'        => PHP_VERSION,
    'Time zone'          => date_default_timezone_get(),
    'Configured roles'   => $roleCount,
    'Active users'       => $activeUsers,
];
$rules = [
    'Payroll cut-off'      => '1st to 15th and 16th to end of month',
    'Salary release'       => 'Every 20th (1st cut-off) and 5th (2nd cut-off); moves to the Friday before when it falls on a weekend',
    'Loan deduction'       => 'Approved SOA loans, once a month on the 15th or end of month',
    'Payslip visibility'   => 'Employees see a payslip only after release date and CEO approval',
];

include '../../includes/header.php';
?>
<div class="page-body">
    <div class="stat-grid">
        <?php foreach ($checks as [$label, $ok, $detail]): ?>
        <div class="stat-card" style="padding:18px 20px;">
            <div class="stat-label"><?= htmlspecialchars($label) ?></div>
            <div style="margin:8px 0 6px;"><span class="badge <?= $ok ? 'badge-success' : 'badge-danger' ?>"><?= $ok ? 'OK' : 'Needs attention' ?></span></div>
            <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($detail) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($missingAgencies): ?>
    <div class="alert alert-warning">Payroll generation is blocked until each agency has an active contribution schedule.
        <a href="../admin/government_contributions.php">Open Government Contributions</a> and activate the schedules.</div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-title">Runtime Configuration</div>
                <div class="table-wrapper"><table>
                    <tbody>
                    <?php foreach ($runtime as $label => $value): ?>
                    <tr><td style="color:var(--text-muted);width:42%;"><?= htmlspecialchars($label) ?></td><td style="font-weight:500;"><?= htmlspecialchars((string)$value) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-title">Payroll Rules in Effect</div>
                <div class="table-wrapper"><table>
                    <tbody>
                    <?php foreach ($rules as $label => $value): ?>
                    <tr><td style="color:var(--text-muted);width:34%;"><?= htmlspecialchars($label) ?></td><td style="font-weight:500;"><?= htmlspecialchars($value) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
</div>

<?php
// Close wrapper divs opened by header.php
echo '</div></div>';

