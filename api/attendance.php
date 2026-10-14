<?php
// api/attendance.php
// Handles: time_in, time_out, today_status
// Uses the shared rules in includes/attendance_rules.php (time-in window, holidays,
// late vs undertime). WFH employees are verified through api/face_verify.php.

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/attendance_rules.php';

header('Content-Type: application/json');

if (!isset($_SESSION['employee_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit;
}
apiRequireRole('employee');

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_POST['action'] ?? '';
$db     = getDB();
$empId  = $_SESSION['employee_id'];

if ($action === 'time_in' || $action === 'time_out') {
    // Optional captured photo
    $photoName = null;
    $photoData = $input['photo'] ?? '';
    if ($action === 'time_in' && $photoData && strpos($photoData, 'data:image') === 0) {
        $parts = explode(',', $photoData, 2);
        $bin   = isset($parts[1]) ? base64_decode($parts[1], true) : false;
        if ($bin !== false && strlen($bin) < 4500000) {
            $photoName = 'att_' . preg_replace('/[^A-Za-z0-9_-]/', '', $empId) . '_' . time() . '.jpg';
            if (!is_dir(CAPTURED_PHOTO_PATH)) mkdir(CAPTURED_PHOTO_PATH, 0755, true);
            file_put_contents(CAPTURED_PHOTO_PATH . $photoName, $bin);
        }
    }

    [$ok, $msg, $data] = attRecord($db, $empId, $action, $photoName);
    if ($ok) {
        auditLog($action === 'time_in' ? 'Time In' : 'Time Out', 'Attendance', $msg);
    }
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $data));
    exit;
}

if ($action === 'today_status') {
    $stmt = $db->prepare("SELECT * FROM attendance WHERE employee_id=? AND date=CURDATE()");
    $stmt->execute([$empId]);
    echo json_encode(['success' => true, 'attendance' => $stmt->fetch() ?: null]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
