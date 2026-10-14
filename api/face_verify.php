<?php
// ============================================================
// Directpath HRMS — WFH Time In / Time Out Verification
//
// A WFH punch is accepted only when ALL of these pass:
//   1. Logged-in employee session, work mode = WFH (onsite staff use the terminal)
//   2. A one-time verification session (issued by 'challenge', valid 2 minutes,
//      bound to the action + IP) — stops replaying an old photo / scripted calls
//   3. Live face identifies and matches the logged-in employee (facial_recognition.py `identify`)
//   5. Attendance rules: weekday, not a holiday, inside the time-in window
// Every attempt (pass or fail) is written to wfh_verification_log with IP, device,
// optional location and the captured photo. 5 failures in 15 minutes lock the
// employee out temporarily.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance_rules.php';

header('Content-Type: application/json');

function wfhRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

if (!isset($_SESSION['employee_id'])) wfhRespond(false, 'Not authenticated');
apiRequireRole('employee');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wfhRespond(false, 'Invalid request method.');

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';
$db     = getDB();
$empId  = $_SESSION['employee_id'];
$ip     = $_SERVER['REMOTE_ADDR'] ?? '';

// ── Work mode: only WFH employees may use remote verification ─────────
$workMode = 'wfh';
try {
    $stmt = $db->prepare("SELECT work_mode FROM users WHERE employee_id = ?");
    $stmt->execute([$empId]);
    $workMode = $stmt->fetchColumn() ?: 'wfh';
} catch (PDOException $e) { /* column missing -> treat as wfh, same as the page */ }
if ($workMode !== 'wfh') {
    wfhRespond(false, 'You are scheduled Onsite. Use the office terminal to record attendance.');
}

// ── Failed-attempt lockout ────────────────────────────────────────────
function wfhRecentFailures(PDO $db, string $empId): int {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM wfh_verification_log
                              WHERE employee_id = ? AND result = 'failed'
                                AND created_at >= (NOW() - INTERVAL " . (int)WFH_LOCK_MINUTES . " MINUTE)");
        $stmt->execute([$empId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function wfhLog(PDO $db, string $empId, string $action, string $result, string $reason, array $in, ?string $photo = null, ?string $flags = null): void {
    try {
        $lat = isset($in['lat']) && is_numeric($in['lat']) ? round((float)$in['lat'], 6) : null;
        $lng = isset($in['lng']) && is_numeric($in['lng']) ? round((float)$in['lng'], 6) : null;
        $acc = isset($in['accuracy']) && is_numeric($in['accuracy']) ? (int)round((float)$in['accuracy']) : null;
        $db->prepare("INSERT INTO wfh_verification_log
                (employee_id, action, result, reason, ip_address, user_agent, latitude, longitude, accuracy_m, photo, flags)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$empId, $action, $result, substr($reason, 0, 255), $_SERVER['REMOTE_ADDR'] ?? null,
                      substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $lat, $lng, $acc, $photo, $flags]);
    } catch (PDOException $e) { /* log table not migrated yet — never block attendance for it */ }
    if ($result === 'failed') {
        auditLog('WFH Verification Failed', 'Attendance', "$action — $reason");
    }
}

// ── STEP 1: issue a one-time verification session ─────────────────────
if ($action === 'challenge') {
    $req = $input['for'] ?? '';
    if (!in_array($req, ['time_in', 'time_out'], true)) wfhRespond(false, 'Invalid action.');

    if (wfhRecentFailures($db, $empId) >= WFH_MAX_FAILED) {
        wfhRespond(false, 'Too many failed verification attempts. Try again in ' . WFH_LOCK_MINUTES . ' minutes or contact HR.');
    }

    // Fail early with a clear message instead of after the camera scan
    if ($req === 'time_in') {
        $reason = attTimeInBlockReason($db);
        if ($reason) wfhRespond(false, $reason);
    } else {
        $stmt = $db->prepare("SELECT time_in, time_out FROM attendance WHERE employee_id = ? AND date = CURDATE()");
        $stmt->execute([$empId]);
        $reason = attTimeOutBlockReason($stmt->fetch() ?: null);
        if ($reason) wfhRespond(false, $reason);
    }

    $token = bin2hex(random_bytes(16));
    $_SESSION['wfh_challenge'] = [
        'token'   => $token,
        'action'  => $req,
        'expires' => time() + WFH_CHALLENGE_TTL,
        'ip'      => $ip,
    ];
    wfhRespond(true, 'Verification session started.', ['token' => $token, 'ttl' => WFH_CHALLENGE_TTL]);
}

// ── STEP 2: verify and record ─────────────────────────────────────────
if (!in_array($action, ['time_in', 'time_out'], true)) wfhRespond(false, 'Invalid action.');
if (empty($input['image'])) wfhRespond(false, 'Missing image.');

if (wfhRecentFailures($db, $empId) >= WFH_MAX_FAILED) {
    wfhRespond(false, 'Too many failed verification attempts. Try again in ' . WFH_LOCK_MINUTES . ' minutes or contact HR.');
}

// (2) one-time verification session
$ch = $_SESSION['wfh_challenge'] ?? null;
unset($_SESSION['wfh_challenge']); // single use, whatever happens next
if (!$ch || !hash_equals($ch['token'], (string)($input['token'] ?? ''))
    || $ch['action'] !== $action || $ch['expires'] < time() || $ch['ip'] !== $ip) {
    wfhLog($db, $empId, $action, 'failed', 'Verification session missing, expired or reused', $input);
    wfhRespond(false, 'Verification session expired. Please close this window and start again.', ['restart' => true]);
}

// (5) attendance rules (checked before the slow face scan)
if ($action === 'time_in') {
    $reason = attTimeInBlockReason($db);
} else {
    $stmt = $db->prepare("SELECT time_in, time_out FROM attendance WHERE employee_id = ? AND date = CURDATE()");
    $stmt->execute([$empId]);
    $reason = attTimeOutBlockReason($stmt->fetch() ?: null);
}
if ($reason) {
    wfhLog($db, $empId, $action, 'failed', $reason, $input);
    wfhRespond(false, $reason);
}

// Decode the photo
$imageData = $input['image'];
if (strpos($imageData, ',') !== false) $imageData = explode(',', $imageData, 2)[1];
$decoded = base64_decode($imageData, true);
if (!$decoded || strlen($decoded) > 4500000 || @getimagesizefromstring($decoded) === false) {
    wfhRespond(false, 'Invalid image data.', ['restart' => true]);
}

$tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'verify_' . preg_replace('/[^A-Za-z0-9_-]/', '', $empId) . '_' . bin2hex(random_bytes(4)) . '.jpg';
if (file_put_contents($tempFile, $decoded) === false) wfhRespond(false, 'Failed to save temporary image.', ['restart' => true]);

// (4) live face match — `identify` prints "EMP-0001|Recognized with 93% confidence"
$pythonScript = realpath(__DIR__ . '/../facial_recognition.py');
if (!$pythonScript) { @unlink($tempFile); wfhRespond(false, 'facial_recognition.py not found.', ['restart' => true]); }
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
    wfhLog($db, $empId, $action, 'failed', 'Face not recognized: ' . $outputText, $input);
    wfhRespond(false, 'Face not recognized: ' . $outputText, ['restart' => true]);
}
[$matchedId] = array_pad(explode('|', $outputText, 2), 2, '');
if (strtoupper(trim($matchedId)) !== strtoupper($empId)) {
    wfhLog($db, $empId, $action, 'failed', 'Face does not match the logged-in employee', $input);
    wfhRespond(false, 'Face does not match the logged-in employee.', ['restart' => true]);
}

// Keep the evidence photo
$photoName = 'wfh_' . preg_replace('/[^A-Za-z0-9_-]/', '', $empId) . '_' . date('Ymd_His') . '_' . $action . '.jpg';
if (!is_dir(CAPTURED_PHOTO_PATH)) @mkdir(CAPTURED_PHOTO_PATH, 0755, true);
if (file_put_contents(CAPTURED_PHOTO_PATH . $photoName, $decoded) === false) $photoName = null;

// Flag a change of network since the last successful WFH punch (review aid only)
$flags = null;
try {
    $stmt = $db->prepare("SELECT ip_address FROM wfh_verification_log WHERE employee_id = ? AND result = 'success' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$empId]);
    $lastIp = $stmt->fetchColumn();
    if ($lastIp && $lastIp !== $ip) $flags = 'new_ip';
} catch (PDOException $e) {}
if (!isset($input['lat']) || !is_numeric($input['lat'])) $flags = trim(($flags ? $flags . ',' : '') . 'no_location', ',');

// Record attendance
[$ok, $msg, $data] = attRecord($db, $empId, $action, $action === 'time_in' ? $photoName : null);
if (!$ok) {
    wfhLog($db, $empId, $action, 'failed', $msg, $input, $photoName);
    wfhRespond(false, $msg);
}

wfhLog($db, $empId, $action, 'success', $msg, $input, $photoName, $flags);
auditLog($action === 'time_in' ? 'Time In' : 'Time Out', 'Attendance', 'WFH verified — ' . $msg);
wfhRespond(true, $msg, $data);
