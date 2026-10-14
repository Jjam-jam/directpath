<?php
// CEO Announcements — READ ONLY (no post / edit / delete controls)
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('ceo');

$pageTitle  = 'Announcements';
$activePage = 'announcements';
$db = getDB();

$announcements = $db->query("SELECT * FROM announcements WHERE is_active = 1 ORDER BY FIELD(priority, 'urgent', 'important', 'normal'), date_posted DESC")->fetchAll();

include '../../includes/header.php';
?>
<div class="page-body">
    <div class="alert alert-info" style="margin-bottom:16px;">You have view-only access. Announcements are posted by HR Admin.</div>

    <?php foreach ($announcements as $a): ?>
    <div class="card" style="margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
            <div style="font-size:14px;font-weight:600;"><?= htmlspecialchars($a['title']) ?></div>
            <span class="badge <?= $a['priority']==='urgent'?'badge-red':($a['priority']==='important'?'badge-amber':'badge-gray') ?>"><?= ucfirst($a['priority']) ?></span>
        </div>
        <div style="font-size:13px;color:#374151;margin-top:8px;white-space:pre-wrap;"><?= htmlspecialchars($a['message']) ?></div>
        <div style="font-size:11px;color:#9CA3AF;margin-top:10px;">Posted by <?= htmlspecialchars($a['posted_by']) ?> · <?= formatDate($a['date_posted']) ?></div>
    </div>
    <?php endforeach; ?>
    <?php if (!$announcements): ?>
    <div class="card" style="text-align:center;color:#9CA3AF;padding:32px;">No active announcements.</div>
    <?php endif; ?>
</div>
