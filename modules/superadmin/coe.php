<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('hr_admin');
header('Location: ../admin/coe.php');
exit;
$returnPath = 'requests.php';

$db     = getDB();
$empId  = sanitize($_GET['emp'] ?? '');

$stmt = $db->prepare("SELECT * FROM users WHERE employee_id=?");
$stmt->execute([$empId]);
$emp  = $stmt->fetch();

if (!$emp) { echo '<p style="text-align:center; padding:40px; font-family:sans-serif;">Employee not found.</p>'; exit; }

$yearsOfService = '';
if (!empty($emp['date_hired'])) {
    $start = new DateTime($emp['date_hired']);
    $now   = new DateTime();
    $diff  = $start->diff($now);
    $y = $diff->y;
    $m = $diff->m;
    if ($y > 0) $yearsOfService = $y . ' year' . ($y > 1 ? 's' : '');
    if ($m > 0) $yearsOfService .= ($y > 0 ? ' and ' : '') . $m . ' month' . ($m > 1 ? 's' : '');
    if (!$yearsOfService) $yearsOfService = 'less than 1 month';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Certificate of Employment — <?= htmlspecialchars($emp['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@400;600;700&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: #F4F5F7; padding: 20px; }

.coe-wrapper {
    max-width: 720px;
    margin: 0 auto;
    background: #fff;
    box-shadow: 0 4px 24px rgba(0,0,0,0.1);
    border-radius: 8px;
    overflow: hidden;
}

/* ── Letterhead ── */
.coe-letterhead {
    background: #1A1D26;
    padding: 28px 40px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.logo-area { display: flex; align-items: center; gap: 14px; }
.logo-icon {
    width: 48px; height: 48px;
    background: #CD1E27;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
}
.logo-icon svg { width: 26px; height: 26px; }
.logo-name  { font-size: 20px; font-weight: 700; color: #fff; }
.logo-name span { color: #CD1E27; }
.logo-sub   { font-size: 11px; color: rgba(255,255,255,0.5); }

.coe-doc-info { text-align: right; color: #fff; }
.coe-doc-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: rgba(255,255,255,0.5); }
.coe-doc-date  { font-size: 14px; font-weight: 500; }

/* Red accent bar */
.coe-accent-bar { height: 5px; background: linear-gradient(90deg, #CD1E27, #A8181F); }

/* ── Body ── */
.coe-body {
    padding: 40px 50px;
}

.coe-title {
    font-family: 'Playfair Display', serif;
    font-size: 26px;
    font-weight: 700;
    text-align: center;
    color: #1A1D26;
    margin-bottom: 6px;
    letter-spacing: 0.5px;
}

.coe-subtitle {
    text-align: center;
    font-size: 12px;
    color: #9CA3AF;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin-bottom: 32px;
}

/* Decorative line */
.coe-divider {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 32px;
}
.coe-divider::before,
.coe-divider::after { content: ''; flex: 1; height: 1px; background: #E5E7EB; }
.coe-divider span { font-size: 18px; color: #CD1E27; }

/* Letter content */
.coe-content {
    font-size: 14px;
    line-height: 1.8;
    color: #374151;
}

.coe-content .to-whom {
    margin-bottom: 20px;
    font-size: 13px;
    color: #6B7280;
}

.coe-paragraph { margin-bottom: 16px; }

/* Employee info table */
.emp-details {
    background: #F9FAFB;
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    padding: 20px 24px;
    margin: 20px 0;
}

.emp-detail-row {
    display: flex;
    align-items: baseline;
    margin-bottom: 8px;
    font-size: 13px;
}

.emp-detail-row:last-child { margin-bottom: 0; }

.emp-detail-label {
    width: 160px;
    flex-shrink: 0;
    font-weight: 600;
    color: #6B7280;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.emp-detail-value { color: #1A1D26; font-weight: 500; }

/* Signature */
.coe-signature-area {
    margin-top: 40px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
}

.sig-block { text-align: center; }
.sig-line {
    border-top: 1px solid #1A1D26;
    padding-top: 10px;
    margin-top: 48px;
    font-weight: 700;
    font-size: 13px;
    color: #1A1D26;
}
.sig-role { font-size: 12px; color: #9CA3AF; }

.sig-stamp {
    width: 80px;
    height: 80px;
    border: 2px solid #CD1E27;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    color: #CD1E27;
    font-size: 10px;
    font-weight: 700;
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 1px;
    line-height: 1.2;
}

/* Footer */
.coe-footer {
    background: #F9FAFB;
    border-top: 1px solid #E5E7EB;
    padding: 12px 40px;
    font-size: 10px;
    color: #9CA3AF;
    display: flex;
    justify-content: space-between;
}

/* Print bar */
.print-bar {
    max-width: 720px;
    margin: 0 auto 16px;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}
.btn-print { background:#CD1E27; color:#fff; border:none; padding:10px 22px; border-radius:8px; font-size:13px; font-family:'Poppins',sans-serif; font-weight:600; cursor:pointer; }
.btn-back  { background:#fff; color:#1A1D26; border:1px solid #E5E7EB; padding:10px 22px; border-radius:8px; font-size:13px; font-family:'Poppins',sans-serif; font-weight:600; cursor:pointer; text-decoration:none; }

@media print {
    body { background: #fff; padding: 0; }
    .print-bar { display: none; }
    .coe-wrapper { box-shadow: none; border-radius: 0; }
}
</style>
</head>
<body>

<div class="print-bar no-print">
    <a href="<?= htmlspecialchars($returnPath, ENT_QUOTES, 'UTF-8') ?>" class="btn-back">← Back</a>
    <button class="btn-print" onclick="window.print()">Print COE</button>
</div>

<div class="coe-wrapper">

    <!-- Letterhead -->
    <div class="coe-letterhead">
        <div class="logo-area">
            <div class="logo-icon">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="white" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div>
                <div class="logo-name">Direct<span>path</span></div>
                <div class="logo-sub">HRMS Philippines</div>
            </div>
        </div>
        <div class="coe-doc-info">
            <div class="coe-doc-label">Document Date</div>
            <div class="coe-doc-date"><?= date('F j, Y') ?></div>
            <div class="coe-doc-label" style="margin-top:6px;">Ref No.</div>
            <div class="coe-doc-date">COE-<?= date('Ymd') ?>-<?= str_pad($emp['id'] ?? 0, 4, '0', STR_PAD_LEFT) ?></div>
        </div>
    </div>

    <div class="coe-accent-bar"></div>

    <!-- Body -->
    <div class="coe-body">

        <div class="coe-title">Certificate of Employment</div>
        <div class="coe-subtitle">Official Human Resources Document</div>

        <div class="coe-divider"><span></span></div>

        <div class="coe-content">
            <div class="coe-paragraph to-whom">
                <strong>To Whom It May Concern:</strong>
            </div>

            <div class="coe-paragraph">
                This is to certify that the following individual is a bona fide employee of <strong>Directpath Philippines</strong>, and has been continuously employed with the company under the following details:
            </div>

            <div class="emp-details">
                <div class="emp-detail-row">
                    <div class="emp-detail-label">Full Name</div>
                    <div class="emp-detail-value"><?= htmlspecialchars($emp['name']) ?></div>
                </div>
                <div class="emp-detail-row">
                    <div class="emp-detail-label">Employee ID</div>
                    <div class="emp-detail-value"><?= htmlspecialchars($emp['employee_id']) ?></div>
                </div>
                <div class="emp-detail-row">
                    <div class="emp-detail-label">Position</div>
                    <div class="emp-detail-value"><?= htmlspecialchars($emp['position']) ?></div>
                </div>
                <div class="emp-detail-row">
                    <div class="emp-detail-label">Department</div>
                    <div class="emp-detail-value"><?= htmlspecialchars($emp['department']) ?></div>
                </div>
                <div class="emp-detail-row">
                    <div class="emp-detail-label">Date of Employment</div>
                    <div class="emp-detail-value"><?= formatDate($emp['date_hired']) ?></div>
                </div>
                <div class="emp-detail-row">
                    <div class="emp-detail-label">Length of Service</div>
                    <div class="emp-detail-value"><?= $yearsOfService ?></div>
                </div>
                <div class="emp-detail-row">
                    <div class="emp-detail-label">Employment Status</div>
                    <div class="emp-detail-value"><?= ucfirst($emp['status']) ?></div>
                </div>
            </div>

            <div class="coe-paragraph">
                This certification is issued upon the request of the above-named employee for whatever legal purpose it may serve.
            </div>

            <div class="coe-paragraph">
                Issued this <strong><?= date('jS') ?> day of <?= date('F Y') ?></strong> at the offices of Directpath Philippines.
            </div>
        </div>

        <!-- Signature Area -->
        <div class="coe-signature-area">
            <div class="sig-block">
                <div class="sig-stamp">Company<br>Seal</div>
                <div class="sig-line">HR Manager / Admin</div>
                <div class="sig-role">Authorized Signatory</div>
            </div>
            <div class="sig-block">
                <div style="height:80px;"></div>
                <div class="sig-line"><?= htmlspecialchars($emp['name']) ?></div>
                <div class="sig-role">Employee's Conforme</div>
            </div>
        </div>

    </div><!-- coe-body -->

    <div class="coe-footer">
        <span>Directpath HRMS Philippines — Official Document</span>
        <span>This document is valid only with an authorized signature and company seal.</span>
    </div>

</div>

</body>
</html>
