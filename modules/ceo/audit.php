<?php
// ============================================================
// CEO Audit Trail — READ ONLY
// Filter the system audit log by employee, module, action and date range.
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('ceo');

$pageTitle  = 'Audit Trail';
$activePage = 'audit';
$db = getDB();

$empFilter    = trim($_GET['employee'] ?? '');
$moduleFilter = trim($_GET['module'] ?? '');
$searchFilter = trim($_GET['q'] ?? '');
$from         = trim($_GET['from'] ?? date('Y-m-01'));
$to           = trim($_GET['to'] ?? date('Y-m-d'));
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 50;

$validDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
if (!$validDate($from)) $from = date('Y-m-01');
if (!$validDate($to))   $to   = date('Y-m-d');

$where  = ["a.created_at >= ?", "a.created_at < DATE_ADD(?, INTERVAL 1 DAY)"];
$params = [$from . ' 00:00:00', $to];
if ($empFilter !== '')    { $where[] = 'a.user_id = ?';    $params[] = $empFilter; }
if ($moduleFilter !== '') { $where[] = 'a.module = ?';     $params[] = $moduleFilter; }
$whereSql = implode(' AND ', $where)
    . searchClause(['a.user_id', 'a.user_name', 'a.action', 'a.module', 'a.details', 'a.ip_address'], $searchFilter, $params);

// ── CSV export (still read-only) ─────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $st = $db->prepare("SELECT a.created_at, a.user_id, a.user_name, a.module, a.action, a.details, a.ip_address
                        FROM audit_logs a WHERE $whereSql ORDER BY a.created_at DESC LIMIT 5000");
    $st->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_trail_' . $from . '_to_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date/Time', 'Employee No.', 'Name', 'Module', 'Action', 'Details', 'IP']);
    foreach ($st as $r) fputcsv($out, [$r['created_at'], $r['user_id'], $r['user_name'], $r['module'], $r['action'], $r['details'], $r['ip_address']]);
    fclose($out);
    exit;
}

$cnt = $db->prepare("SELECT COUNT(*) FROM audit_logs a WHERE $whereSql");
$cnt->execute($params);
$total      = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$st = $db->prepare("SELECT a.* FROM audit_logs a WHERE $whereSql ORDER BY a.created_at DESC, a.id DESC LIMIT $perPage OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll();

$employees = $db->query("SELECT employee_id, first_name, last_name FROM users ORDER BY last_name, first_name")->fetchAll();
$modules   = $db->query("SELECT DISTINCT module FROM audit_logs ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);

// Activity summary for the selected filters (per employee)
$sum = $db->prepare("SELECT a.user_id, a.user_name, COUNT(*) AS actions, MAX(a.created_at) AS last_seen
                     FROM audit_logs a WHERE $whereSql GROUP BY a.user_id, a.user_name ORDER BY actions DESC LIMIT 10");
$sum->execute($params);
$summary = $sum->fetchAll();

$qs = function (array $over = []) {
    return '?' . http_build_query(array_merge($_GET, $over));
};

include '../../includes/header.php';
?>
<div class="page-body">

<div class="card">
    <form method="GET" data-live style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div><label class="form-label">Employee</label>
            <select name="employee" class="form-select" style="min-width:200px;">
                <option value="">All employees</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?= htmlspecialchars($e['employee_id']) ?>" <?= $empFilter === $e['employee_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($e['employee_id'] . ' — ' . $e['last_name'] . ', ' . $e['first_name']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div><label class="form-label">Module</label>
            <select name="module" class="form-select">
                <option value="">All modules</option>
                <?php foreach ($modules as $m): ?><option <?= $moduleFilter === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option><?php endforeach; ?>
            </select></div>
        <div><label class="form-label">From</label><input type="date" name="from" class="form-input" value="<?= htmlspecialchars($from) ?>"></div>
        <div><label class="form-label">To</label><input type="date" name="to" class="form-input" value="<?= htmlspecialchars($to) ?>"></div>
        <div><label class="form-label">Search</label><input type="text" name="q" class="form-input" placeholder="Search anything: user, action, module, details..." value="<?= htmlspecialchars($searchFilter) ?>"></div>
        <button class="btn-primary" type="submit">Apply</button>
        <a class="btn-secondary" href="audit.php">Reset</a>
        <a class="btn-secondary" href="<?= htmlspecialchars($qs(['export' => 'csv'])) ?>">Export CSV</a>
    </form>
</div>

<?php if ($summary && $empFilter === ''): ?>
<div class="card">
    <div class="card-title">Most active accounts in this range</div>
    <div class="table-wrapper"><table>
        <thead><tr><th>Employee No.</th><th>Name</th><th>Actions</th><th>Last activity</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($summary as $s): ?>
        <tr>
            <td><?= htmlspecialchars($s['user_id']) ?></td>
            <td><?= htmlspecialchars($s['user_name']) ?></td>
            <td><?= (int)$s['actions'] ?></td>
            <td><?= date('M d, Y g:i A', strtotime($s['last_seen'])) ?></td>
            <td><a class="card-link" href="<?= htmlspecialchars($qs(['employee' => $s['user_id'], 'page' => 1])) ?>">View trail</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-title"><span>Audit log <span style="font-weight:400;color:#6B7280;">(<?= number_format($total) ?> entries)</span></span></div>
    <div class="table-wrapper"><table data-no-paginate>
        <thead><tr><th>Date / Time</th><th>Employee No.</th><th>Name</th><th>Module</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td style="white-space:nowrap;"><?= date('M d, Y g:i:s A', strtotime($r['created_at'])) ?></td>
            <td><?= htmlspecialchars($r['user_id']) ?></td>
            <td><?= htmlspecialchars($r['user_name']) ?></td>
            <td><span class="badge badge-blue"><?= htmlspecialchars($r['module']) ?></span></td>
            <td><?= htmlspecialchars($r['action']) ?></td>
            <td style="font-size:11px;color:#6B7280;max-width:380px;"><?= htmlspecialchars($r['details'] ?? '') ?></td>
            <td style="font-size:11px;color:#9CA3AF;"><?= htmlspecialchars($r['ip_address'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" style="text-align:center;color:#9CA3AF;padding:24px;">No audit entries for these filters.</td></tr><?php endif; ?>
        </tbody></table></div>

    <?php if ($totalPages > 1):
        $win = [];
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1 || ($page <= 3 && $i <= 4) || ($page >= $totalPages - 2 && $i >= $totalPages - 3)) $win[] = $i;
            elseif (end($win) !== '…') $win[] = '…';
        }
        $showFrom = $offset + 1; $showTo = min($offset + $perPage, $total);
    ?>
    <nav class="dp-pager" aria-label="Table pagination">
        <div class="dp-row">
            <?php if ($page > 1): ?><a class="dp-btn is-nav" href="<?= htmlspecialchars($qs(['page' => $page - 1])) ?>" aria-label="Previous page">‹</a><?php else: ?><span class="dp-btn is-nav" style="opacity:.4;cursor:not-allowed;">‹</span><?php endif; ?>
            <?php foreach ($win as $w): ?>
                <?php if ($w === '…'): ?><span class="dp-gap">…</span>
                <?php elseif ($w === $page): ?><span class="dp-btn is-current" aria-current="page"><?= $w ?></span>
                <?php else: ?><a class="dp-btn" href="<?= htmlspecialchars($qs(['page' => $w])) ?>" aria-label="Page <?= $w ?>"><?= $w ?></a><?php endif; ?>
            <?php endforeach; ?>
            <?php if ($page < $totalPages): ?><a class="dp-btn is-nav" href="<?= htmlspecialchars($qs(['page' => $page + 1])) ?>" aria-label="Next page">›</a><?php else: ?><span class="dp-btn is-nav" style="opacity:.4;cursor:not-allowed;">›</span><?php endif; ?>
        </div>
        <div class="dp-info"><?= number_format($showFrom) ?>–<?= number_format($showTo) ?> of <?= number_format($total) ?></div>
    </nav>
    <?php endif; ?>
</div>

</div>