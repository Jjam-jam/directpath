<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('superadmin');

$pageTitle  = 'Bug Tickets';
$activePage = 'tickets';
$db = getDB();

$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $tid    = (int)($_POST['ticket_id'] ?? 0);

    if ($action === 'update_status') {
        $status = sanitize($_POST['status'] ?? '');
        $notes  = sanitize($_POST['resolution_notes'] ?? '');
        $resolved = ($status === 'resolved' || $status === 'closed') ? date('Y-m-d H:i:s') : null;
        $db->prepare("UPDATE bug_tickets SET status=?, resolution_notes=?, date_resolved=? WHERE id=?")
           ->execute([$status, $notes, $resolved, $tid]);
        auditLog('Update Ticket','Tickets',"Updated ticket ID: $tid to $status");
        $success = 'Ticket updated.';
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM bug_tickets WHERE id=?")->execute([$tid]);
        $success = 'Ticket deleted.';
    }
}

$filterStatus = sanitize($_GET['status'] ?? '');
$where  = "WHERE 1=1";
$params = [];
if ($filterStatus) { $where .= " AND status=?"; $params[] = $filterStatus; }

$stmt = $db->prepare("SELECT * FROM bug_tickets $where ORDER BY date_created DESC");
$stmt->execute($params);
$tickets = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<!-- Filter -->
<div class="card">
    <form method="GET" class="filter-bar" style="margin:0;">
        <select name="status" class="form-select" style="width:auto;">
            <option value="">All Status</option>
            <option value="open"        <?= $filterStatus==='open'?'selected':'' ?>>Open</option>
            <option value="in-progress" <?= $filterStatus==='in-progress'?'selected':'' ?>>In Progress</option>
            <option value="resolved"    <?= $filterStatus==='resolved'?'selected':'' ?>>Resolved</option>
            <option value="closed"      <?= $filterStatus==='closed'?'selected':'' ?>>Closed</option>
        </select>
        <button type="submit" class="btn-primary">Filter</button>
        <a href="tickets.php" class="btn-secondary">Reset</a>
    </form>
</div>

<!-- Tickets Table -->
<div class="card">
    <div class="card-title">Bug Tickets (<?= count($tickets) ?>)</div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Title</th><th>Reported By</th><th>Priority</th><th>Status</th><th>Date</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($tickets as $t): ?>
                <tr>
                    <td>
                        <div style="font-weight:600; font-size:13px;"><?= htmlspecialchars($t['title']) ?></div>
                        <div style="font-size:11px; color:#9CA3AF; margin-top:2px;"><?= htmlspecialchars(substr($t['description'],0,60)) ?>...</div>
                    </td>
                    <td style="font-size:13px;"><?= htmlspecialchars($t['reported_by_name']) ?></td>
                    <td>
                        <span class="badge <?= $t['priority']==='critical'||$t['priority']==='high'?'badge-red':($t['priority']==='medium'?'badge-amber':'badge-gray') ?>">
                            <?= ucfirst($t['priority']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= $t['status']==='resolved'||$t['status']==='closed'?'badge-green':($t['status']==='open'?'badge-red':'badge-amber') ?>">
                            <?= ucfirst($t['status']) ?>
                        </span>
                    </td>
                    <td style="font-size:12px; color:#9CA3AF;"><?= date('M j, Y', strtotime($t['date_created'])) ?></td>
                    <td>
                        <div style="display:flex; gap:6px;">
                            <button class="btn-secondary btn-sm" onclick="openUpdateModal(<?= htmlspecialchars(json_encode($t)) ?>)">Update</button>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn-secondary btn-sm" style="color:#CD1E27;" onclick="return confirm('Delete ticket?')">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($tickets)): ?>
                <tr><td colspan="6" style="text-align:center; color:#9CA3AF; padding:24px;">No tickets found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<!-- Update Ticket Modal -->
<div class="modal-overlay" id="updateModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Update Ticket</div>
            <button class="modal-close" onclick="closeModal('updateModal')">&times;</button>
        </div>
        <div style="font-size:13px; color:#6B7280; margin-bottom:16px;" id="ticketDesc"></div>
        <form method="POST">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="ticket_id" id="ticket_id">
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" id="ticket_status" class="form-select">
                    <option value="open">Open</option>
                    <option value="in-progress">In Progress</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Resolution Notes</label>
                <textarea name="resolution_notes" id="ticket_notes" class="form-textarea" rows="3" placeholder="Describe the fix or resolution..."></textarea>
            </div>
            <div style="display:flex; gap:10px; margin-top:12px;">
                <button type="submit" class="btn-primary">Save Update</button>
                <button type="button" class="btn-secondary" onclick="closeModal('updateModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openUpdateModal(t) {
    document.getElementById('ticket_id').value     = t.id;
    document.getElementById('ticket_status').value = t.status;
    document.getElementById('ticket_notes').value  = t.resolution_notes || '';
    document.getElementById('ticketDesc').textContent = t.title + ' — ' + t.description.substring(0, 100) + '...';
    openModal('updateModal');
}
</script>
