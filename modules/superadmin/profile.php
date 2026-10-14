<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole("superadmin");

$pageTitle  = 'My Profile';
$activePage = 'profile';
$db = getDB();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_profile') {
        $phone   = sanitize($_POST['phone'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $civil   = sanitize($_POST['civil_status'] ?? 'single');

        // Handle photo upload
        $photoName = null;
        if (!empty($_FILES['profile_photo']['name'])) {
            $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $photoName = 'profile_' . $_SESSION['employee_id'] . '_' . time() . '.' . $ext;
                if (!is_dir(PROFILE_PHOTO_PATH)) mkdir(PROFILE_PHOTO_PATH, 0755, true);
                move_uploaded_file($_FILES['profile_photo']['tmp_name'], PROFILE_PHOTO_PATH . $photoName);
            }
        }

        $sql = "UPDATE users SET phone=?, address=?, civil_status=?";
        $params = [$phone, $address, $civil];
        if ($photoName) { $sql .= ", profile_photo=?"; $params[] = $photoName; }
        $sql .= " WHERE employee_id=?";
        $params[] = $_SESSION['employee_id'];
        $db->prepare($sql)->execute($params);
        auditLog('Update Profile', 'Profile', 'Updated profile info');
        $success = 'Profile updated.';
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $db->prepare("SELECT password FROM users WHERE employee_id=?");
        $stmt->execute([$_SESSION['employee_id']]);
        $pw = $stmt->fetchColumn();

        if (!password_verify($current, $pw)) {
            $error = 'Current password is incorrect.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } elseif (strlen($new) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            $db->prepare("UPDATE users SET password=? WHERE employee_id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['employee_id']]);
            auditLog('Change Password', 'Profile', 'Password changed');
            auditLog('Change Password', 'Profile', 'Password changed');
            $success = 'Password updated successfully.';
        }
    }
}

$user = getCurrentUser();
include '../../includes/header.php';
?>

<div class="page-body">

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-3 profile-settings-layout">
    <div class="col-12 col-md-4">
        <div class="card" style="text-align:center;">
            <div style="margin-bottom:16px;">
                <?php if (!empty($user['profile_photo']) && file_exists(PROFILE_PHOTO_PATH . $user['profile_photo'])): ?>
                <img src="../../uploads/profile_photos/<?= htmlspecialchars($user['profile_photo']) ?>" style="width:100px; height:100px; border-radius:50%; object-fit:cover; border:3px solid #E5E7EB;">
                <?php else: ?>
                <div style="width:100px; height:100px; border-radius:50%; background:#CD1E27; color:#fff; display:flex; align-items:center; justify-content:center; font-size:36px; font-weight:700; margin:0 auto; border:3px solid #E5E7EB;">
                    <?= getInitials($user['name']) ?>
                </div>
                <?php endif; ?>
            </div>
            <div style="font-size:18px; font-weight:700;"><?= htmlspecialchars($user['name']) ?></div>
            <div style="font-size:13px; color:#6B7280; margin-top:4px;"><?= htmlspecialchars($user['position']) ?></div>
            <div style="font-size:12px; color:#9CA3AF;"><?= htmlspecialchars($user['department']) ?></div>
            <div style="margin-top:12px;"><span class="badge badge-<?= $user['role']==='superadmin'?'purple':($user['role']==='admin'?'amber':'blue') ?>"><?= ucfirst($user['role']) ?></span></div>
            <div style="margin-top:12px; font-size:12px; color:#9CA3AF;">
                Employee ID: <strong><?= $user['employee_id'] ?></strong><br>
                Date Hired: <?= formatDate($user['date_hired']) ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-8">
        <!-- Update Profile -->
        <div class="card">
            <div class="card-title">Update Profile</div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile">
                <div class="row g-2">
                    <div class="col-md-6"><div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-input" value="<?= htmlspecialchars($user['phone'] ?? '') ?>"></div></div>
                    <div class="col-md-6"><div class="form-group"><label class="form-label">Civil Status</label><select name="civil_status" class="form-select"><option value="single" <?= ($user['civil_status']==='single')?'selected':'' ?>>Single</option><option value="married" <?= ($user['civil_status']==='married')?'selected':'' ?>>Married</option><option value="widowed" <?= ($user['civil_status']==='widowed')?'selected':'' ?>>Widowed</option><option value="separated" <?= ($user['civil_status']==='separated')?'selected':'' ?>>Separated</option></select></div></div>
                    <div class="col-12"><div class="form-group"><label class="form-label">Address</label><input type="text" name="address" class="form-input" value="<?= htmlspecialchars($user['address'] ?? '') ?>"></div></div>
                    <div class="col-12"><div class="form-group"><label class="form-label">Profile Photo</label><input type="file" name="profile_photo" class="form-input" accept="image/*"></div></div>
                </div>
                <button type="submit" class="btn-primary" style="margin-top:8px;">Save Changes</button>
            </form>
        </div>

        <!-- Change Password -->
        <div class="card">
            <div class="card-title">Change Password</div>
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-input" required></div>
                <div class="form-group"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-input" required></div>
                <div class="form-group"><label class="form-label">Confirm New Password</label><input type="password" name="confirm_password" class="form-input" required></div>
                <button type="submit" class="btn-primary">Update Password</button>
            </form>
        </div>
    </div>
</div>

</div>
