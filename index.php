<?php
session_start();
require_once 'config/config.php';

if (isset($_SESSION['employee_id'])) {
    $role = $_SESSION['role'];
    if ($role === 'superadmin')   header('Location: modules/superadmin/dashboard.php');
    elseif ($role === 'ceo')      header('Location: modules/ceo/dashboard.php');
    elseif ($role === 'employee') header('Location: modules/employee/dashboard.php');
    else                          header('Location: modules/admin/dashboard.php');
    exit;
}

$error   = '';
$success = '';
$timeout = isset($_GET['timeout'])      ? 'Your session has expired. Please log in again.' : '';
$unauth  = isset($_GET['unauthorized']) ? 'You do not have permission to access that page.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT u.*, r.role_name as role FROM users u JOIN user_roles ur ON ur.employee_id = u.employee_id JOIN roles r ON r.id = ur.role_id WHERE u.email = ? AND u.status = 'active' ORDER BY ur.id LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['employee_id']  = $user['employee_id'];
            $_SESSION['name']         = getFullName($user['first_name'], $user['middle_name'] ?? null, $user['last_name']);
            $_SESSION['email']        = $user['email'];
            $_SESSION['role']         = $user['role'];
            $_SESSION['department']   = $user['department'];
            $_SESSION['position']     = $user['position'];
            $_SESSION['last_activity']= time();

            try {
                $db->prepare("INSERT INTO audit_logs (user_id, user_name, action, module, details, ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$user['employee_id'], $_SESSION['name'], 'Login', 'Auth', 'Role: ' . $user['role'] . ' | User logged in', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
            } catch (Exception $e) {}

            if ($user['role'] === 'superadmin') header('Location: modules/superadmin/dashboard.php');
            elseif ($user['role'] === 'ceo')    header('Location: modules/ceo/dashboard.php');
            elseif ($user['role'] === 'employee') header('Location: modules/employee/dashboard.php');
            else                                header('Location: modules/admin/dashboard.php');
            exit;
        } else {
            $error = 'Invalid email or password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Login — Directpath EMPS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/bootstrap.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --red:       #CD1E27;
    --red-dark:  #A8181F;
    --red-light: #FCEAEA;
}
body {
    font-family: 'Poppins', sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
    background-color: #0F1117;
    background-image:
        radial-gradient(ellipse at 25% 50%, rgba(205,30,39,0.16) 0%, transparent 65%),
        radial-gradient(ellipse at 75% 10%, rgba(205,30,39,0.08) 0%, transparent 50%);
    background-attachment: fixed;
    overflow-x: hidden;
}
.login-card {
    width: 100%;
    max-width: 440px;
    background: #fff;
    border-radius: 20px;
    padding: 42px 40px 36px;
    box-shadow: 0 32px 80px rgba(0,0,0,0.55), 0 0 0 1px rgba(255,255,255,0.04);
    position: relative;
    z-index: 1;
}
.logo-row { display: flex; align-items: center; gap: 12px; margin-bottom: 28px; }
.logo-box {
    width: 48px; height: 48px;
    background: #fff; border-radius: 10px;
    border: 1.5px solid #E5E7EB;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; overflow: hidden;
}
.logo-box img { width: 38px; height: 38px; object-fit: contain; }
.logo-name { font-size: 15px; font-weight: 700; color: #1A1D26; line-height: 1.2; }
.logo-sub  { font-size: 10px; color: #9CA3AF; margin-top: 2px; }
.card-divider { border: none; border-top: 1px solid #F1F3F5; margin: 0 0 24px; }
.card-title { font-size: 22px; font-weight: 700; color: #1A1D26; margin-bottom: 4px; }
.card-sub   { font-size: 13px; color: #8A8F9D; margin-bottom: 22px; }
.alert-box  { padding: 10px 14px; border-radius: 8px; font-size: 12.5px; margin-bottom: 16px; line-height: 1.5; }
.alert-error   { background: var(--red-light); color: var(--red-dark); border: 1px solid #F5C0C2; }
.alert-warning { background: #FEF4E1; color: #7A4A00; border: 1px solid #F5D68A; }
.alert-success { background: #F0FDF4; color: #166534; border: 1px solid #BBF7D0; }
.form-group  { margin-bottom: 16px; }
.form-label  { display: block; font-size: 11px; font-weight: 700; color: #6B7280; text-transform: uppercase; letter-spacing: 0.7px; margin-bottom: 6px; }
.input-wrap  { position: relative; }
.form-input  {
    width: 100%; padding: 11px 14px;
    border: 1.5px solid #E5E7EB; border-radius: 9px;
    font-size: 14px; font-family: 'Poppins', sans-serif;
    color: #1A1D26; background: #F9FAFB; outline: none;
    transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
}
.form-input:focus { border-color: var(--red); background: #fff; box-shadow: 0 0 0 3px rgba(205,30,39,0.09); }
.toggle-pw {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer; color: #9CA3AF;
    padding: 0; line-height: 1; font-size: 17px; display: flex; align-items: center;
}
.toggle-pw:hover { color: #6B7280; }
.form-input.has-toggle { padding-right: 42px; }
.extras-row {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 18px; font-size: 12px; gap: 8px;
}
.remember-label { display: flex; align-items: center; gap: 6px; color: #6B7280; cursor: pointer; user-select: none; }
.remember-label input[type="checkbox"] { accent-color: var(--red); width: 14px; height: 14px; cursor: pointer; }
.forgot-btn {
    background: none; border: none; font-family: 'Poppins', sans-serif;
    font-size: 12px; font-weight: 500; color: var(--red);
    cursor: pointer; padding: 0; white-space: nowrap;
}
.forgot-btn:hover { text-decoration: underline; }
.btn-login {
    width: 100%; padding: 13px;
    background: var(--red); color: #fff; border: none; border-radius: 9px;
    font-size: 14px; font-weight: 700; font-family: 'Poppins', sans-serif;
    cursor: pointer; transition: background 0.18s, transform 0.15s, box-shadow 0.18s;
    letter-spacing: 0.2px;
}
.btn-login:hover { background: var(--red-dark); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(205,30,39,0.32); }
.btn-login:active { transform: none; box-shadow: none; }
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.65); z-index: 9999;
    align-items: center; justify-content: center; padding: 16px;
}
.modal-overlay.show { display: flex; }
.modal-box { background: #fff; border-radius: 16px; padding: 32px; width: 100%; max-width: 400px; box-shadow: 0 24px 60px rgba(0,0,0,0.45); }
.modal-box h3 { font-size: 18px; font-weight: 700; color: #1A1D26; margin-bottom: 8px; }
.modal-box p  { font-size: 13px; color: #6B7280; margin-bottom: 20px; line-height: 1.6; }
.modal-btns   { display: flex; gap: 10px; margin-top: 12px; }
.btn-cancel {
    flex: 1; padding: 12px; border: 1.5px solid #E5E7EB; border-radius: 8px;
    background: #fff; font-family: 'Poppins', sans-serif; font-size: 13px;
    cursor: pointer; color: #6B7280; transition: background 0.15s;
}
.btn-cancel:hover { background: #F9FAFB; }
.facial-kiosk-link {
    position: fixed; right: 24px; bottom: 24px; z-index: 10;
    display: inline-flex; align-items: center; justify-content: center;
    min-height: 44px; padding: 0 18px; border-radius: 8px;
    background: var(--red); color: #fff; font-size: 13px; font-weight: 600;
    text-decoration: none; box-shadow: 0 6px 18px rgba(0,0,0,0.2);
    transition: background 0.18s, transform 0.15s;
}
.facial-kiosk-link:hover { background: var(--red-dark); color: #fff; transform: translateY(-1px); }
.facial-kiosk-link:focus-visible { outline: 3px solid #1A1D26; outline-offset: 3px; }
@media (max-width: 480px) {
    body { padding: 16px 12px; align-items: flex-start; padding-top: 32px; }
    .login-card { padding: 30px 22px 26px; border-radius: 16px; }
    .card-title { font-size: 20px; }
    .facial-kiosk-link { position: static; align-self: flex-end; margin-top: 16px; }
}
</style>
</head>
<body>

<div class="login-card">
    <div class="logo-row">
        <div class="logo-box">
            <img src="assets/img/logo.png" alt="Directpath Logo">
        </div>
        <div>
            <div class="logo-name">Directpath</div>
            <div class="logo-sub">Canada Consultation Inc.</div>
        </div>
    </div>

    <hr class="card-divider">

    <div class="card-title">Welcome back!</div>
    <div class="card-sub">Sign in to your Directpath EMPS account.</div>

    <?php if ($error):   ?><div class="alert-box alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($timeout): ?><div class="alert-box alert-warning"><?= htmlspecialchars($timeout) ?></div><?php endif; ?>
    <?php if ($unauth):  ?><div class="alert-box alert-error"><?= htmlspecialchars($unauth) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-box alert-success"><?= $success ?></div><?php endif; ?>

    <form method="POST" action="" id="loginForm">
        <div class="form-group">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" id="emailField" class="form-input"
                   placeholder="your@email.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   required autofocus>
        </div>
        <div class="form-group">
            <label class="form-label">Password</label>
            <div class="input-wrap">
                <input type="password" name="password" id="passwordField"
                       class="form-input has-toggle" placeholder="••••••••" required>
                <button type="button" class="toggle-pw" onclick="togglePassword()">
                    <svg id="eyeOpen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg id="eyeClosed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="display:none;">
                        <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/>
                        <line x1="1" y1="1" x2="23" y2="23"/>
                    </svg>
                </button>
            </div>
        </div>
        <div class="extras-row">
            <label class="remember-label">
                <input type="checkbox" id="rememberCheck" onchange="handleRemember()">
                Remember my email
            </label>
            <button type="button" class="forgot-btn" onclick="showForgot()">Forgot Password?</button>
        </div>
        <button type="submit" class="btn-login">Sign In to EMPS</button>
    </form>
</div>

<a class="facial-kiosk-link" href="facial_login.php">Facial Login</a>

<!-- Forgot Password Modal -->
<div class="modal-overlay" id="forgotModal">
    <div class="modal-box">
        <h3>Reset Password</h3>
        <p>We will email a 6-digit verification code to your registered email address. Verify the code before setting a new password.</p>
        <form method="GET" action="forgot_password.php">
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-input" placeholder="your@email.com" required>
            </div>
            <div class="modal-btns">
                <button type="submit" class="btn-login" style="flex:1;padding:12px;">Send Verification Code</button>
                <button type="button" class="btn-cancel" onclick="hideForgot()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePassword() {
    var field  = document.getElementById('passwordField');
    var open   = document.getElementById('eyeOpen');
    var closed = document.getElementById('eyeClosed');
    if (field.type === 'password') {
        field.type = 'text';
        open.style.display   = 'none';
        closed.style.display = 'block';
    } else {
        field.type = 'password';
        open.style.display   = 'block';
        closed.style.display = 'none';
    }
}
function handleRemember() {
    var cb    = document.getElementById('rememberCheck');
    var email = document.getElementById('emailField').value;
    if (cb.checked && email) localStorage.setItem('dp_email', email);
    else localStorage.removeItem('dp_email');
}
window.addEventListener('load', function() {
    var saved = localStorage.getItem('dp_email');
    if (saved) {
        document.getElementById('emailField').value      = saved;
        document.getElementById('rememberCheck').checked = true;
    }
});
document.getElementById('loginForm').addEventListener('submit', function() {
    if (document.getElementById('rememberCheck').checked)
        localStorage.setItem('dp_email', document.getElementById('emailField').value);
});
function showForgot() { document.getElementById('forgotModal').classList.add('show'); }
function hideForgot() { document.getElementById('forgotModal').classList.remove('show'); }
document.getElementById('forgotModal').addEventListener('click', function(e) {
    if (e.target === this) hideForgot();
});
</script>

<script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>