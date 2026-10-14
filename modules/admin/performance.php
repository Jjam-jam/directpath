<?php
// ============================================================
// HR/Admin — Performance Evaluation
// HR creates, rates and finalizes. Weighted score and level are computed by the system:
//   weighted score = (rating / 5) x weight ; total = sum ; level from the total.
// Employees only see FINALIZED evaluations (employee/performance.php); the CEO views them read-only.
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin');

$pageTitle  = 'Performance Evaluation';
$activePage = 'performance';
$db = getDB();
$success = $error = '';
// Attendance numbers shown next to the "Attendance & Punctuality" rating (read-only helper)
if (isset($_GET['att'])) {
    header('Content-Type: application/json');
    $e = sanitize($_GET['emp'] ?? ''); $f = sanitize($_GET['from'] ?? ''); $t = sanitize($_GET['to'] ?? '');
    echo json_encode(preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? attendanceSummary($db, $e, $f, $t) : []);
    exit;
}
$criteria = $db->query("SELECT * FROM performance_criteria ORDER BY sort_order")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $evalId   = (int)($_POST['eval_id'] ?? 0);
    $empId    = sanitize($_POST['employee_id'] ?? '');
    $label    = trim(sanitize($_POST['period_label'] ?? ''));
    $start    = sanitize($_POST['period_start'] ?? '');
    $end      = sanitize($_POST['period_end'] ?? '');
    $remarks  = trim(sanitize($_POST['hr_remarks'] ?? ''));
    $finalize = ($_POST['finish'] ?? '') === 'finalize';
    $ratings  = $_POST['rating'] ?? [];

    $chk = $db->prepare("SELECT 1 FROM users WHERE employee_id=? AND role='employee' AND status='active'");
    $chk->execute([$empId]);
    $ok = $chk->fetchColumn() && $label !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) && $start <= $end;
    $total = 0.0; $rows = [];
    foreach ($criteria as $c) {
        $r = (int)($ratings[$c['id']] ?? 0);
        if ($r < 1 || $r > 5) { if ($finalize) $ok = false; $r = max(0, min(5, $r)); }
        $ws = $r ? performanceWeightedScore($r, (float)$c['weight']) : 0.0;
        $total += $ws; $rows[] = [$c['id'], $r, (float)$c['weight'], $ws];
    }
    $total = round($total, 2);

    if (!$ok) {
        $error = $finalize ? 'To finalize: choose an active employee, period name/dates, and rate all 5 criteria (1–5).' : 'Choose an active employee and a valid period.';
    } else {
        $db->beginTransaction();
        try {
            $existing = null;
            if ($evalId) {
                $st = $db->prepare("SELECT * FROM performance_evaluations WHERE id=? FOR UPDATE"); $st->execute([$evalId]); $existing = $st->fetch();
                if (!$existing) throw new DomainException('Evaluation not found.');
                if ($existing['status'] === 'finalized') throw new DomainException('This evaluation is finalized and can no longer be edited.');
            } else {
                $dup = $db->prepare("SELECT id, status FROM performance_evaluations WHERE employee_id=? AND period_start=? AND period_end=?");
                $dup->execute([$empId, $start, $end]);
                if ($d = $dup->fetch()) throw new DomainException('An evaluation for this employee and period already exists (#' . $d['id'] . ').');
            }
            $att = attendanceSummary($db, $empId, $start, $end);   // snapshot for the record
            $level = performanceLevel($total);
            $status = $finalize ? 'finalized' : 'draft';
            if ($evalId) {
                $db->prepare("UPDATE performance_evaluations SET period_label=?, period_start=?, period_end=?, status=?, total_score=?, performance_level=?, hr_remarks=?, attendance_pct=?, late_count=?, undertime_count=?, absent_count=?, evaluated_by=?, evaluated_by_name=?, evaluated_at=NOW(), finalized_at=IF(?='finalized', NOW(), NULL) WHERE id=?")
                   ->execute([$label, $start, $end, $status, $total, $level, $remarks, $att['pct'], $att['late'], $att['undertime'], $att['absent'], $_SESSION['employee_id'], $_SESSION['name'], $status, $evalId]);
            } else {
                $db->prepare("INSERT INTO performance_evaluations (employee_id, period_label, period_start, period_end, status, total_score, performance_level, hr_remarks, attendance_pct, late_count, undertime_count, absent_count, evaluated_by, evaluated_by_name, evaluated_at, finalized_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),IF(?='finalized', NOW(), NULL))")
                   ->execute([$empId, $label, $start, $end, $status, $total, $level, $remarks, $att['pct'], $att['late'], $att['undertime'], $att['absent'], $_SESSION['employee_id'], $_SESSION['name'], $status]);
                $evalId = (int)$db->lastInsertId();
            }
            $db->prepare("DELETE FROM performance_ratings WHERE evaluation_id=?")->execute([$evalId]);
            $ins = $db->prepare("INSERT INTO performance_ratings (evaluation_id, criterion_id, rating, weight, weighted_score) VALUES (?,?,?,?,?)");
            foreach ($rows as [$cid, $r, $w, $ws]) if ($r) $ins->execute([$evalId, $cid, $r, $w, $ws]);
            if (!auditLog($finalize ? 'Finalize Evaluation' : 'Save Evaluation Draft', 'Performance', "Evaluation #$evalId | Employee: $empId | $label | Score: $total ($level)")) throw new RuntimeException('audit');
            if ($finalize) createNotification($db, 'Your performance evaluation is ready to view.', 'modules/employee/performance.php?id=' . $evalId, $empId);
            $db->commit();
            $success = $finalize ? "Evaluation submitted: $total / 100 — $level. It is now visible on the employee's Performance Evaluation page." : 'Draft saved.';
        } catch (DomainException $e) { if ($db->inTransaction()) $db->rollBack(); $error = $e->getMessage();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); error_log($e->getMessage()); $error = 'The evaluation could not be saved.'; }
    }
}

// Detail / edit target
$view = null; $viewRatings = [];
if (isset($_GET['id'])) {
    $st = $db->prepare("SELECT pe.*, u.first_name, u.last_name, u.department, u.position FROM performance_evaluations pe JOIN users u ON u.employee_id=pe.employee_id WHERE pe.id=?");
    $st->execute([(int)$_GET['id']]); $view = $st->fetch() ?: null;
    if ($view) { $rs = $db->prepare("SELECT criterion_id, rating, weight, weighted_score FROM performance_ratings WHERE evaluation_id=?"); $rs->execute([$view['id']]);
        foreach ($rs->fetchAll() as $r) $viewRatings[$r['criterion_id']] = $r; }
}

// The list is filtered live in the browser (search + Filter dropdown), so every evaluation is loaded here.
$st = $db->prepare("SELECT pe.*, u.first_name, u.last_name, u.department, u.position FROM performance_evaluations pe JOIN users u ON u.employee_id=pe.employee_id ORDER BY pe.created_at DESC LIMIT 1000");
$st->execute(); $evals = $st->fetchAll();
$employees = $db->query("SELECT employee_id, first_name, last_name, department, position FROM users WHERE role='employee' AND status='active' ORDER BY first_name")->fetchAll();
$depts = array_keys(departmentPositionMap());

$formMode = isset($_GET['new']) || ($view && $view['status'] === 'draft');
include '../../includes/header.php';
?>
<div class="page-body">
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($formMode || ($view && $view['status']==='finalized')): ?>
<?php $ro = $view && $view['status'] === 'finalized'; ?>
<div class="card">
  <div class="card-title"><?= $ro ? 'Evaluation #' . (int)$view['id'] . ' (Finalized)' : ($view ? 'Edit Draft #' . (int)$view['id'] : 'New Evaluation') ?>
     <a href="performance.php" class="btn-secondary btn-sm" style="float:right;">← Back to list</a></div>
  <form method="POST" id="evalForm">
    <input type="hidden" name="action" value="save"><input type="hidden" name="eval_id" value="<?= (int)($view['id'] ?? 0) ?>">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Employee</label>
        <?php if ($view): ?><input class="form-input" readonly value="<?= htmlspecialchars($view['first_name'].' '.$view['last_name'].' ('.$view['employee_id'].')') ?>"><input type="hidden" name="employee_id" id="emp" value="<?= htmlspecialchars($view['employee_id']) ?>">
        <?php else: ?><select name="employee_id" id="emp" class="form-select" required onchange="loadAtt()"><option value="">-- Select employee --</option>
          <?php foreach ($employees as $e): ?><option value="<?= htmlspecialchars($e['employee_id']) ?>"><?= htmlspecialchars($e['first_name'].' '.$e['last_name'].' — '.$e['department'].' / '.$e['position']) ?></option><?php endforeach; ?></select><?php endif; ?></div>
      <div class="col-md-6"><label class="form-label">Department / Position</label><input class="form-input" readonly id="deptpos" value="<?= $view ? htmlspecialchars($view['department'].' / '.$view['position']) : '' ?>"></div>
      <?php if (!$ro): ?>
      <div class="col-md-4"><label class="form-label">Period type</label>
        <div style="display:flex;gap:8px;">
          <select id="pType" class="form-select" onchange="applyPeriod()"><option value="">Custom</option><option value="Q1">Q1</option><option value="Q2">Q2</option><option value="Q3">Q3</option><option value="Q4">Q4</option><option value="H1">1st Half</option><option value="H2">2nd Half</option><option value="FY">Whole Year</option></select>
          <select id="pYear" class="form-select" style="max-width:100px;" onchange="applyPeriod()"><?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 3; $y--): ?><option value="<?= $y ?>" <?= $y === (int)date('Y') ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select>
        </div>
        <div id="pMonths" style="font-size:11px;color:var(--text-muted);margin-top:4px;">Months covered appear here.</div></div>
      <?php endif; ?>
      <div class="col-md-4"><label class="form-label">Period name</label><input name="period_label" id="pl" class="form-input" required placeholder="Filled automatically, or type your own" value="<?= htmlspecialchars($view['period_label'] ?? '') ?>" <?= $ro?'readonly':'' ?>></div>
      <div class="col-md-4"><label class="form-label">Period start</label><input type="date" name="period_start" id="ps" class="form-input" required value="<?= htmlspecialchars($view['period_start'] ?? '') ?>" onchange="loadAtt()" <?= $ro?'readonly':'' ?>></div>
      <div class="col-md-4"><label class="form-label">Period end</label><input type="date" name="period_end" id="pe" class="form-input" required value="<?= htmlspecialchars($view['period_end'] ?? '') ?>" onchange="loadAtt()" <?= $ro?'readonly':'' ?>></div>
    </div>

    <div class="table-wrapper mt-3"><table>
      <thead><tr><th>Criteria</th><th>Rating (1–5)</th><th style="text-align:right;">Weight</th><th style="text-align:right;">Weighted score</th></tr></thead><tbody>
      <?php foreach ($criteria as $c): $cur = $viewRatings[$c['id']]['rating'] ?? ''; ?>
      <tr><td><div style="font-weight:600;"><?= htmlspecialchars($c['name']) ?></div><div style="font-size:11px;color:#6B7280;"><?= htmlspecialchars($c['description']) ?></div>
        <?php if (stripos($c['name'], 'Attendance') === 0): ?><div id="attbox" style="margin-top:6px;font-size:12px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;padding:6px 10px;">Select an employee and period to see attendance data.</div><?php endif; ?></td>
        <td><select name="rating[<?= $c['id'] ?>]" class="form-select rate" data-w="<?= (float)$c['weight'] ?>" style="width:auto;" onchange="calc()" <?= $ro?'disabled':'' ?>>
          <option value="">—</option><?php foreach (PERF_RATING_LABELS as $n => $lbl): ?><option value="<?= $n ?>" <?= (string)$cur === (string)$n ? 'selected' : '' ?>><?= $n ?> — <?= $lbl ?></option><?php endforeach; ?></select></td>
        <td style="text-align:right;"><?= rtrim(rtrim(number_format((float)$c['weight'],2),'0'),'.') ?>%</td>
        <td style="text-align:right;" class="ws">0.00</td></tr>
      <?php endforeach; ?>
      <tr style="font-weight:700;background:#F9FAFB;"><td>TOTAL</td><td></td><td style="text-align:right;">100%</td><td style="text-align:right;"><span id="total">0.00</span> / 100</td></tr>
      </tbody></table></div>
    <div style="margin:10px 0;font-size:14px;">Performance level: <strong id="level">—</strong></div>
    <div class="form-group"><label class="form-label">HR/Admin remarks</label><textarea name="hr_remarks" class="form-textarea" rows="3" <?= $ro?'readonly':'' ?>><?= htmlspecialchars($view['hr_remarks'] ?? '') ?></textarea></div>
    <?php if ($ro): ?><div style="font-size:12px;color:#6B7280;">Status: Finalized · Evaluated by <?= htmlspecialchars($view['evaluated_by_name'] ?? '') ?> · <?= htmlspecialchars($view['evaluated_at'] ?? '') ?></div>
    <?php else: ?><div style="display:flex;gap:10px;"><button class="btn-secondary" type="submit" name="finish" value="draft" title="Drafts are hidden from the employee">Save Draft (hidden from employee)</button><button class="btn-primary" type="submit" name="finish" value="finalize" onclick="return confirm('Finalize? The employee will see it and it cannot be edited afterwards.')">Submit Evaluation (visible to employee)</button></div><?php endif; ?>
  </form>
</div>
<script>
var LV = [[90,'Outstanding'],[80,'Very Good'],[70,'Satisfactory'],[60,'Needs Improvement'],[0,'Unsatisfactory']];
function calc() {
  var t = 0, any = false;
  document.querySelectorAll('.rate').forEach(function (s) {
    var r = parseInt(s.value || 0), w = parseFloat(s.dataset.w), v = r ? Math.round(r / 5 * w * 100) / 100 : 0; t += v; if (r) any = true;
    s.closest('tr').querySelector('.ws').textContent = v.toFixed(2); });
  document.getElementById('total').textContent = t.toFixed(2);
  var l = '—'; if (any) for (var i = 0; i < LV.length; i++) if (t >= LV[i][0]) { l = LV[i][1]; break; }
  document.getElementById('level').textContent = l;
}
function applyPeriod() {
  var t = document.getElementById('pType').value, y = parseInt(document.getElementById('pYear').value, 10);
  var map = { Q1:[0,2,'Q1'], Q2:[3,5,'Q2'], Q3:[6,8,'Q3'], Q4:[9,11,'Q4'], H1:[0,5,'1st Half'], H2:[6,11,'2nd Half'], FY:[0,11,'Whole Year'] };
  var names = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  var note = document.getElementById('pMonths');
  if (!t) { note.textContent = 'Custom period: set the dates below.'; return; }
  var m = map[t], pad = function (n) { return String(n).padStart(2, '0'); };
  var last = new Date(y, m[1] + 1, 0).getDate();
  document.getElementById('ps').value = y + '-' + pad(m[0] + 1) + '-01';
  document.getElementById('pe').value = y + '-' + pad(m[1] + 1) + '-' + pad(last);
  var months = names.slice(m[0], m[1] + 1);
  document.getElementById('pl').value = y + ' ' + m[2] + ' (' + months[0].slice(0,3) + (months.length > 1 ? ' - ' + months[months.length-1].slice(0,3) : '') + ')';
  note.textContent = 'Covers: ' + months.join(', ');
  if (typeof loadAtt === 'function') loadAtt();
}
function loadAtt() {
  var emp = document.getElementById('emp'), box = document.getElementById('attbox'); if (!box || !emp.value) return;
  var opt = emp.options ? emp.options[emp.selectedIndex] : null; if (opt && opt.text.indexOf('—') > 0) document.getElementById('deptpos').value = opt.text.split(' — ').slice(1).join(' — ').replace(' / ', ' / ');
  var ps = document.getElementById('ps').value, pe = document.getElementById('pe').value; if (!ps || !pe) return;
  fetch('performance.php?att=1&emp=' + encodeURIComponent(emp.value) + '&from=' + ps + '&to=' + pe).then(function (r) { return r.json(); }).then(function (a) {
    box.textContent = 'Attendance ' + a.pct + '% · Late: ' + a.late + ' · Undertime: ' + a.undertime + ' · Absences: ' + a.absent + ' (of ' + a.working_days + ' working days)'; });
}
calc(); loadAtt();
</script>
<?php else: ?>
<?php
// ── Live search + filters (same behaviour as Employee Management) ──
$dec = fn($v) => html_entity_decode((string)$v, ENT_QUOTES);
$qv  = fn($k) => $dec(trim((string)($_GET[$k] ?? '')));
$cur = ['search' => trim((string)($_GET['search'] ?? '')), 'dept' => $qv('dept'), 'position' => $qv('position'), 'period' => $qv('period'),
        'level' => $qv('level'), 'status' => $qv('status'), 'by' => $qv('by'), 'month' => $qv('month')];
$opt = ['dept' => [], 'position' => [], 'period' => [], 'level' => [], 'by' => [], 'month' => []];
foreach ($depts as $d) $opt['dept'][$dec($d)] = $dec($d);
foreach ($evals as $e) {
    if ($e['position'] !== null && $e['position'] !== '')                 $opt['position'][$dec($e['position'])] = $dec($e['position']);
    if ($e['period_label'] !== null && $e['period_label'] !== '')         $opt['period'][$dec($e['period_label'])] = $dec($e['period_label']);
    if (!empty($e['performance_level']))                                  $opt['level'][$dec($e['performance_level'])] = $dec($e['performance_level']);
    if (!empty($e['evaluated_by_name']))                                  $opt['by'][$dec($e['evaluated_by_name'])] = $dec($e['evaluated_by_name']);
    if (!empty($e['evaluated_at']))                                       $opt['month'][substr((string)$e['evaluated_at'], 0, 7)] = date('F Y', strtotime((string)$e['evaluated_at']));
}
ksort($opt['position']); ksort($opt['period']); ksort($opt['level']); ksort($opt['by']); krsort($opt['month']);
$pfSelect = function (string $id, string $label, string $attr, string $all, array $options, string $sel, bool $first = false): string {
    $h = '<label class="form-label" for="' . $id . '"' . ($first ? '' : ' style="margin-top:12px;"') . '>' . $label . '</label>'
       . '<select id="' . $id . '" class="form-select" data-attr="' . $attr . '"><option value="">' . $all . '</option>';
    foreach ($options as $v => $t) $h .= '<option value="' . htmlspecialchars((string)$v) . '"' . ((string)$v === $sel ? ' selected' : '') . '>' . htmlspecialchars((string)$t) . '</option>';
    return $h . '</select>';
};
?>
<!-- Search + Filter dropdown (live, no page reload) -->
<div class="card">
  <div class="emp-toolbar">
    <div class="emp-toolbar-left">
      <div class="emp-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" id="pfSearch" class="search-input" autocomplete="off"
               placeholder="Search anything: employee, ID, department, position, period, level, status..."
               value="<?= htmlspecialchars($cur['search']) ?>">
      </div>
      <div class="emp-filter" id="pfFilter">
        <button type="button" class="btn-secondary emp-filter-btn" id="pfFilterBtn" aria-haspopup="true" aria-expanded="false">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
          Filter <span class="emp-filter-count" id="pfFilterCount" hidden>0</span>
        </button>
        <div class="emp-filter-menu" id="pfFilterMenu" role="menu" hidden>
          <?= $pfSelect('pfDept', 'Department', 'dept', 'All Departments', $opt['dept'], $cur['dept'], true) ?>
          <?= $pfSelect('pfPosition', 'Position', 'position', 'All Positions', $opt['position'], $cur['position']) ?>
          <?= $pfSelect('pfPeriod', 'Period', 'period', 'All Periods', $opt['period'], $cur['period']) ?>
          <?= $pfSelect('pfLevel', 'Level', 'level', 'All Levels', $opt['level'], $cur['level']) ?>
          <?= $pfSelect('pfStatus', 'Status', 'status', 'All Status', ['finalized' => 'Submitted', 'draft' => 'Draft (hidden)'], $cur['status']) ?>
          <?= $pfSelect('pfMonth', 'Evaluated', 'month', 'Any Date', $opt['month'], $cur['month']) ?>
          <?= $pfSelect('pfBy', 'Evaluated By', 'by', 'Anyone', $opt['by'], $cur['by']) ?>
          <div class="emp-filter-foot"><button type="button" class="btn-secondary btn-sm" id="pfClear">Clear all</button></div>
        </div>
      </div>
    </div>
    <a href="performance.php?new=1" class="btn-primary">+ New Evaluation</a>
  </div>
</div>
<style>
.emp-toolbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
.emp-toolbar-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex:1 1 420px;min-width:0;}
.emp-search{position:relative;flex:1 1 280px;max-width:460px;}
.emp-search svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:#9CA3AF;pointer-events:none;}
.emp-search .search-input{width:100%;padding-left:34px!important;}
.emp-filter{position:relative;}
.emp-filter-btn{gap:7px!important;}
.emp-filter-btn svg{width:14px;height:14px;}
.emp-filter-btn::after{content:"";width:5px;height:5px;border:solid currentColor;border-width:0 2px 2px 0;transform:rotate(45deg);margin:-3px 0 0 2px;transition:transform .15s;}
.emp-filter-btn[aria-expanded="true"]::after{transform:rotate(-135deg);margin:3px 0 0 2px;}
.emp-filter-count{background:var(--red);color:#fff;border-radius:20px;padding:0 7px;font-size:10px;font-weight:700;line-height:17px;}
.emp-filter-menu{position:absolute;top:calc(100% + 6px);left:0;z-index:60;width:280px;max-height:70vh;overflow-y:auto;background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,.12);padding:14px;}
.emp-filter-menu[hidden]{display:none;}
.emp-filter-menu .form-select{width:100%;}
.emp-filter-foot{display:flex;justify-content:flex-end;margin-top:14px;padding-top:12px;border-top:1px solid var(--border);}
.emp-row[hidden]{display:none;}
</style>

<div class="card"><div class="card-title"><span>Evaluations (<span id="pfCount"><?= count($evals) ?></span>)</span></div><div class="table-wrapper"><table>
  <thead><tr><th>Employee</th><th>Department</th><th>Position</th><th>Period</th><th>Total</th><th>Level</th><th>Status</th><th>Evaluated</th><th>By</th><th></th></tr></thead><tbody>
  <?php foreach ($evals as $e):
      $lvl = (string)($e['performance_level'] ?? ''); $by = (string)($e['evaluated_by_name'] ?? '');
      $mon = !empty($e['evaluated_at']) ? substr((string)$e['evaluated_at'], 0, 7) : '';
      $hay = mb_strtolower(implode(' ', [
          $e['first_name'], $e['last_name'], $e['employee_id'], $e['department'], (string)$e['position'], (string)$e['period_label'],
          formatDate($e['period_start']), formatDate($e['period_end']), number_format((float)$e['total_score'], 2), $lvl,
          $e['status'] === 'finalized' ? 'submitted finalized' : 'draft hidden',
          !empty($e['evaluated_at']) ? formatDate($e['evaluated_at']) . ' ' . date('F Y', strtotime((string)$e['evaluated_at'])) : '', $by,
      ])); ?>
  <tr class="emp-row" data-search="<?= htmlspecialchars($hay) ?>" data-dept="<?= htmlspecialchars($dec($e['department'])) ?>" data-position="<?= htmlspecialchars($dec($e['position'])) ?>"
      data-period="<?= htmlspecialchars($dec($e['period_label'])) ?>" data-level="<?= htmlspecialchars($dec($lvl)) ?>" data-status="<?= htmlspecialchars((string)$e['status']) ?>"
      data-month="<?= htmlspecialchars($mon) ?>" data-by="<?= htmlspecialchars($dec($by)) ?>">
  <td><div style="font-weight:600;"><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></div><div style="font-size:11px;color:#9CA3AF;"><?= htmlspecialchars($e['employee_id']) ?></div></td>
  <td><?= htmlspecialchars($e['department']) ?></td><td><?= htmlspecialchars($e['position']) ?></td><td><?= htmlspecialchars($e['period_label']) ?><div style="font-size:11px;color:#9CA3AF;"><?= formatDate($e['period_start']) ?> – <?= formatDate($e['period_end']) ?></div></td>
  <td><strong><?= number_format((float)$e['total_score'],2) ?></strong>/100</td><td><span style="color:<?= performanceLevelColor((string)$e['performance_level']) ?>;font-weight:600;"><?= htmlspecialchars($e['performance_level'] ?? '—') ?></span></td>
  <td><span class="badge <?= $e['status']==='finalized'?'badge-green':'badge-amber' ?>"><?= $e['status']==='finalized' ? 'Submitted' : 'Draft (hidden)' ?></span></td>
  <td style="font-size:12px;"><?= $e['evaluated_at'] ? formatDate($e['evaluated_at']) : '—' ?></td><td style="font-size:12px;"><?= htmlspecialchars($e['evaluated_by_name'] ?? '—') ?></td>
  <td><a class="btn-secondary btn-sm" href="performance.php?id=<?= (int)$e['id'] ?>"><?= $e['status']==='draft' ? 'Edit / Submit' : 'View' ?></a></td></tr>
  <?php endforeach; ?>
  <tr id="pfNoMatch" <?= $evals ? 'hidden' : '' ?>><td colspan="10" style="text-align:center;color:#9CA3AF;padding:24px;"><?= $evals ? 'No evaluations match your search or filters.' : 'No evaluations yet.' ?></td></tr></tbody></table></div></div>
<script>
(function () {
    var input = document.getElementById('pfSearch'),
        sels  = Array.prototype.slice.call(document.querySelectorAll('#pfFilterMenu select[data-attr]')),
        btn   = document.getElementById('pfFilterBtn'),
        menu  = document.getElementById('pfFilterMenu'),
        badge = document.getElementById('pfFilterCount'),
        count = document.getElementById('pfCount'),
        none  = document.getElementById('pfNoMatch'),
        rows  = Array.prototype.slice.call(document.querySelectorAll('.emp-row'));
    var param = { dept: 'dept', position: 'position', period: 'period', level: 'level', status: 'status', by: 'by', month: 'month' };

    function apply() {
        // every typed word must appear somewhere in the row; every chosen filter must match its column
        var words = input.value.toLowerCase().split(/\s+/).filter(Boolean), shown = 0;
        rows.forEach(function (tr) {
            var hay = tr.getAttribute('data-search'),
                ok = sels.every(function (s) { return !s.value || tr.getAttribute('data-' + s.getAttribute('data-attr')) === s.value; }) &&
                     words.every(function (w) { return hay.indexOf(w) !== -1; });
            tr.hidden = !ok;
            if (ok) shown++;
        });
        count.textContent = shown;
        none.hidden = shown > 0 || !rows.length;
        if (rows.length) none.querySelector('td').textContent = 'No evaluations match your search or filters.';
        var active = sels.filter(function (s) { return s.value; }).length;
        badge.textContent = active; badge.hidden = !active;
        try {   // keep the filters across a page refresh
            var q = new URLSearchParams();
            if (input.value.trim()) q.set('search', input.value.trim());
            sels.forEach(function (s) { if (s.value) q.set(param[s.getAttribute('data-attr')], s.value); });
            history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
        } catch (e) {}
    }
    function setMenu(open) { menu.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }

    input.addEventListener('input', apply);
    sels.forEach(function (s) { s.addEventListener('change', apply); });
    btn.addEventListener('click', function (e) { e.stopPropagation(); setMenu(menu.hidden); });
    menu.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', function () { setMenu(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setMenu(false); });
    document.getElementById('pfClear').addEventListener('click', function () { sels.forEach(function (s) { s.value = ''; }); apply(); });
    apply();
})();
</script>
<?php endif; ?>
</div>
