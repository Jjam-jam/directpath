<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin');
require_once '../../includes/coe_letter.php';
$returnPath = 'requests.php';

$db     = getDB();
$empId  = sanitize($_GET['emp'] ?? '');
$reqId  = (int)($_GET['req'] ?? 0);

$stmt = $db->prepare("SELECT * FROM users WHERE employee_id=?");
$stmt->execute([$empId]);
$emp = $stmt->fetch();

if (!$emp) {
    echo '<p style="text-align:center;padding:40px;font-family:sans-serif;color:red;">Employee not found.</p>';
    exit;
}

$empFullName = trim(($emp['first_name'] ?? '') . ' ' . ($emp['middle_name'] ? $emp['middle_name'] . ' ' : '') . ($emp['last_name'] ?? ''));

// Handle "Send to Employee" action
$sentMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'send_coe' && $reqId) {
        $db->prepare("UPDATE employee_requests SET coe_sent=1 WHERE id=? AND employee_id=?")
           ->execute([$reqId, $empId]);
        auditLog('Send COE', 'Requests', "COE sent to $empId for request ID $reqId");
        $sentMsg = 'COE marked as sent to employee.';
    }
    if ($_POST['action'] === 'no_send' && $reqId) {
        $sentMsg = 'COE not sent to employee (admin only copy).';
    }
}

// Check if this request already has coe_sent
$isSent = false;
if ($reqId) {
    $chk = $db->prepare("SELECT coe_sent FROM employee_requests WHERE id=?");
    $chk->execute([$reqId]);
    $row = $chk->fetch();
    if ($row) $isSent = (bool)$row['coe_sent'];
}

// Years of service calculation
$yearsOfService = '—';
if (!empty($emp['date_hired'])) {
    $start = new DateTime($emp['date_hired']);
    $now   = new DateTime();
    $diff  = $start->diff($now);
    $y = $diff->y;
    $m = $diff->m;
    $yearsOfService = '';
    if ($y > 0) $yearsOfService = $y . ' year' . ($y > 1 ? 's' : '');
    if ($m > 0) $yearsOfService .= ($y > 0 ? ' and ' : '') . $m . ' month' . ($m > 1 ? 's' : '');
    if (!$yearsOfService) $yearsOfService = 'less than 1 month';
}

$coePurpose = null;
if ($reqId) {
    $pq = $db->prepare("SELECT purpose FROM employee_requests WHERE id=? AND employee_id=? AND request_type='coe'");
    $pq->execute([$reqId, $empId]);
    $coePurpose = $pq->fetchColumn() ?: null;
}

$refNo = 'COE-' . date('Ymd') . '-' . str_pad($emp['id'] ?? 0, 4, '0', STR_PAD_LEFT);

// Fetch HR Manager info (logged-in admin/hr user)
$stmtHR = $db->prepare("SELECT first_name, middle_name, last_name, profile_photo FROM users WHERE employee_id = ?");
$stmtHR->execute([$_SESSION['employee_id']]);
$hrUser = $stmtHR->fetch();
$hrFullName = trim(
    ($hrUser['first_name'] ?? '') . ' ' . 
    ($hrUser['middle_name'] ? $hrUser['middle_name'] . ' ' : '') . 
    ($hrUser['last_name'] ?? '')
);
$hrPhoto = !empty($hrUser['profile_photo']) 
    ? '/directpath_2_updated/assets/uploads/profiles/' . $hrUser['profile_photo'] 
    : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>COE — <?= htmlspecialchars($empFullName) ?></title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
<style>
* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}
body {
  font-family: "Poppins", sans-serif;
  background: #f4f5f7;
  padding: 20px;
  font-size: 12px;
  color: #1a1d26;
}
.action-bar {
  max-width: 760px;
  margin: 0 auto 14px;
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}
.btn-print {
  background: #cd1e27;
  color: #fff;
  border: none;
  padding: 9px 20px;
  border-radius: 7px;
  font-family: "Poppins", sans-serif;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.btn-back {
  background: #fff;
  color: #1a1d26;
  border: 1px solid #e5e7eb;
  padding: 9px 20px;
  border-radius: 7px;
  font-family: "Poppins", sans-serif;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
}
.btn-send {
  background: #16a34a;
  color: #fff;
  border: none;
  padding: 9px 20px;
  border-radius: 7px;
  font-family: "Poppins", sans-serif;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.btn-nosend {
  background: #fff;
  color: #374151;
  border: 1px solid #e5e7eb;
  padding: 9px 20px;
  border-radius: 7px;
  font-family: "Poppins", sans-serif;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.alert-success {
  background: #dcfce7;
  color: #166534;
  border: 1px solid #bbf7d0;
  padding: 10px 14px;
  border-radius: 7px;
  font-size: 12px;
  margin-bottom: 12px;
  max-width: 760px;
  margin-left: auto;
  margin-right: auto;
}
.badge-sent {
  background: #dcfce7;
  color: #166534;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 10px;
  font-weight: 600;
}
.coe {
  max-width: 760px;
  margin: 0 auto;
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
  overflow: hidden;
}
.coe-header {
  background: #1a1d26;
  padding: 18px 32px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}
.logo-area {
  display: flex;
  align-items: center;
  gap: 12px;
}
.logo-icon {
  width: 42px;
  height: 42px;
  background: #cd1e27;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.logo-icon svg {
  width: 22px;
  height: 22px;
}
.logo-name {
  font-size: 17px;
  font-weight: 700;
  color: #fff;
}
.logo-name span {
  color: #cd1e27;
}
.logo-sub {
  font-size: 10px;
  color: rgba(255, 255, 255, 0.45);
}
.doc-info {
  text-align: right;
  color: #fff;
}
.doc-label {
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: rgba(255, 255, 255, 0.45);
}
.doc-val {
  font-size: 12px;
  font-weight: 600;
}
.accent-bar {
  height: 4px;
  background: linear-gradient(90deg, #cd1e27, #a8181f);
}
.coe-body {
  padding: 28px 32px;
}
.coe-title {
  font-family: "Playfair Display", serif;
  font-size: 24px;
  font-weight: 700;
  text-align: center;
  color: #1a1d26;
  margin-bottom: 4px;
}
.coe-subtitle {
  text-align: center;
  font-size: 9px;
  color: #9ca3af;
  text-transform: uppercase;
  letter-spacing: 2px;
  margin-bottom: 20px;
}
.coe-divider {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 20px;
}
.coe-divider::before,
.coe-divider::after {
  content: "";
  flex: 1;
  height: 1px;
  background: #e5e7eb;
}
.coe-divider span {
  color: #cd1e27;
  font-size: 16px;
}
.coe-text {
  font-size: 12px;
  line-height: 1.75;
  color: #374151;
}
.coe-text p {
  margin-bottom: 12px;
}
.emp-details {
  background: #f9fafb;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 14px 18px;
  margin: 14px 0;
}
.detail-row {
  display: flex;
  align-items: baseline;
  margin-bottom: 6px;
  font-size: 12px;
}
.detail-row:last-child {
  margin-bottom: 0;
}
.detail-label {
  width: 160px;
  flex-shrink: 0;
  font-weight: 600;
  color: #6b7280;
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.detail-val {
  color: #1a1d26;
  font-weight: 600;
}
.sig-area {
  margin-top: 28px;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 40px;
  padding-top: 16px;
  border-top: 1px dashed #e5e7eb;
}
.sig-block {
  text-align: center;
}
.sig-stamp {
  width: 70px;
  height: 70px;
  border: 2px solid #cd1e27;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto;
  color: #cd1e27;
  font-size: 8px;
  font-weight: 700;
  text-align: center;
  text-transform: uppercase;
  letter-spacing: 1px;
  line-height: 1.2;
}
.sig-line {
  border-top: 1px solid #1a1d26;
  padding-top: 7px;
  margin-top: 32px;
  font-size: 11px;
  font-weight: 700;
}
.sig-role {
  font-size: 10px;
  color: #9ca3af;
  margin-top: 2px;
}
.coe-footer {
  background: #f9fafb;
  border-top: 1px solid #e5e7eb;
  padding: 8px 32px;
  display: flex;
  justify-content: space-between;
  font-size: 9px;
  color: #9ca3af;
  flex-wrap: wrap;
  gap: 4px;
}
@media print {
  @page {
    size: A4 portrait;
    margin: 8mm 10mm;
  }
  * {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
  html,
  body {
    background: #fff !important;
    padding: 0 !important;
  }
  .action-bar,
  .alert-success {
    display: none !important;
  }
  .coe {
    max-width: 100% !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    margin: 0 !important;
  }
  .coe-body,
  .sig-area,
  .emp-details {
    page-break-inside: avoid;
  }
}
@media (max-width: 600px) {
  body {
    padding: 10px;
  }
  .coe-header,
  .coe-body {
    padding: 14px 16px;
  }
  .detail-label {
    width: 120px;
  }
  .sig-area {
    grid-template-columns: 1fr;
    gap: 20px;
  }
}

<?= '' ?>
<?= coeLetterCss() ?>
</style>
</head>
<body>

<?php if ($sentMsg): ?>
<div class="alert-success"><?= htmlspecialchars($sentMsg) ?></div>
<?php endif; ?>

<!-- Action Bar -->
<div class="action-bar no-print">
    <a href="<?= htmlspecialchars($returnPath, ENT_QUOTES, 'UTF-8') ?>" class="btn-back">← Back</a>
    <button class="btn-print" onclick="window.print()">Print COE</button>

    <?php if ($reqId): ?>
        <?php if (!$isSent): ?>
        <!-- Send to Employee option -->
        <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="send_coe">
            <button type="submit" class="btn-send">Send to Employee</button>
        </form>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="no_send">
            <button type="submit" class="btn-nosend">&times; Do Not Send</button>
        </form>
        <?php else: ?>
        <span class="badge-sent">Sent to Employee</span>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Certificate -->
<?= coeLetterHtml($emp, $coePurpose) ?>

<script src="../../assets/js/a4-fit.js"></script>
</body>
</html>
