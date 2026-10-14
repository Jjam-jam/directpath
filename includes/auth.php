<?php
// ============================================================
// Directpath HRMS — Auth & Helper Functions
// Pinagbago: Gumagamit na ng 'users' table at 'user_roles' table
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';

// ── requireLogin() ────────────────────────────────────────
// Tinitingnan kung naka-login ang user at hindi pa expired ang session
function requireLogin(): void {
    if (!isset($_SESSION['employee_id'])) {
        header('Location: ' . SITE_URL . '/index.php');
        exit;
    }
    if (!refreshSessionRole()) {
        header('Location: ' . SITE_URL . '/index.php?unauthorized=1');
        exit;
    }
    // Auto-logout kapag nag-idle ng 8 hours
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        session_destroy();
        header('Location: ' . SITE_URL . '/index.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
    enforceCeoReadOnly();
}

// ── enforceCeoReadOnly() ──────────────────────────────────
// The CEO account is view-only. Any request that could change data (POST/PUT/DELETE...)
// is refused server-side, no matter which page or API it targets. The only exceptions are
// profile.php (own password/photo), payroll_approval.php (Approve/Reject payroll) and contribution_approval.php (Approve/Reject contribution schedules).
function enforceCeoReadOnly(): void {
    if (($_SESSION['role'] ?? '') !== 'ceo') return;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;
    // Allowed CEO POSTs: own profile + the payroll approve/reject decision (the CEO's only write action)
    if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['profile.php', 'payroll_approval.php', 'contribution_approval.php'], true)) return;

    http_response_code(403);
    if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'The CEO account is view-only.']);
    } else {
        echo 'The CEO account is view-only. <a href="javascript:history.back()">Go back</a>';
    }
    exit;
}

function refreshSessionRole(): bool {
    if (!isset($_SESSION['employee_id'])) return false;

    $sql = "
        SELECT r.role_name%s
        FROM users u
        JOIN user_roles ur ON ur.employee_id = u.employee_id
        JOIN roles r ON r.id = ur.role_id
        WHERE u.employee_id = ? AND u.status = 'active'
        ORDER BY ur.id
        LIMIT 1
    ";
    try {
        $stmt = getDB()->prepare(sprintf($sql, ', u.is_team_leader'));
        $stmt->execute([$_SESSION['employee_id']]);
    } catch (PDOException $e) {
        // is_team_leader column not migrated yet: keep the system usable
        $stmt = getDB()->prepare(sprintf($sql, ''));
        $stmt->execute([$_SESSION['employee_id']]);
    }
    $row  = $stmt->fetch();
    $role = $row['role_name'] ?? false;

    if (!$role) {
        session_unset();
        session_destroy();
        return false;
    }

    $_SESSION['role'] = $role === 'admin' ? 'hr_admin' : $role;
    // Team Manager is a designation of a normal employee, not a separate role
    $_SESSION['is_team_leader'] = ($role === 'employee' && (int)($row['is_team_leader'] ?? 0) === 1) ? 1 : 0;
    return true;
}

// ── requireRole() ─────────────────────────────────────────
// Tinitingnan kung may tamang role ang user. Kung hindi, i-redirect sa login.
function requireRole(string ...$roles): void {
    requireLogin();
    if (!in_array($_SESSION['role'], $roles)) {
        header('Location: ' . SITE_URL . '/index.php?unauthorized=1');
        exit;
    }
}

// ── getCurrentUser() ──────────────────────────────────────
// Kinukuha ang current logged-in user mula sa USERS table (hindi na employees)
// Nag-a-assemble din ng full_name para compatible sa lumang code
function getCurrentUser(): array {
    if (!isset($_SESSION['employee_id'])) return [];
    $db   = getDB();
    // JOIN sa user_roles at roles para makuha ang role ng user
    $stmt = $db->prepare("
        SELECT u.*, r.role_name as role
        FROM users u
        JOIN user_roles ur ON ur.employee_id = u.employee_id
        JOIN roles r ON r.id = ur.role_id
        WHERE u.employee_id = ?
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['employee_id']]);
    $user = $stmt->fetch() ?: [];

    if (!empty($user)) {
        // Gawa ng 'name' property para compatible sa lumang code na gumagamit ng $user['name']
        $user['name'] = getFullName(
            $user['first_name'],
            $user['middle_name'] ?? null,
            $user['last_name']
        );
    }
    return $user;
}

// ── apiRequireRole() ──────────────────────────────────────
// Para sa API endpoints — nagre-return ng JSON error (hindi nag-re-redirect)
function apiRequireRole(string ...$roles): void {
    if (!refreshSessionRole()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
        exit;
    }
    if (!in_array($_SESSION['role'], $roles)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }
    enforceCeoReadOnly();
}

function csrfToken(): string {
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrfTokenIsValid(mixed $token): bool {
    return is_string($token)
        && isset($_SESSION['_csrf_token'])
        && is_string($_SESSION['_csrf_token'])
        && hash_equals($_SESSION['_csrf_token'], $token);
}
