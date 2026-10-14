<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('payroll_manager', 'employee');
header('Location: ../admin/payslip.php?id=' . (int)($_GET['id'] ?? 0));
exit;
$returnPath = 'payroll.php';

$db  = getDB();
$id  = (int)($_GET['id'] ?? 0);

// Employees can only view their own
if ($_SESSION['role'] === 'employee') {
    $stmt = $db->prepare("SELECT p.*, e.*, CONCAT(e.first_name, ' ', e.last_name) AS name FROM payroll p JOIN users e ON p.employee_id=e.employee_id WHERE p.id=? AND p.employee_id=?");
    $stmt->execute([$id, $_SESSION['employee_id']]);
} else {
    $stmt = $db->prepare("SELECT p.*, e.*, CONCAT(e.first_name, ' ', e.last_name) AS name FROM payroll p JOIN users e ON p.employee_id=e.employee_id WHERE p.id=?");
    $stmt->execute([$id]);
}

$p = $stmt->fetch();
if (!$p) { echo '<p style="text-align:center; padding:40px; font-family:sans-serif;">Payslip not found.</p>'; exit; }
$employerShares = payrollEmployerShares($p);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payslip — <?= htmlspecialchars($p['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: #F4F5F7; padding: 20px; }

.payslip-wrapper {
    max-width: 760px;
    margin: 0 auto;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.1);
    overflow: hidden;
}

/* ── Header / Logo ── */
.payslip-header {
    background: #1A1D26;
    padding: 28px 36px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.logo-area {
    display: flex;
    align-items: center;
    gap: 14px;
}

.logo-icon {
    width: 48px;
    height: 48px;
    background: #CD1E27;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.logo-icon svg { width: 26px; height: 26px; }

.logo-text-area { color: #fff; }
.logo-name  { font-size: 20px; font-weight: 700; }
.logo-name span { color: #CD1E27; }
.logo-sub   { font-size: 11px; color: rgba(255,255,255,0.5); }

.payslip-title-area { text-align: right; color: #fff; }
.payslip-label { font-size: 22px; font-weight: 700; letter-spacing: 1px; }
.payslip-period { font-size: 12px; color: rgba(255,255,255,0.6); margin-top: 4px; }

/* ── Body ── */
.payslip-body { padding: 28px 36px; }

/* Employee Info */
.emp-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px 24px;
    background: #F9FAFB;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 24px;
}

.emp-field label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #9CA3AF; }
.emp-field span  { font-size: 13px; font-weight: 500; color: #1A1D26; display: block; }

/* Pay Table */
.pay-section-title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #9CA3AF;
    margin-bottom: 8px;
    margin-top: 20px;
}

.pay-table { width: 100%; border-collapse: collapse; }
.pay-table td { padding: 8px 12px; font-size: 13px; border-bottom: 1px solid #F3F4F6; }
.pay-table td:last-child { text-align: right; font-weight: 500; }
.pay-table tr.total td {
    font-weight: 700;
    font-size: 14px;
    background: #F9FAFB;
    border-top: 2px solid #E5E7EB;
}
.pay-table tr.deduction td:last-child { color: #CD1E27; }
.pay-table tr.net-pay td {
    background: #1A1D26;
    color: #fff;
    font-size: 16px;
    font-weight: 700;
    border-radius: 0;
}

/* Summary Box */
.net-box {
    background: linear-gradient(135deg, #1A1D26, #CD1E27);
    border-radius: 10px;
    padding: 20px 24px;
    margin-top: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: #fff;
}

.net-label { font-size: 13px; opacity: 0.8; }
.net-amount { font-size: 28px; font-weight: 700; }

/* Signature Area */
.signature-area {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 32px;
    margin-top: 40px;
    padding-top: 24px;
    border-top: 1px dashed #E5E7EB;
}

.sig-block { text-align: center; }
.sig-line { border-top: 1px solid #1A1D26; padding-top: 8px; margin-top: 40px; font-size: 12px; font-weight: 600; }
.sig-role { font-size: 11px; color: #9CA3AF; }

/* Footer */
.payslip-footer {
    background: #F9FAFB;
    border-top: 1px solid #E5E7EB;
    padding: 14px 36px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 11px;
    color: #9CA3AF;
}

/* Print button */
.print-bar {
    max-width: 760px;
    margin: 0 auto 16px;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.btn-print {
    background: #CD1E27;
    color: #fff;
    border: none;
    padding: 10px 22px;
    border-radius: 8px;
    font-size: 13px;
    font-family: 'Poppins', sans-serif;
    font-weight: 600;
    cursor: pointer;
}

.btn-back {
    background: #fff;
    color: #1A1D26;
    border: 1px solid #E5E7EB;
    padding: 10px 22px;
    border-radius: 8px;
    font-size: 13px;
    font-family: 'Poppins', sans-serif;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
}

@media print {
    body { background: #fff; padding: 0; }
    .print-bar { display: none; }
    .payslip-wrapper { box-shadow: none; border-radius: 0; }
}

@media (max-width: 600px) {
    .payslip-header { flex-direction: column; gap: 12px; }
    .payslip-title-area { text-align: left; }
    .emp-info-grid { grid-template-columns: 1fr; }
    .signature-area { grid-template-columns: 1fr; }
    .payslip-body { padding: 20px; }
    .payslip-header { padding: 20px; }
}
</style>
</head>
<body>

<div class="print-bar no-print">
    <a href="<?= htmlspecialchars($returnPath, ENT_QUOTES, 'UTF-8') ?>" class="btn-back">← Back</a>
    <button class="btn-print" onclick="window.print()">Print Payslip</button>
</div>

<div class="payslip-wrapper">

    <!-- Header with Logo -->
    <div class="payslip-header">
        <div class="logo-area">
            <div class="logo-icon">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="white" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="logo-text-area">
                <div class="logo-name">Direct<span>path</span></div>
                <div class="logo-sub">HRMS Philippines</div>
            </div>
        </div>
        <div class="payslip-title-area">
            <div class="payslip-label">PAYSLIP</div>
            <div class="payslip-period">
                <?= formatDate($p['payroll_period_start']) ?> – <?= formatDate($p['payroll_period_end']) ?>
            </div>
            <div class="payslip-period">Generated: <?= date('F j, Y') ?></div>
        </div>
    </div>

    <!-- Body -->
    <div class="payslip-body">

        <!-- Employee Info -->
        <div class="emp-info-grid">
            <div class="emp-field">
                <label>Employee Name</label>
                <span><?= htmlspecialchars($p['name']) ?></span>
            </div>
            <div class="emp-field">
                <label>Employee ID</label>
                <span><?= htmlspecialchars($p['employee_id']) ?></span>
            </div>
            <div class="emp-field">
                <label>Department</label>
                <span><?= htmlspecialchars($p['department']) ?></span>
            </div>
            <div class="emp-field">
                <label>Position</label>
                <span><?= htmlspecialchars($p['position']) ?></span>
            </div>
            <div class="emp-field">
                <label>SSS No.</label>
                <span><?= htmlspecialchars($p['sss_no'] ?? '—') ?></span>
            </div>
            <div class="emp-field">
                <label>PhilHealth No.</label>
                <span><?= htmlspecialchars($p['philhealth_no'] ?? '—') ?></span>
            </div>
            <div class="emp-field">
                <label>Pag-IBIG No.</label>
                <span><?= htmlspecialchars($p['pagibig_no'] ?? '—') ?></span>
            </div>
            <div class="emp-field">
                <label>Days Worked</label>
                <span><?= $p['days_worked'] ?> day(s)</span>
            </div>
        </div>

        <!-- Earnings -->
        <div class="pay-section-title">Earnings</div>
        <table class="pay-table">
            <tr>
                <td>Basic Salary</td>
                <td><?= formatMoney((float)$p['basic_salary']) ?></td>
            </tr>
            <?php if ((float)$p['allowances'] > 0): ?>
            <tr>
                <td>Allowances</td>
                <td><?= formatMoney((float)$p['allowances']) ?></td>
            </tr>
            <?php endif; ?>
            <?php if ((float)$p['bonuses'] > 0): ?>
            <tr>
                <td>Bonuses / Incentives</td>
                <td><?= formatMoney((float)$p['bonuses']) ?></td>
            </tr>
            <?php endif; ?>
            <tr class="total">
                <td>GROSS PAY</td>
                <td><?= formatMoney((float)$p['gross_pay']) ?></td>
            </tr>
        </table>

        <!-- Deductions -->
        <div class="pay-section-title">Deductions</div>
        <table class="pay-table">
            <tr class="deduction">
                <td>SSS Contribution (5%)</td>
                <td><?= formatMoney((float)$p['sss']) ?></td>
            </tr>
            <tr class="deduction">
                <td>PhilHealth (2.5%)</td>
                <td><?= formatMoney((float)$p['philhealth']) ?></td>
            </tr>
            <tr class="deduction">
                <td>Pag-IBIG / HDMF (2%, max ₱200)</td>
                <td><?= formatMoney((float)$p['pagibig']) ?></td>
            </tr>
            <tr class="deduction">
                <td>Withholding Tax</td>
                <td><?= formatMoney((float)$p['withholding_tax']) ?></td>
            </tr>
            <?php if ((float)$p['other_deductions'] > 0): ?>
            <tr class="deduction">
                <td>Other Deductions</td>
                <td><?= formatMoney((float)$p['other_deductions']) ?></td>
            </tr>
            <?php endif; ?>
            <tr class="total">
                <td>TOTAL DEDUCTIONS</td>
                <td style="color:#CD1E27;"><?= formatMoney((float)$p['total_deductions']) ?></td>
            </tr>
        </table>

        <!-- Employer contributions do not reduce employee net pay. -->
        <div class="pay-section-title">Employer Contributions (company paid)</div>
        <table class="pay-table">
            <tr><td>SSS employer share</td><td><?= formatMoney($employerShares['sss']) ?></td></tr>
            <tr><td>SSS Employees’ Compensation (ECP)</td><td><?= formatMoney($employerShares['ec']) ?></td></tr>
            <tr><td>PhilHealth employer share</td><td><?= formatMoney($employerShares['philhealth']) ?></td></tr>
            <tr><td>Pag-IBIG employer share</td><td><?= formatMoney($employerShares['pagibig']) ?></td></tr>
            <tr class="total"><td>TOTAL EMPLOYER CONTRIBUTIONS</td><td><?= formatMoney($employerShares['total']) ?></td></tr>
        </table>

        <!-- Net Pay Box -->
        <div class="net-box">
            <div>
                <div class="net-label">NET PAY</div>
                <div style="font-size:12px; opacity:0.6; margin-top:2px;">For the period <?= formatDate($p['payroll_period_start']) ?> – <?= formatDate($p['payroll_period_end']) ?></div>
            </div>
            <div class="net-amount"><?= formatMoney((float)$p['net_salary']) ?></div>
        </div>

        <!-- Signature -->
        <div class="signature-area">
            <div class="sig-block">
                <div class="sig-line">Employee's Signature</div>
                <div class="sig-role"><?= htmlspecialchars($p['name']) ?></div>
            </div>
            <div class="sig-block">
                <div class="sig-line">Authorized Signatory</div>
                <div class="sig-role">HR Manager / Admin</div>
            </div>
        </div>

    </div><!-- payslip-body -->

    <!-- Footer -->
    <div class="payslip-footer">
        <span>Directpath HRMS Philippines • <?= SITE_NAME ?></span>
        <span>Generated on <?= date('F j, Y \a\t h:i A') ?> • Status: <strong><?= ucfirst($p['status']) ?></strong></span>
    </div>

</div><!-- payslip-wrapper -->

</body>
</html>
