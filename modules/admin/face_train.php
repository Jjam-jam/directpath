<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
apiRequireRole('hr_admin');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['employee_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing employee ID']);
    exit;
}

$employeeId = $input['employee_id'];

// Check if employee has reference photo
$db = getDB();
$stmt = $db->prepare("
    SELECT u.reference_photo, u.reference_photo_data, u.reference_photo_mime
    FROM users u
    WHERE u.employee_id = ? AND u.status = 'active'
      AND EXISTS (
          SELECT 1
          FROM user_roles ur
          JOIN roles r ON r.id = ur.role_id
          WHERE ur.employee_id = u.employee_id AND r.role_name = 'employee'
      )
");
$stmt->execute([$employeeId]);
$user = $stmt->fetch();

if (!$user || !$user['reference_photo']) {
    echo json_encode(['success' => false, 'message' => 'No reference photo found for this employee']);
    exit;
}

$tempPhotoPath = null;
if (!empty($user['reference_photo_data'])) {
    $ext = (($user['reference_photo_mime'] ?? '') === 'image/png') ? '.png' : '.jpg';
    $tempPhotoPath = tempnam(sys_get_temp_dir(), 'face_train_') . $ext;
    if (file_put_contents($tempPhotoPath, $user['reference_photo_data']) === false) {
        echo json_encode(['success'=>false,'message'=>'Could not prepare database photo for training']); exit;
    }
    $photoPath = $tempPhotoPath;
} else {
    $photoPath = realpath(REFERENCE_PHOTO_PATH . $user['reference_photo']);
}
$pythonScript = realpath(__DIR__ . '/../../facial_recognition.py');
if (!$photoPath || !file_exists($photoPath)) {
    echo json_encode(['success' => false, 'message' => 'Photo not found. Upload the reference photo again.']); exit;
}

if (!$pythonScript) {
    echo json_encode(['success' => false, 'message' => 'facial_recognition.py not found']);
    exit;
}

// FIX: Use realpath() sa Python script 
$pythonScript = realpath(__DIR__ . '/../../facial_recognition.py');

if (!$pythonScript) {
    echo json_encode(['success' => false, 'message' => 'Python script not found at: ' . __DIR__ . '/../../facial_recognition.py']);
    exit;
}

$pythonBinary = defined('PYTHON_BINARY') ? PYTHON_BINARY : 'python';
$command = "\"$pythonBinary\" \"$pythonScript\" train \"$employeeId\" \"$photoPath\" 2>&1";

exec($command, $output, $returnCode);
if ($tempPhotoPath && file_exists($tempPhotoPath)) { @unlink($tempPhotoPath); }

if ($returnCode === 0) {
    $message = implode("\n", $output);
    echo json_encode(['success' => true, 'message' => $message]);
} else {
    $error = implode("\n", $output);
    echo json_encode([
        'success' => false, 
        'message' => 'Training failed: ' . $error,
        'debug' => $command  // Remove this line after fixing
    ]);
}
?>