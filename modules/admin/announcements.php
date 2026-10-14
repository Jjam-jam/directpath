<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin', 'payroll_manager', 'ceo');

$pageTitle  = 'Announcements';
$activePage = 'announcements';
$db = getDB();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireRole('hr_admin');
    $action = $_POST['action'];

    if ($action === 'add') {
        $title    = sanitize($_POST['title'] ?? '');
        $message  = sanitize($_POST['message'] ?? '');
        $priority = sanitize($_POST['priority'] ?? 'normal');
        if ($title && $message) {
            $db->prepare("INSERT INTO announcements (title, message, priority, posted_by, is_active) VALUES (?,?,?,?,1)")
               ->execute([$title, $message, $priority, $_SESSION['name']]);
            notifyActiveEmployees($db, 'New announcement: ' . $title, 'modules/employee/announcements.php');
            createNotification($db, 'New announcement: ' . $title, 'modules/ceo/announcements.php', null, 'ceo');
            createNotification($db, 'New announcement: ' . $title, 'modules/admin/announcements.php', null, 'payroll_manager');
            auditLog('Post Announcement','Announcements',"Posted: $title");
            $success = 'Announcement posted.';
        } else {
            $error = 'Title and message are required.';
        }
    }

    if ($action === 'edit') {
        $aid      = (int)($_POST['ann_id'] ?? 0);
        $title    = sanitize($_POST['title'] ?? '');
        $message  = sanitize($_POST['message'] ?? '');
        $priority = sanitize($_POST['priority'] ?? 'normal');
        $active   = (int)($_POST['is_active'] ?? 1);
        $db->prepare("UPDATE announcements SET title=?, message=?, priority=?, is_active=? WHERE id=?")->execute([$title, $message, $priority, $active, $aid]);
        if ($active) {
            notifyActiveEmployees($db, 'Announcement updated: ' . $title, 'modules/employee/announcements.php');
            createNotification($db, 'Announcement updated: ' . $title, 'modules/ceo/announcements.php', null, 'ceo');
            createNotification($db, 'Announcement updated: ' . $title, 'modules/admin/announcements.php', null, 'payroll_manager');
        }
        auditLog('Edit Announcement','Announcements',"Edited ID: $aid");
        $success = 'Announcement updated.';
    }

    if ($action === 'delete') {
        $aid = (int)($_POST['ann_id'] ?? 0);
        $db->prepare("DELETE FROM announcements WHERE id=?")->execute([$aid]);
        auditLog('Delete Announcement','Announcements',"Deleted ID: $aid");
        $success = 'Announcement deleted.';
    }
}

$announcements = $_SESSION['role'] === 'hr_admin'
    ? $db->query("SELECT * FROM announcements ORDER BY FIELD(priority, 'urgent', 'important', 'normal'), date_posted DESC")->fetchAll()
    : $db->query("SELECT * FROM announcements WHERE is_active=1 ORDER BY FIELD(priority, 'urgent', 'important', 'normal'), date_posted DESC")->fetchAll();

include '../../includes/header.php';
?>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($_SESSION['role'] === 'hr_admin'): ?>
<div class="card" style="display:flex; justify-content:flex-end;">
    <button class="btn-primary" onclick="openModal('addAnnModal')">+ Post Announcement</button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-title">All Announcements (<?= count($announcements) ?>)</div>
    <div class="table-wrapper" data-table-search="Search announcements...">
        <table>
            <thead>
                <tr><th>Title</th><th>Priority</th><th>Posted By</th><th>Date</th><th>Active</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($announcements as $a): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($a['title']) ?></div>
                        <div style="font-size:12px; color:#9CA3AF; margin-top:2px;"><?= htmlspecialchars(substr($a['message'],0,70)) ?>...</div>
                    </td>
                    <td>
                        <span class="badge <?= $a['priority']==='urgent'?'badge-red':($a['priority']==='important'?'badge-amber':'badge-gray') ?>">
                            <?= ucfirst($a['priority']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($a['posted_by']) ?></td>
                    <td style="font-size:12px;"><?= formatDate($a['date_posted']) ?></td>
                    <td>
                        <span class="badge <?= $a['is_active'] ? 'badge-green' : 'badge-gray' ?>">
                            <?= $a['is_active'] ? 'Active' : 'Hidden' ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($_SESSION['role'] === 'hr_admin'): ?>
                        <div style="display:flex; gap:6px;">
                            <button class="btn-secondary btn-sm" onclick="openEditAnn(<?= htmlspecialchars(json_encode($a)) ?>)">Edit</button>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="ann_id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn-secondary btn-sm" style="color:#CD1E27;" onclick="return confirm('Delete announcement?')">Delete</button>
                            </form>
                        </div>
                        <?php else: ?>View only<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($announcements)): ?>
                <tr><td colspan="6" style="text-align:center; color:#9CA3AF; padding:24px;">No announcements yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<?php if ($_SESSION['role'] === 'hr_admin'): ?>
<!-- Add Modal -->
<div class="modal-overlay" id="addAnnModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Post Announcement</div>
            <button class="modal-close" onclick="closeModal('addAnnModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group"><label class="form-label">Title *</label><input type="text" name="title" class="form-input" required></div>
            <div class="form-group"><label class="form-label">Message *</label><textarea name="message" class="form-textarea" rows="4" required></textarea></div>
            <div class="form-group"><label class="form-label">Priority</label>
                <select name="priority" class="form-select">
                    <option value="normal">Normal</option>
                    <option value="important">Important</option>
                    <option value="urgent">Urgent</option>
                </select>
            </div>
            <div style="display:flex; gap:10px; margin-top:12px;">
                <button type="submit" class="btn-primary">Post</button>
                <button type="button" class="btn-secondary" onclick="closeModal('addAnnModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editAnnModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Edit Announcement</div>
            <button class="modal-close" onclick="closeModal('editAnnModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="ann_id" id="ea_id">
            <div class="form-group"><label class="form-label">Title</label><input type="text" name="title" id="ea_title" class="form-input" required></div>
            <div class="form-group"><label class="form-label">Message</label><textarea name="message" id="ea_msg" class="form-textarea" rows="4" required></textarea></div>
            <div class="row g-2">
                <div class="col-6"><div class="form-group"><label class="form-label">Priority</label>
                    <select name="priority" id="ea_priority" class="form-select">
                        <option value="normal">Normal</option><option value="important">Important</option><option value="urgent">Urgent</option>
                    </select>
                </div></div>
                <div class="col-6"><div class="form-group"><label class="form-label">Status</label>
                    <select name="is_active" id="ea_active" class="form-select">
                        <option value="1">Active</option><option value="0">Hidden</option>
                    </select>
                </div></div>
            </div>
            <div style="display:flex; gap:10px; margin-top:12px;">
                <button type="submit" class="btn-primary">Update</button>
                <button type="button" class="btn-secondary" onclick="closeModal('editAnnModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditAnn(a) {
    document.getElementById('ea_id').value       = a.id;
    document.getElementById('ea_title').value    = a.title;
    document.getElementById('ea_msg').value      = a.message;
    document.getElementById('ea_priority').value = a.priority;
    document.getElementById('ea_active').value   = a.is_active;
    openModal('editAnnModal');
}
</script>
<?php endif; ?>
