<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('employee');
$pageTitle  = 'Announcements';
$activePage = 'announcements';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    header('Content-Type: application/json');
    $announcementId = (int)($_POST['announcement_id'] ?? 0);
    $stmt = $db->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, employee_id) SELECT id, ? FROM announcements WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['employee_id'], $announcementId]);
    echo json_encode(['success' => true]);
    exit;
}

$stmt = $db->prepare("SELECT a.*, (ar.announcement_id IS NULL) AS is_new
    FROM announcements a
    LEFT JOIN announcement_reads ar ON ar.announcement_id = a.id AND ar.employee_id = ?
    WHERE a.is_active = 1
    ORDER BY FIELD(a.priority, 'urgent', 'important', 'normal'), a.date_posted DESC");
$stmt->execute([$_SESSION['employee_id']]);
$announcements = $stmt->fetchAll();
include '../../includes/header.php';
?>
<div class="page-body">
<div class="card">
    <div class="card-title">Announcements</div>
    <?php foreach ($announcements as $a): ?>
    <div style="border:1px solid #E5E7EB; border-radius:10px; padding:18px; margin-bottom:14px; cursor:pointer;" data-announcement-card="<?= (int)$a['id'] ?>" role="button" tabindex="0" aria-expanded="false">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; gap:8px;">
            <div style="font-size:15px; font-weight:700;">
                <?= htmlspecialchars($a['title']) ?>
                <?php if ($a['is_new']): ?><span class="badge badge-blue" data-new-badge style="font-size:10px; margin-left:6px;">New</span><?php endif; ?>
            </div>
            <span class="badge <?= $a['priority']==='urgent'?'badge-red':($a['priority']==='important'?'badge-amber':'badge-gray') ?>">
                <?= ucfirst($a['priority']) ?>
            </span>
        </div>
        <div data-announcement-details hidden style="font-size:13px; color:#374151; line-height:1.7; margin-top:10px;"><?= nl2br(htmlspecialchars($a['message'])) ?></div>
        <div style="font-size:11px; color:#9CA3AF; margin-top:10px;">
            Posted by <?= htmlspecialchars($a['posted_by']) ?> on <?= formatDate($a['date_posted']) ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($announcements)): ?>
    <div style="text-align:center; color:#9CA3AF; padding:32px; font-size:13px;">No announcements at this time.</div>
    <?php endif; ?>
</div>
</div>
<script>
document.querySelectorAll('[data-announcement-card]').forEach(function (card) {
    async function toggleDetails() {
        var details = card.querySelector('[data-announcement-details]');
        var opening = details.hidden;
        if (opening) {
            document.querySelectorAll('[data-announcement-card][aria-expanded="true"]').forEach(function (openCard) {
                if (openCard !== card) {
                    openCard.querySelector('[data-announcement-details]').hidden = true;
                    openCard.setAttribute('aria-expanded', 'false');
                }
            });
        }
        details.hidden = !opening;
        card.setAttribute('aria-expanded', opening ? 'true' : 'false');
        var badge = card.querySelector('[data-new-badge]');
        if (opening && badge) {
            var body = new URLSearchParams({action: 'mark_read', announcement_id: card.dataset.announcementCard});
            var response = await fetch(window.location.href, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body.toString()});
            if (response.ok) badge.remove();
        }
    }
    card.addEventListener('click', toggleDetails);
    card.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            toggleDetails();
        }
    });
});
</script>
