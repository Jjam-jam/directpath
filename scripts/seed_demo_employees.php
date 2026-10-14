<?php
// ============================================================
// OPTIONAL demo data: tops every official department up to its target headcount
// (10 departments, 10 Team Managers, 102 employees in total) using placeholder names.
//   php scripts/seed_demo_employees.php
// Demo accounts: email firstname.lastname.N@directpath-demo.local, password "password".
// Run it only on a test/demo database.
// ============================================================
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Run this script from the command line.'); }
require_once __DIR__ . '/../config/config.php';
$db = getDB();
$first = ['Maria','Jose','Ana','Juan','Liza','Mark','Carla','Paolo','Grace','Rico','Nina','Leo','Joy','Dante','Kris','Ben','Ella','Ivan','Rose','Noel','Tina','Gabe','Mica','Ramon'];
$last  = ['Santos','Reyes','Cruz','Bautista','Ocampo','Garcia','Mendoza','Torres','Flores','Aquino','Dizon','Navarro','Castillo','Ramos','Villanueva','Soriano','Pascual','Manalo','Lim','Tan'];
$empRole = $db->query("SELECT id FROM roles WHERE role_name='employee'")->fetchColumn();
if (!$empRole) exit("Employee role is missing. Run the role migrations first.\n");
$hash = password_hash('password', PASSWORD_DEFAULT);
$added = 0; $n = 0;

foreach ($db->query("SELECT * FROM departments ORDER BY sort_order")->fetchAll() as $d) {
    $cnt = $db->prepare("SELECT COUNT(*) FROM users WHERE department=? AND role='employee' AND status='active'");
    $cnt->execute([$d['name']]);
    $have = (int)$cnt->fetchColumn();
    $hasTl = $db->prepare("SELECT 1 FROM users WHERE department=? AND is_team_leader=1 AND status='active'");
    $hasTl->execute([$d['name']]);
    $needTl = !$hasTl->fetchColumn();
    $staff = getDepartmentPositions($d['name'], false);
    $need  = max(0, (int)$d['target_headcount'] - $have);
    for ($i = 0; $i < $need; $i++) {
        $isTl = $needTl && $i === 0;
        $fn = $first[($n * 7 + $i) % count($first)]; $ln = $last[($n * 3 + $i * 5) % count($last)]; $n++;
        $last_id = $db->query("SELECT employee_id FROM users WHERE employee_id LIKE 'EMP-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $empId = 'EMP-' . str_pad(($last_id ? (int)substr($last_id, 4) + 1 : 1), 4, '0', STR_PAD_LEFT);
        $email = strtolower("$fn.$ln.") . ($have + $i + $d['id'] * 100) . '@directpath-demo.local';
        $pos   = $isTl ? $d['tl_position'] : $staff[$i % count($staff)];
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO users (employee_id, first_name, last_name, email, password, department, position, date_hired, basic_salary, allowances, work_mode, role, status, is_team_leader)
                          VALUES (?,?,?,?,?,?,?,?,?,?,'wfh','employee','active',?)")
               ->execute([$empId, $fn, $ln, $email, $hash, $d['name'], $pos, date('Y-m-d', strtotime('-' . (30 + $n * 9) . ' days')), $isTl ? 45000 : 22000 + ($n % 5) * 1500, 1000, $isTl ? 1 : 0]);
            $db->prepare("INSERT INTO user_roles (employee_id, role_id) VALUES (?,?)")->execute([$empId, $empRole]);
            $db->prepare("INSERT IGNORE INTO leave_balances (employee_id, year) VALUES (?,?)")->execute([$empId, date('Y')]);
            $db->commit(); $added++;
        } catch (Throwable $e) { $db->rollBack(); echo "Skipped $empId: " . $e->getMessage() . "\n"; }
    }
    syncDepartmentTeam($db, $d['name']);   // everyone in the department -> the department's Team Manager
    echo str_pad($d['name'], 28) . " target {$d['target_headcount']} | had $have | added $need\n";
}
echo "Done. Added $added employee(s).\n";
$tot = $db->query("SELECT COUNT(*) FROM users u JOIN departments d ON d.name=u.department WHERE u.role='employee' AND u.status='active'")->fetchColumn();
$tls = $db->query("SELECT COUNT(*) FROM users WHERE is_team_leader=1 AND status='active'")->fetchColumn();
echo "Employees in the 10 departments: $tot | Team Managers: $tls\n";
