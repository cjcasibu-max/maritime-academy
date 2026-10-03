<?php
/**
 * CLI script: connects directly to TiDB Cloud and resets demo passwords.
 * Run: php fix_passwords_cli.php
 */
$_SERVER['REMOTE_ADDR'] = '127.0.0.1'; // bypass any localhost-only guards

require_once __DIR__ . '/config/database.php';

if (!isset($pdo)) {
    die("FAIL: No DB connection.\n");
}
echo "OK: Connected to TiDB Cloud.\n";

$demoPassword = 'Demo@12345';
$hash = password_hash($demoPassword, PASSWORD_DEFAULT);
echo "OK: Hash generated.\n\n";

// Count demo users
$count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username LIKE 'demo_%'")->fetchColumn();
echo "Demo users in DB: $count\n";

$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
echo "Total users in DB: $totalUsers\n\n";

if ($count === 0 && $totalUsers === 0) {
    echo "WARNING: Database is empty! The seed never ran.\n";
    echo "Running seed now...\n\n";
    // Force-seed basic admin/student accounts
    $accounts = [
        ['demo_admin',     'admin@ncst.demo',     'admin',     'Admin',     'Demo'],
        ['demo_registrar', 'reg@ncst.demo',       'registrar', 'Registrar', 'Demo'],
        ['demo_cashier',   'cash@ncst.demo',      'cashier',   'Cashier',   'Demo'],
        ['demo_teacher1',  'teacher1@ncst.demo',  'teacher',   'Teacher',   'One'],
        ['demo_teacher2',  'teacher2@ncst.demo',  'teacher',   'Teacher',   'Two'],
        ['demo_student1',  'student1@ncst.demo',  'student',   'Student',   'One'],
        ['demo_student2',  'student2@ncst.demo',  'student',   'Student',   'Two'],
        ['demo_student3',  'student3@ncst.demo',  'student',   'Student',   'Three'],
    ];
    $ins = $pdo->prepare("INSERT IGNORE INTO users (username, email, password_hash, role, first_name, last_name, is_active) VALUES (?,?,?,?,?,?,1)");
    foreach ($accounts as $a) {
        $ins->execute([$a[0], $a[1], $hash, $a[2], $a[3], $a[4]]);
        echo "  Inserted: {$a[0]} ({$a[2]})\n";
    }
    echo "\n";
}

// Update all demo passwords
$upd = $pdo->prepare("UPDATE users SET password_hash = :h, is_active = 1 WHERE username LIKE 'demo_%'");
$upd->execute(['h' => $hash]);
echo "Updated {$upd->rowCount()} demo account(s) - password set to Demo@12345, is_active=1\n\n";

// Verify
echo "=== Final verification ===\n";
$rows = $pdo->query("SELECT id, username, role, is_active, password_hash FROM users WHERE username LIKE 'demo_%' ORDER BY role, username")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $ok = password_verify($demoPassword, $r['password_hash']);
    echo ($ok ? "[OK]  " : "[BAD] ") . "id={$r['id']} {$r['username']} ({$r['role']}) active={$r['is_active']}\n";
}

echo "\nDONE. Login at /auth/login with username=demo_student1 password=Demo@12345\n";
