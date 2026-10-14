<?php
// ============================================================
// Directpath HRMS — Employee Loan Management Module
// SSS Salary Loan + Pag-IBIG Multi-Purpose Loan
// ============================================================
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole('employee');

$pageTitle  = 'Loan Management';
$activePage = 'loans';
$db    = getDB();
$empId = $_SESSION['employee_id'];

$success = '';
$error   = '';

// ── Loan Rate Constants (2026) ─────────────────────────────
// SSS Salary Loan: 10% per annum = 0.8333% per month
define('SSS_MONTHLY_RATE', 0.008333);
// Pag-IBIG MPL: 10.5% per annum = 0.875% per month  
define('PAGIBIG_MONTHLY_RATE', 0.00875);

// ── Monthly Amortization Calculator ───────────────────────
// Standard reducing-balance amortization formula
function computeMonthlyAmortization(float $principal, float $monthlyRate, int $months): float {
    if ($monthlyRate == 0) return round($principal / $months, 2);
    $factor = pow(1 + $monthlyRate, $months);
    return round($principal * ($monthlyRate * $factor) / ($factor - 1), 2);
}

// ── Generate Loan Number ───────────────────────────────────
function generateLoanNo(string $type): string {
    $prefix = match($type) {
        'sss_salary'     => 'SSS-SAL',
        'sss_calamity'   => 'SSS-CAL',
        'pagibig_mpl'    => 'PGMPL',
        'pagibig_housing'=> 'PGHOU',
        default          => 'LN'
    };
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
}

// ── Handle POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // === SUBMIT / RE-SUBMIT A LOAN DOCUMENT (SOA) FOR HR REVIEW ===
    // Only records the submission. It never creates a payroll deduction: HR/Admin must verify and approve first.
    if ($action === 'submit_loan_document') {
        $resubId  = (int)($_POST['disclosure_id'] ?? 0);
        $provider = sanitize($_POST['provider'] ?? '');
        $ltype    = sanitize($_POST['loan_type_disclosed'] ?? '');
        $refNo    = sanitize($_POST['reference_no'] ?? '');
        $orig     = round((float)($_POST['original_amount'] ?? 0), 2);
        $outst    = round((float)($_POST['outstanding_balance'] ?? 0), 2);
        $monthly  = round((float)($_POST['monthly_amortization'] ?? 0), 2);
        $startD   = sanitize($_POST['loan_start_date'] ?? '');
        $term     = (int)($_POST['term_months_disclosed'] ?? 0);
        $sched    = normalizeLoanSchedule((string)($_POST['deduction_schedule'] ?? '')) ?: 'end_of_month';
        $remarks  = sanitize($_POST['remarks'] ?? '');
        $hasFile  = !empty($_FILES['loan_document']['name']) && ($_FILES['loan_document']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE;

        $old = null;
        if ($resubId) {
            $o = $db->prepare("SELECT * FROM loan_disclosures WHERE id=? AND employee_id=?");
            $o->execute([$resubId, $empId]);
            $old = $o->fetch();
        }
        if ($resubId && (!$old || $old['status'] !== 'needs_info')) {
            $error = 'Only a submission marked "Needs Additional Document/Information" can be updated.';
        } elseif ($provider === '' || $ltype === '' || $orig <= 0 || $outst < 0 || $monthly <= 0) {
            $error = 'Provider, loan type, original amount, outstanding balance and monthly amortization are required.';
        } elseif ($outst > $orig) {
            $error = 'Outstanding balance cannot be higher than the original loan amount.';
        } elseif ($startD !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startD)) {
            $error = 'Invalid loan start date.';
        } elseif (!$hasFile && !($old && $old['doc_path'])) {
            $error = 'Please attach the official SOA / loan statement (PDF, JPG or PNG).';
        } else {
            $doc = null;
            try {
                if ($hasFile) $doc = storeLoanDocument($_FILES['loan_document'], $empId);
                $db->beginTransaction();
                if ($old) {
                    $db->prepare("UPDATE loan_disclosures SET provider=?, loan_type=?, reference_no=?, original_amount=?, outstanding_balance=?, monthly_amortization=?,
                                  loan_start_date=?, term_months=?, deduction_schedule=?, remarks=?, status='for_review', hr_remarks=NULL, reviewed_by=NULL, reviewed_at=NULL,
                                  doc_path=COALESCE(?,doc_path), doc_name=COALESCE(?,doc_name), doc_mime=COALESCE(?,doc_mime), submitted_at=NOW()
                                  WHERE id=? AND employee_id=? AND status='needs_info'")
                       ->execute([$provider, $ltype, $refNo ?: null, $orig, $outst, $monthly, $startD ?: null, $term ?: null, $sched, $remarks ?: null,
                                  $doc['path'] ?? null, $doc['name'] ?? null, $doc['mime'] ?? null, $resubId, $empId]);
                } else {
                    $db->prepare("INSERT INTO loan_disclosures (employee_id, provider, loan_type, reference_no, original_amount, outstanding_balance, monthly_amortization,
                                  loan_start_date, term_months, deduction_schedule, remarks, doc_path, doc_name, doc_mime)
                                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                       ->execute([$empId, $provider, $ltype, $refNo ?: null, $orig, $outst, $monthly, $startD ?: null, $term ?: null, $sched, $remarks ?: null,
                                  $doc['path'], $doc['name'], $doc['mime']]);
                }
                if (!auditLog($old ? 'Resubmit Loan Document' : 'Submit Loan Document', 'Loans', "Provider: $provider | Type: $ltype | Original: $orig | Monthly: $monthly | Status: For HR Review")) {
                    throw new RuntimeException('Audit log write failed.');
                }
                createNotification($db, "New loan submission from {$_SESSION['name']} needs review.", 'modules/admin/loan_review.php', null, 'hr_admin');
                $db->commit();
                $success = 'Loan document submitted. Status: For HR Review. No payroll deduction is created until HR/Admin verifies and approves it.';
            } catch (InvalidArgumentException $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = $e->getMessage();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                if ($doc && is_file(LOAN_DOC_PATH . $doc['path'])) unlink(LOAN_DOC_PATH . $doc['path']);
                error_log('Loan document submit failed: ' . $e->getMessage());
                $error = 'The loan document could not be saved. Please try again.';
            }
        }
    }

}

// ── Fetch My Loans ─────────────────────────────────────────

// Disclosed loans (SOA submissions) + their payroll payment history — view-only for the employee
$myDisclosures = [];
try {
    $d = $db->prepare("SELECT * FROM loan_disclosures WHERE employee_id=? ORDER BY submitted_at DESC");
    $d->execute([$empId]);
    $myDisclosures = $d->fetchAll();
} catch (Throwable $e) { /* migration not run yet */ }

// Stats
$activeLoans  = count(array_filter($myDisclosures, fn($l) => $l['status'] === 'approved' && $l['loan_status'] === 'active'));
$pendingLoans = count(array_filter($myDisclosures, fn($l) => in_array($l['status'], ['for_review','verified','needs_info'], true)));
$totalBalance = array_sum(array_map(fn($l) => ($l['status'] === 'approved' && $l['loan_status'] === 'active') ? (float)$l['outstanding_balance'] : 0, $myDisclosures));

include '../../includes/header.php';
?>


<style>
.stat-icon-box {
    width: 48px; height: 48px; min-width: 48px;
    border-radius: 10px; display: flex;
    align-items: center; justify-content: center; font-size: 22px;
}
.loan-type-card {
    border: 2px solid var(--border);
    border-radius: 14px;
    padding: 18px 20px;
    cursor: pointer;
    transition: all 0.2s;
    background: #fff;
    position: relative;
}
.loan-type-card:hover { border-color: var(--red); box-shadow: 0 4px 16px rgba(205,30,39,0.1); }
.loan-type-card.selected { border-color: var(--red); background: #fef2f2; }
.loan-type-card input[type=radio] { position:absolute; opacity:0; }
.loan-badge { font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; }

/* Modal */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2000; align-items:center; justify-content:center; }
.modal-overlay.show { display:flex; }
.modal-box { background:#fff; border-radius:12px; padding:28px; width:100%; max-width:540px; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.2); }

.badge { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px; }
.badge-pending  { background:#fff8e1; color:#b45309; }
.badge-approved { background:#e8f5e9; color:#2e7d32; }
.badge-active   { background:#e3f2fd; color:#1565c0; }
.badge-rejected { background:#fce4ec; color:#c62828; }
.badge-fully_paid { background:#f3e5f5; color:#6a1b9a; }

.form-label { font-size:12px; font-weight:600; color:#374151; margin-bottom:4px; display:block; }
.form-control { width:100%; padding:10px 14px; border:1.5px solid var(--border); border-radius:10px; font-size:13px; font-family:inherit; outline:none; transition:border .2s; background:#fff; }
.form-control:focus { border-color:var(--red); }
select.form-control { cursor:pointer; }

.btn-primary { background:var(--red); color:#fff; border:none; border-radius:10px; padding:11px 24px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:.2s; }
.btn-primary:hover { background:var(--red-dark); }
.btn-outline { background:transparent; border:1.5px solid var(--border); color:#374151; border-radius:10px; padding:10px 20px; font-size:13px; font-weight:500; cursor:pointer; }
.btn-outline:hover { border-color:#9ca3af; }
.btn-danger { background:#fee2e2; color:#b91c1c; border:none; border-radius:8px; padding:6px 14px; font-size:12px; font-weight:600; cursor:pointer; }

.amort-preview { background:#f9fafb; border-radius:12px; padding:16px; border:1px solid var(--border); }

.info-note { background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:12px 16px; font-size:12px; color:#1e40af; display:flex; gap:8px; align-items:flex-start; }
</style>

<div class="page-body" style="padding:24px 28px 40px;">

    <!-- Alerts -->
    <?php if ($success): ?>
    <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:10px;color:#1b5e20;font-size:13px;font-weight:500;">
        <i class='bx bx-check-circle' style="font-size:20px;"></i> <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div style="background:#fce4ec;border:1px solid #f48fb1;border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:10px;color:#b71c1c;font-size:13px;font-weight:500;">
        <i class='bx bx-error-circle' style="font-size:20px;"></i> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>



    <!-- Stats Cards -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:20px;">
        <div class="stat-card" style="padding:18px 20px;">
            <div class="stat-icon-box" style="background:#fff8e1;"><i class='bx bx-money' style="color:#d97706;"></i></div>
            <div>
                <div style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Active Loans</div>
                <div style="font-size:26px;font-weight:700;color:#1a1d26;"><?= $activeLoans ?></div>
            </div>
        </div>
        <div class="stat-card" style="padding:18px 20px;">
            <div class="stat-icon-box" style="background:#ffe4e6;"><i class='bx bx-time' style="color:#e11d48;"></i></div>
            <div>
                <div style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">For HR Review</div>
                <div style="font-size:26px;font-weight:700;color:#1a1d26;"><?= $pendingLoans ?></div>
            </div>
        </div>
        <div class="stat-card" style="padding:18px 20px;">
            <div class="stat-icon-box" style="background:#e3f2fd;"><i class='bx bx-wallet' style="color:#1565c0;"></i></div>
            <div>
                <div style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Total Balance</div>
                <div style="font-size:18px;font-weight:700;color:#1a1d26;">₱<?= number_format($totalBalance, 0) ?></div>
            </div>
        </div>
    </div>

<!-- ══ Disclosed loans: SOA submission + HR verification status ══ -->
<div class="card" style="padding:0;overflow:hidden;margin-top:20px;">
    <div style="padding:18px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <span style="font-size:14px;font-weight:600;color:#1a1d26;">Loans and SOA Submissions</span>
        <button class="btn-primary" type="button" onclick="openLoanDoc(null)"><i class='bx bx-upload'></i> Submit Loan Document</button>
    </div>
    <div class="info-note" style="margin:14px 20px 0;"><i class='bx bx-info-circle'></i><div>Have a loan with another institution? Upload the official Statement of Account (PDF/JPG/PNG). HR/Admin must verify and approve it before any deduction is made from your payroll.</div></div>
    <div style="overflow-x:auto;">
    <table class="data-table">
        <thead><tr><th>Provider / Type</th><th>Reference</th><th>Original</th><th>Monthly</th><th>Document</th><th>Submitted</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($myDisclosures as $dl): ?>
            <tr>
                <td><strong><?= htmlspecialchars($dl['provider']) ?></strong><div style="font-size:11px;color:#6b7280;"><?= htmlspecialchars($dl['loan_type']) ?></div></td>
                <td><?= htmlspecialchars($dl['reference_no'] ?: '—') ?></td>
                <td>₱<?= number_format((float)$dl['original_amount'], 2) ?></td>
                <td>₱<?= number_format((float)$dl['monthly_amortization'], 2) ?></td>
                <td><a href="../../api/loan_document.php?id=<?= (int)$dl['id'] ?>" target="_blank" rel="noopener"><?= htmlspecialchars($dl['doc_name'] ?? 'Document') ?></a></td>
                <td style="font-size:12px;"><?= formatDate($dl['submitted_at']) ?></td>
                <td>
                    <span class="badge" style="background:<?= ['approved'=>'#e8f5e9','verified'=>'#e3f2fd','rejected'=>'#fce4ec','needs_info'=>'#fff3e0'][$dl['status']] ?? '#fff8e1' ?>;"><?= htmlspecialchars(loanStatusLabel($dl['status'])) ?></span>
                    <?php if (!empty($dl['hr_remarks'])): ?><div style="font-size:11px;color:#6b7280;margin-top:3px;">HR: <?= htmlspecialchars($dl['hr_remarks']) ?></div><?php endif; ?>
                </td>
                <td>
                    <?php if ($dl['status'] === 'needs_info'): ?>
                        <button class="btn-outline" style="font-size:11px;padding:4px 10px;" type="button" onclick='openLoanDoc(<?= htmlspecialchars(json_encode($dl), ENT_QUOTES) ?>)'>Update</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($myDisclosures): ?><tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="2">Total</td>
            <td>₱<?= number_format(array_sum(array_map(static fn($loan) => (float)$loan['original_amount'], $myDisclosures)), 2) ?></td>
            <td>₱<?= number_format(array_sum(array_map(static fn($loan) => (float)$loan['monthly_amortization'], $myDisclosures)), 2) ?></td><td colspan="4"></td></tr><?php endif; ?>
        <?php if (!$myDisclosures): ?><tr><td colspan="8" style="text-align:center;padding:20px;color:#9ca3af;">No loan documents submitted.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php $official = array_filter($myDisclosures, fn($d) => $d['status'] === 'approved'); ?>
<?php if ($official): ?>
<div class="card" style="padding:0;overflow:hidden;margin-top:20px;">
    <div style="padding:18px 20px;border-bottom:1px solid var(--border);font-size:14px;font-weight:600;color:#1a1d26;">My Official Loans (verified by HR/Admin — view only)</div>
    <?php foreach ($official as $dl):
        $paid = round((float)$dl['original_amount'] - (float)$dl['outstanding_balance'], 2);
        // Join through the payroll owner as well as disclosure_id. This keeps legacy rows
        // with repeated/invalid disclosure IDs from leaking other employees' payments here.
        $pays = [];
        try {
            $paymentStmt = $db->prepare("SELECT pm.payroll_id, pm.payment_month,
                                                COALESCE(p.payroll_period_end, pm.payment_date) AS payment_date,
                                                pm.amount, MIN(pm.balance_after) AS balance_after
                                         FROM loan_disclosure_payments pm
                                         LEFT JOIN payroll p ON p.id = pm.payroll_id
                                         WHERE pm.disclosure_id = ?
                                           AND (pm.payroll_id IS NULL OR (p.id IS NOT NULL AND p.employee_id = ?))
                                           AND pm.amount <= ?
                                         GROUP BY pm.payroll_id, pm.payment_month, COALESCE(p.payroll_period_end, pm.payment_date), pm.amount
                                         ORDER BY COALESCE(p.payroll_period_end, pm.payment_date), pm.payment_month");
            $paymentStmt->execute([(int)$dl['id'], $empId, (float)$dl['monthly_amortization']]);
            $pays = $paymentStmt->fetchAll();
        } catch (Throwable $e) { /* payment history migration not run yet */ }
    ?>
    <div style="padding:16px 20px;border-bottom:1px solid #f3f4f6;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px 18px;font-size:13px;">
            <div><div class="lbl">Loan Provider</div><?= htmlspecialchars($dl['provider']) ?></div>
            <div><div class="lbl">Loan Type</div><?= htmlspecialchars($dl['loan_type']) ?></div>
            <div><div class="lbl">Reference No.</div><?= htmlspecialchars($dl['reference_no'] ?: '—') ?></div>
            <div><div class="lbl">Original Amount</div>₱<?= number_format((float)$dl['original_amount'], 2) ?></div>
            <div><div class="lbl">Monthly Amortization</div>₱<?= number_format((float)$dl['monthly_amortization'], 2) ?></div>
            <div><div class="lbl">Payment Frequency</div>Monthly — <?= $dl['deduction_schedule'] === '15th' ? 'every 15th' : 'last day of the month' ?></div>
            <div><div class="lbl">Amount Paid</div>₱<?= number_format($paid, 2) ?></div>
            <div><div class="lbl">Remaining Balance</div><strong style="color:#e11d48;">₱<?= number_format((float)$dl['outstanding_balance'], 2) ?></strong></div>
            <div><div class="lbl">Payment Count (via payroll)</div><?= count($pays) ?></div>
            <div><div class="lbl">Next Deduction Date</div><?= $dl['loan_status'] === 'fully_paid' ? '—' : ($dl['next_deduction_date'] ? formatDate($dl['next_deduction_date']) : '—') ?></div>
            <div><div class="lbl">Loan Status</div><span class="badge <?= $dl['loan_status'] === 'fully_paid' ? 'badge-fully_paid' : 'badge-active' ?>"><?= $dl['loan_status'] === 'fully_paid' ? 'Fully Paid' : 'Active' ?></span></div>
        </div>
        <?php if ($pays): ?>
        <details style="margin-top:10px;"><summary style="cursor:pointer;font-size:12px;color:#1565c0;">Payment history</summary>
            <table class="data-table" style="margin-top:6px;"><thead><tr><th>Date</th><th>Payroll</th><th>Amount</th><th>Balance After</th></tr></thead><tbody>
            <?php foreach ($pays as $pm): $payrollId = (int)($pm['payroll_id'] ?? 0); $payslipUrl = '../admin/payslip.php?id=' . $payrollId . '&from=loan'; ?><?php if ($payrollId): ?><tr class="payment-history-row" role="link" tabindex="0" aria-label="Open payslip for payroll #<?= $payrollId ?>" title="Open payslip" onclick="window.location.href='<?= htmlspecialchars($payslipUrl, ENT_QUOTES, 'UTF-8') ?>'" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location.href='<?= htmlspecialchars($payslipUrl, ENT_QUOTES, 'UTF-8') ?>';}"><?php else: ?><tr><?php endif; ?><td><?= formatDate($pm['payment_date']) ?></td><td><?= $payrollId ? '#' . $payrollId : '—' ?></td><td>₱<?= number_format((float)$pm['amount'], 2) ?></td><td>₱<?= number_format((float)$pm['balance_after'], 2) ?></td></tr><?php endforeach; ?>
            <?php if ($pays): ?><tr data-ts-keep style="font-weight:700;background:#F9FAFB;"><td colspan="2">Total paid</td><td>₱<?= number_format(array_sum(array_map(static fn($payment) => (float)$payment['amount'], $pays)), 2) ?></td><td>—</td></tr><?php endif; ?>
            </tbody></table></details>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<style>.lbl{font-size:10px;font-weight:700;text-transform:uppercase;color:#9ca3af;letter-spacing:.04em;margin-bottom:2px;}.payment-history-row{cursor:pointer;}.payment-history-row:hover{background:#f8fafc;}.payment-history-row:focus-visible{outline:2px solid #1565c0;outline-offset:-2px;}</style>
<?php endif; ?>

<!-- Submit Loan Document Modal -->
<div class="modal-overlay" id="loanDocModal">
<div class="modal-box">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
        <div style="font-size:18px;font-weight:700;color:#1a1d26;" id="ld_title">Submit Loan Document</div>
        <button onclick="closeModal('loanDocModal')" style="background:none;border:none;font-size:22px;cursor:pointer;color:#6b7280;line-height:1;">&times;</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="submit_loan_document">
        <input type="hidden" name="disclosure_id" id="ld_id" value="0">
        <div style="margin-bottom:12px;"><label class="form-label">Loan Provider / Institution *</label><input class="form-control" name="provider" id="ld_provider" required placeholder="e.g. SSS, Pag-IBIG, Bank"></div>
        <div style="margin-bottom:12px;"><label class="form-label">Loan Type *</label><input class="form-control" name="loan_type_disclosed" id="ld_type" required placeholder="e.g. SSS Salary Loan"></div>
        <div style="margin-bottom:12px;"><label class="form-label">Loan Account / Reference No.</label><input class="form-control" name="reference_no" id="ld_ref"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div><label class="form-label">Original Loan Amount *</label><input class="form-control" type="number" step="0.01" min="0.01" name="original_amount" id="ld_orig" required></div>
            <div><label class="form-label">Current Outstanding Balance *</label><input class="form-control" type="number" step="0.01" min="0" name="outstanding_balance" id="ld_out" required></div>
            <div><label class="form-label">Monthly Amortization *</label><input class="form-control" type="number" step="0.01" min="0.01" name="monthly_amortization" id="ld_mon" required></div>
            <div><label class="form-label">Loan Term (months)</label><input class="form-control" type="number" min="0" name="term_months_disclosed" id="ld_term"></div>
            <div><label class="form-label">Loan Start Date</label><input class="form-control" type="date" name="loan_start_date" id="ld_start"></div>
            <div><label class="form-label">Preferred Deduction Schedule</label>
                <select class="form-control" name="deduction_schedule" id="ld_sched"><option value="end_of_month">Last day of the month</option><option value="15th">Every 15th</option></select></div>
        </div>
        <div style="margin-bottom:12px;"><label class="form-label">Remarks</label><textarea class="form-control" name="remarks" id="ld_rem" rows="2"></textarea></div>
        <div style="margin-bottom:14px;"><label class="form-label">Official SOA / Loan Statement * <span style="font-weight:400;color:#6b7280;">(PDF, JPG, PNG — max 5 MB)</span></label><input class="form-control" type="file" name="loan_document" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"></div>
        <div style="display:flex;gap:10px;"><button class="btn-primary" type="submit">Submit for HR Review</button><button class="btn-outline" type="button" onclick="closeModal('loanDocModal')">Cancel</button></div>
    </form>
</div>
</div>
<script>
function openLoanDoc(d) {
    var f = function (id, v) { document.getElementById(id).value = (v === null || v === undefined) ? '' : v; };
    document.getElementById('ld_title').textContent = d ? 'Update Loan Submission' : 'Submit Loan Document';
    f('ld_id', d ? d.id : 0); f('ld_provider', d && d.provider); f('ld_type', d && d.loan_type); f('ld_ref', d && d.reference_no);
    f('ld_orig', d && d.original_amount); f('ld_out', d && d.outstanding_balance); f('ld_mon', d && d.monthly_amortization);
    f('ld_term', d && d.term_months); f('ld_start', d && d.loan_start_date); f('ld_rem', d && d.remarks);
    document.getElementById('ld_sched').value = (d && d.deduction_schedule) || 'end_of_month';
    openModal('loanDocModal');
}
</script>

<!-- Remark Modal -->
<div class="modal-overlay" id="remarkModal">
<div class="modal-box" style="max-width:400px;">
    <div style="font-size:16px;font-weight:700;color:#1a1d26;margin-bottom:12px;">Rejection Reason</div>
    <div id="remarkText" style="font-size:13px;color:#374151;background:#fff5f5;border-radius:10px;padding:14px;border:1px solid #fecaca;"></div>
    <div style="text-align:right;margin-top:16px;">
        <button class="btn-outline" onclick="closeModal('remarkModal')">Close</button>
    </div>
</div>
</div>

<script>
function showRemark(text) {
    document.getElementById('remarkText').textContent = text;
    openModal('remarkModal');
}
</script>

<?php
// Close wrapper divs from header.php
echo '</div></div>';
?>
