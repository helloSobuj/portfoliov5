<?php
// One-time installer: creates the tables and the first developer (admin) account.
// It locks itself once an admin exists. Delete this file after setup.

declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();

$msg = ''; $done = false;
try {
    db()->exec(file_get_contents(__DIR__ . '/schema.sql'));
    migrate();
    $hasAdmin = (bool)q("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1")->fetch();
    if ($hasAdmin) {
        $done = true;
        $msg = 'সেটআপ আগেই শেষ, ডেটাবেসও আপডেট করা হয়েছে। নিরাপত্তার জন্য setup.php ফাইলটা এখনই ডিলিট করুন।';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('পেজ রিফ্রেশ করে আবার চেষ্টা করুন।');
        $name = trim((string)($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $pass = (string)($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('নাম ও সঠিক ইমেইল দিন।');
        if (mb_strlen($pass) < 10) throw new RuntimeException('অ্যাডমিন পাসওয়ার্ড কমপক্ষে ১০ অক্ষরের দিন।');
        q("INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,'admin')
           ON DUPLICATE KEY UPDATE role = 'admin', password_hash = VALUES(password_hash)", [$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        $done = true;
        $msg = 'অ্যাডমিন অ্যাকাউন্ট তৈরি হয়েছে। এখন setup.php ডিলিট করুন, তারপর admin.html থেকে লগইন করুন।';
    }
} catch (PDOException $e) {
    $msg = 'ডেটাবেসে কানেক্ট করা যায়নি। config.php-তে db_name, db_user, db_pass ঠিক আছে কিনা দেখুন। (' . $e->getMessage() . ')';
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Brings tables from an older install up to date. Safe to run many times.
function migrate(): void {
    $col = fn(string $t, string $c) => (bool)q('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c])->fetch();
    db()->exec("ALTER TABLE users MODIFY role ENUM('customer','developer','admin') NOT NULL DEFAULT 'customer'");
    if (!$col('users', 'active')) db()->exec('ALTER TABLE users ADD active TINYINT(1) NOT NULL DEFAULT 1 AFTER role');
    if (!$col('orders', 'developer_id')) db()->exec('ALTER TABLE orders ADD developer_id INT UNSIGNED NULL AFTER info_json, ADD KEY ix_orders_dev (developer_id)');
    if (!$col('orders', 'cancelled')) db()->exec('ALTER TABLE orders ADD cancelled TINYINT(1) NOT NULL DEFAULT 0 AFTER developer_id');
    if (!$col('visits', 'city')) db()->exec('ALTER TABLE visits ADD browser VARCHAR(30) NULL AFTER device, ADD os VARCHAR(20) NULL AFTER browser,
        ADD country CHAR(2) NULL AFTER os, ADD country_name VARCHAR(60) NULL AFTER country, ADD region VARCHAR(80) NULL AFTER country_name, ADD city VARCHAR(80) NULL AFTER region');
    if (!$col('users', 'avatar')) db()->exec('ALTER TABLE users ADD avatar VARCHAR(60) NULL AFTER active');
    if (!$col('users', 'email_verified_at')) db()->exec('ALTER TABLE users ADD email_verified_at DATETIME NULL AFTER avatar, ADD email_notify TINYINT(1) NOT NULL DEFAULT 1 AFTER email_verified_at,
        ADD ref_code VARCHAR(12) NULL AFTER email_notify, ADD referred_by INT UNSIGNED NULL AFTER ref_code, ADD UNIQUE KEY uq_users_ref (ref_code)');
    if (!$col('orders', 'referrer_id')) db()->exec('ALTER TABLE orders ADD referrer_id INT UNSIGNED NULL AFTER cancelled');
    if (!$col('orders', 'domain')) db()->exec('ALTER TABLE orders ADD domain VARCHAR(253) NULL AFTER referrer_id');
    if (!$col('domain_cache', 'source')) db()->exec('ALTER TABLE domain_cache ADD source VARCHAR(8) NULL AFTER status');
    db()->exec("ALTER TABLE files MODIFY kind ENUM('logo','csv','attachment','license','nid') NOT NULL DEFAULT 'attachment'");
    if (!$col('live_visitors', 'city')) db()->exec('ALTER TABLE live_visitors ADD browser VARCHAR(30) NULL AFTER device, ADD city VARCHAR(80) NULL AFTER browser, ADD country CHAR(2) NULL AFTER city');
}
?><!doctype html>
<html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Techill সেটআপ</title>
<style>body{font-family:system-ui,sans-serif;background:#f7f9fc;color:#121822;margin:0;padding:40px 16px}main{max-width:440px;margin:auto;background:#fff;border:1px solid #e1e7ef;border-radius:16px;padding:28px}h1{font-size:1.4rem;margin:0 0 16px}label{display:grid;gap:6px;margin-bottom:14px;font-weight:600}input{font:inherit;padding:10px 12px;border:1.5px solid #e1e7ef;border-radius:10px}button{font:inherit;font-weight:600;background:#0a84f0;color:#fff;border:0;border-radius:10px;padding:12px 20px;cursor:pointer}.msg{background:#e8f3ff;border-radius:10px;padding:12px 14px;margin-bottom:16px}</style>
</head><body><main>
<h1>Techill সেটআপ</h1>
<?php if ($msg): ?><p class="msg"><?= $h($msg) ?></p><?php endif; ?>
<?php if (!$done): ?>
<p>প্রথম ডেভেলপার (অ্যাডমিন) অ্যাকাউন্ট তৈরি করুন। এই অ্যাকাউন্ট দিয়ে সব অর্ডার দেখা, অগ্রগতি আপডেট আর কাস্টমারের সাথে চ্যাট করা যাবে।</p>
<form method="post">
<input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
<label>নাম<input name="name" required></label>
<label>ইমেইল<input type="email" name="email" required></label>
<label>পাসওয়ার্ড (১০+ অক্ষর)<input type="password" name="password" minlength="10" required></label>
<button>অ্যাডমিন তৈরি করুন</button>
</form>
<?php endif; ?>
</main></body></html>
