<?php
/**
 * Fix passwords directly in TiDB Cloud 'test' database (what Render uses).
 * Run: php fix_tidb_test_db.php
 */
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Override DB_NAME to 'test' — what Render is actually using
putenv('DB_NAME=test');
$_ENV['DB_NAME'] = 'test';
$_SERVER['DB_NAME'] = 'test';

// Also override DB_HOST to TiDB Cloud (not localhost)
// Read from .env manually for the credentials but override DB_NAME
$envLines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($envLines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    if (str_contains($line, '=')) {
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \"'");
        // Load everything EXCEPT override DB_NAME and DB_HOST
        if ($name === 'DB_NAME') {
            // Force 'test' (Render's DB_NAME)
            putenv('DB_NAME=test');
        } elseif ($name === 'DB_HOST') {
            // Force TiDB Cloud host (not localhost)
            putenv("DB_HOST={$value}");
        } else {
            putenv("{$name}={$value}");
        }
    }
}

// Use TiDB credentials from healthz output (what Render is actually using)
$tidbHost = 'gateway01.ap-southeast-1.prod.aws.tidbcloud.com';
$tidbPort = 4000;
$tidbDb   = 'test';
// Get user/pass from env
$tidbUser = getenv('DB_USER') ?: '';
$tidbPass = getenv('DB_PASS') ?: '';

if (empty($tidbUser)) {
    die("ERROR: DB_USER not set in .env\n");
}

echo "Connecting to TiDB: $tidbHost:$tidbPort / DB: $tidbDb / User: $tidbUser\n";

$dsn = "mysql:host=$tidbHost;port=$tidbPort;dbname=$tidbDb;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_SSL_CA       => '/etc/ssl/certs/ca-certificates.crt',
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
];

// Windows doesn't have /etc/ssl, use a different approach
if (PHP_OS_FAMILY === 'Windows') {
    unset($options[PDO::MYSQL_ATTR_SSL_CA]);
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
}

try {
    $pdo = new PDO($dsn, $tidbUser, $tidbPass, $options);
    echo "OK: Connected to TiDB 'test' database.\n\n";
} catch (PDOException $e) {
    die("FAIL: " . $e->getMessage() . "\n");
}

// Check what's in the test database
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$demoUsers  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username LIKE 'demo_%'")->fetchColumn();
echo "Total users in 'test' DB: $totalUsers\n";
echo "Demo users in 'test' DB: $demoUsers\n\n";

$demoPassword = 'Demo@12345';
$hash = password_hash($demoPassword, PASSWORD_DEFAULT);

// Update all demo accounts
$upd = $pdo->prepare("UPDATE users SET password_hash = :h, is_active = 1 WHERE username LIKE 'demo_%'");
$upd->execute(['h' => $hash]);
echo "Updated {$upd->rowCount()} demo account(s) in 'test' database.\n\n";

// Also fix any regular users whose passwords might be wrong
$allUpdated = $pdo->query("SELECT id, username, role, is_active, password_hash FROM users WHERE username LIKE 'demo_%'")->fetchAll();
echo "=== Verification ===\n";
foreach ($allUpdated as $r) {
    $ok = password_verify($demoPassword, $r['password_hash']);
    echo ($ok ? "[OK]  " : "[FAIL] ") . $r['username'] . " ({$r['role']}) active={$r['is_active']}\n";
}

echo "\nDONE. All demo passwords set to 'Demo@12345' in Render's 'test' database.\n";
