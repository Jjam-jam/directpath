<?php
// Certificate of Employment letter - shared by the HR preview and the employee view.
// Letter content follows the company's COE format. The purpose line is filled from the
// reason the employee selected when requesting the COE.

const COE_COMPANY_NAME = 'Directpath Canada Consultation Inc.';
const COE_DEPARTMENT   = 'Human Resources Department';
const COE_FALLBACK_EMAIL = 'hr@directpath.com';   // used only when no HR account email exists

function coeEmploymentStatus(?string $dateHired): string {
    if (!$dateHired) return 'Regular';
    return (new DateTime($dateHired))->modify('+6 months') > new DateTime() ? 'Probation' : 'Regular';
}

function coeImageData(string $relative): string {
    $path = __DIR__ . '/../' . $relative;
    if (!is_file($path)) return '';
    $mime = str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg';
    return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path));
}

// Authorized signatory = the active HR Admin account (name, position and e-mail come from the system).
function coeSignatory(): array {
    $fallback = ['name' => 'Authorized Signatory', 'role' => 'HR Manager', 'email' => COE_FALLBACK_EMAIL];
    try {
        $r = getDB()->query("SELECT u.first_name, u.last_name, u.position, u.email FROM users u
                             JOIN user_roles ur ON ur.employee_id = u.employee_id JOIN roles r ON r.id = ur.role_id
                             WHERE r.role_name = 'hr_admin' AND u.status = 'active' ORDER BY u.id LIMIT 1")->fetch();
        if ($r) return ['name' => trim($r['first_name'] . ' ' . $r['last_name']), 'role' => $r['position'] ?: 'HR Manager', 'email' => $r['email'] ?: COE_FALLBACK_EMAIL];
    } catch (Throwable $e) {}
    return $fallback;
}

function coeLetterCss(): string {
    return <<<CSS
.cl-wrap{padding:0 12px 24px;}
.cl-page{position:relative;width:210mm;max-width:100%;min-height:297mm;margin:0 auto;background:#fff;color:#1A1D26;font-family:'Poppins',Arial,sans-serif;font-size:13px;line-height:1.6;border:1px solid #E5E7EB;box-shadow:0 4px 24px rgba(26,29,38,.10);padding:0 0 90px;overflow:hidden;}
.cl-head{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:26px 48px 16px;border-bottom:3px solid #CD1E27;}
.cl-brand{display:flex;align-items:center;gap:14px;}
.cl-brand img{height:54px;width:auto;}
.cl-brand .nm{font-size:16px;font-weight:700;line-height:1.2;}
.cl-brand .dp{font-size:11.5px;color:#6B7280;}
.cl-meta{text-align:right;font-size:11.5px;color:#6B7280;line-height:1.5;}
.cl-meta b{color:#1A1D26;}
.cl-body{padding:26px 48px 0;}
.cl-title{text-align:center;font-size:19px;font-weight:700;letter-spacing:1.5px;margin:4px 0 22px;color:#1A1D26;}
.cl-title:after{content:"";display:block;width:56px;height:3px;background:#CD1E27;margin:8px auto 0;border-radius:2px;}
.cl-body p{margin:0 0 12px;}
.cl-salary{width:100%;max-width:440px;border-collapse:collapse;margin:8px 0 6px;font-size:12.5px;}
.cl-salary td{padding:6px 10px;border-bottom:1px solid #F0F1F3;}
.cl-salary tr:nth-child(odd) td{background:#FAFAFB;}
.cl-salary td.cur{width:44px;color:#6B7280;}
.cl-salary td.amt{text-align:right;font-variant-numeric:tabular-nums;}
.cl-salary tr.total td{background:#FCEAEA;border-bottom:none;font-weight:700;color:#A8181F;}
.cl-sign{margin:2px 0 4px;height:80px;}
.cl-sign img{height:80px;width:auto;object-fit:contain;}
.cl-signer{margin-bottom:16px;}
.cl-signer .sn{font-weight:700;}
.cl-note{font-size:11.5px;color:#6B7280;}
.cl-footer{position:absolute;left:0;right:0;bottom:0;border-top:3px solid #CD1E27;padding:12px 48px;font-size:11px;color:#6B7280;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;background:#fff;}
@media (max-width:820px){.cl-head,.cl-body,.cl-footer{padding-left:20px;padding-right:20px;}.cl-meta{text-align:left;}.cl-page{min-height:0;font-size:12.5px;}}
@media print{@page{size:A4;margin:0}body{background:#fff!important;padding:0!important}.cl-wrap{padding:0}.cl-page{box-shadow:none;border:none;width:210mm;min-height:297mm}.no-print{display:none!important}}
CSS;
}

function coeLetterHtml(array $emp, ?string $purpose): string {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $name = trim(preg_replace('/\s+/', ' ', ($emp['first_name'] ?? '') . ' ' . ($emp['middle_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')));
    $male = ($emp['gender'] ?? '') === 'male';
    $honorific = $male ? 'Mr.' : 'Ms.';
    $pron = $male ? 'His' : 'Her';
    $basic = (float)($emp['basic_salary'] ?? 0);
    $deMin = (float)($emp['allowances'] ?? 0);
    $total = $basic + $deMin;
    $lines = ['Basic Salary' => $basic, 'De Minimis' => $deMin, 'Language Premium' => 0.0, 'Acting Capacity' => 0.0,
              'Allowance' => 0.0, 'Tele Communication Allowance' => 0.0, 'Transport Allowance' => 0.0];
    $hired  = !empty($emp['date_hired']) ? date('F j, Y', strtotime($emp['date_hired'])) : '—';
    $status = coeEmploymentStatus($emp['date_hired'] ?? null);
    $purposeHtml = $purpose !== null && trim($purpose) !== '' ? '<b>' . $h($purpose) . '</b>' : '<b>________________</b>';
    $logo = coeImageData('assets/img/logo.png');
    $esig = coeImageData('assets/img/e-sig.png');
    $sg   = coeSignatory();
    $ref  = 'COE-' . preg_replace('/[^A-Za-z0-9]/', '', (string)($emp['employee_id'] ?? '')) . '-' . date('Ymd');

    ob_start(); ?>
<div class="cl-wrap">
<div class="cl-page">
    <div class="cl-head">
        <div class="cl-brand">
            <?php if ($logo): ?><img src="<?= $logo ?>" alt="Directpath logo"><?php endif; ?>
            <div><div class="nm"><?= $h(COE_COMPANY_NAME) ?></div><div class="dp"><?= $h(COE_DEPARTMENT) ?></div></div>
        </div>
        <div class="cl-meta">Date: <b><?= date('F j, Y') ?></b><br>Ref. No.: <b><?= $h($ref) ?></b></div>
    </div>
    <div class="cl-body">
        <h1 class="cl-title">CERTIFICATE OF EMPLOYMENT</h1>
        <p>Dear Sir/Madam,</p>
        <p>This is to certify that <b><?= $honorific ?> <?= $h(strtoupper($name)) ?></b> is employed in <?= $h(COE_COMPANY_NAME) ?> as <b><?= $h($emp['position'] ?? '') ?></b>
           <?= !empty($emp['department']) ? 'in the ' . $h($emp['department']) . ' department' : '' ?>. <?= $pron ?> date of joining the company is <b><?= $h($hired) ?></b> and <?= $male ? 'he' : 'she' ?> is currently a <b><?= $h($status) ?></b> employee as per our records.</p>
        <p>The employee&rsquo;s monthly salary details are as given below:</p>
        <table class="cl-salary">
            <?php foreach ($lines as $label => $amt): ?>
            <tr><td><?= $h($label) ?></td><td class="cur">PHP</td><td class="amt"><?= number_format($amt, 2) ?></td></tr>
            <?php endforeach; ?>
            <tr class="total"><td>Total</td><td class="cur">PHP</td><td class="amt"><?= number_format($total, 2) ?></td></tr>
        </table>
        <p style="margin-top:14px;">This letter is issued for the purpose of <?= $purposeHtml ?>.</p>
        <p>For further verification of the information contained herein, you may send an e-mail request to <?= $h($sg['email']) ?>.</p>
        <p>This cannot be used for employment purposes.</p>
        <p>Sincerely,</p>
        <div class="cl-sign"><?php if ($esig): ?><img src="<?= $esig ?>" alt="Signature"><?php endif; ?></div>
        <div class="cl-signer"><span class="sn"><?= $h($sg['name']) ?></span><br><?= $h($sg['role']) ?><br>for <b><?= $h(COE_COMPANY_NAME) ?></b></div>
        <p class="cl-note">The information furnished above is as per our company records. This letter is issued based on the employee&rsquo;s request without any liability or responsibility on the part of the signing official or the company.</p>
        <p class="cl-note">This is an automatically system-generated letter. Digital signature has been affixed.</p>
    </div>
    <div class="cl-footer"><span><?= $h(COE_COMPANY_NAME) ?> &mdash; Official HR Document</span><span><?= $h($sg['email']) ?></span></div>
</div>
</div>
<?php
    return (string)ob_get_clean();
}
