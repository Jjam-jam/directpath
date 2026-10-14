<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('superadmin');

$pageTitle  = 'Audit Logs';
$activePage = 'logs';
$db = getDB();

$search = sanitize($_GET['search'] ?? '');
$module = sanitize($_GET['module'] ?? '');
$date   = sanitize($_GET['date'] ?? '');

$where  = "WHERE 1=1";
$params = [];
$where .= searchClause(["user_id", "user_name", "action", "module", "details", "ip_address", "CAST(created_at AS CHAR)"], $search, $params);
if ($module) { $where .= " AND module=?"; $params[] = $module; }
if ($date)   { $where .= " AND DATE(created_at)=?"; $params[] = $date; }

$stmt = $db->prepare("SELECT * FROM audit_logs $where ORDER BY created_at DESC LIMIT 200");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$modules = $db->query("SELECT DISTINCT module FROM audit_logs ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);

include '../../includes/header.php';
?>

<div class="page-body">

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <form method="GET" class="filter-bar" style="margin:0;">
            <input type="text" name="search" class="search-input" placeholder="Search anything: user, action, module, details..." value="<?= htmlspecialchars($search) ?>">
            <select name="module" class="form-select" style="width:auto;">
                <option value="">All Modules</option>
                <?php foreach ($modules as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>" <?= $module===$m?'selected':'' ?>><?= htmlspecialchars($m) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date" class="form-input" style="width:auto;" value="<?= $date ?>">
            <button type="submit" class="btn-primary">Filter</button>
            <a href="logs.php" class="btn-secondary">Reset</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-title">Audit Logs (<?= count($logs) ?>)</div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>User</th><th>Action</th><th>Module</th><th>Details</th><th>IP Address</th><th>Date & Time</th></tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="font-weight:500; font-size:13px;"><?= htmlspecialchars($log['user_name']) ?></td>
                    <td style="font-size:13px;"><?= htmlspecialchars($log['action']) ?></td>
                    <td><span class="badge badge-gray"><?= htmlspecialchars($log['module']) ?></span></td>
                    <td style="font-size:12px; color:#6B7280; max-width:200px;"><?= htmlspecialchars($log['details'] ?? '—') ?></td>
                    <td style="font-size:11px; font-family:monospace; color:#9CA3AF;"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                    <td style="font-size:12px; color:#6B7280;"><?= date('M j, Y h:i A', strtotime($log['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?>
                <tr><td colspan="6" style="text-align:center; color:#9CA3AF; padding:24px;">No audit logs found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<?php include '../../includes/footer.php'; ?>
