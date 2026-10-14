<?php
// ============================================================
// Directpath HRMS — Employee COE View (coe_view.php)
// Para sa employee side lang — hindi sila pwedeng tumingin
// ng COE ng ibang empleyado. Dapat approved at may coe_sent=1
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('user', 'employee'); // employee side lang
require_once '../../includes/coe_letter.php';

$db    = getDB();
$reqId = (int)($_GET['req'] ?? 0);
$empId = $_SESSION['employee_id'];

if (!$reqId) {
    echo '<p style="text-align:center;padding:40px;font-family:sans-serif;color:red;">Invalid request.</p>';
    exit;
}

// ── Fetch the request — dapat sa sariling empleyado lang ──────
// SECURITY: Palaging i-check na ang employee_id ay sa naka-login
$stmt = $db->prepare("
    SELECT er.*
    FROM   employee_requests er
    WHERE  er.id = ?
      AND  er.employee_id = ?
      AND  er.request_type = 'coe'
      AND  er.status = 'approved'
");
$stmt->execute([$reqId, $empId]);
$req = $stmt->fetch();

// Kung hindi found o hindi approved, i-block ang access
if (!$req) {
    echo '<div style="text-align:center;padding:60px;font-family:sans-serif;">
            <div style="font-size:48px;margin-bottom:12px;"></div>
            <div style="font-size:16px;font-weight:700;color:#1A1D26;margin-bottom:8px;">Access Denied</div>
            <div style="font-size:13px;color:#6B7280;">This COE is not available or has not been approved yet.</div>
            <a href="requests.php" style="display:inline-block;margin-top:20px;padding:10px 24px;background:#CD1E27;color:#fff;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;">← Back to Requests</a>
          </div>';
    exit;
}

// ── Kung may coe_file, i-redirect sa file download ───────────
// Mas maganda kung may actual na PDF file
if (!empty($req['coe_file'])) {
    $filePath = __DIR__ . '/../../uploads/coe/' . basename($req['coe_file']);
    if (file_exists($filePath)) {
        // Kung PDF, buksan sa bagong tab
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="COE_' . $empId . '.pdf"');
        readfile($filePath);
        exit;
    }
}

// ── Kung walang file pero coe_sent=1, ipakita ang HTML version ─
if (empty($req['coe_sent']) && empty($req['coe_file'])) {
    echo '<div style="text-align:center;padding:60px;font-family:sans-serif;">
            <div style="font-size:48px;margin-bottom:12px;"></div>
            <div style="font-size:16px;font-weight:700;color:#1A1D26;margin-bottom:8px;">COE Being Prepared</div>
            <div style="font-size:13px;color:#6B7280;">Your COE is being generated. Please refresh in a moment.</div>
            <a href="requests.php" style="display:inline-block;margin-top:20px;padding:10px 24px;background:#CD1E27;color:#fff;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;">← Back to Requests</a>
          </div>';
    exit;
}

// ── Fetch employee data ───────────────────────────────────────
$stmt = $db->prepare("
    SELECT u.*,
           CONCAT(u.first_name,' ',COALESCE(NULLIF(u.middle_name,''),''),' ',u.last_name) AS full_name
    FROM   users u
    WHERE  u.employee_id = ?
");
$stmt->execute([$empId]);
$emp = $stmt->fetch();

if (!$emp) {
    echo '<p style="text-align:center;padding:40px;font-family:sans-serif;color:red;">Employee record not found.</p>';
    exit;
}

// Linisin ang full name (tanggalin ang double spaces)
$empFullName = trim(preg_replace('/\s+/', ' ', $emp['full_name']));

// ── Compute years of service ──────────────────────────────────
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

// ── Reference number ──────────────────────────────────────────
$refNo = 'COE-' . date('Ymd') . '-' . str_pad($emp['id'] ?? $reqId, 4, '0', STR_PAD_LEFT);

// ── Fetch HR Manager who issued this ─────────────────────────
// Hinahanap ang pinaka-recent na admin/superadmin
$stmtHR = $db->prepare("
    SELECT u.first_name, u.middle_name, u.last_name, u.position, u.profile_photo
    FROM   users u
    JOIN   user_roles ur ON ur.employee_id = u.employee_id
    JOIN   roles r ON r.id = ur.role_id
    WHERE  r.role_name IN ('admin','superadmin')
    ORDER BY u.id ASC
    LIMIT 1
");
$stmtHR->execute();
$hrUser = $stmtHR->fetch();

$hrFullName = $hrUser
    ? trim(preg_replace('/\s+/', ' ',
        ($hrUser['first_name'] ?? '') . ' ' .
        ($hrUser['middle_name'] ?? '') . ' ' .
        ($hrUser['last_name']   ?? '')))
    : 'HR Manager';

$hrPosition = $hrUser['position'] ?? 'HR Manager & Authorized Signatory';

// ── Logo as base64 for print reliability ─────────────────────
$logoPath = __DIR__ . '/../../assets/img/logo.png';
$logoBase64 = '';
if (file_exists($logoPath)) {
    $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
}

// ── E-sig as base64 ───────────────────────────────────────────
$esigPath = __DIR__ . '/../../assets/img/e-sig.png';
$esigBase64 = '';
if (file_exists($esigPath)) {
    $esigBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($esigPath));
}

// ── HR Profile photo as base64 ────────────────────────────────
$hrPhotoBase64 = '';
if (!empty($hrUser['profile_photo'])) {
    $hrPhotoPath = __DIR__ . '/../../uploads/profile_photos/' . basename($hrUser['profile_photo']);
    if (file_exists($hrPhotoPath)) {
        $ext = strtolower(pathinfo($hrPhotoPath, PATHINFO_EXTENSION));
        $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : 'image/png';
        $hrPhotoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($hrPhotoPath));
    }
}

// ── Log na na-view ng employee ang COE ───────────────────────
auditLog('View COE', 'Requests', "Employee viewed COE for request ID: $reqId");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Certificate of Employment — <?= htmlspecialchars($empFullName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
<style>
*  { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Poppins', sans-serif;
  background: #F4F5F7;
  padding: 20px;
  font-size: 12px;
  color: #1A1D26;
}

/* ── Action Bar ── */
.action-bar {
  max-width: 760px;
  margin: 0 auto 14px;
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}
.btn-print {
  background: #CD1E27; color: #fff;
  border: none; padding: 9px 22px;
  border-radius: 7px;
  font-family: 'Poppins', sans-serif;
  font-size: 12px; font-weight: 600; cursor: pointer;
  display: flex; align-items: center; gap: 6px;
}
.btn-back {
  background: #fff; color: #1A1D26;
  border: 1px solid #E5E7EB;
  padding: 9px 22px; border-radius: 7px;
  font-family: 'Poppins', sans-serif;
  font-size: 12px; font-weight: 600;
  text-decoration: none;
  display: flex; align-items: center; gap: 6px;
}
.btn-download {
  background: #1D4ED8; color: #fff;
  border: none; padding: 9px 22px;
  border-radius: 7px;
  font-family: 'Poppins', sans-serif;
  font-size: 12px; font-weight: 600; cursor: pointer;
  display: flex; align-items: center; gap: 6px;
  text-decoration: none;
}

/* ── Notice Banner — para alam ng empleyado na official ── */
.notice-banner {
  max-width: 760px;
  margin: 0 auto 14px;
  background: #EFF6FF;
  border: 1px solid #BFDBFE;
  border-radius: 8px;
  padding: 10px 14px;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  font-size: 11px;
  color: #1E40AF;
}
.notice-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }

/* ── COE Document ── */
.coe {
  max-width: 760px;
  margin: 0 auto;
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.10);
  overflow: hidden;
}

/* Header */
.coe-header {
  background: #1A1D26;
  padding: 20px 32px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}
.logo-area { display: flex; align-items: center; gap: 12px; }
.logo-img {
  width: 44px; height: 44px;
  background: #fff;
  border-radius: 10px;
  padding: 4px;
  object-fit: contain;
  flex-shrink: 0;
}
.logo-fallback {
  width: 44px; height: 44px;
  background: #CD1E27;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.logo-name { font-size: 17px; font-weight: 700; color: #fff; }
.logo-name span { color: #CD1E27; }
.logo-sub  { font-size: 10px; color: rgba(255,255,255,0.4); }
.doc-info  { text-align: right; color: #fff; }
.doc-label { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: rgba(255,255,255,0.4); }
.doc-val   { font-size: 12px; font-weight: 600; }

.accent-bar { height: 4px; background: linear-gradient(90deg, #CD1E27, #A8181F); }

/* Body */
.coe-body { padding: 28px 32px; }

.coe-title {
  font-family: 'Playfair Display', serif;
  font-size: 24px; font-weight: 700;
  text-align: center; color: #1A1D26;
  margin-bottom: 4px;
}
.coe-subtitle {
  text-align: center;
  font-size: 9px; color: #9CA3AF;
  text-transform: uppercase; letter-spacing: 2px;
  margin-bottom: 20px;
}
.coe-divider {
  display: flex; align-items: center; gap: 10px; margin-bottom: 20px;
}
.coe-divider::before, .coe-divider::after {
  content: ''; flex: 1; height: 1px; background: #E5E7EB;
}
.coe-divider span { color: #CD1E27; font-size: 16px; }

.coe-text { font-size: 12px; line-height: 1.75; color: #374151; }
.coe-text p { margin-bottom: 12px; }

/* Employee detail box */
.emp-details {
  background: #F9FAFB;
  border: 1px solid #E5E7EB;
  border-radius: 8px;
  padding: 14px 18px;
  margin: 14px 0;
}
.detail-row {
  display: flex; align-items: baseline;
  margin-bottom: 7px; font-size: 12px;
}
.detail-row:last-child { margin-bottom: 0; }
.detail-label {
  width: 170px; flex-shrink: 0;
  font-weight: 600; color: #6B7280;
  font-size: 10px; text-transform: uppercase;
  letter-spacing: 0.4px;
}
.detail-val { color: #1A1D26; font-weight: 600; }

/* Signature area */
.sig-area {
  display: flex;
  justify-content: space-around;
  align-items: flex-end;
  margin-top: 36px;
  padding-top: 16px;
  border-top: 1px dashed #E5E7EB;
  flex-wrap: wrap;
  gap: 24px;
}
.sig-block { text-align: center; min-width: 180px; }
.sig-photo-wrap {
  position: relative;
  width: 160px; height: 120px;
  margin: 0 auto 4px;
}
.sig-photo {
  width: 90px; height: 90px;
  object-fit: cover; border-radius: 50%;
  position: absolute; left: 0; top: 15px; z-index: 2;
  border: 2px solid #E5E7EB;
}
.sig-esig {
  width: 130px; height: 120px;
  object-fit: contain;
  position: absolute; left: 30px; top: 0; z-index: 3;
  opacity: 0.95;
}
.sig-line {
  border-top: 1.5px solid #1A1D26;
  padding-top: 7px; margin-top: 8px;
  font-size: 13px; font-weight: 700;
  min-width: 200px;
}
.sig-role  { font-size: 10px; color: #9CA3AF; margin-top: 2px; }

/* Footer */
.coe-footer {
  background: #F9FAFB;
  border-top: 1px solid #E5E7EB;
  padding: 8px 32px;
  display: flex;
  justify-content: space-between;
  font-size: 9px;
  color: #9CA3AF;
  flex-wrap: wrap;
  gap: 4px;
}

/* ── PRINT STYLES ── */
@media print {
  @page { size: A4 portrait; margin: 8mm 10mm; }
  * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  html, body { background: #fff !important; padding: 0 !important; }
  .action-bar, .notice-banner { display: none !important; }
  .coe {
    max-width: 100% !important; width: 100% !important;
    box-shadow: none !important; border-radius: 0 !important; margin: 0 !important;
  }
  .coe-body, .sig-area, .emp-details { page-break-inside: avoid; }
}

@media (max-width: 600px) {
  body { padding: 10px; }
  .coe-header, .coe-body { padding: 14px 16px; }
  .detail-label { width: 130px; }
  .sig-area { flex-direction: column; align-items: center; }
}
<?= coeLetterCss() ?>
</style>
</head>
<body>

<!-- Action Bar — hindi lalabas sa print -->
<div class="action-bar">
    <a href="requests.php" class="btn-back">← Back to Requests</a>
    <button class="btn-print" onclick="window.print()">Print COE</button>
    <?php if (!empty($req['coe_file'])): ?>
    <!-- Kung may actual na file, pwedeng i-download -->
    <a href="../../uploads/coe/<?= htmlspecialchars(basename($req['coe_file'])) ?>"
       download class="btn-download">Download PDF</a>
    <?php endif; ?>
</div>

<?= coeLetterHtml($emp, $req['purpose'] ?? null) ?>

<script src="../../assets/js/a4-fit.js"></script>
</body>
</html>