<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
apiRequireRole('hr_admin');
header('Content-Type: application/json');
function failUpload($message) { echo json_encode(['success'=>false,'message'=>$message]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') failUpload('Invalid request method');
if (!isset($_FILES['photo'], $_POST['employee_id'])) failUpload('Missing photo or employee ID');
$f = $_FILES['photo'];
if ($f['error'] !== UPLOAD_ERR_OK) failUpload('Photo upload failed (code '.$f['error'].')');
if (!is_uploaded_file($f['tmp_name'])) failUpload('Uploaded file is not valid');
if ($f['size'] > 5*1024*1024) failUpload('File too large. Maximum 5MB.');
$info = @getimagesize($f['tmp_name']);
if (!$info || !in_array($info['mime'], ['image/jpeg','image/png'], true)) failUpload('Only valid JPG or PNG images are allowed');
$data = file_get_contents($f['tmp_name']);
if ($data === false || $data === '') failUpload('Could not read uploaded image');
$mime = $info['mime'];
// Large photo BLOBs can exceed MySQL's configured packet limit. Downsize/compress when GD is available.
if (strlen($data) > 900 * 1024 && function_exists('imagecreatefromstring')) {
    $image = @imagecreatefromstring($data);
    if ($image !== false) {
        $width = imagesx($image); $height = imagesy($image);
        $scale = min(1, 1400 / max($width, $height));
        $outWidth = max(1, (int)round($width * $scale));
        $outHeight = max(1, (int)round($height * $scale));
        $resized = imagecreatetruecolor($outWidth, $outHeight);
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $outWidth, $outHeight, $width, $height);
        ob_start();
        imagejpeg($resized, null, 82);
        $compressed = ob_get_clean();
        imagedestroy($resized); imagedestroy($image);
        if ($compressed !== false && strlen($compressed) < strlen($data)) {
            $data = $compressed;
            $mime = 'image/jpeg';
        }
    }
}
$employeeId = trim((string)$_POST['employee_id']);
$db = getDB();
$check = $db->prepare("SELECT u.employee_id FROM users u WHERE u.employee_id=? AND u.status='active' AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.employee_id=u.employee_id AND r.role_name='employee')");
$check->execute([$employeeId]);
if (!$check->fetch()) failUpload('Employee not found or inactive');
try {
    $db->beginTransaction();
    $stmt = $db->prepare('UPDATE users SET reference_photo_data=?, reference_photo_mime=?, reference_photo=? WHERE employee_id=?');
    $stmt->bindValue(1, $data, PDO::PARAM_LOB);
    $stmt->bindValue(2, $mime, PDO::PARAM_STR);
    $stmt->bindValue(3, 'mysql_blob', PDO::PARAM_STR);
    $stmt->bindValue(4, $employeeId, PDO::PARAM_STR);
    $stmt->execute();
    $db->prepare('DELETE FROM face_encodings WHERE employee_id=?')->execute([$employeeId]);
    $db->commit();
    echo json_encode(['success'=>true,'message'=>'Photo saved in MySQL successfully']);
} catch (Throwable $e) {
    try {
        if ($db->inTransaction()) $db->rollBack();
    } catch (Throwable $rollbackError) {
        error_log('Face photo rollback failed: ' . $rollbackError->getMessage());
    }
    error_log('Face photo DB save failed: '.$e->getMessage());
    failUpload('Could not save the photo. Check the database connection and try again with a smaller image.');
}
?>
