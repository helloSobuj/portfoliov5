<?php
// JSON API for the landing page checkout and the dashboard.
// Every call is api.php?action=<name>. POST calls need the X-CSRF-Token header from `me`.

declare(strict_types=1);
require __DIR__ . '/lib.php';

start_session();
$action = $_GET['action'] ?? '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost) check_csrf();

const STAGES = 5;          // তথ্য জমা, পেমেন্ট যাচাই, সেটআপ চলছে, রিভিউ, ডেলিভারি
const LOGIN_LIMIT = 10;    // failed logins per IP per 15 minutes

try {
    switch ($action) {
        case 'me':           out(['user' => current_user(), 'csrf' => $_SESSION['csrf']]);
        case 'register':     $isPost || fail('POST only', 405); do_register();
        case 'login':        $isPost || fail('POST only', 405); do_login();
        case 'logout':       $isPost || fail('POST only', 405); session_destroy(); out([]);
        case 'order_create': $isPost || fail('POST only', 405); order_create();
        case 'orders':       orders_list();
        case 'order':        order_detail();
        case 'messages':     messages_since();
        case 'message_send': $isPost || fail('POST only', 405); message_send();
        case 'file_upload':  $isPost || fail('POST only', 405); file_upload();
        case 'file':         file_download();
        case 'info_update':  $isPost || fail('POST only', 405); info_update();
        case 'admin_update': $isPost || fail('POST only', 405); admin_update();
        default:             fail('Unknown action', 404);
    }
} catch (PDOException $e) {
    error_log('techill api: ' . $e->getMessage());
    fail('সার্ভারে সমস্যা হয়েছে, একটু পরে চেষ্টা করুন।', 500);
}

/* ---------- auth ---------- */

function throttle(): void {
    q('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
    $n = (int)q('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > NOW() - INTERVAL 15 MINUTE', [client_ip()])->fetchColumn();
    if ($n >= LOGIN_LIMIT) fail('অনেকবার চেষ্টা হয়েছে, ১৫ মিনিট পরে আবার চেষ্টা করুন।', 429);
}

function note_attempt(): void { q('INSERT INTO login_attempts (ip) VALUES (?)', [client_ip()]); }

// Creates a customer account, or logs into an existing one when the password matches.
function create_or_login(string $name, string $email, string $phone, string $pass): int {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('সঠিক ইমেইল দিন।');
    if (mb_strlen($pass) < 8) fail('পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।');
    $row = q('SELECT id, password_hash FROM users WHERE email = ?', [$email])->fetch();
    if ($row) {
        if (!password_verify($pass, $row['password_hash'])) { note_attempt(); fail('এই ইমেইলে আগেই অ্যাকাউন্ট আছে, পাসওয়ার্ড মেলেনি।', 409); }
        return (int)$row['id'];
    }
    if ($name === '') fail('নাম দিন।');
    if ($phone !== '' && !valid_phone($phone)) fail('সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন।');
    note_attempt();
    q('INSERT INTO users (name, email, phone, password_hash) VALUES (?,?,?,?)', [$name, $email, $phone ?: null, password_hash($pass, PASSWORD_DEFAULT)]);
    return (int)db()->lastInsertId();
}

function do_register(): void {
    throttle();
    $email = mb_strtolower(str_in('email', 190));
    if (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) fail('এই ইমেইলে আগেই অ্যাকাউন্ট আছে, লগইন করুন।', 409);
    $id = create_or_login(str_in('name', 120), $email, str_in('phone', 20), (string)($_POST['password'] ?? ''));
    login_as($id);
    out(['user' => current_user(), 'csrf' => $_SESSION['csrf']]);
}

function do_login(): void {
    throttle();
    $row = q('SELECT id, password_hash FROM users WHERE email = ?', [mb_strtolower(str_in('email', 190))])->fetch();
    if (!$row || !password_verify((string)($_POST['password'] ?? ''), $row['password_hash'])) {
        note_attempt();
        fail('ইমেইল বা পাসওয়ার্ড ভুল।', 401);
    }
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $row['id']]);
    }
    q('DELETE FROM login_attempts WHERE ip = ?', [client_ip()]);
    login_as((int)$row['id']);
    out(['user' => current_user(), 'csrf' => $_SESSION['csrf']]);
}

/* ---------- shaping rows for the frontend ---------- */

function order_out(array $o, ?array $viewer = null): array {
    $r = [
        'id' => (int)$o['id'], 'code' => $o['code'], 'stack' => $o['stack'], 'pack' => $o['pack'], 'template' => $o['template'],
        'lines' => json_decode($o['lines_json'], true) ?: [], 'total' => (int)$o['total'], 'hours' => (int)$o['hours'],
        'products' => (int)$o['products'], 'pay_method' => $o['pay_method'], 'pay_sender' => $o['pay_sender'],
        'pay_trx' => $o['pay_trx'], 'pay_status' => $o['pay_status'], 'info' => json_decode($o['info_json'], true) ?: [],
        'stage' => (int)$o['stage'], 'progress' => (int)$o['progress'], 'site_url' => $o['site_url'],
        'deadline' => strtotime($o['deadline_at'] . ' UTC'), 'created' => strtotime($o['created_at'] . ' UTC'),
    ];
    if (isset($o['unread'])) $r['unread'] = (int)$o['unread'];
    if (is_admin($viewer) && isset($o['c_name'])) $r['customer'] = ['name' => $o['c_name'], 'email' => $o['c_email'], 'phone' => $o['c_phone']];
    return $r;
}

function message_rows(int $orderId, int $after = 0): array {
    $rows = q('SELECT m.id, m.user_id, m.from_admin, m.body, m.created_at, u.name AS by_name, f.id AS f_id, f.original_name AS f_name, f.size AS f_size
               FROM messages m LEFT JOIN users u ON u.id = m.user_id LEFT JOIN files f ON f.id = m.file_id
               WHERE m.order_id = ? AND m.id > ? ORDER BY m.id', [$orderId, $after])->fetchAll();
    return array_map(fn($m) => [
        'id' => (int)$m['id'], 'from_admin' => (bool)$m['from_admin'], 'system' => $m['user_id'] === null, 'body' => $m['body'], 'by' => $m['by_name'] ?? 'Techill',
        'file' => $m['f_id'] ? ['id' => (int)$m['f_id'], 'name' => $m['f_name'], 'size' => (int)$m['f_size']] : null,
        'created' => strtotime($m['created_at'] . ' UTC'),
    ], $rows);
}

function mark_seen(array $u, int $orderId): void {
    q('UPDATE messages SET seen = 1 WHERE order_id = ? AND from_admin = ? AND seen = 0', [$orderId, is_admin($u) ? 0 : 1]);
}

/* ---------- orders ---------- */

function order_create(): void {
    $u = current_user();
    if (!$u) {
        throttle();
        $id = create_or_login(str_in('admin_name', 120), mb_strtolower(str_in('email', 190)), str_in('phone', 20), (string)($_POST['password'] ?? ''));
        login_as($id);
        $u = current_user();
    }

    $stack = str_in('stack', 20);
    if (!in_array($stack, ['WooCommerce', 'Laravel'], true)) fail('প্ল্যাটফর্ম সঠিক নয়।');
    $pack = str_in('pack', 40); $template = str_in('template', 80);
    if ($pack === '' || $template === '') fail('প্যাকেজ ও টেমপ্লেট বাছাই করুন।');

    $lines = json_decode((string)($_POST['lines'] ?? ''), true);
    if (!is_array($lines) || !$lines || count($lines) > 40) fail('অর্ডারের হিসাব পাওয়া যায়নি।');
    $clean = []; $sum = 0;
    foreach ($lines as $l) {
        if (!is_array($l) || !is_string($l['label'] ?? null) || !is_int($l['amt'] ?? null) || $l['amt'] < 0) fail('অর্ডারের হিসাব সঠিক নয়।');
        $clean[] = ['label' => mb_substr($l['label'], 0, 120), 'amt' => $l['amt']];
        $sum += $l['amt'];
    }
    $total = (int)($_POST['total'] ?? -1);
    if ($total !== $sum) fail('মোট টাকার হিসাব মেলেনি।');
    $hours = (int)($_POST['hours'] ?? 0); $products = (int)($_POST['products'] ?? 0);
    if ($hours < 1 || $hours > 720 || $products < 0 || $products > 5000) fail('অর্ডারের তথ্য সঠিক নয়।');

    $method = str_in('pay_method', 20);
    if (!in_array($method, ['bKash', 'Nagad', 'Rocket'], true)) fail('পেমেন্ট মাধ্যম সঠিক নয়।');
    $sender = str_in('pay_sender', 20); $trx = str_in('pay_trx', 20);
    if (!valid_phone($sender)) fail('যে নম্বর থেকে পাঠিয়েছেন সেটা সঠিক নয়।');
    if (!preg_match('/^[A-Za-z0-9]{8,12}$/', $trx)) fail('Transaction ID সঠিক নয়।');

    $info = [];
    foreach (['admin_name' => 120, 'whatsapp' => 20, 'phone' => 20, 'fb_page' => 255, 'address' => 255, 'business_intro' => 3000, 'additional_info' => 3000] as $k => $max) $info[$k] = str_in($k, $max);
    if (!valid_phone($info['whatsapp']) || !valid_phone($info['phone'])) fail('সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন।');
    if ($info['business_intro'] === '') fail('ব্যবসার পরিচিতি লিখুন।');
    if (empty($_FILES['product_csv']['name'])) fail('প্রোডাক্ট CSV ফাইল দিন।');

    db()->beginTransaction();
    do { $code = 'TC-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)); }
    while (q('SELECT 1 FROM orders WHERE code = ?', [$code])->fetch());
    q('INSERT INTO orders (code, user_id, stack, pack, template, lines_json, total, hours, products, pay_method, pay_sender, pay_trx, info_json, deadline_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, UTC_TIMESTAMP() + INTERVAL ? HOUR)',
      [$code, $u['id'], $stack, $pack, $template, json_encode($clean, JSON_UNESCAPED_UNICODE), $total, $hours, $products,
       $method, $sender, $trx, json_encode($info, JSON_UNESCAPED_UNICODE), $hours]);
    $oid = (int)db()->lastInsertId();
    save_upload('logo', $oid, $u['id'], 'logo');
    save_upload('product_csv', $oid, $u['id'], 'csv');
    q('INSERT INTO order_events (order_id, stage, progress, note, created_by) VALUES (?,0,5,?,?)', [$oid, 'অর্ডার ও তথ্য জমা হয়েছে।', $u['id']]);
    q('INSERT INTO messages (order_id, from_admin, body) VALUES (?,1,?)',
      [$oid, "আসসালামু আলাইকুম! অর্ডার $code পেয়েছি। পেমেন্ট যাচাই করে কাজ শুরু করছি। কোনো প্রশ্ন বা নতুন তথ্য থাকলে এখানেই লিখুন।"]);
    db()->commit();

    out(['order' => order_out(q('SELECT * FROM orders WHERE id = ?', [$oid])->fetch()), 'user' => $u, 'csrf' => $_SESSION['csrf']]);
}

function orders_list(): void {
    $u = require_user();
    $unread = '(SELECT COUNT(*) FROM messages m WHERE m.order_id = o.id AND m.seen = 0 AND m.from_admin = ' . (is_admin($u) ? 0 : 1) . ') AS unread';
    $rows = is_admin($u)
        ? q("SELECT o.*, u.name c_name, u.email c_email, u.phone c_phone, $unread FROM orders o JOIN users u ON u.id = o.user_id ORDER BY o.id DESC LIMIT 500")->fetchAll()
        : q("SELECT o.*, $unread FROM orders o WHERE o.user_id = ? ORDER BY o.id DESC", [$u['id']])->fetchAll();
    out(['orders' => array_map(fn($o) => order_out($o, $u), $rows)]);
}

function order_detail(): void {
    $u = require_user();
    $o = order_for($u, (int)($_GET['id'] ?? 0));
    $o += q('SELECT name c_name, email c_email, phone c_phone FROM users WHERE id = ?', [$o['user_id']])->fetch() ?: [];
    $id = (int)$o['id'];
    mark_seen($u, $id);
    $events = array_map(fn($e) => ['id' => (int)$e['id'], 'stage' => (int)$e['stage'], 'progress' => (int)$e['progress'], 'note' => $e['note'], 'created' => strtotime($e['created_at'] . ' UTC')],
        q('SELECT * FROM order_events WHERE order_id = ? ORDER BY id DESC', [$id])->fetchAll());
    $files = array_map(fn($f) => ['id' => (int)$f['id'], 'kind' => $f['kind'], 'name' => $f['original_name'], 'size' => (int)$f['size'], 'by_admin' => $f['role'] === 'admin', 'created' => strtotime($f['created_at'] . ' UTC')],
        q('SELECT f.*, u.role FROM files f LEFT JOIN users u ON u.id = f.user_id WHERE f.order_id = ? ORDER BY f.id DESC', [$id])->fetchAll());
    out(['order' => order_out($o, $u), 'events' => $events, 'messages' => message_rows($id), 'files' => $files]);
}

/* ---------- chat & files ---------- */

function messages_since(): void {
    $u = require_user();
    $o = order_for($u, (int)($_GET['id'] ?? 0));
    mark_seen($u, (int)$o['id']);
    out(['messages' => message_rows((int)$o['id'], (int)($_GET['after'] ?? 0))]);
}

function message_send(): void {
    $u = require_user();
    $o = order_for($u, (int)($_POST['order_id'] ?? 0));
    $body = str_in('body', 4000);
    if ($body === '') fail('মেসেজ লিখুন।');
    q('INSERT INTO messages (order_id, user_id, from_admin, body) VALUES (?,?,?,?)', [$o['id'], $u['id'], is_admin($u) ? 1 : 0, $body]);
    out(['messages' => message_rows((int)$o['id'], (int)($_POST['after'] ?? 0))]);
}

function file_upload(): void {
    $u = require_user();
    $o = order_for($u, (int)($_POST['order_id'] ?? 0));
    $fid = save_upload('file', (int)$o['id'], $u['id'], 'attachment');
    if (!$fid) fail('একটা ফাইল বাছাই করুন।');
    $note = str_in('note', 1000);
    q('INSERT INTO messages (order_id, user_id, from_admin, body, file_id) VALUES (?,?,?,?,?)',
      [$o['id'], $u['id'], is_admin($u) ? 1 : 0, $note !== '' ? $note : 'ফাইল পাঠানো হয়েছে।', $fid]);
    out(['file_id' => $fid]);
}

function file_download(): void {
    $u = require_user();
    $f = q('SELECT * FROM files WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
    if (!$f) fail('ফাইল পাওয়া যায়নি।', 404);
    order_for($u, (int)$f['order_id']);
    $path = rtrim(cfg('upload_dir'), '/') . '/' . $f['stored_name'];
    if (!is_file($path)) fail('ফাইল পাওয়া যায়নি।', 404);
    $inline = in_array($f['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true);
    header('Content-Type: ' . ($inline ? $f['mime'] : 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'");
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($f['original_name']));
    readfile($path);
    exit;
}

/* ---------- updates ---------- */

function info_update(): void {
    $u = require_user();
    $o = order_for($u, (int)($_POST['order_id'] ?? 0));
    $info = json_decode($o['info_json'], true) ?: [];
    foreach (['whatsapp' => 20, 'phone' => 20, 'fb_page' => 255, 'address' => 255, 'business_intro' => 3000, 'additional_info' => 3000] as $k => $max) {
        if (isset($_POST[$k])) $info[$k] = str_in($k, $max);
    }
    foreach (['whatsapp', 'phone'] as $k) if (($info[$k] ?? '') !== '' && !valid_phone($info[$k])) fail('সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন।');
    q('UPDATE orders SET info_json = ? WHERE id = ?', [json_encode($info, JSON_UNESCAPED_UNICODE), $o['id']]);
    q('INSERT INTO order_events (order_id, stage, progress, note, created_by) VALUES (?,?,?,?,?)',
      [$o['id'], $o['stage'], $o['progress'], is_admin($u) ? 'ব্যবসার তথ্য আপডেট করা হয়েছে।' : 'কাস্টমার ব্যবসার তথ্য আপডেট করেছেন।', $u['id']]);
    out(['info' => $info]);
}

function admin_update(): void {
    $u = require_user();
    if (!is_admin($u)) fail('শুধু ডেভেলপার এটা করতে পারবেন।', 403);
    $o = order_for($u, (int)($_POST['order_id'] ?? 0));
    $stage = max(0, min(STAGES - 1, (int)($_POST['stage'] ?? $o['stage'])));
    $progress = max(0, min(100, (int)($_POST['progress'] ?? $o['progress'])));
    $pay = str_in('pay_status', 10) ?: $o['pay_status'];
    if (!in_array($pay, ['pending', 'verified', 'rejected'], true)) fail('পেমেন্ট স্ট্যাটাস সঠিক নয়।');
    $site = str_in('site_url', 255);
    if ($site !== '' && !preg_match('#^https?://\S+\.\S+#', $site)) fail('সাইটের পুরো লিংক দিন (https://...)।');
    $note = str_in('note', 2000);

    q('UPDATE orders SET stage = ?, progress = ?, pay_status = ?, site_url = ? WHERE id = ?', [$stage, $progress, $pay, $site ?: null, $o['id']]);
    $notes = [];
    if ($pay !== $o['pay_status']) $notes[] = ['pending' => 'পেমেন্ট যাচাই বাকি।', 'verified' => 'পেমেন্ট যাচাই সম্পন্ন।', 'rejected' => 'পেমেন্ট পাওয়া যায়নি, যোগাযোগ করুন।'][$pay];
    if ($site !== '' && $site !== $o['site_url']) $notes[] = 'সাইটের লিংক যোগ হয়েছে: ' . $site;
    if ($note !== '') $notes[] = $note;
    if ($notes || $stage !== (int)$o['stage'] || $progress !== (int)$o['progress']) {
        q('INSERT INTO order_events (order_id, stage, progress, note, created_by) VALUES (?,?,?,?,?)', [$o['id'], $stage, $progress, implode("\n", $notes) ?: null, $u['id']]);
    }
    out(['order' => order_out(q('SELECT * FROM orders WHERE id = ?', [$o['id']])->fetch(), $u)]);
}
