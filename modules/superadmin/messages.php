<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
http_response_code(404);
exit;

$pageTitle  = 'Messages';
$activePage = 'messages';
$db = getDB();
$myId = $_SESSION['employee_id'];

$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'send') {
        $to      = sanitize($_POST['receiver_id'] ?? '');
        $subject = sanitize($_POST['subject'] ?? '');
        $message = sanitize($_POST['message'] ?? '');
        if ($to && $message) {
            $db->prepare("INSERT INTO messages (sender_id, receiver_id, subject, message) VALUES (?,?,?,?)")
               ->execute([$myId, $to, $subject, $message]);
            auditLog('Send Message','Messages',"Sent to $to");
            $success = 'Message sent.';
        }
    }

    if ($action === 'delete') {
        $mid = (int)($_POST['msg_id'] ?? 0);
        $db->prepare("DELETE FROM messages WHERE id=? AND (sender_id=? OR receiver_id=?)")->execute([$mid, $myId, $myId]);
        $success = 'Message deleted.';
    }
}

// Mark received messages as read
$db->prepare("UPDATE messages SET is_read=1 WHERE receiver_id=? AND is_read=0")->execute([$myId]);

// Get all messages (inbox + sent)
$inbox = $db->prepare("SELECT m.*, CONCAT(u.first_name, ' ', u.last_name) AS sender_name FROM messages m JOIN users e ON m.sender_id=e.employee_id WHERE m.receiver_id=? ORDER BY m.sent_at DESC");
$inbox->execute([$myId]);
$inboxMsgs = $inbox->fetchAll();

$sent = $db->prepare("SELECT m.*, CONCAT(u.first_name, ' ', u.last_name) AS receiver_name FROM messages m JOIN users e ON m.receiver_id=e.employee_id WHERE m.sender_id=? ORDER BY m.sent_at DESC");
$sent->execute([$myId]);
$sentMsgs = $sent->fetchAll();

$admins = $db->query("SELECT u.employee_id, CONCAT(u.first_name, ' ', u.last_name) AS name FROM users u JOIN user_roles ur ON ur.employee_id=u.employee_id JOIN roles r ON r.id=ur.role_id WHERE r.role_name IN ('hr_admin','payroll_manager','ceo') AND u.status='active' ORDER BY u.last_name, u.first_name")->fetchAll();

include '../../includes/header.php';
?>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="card" style="display:flex; justify-content:flex-end;">
    <button class="btn-primary" onclick="openModal('composeModal')">Compose Message</button>
</div>

<div class="row g-3">
    <!-- Inbox -->
    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-title">Inbox (<?= count($inboxMsgs) ?>)</div>
            <?php foreach ($inboxMsgs as $m): ?>
            <div style="border:1px solid #E5E7EB; border-radius:8px; padding:14px; margin-bottom:8px; background:<?= $m['is_read']?'#fff':'#F0F9FF' ?>;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px;">
                    <div>
                        <div style="font-size:13px; font-weight:600;"><?= htmlspecialchars($m['sender_name']) ?></div>
                        <?php if ($m['subject']): ?><div style="font-size:11px; color:#6B7280;"><?= htmlspecialchars($m['subject']) ?></div><?php endif; ?>
                    </div>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="msg_id" value="<?= $m['id'] ?>">
                        <button type="submit" style="background:none; border:none; color:#9CA3AF; cursor:pointer; font-size:14px;" onclick="return confirm('Delete?')">&times;</button>
                    </form>
                </div>
                <div style="font-size:12px; color:#374151;"><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                <div style="font-size:11px; color:#9CA3AF; margin-top:6px;"><?= date('M j, Y h:i A', strtotime($m['sent_at'])) ?></div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($inboxMsgs)): ?>
            <div style="text-align:center; color:#9CA3AF; font-size:13px; padding:20px;">No messages in inbox.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sent -->
    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-title">Sent (<?= count($sentMsgs) ?>)</div>
            <?php foreach ($sentMsgs as $m): ?>
            <div style="border:1px solid #E5E7EB; border-radius:8px; padding:14px; margin-bottom:8px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px;">
                    <div>
                        <div style="font-size:13px; font-weight:600;">To: <?= htmlspecialchars($m['receiver_name']) ?></div>
                        <?php if ($m['subject']): ?><div style="font-size:11px; color:#6B7280;"><?= htmlspecialchars($m['subject']) ?></div><?php endif; ?>
                    </div>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="msg_id" value="<?= $m['id'] ?>">
                        <button type="submit" style="background:none; border:none; color:#9CA3AF; cursor:pointer; font-size:14px;" onclick="return confirm('Delete?')">&times;</button>
                    </form>
                </div>
                <div style="font-size:12px; color:#374151;"><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                <div style="font-size:11px; color:#9CA3AF; margin-top:6px;"><?= date('M j, Y h:i A', strtotime($m['sent_at'])) ?></div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($sentMsgs)): ?>
            <div style="text-align:center; color:#9CA3AF; font-size:13px; padding:20px;">No sent messages.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

</div>

<!-- Compose Modal -->
<div class="modal-overlay" id="composeModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Compose Message</div>
            <button class="modal-close" onclick="closeModal('composeModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="send">
            <div class="form-group">
                <label class="form-label">To (Admin) *</label>
                <select name="receiver_id" class="form-select" required>
                    <option value="">-- Select Admin --</option>
                    <?php foreach ($admins as $a): ?>
                    <option value="<?= $a['employee_id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Subject</label>
                <input type="text" name="subject" class="form-input" placeholder="Optional subject...">
            </div>
            <div class="form-group">
                <label class="form-label">Message *</label>
                <textarea name="message" class="form-textarea" rows="4" required placeholder="Type your message..."></textarea>
            </div>
            <div style="display:flex; gap:10px; margin-top:12px;">
                <button type="submit" class="btn-primary">Send Message</button>
                <button type="button" class="btn-secondary" onclick="closeModal('composeModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
