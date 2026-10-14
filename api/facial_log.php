<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/attendance_rules.php';

header('Content-Type: application/json');

function facialLogRespond(array $response): void
{
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Status check used by facial_login.php: is Time In open right now?
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'status') {
    header('Cache-Control: no-store');
    $reason = attTimeInBlockReason(getDB());
    facialLogRespond([
        'success'         => true,
        'time_in_allowed' => $reason === null,
        'message'         => $reason ?? '',
    ]);
}
    facialLogRespond(['success' => false, 'message' => 'Invalid request method.']);
}

$imageData = $_POST['image'] ?? '';
if (!is_string($imageData) || strlen($imageData) > 6000000 || !preg_match('/^data:image\/jpeg;base64,/', $imageData)) {
    facialLogRespond(['success' => false, 'message' => 'Invalid or oversized image. Please capture the photo again.']);
}

$action = $_POST['action'] ?? '';
if (!in_array($action, ['in', 'out'], true)) {
    facialLogRespond(['success' => false, 'message' => 'Invalid action. Must be "in" or "out".']);
}

$imageBinary = base64_decode(substr($imageData, strpos($imageData, ',') + 1), true);
if ($imageBinary === false || strlen($imageBinary) > 4500000) {
    facialLogRespond(['success' => false, 'message' => 'Unable to decode the image.']);
}

$capturedPath = CAPTURED_PHOTO_PATH;
if (!is_dir($capturedPath) && !mkdir($capturedPath, 0755, true) && !is_dir($capturedPath)) {
    facialLogRespond(['success' => false, 'message' => 'Failed to prepare photo storage.']);
}
$destFile = $capturedPath . 'facial_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
if (file_put_contents($destFile, $imageBinary) === false) {
    facialLogRespond(['success' => false, 'message' => 'Failed to save captured photo.']);
}

$pythonScript = realpath(__DIR__ . '/../facial_recognition.py');
$pythonBinary = defined('PYTHON_BINARY') ? PYTHON_BINARY : 'python';
if (!$pythonScript) {
    facialLogRespond(['success' => false, 'message' => 'facial_recognition.py not found.']);
}

// Identify only. Attendance is recorded by PHP so the shared rules apply.
$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
if ($isWindows) {
    $command = 'cmd /C ""' . str_replace('/', '\\', $pythonBinary) . '" "' . str_replace('/', '\\', $pythonScript)
             . '" identify "' . str_replace('/', '\\', $destFile) . '"" 2>&1';
} else {
    $command = escapeshellarg($pythonBinary) . ' ' . escapeshellarg($pythonScript)
             . ' identify ' . escapeshellarg($destFile) . ' 2>&1';
}

$output = [];
exec($command, $output, $returnCode);
if ($returnCode !== 0) {
    $error = strtolower(implode(' ', $output));
    if (strpos($error, 'no face') !== false) {
        facialLogRespond(['success' => false, 'message' => 'No face detected. Please retake the photo with a clear, front-facing view.']);
    }
    if (strpos($error, 'multiple') !== false) {
        facialLogRespond(['success' => false, 'message' => 'Multiple faces detected. Please ensure only one person is in the frame.']);
    }
    if (strpos($error, 'no trained faces') !== false || strpos($error, 'face not recognized') !== false) {
        facialLogRespond(['success' => false, 'message' => 'Face not registered. This person is not enrolled in the system.']);
    }
    facialLogRespond(['success' => false, 'message' => 'Attendance logging failed. Please try again.']);
}

// identify prints "EMP-0001|Recognized with 93% confidence"
[$matchedId] = array_pad(explode('|', trim(implode(' ', $output)), 2), 2, '');
$empId = trim($matchedId);
if ($empId === '') {
    facialLogRespond(['success' => false, 'message' => 'Face not registered. This person is not enrolled in the system.']);
}

$db = getDB();

// This kiosk is for onsite employees only (same check Python used to do)
$stmt = $db->prepare("SELECT work_mode FROM users WHERE employee_id = ? LIMIT 1");
$stmt->execute([$empId]);
if ($stmt->fetchColumn() !== 'onsite') {
    facialLogRespond(['success' => false, 'message' => 'This employee is not scheduled for onsite work.']);
}

// Record through the shared rules (window, weekends, holidays, late, undertime)
[$ok, $msg, $data] = attRecord($db, $empId, $action === 'in' ? 'time_in' : 'time_out', basename($destFile));
facialLogRespond([
    'success'     => $ok,
    'message'     => $msg,
    'employee_id' => $empId,
    'action'      => $action,
]);