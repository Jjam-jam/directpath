<?php
// Employee — Performance Evaluation (view-only, own FINALIZED evaluations only)
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('employee');

$pageTitle  = 'Performance Evaluation';
$activePage = 'performance';
$db = getDB();
$me = $_SESSION['employee_id'];

$st = $db->prepare("SELECT * FROM performance_evaluations WHERE employee_id=? AND status='finalized' ORDER BY period_end DESC");
$st->execute([$me]);
$evals = $st->fetchAll();
$selId = (int)($_GET['id'] ?? ($evals[0]['id'] ?? 0));
$cur = null;
foreach ($evals as $e) if ((int)$e['id'] === $selId) $cur = $e;   // only the employee's own finalized rows can be selected
$ratings = [];
if ($cur) {
    $rs = $db->prepare("SELECT c.name, c.description, r.rating, r.weight, r.weighted_score FROM performance_ratings r JOIN performance_criteria c ON c.id=r.criterion_id WHERE r.evaluation_id=? ORDER BY c.sort_order");
    $rs->execute([$cur['id']]); $ratings = $rs->fetchAll();
}
include '../../includes/header.php';
?>
<div class="page-body">
<?php if (!$cur): ?>
  <div class="card" style="text-align:center;padding:40px;color:#6B7280;">No finalized performance evaluation yet. Your evaluation will appear here once HR finalizes it.</div>
<?php else: $color = performanceLevelColor($cur['performance_level']); $score = (float)$cur['total_score']; ?>
  <?php if (count($evals) > 1): ?><div class="card"><form method="GET"><label class="form-label">Evaluation period</label>
    <select name="id" class="form-select" style="width:auto;" onchange="this.form.submit()"><?php foreach ($evals as $e): ?><option value="<?= (int)$e['id'] ?>" <?= (int)$e['id']===$selId?'selected':'' ?>><?= htmlspecialchars($e['period_label']) ?></option><?php endforeach; ?></select></form></div><?php endif; ?>
  <div class="card">
    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;align-items:center;">
      <div><div style="font-size:12px;color:#6B7280;">Evaluation period</div><div style="font-weight:700;font-size:16px;"><?= htmlspecialchars($cur['period_label']) ?></div>
        <div style="font-size:12px;color:#6B7280;"><?= formatDate($cur['period_start']) ?> – <?= formatDate($cur['period_end']) ?></div></div>
      <div style="text-align:right;"><div style="font-size:34px;font-weight:800;color:<?= $color ?>;"><?= number_format($score, 2) ?><span style="font-size:15px;color:#9CA3AF;"> / 100</span></div>
        <div style="font-weight:700;color:<?= $color ?>;"><?= htmlspecialchars($cur['performance_level']) ?></div></div>
    </div>
    <div style="background:#E5E7EB;border-radius:99px;height:14px;margin-top:14px;overflow:hidden;"><div style="width:<?= max(0,min(100,$score)) ?>%;height:100%;background:<?= $color ?>;"></div></div>
  </div>
  <div class="card"><div class="card-title">Ratings</div><div class="table-wrapper"><table>
    <thead><tr><th>Criteria</th><th>Rating</th><th style="text-align:right;">Weight</th><th style="text-align:right;">Weighted score</th></tr></thead><tbody>
    <?php foreach ($ratings as $r): ?><tr><td><div style="font-weight:600;"><?= htmlspecialchars($r['name']) ?></div><div style="font-size:11px;color:#6B7280;"><?= htmlspecialchars($r['description']) ?></div></td>
      <td><?= (int)$r['rating'] ?>/5 — <?= PERF_RATING_LABELS[(int)$r['rating']] ?? '' ?></td><td style="text-align:right;"><?= rtrim(rtrim(number_format((float)$r['weight'],2),'0'),'.') ?>%</td><td style="text-align:right;"><?= number_format((float)$r['weighted_score'],2) ?></td></tr><?php endforeach; ?>
    <tr style="font-weight:700;background:#F9FAFB;"><td>Total</td><td></td><td style="text-align:right;">100%</td><td style="text-align:right;"><?= number_format($score,2) ?></td></tr></tbody></table></div></div>
  <div class="card"><div class="card-title">HR/Admin Remarks</div><p style="white-space:pre-wrap;"><?= htmlspecialchars($cur['hr_remarks'] ?: 'No remarks.') ?></p>
    <div style="font-size:12px;color:#6B7280;">Evaluated by <?= htmlspecialchars($cur['evaluated_by_name'] ?? 'HR/Admin') ?> on <?= formatDate($cur['finalized_at']) ?></div></div>
<?php endif; ?>
</div>
