<?php
// Face Processing API (used by face_checkin.php)
// The face is identified here, then attendance is recorded through the shared
// rules in includes/attendance_rules.php (time-in window, weekends, holidays,
// late and undertime). The Python script does not write attendance anymore.
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/attendance_rules.php';

header('Content-Type: application/json');

function faceProcessRespond(bool $ok, string $message, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    faceProcessRespond(false, 'Invalid request method');
}

apiRequireRole('employee', 'admin', 'superadmin');
$empId = $_SESSION['employee_id'] ?? '';
if ($empId === '') {
    faceProcessRespond(false, 'Not logged in.');
}

if (!isset($_FILES['image']) || !isset($_POST['action'])) {
    faceProcessRespond(false, 'Missing image or action');
}

$action = $_POST['action'];
if (!in_array($action, ['in', 'out'], true)) {
    faceProcessRespond(false, 'Invalid action');
}
$recordAction = $action === 'in' ? 'time_in' : 'time_out';

$db = getDB();

// Check the attendance rules BEFORE the slow face scan
if ($recordAction === 'time_in') {
    $reason = attTimeInBlockReason($db);
} else {
    $stmt = $db->prepare("SELECT time_in, time_out FROM attendance WHERE employee_id = ? AND date = CURDATE()");
    $stmt->execute([$empId]);
    $reason = attTimeOutBlockReason($stmt->fetch() ?: null);
}
if ($reason) {
    faceProcessRespond(false, $reason);
}

// Validate and save the uploaded image temporarily
$upload = $_FILES['image'];
if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || ($upload['size'] ?? 0) > 4500000
    || @getimagesize($upload['tmp_name']) === false) {
    faceProcessRespond(false, 'Invalid image data.');
}
$tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'face_' . bin2hex(random_bytes(8)) . '.jpg';
if (!move_uploaded_file($upload['tmp_name'], $tempFile)) {
    faceProcessRespond(false, 'Failed to save image');
}

// Identify only (no attendance writing in Python)
$pythonScript = realpath(__DIR__ . '/../../facial_recognition.py');
if (!$pythonScript) {
    @unlink($tempFile);
    faceProcessRespond(false, 'facial_recognition.py not found.');
}
$pythonBinary = defined('PYTHON_BINARY') ? PYTHON_BINARY : 'python';

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $command = 'cmd /C ""' . str_replace('/', '\\', $pythonBinary) . '" "' . str_replace('/', '\\', $pythonScript)
             . '" identify "' . str_replace('/', '\\', $tempFile) . '"" 2>&1';
} else {
    $command = escapeshellarg($pythonBinary) . ' ' . escapeshellarg($pythonScript)
             . ' identify ' . escapeshellarg($tempFile) . ' 2>&1';
}
$output = [];
exec($command, $output, $returnCode);
@unlink($tempFile);
$outputText = trim(implode("\n", $output));

if ($returnCode !== 0) {
    faceProcessRespond(false, 'Recognition failed: ' . $outputText);
}

// identify prints "EMP-0001|Recognized with 93% confidence"
[$matchedId] = array_pad(explode('|', $outputText, 2), 2, '');
if (strtoupper(trim($matchedId)) !== strtoupper($empId)) {
    faceProcessRespond(false, 'Face does not match the logged-in employee.');
}

// Record through the shared rules
[$ok, $msg, $data] = attRecord($db, $empId, $recordAction, null);
if ($ok) {
    auditLog($recordAction === 'time_in' ? 'Time In' : 'Time Out', 'Attendance', 'Face check-in - ' . $msg);
}
faceProcessRespond($ok, $msg, ['employee_id' => $empId, 'action' => $action]);