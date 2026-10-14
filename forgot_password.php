<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/config.php';

$notice = '';
$error = '';
$email = strtolower(trim((string)($_GET['email'] ?? $_POST['email'] ?? $_SESSION['password_reset_email'] ?? '')));
$stage = 'request';
if (!empty($_SESSION['password_reset_email']) && !empty($_SESSION['password_reset_verified'])) {
    $stage = 'reset';
} elseif (!empty($_SESSION['password_reset_email'])) {
    $stage = 'verify';
}

function resetMailer(): object {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Email service is not installed. Run Composer install in the project folder.');
    }
    require_once $autoload;
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        throw new RuntimeException('PHPMailer was not found. Run Composer install in the project folder.');
    }
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USERNAME;
    $mail->Password = SMTP_PASSWORD;
    $mail->SMTPSecure = SMTP_SECURE === 'ssl'
        ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = SMTP_PORT;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    return $mail;
}

function csrf(): string {
    if (empty($_SESSION['reset_csrf'])) $_SESSION['reset_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['reset_csrf'];
}
function validCsrf(): bool {
    return isset($_POST['csrf'], $_SESSION['reset_csrf'])
        && is_string($_POST['csrf'])
        && hash_equals($_SESSION['reset_csrf'], $_POST['csrf']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrf()) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            $db = getDB();
            if ($action === 'send_code') {
                $email = strtolower(trim((string)($_POST['email'] ?? '')));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Enter a valid email address.';
                } else {
                    // Generic response avoids confirming whether an account exists.
                    $stmt = $db->prepare("SELECT employee_id, first_name FROM users WHERE email = ? AND status = 'active' LIMIT 1");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch();

                    if ($user) {
                        // Limit repeated requests per email to one code per 60 seconds.
                        $rate = $db->prepare("SELECT created_at FROM password_reset_codes WHERE email = ? LIMIT 1");
                        $rate->execute([$email]);
                        $last = $rate->fetchColumn();
                        if ($last && (time() - strtotime((string)$last)) < 60) {
                            $error = 'Please wait one minute before requesting another code.';
                        } else {
                            $code = (string)random_int(100000, 999999);
                            $hash = password_hash($code, PASSWORD_DEFAULT);
                            $expires = date('Y-m-d H:i:s', time() + 600);
                            $db->prepare("DELETE FROM password_reset_codes WHERE email = ?")->execute([$email]);
                            $db->prepare("INSERT INTO password_reset_codes (email, code_hash, expires_at, attempts, created_at) VALUES (?, ?, ?, 0, NOW())")
                               ->execute([$email, $hash, $expires]);
                            try {
                                $mail = resetMailer();
                                $mail->addAddress($email, (string)$user['first_name']);
                                $mail->isHTML(true);
                                $mail->Subject = 'Your Directpath EMPS password reset code';
                                $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
                                $mail->Body = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;padding:24px;color:#202124">'
                                    . '<h2 style="color:#CD1E27">Password reset verification</h2>'
                                    . '<p>Hello ' . htmlspecialchars((string)$user['first_name'], ENT_QUOTES, 'UTF-8') . ',</p>'
                                    . '<p>Use this verification code to reset your Directpath EMPS password:</p>'
                                    . '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;padding:14px;background:#f5f5f5;text-align:center">' . $safeCode . '</p>'
                                    . '<p>This code expires in 10 minutes. If you did not request a reset, you can ignore this email.</p>'
                                    . '<p>For your security, do not share this code with anyone.</p></div>';
                                $mail->AltBody = "Your Directpath EMPS password reset code is $code. It expires in 10 minutes.";
                                $mail->send();
                                $_SESSION['password_reset_email'] = $email;
                                $_SESSION['password_reset_verified'] = false;
                                $stage = 'verify';
                                $notice = 'If an active account uses that email, a verification code has been sent. Check your inbox and spam folder.';
                            } catch (Throwable $mailError) {
                                $db->prepare("DELETE FROM password_reset_codes WHERE email = ?")->execute([$email]);
                                error_log('Password reset email failed: ' . $mailError->getMessage());
                                $error = 'The email could not be sent. Check the SMTP setup and try again.';
                            }
                        }
                    } else {
                        // Preserve the same public response for unknown email addresses.
                        $_SESSION['password_reset_email'] = $email;
                        $_SESSION['password_reset_verified'] = false;
                        $stage = 'verify';
                        $notice = 'If an active account uses that email, a verification code has been sent. Check your inbox and spam folder.';
                    }
                }
            } elseif ($action === 'verify_code') {
                $email = (string)($_SESSION['password_reset_email'] ?? '');
                $code = trim((string)($_POST['code'] ?? ''));
                if ($email === '' || !preg_match('/^\d{6}$/', $code)) {
                    $error = 'Enter the 6-digit code sent to your email.';
                    $stage = 'verify';
                } else {
                    $stmt = $db->prepare("SELECT code_hash, expires_at, attempts FROM password_reset_codes WHERE email = ? LIMIT 1");
                    $stmt->execute([$email]);
                    $row = $stmt->fetch();
                    if (!$row || strtotime((string)$row['expires_at']) < time()) {
                        $error = 'That code has expired or is invalid. Request a new code.';
                        $stage = 'verify';
                    } elseif ((int)$row['attempts'] >= 5) {
                        $error = 'Too many incorrect attempts. Request a new code.';
                        $stage = 'verify';
                    } else {
                        $db->prepare("UPDATE password_reset_codes SET attempts = attempts + 1 WHERE email = ?")->execute([$email]);
                        if (password_verify($code, (string)$row['code_hash'])) {
                            $_SESSION['password_reset_verified'] = true;
                            $stage = 'reset';
                            $notice = 'Email verified. You can now set a new password.';
                        } else {
                            $error = 'Incorrect verification code. Please try again.';
                            $stage = 'verify';
                        }
                    }
                }
            } elseif ($action === 'reset_password') {
                $email = (string)($_SESSION['password_reset_email'] ?? '');
                if (empty($_SESSION['password_reset_verified']) || $email === '') {
                    $error = 'Verify your email code before resetting your password.';
                    $stage = 'verify';
                } else {
                    $password = (string)($_POST['new_password'] ?? '');
                    $confirm = (string)($_POST['confirm_password'] ?? '');
                    if (strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
                        $error = 'Use at least 10 characters, including at least one letter and one number.';
                        $stage = 'reset';
                    } elseif ($password !== $confirm) {
                        $error = 'The passwords do not match.';
                        $stage = 'reset';
                    } else {
                        $stmt = $db->prepare("SELECT employee_id FROM users WHERE email = ? AND status = 'active' LIMIT 1");
                        $stmt->execute([$email]);
                        $user = $stmt->fetch();
                        if (!$user) {
                            $error = 'This account is no longer available. Contact your system administrator.';
                            $stage = 'request';
                        } else {
                            $db->beginTransaction();
                            $db->prepare("UPDATE users SET password = ? WHERE employee_id = ?")
                               ->execute([password_hash($password, PASSWORD_DEFAULT), $user['employee_id']]);
                            $db->prepare("DELETE FROM password_reset_codes WHERE email = ?")->execute([$email]);
                            $db->commit();
                            unset($_SESSION['password_reset_email'], $_SESSION['password_reset_verified']);
                            $_SESSION['reset_complete'] = true;
                            header('Location: forgot_password.php?done=1');
                            exit;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
            error_log('Password reset workflow error: ' . $e->getMessage());
            $error = 'Something went wrong. Confirm the password-reset database migration and email settings.';
        }
    }
}
if (isset($_GET['done'])) {
    $stage = 'request';
    $notice = 'Your password has been reset successfully. You can now sign in with your new password.';
    unset($_SESSION['reset_complete']);
}
if ($stage === 'request' && !empty($_SESSION['password_reset_email']) && empty($_SESSION['password_reset_verified'])) $stage = 'verify';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset Password — Directpath EMPS</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:#0f1117;font-family:Poppins,Arial,sans-serif;color:#1a1d26}.card{width:100%;max-width:450px;background:#fff;border-radius:18px;padding:32px;box-shadow:0 24px 70px #0008}h1{font-size:23px;margin:0 0 8px}p{font-size:13px;color:#687080;line-height:1.6}.field{margin:18px 0}.field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:7px;color:#596170}.field input{width:100%;padding:12px;border:1px solid #dfe3e8;border-radius:8px;font:14px Poppins,Arial}.btn{width:100%;border:0;border-radius:8px;padding:13px;background:#cd1e27;color:white;font:600 14px Poppins;cursor:pointer}.btn:hover{background:#a8181f}.msg{padding:11px 13px;border-radius:8px;font-size:12px;margin:15px 0;background:#effaf1;color:#166534}.err{background:#fceaea;color:#a8181f}.back{display:block;margin-top:18px;text-align:center;font-size:12px;color:#cd1e27;text-decoration:none}
</style>
</head><body><main class="card">
<h1>Reset your password</h1>
<p>Use your registered email to verify your identity before creating a new password.</p>
<?php if ($notice): ?><div class="msg"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($error): ?><div class="msg err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($stage === 'request'): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="send_code">
<div class="field"><label for="email">Registered email address</label><input id="email" name="email" type="email" required autocomplete="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"></div>
<button class="btn">Send verification code</button>
</form>
<?php elseif ($stage === 'verify'): ?>
<p>Enter the 6-digit code sent to <strong><?= htmlspecialchars((string)($_SESSION['password_reset_email'] ?? $email), ENT_QUOTES, 'UTF-8') ?></strong>. The code expires in 10 minutes.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="verify_code">
<div class="field"><label for="code">6-digit verification code</label><input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></div>
<button class="btn">Verify code</button>
</form>
<form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="send_code"><input type="hidden" name="email" value="<?= htmlspecialchars((string)($_SESSION['password_reset_email'] ?? $email), ENT_QUOTES, 'UTF-8') ?>"><button class="btn" style="background:#596170">Resend code</button></form>
<?php else: ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="reset_password">
<div class="field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" minlength="10" required autocomplete="new-password"><p>At least 10 characters, including a letter and a number.</p></div>
<div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" minlength="10" required autocomplete="new-password"></div>
<button class="btn">Reset password</button>
</form>
<?php endif; ?>
<a class="back" href="index.php">← Back to sign in</a>
</main></body></html>
