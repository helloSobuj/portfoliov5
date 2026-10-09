<?php
// JSON API for the landing page, the customer/developer dashboard and the admin panel.
// Every call is api.php?action=<name>. POST calls need the X-CSRF-Token header from `me`,
// except `track`, the public analytics beacon.

declare(strict_types=1);
require __DIR__ . '/lib.php';

start_session();
$action = $_GET['action'] ?? '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
// The analytics beacon and PayStation's callback come without our CSRF token. Neither trusts
// its input: the callback only triggers a server-to-server status check with PayStation.
if ($isPost && !in_array($action, ['track', 'paystation_callback'], true)) check_csrf();

const STAGES = 5;          // তথ্য জমা, পেমেন্ট যাচাই, সেটআপ চলছে, রিভিউ, ডেলিভারি
const LOGIN_LIMIT = 10;    // failed logins per IP per 15 minutes
const LOCAL_TZ = '+06:00';   // Bangladesh, for "today" and daily charts
const TRACK_EVENTS = ['checkout_open', 'whatsapp_click', 'package_select', 'support_open', 'support_messenger', 'support_whatsapp', 'support_tawk'];
// Browser time zone → country, used only when the IP lookup gives nothing.
const TZ_COUNTRY = ['Asia/Dhaka' => ['BD', 'Bangladesh'], 'Asia/Kolkata' => ['IN', 'India'], 'Asia/Calcutta' => ['IN', 'India'],
    'Asia/Dubai' => ['AE', 'United Arab Emirates'], 'Asia/Riyadh' => ['SA', 'Saudi Arabia'], 'Asia/Qatar' => ['QA', 'Qatar'],
    'Asia/Kuwait' => ['KW', 'Kuwait'], 'Asia/Muscat' => ['OM', 'Oman'], 'Asia/Kuala_Lumpur' => ['MY', 'Malaysia'],
    'Asia/Singapore' => ['SG', 'Singapore'], 'Europe/London' => ['GB', 'United Kingdom'], 'Europe/Rome' => ['IT', 'Italy']];
const ORDER_SELECT = 'SELECT o.*, c.name c_name, c.email c_email, c.phone c_phone, c.avatar c_avatar, d.name d_name, d.avatar d_avatar FROM orders o JOIN users c ON c.id = o.user_id LEFT JOIN users d ON d.id = o.developer_id';

try {
    switch ($action) {
        case 'me':           out(['user' => current_user(), 'csrf' => $_SESSION['csrf']]);
        case 'register':     $isPost || fail('POST only', 405); do_register();
        case 'login':        $isPost || fail('POST only', 405); do_login();
        case 'logout':       $isPost || fail('POST only', 405); do_logout();
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
        case 'catalog':      out(['catalog' => catalog(), 'payments' => public_payments(), 'pixel' => public_pixel(), 'support' => public_support(), 'referral' => public_referral(),
                                 'auth' => ['email' => email_ready(), 'otp' => otp_required()]]);
        case 'catalog_save': $isPost || fail('POST only', 405); catalog_save();
        case 'admin_stats':  admin_stats();
        case 'assign':       $isPost || fail('POST only', 405); assign();
        case 'order_adjust': $isPost || fail('POST only', 405); order_adjust();
        case 'team':         team_list();
        case 'team_save':    $isPost || fail('POST only', 405); team_save();
        case 'customers':    customers_list();
        case 'customer_save': $isPost || fail('POST only', 405); customer_save();
        case 'analytics':    analytics();
        case 'pay_start':    $isPost || fail('POST only', 405); pay_start();
        case 'paystation_callback': paystation_callback();
        case 'pay_verify':   $isPost || fail('POST only', 405); pay_verify();
        case 'payment_settings': payment_settings_get();
        case 'payment_settings_save': $isPost || fail('POST only', 405); payment_settings_save();
        case 'payment_test': $isPost || fail('POST only', 405); payment_test();
        case 'payments':     payments_list();
        case 'avatar_upload': $isPost || fail('POST only', 405); avatar_upload();
        case 'avatar_remove': $isPost || fail('POST only', 405); avatar_remove();
        case 'media_upload': $isPost || fail('POST only', 405); media_upload();
        case 'marketing_settings': marketing_get();
        case 'marketing_save': $isPost || fail('POST only', 405); marketing_save();
        case 'capi_test':    $isPost || fail('POST only', 405); capi_test();
        case 'export_orders': export_orders();
        case 'domain_check': domain_check();
        case 'domain_diag': domain_diag();
        case 'otp_send':     $isPost || fail('POST only', 405); otp_send();
        case 'password_reset': $isPost || fail('POST only', 405); password_reset();
        case 'email_verify': $isPost || fail('POST only', 405); email_verify();
        case 'profile_update': $isPost || fail('POST only', 405); profile_update();
        case 'email_change': $isPost || fail('POST only', 405); email_change();
        case 'password_change': $isPost || fail('POST only', 405); password_change();
        case 'email_settings': email_settings_get();
        case 'email_save':   $isPost || fail('POST only', 405); email_save();
        case 'email_test':   $isPost || fail('POST only', 405); email_test();
        case 'support_settings': support_get();
        case 'support_save': $isPost || fail('POST only', 405); support_save();
        case 'ref_check':    ref_check();
        case 'referral':     referral_me();
        case 'payout_request': $isPost || fail('POST only', 405); payout_request();
        case 'referral_admin': referral_admin();
        case 'referral_save': $isPost || fail('POST only', 405); referral_save();
        case 'referral_op':  $isPost || fail('POST only', 405); referral_op();
        case 'payout_op':    $isPost || fail('POST only', 405); payout_op();
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
function create_or_login(string $name, string $email, string $phone, string $pass, bool $verified = false): int {
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
    q('INSERT INTO users (name, email, phone, password_hash, email_verified_at) VALUES (?,?,?,?,' . ($verified ? 'UTC_TIMESTAMP()' : 'NULL') . ')', [$name, $email, $phone ?: null, password_hash($pass, PASSWORD_DEFAULT)]);
    return (int)db()->lastInsertId();
}

// With email OTP switched on, the first call (no otp) checks the form and emails a code;
// the second call, with the code, creates the account already verified.
function do_register(): void {
    throttle();
    $email = mb_strtolower(str_in('email', 190));
    $name = str_in('name', 120); $phone = str_in('phone', 20); $pass = (string)($_POST['password'] ?? '');
    if (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) fail('এই ইমেইলে আগেই অ্যাকাউন্ট আছে, লগইন করুন।', 409);
    $verified = false;
    if (otp_required()) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('সঠিক ইমেইল দিন।');
        if ($name === '') fail('নাম দিন।');
        if ($phone !== '' && !valid_phone($phone)) fail('সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন।');
        if (mb_strlen($pass) < 8) fail('পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।');
        if (str_in('otp', 10) === '') { otp_issue($email, 'register'); out(['need_otp' => true, 'email' => $email]); }
        otp_check($email, 'register', str_in('otp', 10));
        $verified = true;
    }
    $id = create_or_login($name, $email, $phone, $pass, $verified);
    login_as($id);
    out(['user' => current_user(), 'csrf' => $_SESSION['csrf']]);
}

// Clears the account from the session but keeps a fresh session and CSRF token, so the same page
// can log in again (or reset a password) without a reload.
function do_logout(): void {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    out(['csrf' => $_SESSION['csrf']]);
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
        'stage' => (int)$o['stage'], 'progress' => (int)$o['progress'], 'site_url' => $o['site_url'], 'domain' => $o['domain'] ?? null,
        'deadline' => strtotime($o['deadline_at'] . ' UTC'), 'created' => strtotime($o['created_at'] . ' UTC'),
    ];
    $r['cancelled'] = (bool)($o['cancelled'] ?? false);
    if (isset($o['unread'])) $r['unread'] = (int)$o['unread'];
    if (is_staff($viewer)) {
        if (isset($o['c_name'])) $r['customer'] = ['name' => $o['c_name'], 'email' => $o['c_email'], 'phone' => $o['c_phone'], 'avatar' => $o['c_avatar'] ?? null];
        $r['developer'] = $o['developer_id'] ? ['id' => (int)$o['developer_id'], 'name' => $o['d_name'] ?? null, 'avatar' => $o['d_avatar'] ?? null] : null;
    }
    return $r;
}

function message_rows(int $orderId, int $after = 0): array {
    $rows = q('SELECT m.id, m.user_id, m.from_admin, m.body, m.created_at, u.name AS by_name, u.avatar AS by_avatar, f.id AS f_id, f.original_name AS f_name, f.size AS f_size
               FROM messages m LEFT JOIN users u ON u.id = m.user_id LEFT JOIN files f ON f.id = m.file_id
               WHERE m.order_id = ? AND m.id > ? ORDER BY m.id', [$orderId, $after])->fetchAll();
    return array_map(fn($m) => [
        'id' => (int)$m['id'], 'from_admin' => (bool)$m['from_admin'], 'system' => $m['user_id'] === null, 'body' => $m['body'], 'by' => $m['by_name'] ?? 'Techill', 'avatar' => $m['by_avatar'] ?? null,
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
    $stackKey = str_in('stack_key', 20); $packIdx = (int)($_POST['pack'] ?? -1);
    $priced = price_order($stackKey, $packIdx, (int)($_POST['tpl'] ?? -1), $add);
    $PK = catalog()['stacks'][$stackKey]['packs'][$packIdx];
    ['stack' => $stack, 'pack' => $pack, 'template' => $template, 'lines' => $clean, 'total' => $total, 'hours' => $hours, 'products' => $products] = $priced;

    $method = str_in('pay_method', 20);
    $online = $method === 'PayStation';
    if ($online) {
        if (!paystation_ready()) fail('অনলাইন পেমেন্ট এখন বন্ধ আছে, পেজ রিফ্রেশ করে অন্য মাধ্যম বাছুন।', 409);
        $sender = ''; $trx = '';
    } else {
        if (!in_array($method, ['bKash', 'Nagad', 'Rocket'], true)) fail('পেমেন্ট মাধ্যম সঠিক নয়।');
        if (!payment_settings()['manual_enabled']) fail('ম্যানুয়াল পেমেন্ট এখন বন্ধ আছে, অনলাইনে পেমেন্ট করুন।', 409);
        $sender = str_in('pay_sender', 20); $trx = str_in('pay_trx', 20);
        if (!valid_phone($sender)) fail('যে নম্বর থেকে পাঠিয়েছেন সেটা সঠিক নয়।');
        if (!preg_match('/^[A-Za-z0-9]{8,12}$/', $trx)) fail('Transaction ID সঠিক নয়।');
    }

    $info = [];
    foreach (['admin_name' => 120, 'whatsapp' => 20, 'phone' => 20, 'fb_page' => 255, 'address' => 255, 'business_intro' => 3000, 'additional_info' => 3000] as $k => $max) $info[$k] = str_in($k, $max);
    if (!valid_phone($info['whatsapp']) || !valid_phone($info['phone'])) fail('সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন।');
    if ($info['business_intro'] === '') fail('ব্যবসার পরিচিতি লিখুন।');
    if (empty($_FILES['product_csv']['name'])) fail('প্রোডাক্ট CSV ফাইল দিন।');

    // Payment gateway: the provider needs the trade licence and the owner's NID.
    $gwDocs = needs_gateway_docs($stackKey, $PK, $add);
    if ($gwDocs) {
        $info['trade_license'] = str_in('trade_license', 40);
        $info['nid_number'] = digits_only(str_in('nid_number', 25));
        if (mb_strlen($info['trade_license']) < 3) fail('ট্রেড লাইসেন্স নম্বর দিন।');
        if (!valid_nid($info['nid_number'])) fail('সঠিক NID নম্বর দিন (১০, ১৩ বা ১৭ ডিজিট)।');
        check_doc_upload('trade_license_file', 'ট্রেড লাইসেন্স');
        check_doc_upload('nid_file', 'NID কার্ড');
    }

    // Domain: a new one from the package's extensions, the customer's own, or decided later.
    $domMode = in_array($_POST['domain_mode'] ?? '', ['new', 'own'], true) ? $_POST['domain_mode'] : 'later';
    $domain = null;
    $domFail = fn(string $m, int $c = 422) => out(['error' => $m, 'domain_error' => true], $c);
    if ($domMode === 'new') {
        if (!pack_has_domain($PK, $add)) $domFail('এই অর্ডারে ডোমেইন নেই। অ্যাড-অন থেকে "ডোমেইন ও হোস্টিং" যোগ করুন, বা নিজের ডোমেইন দিন।');
        $d = strtolower(str_in('domain', 253));
        $tld = substr($d, strrpos($d, '.') + 1);
        if (!valid_label(substr($d, 0, strrpos($d, '.') ?: 0))) $domFail('ডোমেইনের নাম সঠিক নয়।');
        if (!in_array($tld, allowed_tlds($PK), true)) $domFail(".$tld এই প্যাকেজে নেই। পাওয়া যাবে: ." . implode(', .', allowed_tlds($PK)));
        if (domains_booked([$d])) $domFail("$d আরেকটা অর্ডারে বুক করা আছে, আরেকটা বাছুন।", 409);
        if (domain_lookup([$d], true)[$d]['status'] === 'taken') $domFail("$d এর মধ্যে অন্য কেউ নিয়ে নিয়েছে, আরেকটা বাছুন।", 409);
        $domain = $d;
    } elseif ($domMode === 'own') {
        $d = clean_host(str_in('domain', 253));
        if (!valid_hostname($d)) $domFail('আপনার ডোমেইনটা ঠিকভাবে লিখুন, যেমন myshop.com');
        $domain = $d;
    }
    $info['domain_mode'] = $domMode;

    // Referral discount: checked before the account is created, against the email the order is for.
    $u = current_user();
    $email = $u ? $u['email'] : mb_strtolower(str_in('email', 190));
    $existing = $u ? $u['id'] : (int)(q('SELECT id FROM users WHERE email = ?', [$email])->fetchColumn() ?: 0);
    $refCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', str_in('ref_code', 20)));
    $referrer = null; $discount = 0;
    if ($refCode !== '') {
        [$referrer, $discount] = referral_for_order($refCode, $email, $existing ?: null, [$info['phone'], $info['whatsapp'], str_in('phone', 20)], $total);
        $clean[] = ['label' => "রেফারেল ছাড় ($refCode)", 'amt' => -$discount, 'ref' => true];
        $total -= $discount;
    }
    if (isset($_POST['total']) && (int)$_POST['total'] !== $total) fail('দাম আপডেট হয়েছে, পেজ রিফ্রেশ করে আবার দেখে নিন।', 422);

    if (!$u) {
        throttle();
        $verified = false;
        if (!$existing && otp_required()) {   // a brand-new account must prove the email first
            if (str_in('otp', 10) === '') out(['error' => 'ইমেইলে পাঠানো ৬ ডিজিটের কোড দিন।', 'need_otp' => true], 422);
            otp_check($email, 'register', str_in('otp', 10));
            $verified = true;
        }
        $id = create_or_login(str_in('admin_name', 120), $email, str_in('phone', 20), (string)($_POST['password'] ?? ''), $verified);
        login_as($id);
        $u = current_user();
    }

    db()->beginTransaction();
    do { $code = 'TC-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)); }
    while (q('SELECT 1 FROM orders WHERE code = ?', [$code])->fetch());
    q('INSERT INTO orders (code, user_id, stack, pack, template, lines_json, total, hours, products, pay_method, pay_sender, pay_trx, info_json, referrer_id, domain, deadline_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, UTC_TIMESTAMP() + INTERVAL ? HOUR)',
      [$code, $u['id'], $stack, $pack, $template, json_encode($clean, JSON_UNESCAPED_UNICODE), $total, $hours, $products,
       $method, $sender, $trx, json_encode($info, JSON_UNESCAPED_UNICODE), $referrer ? $referrer['id'] : null, $domain, $hours]);
    $oid = (int)db()->lastInsertId();
    if ($referrer) {
        q('INSERT INTO referrals (order_id, referrer_id, referee_id, code, reward, discount) VALUES (?,?,?,?,?,?)', [$oid, $referrer['id'], $u['id'], $refCode, (int)referral_settings()['reward'], $discount]);
        q('UPDATE users SET referred_by = COALESCE(referred_by, ?) WHERE id = ?', [$referrer['id'], $u['id']]);
    }
    save_upload('logo', $oid, $u['id'], 'logo');
    save_upload('product_csv', $oid, $u['id'], 'csv');
    if ($gwDocs) { save_upload('trade_license_file', $oid, $u['id'], 'license'); save_upload('nid_file', $oid, $u['id'], 'nid'); }
    q('INSERT INTO order_events (order_id, stage, progress, note, created_by) VALUES (?,0,5,?,?)', [$oid, 'অর্ডার ও তথ্য জমা হয়েছে।', $u['id']]);
    q('INSERT INTO messages (order_id, from_admin, body) VALUES (?,1,?)',
      [$oid, $online ? "আসসালামু আলাইকুম! অর্ডার $code পেয়েছি। অনলাইন পেমেন্ট সম্পন্ন হলেই কাজ শুরু হবে। কোনো প্রশ্ন বা নতুন তথ্য থাকলে এখানেই লিখুন।"
                     : "আসসালামু আলাইকুম! অর্ডার $code পেয়েছি। পেমেন্ট যাচাই করে কাজ শুরু করছি। কোনো প্রশ্ন বা নতুন তথ্য থাকলে এখানেই লিখুন।"]);
    db()->commit();
    $vid = (string)($_POST['vid'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $vid)) q("INSERT INTO track_events (vid, name) VALUES (?, 'order')", [$vid]);

    $row = q('SELECT * FROM orders WHERE id = ?', [$oid])->fetch();
    notify_new_order($row, $u, $info);
    $res = ['order' => order_out($row), 'user' => $u, 'csrf' => $_SESSION['csrf']];
    if (!$online) fb_capi('Purchase', $row, fb_context());
    if ($online) {
        // The order is saved either way; if PayStation is unreachable the customer can retry from the dashboard.
        try { $res['payment_url'] = paystation_start($row); }
        catch (RuntimeException $e) { $res['pay_error'] = $e->getMessage(); }
    }
    out($res);
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
    $res = ['order' => order_out($o, $u), 'events' => $events, 'messages' => message_rows($id), 'files' => $files];
    if (is_admin($u)) {
        $res['payments'] = payment_rows($id);
        $r = q('SELECT r.*, u.name referrer_name, u.email referrer_email FROM referrals r JOIN users u ON u.id = r.referrer_id WHERE r.order_id = ?', [$id])->fetch();
        $res['referral'] = $r ? ['id' => (int)$r['id'], 'code' => $r['code'], 'status' => $r['status'], 'reward' => (int)$r['reward'], 'discount' => (int)$r['discount'], 'referrer' => ['name' => $r['referrer_name'], 'email' => $r['referrer_email']]] : null;
    }
    out($res);
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
    notify_message($o, $u, $body);
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
    notify_message($o, $u, ($note !== '' ? $note . "\n" : '') . '📎 ফাইল: ' . mb_substr(basename((string)($_FILES['file']['name'] ?? '')), 0, 120));
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
    foreach (['whatsapp' => 20, 'phone' => 20, 'fb_page' => 255, 'address' => 255, 'business_intro' => 3000, 'additional_info' => 3000, 'trade_license' => 40, 'nid_number' => 25] as $k => $max) {
        if (isset($_POST[$k])) $info[$k] = str_in($k, $max);
    }
    if (($info['nid_number'] ?? '') !== '') { $info['nid_number'] = digits_only($info['nid_number']); if (!valid_nid($info['nid_number'])) fail('সঠিক NID নম্বর দিন (১০, ১৩ বা ১৭ ডিজিট)।'); }
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
    $domain = $o['domain'];
    if (isset($_POST['domain'])) {
        $domain = clean_host(str_in('domain', 253)) ?: null;
        if ($domain !== null && !valid_hostname($domain)) fail('ডোমেইন সঠিক নয়, যেমন myshop.com');
    }

    q('UPDATE orders SET stage = ?, progress = ?, pay_status = ?, site_url = ?, domain = ? WHERE id = ?', [$stage, $progress, $pay, $site ?: null, $domain, $o['id']]);
    $notes = [];
    if ($domain !== $o['domain'] && $domain) $notes[] = 'প্রোজেক্টের ডোমেইন: ' . $domain;
    if ($pay !== $o['pay_status']) $notes[] = ['pending' => 'পেমেন্ট যাচাই বাকি।', 'verified' => 'পেমেন্ট যাচাই সম্পন্ন।', 'rejected' => 'পেমেন্ট পাওয়া যায়নি, যোগাযোগ করুন।'][$pay];
    if ($site !== '' && $site !== $o['site_url']) $notes[] = 'সাইটের লিংক যোগ হয়েছে: ' . $site;
    if ($note !== '') $notes[] = $note;
    if ($notes || $stage !== (int)$o['stage'] || $progress !== (int)$o['progress']) {
        q('INSERT INTO order_events (order_id, stage, progress, note, created_by) VALUES (?,?,?,?,?)', [$o['id'], $stage, $progress, implode("\n", $notes) ?: null, $u['id']]);
    }
    referral_sync((int)$o['id']);
    notify_order_update((int)$o['id'], $notes, $stage !== (int)$o['stage']);
    out(['order' => order_out(order_row((int)$o['id']), $u)]);
}

/* ---------- visitor analytics ---------- */


// Public beacon from assets/api.js: page views, 30-second heartbeats and a few named events.
function track(): void {
    $vid = (string)($_POST['vid'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $vid) || $ua === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse/i', $ua)) out([]);
    ['device' => $device, 'browser' => $browser, 'os' => $os] = ua_info($ua);
    $path = mb_substr((string)($_POST['p'] ?? '/'), 0, 200);
    if ($path === '' || $path[0] !== '/') $path = '/';
    $type = (string)($_POST['t'] ?? '');

    // The beacon does not wait for an answer; on PHP-FPM let it go before the location lookup.
    if ($type === 'view' && function_exists('fastcgi_finish_request')) {
        header('Content-Type: application/json'); echo '{"ok":true}'; fastcgi_finish_request();
    }
    if ($type === 'view') {
        $ref = parse_url((string)($_POST['r'] ?? ''), PHP_URL_HOST) ?: null;
        if ($ref && strcasecmp($ref, (string)($_SERVER['HTTP_HOST'] ?? '')) === 0) $ref = null;
        $utm = mb_substr(preg_replace('/[^\w.\-]/u', '', (string)($_POST['u'] ?? '')), 0, 60) ?: null;
        $geo = geo_for_ip(client_ip());
        if (!$geo['country'] && isset(TZ_COUNTRY[$_POST['tz'] ?? ''])) [$geo['country'], $geo['country_name']] = TZ_COUNTRY[$_POST['tz']];
        q('INSERT INTO visits (vid, path, ref_host, utm, device, browser, os, country, country_name, region, city) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
          [$vid, $path, $ref ? mb_substr(strtolower($ref), 0, 120) : null, $utm, $device, $browser, $os, $geo['country'], $geo['country_name'], $geo['region'], $geo['city']]);
        q('INSERT INTO live_visitors (vid, path, device, browser, city, country) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE path = VALUES(path), browser = VALUES(browser),
           city = VALUES(city), country = VALUES(country), first_seen = IF(last_seen < UTC_TIMESTAMP() - INTERVAL 30 MINUTE, UTC_TIMESTAMP(), first_seen), last_seen = UTC_TIMESTAMP()',
          [$vid, $path, $device, $browser, $geo['city'], $geo['country']]);
    }
    if ($type === 'ping') {
        q('INSERT INTO live_visitors (vid, path, device, browser) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE path = VALUES(path),
           first_seen = IF(last_seen < UTC_TIMESTAMP() - INTERVAL 30 MINUTE, UTC_TIMESTAMP(), first_seen), last_seen = UTC_TIMESTAMP()', [$vid, $path, $device, $browser]);
    }
    if (($type === 'view' || $type === 'ping') && random_int(1, 100) === 1) q('DELETE FROM live_visitors WHERE last_seen < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    if ($type === 'event' && in_array($_POST['n'] ?? '', TRACK_EVENTS, true)) {
        q('INSERT INTO track_events (vid, name) VALUES (?,?)', [$vid, $_POST['n']]);
    }
    out([]);
}

/* ---------- admin: overview ---------- */

function admin_stats(): void {
    require_admin();
    $days = (int)($_GET['days'] ?? 30);
    if (!in_array($days, [7, 30, 90], true)) $days = 30;
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
            if (!empty($l['adj']) || !empty($l['ref'])) continue;   // price adjustments and referral discounts are not add-ons
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
        'counts' => people_counts(),
        'referral' => array_map('intval', q("SELECT COUNT(*) requests, COALESCE(SUM(amount), 0) amount FROM payouts WHERE status = 'requested'")->fetch()),
        'email' => ['ready' => email_ready(), 'failed' => (int)q("SELECT COUNT(*) FROM email_log WHERE status = 'failed' AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY")->fetchColumn()],
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
        if ($dev && (int)$dev['id'] !== $u['id']) notify_assign($o, $dev);
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
    referral_sync((int)$o['id']);
    notify_order_update((int)$o['id'], $notes, false);
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
    fputcsv($f, ['code', 'domain', 'created', 'customer', 'email', 'phone', 'platform', 'package', 'template', 'total', 'pay_method', 'pay_sender', 'trx', 'pay_status', 'stage', 'progress', 'developer', 'deadline', 'cancelled']);
    $fmt = fn($t) => date('Y-m-d H:i', strtotime($t . ' UTC') + 6 * 3600);
    foreach (q(ORDER_SELECT . ' ORDER BY o.id DESC')->fetchAll() as $o) {
        fputcsv($f, [$o['code'], $o['domain'], $fmt($o['created_at']), $o['c_name'], $o['c_email'], $o['c_phone'], $o['stack'], $o['pack'], $o['template'], $o['total'],
            $o['pay_method'], $o['pay_sender'], $o['pay_trx'], $o['pay_status'], $stages[(int)$o['stage']] ?? '', $o['progress'], $o['d_name'], $fmt($o['deadline_at']), $o['cancelled'] ? 'yes' : 'no']);
    }
    exit;
}

/* ---------- admin: people ---------- */

function team_rows(): array {
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'role' => $r['role'], 'active' => (bool)$r['active'], 'avatar' => $r['avatar'],
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
        } elseif ($op === 'update') {
            update_person($id, str_in('name', 120), mb_strtolower(str_in('email', 190)), $t['phone']);
        } elseif ($op === 'delete') {
            if ($id === $u['id']) fail('নিজের অ্যাকাউন্ট মোছা যায় না।');
            if ($t['role'] === 'admin' && (int)q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn() <= 1) fail('অন্তত একজন অ্যাডমিন রাখতে হবে।');
            db()->beginTransaction();
            $n = q('SELECT COUNT(*) FROM orders WHERE developer_id = ? AND cancelled = 0 AND stage < 4', [$id])->fetchColumn();
            q('UPDATE orders SET developer_id = NULL WHERE developer_id = ?', [$id]);
            q('DELETE FROM users WHERE id = ?', [$id]);
            db()->commit();
            out(['team' => team_rows(), 'unassigned' => (int)$n]);
        } else fail('Unknown op');
    }
    out(['team' => team_rows()]);
}

function customers_list(): void {
    require_admin();
    $rows = q("SELECT u.id, u.name, u.email, u.phone, u.active, u.avatar, u.created_at,
                 COUNT(o.id) orders, SUM(CASE WHEN o.cancelled = 0 AND o.pay_status = 'verified' THEN o.total ELSE 0 END) spent,
                 SUM(o.cancelled = 0 AND o.stage < 4) running, MAX(o.created_at) last_order
               FROM users u LEFT JOIN orders o ON o.user_id = u.id WHERE u.role = 'customer' GROUP BY u.id ORDER BY last_order DESC, u.id DESC LIMIT 2000")->fetchAll();
    out(['customers' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'phone' => $r['phone'], 'active' => (bool)$r['active'], 'avatar' => $r['avatar'],
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
                       'niche' => $str($t['niche'] ?? '', 120), 'demo' => preg_match('#^https?://#', $demo) ? $demo : '#', 't' => $hex($t['t'] ?? ''), 't2' => $hex($t['t2'] ?? '')]
                    + (is_media_path($t['image'] ?? null) ? ['image' => $t['image']] : []);
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
            $x['premium_tlds'] = (bool)($p['premium_tlds'] ?? in_array('domain', $incl, true));
            $packs[] = $x;
        }
        if (!$tpls || !$packs) fail('প্রতিটা প্ল্যাটফর্মে অন্তত একটা প্যাকেজ আর একটা টেমপ্লেট লাগবে।');
        $stacks[$sk] = ['name' => $str($S['name'] ?? $sk, 40), 'domainPrice' => $int($S['domainPrice'] ?? 0, 0, 1000000), 'templates' => $tpls, 'packs' => $packs];
    }
    $pay = [];
    foreach (['bKash', 'Nagad', 'Rocket'] as $m) $pay[$m] = $str($in['pay_numbers'][$m] ?? '', 20);
    $tldList = function ($v, array $def) {
        $l = array_values(array_unique(array_filter(array_map(fn($t) => strtolower(trim((string)$t, " .")), (array)($v ?? $def)), fn($t) => preg_match('/^[a-z]{2,24}$/', $t))));
        return $l;
    };
    $tb = $tldList($in['tlds_basic'] ?? null, TLDS_BASIC);
    if (!$tb) fail('অন্তত একটা ডোমেইন এক্সটেনশন দিন (যেমন shop)।');
    $cat = ['rev' => CATALOG_REV, 'whatsapp' => preg_replace('/\D/', '', (string)($in['whatsapp'] ?? '')), 'pay_numbers' => $pay,
            'tlds_basic' => $tb, 'tlds_premium' => $tldList($in['tlds_premium'] ?? null, TLDS_PREMIUM),
            'gateway_extra_hours' => $int($in['gateway_extra_hours'] ?? 48, 0, 720), 'groups' => $groups, 'stacks' => $stacks, 'addons' => $addons];
    q("INSERT INTO settings (k, v) VALUES ('catalog', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [json_encode($cat, JSON_UNESCAPED_UNICODE)]);
    out(['catalog' => $cat]);
}

/* ---------- admin: customers & counts ---------- */

function people_counts(): array {
    $r = q("SELECT SUM(role = 'customer') customers, SUM(role = 'customer' AND active = 1) customers_active,
              SUM(role = 'developer') developers, SUM(role = 'developer' AND active = 1) developers_active, SUM(role = 'admin') admins FROM users")->fetch();
    $cat = catalog();
    $packs = 0; $tpls = 0;
    foreach ($cat['stacks'] as $S) { $packs += count($S['packs']); $tpls += count($S['templates']); }
    return array_map('intval', $r) + ['packages' => $packs, 'templates' => $tpls, 'addons' => count($cat['addons']), 'platforms' => count($cat['stacks'])];
}

function update_person(int $id, string $name, string $email, ?string $phone): void {
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('নাম ও সঠিক ইমেইল দিন।');
    if ($phone !== null && $phone !== '' && !valid_phone($phone)) fail('সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন।');
    if (q('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $id])->fetch()) fail('এই ইমেইলে অন্য একটা অ্যাকাউন্ট আছে।', 409);
    q('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?', [$name, $email, $phone ?: null, $id]);
}

// Add, edit, block/unblock, reset password or delete a customer. A customer with orders cannot be
// deleted (that would wipe the orders, chat and files); block the account instead.
function customer_save(): void {
    require_admin();
    $op = str_in('op', 20);
    if ($op === 'create') {
        $email = mb_strtolower(str_in('email', 190)); $pass = (string)($_POST['password'] ?? ''); $phone = str_in('phone', 20);
        if (str_in('name', 120) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('নাম ও সঠিক ইমেইল দিন।');
        if ($phone !== '' && !valid_phone($phone)) fail('সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন।');
        if (mb_strlen($pass) < 8) fail('পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের দিন।');
        if (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) fail('এই ইমেইলে আগেই অ্যাকাউন্ট আছে।', 409);
        q("INSERT INTO users (name, email, phone, password_hash, role) VALUES (?,?,?,?,'customer')", [str_in('name', 120), $email, $phone ?: null, password_hash($pass, PASSWORD_DEFAULT)]);
        customers_list();
    }
    $id = (int)($_POST['id'] ?? 0);
    $c = q("SELECT * FROM users WHERE id = ? AND role = 'customer'", [$id])->fetch();
    if (!$c) fail('কাস্টমার পাওয়া যায়নি।', 404);
    if ($op === 'update') update_person($id, str_in('name', 120), mb_strtolower(str_in('email', 190)), str_in('phone', 20));
    elseif ($op === 'toggle') q('UPDATE users SET active = 1 - active WHERE id = ?', [$id]);
    elseif ($op === 'password') {
        $pass = (string)($_POST['password'] ?? '');
        if (mb_strlen($pass) < 8) fail('পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের দিন।');
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
    } elseif ($op === 'delete') {
        if ((int)q('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$id])->fetchColumn() > 0) fail('এই কাস্টমারের অর্ডার আছে, তাই মোছা যাবে না। অ্যাকাউন্ট বন্ধ করে দিন।', 409);
        q('DELETE FROM users WHERE id = ?', [$id]);
    } else fail('Unknown op');
    customers_list();
}

/* ---------- admin: analytics ---------- */

function analytics(): void {
    require_admin();
    $days = (int)($_GET['days'] ?? 30);
    if (!in_array($days, [7, 30, 90, 365], true)) $days = 30;
    $tz = LOCAL_TZ;
    $loc = fn(string $c) => "CONVERT_TZ($c, '+00:00', '$tz')";
    $since = "UTC_TIMESTAMP() - INTERVAL $days DAY";
    $prev = "created_at >= UTC_TIMESTAMP() - INTERVAL " . (2 * $days) . " DAY AND created_at < $since";
    $one = fn(string $sql, array $a = []) => (int)q($sql, $a)->fetchColumn();
    $kv = fn(string $sql) => array_map(fn($r) => ['k' => (string)$r['k'], 'n' => (int)$r['n']] + (isset($r['t']) ? ['t' => (int)$r['t']] : []), q($sql)->fetchAll());
    $today = q("SELECT DATE({$loc('UTC_TIMESTAMP()')})")->fetchColumn();

    // visitors
    $uniq = $one("SELECT COUNT(DISTINCT vid) FROM visits WHERE created_at >= $since");
    $views = $one("SELECT COUNT(*) FROM visits WHERE created_at >= $since");
    $returning = $one("SELECT COUNT(DISTINCT v.vid) FROM visits v WHERE v.created_at >= $since AND EXISTS (SELECT 1 FROM visits p WHERE p.vid = v.vid AND p.created_at < $since)");
    $single = $one("SELECT COUNT(*) FROM (SELECT vid FROM visits WHERE created_at >= $since GROUP BY vid HAVING COUNT(*) = 1) x");
    $hours = array_fill(0, 24, 0); $week = array_fill(0, 7, 0);
    foreach (q("SELECT HOUR({$loc('created_at')}) h, COUNT(DISTINCT vid) n FROM visits WHERE created_at >= $since GROUP BY h")->fetchAll() as $r) $hours[(int)$r['h']] = (int)$r['n'];
    foreach (q("SELECT DAYOFWEEK({$loc('created_at')}) - 1 w, COUNT(DISTINCT vid) n FROM visits WHERE created_at >= $since GROUP BY w")->fetchAll() as $r) $week[(int)$r['w']] = (int)$r['n'];

    // daily series
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("$today -$i day")); $series[$d] = ['d' => $d, 'uniques' => 0, 'visits' => 0, 'orders' => 0, 'earned' => 0]; }
    foreach (q("SELECT DATE({$loc('created_at')}) d, COUNT(*) v, COUNT(DISTINCT vid) u FROM visits WHERE created_at >= $since GROUP BY d")->fetchAll() as $r)
        if (isset($series[$r['d']])) { $series[$r['d']]['visits'] = (int)$r['v']; $series[$r['d']]['uniques'] = (int)$r['u']; }
    foreach (q("SELECT DATE({$loc('created_at')}) d, COUNT(*) n, SUM(CASE WHEN pay_status = 'verified' THEN total ELSE 0 END) t FROM orders WHERE cancelled = 0 AND created_at >= $since GROUP BY d")->fetchAll() as $r)
        if (isset($series[$r['d']])) { $series[$r['d']]['orders'] = (int)$r['n']; $series[$r['d']]['earned'] = (int)$r['t']; }

    // orders
    $ord = q("SELECT COUNT(*) n, SUM(cancelled = 1) cancelled, SUM(cancelled = 0 AND stage = 4) done, SUM(cancelled = 0 AND stage < 4) running,
                SUM(CASE WHEN cancelled = 0 THEN total ELSE 0 END) value FROM orders WHERE created_at >= $since")->fetch();
    $delivered = q("SELECT o.created_at, o.deadline_at, MIN(e.created_at) done_at FROM orders o JOIN order_events e ON e.order_id = o.id AND e.stage = 4
                    WHERE o.cancelled = 0 AND o.stage = 4 AND o.created_at >= $since GROUP BY o.id")->fetchAll();
    $onTime = count(array_filter($delivered, fn($r) => $r['done_at'] <= $r['deadline_at']));
    $avgHours = $delivered ? array_sum(array_map(fn($r) => (strtotime($r['done_at']) - strtotime($r['created_at'])) / 3600, $delivered)) / count($delivered) : 0;
    $stages = array_fill(0, STAGES, 0);
    foreach (q("SELECT stage, COUNT(*) n FROM orders WHERE cancelled = 0 AND created_at >= $since GROUP BY stage")->fetchAll() as $r) $stages[(int)$r['stage']] = (int)$r['n'];

    // where orders came from: the first visit of the browser that placed the order
    $orderSources = $kv("SELECT COALESCE(NULLIF(fv.utm, ''), fv.ref_host, '') k, COUNT(*) n FROM track_events te
        JOIN (SELECT v.vid, v.ref_host, v.utm FROM visits v JOIN (SELECT vid, MIN(id) mid FROM visits GROUP BY vid) f ON f.mid = v.id) fv ON fv.vid = te.vid
        WHERE te.name = 'order' AND te.created_at >= $since GROUP BY k ORDER BY n DESC LIMIT 8");

    // earnings
    $earn = fn(string $where) => $one("SELECT COALESCE(SUM(total), 0) FROM orders WHERE cancelled = 0 AND pay_status = 'verified' AND $where");
    $ym = fn(string $mod) => q("SELECT DATE_FORMAT({$loc('UTC_TIMESTAMP()')} $mod, '%Y-%m')")->fetchColumn();
    $months = [];
    for ($i = 11; $i >= 0; $i--) { $m = $ym("- INTERVAL $i MONTH"); $months[$m] = ['m' => $m, 'earned' => 0, 'orders' => 0]; }
    foreach (q("SELECT DATE_FORMAT({$loc('created_at')}, '%Y-%m') m, COUNT(*) n, SUM(CASE WHEN pay_status = 'verified' THEN total ELSE 0 END) t FROM orders
                WHERE cancelled = 0 AND created_at >= UTC_TIMESTAMP() - INTERVAL 13 MONTH GROUP BY m")->fetchAll() as $r)
        if (isset($months[$r['m']])) { $months[$r['m']]['earned'] = (int)$r['t']; $months[$r['m']]['orders'] = (int)$r['n']; }
    $thisM = $ym(''); $lastM = $ym('- INTERVAL 1 MONTH');

    out([
        'days' => $days,
        'visitors' => [
            'uniques' => $uniq, 'views' => $views, 'returning' => $returning, 'new' => $uniq - $returning,
            'single_page' => $single, 'prev_uniques' => $one("SELECT COUNT(DISTINCT vid) FROM visits WHERE $prev"),
            'today' => $one("SELECT COUNT(DISTINCT vid) FROM visits WHERE DATE({$loc('created_at')}) = ?", [$today]),
            'total_uniques' => $one('SELECT COUNT(DISTINCT vid) FROM visits'), 'total_views' => $one('SELECT COUNT(*) FROM visits'),
        ],
        'live' => array_map(fn($r) => ['path' => $r['path'], 'device' => $r['device'], 'browser' => $r['browser'], 'city' => $r['city'], 'country' => $r['country'],
            'since' => strtotime($r['first_seen'] . ' UTC'), 'seen' => strtotime($r['last_seen'] . ' UTC')],
            q('SELECT * FROM live_visitors WHERE last_seen > UTC_TIMESTAMP() - INTERVAL 2 MINUTE ORDER BY first_seen DESC LIMIT 100')->fetchAll()),
        'series' => array_values($series), 'hours' => $hours, 'weekdays' => $week,
        'devices' => $kv("SELECT device k, COUNT(DISTINCT vid) n FROM visits WHERE created_at >= $since GROUP BY device ORDER BY n DESC"),
        'browsers' => $kv("SELECT COALESCE(browser, 'Other') k, COUNT(DISTINCT vid) n FROM visits WHERE created_at >= $since GROUP BY k ORDER BY n DESC LIMIT 8"),
        'os' => $kv("SELECT COALESCE(os, 'Other') k, COUNT(DISTINCT vid) n FROM visits WHERE created_at >= $since GROUP BY k ORDER BY n DESC LIMIT 8"),
        'countries' => $kv("SELECT COALESCE(country, '') k, COUNT(DISTINCT vid) n FROM visits WHERE created_at >= $since GROUP BY k ORDER BY n DESC LIMIT 12"),
        'cities' => $kv("SELECT COALESCE(city, '') k, COUNT(DISTINCT vid) n FROM visits WHERE created_at >= $since AND city IS NOT NULL GROUP BY k ORDER BY n DESC LIMIT 12"),
        'regions' => $kv("SELECT COALESCE(region, '') k, COUNT(DISTINCT vid) n FROM visits WHERE created_at >= $since AND region IS NOT NULL GROUP BY k ORDER BY n DESC LIMIT 10"),
        'referrers' => $kv("SELECT COALESCE(ref_host, '') k, COUNT(DISTINCT vid) n FROM visits WHERE created_at >= $since GROUP BY k ORDER BY n DESC LIMIT 8"),
        'utm' => $kv("SELECT utm k, COUNT(DISTINCT vid) n FROM visits WHERE created_at >= $since AND utm IS NOT NULL GROUP BY utm ORDER BY n DESC LIMIT 8"),
        'pages' => $kv("SELECT path k, COUNT(*) n FROM visits WHERE created_at >= $since GROUP BY path ORDER BY n DESC LIMIT 8"),
        'funnel' => [
            'visitors' => $uniq,
            'checkout' => $one("SELECT COUNT(DISTINCT vid) FROM track_events WHERE name = 'checkout_open' AND created_at >= $since"),
            'whatsapp' => $one("SELECT COUNT(DISTINCT vid) FROM track_events WHERE name = 'whatsapp_click' AND created_at >= $since"),
            'orders' => (int)$ord['n'] - (int)$ord['cancelled'],
        ],
        'orders' => [
            'count' => (int)$ord['n'], 'prev_count' => $one("SELECT COUNT(*) FROM orders WHERE $prev"), 'cancelled' => (int)$ord['cancelled'],
            'done' => (int)$ord['done'], 'running' => (int)$ord['running'], 'value' => (int)$ord['value'],
            'on_time' => $onTime, 'delivered' => count($delivered), 'avg_hours' => round($avgHours, 1), 'stages' => $stages,
            'by_stack' => $kv("SELECT stack k, COUNT(*) n, SUM(total) t FROM orders WHERE cancelled = 0 AND created_at >= $since GROUP BY stack ORDER BY n DESC"),
            'by_pack' => $kv("SELECT CONCAT(pack, ' · ', stack) k, COUNT(*) n, SUM(total) t FROM orders WHERE cancelled = 0 AND created_at >= $since GROUP BY pack, stack ORDER BY n DESC"),
            'by_method' => $kv("SELECT pay_method k, COUNT(*) n, SUM(total) t FROM orders WHERE cancelled = 0 AND created_at >= $since GROUP BY pay_method ORDER BY n DESC"),
            'by_pay' => $kv("SELECT pay_status k, COUNT(*) n, SUM(total) t FROM orders WHERE cancelled = 0 AND created_at >= $since GROUP BY pay_status"),
            'sources' => $orderSources,
        ],
        'earnings' => [
            'range' => $earn("created_at >= $since"), 'prev' => $earn($prev),
            'all_time' => $earn('1'), 'pending' => $one("SELECT COALESCE(SUM(total), 0) FROM orders WHERE cancelled = 0 AND pay_status = 'pending'"),
            'this_month' => $earn("DATE_FORMAT({$loc('created_at')}, '%Y-%m') = '$thisM'"), 'last_month' => $earn("DATE_FORMAT({$loc('created_at')}, '%Y-%m') = '$lastM'"),
            'months' => array_values($months),
            'top_customers' => array_map(fn($r) => ['name' => $r['name'], 'email' => $r['email'], 'orders' => (int)$r['n'], 'total' => (int)$r['t']],
                q("SELECT u.name, u.email, COUNT(*) n, SUM(o.total) t FROM orders o JOIN users u ON u.id = o.user_id WHERE o.cancelled = 0 AND o.pay_status = 'verified' GROUP BY u.id ORDER BY t DESC LIMIT 8")->fetchAll()),
        ],
    ]);
}

/* ---------- online payments (PayStation) ---------- */

function public_payments(): array {
    $s = payment_settings();
    return ['online' => paystation_ready(), 'manual' => (bool)$s['manual_enabled'], 'pay_with_charge' => (int)$s['paystation']['pay_with_charge'], 'sandbox' => (bool)$s['paystation']['sandbox']];
}

// Records a new attempt and asks PayStation for a checkout page; returns its payment_url.
// Throws instead of fail() so order_create can still return the saved order.
function paystation_start(array $o): string {
    if (!paystation_ready()) throw new RuntimeException('অনলাইন পেমেন্ট এখন বন্ধ আছে।');
    $p = payment_settings()['paystation'];
    $info = json_decode($o['info_json'], true) ?: [];
    $cust = q('SELECT name, email, phone FROM users WHERE id = ?', [$o['user_id']])->fetch() ?: [];
    $invoice = $o['code'] . '-' . strtoupper(bin2hex(random_bytes(3)));
    q('INSERT INTO payments (order_id, invoice_number, amount, sandbox) VALUES (?,?,?,?)', [$o['id'], $invoice, $o['total'], $p['sandbox'] ? 1 : 0]);
    $r = paystation_post('/initiate-payment', paystation_fields($o, $info, $cust, $invoice));
    $url = (string)($r['json']['payment_url'] ?? '');
    if (ps_accepted($r) && preg_match('#^https://[^/]*paystation\.com\.bd/#', $url)) return $url;
    $why = $r['ok'] ? ('PayStation: ' . ($r['json']['message'] ?? 'অনুরোধ নেওয়া হয়নি') . ' (কোড ' . ($r['json']['status_code'] ?? '?') . ')') : $r['error'];
    q("UPDATE payments SET status = 'error', note = ? WHERE invoice_number = ?", [mb_substr($why, 0, 255), $invoice]);
    error_log('techill paystation initiate: ' . $why);
    throw new RuntimeException('অনলাইন পেমেন্ট শুরু করা যায়নি। ড্যাশবোর্ড থেকে আবার চেষ্টা করুন।');
}

function payment_rows(int $orderId): array {
    return array_map(fn($r) => ['id' => (int)$r['id'], 'invoice' => $r['invoice_number'], 'amount' => (int)$r['amount'], 'status' => $r['status'], 'trx_id' => $r['trx_id'],
        'method' => $r['method'], 'payer' => $r['payer'], 'sandbox' => (bool)$r['sandbox'], 'note' => $r['note'], 'created' => strtotime($r['created_at'] . ' UTC'), 'updated' => strtotime($r['updated_at'] . ' UTC')],
        q('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC', [$orderId])->fetchAll());
}

// Customer (or admin) starts / retries an online payment for an unpaid order.
function pay_start(): void {
    $u = require_user();
    $o = order_for($u, (int)($_POST['order_id'] ?? 0));
    if (is_staff($u) && !is_admin($u)) fail('শুধু কাস্টমার বা অ্যাডমিন পেমেন্ট শুরু করতে পারেন।', 403);
    if ($o['cancelled']) fail('এই অর্ডার বাতিল করা হয়েছে।', 409);
    if ($o['pay_status'] === 'verified') fail('এই অর্ডারের পেমেন্ট আগেই হয়ে গেছে।', 409);
    // A payment may have gone through without the customer coming back; check before charging again.
    foreach (q("SELECT * FROM payments WHERE order_id = ? AND status IN ('initiated','processing') AND created_at > UTC_TIMESTAMP() - INTERVAL 2 DAY ORDER BY id DESC LIMIT 3", [$o['id']])->fetchAll() as $prev) {
        if (paystation_verify($prev, '', fb_context()) === 'success') out(['paid' => true]);
    }
    if ($o['pay_method'] !== 'PayStation') q("UPDATE orders SET pay_method = 'PayStation', pay_status = 'pending' WHERE id = ?", [$o['id']]);
    try { out(['payment_url' => paystation_start(q('SELECT * FROM orders WHERE id = ?', [$o['id']])->fetch())]); }
    catch (RuntimeException $e) { fail($e->getMessage(), 502); }
}

// PayStation sends the customer's browser back here (GET), and may also POST a server notification.
// Either way we look the payment up with PayStation before changing anything.
function paystation_callback(): void {
    $in = $_GET + $_POST;
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($body)) $in += (is_array($body['data'] ?? null) ? $body['data'] : []) + $body;
    $pick = function (array $keys) use ($in): string {
        foreach ($keys as $k) if (isset($in[$k]) && is_scalar($in[$k]) && trim((string)$in[$k]) !== '') return mb_substr(trim((string)$in[$k]), 0, 60);
        return '';
    };
    $invoice = $pick(['invoice_number', 'invoiceNumber', 'invoice_no']);
    $trx = $pick(['trx_id', 'trxId', 'transaction_id']);
    $pay = $invoice !== '' ? q('SELECT * FROM payments WHERE invoice_number = ?', [$invoice])->fetch() : false;
    if (!$pay && $trx !== '') $pay = q('SELECT * FROM payments WHERE trx_id = ?', [$trx])->fetch();
    $isIpn = $_SERVER['REQUEST_METHOD'] === 'POST';
    if (!$pay) {
        if ($isIpn) out(['status' => 'error', 'message' => 'unknown invoice'], 404);
        header('Location: ' . site_url('dashboard.html#pay=unknown')); exit;
    }
    $status = paystation_verify($pay, $trx, $isIpn ? [] : fb_context());
    if ($isIpn) out(['status' => 'success', 'result' => $status]);
    $flag = ['success' => 'success', 'canceled' => 'cancel', 'processing' => 'pending'][$status] ?? 'failed';
    header('Cache-Control: no-store');
    header('Location: ' . site_url('dashboard.html#o=' . (int)$pay['order_id'] . '&pay=' . $flag));
    exit;
}

// Admin: ask PayStation again about the latest attempts of an order.
function pay_verify(): void {
    $u = require_admin();
    $o = order_for($u, (int)($_POST['order_id'] ?? 0));
    $results = [];
    foreach (q("SELECT * FROM payments WHERE order_id = ? AND status <> 'success' ORDER BY id DESC LIMIT 5", [$o['id']])->fetchAll() as $p) $results[] = paystation_verify($p, (string)$p['trx_id']);
    out(['results' => $results, 'payments' => payment_rows((int)$o['id']), 'order' => order_out(order_row((int)$o['id']), $u)]);
}

function payment_settings_out(): array {
    $s = payment_settings(); $p = $s['paystation'];
    return ['manual_enabled' => (bool)$s['manual_enabled'], 'paystation' => ['enabled' => (bool)$p['enabled'], 'sandbox' => (bool)$p['sandbox'], 'merchant_id' => $p['merchant_id'],
        'password_set' => $p['password'] !== '', 'pay_with_charge' => (int)$p['pay_with_charge'], 'ready' => paystation_ready()],
        'callback_url' => site_url('api.php?action=paystation_callback'), 'curl' => function_exists('curl_init')];
}
function payment_settings_get(): void { require_admin(); out(['settings' => payment_settings_out()]); }

function payment_settings_save(): void {
    require_admin();
    $s = payment_settings();
    $p = $s['paystation'];
    $p['enabled'] = !empty($_POST['ps_enabled']);
    $p['sandbox'] = !empty($_POST['ps_sandbox']);
    $p['merchant_id'] = str_in('ps_merchant_id', 80);
    if (isset($_POST['ps_password']) && (string)$_POST['ps_password'] !== '') $p['password'] = mb_substr((string)$_POST['ps_password'], 0, 200);
    if (!empty($_POST['ps_clear_password'])) $p['password'] = '';
    $p['pay_with_charge'] = (int)!empty($_POST['ps_pay_with_charge']);
    $manual = !empty($_POST['manual_enabled']);
    if ($p['enabled'] && ($p['merchant_id'] === '' || $p['password'] === '')) fail('গেটওয়ে চালু করতে Merchant ID আর পাসওয়ার্ড দুটোই দিন।');
    if (!$manual && !$p['enabled']) fail('অন্তত একটা পেমেন্ট মাধ্যম চালু রাখতে হবে, নইলে কেউ অর্ডার করতে পারবে না।');
    q("INSERT INTO settings (k, v) VALUES ('payments', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [json_encode(['manual_enabled' => $manual, 'paystation' => $p], JSON_UNESCAPED_UNICODE)]);
    payment_settings(true);
    out(['settings' => payment_settings_out()]);
}
// Checks that this server can reach PayStation and that the Merchant ID is recognised,
// by looking up an invoice number that does not exist. It does not create a payment.
function payment_test(): void {
    require_admin();
    $p = payment_settings()['paystation'];
    if ($p['merchant_id'] === '') fail('আগে Merchant ID দিয়ে সেভ করুন।');
    $r = paystation_post('/transaction-status', ['invoice_number' => 'TECHILL-TEST-' . strtoupper(bin2hex(random_bytes(3)))], ['merchantId: ' . $p['merchant_id']]);
    if (!$r['ok']) out(['reachable' => false, 'message' => $r['error']]);
    out(['reachable' => true, 'status_code' => (string)($r['json']['status_code'] ?? ''), 'message' => (string)($r['json']['message'] ?? ''), 'sandbox' => (bool)$p['sandbox']]);
}

function payments_list(): void {
    require_admin();
    $rows = q('SELECT p.*, o.code, u.name c_name FROM payments p JOIN orders o ON o.id = p.order_id JOIN users u ON u.id = o.user_id ORDER BY p.id DESC LIMIT 100')->fetchAll();
    $sum = q("SELECT COUNT(*) n, COALESCE(SUM(amount), 0) t FROM payments WHERE status = 'success' AND created_at > UTC_TIMESTAMP() - INTERVAL 30 DAY")->fetch();
    out(['payments' => array_map(fn($r) => ['id' => (int)$r['id'], 'order_id' => (int)$r['order_id'], 'code' => $r['code'], 'customer' => $r['c_name'], 'invoice' => $r['invoice_number'],
        'amount' => (int)$r['amount'], 'status' => $r['status'], 'trx_id' => $r['trx_id'], 'method' => $r['method'], 'payer' => $r['payer'], 'sandbox' => (bool)$r['sandbox'], 'note' => $r['note'],
        'created' => strtotime($r['created_at'] . ' UTC')], $rows), 'month' => ['count' => (int)$sum['n'], 'total' => (int)$sum['t']]]);
}

/* ---------- photos ---------- */

// Profile photo for yourself, or (admin) for anyone via user_id.
function avatar_target(): array {
    $u = require_user();
    $id = (int)($_POST['user_id'] ?? 0);
    if ($id && $id !== $u['id']) {
        if (!is_admin($u)) fail('অন্যের ছবি বদলানো যায় না।', 403);
        $t = q('SELECT id, avatar FROM users WHERE id = ?', [$id])->fetch() ?: fail('অ্যাকাউন্ট পাওয়া যায়নি।', 404);
        return $t;
    }
    return q('SELECT id, avatar FROM users WHERE id = ?', [$u['id']])->fetch();
}

function avatar_upload(): void {
    $t = avatar_target();
    $path = save_image('photo', 320, true);
    q('UPDATE users SET avatar = ? WHERE id = ?', [$path, $t['id']]);
    delete_media($t['avatar']);
    out(['avatar' => $path, 'user_id' => (int)$t['id']]);
}

function avatar_remove(): void {
    $t = avatar_target();
    q('UPDATE users SET avatar = NULL WHERE id = ?', [$t['id']]);
    delete_media($t['avatar']);
    out(['avatar' => null, 'user_id' => (int)$t['id']]);
}

// Admin: an image for the catalog (template photos). Saved into the catalog when the editor saves.
function media_upload(): void {
    require_admin();
    out(['url' => save_image('photo', 1200, false)]);
}

/* ---------- Facebook Pixel ---------- */

function public_pixel(): ?array {
    $m = marketing_settings();
    return $m['pixel_enabled'] && $m['pixel_id'] !== '' ? ['id' => $m['pixel_id']] : null;
}

function marketing_out(): array {
    $m = marketing_settings();
    return ['pixel_enabled' => (bool)$m['pixel_enabled'], 'pixel_id' => $m['pixel_id'], 'capi_set' => $m['capi_token'] !== '', 'test_code' => $m['test_code'], 'capi_last' => $m['capi_last']];
}
function marketing_get(): void { require_admin(); out(['settings' => marketing_out()]); }

function marketing_save(): void {
    require_admin();
    $m = marketing_settings();
    $id = preg_replace('/\D/', '', str_in('pixel_id', 40));
    $enabled = !empty($_POST['pixel_enabled']);
    if ($enabled && !preg_match('/^\d{10,20}$/', $id)) fail('সঠিক Pixel ID দিন (শুধু সংখ্যা, সাধারণত ১৫-১৬ ডিজিট)।');
    $m['pixel_enabled'] = $enabled;
    $m['pixel_id'] = $id;
    $token = trim((string)($_POST['capi_token'] ?? ''));
    if ($token !== '') {
        if (!preg_match('/^[A-Za-z0-9_\-]{20,600}$/', $token)) fail('Access token সঠিক মনে হচ্ছে না, Events Manager থেকে আবার কপি করুন।');
        $m['capi_token'] = $token;
    }
    if (!empty($_POST['capi_clear'])) $m['capi_token'] = '';
    $m['test_code'] = preg_replace('/[^A-Za-z0-9]/', '', str_in('test_code', 40));
    q("INSERT INTO settings (k, v) VALUES ('marketing', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [json_encode($m, JSON_UNESCAPED_UNICODE)]);
    marketing_settings(true);
    out(['settings' => marketing_out()]);
}

// Sends one server-side test event; it shows under "Test events" in Meta Events Manager when a test code is set.
function capi_test(): void {
    require_admin();
    $m = marketing_settings();
    if ($m['pixel_id'] === '' || $m['capi_token'] === '') fail('আগে Pixel ID আর Access token দিয়ে সেভ করুন।');
    if (!function_exists('curl_init')) fail('সার্ভারে PHP cURL চালু নেই।');
    $body = ['data' => [['event_name' => 'PageView', 'event_time' => time(), 'event_id' => 'techill-test-' . bin2hex(random_bytes(4)), 'action_source' => 'website',
        'event_source_url' => site_url('index.html'), 'user_data' => ['client_ip_address' => client_ip(), 'client_user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Techill'), 0, 400)]]]];
    if ($m['test_code'] !== '') $body['test_event_code'] = $m['test_code'];
    out(fb_post($m, $body));
}

/* ---------- email notifications ---------- */

function notify_new_order(array $o, array $u, array $info): void {
    if (!notify_on('new_order')) return;
    $online = $o['pay_method'] === 'PayStation';
    $lines = json_decode($o['lines_json'], true) ?: [];
    $rows = ['অর্ডার' => $o['code'], 'প্যাকেজ' => $o['pack'] . ' · ' . $o['stack'], 'টেমপ্লেট' => $o['template']] + ($o['domain'] ? ['ডোমেইন' => $o['domain']] : []);
    foreach (array_slice($lines, 1) as $l) $rows[$l['label']] = ($l['amt'] < 0 ? '−' : '') . taka(abs((int)$l['amt']));
    $rows['মোট'] = taka((int)$o['total']);
    $rows['পেমেন্ট'] = $online ? 'অনলাইন (PayStation)' : $o['pay_method'] . ' · TrxID ' . $o['pay_trx'];
    $next = $online ? 'অনলাইন পেমেন্ট সম্পন্ন হলেই কাজ শুরু হবে, আর তখন থেকে ডেলিভারির কাউন্টডাউন চলবে।' : 'আমাদের টিম পেমেন্ট মিলিয়ে দেখে কাজ শুরু করবে। ড্যাশবোর্ডে প্রতিটা ধাপ দেখতে পাবেন।';
    notify_user((int)$u['id'], "অর্ডার {$o['code']} পেয়েছি, ধন্যবাদ!", mail_layout('আপনার অর্ডার পেয়েছি',
        '<p>আসসালামু আলাইকুম ' . h($info['admin_name'] ?: $u['name']) . ', Techill-এ অর্ডার করার জন্য ধন্যবাদ।</p>' . mail_kv($rows) . '<p>' . h($next) . '</p>',
        'ড্যাশবোর্ডে প্রোজেক্ট দেখুন', site_url('dashboard.html#o=' . $o['id'])), 'new_order', (int)$o['id'], true);
    $adm = ($o['domain'] ? ['ডোমেইন' => $o['domain']] : []) + ['কাস্টমার' => $info['admin_name'] ?: $u['name'], 'ইমেইল' => $u['email'], 'ফোন' => $info['phone'], 'প্যাকেজ' => $o['pack'] . ' · ' . $o['stack'], 'মোট' => taka((int)$o['total']), 'পেমেন্ট' => $rows['পেমেন্ট']];
    $html = mail_layout("নতুন অর্ডার {$o['code']}", '<p>সাইটে নতুন একটা অর্ডার এসেছে।</p>' . mail_kv($adm) . ($online ? '' : '<p>বিকাশ/নগদে টাকা এসেছে কিনা মিলিয়ে অ্যাডমিন প্যানেল থেকে পেমেন্ট যাচাই করুন।</p>'), 'অ্যাডমিন প্যানেলে দেখুন', site_url('admin.html#orders'));
    foreach (admin_emails() as $to) mail_queue($to, "নতুন অর্ডার {$o['code']} · " . taka((int)$o['total']), $html, 'new_order', (int)$o['id']);
}

// A chat message reaches the other side by email, at most once per 10 minutes per order and person.
function notify_message(array $o, array $sender, string $body): void {
    if (!notify_on('message')) return;
    $preview = mb_strlen($body) > 400 ? mb_substr($body, 0, 400) . '…' : $body;
    $html = fn(string $link) => mail_layout("অর্ডার {$o['code']}-এ নতুন মেসেজ", '<p><b>' . h($sender['name']) . '</b> লিখেছেন:</p><p style="background:#f7f9fc;border-radius:10px;padding:12px 16px;white-space:pre-wrap">' . h($preview) . '</p>', 'উত্তর দিন', $link);
    $subject = "{$o['code']}: {$sender['name']}-এর নতুন মেসেজ";
    if (is_staff($sender)) {
        $c = q('SELECT id, email, email_notify FROM users WHERE id = ? AND active = 1', [$o['user_id']])->fetch();
        if ($c && $c['email_notify'] && !mail_recent('message', (int)$o['id'], $c['email'], 10)) mail_queue($c['email'], $subject, $html(site_url('dashboard.html#o=' . $o['id'])), 'message', (int)$o['id']);
        return;
    }
    $to = [];
    if ($o['developer_id']) { $d = q('SELECT email, email_notify FROM users WHERE id = ? AND active = 1', [$o['developer_id']])->fetch(); if ($d && $d['email_notify']) $to[] = $d['email']; }
    if (!$to) $to = admin_emails();
    foreach ($to as $e) if (!mail_recent('message', (int)$o['id'], $e, 10)) mail_queue($e, $subject, $html(site_url('dashboard.html#o=' . $o['id'])), 'message', (int)$o['id']);
}

function notify_assign(array $o, array $dev): void {
    if (!notify_on('assign')) return;
    $info = json_decode($o['info_json'], true) ?: [];
    notify_user((int)$dev['id'], "নতুন প্রোজেক্ট: {$o['code']}", mail_layout("আপনাকে {$o['code']} প্রোজেক্ট দেওয়া হয়েছে", mail_kv([
        'প্যাকেজ' => $o['pack'] . ' · ' . $o['stack'], 'টেমপ্লেট' => $o['template'], 'কাস্টমার' => $info['admin_name'] ?? '',
        'ডেডলাইন' => date('j M, g:i A', strtotime($o['deadline_at'] . ' UTC') + 6 * 3600) . ' (বাংলাদেশ সময়)']), 'প্রোজেক্ট খুলুন', site_url('dashboard.html#o=' . $o['id'])), 'assign', (int)$o['id']);
}

/* ---------- account: email codes, profile, password ---------- */

function otp_send(): void {
    $purpose = str_in('purpose', 10);
    if (!in_array($purpose, OTP_PURPOSES, true)) fail('Unknown purpose');
    if (!email_ready()) fail('ইমেইল সার্ভিস এখন বন্ধ আছে।', 409);
    $u = current_user();
    if (in_array($purpose, ['verify', 'change'], true) && !$u) fail('আগে লগইন করুন।', 401);
    if ($purpose === 'verify') {
        if ($u['email_verified']) fail('আপনার ইমেইল আগেই যাচাই হয়েছে।', 409);
        $email = $u['email'];
    } else {
        $email = mb_strtolower(str_in('email', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('সঠিক ইমেইল দিন।');
        $acct = q('SELECT id, active FROM users WHERE email = ?', [$email])->fetch();
        if ($purpose === 'register' && $acct) fail('এই ইমেইলে আগেই অ্যাকাউন্ট আছে। সেই পাসওয়ার্ড দিন বা লগইন করুন।', 409);
        if ($purpose === 'change' && $acct) fail('এই ইমেইলে অন্য একটা অ্যাকাউন্ট আছে।', 409);
        if ($purpose === 'reset' && (!$acct || !$acct['active'])) out(['sent' => true, 'email' => $email]);   // same answer either way
    }
    otp_issue($email, $purpose);
    out(['sent' => true, 'email' => $email]);
}

function password_reset(): void {
    throttle();
    $email = mb_strtolower(str_in('email', 190));
    $pass = (string)($_POST['password'] ?? '');
    if (mb_strlen($pass) < 8) fail('নতুন পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের দিন।');
    $row = q('SELECT id FROM users WHERE email = ? AND active = 1', [$email])->fetch();
    if (!$row) { note_attempt(); fail('কোড মেলেনি, আবার দেখে লিখুন।', 422); }
    otp_check($email, 'reset', str_in('otp', 10));
    q('UPDATE users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $row['id']]);
    q('DELETE FROM login_attempts WHERE ip = ?', [client_ip()]);
    login_as((int)$row['id']);
    out(['user' => current_user(true), 'csrf' => $_SESSION['csrf']]);
}

function email_verify(): void {
    $u = require_user();
    otp_check($u['email'], 'verify', str_in('otp', 10));
    q('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]);
    out(['user' => current_user(true)]);
}

function profile_update(): void {
    $u = require_user();
    $name = str_in('name', 120); $phone = str_in('phone', 20);
    if ($name === '') fail('নাম দিন।');
    if ($phone !== '' && !valid_phone($phone)) fail('সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন।');
    q('UPDATE users SET name = ?, phone = ?, email_notify = ? WHERE id = ?', [$name, $phone ?: null, empty($_POST['email_notify']) ? 0 : 1, $u['id']]);
    out(['user' => current_user(true)]);
}

function email_change(): void {
    $u = require_user();
    $email = mb_strtolower(str_in('email', 190));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('সঠিক ইমেইল দিন।');
    if ($email === $u['email']) fail('এটাই আপনার বর্তমান ইমেইল।');
    if (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) fail('এই ইমেইলে অন্য একটা অ্যাকাউন্ট আছে।', 409);
    if (email_ready()) otp_check($email, 'change', str_in('otp', 10));
    elseif (!is_admin($u)) fail('ইমেইল বদলাতে যাচাই কোড লাগে, কিন্তু ইমেইল সার্ভিস এখন বন্ধ। অ্যাডমিনকে বলুন।', 409);
    q('UPDATE users SET email = ?, email_verified_at = ' . (email_ready() ? 'UTC_TIMESTAMP()' : 'NULL') . ' WHERE id = ?', [$email, $u['id']]);
    out(['user' => current_user(true)]);
}

function password_change(): void {
    $u = require_user();
    $row = q('SELECT password_hash FROM users WHERE id = ?', [$u['id']])->fetch();
    if (!password_verify((string)($_POST['current'] ?? ''), $row['password_hash'])) { note_attempt(); throttle(); fail('বর্তমান পাসওয়ার্ড মেলেনি।', 422); }
    $pass = (string)($_POST['password'] ?? '');
    if (mb_strlen($pass) < 8) fail('নতুন পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের দিন।');
    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    login_as($u['id']);
    out(['csrf' => $_SESSION['csrf']]);
}

/* ---------- admin: email setup ---------- */

function email_settings_out(): array {
    $e = email_settings();
    $log = array_map(fn($r) => ['to' => $r['to_email'], 'subject' => $r['subject'], 'kind' => $r['kind'], 'status' => $r['status'], 'error' => $r['error'], 'created' => strtotime($r['created_at'] . ' UTC')],
        q('SELECT * FROM email_log ORDER BY id DESC LIMIT 40')->fetchAll());
    $st = q("SELECT COALESCE(SUM(status = 'sent'), 0) sent, COALESCE(SUM(status = 'failed'), 0) failed, COUNT(*) n FROM email_log WHERE created_at > UTC_TIMESTAMP() - INTERVAL 30 DAY")->fetch();
    unset($e['password']);
    return $e + ['password_set' => email_settings()['password'] !== '', 'ready' => email_ready(), 'log' => $log, 'stats' => array_map('intval', $st),
        'host_hint' => preg_replace('/^www\./', '', preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? ''))),
        'admin_fallback' => array_column(q("SELECT email FROM users WHERE role = 'admin' AND active = 1")->fetchAll(), 'email'),
        'openssl' => extension_loaded('openssl'), 'mail_fn' => function_exists('mail')];
}
function email_settings_get(): void { require_admin(); out(['settings' => email_settings_out()]); }

function email_save(): void {
    require_admin();
    $e = email_settings();
    $e['enabled'] = !empty($_POST['enabled']);
    $e['transport'] = str_in('transport', 10) === 'mail' ? 'mail' : 'smtp';
    $e['host'] = strtolower(str_in('host', 190));
    if ($e['host'] !== '' && !preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/', $e['host'])) fail('SMTP হোস্ট সঠিক নয় (যেমন mail.yourdomain.com)।');
    $e['port'] = (int)($_POST['port'] ?? 465);
    if ($e['port'] < 1 || $e['port'] > 65535) fail('পোর্ট সঠিক নয় (সাধারণত 465 বা 587)।');
    $e['secure'] = in_array($_POST['secure'] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST['secure'] : 'ssl';
    $e['username'] = str_in('username', 190);
    if (isset($_POST['password']) && (string)$_POST['password'] !== '') $e['password'] = mb_substr((string)$_POST['password'], 0, 300);
    if (!empty($_POST['clear_password'])) $e['password'] = '';
    $e['from_email'] = mb_strtolower(str_in('from_email', 190));
    $e['from_name'] = preg_replace('/[\r\n"<>]/', '', str_in('from_name', 80)) ?: 'Techill';
    $e['reply_to'] = mb_strtolower(str_in('reply_to', 190));
    if ($e['from_email'] !== '' && !filter_var($e['from_email'], FILTER_VALIDATE_EMAIL)) fail('প্রেরকের ইমেইল (From) সঠিক নয়।');
    if ($e['reply_to'] !== '' && !filter_var($e['reply_to'], FILTER_VALIDATE_EMAIL)) fail('Reply-To ইমেইল সঠিক নয়।');
    $list = array_filter(array_map('trim', preg_split('/[,;\s]+/', str_in('admin_emails', 1000))));
    foreach ($list as $x) if (!filter_var($x, FILTER_VALIDATE_EMAIL)) fail("এই ইমেইলটা সঠিক নয়: $x");
    $e['admin_emails'] = implode(', ', array_unique(array_map('mb_strtolower', $list)));
    $e['otp_required'] = !empty($_POST['otp_required']);
    foreach (array_keys(EMAIL_DEFAULTS['notify']) as $k) $e['notify'][$k] = !empty($_POST["n_$k"]);
    if ($e['enabled']) {
        if ($e['from_email'] === '') fail('ইমেইল চালু করতে প্রেরকের ইমেইল (From) দিন।');
        if ($e['transport'] === 'smtp' && $e['host'] === '') fail('SMTP হোস্ট দিন, অথবা "PHP mail()" বাছুন।');
        if ($e['transport'] === 'smtp' && $e['username'] !== '' && $e['password'] === '') fail('SMTP পাসওয়ার্ড দিন।');
    }
    settings_store('email', $e);
    email_settings(true);
    out(['settings' => email_settings_out()]);
}

// Sends a test message with the saved settings, right now, and reports the server's answer.
function email_test(): void {
    $u = require_admin();
    $to = mb_strtolower(str_in('to', 190)) ?: $u['email'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) fail('যে ঠিকানায় পাঠাবেন সেটা সঠিক নয়।');
    $e = email_settings();
    if (!filter_var($e['from_email'], FILTER_VALIDATE_EMAIL) || ($e['transport'] === 'smtp' && $e['host'] === '')) fail('আগে SMTP তথ্য আর প্রেরকের ইমেইল দিয়ে সেভ করুন।');
    $t0 = microtime(true);
    [$ok, $err] = mail_send_now($to, 'Techill টেস্ট ইমেইল', mail_layout('ইমেইল সেটআপ কাজ করছে', '<p>এই ইমেইল পেয়ে থাকলে Techill থেকে OTP আর নোটিফিকেশন ঠিকমতো যাবে।</p>' . mail_kv(['সার্ভার' => $e['transport'] === 'mail' ? 'PHP mail()' : $e['host'] . ':' . $e['port'] . ' (' . strtoupper($e['secure']) . ')', 'প্রেরক' => $e['from_email']])), $e);
    q("INSERT INTO email_log (to_email, subject, kind, status, error, sent_at) VALUES (?, 'Techill টেস্ট ইমেইল', 'test', ?, ?, IF(?, UTC_TIMESTAMP(), NULL))", [$to, $ok ? 'sent' : 'failed', $ok ? null : $err, $ok ? 1 : 0]);
    out(['sent' => $ok, 'message' => $ok ? "$to-এ টেস্ট ইমেইল পাঠানো হয়েছে (" . bn_digits(number_format(microtime(true) - $t0, 1)) . ' সেকেন্ড)। Inbox আর Spam দুটোই দেখুন।' : $err, 'settings' => email_settings_out()]);
}

/* ---------- floating support button ---------- */

function public_support(): ?array {
    $s = support_settings();
    if (!$s['enabled']) return null;
    $wa = $s['whatsapp'] ?: preg_replace('/\D/', '', (string)(catalog()['whatsapp'] ?? ''));
    $out = ['greeting' => $s['greeting'], 'on_dashboard' => (bool)$s['on_dashboard'],
        'messenger' => $s['messenger'] !== '' ? 'https://m.me/' . rawurlencode($s['messenger']) : null,
        'whatsapp' => $wa !== '' ? $wa : null, 'wa_text' => $s['wa_text'],
        'tawk' => $s['tawk_property'] !== '' ? ['property' => $s['tawk_property'], 'widget' => $s['tawk_widget'] ?: 'default'] : null];
    return $out['messenger'] || $out['whatsapp'] || $out['tawk'] ? $out : null;
}

function support_out(): array {
    $clicks = [];
    foreach (q("SELECT name, COUNT(DISTINCT vid) n FROM track_events WHERE name IN ('support_open','support_messenger','support_whatsapp','support_tawk') AND created_at > UTC_TIMESTAMP() - INTERVAL 30 DAY GROUP BY name")->fetchAll() as $r) $clicks[$r['name']] = (int)$r['n'];
    return support_settings() + ['catalog_whatsapp' => preg_replace('/\D/', '', (string)(catalog()['whatsapp'] ?? '')), 'clicks' => (object)$clicks, 'public' => public_support()];
}
function support_get(): void { require_admin(); out(['settings' => support_out()]); }

function support_save(): void {
    require_admin();
    $s = support_settings();
    $s['enabled'] = !empty($_POST['enabled']);
    $s['on_dashboard'] = !empty($_POST['on_dashboard']);
    $s['greeting'] = str_in('greeting', 80);
    // Messenger: a page username or ID, from "techillbd", "m.me/techillbd" or a facebook.com page link.
    $m = trim(str_in('messenger', 255));
    if ($m !== '') {
        if (preg_match('#profile\.php\?id=(\d{5,20})#', $m, $x)) $m = $x[1];
        elseif (preg_match('#(?:m\.me|messenger\.com/t|facebook\.com|fb\.com)/([A-Za-z0-9.\-_]{3,80})#i', $m, $x)) $m = $x[1];
        $m = trim($m, '/@ ');
        if (!preg_match('/^[A-Za-z0-9.\-_]{3,80}$/', $m) || in_array(strtolower($m), ['pages', 'groups', 'people', 'profile.php'], true)) fail('Messenger-এর জন্য ফেসবুক পেজের username বা লিংক দিন (যেমন techillbd বা m.me/techillbd)।');
    }
    $s['messenger'] = $m;
    $wa = digits_only(str_in('whatsapp', 30));
    if ($wa !== '') {
        if (preg_match('/^01[3-9]\d{8}$/', $wa)) $wa = '88' . $wa;
        if (!preg_match('/^\d{8,15}$/', $wa)) fail('হোয়াটসঅ্যাপ নম্বর সঠিক নয় (যেমন 01712345678 বা 8801712345678)।');
    }
    $s['whatsapp'] = $wa;
    $s['wa_text'] = str_in('wa_text', 300);
    // tawk.to: accept the property ID, the "Direct Chat Link" or the whole embed script.
    $t = str_in('tawk', 3000);
    if ($t === '') { $s['tawk_property'] = ''; $s['tawk_widget'] = ''; }
    elseif (preg_match('#(?:embed\.tawk\.to|tawk\.to/chat)/([a-f0-9]{24})(?:/([A-Za-z0-9]{3,30}))?#i', $t, $x)) { $s['tawk_property'] = strtolower($x[1]); $s['tawk_widget'] = $x[2] ?? 'default'; }
    elseif (preg_match('/^([a-f0-9]{24})(?:\/([A-Za-z0-9]{3,30}))?$/i', trim($t), $x)) { $s['tawk_property'] = strtolower($x[1]); $s['tawk_widget'] = $x[2] ?? 'default'; }
    else fail('tawk.to-এর Direct Chat Link বা পুরো widget কোডটা পেস্ট করুন (tawk.to → Administration → Chat Widget)।');
    settings_store('support', $s);
    support_settings(true);
    out(['settings' => support_out()]);
}

/* ---------- referrals: customer side ---------- */

function public_referral(): ?array {
    $s = referral_settings();
    return $s['enabled'] ? ['reward' => (int)$s['reward'], 'discount' => (int)$s['discount'], 'min_order' => (int)$s['min_order']] : null;
}

function mask_name(?string $n): string {
    $p = preg_split('/\s+/u', trim((string)$n)) ?: [''];
    return $p[0] . (isset($p[1]) ? ' ' . mb_substr($p[1], 0, 1) . '.' : '');
}

// Public: is this code usable? Gives the referrer's first name so the visitor knows who invited them.
function ref_check(): void {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['code'] ?? '')));
    $s = referral_settings();
    if (!$s['enabled']) out(['valid' => false, 'message' => 'রেফারেল অফার এখন বন্ধ আছে।']);
    $r = referrer_by_code($code);
    if (!$r) out(['valid' => false, 'message' => 'রেফারেল কোডটা সঠিক নয়।']);
    $u = current_user();
    if ($u && (int)$u['id'] === (int)$r['id']) out(['valid' => false, 'message' => 'নিজের রেফারেল কোড নিজে ব্যবহার করা যায় না।']);
    if ($u && $s['first_order_only'] && q('SELECT 1 FROM orders WHERE user_id = ? AND cancelled = 0 LIMIT 1', [$u['id']])->fetch()) out(['valid' => false, 'message' => 'রেফারেল ছাড় শুধু প্রথম অর্ডারে পাওয়া যায়।']);
    out(['valid' => true, 'code' => $code, 'name' => preg_split('/\s+/u', trim($r['name']))[0], 'discount' => (int)$s['discount'], 'min_order' => (int)$s['min_order']]);
}

function referral_me_out(array $u): array {
    $s = referral_settings();
    $code = ref_code_for($u['id']);
    $sum = referral_summary($u['id']);
    $open = (bool)q("SELECT 1 FROM payouts WHERE user_id = ? AND status = 'requested'", [$u['id']])->fetch();
    $why = !$s['enabled'] && !$sum['n'] ? 'রেফারেল অফার এখন বন্ধ আছে।'
        : ($open ? 'আগের অনুরোধটা এখনো প্রক্রিয়াধীন।'
        : ($sum['balance'] < max(1, (int)$s['payout_min']) ? 'কমপক্ষে ' . taka(max(1, (int)$s['payout_min'])) . ' জমা হলে তুলতে পারবেন।'
        : (email_ready() && !$u['email_verified'] ? 'টাকা তুলতে আগে প্রোফাইল থেকে ইমেইল যাচাই করুন।' : '')));
    return [
        'settings' => ['enabled' => (bool)$s['enabled'], 'reward' => (int)$s['reward'], 'discount' => (int)$s['discount'], 'min_order' => (int)$s['min_order'], 'trigger' => $s['trigger'], 'payout_min' => (int)$s['payout_min'], 'first_order_only' => (bool)$s['first_order_only']],
        'code' => $code, 'link' => site_url('index.html?ref=' . $code), 'summary' => $sum,
        'can_withdraw' => $why === '', 'withdraw_note' => $why,
        'referrals' => array_map(fn($r) => ['name' => mask_name($r['name']), 'status' => $r['status'], 'reward' => (int)$r['reward'], 'created' => strtotime($r['created_at'] . ' UTC'), 'earned' => $r['earned_at'] ? strtotime($r['earned_at'] . ' UTC') : null, 'stage' => (int)$r['stage']],
            q('SELECT r.*, u.name, o.stage FROM referrals r JOIN users u ON u.id = r.referee_id JOIN orders o ON o.id = r.order_id WHERE r.referrer_id = ? ORDER BY r.id DESC LIMIT 200', [$u['id']])->fetchAll()),
        'payouts' => array_map(fn($p) => ['id' => (int)$p['id'], 'amount' => (int)$p['amount'], 'method' => $p['method'], 'account' => $p['account'], 'status' => $p['status'], 'trx' => $p['trx'], 'note' => $p['note'], 'created' => strtotime($p['created_at'] . ' UTC')],
            q('SELECT * FROM payouts WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$u['id']])->fetchAll()),
    ];
}

function referral_me(): void {
    $u = require_user();
    if ($u['role'] !== 'customer') fail('রেফারেল শুধু কাস্টমারদের জন্য।', 403);
    out(referral_me_out($u));
}

function payout_request(): void {
    $u = require_user();
    if ($u['role'] !== 'customer') fail('রেফারেল শুধু কাস্টমারদের জন্য।', 403);
    $method = str_in('method', 20);
    if (!in_array($method, ['bKash', 'Nagad', 'Rocket'], true)) fail('কোন মাধ্যমে টাকা নেবেন বাছুন।');
    $acct = digits_only(str_in('account', 20));
    if (!valid_phone($acct)) fail('সঠিক ১১ ডিজিটের নম্বর দিন।');
    db()->beginTransaction();
    q('SELECT id FROM users WHERE id = ? FOR UPDATE', [$u['id']]);   // one request at a time per customer
    $me = referral_me_out($u);
    if (!$me['can_withdraw']) { db()->rollBack(); fail($me['withdraw_note'] ?: 'এখন টাকা তোলা যাবে না।', 409); }
    $amount = (int)($_POST['amount'] ?? 0) ?: $me['summary']['balance'];
    if ($amount < max(1, $me['settings']['payout_min']) || $amount > $me['summary']['balance']) { db()->rollBack(); fail('টাকার পরিমাণ ' . taka(max(1, $me['settings']['payout_min'])) . ' থেকে ' . taka($me['summary']['balance']) . '-এর মধ্যে দিন।'); }
    q('INSERT INTO payouts (user_id, amount, method, account) VALUES (?,?,?,?)', [$u['id'], $amount, $method, $acct]);
    db()->commit();
    if (notify_on('referral')) {
        $html = mail_layout('রেফারেলের টাকা তোলার অনুরোধ', mail_kv(['কাস্টমার' => $u['name'] . ' · ' . $u['email'], 'টাকা' => taka($amount), 'মাধ্যম' => "$method · $acct"]) . '<p>পাঠানোর পর অ্যাডমিন প্যানেল → রেফারেল থেকে "পরিশোধ করেছি" চাপুন।</p>', 'অ্যাডমিন প্যানেল', site_url('admin.html#referral'));
        foreach (admin_emails() as $to) mail_queue($to, 'রেফারেল পেমেন্টের অনুরোধ: ' . taka($amount), $html, 'payout');
    }
    out(referral_me_out($u));
}

/* ---------- referrals: admin ---------- */

function referral_admin_out(): array {
    $t = q("SELECT COUNT(*) n, COALESCE(SUM(status = 'earned'), 0) earned_n, COALESCE(SUM(status = 'pending'), 0) pending_n, COALESCE(SUM(CASE WHEN status = 'earned' THEN reward ELSE 0 END), 0) earned,
              COALESCE(SUM(CASE WHEN status IN ('pending','earned') THEN discount ELSE 0 END), 0) discounts FROM referrals")->fetch();
    $p = q("SELECT COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) paid, COALESCE(SUM(CASE WHEN status = 'requested' THEN amount ELSE 0 END), 0) requested,
              COALESCE(SUM(status = 'requested'), 0) requests FROM payouts")->fetch();
    $rev = (int)q("SELECT COALESCE(SUM(o.total), 0) FROM referrals r JOIN orders o ON o.id = r.order_id WHERE r.status = 'earned'")->fetchColumn();
    return [
        'settings' => referral_settings(),
        'totals' => array_map('intval', $t + $p) + ['revenue' => $rev],
        'top' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'code' => $r['ref_code'], 'n' => (int)$r['n'], 'earned' => (int)$r['earned']],
            q("SELECT u.id, u.name, u.email, u.ref_code, COUNT(*) n, SUM(CASE WHEN r.status = 'earned' THEN r.reward ELSE 0 END) earned FROM referrals r JOIN users u ON u.id = r.referrer_id GROUP BY u.id ORDER BY earned DESC, n DESC LIMIT 10")->fetchAll()),
        'referrals' => array_map(fn($r) => ['id' => (int)$r['id'], 'order_id' => (int)$r['order_id'], 'order' => $r['ocode'], 'total' => (int)$r['total'], 'code' => $r['code'], 'status' => $r['status'], 'reward' => (int)$r['reward'], 'discount' => (int)$r['discount'],
                'referrer' => $r['rname'], 'referee' => $r['ename'], 'created' => strtotime($r['created_at'] . ' UTC'), 'note' => $r['note']],
            q('SELECT r.*, o.code ocode, o.total, a.name rname, b.name ename FROM referrals r JOIN orders o ON o.id = r.order_id JOIN users a ON a.id = r.referrer_id JOIN users b ON b.id = r.referee_id ORDER BY r.id DESC LIMIT 200')->fetchAll()),
        'payouts' => array_map(fn($p) => ['id' => (int)$p['id'], 'user' => $p['name'], 'email' => $p['email'], 'amount' => (int)$p['amount'], 'method' => $p['method'], 'account' => $p['account'], 'status' => $p['status'], 'trx' => $p['trx'], 'note' => $p['note'],
                'created' => strtotime($p['created_at'] . ' UTC'), 'processed' => $p['processed_at'] ? strtotime($p['processed_at'] . ' UTC') : null, 'balance' => referral_summary((int)$p['user_id'])['balance'] + ($p['status'] === 'requested' ? (int)$p['amount'] : 0)],
            q("SELECT p.*, u.name, u.email FROM payouts p JOIN users u ON u.id = p.user_id ORDER BY p.status = 'requested' DESC, p.id DESC LIMIT 200")->fetchAll()),
    ];
}
function referral_admin(): void { require_admin(); out(referral_admin_out()); }

function referral_save(): void {
    require_admin();
    $n = function (string $k, int $max) { $v = $_POST[$k] ?? ''; if (!is_numeric($v) || (int)$v < 0 || (int)$v > $max) fail('টাকার পরিমাণ সঠিক নয়।'); return (int)$v; };
    $s = ['enabled' => !empty($_POST['enabled']), 'reward' => $n('reward', 1000000), 'discount' => $n('discount', 1000000), 'min_order' => $n('min_order', 10000000),
          'trigger' => ($_POST['trigger'] ?? '') === 'paid' ? 'paid' : 'delivered', 'payout_min' => $n('payout_min', 1000000), 'first_order_only' => !empty($_POST['first_order_only'])];
    if ($s['enabled'] && !$s['reward'] && !$s['discount']) fail('আয় বা ছাড়, অন্তত একটা শূন্যের বেশি দিন।');
    settings_store('referral', $s);
    referral_settings(true);
    out(referral_admin_out());
}

// Admin overrides one referral: reject it (no reward), or restore it to follow its order again.
function referral_op(): void {
    require_admin();
    $r = q('SELECT * FROM referrals WHERE id = ?', [(int)($_POST['id'] ?? 0)])->fetch() ?: fail('রেফারেল পাওয়া যায়নি।', 404);
    $op = str_in('op', 10);
    if ($op === 'reject') q("UPDATE referrals SET status = 'rejected', note = ? WHERE id = ?", [str_in('note', 255) ?: null, $r['id']]);
    elseif ($op === 'restore') { q("UPDATE referrals SET status = 'pending', note = NULL WHERE id = ?", [$r['id']]); referral_sync((int)$r['order_id']); }
    else fail('Unknown op');
    out(referral_admin_out());
}

function payout_op(): void {
    $admin = require_admin();
    $p = q("SELECT * FROM payouts WHERE id = ? AND status = 'requested'", [(int)($_POST['id'] ?? 0)])->fetch() ?: fail('এই অনুরোধটা আর অপেক্ষায় নেই।', 404);
    $op = str_in('op', 10);
    if (!in_array($op, ['paid', 'rejected'], true)) fail('Unknown op');
    $trx = str_in('trx', 40); $note = str_in('note', 255);
    if ($op === 'paid' && !preg_match('/^[A-Za-z0-9]{6,20}$/', $trx)) fail('যে TrxID দিয়ে টাকা পাঠিয়েছেন সেটা দিন।');
    if ($op === 'rejected' && $note === '') fail('কেন বাতিল করছেন, কাস্টমারকে জানাতে একটা কারণ লিখুন।');
    q('UPDATE payouts SET status = ?, trx = ?, note = ?, processed_at = UTC_TIMESTAMP(), processed_by = ? WHERE id = ?', [$op, $op === 'paid' ? $trx : null, $note ?: null, $admin['id'], $p['id']]);
    if (notify_on('referral')) {
        $t = $op === 'paid' ? 'রেফারেলের ' . taka((int)$p['amount']) . ' পাঠানো হয়েছে' : 'রেফারেলের টাকা তোলার অনুরোধ বাতিল হয়েছে';
        notify_user((int)$p['user_id'], $t, mail_layout($t, $op === 'paid'
            ? mail_kv(['টাকা' => taka((int)$p['amount']), 'মাধ্যম' => $p['method'] . ' · ' . $p['account'], 'TrxID' => $trx]) . '<p>আপনার রেফারেলের জন্য ধন্যবাদ! আরও বন্ধুকে লিংক পাঠিয়ে আয় চালিয়ে যান।</p>'
            : '<p>' . h($note) . '</p><p>টাকাটা আপনার ব্যালেন্সে ফিরে গেছে। প্রশ্ন থাকলে আমাদের জানান।</p>', 'ড্যাশবোর্ডে দেখুন', site_url('dashboard.html#referral')), 'payout', null, true);
    }
    out(referral_admin_out());
}

/* ---------- domain search ---------- */

// Checks one name against every extension this package allows. Public (checkout), rate limited per IP.
function domain_check(): void {
    $cat = catalog();
    $PK = $cat['stacks'][$_GET['stack'] ?? '']['packs'][(int)($_GET['pack'] ?? -1)] ?? null;
    if (!$PK) fail('প্যাকেজ পাওয়া যায়নি।');
    $raw = (string)($_GET['name'] ?? '');
    $label = domain_label($raw);
    if (!valid_label($label)) fail('ইংরেজি অক্ষর, সংখ্যা বা হাইফেন দিয়ে নাম লিখুন (যেমন myshop)।');
    $tlds = allowed_tlds($PK);
    q('DELETE FROM domain_searches WHERE created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    if ((int)q('SELECT COUNT(*) FROM domain_searches WHERE ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR', [client_ip()])->fetchColumn() >= 60) fail('অনেকবার খোঁজা হয়েছে, কিছুক্ষণ পরে চেষ্টা করুন।', 429);
    q('INSERT INTO domain_searches (ip) VALUES (?)', [client_ip()]);
    // A typed extension the package allows goes first.
    $typed = preg_match('/\.([a-z]{2,24})$/', strtolower(trim(explode('/', preg_replace('#^https?://#', '', trim($raw)))[0])), $m) ? $m[1] : '';
    if (in_array($typed, $tlds, true)) $tlds = array_values(array_unique(array_merge([$typed], $tlds)));
    $names = array_map(fn($t) => "$label.$t", $tlds);
    $st = domain_lookup($names);
    $booked = domains_booked($names);
    $premium = $cat['tlds_premium'] ?? TLDS_PREMIUM;
    out(['name' => $label, 'checked_at' => gmdate('c'),
         'results' => array_map(fn($d, $t) => ['domain' => $d, 'tld' => $t, 'premium' => in_array($t, $premium, true)]
             + (in_array($d, $booked, true) ? ['status' => 'taken', 'src' => 'order'] : $st[$d]), $names, $tlds),
         'tlds' => allowed_tlds($PK), 'premium_missing' => array_values(array_diff($premium, allowed_tlds($PK)))]);
}

// Admin check: can this server reach the registries and the DNS services the domain search uses?
function domain_diag(): void {
    require_admin();
    $rows = [['name' => 'PHP cURL', 'ok' => function_exists('curl_multi_init'), 'detail' => function_exists('curl_multi_init') ? 'আছে' : 'নেই — হোস্টিং থেকে PHP cURL চালু করুন']];
    if (!$rows[0]['ok']) out(['rows' => $rows]);
    $doh = cfg('doh_base_url') ? rtrim(cfg('doh_base_url'), '/') . '/resolve?' : 'https://dns.google/resolve?';
    $t = microtime(true);
    $res = http_multi(['iana' => 'https://data.iana.org/rdap/dns.json', 'com' => rdap_base('com') . 'domain/google.com',
        'store' => rdap_base('store') . 'domain/google.store', 'dns' => $doh . 'name=google.com&type=NS',
        'cf' => 'https://cloudflare-dns.com/dns-query?ct=application/dns-json&name=google.com&type=NS'], ['Accept: application/rdap+json, application/dns-json, application/json']);
    $ms = (int)round((microtime(true) - $t) * 1000);
    $show = fn($c) => $c ? "HTTP $c" : 'সংযোগ হয়নি';
    $rows[] = ['name' => 'IANA-র RDAP তালিকা', 'ok' => $res['iana'][0] === 200, 'detail' => $show($res['iana'][0])];
    $rows[] = ['name' => 'রেজিস্ট্রি: .com (Verisign)', 'ok' => $res['com'][0] === 200, 'detail' => $show($res['com'][0])];
    $rows[] = ['name' => 'রেজিস্ট্রি: .store .online .site .website', 'ok' => in_array($res['store'][0], [200, 404], true), 'detail' => $show($res['store'][0])];
    $rows[] = ['name' => 'DNS: Google', 'ok' => $res['dns'][0] === 200, 'detail' => $show($res['dns'][0])];
    $rows[] = ['name' => 'DNS: Cloudflare', 'ok' => $res['cf'][0] === 200, 'detail' => $show($res['cf'][0])];
    out(['rows' => $rows, 'ms' => $ms]);
}

// New domains already picked by another live order (not yet registered, so the registry still says free).
function domains_booked(array $domains): array {
    if (!$domains) return [];
    $marks = implode(',', array_fill(0, count($domains), '?'));
    return q("SELECT DISTINCT domain FROM orders WHERE cancelled = 0 AND domain IN ($marks)", $domains)->fetchAll(PDO::FETCH_COLUMN);
}
