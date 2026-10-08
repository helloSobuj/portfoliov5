<?php
// JSON API for the landing page, the customer/developer dashboard and the admin panel.
// Every call is api.php?action=<name>. POST calls need the X-CSRF-Token header from `me`,
// except `track`, the public analytics beacon.

declare(strict_types=1);
require __DIR__ . '/lib.php';

start_session();
$action = $_GET['action'] ?? '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost && $action !== 'track') check_csrf();

const STAGES = 5;          // তথ্য জমা, পেমেন্ট যাচাই, সেটআপ চলছে, রিভিউ, ডেলিভারি
const LOGIN_LIMIT = 10;    // failed logins per IP per 15 minutes
const LOCAL_TZ = '+06:00';   // Bangladesh, for "today" and daily charts
const TRACK_EVENTS = ['checkout_open', 'whatsapp_click', 'package_select'];
const ORDER_SELECT = 'SELECT o.*, c.name c_name, c.email c_email, c.phone c_phone, d.name d_name FROM orders o JOIN users c ON c.id = o.user_id LEFT JOIN users d ON d.id = o.developer_id';

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
        case 'admin_update': $isPost || fail('POST only', 405); staff_update();
        case 'track':        $isPost || fail('POST only', 405); track();
        case 'catalog':      out(['catalog' => catalog()]);
        case 'catalog_save': $isPost || fail('POST only', 405); catalog_save();
        case 'admin_stats':  admin_stats();
        case 'assign':       $isPost || fail('POST only', 405); assign();
        case 'order_adjust': $isPost || fail('POST only', 405); order_adjust();
        case 'team':         team_list();
        case 'team_save':    $isPost || fail('POST only', 405); team_save();
        case 'customers':    customers_list();
        case 'export_orders': export_orders();
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
    $row = q('SELECT id, password_hash FROM users WHERE email = ? AND active = 1', [mb_strtolower(str_in('email', 190))])->fetch();
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
    $r['cancelled'] = (bool)($o['cancelled'] ?? false);
    if (isset($o['unread'])) $r['unread'] = (int)$o['unread'];
    if (is_staff($viewer)) {
        if (isset($o['c_name'])) $r['customer'] = ['name' => $o['c_name'], 'email' => $o['c_email'], 'phone' => $o['c_phone']];
        $r['developer'] = $o['developer_id'] ? ['id' => (int)$o['developer_id'], 'name' => $o['d_name'] ?? null] : null;
    }
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

// Marks the other side's messages read. from_admin = 1 means "sent by Techill staff".
function mark_seen(array $u, int $orderId): void {
    q('UPDATE messages SET seen = 1 WHERE order_id = ? AND from_admin = ? AND seen = 0', [$orderId, is_staff($u) ? 0 : 1]);
}


function order_row(int $id): array { return q(ORDER_SELECT . ' WHERE o.id = ?', [$id])->fetch(); }

/* ---------- orders ---------- */

function order_create(): void {
    // The browser sends only what was picked; prices come from the server's catalog.
    $add = json_decode((string)($_POST['addons'] ?? '{}'), true);
    if (!is_array($add)) fail('অ্যাড-অনের তথ্য সঠিক নয়।');
    $priced = price_order(str_in('stack_key', 20), (int)($_POST['pack'] ?? -1), (int)($_POST['tpl'] ?? -1), $add);
    ['stack' => $stack, 'pack' => $pack, 'template' => $template, 'lines' => $clean, 'total' => $total, 'hours' => $hours, 'products' => $products] = $priced;
    if (isset($_POST['total']) && (int)$_POST['total'] !== $total) fail('দাম আপডেট হয়েছে, পেজ রিফ্রেশ করে আবার দেখে নিন।', 422);

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

    $u = current_user();
    if (!$u) {
        throttle();
        $id = create_or_login(str_in('admin_name', 120), mb_strtolower(str_in('email', 190)), str_in('phone', 20), (string)($_POST['password'] ?? ''));
        login_as($id);
        $u = current_user();
    }

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
    $vid = (string)($_POST['vid'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $vid)) q("INSERT INTO track_events (vid, name) VALUES (?, 'order')", [$vid]);

    out(['order' => order_out(q('SELECT * FROM orders WHERE id = ?', [$oid])->fetch()), 'user' => $u, 'csrf' => $_SESSION['csrf']]);
}

function orders_list(): void {
    $u = require_user();
    $unread = '(SELECT COUNT(*) FROM messages m WHERE m.order_id = o.id AND m.seen = 0 AND m.from_admin = ' . (is_staff($u) ? 0 : 1) . ') AS unread';
    $sel = str_replace('SELECT o.*,', "SELECT o.*, $unread,", ORDER_SELECT);
    if (is_admin($u)) $rows = q("$sel ORDER BY o.id DESC LIMIT 1000")->fetchAll();
    elseif ($u['role'] === 'developer') $rows = q("$sel WHERE o.developer_id = ? ORDER BY o.id DESC", [$u['id']])->fetchAll();
    else $rows = q("$sel WHERE o.user_id = ? ORDER BY o.id DESC", [$u['id']])->fetchAll();
    out(['orders' => array_map(fn($o) => order_out($o, $u), $rows)]);
}

function order_detail(): void {
    $u = require_user();
    $id = (int)order_for($u, (int)($_GET['id'] ?? 0))['id'];
    $o = order_row($id);
    mark_seen($u, $id);
    $events = array_map(fn($e) => ['id' => (int)$e['id'], 'stage' => (int)$e['stage'], 'progress' => (int)$e['progress'], 'note' => $e['note'], 'created' => strtotime($e['created_at'] . ' UTC')],
        q('SELECT * FROM order_events WHERE order_id = ? ORDER BY id DESC', [$id])->fetchAll());
    $files = array_map(fn($f) => ['id' => (int)$f['id'], 'kind' => $f['kind'], 'name' => $f['original_name'], 'size' => (int)$f['size'], 'by_admin' => in_array($f['role'], ['developer', 'admin'], true), 'created' => strtotime($f['created_at'] . ' UTC')],
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
    q('INSERT INTO messages (order_id, user_id, from_admin, body) VALUES (?,?,?,?)', [$o['id'], $u['id'], is_staff($u) ? 1 : 0, $body]);
    out(['messages' => message_rows((int)$o['id'], (int)($_POST['after'] ?? 0))]);
}

function file_upload(): void {
    $u = require_user();
    $o = order_for($u, (int)($_POST['order_id'] ?? 0));
    $fid = save_upload('file', (int)$o['id'], $u['id'], 'attachment');
    if (!$fid) fail('একটা ফাইল বাছাই করুন।');
    $note = str_in('note', 1000);
    q('INSERT INTO messages (order_id, user_id, from_admin, body, file_id) VALUES (?,?,?,?,?)',
      [$o['id'], $u['id'], is_staff($u) ? 1 : 0, $note !== '' ? $note : 'ফাইল পাঠানো হয়েছে।', $fid]);
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
      [$o['id'], $o['stage'], $o['progress'], is_staff($u) ? 'ব্যবসার তথ্য আপডেট করা হয়েছে।' : 'কাস্টমার ব্যবসার তথ্য আপডেট করেছেন।', $u['id']]);
    out(['info' => $info]);
}

// Developers and admins update progress; only admins change the payment status.
function staff_update(): void {
    $u = require_user();
    if (!is_staff($u)) fail('শুধু ডেভেলপার এটা করতে পারবেন।', 403);
    $o = order_for($u, (int)($_POST['order_id'] ?? 0));
    $stage = max(0, min(STAGES - 1, (int)($_POST['stage'] ?? $o['stage'])));
    $progress = max(0, min(100, (int)($_POST['progress'] ?? $o['progress'])));
    $pay = is_admin($u) ? (str_in('pay_status', 10) ?: $o['pay_status']) : $o['pay_status'];
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
    out(['order' => order_out(order_row((int)$o['id']), $u)]);
}

/* ---------- visitor analytics ---------- */


// Public beacon from assets/api.js: page views, 30-second heartbeats and a few named events.
function track(): void {
    $vid = (string)($_POST['vid'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $vid) || $ua === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse/i', $ua)) out([]);
    $device = preg_match('/ipad|tablet/i', $ua) ? 'tablet' : (preg_match('/mobi|android|iphone/i', $ua) ? 'mobile' : 'desktop');
    $path = mb_substr((string)($_POST['p'] ?? '/'), 0, 200);
    if ($path === '' || $path[0] !== '/') $path = '/';
    $type = (string)($_POST['t'] ?? '');

    if ($type === 'view') {
        $ref = parse_url((string)($_POST['r'] ?? ''), PHP_URL_HOST) ?: null;
        if ($ref && strcasecmp($ref, (string)($_SERVER['HTTP_HOST'] ?? '')) === 0) $ref = null;
        $utm = mb_substr(preg_replace('/[^\w.\-]/u', '', (string)($_POST['u'] ?? '')), 0, 60) ?: null;
        q('INSERT INTO visits (vid, path, ref_host, utm, device) VALUES (?,?,?,?,?)', [$vid, $path, $ref ? mb_substr(strtolower($ref), 0, 120) : null, $utm, $device]);
    }
    if ($type === 'view' || $type === 'ping') {
        q('INSERT INTO live_visitors (vid, path, device) VALUES (?,?,?) ON DUPLICATE KEY UPDATE path = VALUES(path), last_seen = UTC_TIMESTAMP(),
           first_seen = IF(last_seen < UTC_TIMESTAMP() - INTERVAL 30 MINUTE, UTC_TIMESTAMP(), first_seen)', [$vid, $path, $device]);
        if (random_int(1, 100) === 1) q('DELETE FROM live_visitors WHERE last_seen < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    }
    if ($type === 'event' && in_array($_POST['n'] ?? '', TRACK_EVENTS, true)) {
        q('INSERT INTO track_events (vid, name) VALUES (?,?)', [$vid, $_POST['n']]);
    }
    out([]);
}

/* ---------- admin: overview ---------- */

function admin_stats(): void {
    require_admin();
    $days = in_array((int)($_GET['days'] ?? 30), [7, 30, 90], true) ? (int)$_GET['days'] : 30;
    $local = fn(string $col) => "DATE(CONVERT_TZ($col, '+00:00', '" . LOCAL_TZ . "'))";
    $today = q("SELECT DATE(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '" . LOCAL_TZ . "')) d")->fetchColumn();
    $since = "UTC_TIMESTAMP() - INTERVAL $days DAY";
    $kv = fn(string $sql, array $a = []) => array_map(fn($r) => ['k' => $r['k'], 'n' => (int)$r['n']], q($sql, $a)->fetchAll());

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("$today -$i day"));
        $series[$d] = ['d' => $d, 'visits' => 0, 'uniques' => 0, 'orders' => 0, 'revenue' => 0];
    }
    foreach (q("SELECT {$local('created_at')} d, COUNT(*) v, COUNT(DISTINCT vid) u FROM visits WHERE created_at >= $since GROUP BY d")->fetchAll() as $r) {
        if (isset($series[$r['d']])) { $series[$r['d']]['visits'] = (int)$r['v']; $series[$r['d']]['uniques'] = (int)$r['u']; }
    }
    foreach (q("SELECT {$local('created_at')} d, COUNT(*) n, SUM(total) t FROM orders WHERE cancelled = 0 AND created_at >= $since GROUP BY d")->fetchAll() as $r) {
        if (isset($series[$r['d']])) { $series[$r['d']]['orders'] = (int)$r['n']; $series[$r['d']]['revenue'] = (int)$r['t']; }
    }

    $o = q("SELECT COUNT(*) total,
              SUM(cancelled = 0 AND stage < 4) running, SUM(cancelled = 0 AND stage = 4) done, SUM(cancelled = 1) cancelled,
              SUM(cancelled = 0 AND stage < 4 AND deadline_at < UTC_TIMESTAMP()) overdue,
              SUM(cancelled = 0 AND stage < 4 AND deadline_at BETWEEN UTC_TIMESTAMP() AND UTC_TIMESTAMP() + INTERVAL 24 HOUR) due_soon,
              SUM(cancelled = 0 AND pay_status = 'pending') pending_pay,
              SUM(cancelled = 0 AND stage < 4 AND developer_id IS NULL) unassigned,
              SUM(CASE WHEN cancelled = 0 AND pay_status = 'verified' THEN total ELSE 0 END) revenue,
              SUM(CASE WHEN cancelled = 0 AND pay_status = 'pending' THEN total ELSE 0 END) pending_amount,
              SUM(CASE WHEN cancelled = 0 AND pay_status = 'verified' AND DATE_FORMAT(CONVERT_TZ(created_at, '+00:00', '" . LOCAL_TZ . "'), '%Y-%m') = DATE_FORMAT(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '" . LOCAL_TZ . "'), '%Y-%m') THEN total ELSE 0 END) revenue_month,
              AVG(CASE WHEN cancelled = 0 THEN total END) avg_order
            FROM orders")->fetch();
    $orders = array_map(fn($v) => (int)round((float)$v), $o);
    $orders['unread'] = (int)q("SELECT COUNT(*) FROM messages WHERE from_admin = 0 AND seen = 0")->fetchColumn();
    $orders['stages'] = array_fill(0, STAGES, 0);
    foreach (q('SELECT stage, COUNT(*) n FROM orders WHERE cancelled = 0 GROUP BY stage')->fetchAll() as $r) $orders['stages'][(int)$r['stage']] = (int)$r['n'];

    $addons = [];
    foreach (q("SELECT lines_json FROM orders WHERE cancelled = 0 AND created_at >= $since")->fetchAll() as $r) {
        foreach (array_slice(json_decode($r['lines_json'], true) ?: [], 1) as $l) {
            if (!empty($l['adj'])) continue;   // admin price adjustments are not add-ons
            $k = preg_replace('/ × .*$/u', '', $l['label']);
            $addons[$k] = ($addons[$k] ?? 0) + 1;
        }
    }
    arsort($addons);

    $uniq = (int)q("SELECT COUNT(DISTINCT vid) FROM visits WHERE created_at >= $since")->fetchColumn();
    $activity = array_merge(
        array_map(fn($r) => ['type' => 'event', 'order_id' => (int)$r['order_id'], 'code' => $r['code'], 'who' => $r['who'], 'text' => $r['note'] ?: ('অগ্রগতি ' . $r['progress'] . '%'), 'stage' => (int)$r['stage'], 'created' => strtotime($r['created_at'] . ' UTC')],
            q('SELECT e.*, o.code, u.name who FROM order_events e JOIN orders o ON o.id = e.order_id LEFT JOIN users u ON u.id = e.created_by ORDER BY e.id DESC LIMIT 15')->fetchAll()),
        array_map(fn($r) => ['type' => 'message', 'order_id' => (int)$r['order_id'], 'code' => $r['code'], 'who' => $r['who'], 'text' => $r['body'], 'unread' => !$r['seen'], 'created' => strtotime($r['created_at'] . ' UTC')],
            q('SELECT m.*, o.code, u.name who FROM messages m JOIN orders o ON o.id = m.order_id LEFT JOIN users u ON u.id = m.user_id WHERE m.from_admin = 0 ORDER BY m.id DESC LIMIT 15')->fetchAll())
    );
    usort($activity, fn($a, $b) => $b['created'] <=> $a['created']);

    out([
        'days' => $days,
        'live' => [
            'count' => (int)q('SELECT COUNT(*) FROM live_visitors WHERE last_seen > UTC_TIMESTAMP() - INTERVAL 2 MINUTE')->fetchColumn(),
            'list' => array_map(fn($r) => ['path' => $r['path'], 'device' => $r['device'], 'since' => strtotime($r['first_seen'] . ' UTC'), 'seen' => strtotime($r['last_seen'] . ' UTC')],
                q('SELECT * FROM live_visitors WHERE last_seen > UTC_TIMESTAMP() - INTERVAL 2 MINUTE ORDER BY first_seen DESC LIMIT 50')->fetchAll()),
        ],
        'visits' => [
            'today' => (int)q("SELECT COUNT(*) FROM visits WHERE {$local('created_at')} = ?", [$today])->fetchColumn(),
            'today_uniques' => (int)q("SELECT COUNT(DISTINCT vid) FROM visits WHERE {$local('created_at')} = ?", [$today])->fetchColumn(),
            'total' => (int)q('SELECT COUNT(*) FROM visits')->fetchColumn(),
            'total_uniques' => (int)q('SELECT COUNT(DISTINCT vid) FROM visits')->fetchColumn(),
            'range' => (int)q("SELECT COUNT(*) FROM visits WHERE created_at >= $since")->fetchColumn(),
            'range_uniques' => $uniq,
        ],
        'series' => array_values($series),
        'pages' => $kv("SELECT path k, COUNT(*) n FROM visits WHERE created_at >= $since GROUP BY path ORDER BY n DESC LIMIT 8"),
        'referrers' => $kv("SELECT COALESCE(ref_host, '') k, COUNT(DISTINCT vid) n FROM visits WHERE created_at >= $since GROUP BY k ORDER BY n DESC LIMIT 8"),
        'utm' => $kv("SELECT utm k, COUNT(DISTINCT vid) n FROM visits WHERE created_at >= $since AND utm IS NOT NULL GROUP BY utm ORDER BY n DESC LIMIT 8"),
        'devices' => $kv("SELECT device k, COUNT(DISTINCT vid) n FROM visits WHERE created_at >= $since GROUP BY device ORDER BY n DESC"),
        'funnel' => [
            'visitors' => $uniq,
            'checkout' => (int)q("SELECT COUNT(DISTINCT vid) FROM track_events WHERE name = 'checkout_open' AND created_at >= $since")->fetchColumn(),
            'whatsapp' => (int)q("SELECT COUNT(DISTINCT vid) FROM track_events WHERE name = 'whatsapp_click' AND created_at >= $since")->fetchColumn(),
            'orders' => (int)q("SELECT COUNT(*) FROM orders WHERE cancelled = 0 AND created_at >= $since")->fetchColumn(),
        ],
        'orders' => $orders,
        'packs' => array_map(fn($r) => ['stack' => $r['stack'], 'pack' => $r['pack'], 'n' => (int)$r['n'], 'total' => (int)$r['t']],
            q("SELECT stack, pack, COUNT(*) n, SUM(total) t FROM orders WHERE cancelled = 0 AND created_at >= $since GROUP BY stack, pack ORDER BY n DESC")->fetchAll()),
        'addons' => array_map(fn($k, $n) => ['k' => $k, 'n' => $n], array_keys(array_slice($addons, 0, 10, true)), array_slice($addons, 0, 10, true)),
        'team' => team_rows(),
        'activity' => array_slice($activity, 0, 20),
    ]);
}

/* ---------- admin: orders ---------- */

function assign(): void {
    $u = require_admin();
    $o = order_for($u, (int)($_POST['order_id'] ?? 0));
    $devId = (int)($_POST['developer_id'] ?? 0);
    $dev = $devId ? q("SELECT id, name FROM users WHERE id = ? AND role IN ('developer','admin') AND active = 1", [$devId])->fetch() : null;
    if ($devId && !$dev) fail('এই ডেভেলপার পাওয়া যায়নি।');
    if ((int)$o['developer_id'] !== $devId) {
        q('UPDATE orders SET developer_id = ? WHERE id = ?', [$dev ? $dev['id'] : null, $o['id']]);
        q('INSERT INTO order_events (order_id, stage, progress, note, created_by) VALUES (?,?,?,?,?)',
          [$o['id'], $o['stage'], $o['progress'], $dev ? "ডেভেলপার {$dev['name']} এই প্রোজেক্টে কাজ করছেন।" : 'ডেভেলপার পরিবর্তন হচ্ছে।', $u['id']]);
    }
    out(['order' => order_out(order_row((int)$o['id']), $u)]);
}

// Price changes (extra charge or discount), deadline extensions and cancel / restore.
function order_adjust(): void {
    $u = require_admin();
    $o = order_for($u, (int)($_POST['order_id'] ?? 0));
    $notes = [];
    $label = str_in('line_label', 120);
    if ($label !== '') {
        $amt = (int)($_POST['line_amt'] ?? 0);
        if ($amt === 0 || abs($amt) > 1000000) fail('সঠিক পরিমাণ দিন (ছাড় হলে মাইনাস দিয়ে লিখুন)।');
        $lines = json_decode($o['lines_json'], true) ?: [];
        $lines[] = ['label' => $label, 'amt' => $amt, 'adj' => true];
        $total = array_sum(array_column($lines, 'amt'));
        if ($total < 0) fail('মোট টাকা শূন্যের কম হতে পারে না।');
        q('UPDATE orders SET lines_json = ?, total = ? WHERE id = ?', [json_encode($lines, JSON_UNESCAPED_UNICODE), $total, $o['id']]);
        $notes[] = ($amt < 0 ? 'ছাড়' : 'অতিরিক্ত চার্জ') . ": $label (৳" . bn_digits(number_format(abs($amt))) . '), নতুন মোট ৳' . bn_digits(number_format($total));
    }
    $extend = (int)($_POST['extend_hours'] ?? 0);
    if ($extend) {
        if ($extend < 1 || $extend > 720) fail('১ থেকে ৭২০ ঘণ্টা পর্যন্ত বাড়ানো যায়।');
        q('UPDATE orders SET deadline_at = deadline_at + INTERVAL ? HOUR, hours = hours + ? WHERE id = ?', [$extend, $extend, $o['id']]);
        $notes[] = 'ডেলিভারির সময় ' . bn_digits($extend) . ' ঘণ্টা বাড়ানো হয়েছে।';
    }
    if (isset($_POST['cancelled']) && (int)$_POST['cancelled'] !== (int)$o['cancelled']) {
        q('UPDATE orders SET cancelled = ? WHERE id = ?', [(int)$_POST['cancelled'] ? 1 : 0, $o['id']]);
        $notes[] = (int)$_POST['cancelled'] ? 'অর্ডারটি বাতিল করা হয়েছে।' : 'অর্ডারটি আবার চালু করা হয়েছে।';
    }
    if (!$notes) fail('কিছু বদলানো হয়নি।');
    q('INSERT INTO order_events (order_id, stage, progress, note, created_by) VALUES (?,?,?,?,?)', [$o['id'], $o['stage'], $o['progress'], implode("\n", $notes), $u['id']]);
    out(['order' => order_out(order_row((int)$o['id']), $u)]);
}

function export_orders(): void {
    $u = require_admin();
    $stages = ['তথ্য জমা', 'পেমেন্ট যাচাই', 'সেটআপ চলছে', 'রিভিউ', 'ডেলিভারি'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="techill-orders-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['code', 'created', 'customer', 'email', 'phone', 'platform', 'package', 'template', 'total', 'pay_method', 'pay_sender', 'trx', 'pay_status', 'stage', 'progress', 'developer', 'deadline', 'cancelled']);
    $fmt = fn($t) => date('Y-m-d H:i', strtotime($t . ' UTC') + 6 * 3600);
    foreach (q(ORDER_SELECT . ' ORDER BY o.id DESC')->fetchAll() as $o) {
        fputcsv($f, [$o['code'], $fmt($o['created_at']), $o['c_name'], $o['c_email'], $o['c_phone'], $o['stack'], $o['pack'], $o['template'], $o['total'],
            $o['pay_method'], $o['pay_sender'], $o['pay_trx'], $o['pay_status'], $stages[(int)$o['stage']] ?? '', $o['progress'], $o['d_name'], $fmt($o['deadline_at']), $o['cancelled'] ? 'yes' : 'no']);
    }
    exit;
}

/* ---------- admin: people ---------- */

function team_rows(): array {
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'role' => $r['role'], 'active' => (bool)$r['active'],
        'running' => (int)$r['running'], 'overdue' => (int)$r['overdue'], 'done' => (int)$r['done'], 'created' => strtotime($r['created_at'] . ' UTC')],
        q("SELECT u.*,
             (SELECT COUNT(*) FROM orders o WHERE o.developer_id = u.id AND o.cancelled = 0 AND o.stage < 4) running,
             (SELECT COUNT(*) FROM orders o WHERE o.developer_id = u.id AND o.cancelled = 0 AND o.stage < 4 AND o.deadline_at < UTC_TIMESTAMP()) overdue,
             (SELECT COUNT(*) FROM orders o WHERE o.developer_id = u.id AND o.cancelled = 0 AND o.stage = 4) done
           FROM users u WHERE u.role IN ('developer','admin') ORDER BY u.active DESC, u.role, u.name")->fetchAll());
}

function team_list(): void { require_admin(); out(['team' => team_rows()]); }

function team_save(): void {
    $u = require_admin();
    $op = str_in('op', 20);
    if ($op === 'create') {
        $name = str_in('name', 120); $email = mb_strtolower(str_in('email', 190)); $pass = (string)($_POST['password'] ?? '');
        $role = str_in('role', 20) === 'admin' ? 'admin' : 'developer';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('নাম ও সঠিক ইমেইল দিন।');
        if (mb_strlen($pass) < 8) fail('পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের দিন।');
        if (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) fail('এই ইমেইলে আগেই অ্যাকাউন্ট আছে।', 409);
        q('INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,?)', [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $t = q("SELECT * FROM users WHERE id = ? AND role IN ('developer','admin')", [$id])->fetch();
        if (!$t) fail('টিম মেম্বার পাওয়া যায়নি।', 404);
        if ($op === 'toggle') {
            if ($id === $u['id']) fail('নিজের অ্যাকাউন্ট বন্ধ করা যায় না।');
            q('UPDATE users SET active = 1 - active WHERE id = ?', [$id]);
        } elseif ($op === 'role') {
            if ($id === $u['id']) fail('নিজের রোল বদলানো যায় না।');
            q('UPDATE users SET role = ? WHERE id = ?', [str_in('role', 20) === 'admin' ? 'admin' : 'developer', $id]);
        } elseif ($op === 'password') {
            $pass = (string)($_POST['password'] ?? '');
            if (mb_strlen($pass) < 8) fail('পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের দিন।');
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
        } else fail('Unknown op');
    }
    out(['team' => team_rows()]);
}

function customers_list(): void {
    require_admin();
    $rows = q("SELECT u.id, u.name, u.email, u.phone, u.created_at,
                 COUNT(o.id) orders, SUM(CASE WHEN o.cancelled = 0 AND o.pay_status = 'verified' THEN o.total ELSE 0 END) spent,
                 SUM(o.cancelled = 0 AND o.stage < 4) running, MAX(o.created_at) last_order
               FROM users u LEFT JOIN orders o ON o.user_id = u.id WHERE u.role = 'customer' GROUP BY u.id ORDER BY last_order DESC, u.id DESC LIMIT 2000")->fetchAll();
    out(['customers' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'phone' => $r['phone'],
        'orders' => (int)$r['orders'], 'spent' => (int)$r['spent'], 'running' => (int)$r['running'],
        'last_order' => $r['last_order'] ? strtotime($r['last_order'] . ' UTC') : null, 'created' => strtotime($r['created_at'] . ' UTC')], $rows)]);
}

/* ---------- admin: packages & prices ---------- */

function catalog_save(): void {
    require_admin();
    if (!empty($_POST['reset'])) { q("DELETE FROM settings WHERE k = 'catalog'"); out(['catalog' => json_decode((string)file_get_contents(__DIR__ . '/assets/catalog.json'), true)]); }
    $in = json_decode((string)($_POST['catalog'] ?? ''), true);
    if (!is_array($in)) fail('তথ্য পড়া যায়নি।');
    $str = fn($v, int $max = 200) => mb_substr(trim((string)($v ?? '')), 0, $max);
    $int = fn($v, int $min, int $max) => is_numeric($v) && (int)$v >= $min && (int)$v <= $max ? (int)$v : fail("সংখ্যা $min থেকে $max-এর মধ্যে দিন।");
    $groups = array_values(array_filter(array_map(fn($g) => $str($g, 60), (array)($in['groups'] ?? []))));
    if (!$groups) fail('অন্তত একটা অ্যাড-অন গ্রুপ লাগবে।');
    $addons = []; $keys = [];
    foreach ((array)($in['addons'] ?? []) as $a) {
        $key = (string)($a['key'] ?? '');
        if (!preg_match('/^[a-z0-9_]{2,30}$/', $key) || isset($keys[$key])) fail('অ্যাড-অনের key ইংরেজি ছোট হাতের অক্ষরে, আলাদা আলাদা দিন।');
        $keys[$key] = true;
        $x = ['key' => $key, 'g' => $int($a['g'] ?? 0, 0, count($groups) - 1), 'name' => $str($a['name'] ?? '', 120) ?: fail('অ্যাড-অনের নাম দিন।'),
              'price' => $key === 'domain' ? null : $int($a['price'] ?? -1, 0, 1000000)];
        if ($str($a['note'] ?? '') !== '') $x['note'] = $str($a['note'], 160);
        foreach (['qty', 'gw'] as $flag) if (!empty($a[$flag])) $x[$flag] = true;
        if (in_array($a['only'] ?? '', ['woo', 'laravel'], true)) $x['only'] = $a['only'];
        if (!empty($a['requires'])) $x['requires'] = $str($a['requires'], 30);
        $addons[] = $x;
    }
    $stacks = [];
    foreach (['woo', 'laravel'] as $sk) {
        $S = $in['stacks'][$sk] ?? null;
        if (!$S) fail('দুই প্ল্যাটফর্মই লাগবে।');
        $tpls = [];
        foreach ((array)($S['templates'] ?? []) as $t) {
            $hex = fn($c) => preg_match('/^#[0-9a-f]{6}$/i', (string)$c) ? $c : '#888888';
            $demo = $str($t['demo'] ?? '', 255);
            $tpls[] = ['code' => $str($t['code'] ?? '', 10) ?: fail('টেমপ্লেট কোড দিন।'), 'name' => $str($t['name'] ?? '', 80) ?: fail('টেমপ্লেটের নাম দিন।'),
                       'niche' => $str($t['niche'] ?? '', 120), 'demo' => preg_match('#^https?://#', $demo) ? $demo : '#', 't' => $hex($t['t'] ?? ''), 't2' => $hex($t['t2'] ?? '')];
        }
        $packs = [];
        foreach ((array)($S['packs'] ?? []) as $p) {
            $incl = array_values(array_intersect((array)($p['incl'] ?? []), array_keys($keys)));
            $inclQty = [];
            foreach ((array)($p['inclQty'] ?? []) as $k => $v) if (isset($keys[$k]) && (int)$v > 0) $inclQty[$k] = min(20, (int)$v);
            $x = ['name' => $str($p['name'] ?? '', 40) ?: fail('প্যাকেজের নাম দিন।'), 'en' => $str($p['en'] ?? '', 40), 'tag' => $str($p['tag'] ?? '', 120),
                  'price' => $int($p['price'] ?? -1, 0, 10000000), 'products' => $int($p['products'] ?? -1, 0, 5000), 'hours' => $int($p['hours'] ?? 0, 1, 720),
                  'feat' => array_values(array_filter(array_map(fn($f) => $str($f, 160), (array)($p['feat'] ?? [])))), 'incl' => $incl, 'inclQty' => (object)$inclQty];
            if (!empty($p['popular'])) $x['popular'] = true;
            $packs[] = $x;
        }
        if (!$tpls || !$packs) fail('প্রতিটা প্ল্যাটফর্মে অন্তত একটা প্যাকেজ আর একটা টেমপ্লেট লাগবে।');
        $stacks[$sk] = ['name' => $str($S['name'] ?? $sk, 40), 'domainPrice' => $int($S['domainPrice'] ?? 0, 0, 1000000), 'templates' => $tpls, 'packs' => $packs];
    }
    $pay = [];
    foreach (['bKash', 'Nagad', 'Rocket'] as $m) $pay[$m] = $str($in['pay_numbers'][$m] ?? '', 20);
    $cat = ['whatsapp' => preg_replace('/\D/', '', (string)($in['whatsapp'] ?? '')), 'pay_numbers' => $pay,
            'gateway_extra_hours' => $int($in['gateway_extra_hours'] ?? 48, 0, 720), 'groups' => $groups, 'stacks' => $stacks, 'addons' => $addons];
    q("INSERT INTO settings (k, v) VALUES ('catalog', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [json_encode($cat, JSON_UNESCAPED_UNICODE)]);
    out(['catalog' => $cat]);
}
