<?php
// Shared helpers: config, DB, session, JSON responses, auth.

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'lib.php') { http_response_code(403); exit; }

date_default_timezone_set('UTC');

function cfg(string $key) {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c[$key] ?? null;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    session_name('techill_sid');
    session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function out(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => $status < 400] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $status = 400): void { out(['error' => $msg], $status); }

function check_csrf(): void {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) fail('সেশন মেয়াদোত্তীর্ণ, পেজ রিফ্রেশ করুন।', 419);
}

function current_user(bool $reload = false): ?array {
    if (empty($_SESSION['uid'])) return null;
    static $u = false;
    if ($u === false || $reload) {
        $u = q('SELECT id, name, email, phone, role, avatar, email_verified_at, email_notify FROM users WHERE id = ? AND active = 1', [$_SESSION['uid']])->fetch() ?: null;
        if ($u) {
            $u['id'] = (int)$u['id'];
            $u['email_verified'] = $u['email_verified_at'] !== null;
            $u['email_notify'] = (bool)$u['email_notify'];
            unset($u['email_verified_at']);
        }
    }
    return $u;
}

function require_user(): array {
    $u = current_user();
    if (!$u) fail('আগে লগইন করুন।', 401);
    return $u;
}

function is_admin(?array $u): bool { return $u && $u['role'] === 'admin'; }
function is_staff(?array $u): bool { return $u && in_array($u['role'], ['developer', 'admin'], true); }

function require_admin(): array {
    $u = require_user();
    if (!is_admin($u)) fail('শুধু অ্যাডমিন এটা করতে পারবেন।', 403);
    return $u;
}

function login_as(int $id): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
}

function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45); }

function str_in(string $key, int $max = 2000): string {
    $v = $_POST[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

function valid_phone(string $v): bool {
    $v = strtr(preg_replace('/[\s-]/', '', $v), ['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']);
    return (bool)preg_match('/^(?:\+?88)?01[3-9]\d{8}$/', $v);
}

// Returns the order row if the user may see it, otherwise stops with 404.
// Admins see every order, developers the ones assigned to them, customers their own.
function order_for(array $u, int $id): array {
    $o = q('SELECT * FROM orders WHERE id = ?', [$id])->fetch();
    $ok = $o && (is_admin($u)
        || ($u['role'] === 'developer' && (int)$o['developer_id'] === $u['id'])
        || ($u['role'] === 'customer' && (int)$o['user_id'] === $u['id']));
    if (!$ok) fail('অর্ডার পাওয়া যায়নি।', 404);
    return $o;
}

/* ---------- catalog & pricing ---------- */

// Packages, prices and add-ons: the admin's saved version, or assets/catalog.json.
function catalog(): array {
    static $c = null;
    if ($c === null) {
        $row = q("SELECT v FROM settings WHERE k = 'catalog'")->fetch();
        $c = ($row ? json_decode($row['v'], true) : null) ?: json_decode((string)file_get_contents(__DIR__ . '/assets/catalog.json'), true);
    }
    return $c;
}

function bn_digits($n): string { return strtr((string)$n, ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']); }

// Server-side copy of the checkout's compute(): the browser only says what was picked.
function price_order(string $stackKey, int $packIdx, int $tplIdx, array $add): array {
    $cat = catalog();
    $S = $cat['stacks'][$stackKey] ?? null;
    $P = $S['packs'][$packIdx] ?? null;
    $T = $S['templates'][$tplIdx] ?? null;
    if (!$S || !$P || !$T) fail('প্যাকেজ বা টেমপ্লেট পাওয়া যায়নি, পেজ রিফ্রেশ করে আবার চেষ্টা করুন।');
    $incl = $P['incl'] ?? [];
    if (!empty($add['capi']) && !in_array('pixel', $incl, true)) $add['pixel'] = true;
    $lines = [['label' => $P['name'] . ' প্যাকেজ', 'amt' => (int)$P['price']]];
    $gw = false;
    foreach ($cat['addons'] as $a) {
        if (!empty($a['only']) && $a['only'] !== $stackKey) continue;
        $v = $add[$a['key']] ?? null;
        if (!$v) continue;
        $price = $a['key'] === 'domain' ? (int)$S['domainPrice'] : (int)$a['price'];
        if (!empty($a['qty'])) {
            $n = max(0, min(20, (int)$v));
            if ($n) $lines[] = ['label' => $a['name'] . ' × ' . bn_digits($n), 'amt' => $price * $n];
        } elseif (!in_array($a['key'], $incl, true)) {
            $lines[] = ['label' => $a['name'], 'amt' => $price];
            if (!empty($a['gw'])) $gw = true;
        }
    }
    return [
        'stack' => $S['name'], 'pack' => $P['name'], 'template' => $T['name'] . ' · ' . $T['code'], 'lines' => $lines,
        'total' => array_sum(array_column($lines, 'amt')),
        'hours' => (int)$P['hours'] + ($gw ? (int)$cat['gateway_extra_hours'] : 0),
        'products' => (int)$P['products'] + 25 * max(0, min(20, (int)($add['products25'] ?? 0))),
    ];
}

// Saves one entry of $_FILES and returns the files.id, or null when nothing was sent.
function save_upload(string $field, int $orderId, int $userId, string $kind): ?int {
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) fail('ফাইল আপলোড হয়নি, আবার চেষ্টা করুন।');
    if ($f['size'] > cfg('max_upload_mb') * 1024 * 1024) fail('ফাইল ' . cfg('max_upload_mb') . 'MB-এর বেশি বড়।');
    $name = mb_substr(basename((string)$f['name']), 0, 200);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, cfg('allowed_ext'), true)) fail('এই ধরনের ফাইল নেওয়া হয় না: .' . $ext);
    $dir = rtrim(cfg('upload_dir'), '/');
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) fail('আপলোড ফোল্ডার তৈরি করা যায়নি।', 500);
    $stored = bin2hex(random_bytes(20));
    if (!move_uploaded_file($f['tmp_name'], "$dir/$stored")) fail('ফাইল সেভ করা যায়নি।', 500);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file("$dir/$stored") ?: 'application/octet-stream';
    q('INSERT INTO files (order_id, user_id, kind, original_name, stored_name, mime, size) VALUES (?,?,?,?,?,?,?)',
      [$orderId, $userId, $kind, $name, $stored, $mime, (int)$f['size']]);
    return (int)db()->lastInsertId();
}

/* ---------- visitor details ---------- */

// Device, browser and OS from the user agent. Facebook / Instagram in-app browsers are kept
// separate because most Bangladeshi ad traffic opens inside them.
function ua_info(string $ua): array {
    $device = preg_match('/ipad|tablet/i', $ua) ? 'tablet' : (preg_match('/mobi|android|iphone/i', $ua) ? 'mobile' : 'desktop');
    $browser = 'Other';
    foreach ([
        'Facebook' => '/FBAN|FBAV|FB_IAB|FBIOS/', 'Instagram' => '/Instagram/', 'Samsung' => '/SamsungBrowser/',
        'Opera' => '/OPR\/|Opera/', 'Edge' => '/Edg(e|A|iOS)?\//', 'UC' => '/UCBrowser/', 'Firefox' => '/Firefox|FxiOS/',
        'Chrome' => '/Chrome|CriOS/', 'Safari' => '/Safari/',
    ] as $name => $re) if (preg_match($re, $ua)) { $browser = $name; break; }
    $os = preg_match('/android/i', $ua) ? 'Android' : (preg_match('/iphone|ipad|ipod/i', $ua) ? 'iOS'
        : (preg_match('/windows/i', $ua) ? 'Windows' : (preg_match('/mac os x|macintosh/i', $ua) ? 'macOS' : (preg_match('/linux|cros/i', $ua) ? 'Linux' : 'Other'))));
    return ['device' => $device, 'browser' => $browser, 'os' => $os];
}

// Country / region / city for the visitor's IP. Uses Cloudflare's headers when present, otherwise
// an optional lookup service, cached per salted IP hash for 30 days. Returns nulls when unknown.
function geo_for_ip(string $ip): array {
    $none = ['country' => null, 'country_name' => null, 'region' => null, 'city' => null];
    $cf = strtoupper((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    if (preg_match('/^[A-Z]{2}$/', $cf) && $cf !== 'XX' && $cf !== 'T1') {
        $none['country'] = $cf;
        $none['city'] = mb_substr((string)($_SERVER['HTTP_CF_IPCITY'] ?? ''), 0, 80) ?: null;
        $none['region'] = mb_substr((string)($_SERVER['HTTP_CF_REGION'] ?? ''), 0, 80) ?: null;
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $none;
    $hash = sha1((cfg('geo_salt') ?: (string)cfg('db_pass') . (string)cfg('db_name')) . '|' . $ip);
    $row = q('SELECT * FROM geo_cache WHERE ip_hash = ? AND created_at > UTC_TIMESTAMP() - INTERVAL IF(country IS NULL, 1, 30) DAY', [$hash])->fetch();
    if ($row) return ['country' => $row['country'] ?? $none['country'], 'country_name' => $row['country_name'], 'region' => $row['region'] ?? $none['region'], 'city' => $row['city'] ?? $none['city']];
    $geo = $none;
    if ((cfg('geo_lookup') ?? 'ipapi') === 'ipapi' && function_exists('curl_init')) {
        $ch = curl_init('https://ipapi.co/' . rawurlencode($ip) . '/json/');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_USERAGENT => 'Techill/1.0']);
        $j = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        if (is_array($j) && empty($j['error']) && preg_match('/^[A-Z]{2}$/', (string)($j['country_code'] ?? ''))) {
            $geo = ['country' => $j['country_code'], 'country_name' => mb_substr((string)($j['country_name'] ?? ''), 0, 60) ?: null,
                    'region' => mb_substr((string)($j['region'] ?? ''), 0, 80) ?: null, 'city' => mb_substr((string)($j['city'] ?? ''), 0, 80) ?: null];
        }
    }
    q('REPLACE INTO geo_cache (ip_hash, country, country_name, region, city) VALUES (?,?,?,?,?)', [$hash, $geo['country'], $geo['country_name'], $geo['region'], $geo['city']]);
    return $geo;
}

/* ---------- payments (PayStation) ---------- */

const PAYSTATION_LIVE = 'https://api.paystation.com.bd';
const PAYSTATION_SANDBOX = 'https://sandbox.paystation.com.bd';

// Admin-editable payment settings. The PayStation password is stored here and never sent to a browser.
function payment_settings(bool $reload = false): array {
    static $s = null;
    if ($s === null || $reload) {
        $row = q("SELECT v FROM settings WHERE k = 'payments'")->fetch();
        $saved = $row ? (json_decode($row['v'], true) ?: []) : [];
        $s = array_replace_recursive([
            'manual_enabled' => true,
            'paystation' => ['enabled' => false, 'sandbox' => true, 'merchant_id' => '', 'password' => '', 'pay_with_charge' => 0],
        ], $saved);
    }
    return $s;
}

function paystation_ready(): bool {
    $p = payment_settings()['paystation'];
    return !empty($p['enabled']) && $p['merchant_id'] !== '' && $p['password'] !== '';
}

// Absolute URL of a file next to api.php, e.g. site_url('dashboard.html').
function site_url(string $file): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/' . $file;
}

// POST to PayStation. Form-encoded unless $json; returns ['ok', 'http', 'error', 'json'].
function paystation_post(string $path, array $body, array $headers = [], bool $json = false): array {
    $p = payment_settings()['paystation'];
    if (!function_exists('curl_init')) return ['ok' => false, 'http' => 0, 'error' => 'PHP cURL extension নেই।', 'json' => []];
    $headers[] = 'Accept: application/json';
    $headers[] = $json ? 'Content-Type: application/json' : 'Content-Type: application/x-www-form-urlencoded';
    // 'paystation_base_url' in config.php is only for testing against a local mock; leave it out in production.
    $base = cfg('paystation_base_url') ?: ($p['sandbox'] ? PAYSTATION_SANDBOX : PAYSTATION_LIVE);
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json ? json_encode($body) : http_build_query($body),
        CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 40,
        CURLOPT_USERAGENT => 'Techill/1.0',
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string)$raw, true);
    if ($raw === false || $err !== '') return ['ok' => false, 'http' => $http, 'error' => 'PayStation-এ সংযোগ হয়নি: ' . $err, 'json' => []];
    if (!is_array($j)) return ['ok' => false, 'http' => $http, 'error' => 'PayStation থেকে বোঝা যায় এমন উত্তর আসেনি (HTTP ' . $http . ')।', 'json' => []];
    return ['ok' => true, 'http' => $http, 'error' => '', 'json' => $j];
}

function ps_accepted(array $r): bool {
    return $r['ok'] && (string)($r['json']['status_code'] ?? '') === '200' && strtolower((string)($r['json']['status'] ?? '')) === 'success';
}

function ps_status(string $s): string {
    $s = strtolower(trim($s));
    if (in_array($s, ['success', 'successful', 'completed', 'paid'], true)) return 'success';
    if (in_array($s, ['canceled', 'cancelled', 'cancel'], true)) return 'canceled';
    if (in_array($s, ['processing', 'pending', 'initiated'], true)) return 'processing';
    return 'failed';
}

function to_local_phone(string $v): string {
    $d = preg_replace('/\D/', '', strtr($v, ['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']));
    if (str_starts_with($d, '880')) $d = substr($d, 2);
    return $d;
}

// The initiate-payment fields for an order attempt (credentials included).
function paystation_fields(array $o, array $info, array $cust, string $invoice): array {
    $p = payment_settings()['paystation'];
    $phone = to_local_phone((string)($info['phone'] ?? '') ?: (string)($info['whatsapp'] ?? '') ?: (string)($cust['phone'] ?? ''));
    return [
        'merchantId' => $p['merchant_id'], 'password' => $p['password'],
        'invoice_number' => $invoice, 'currency' => 'BDT', 'payment_amount' => (string)(int)$o['total'],
        'pay_with_charge' => (string)(int)$p['pay_with_charge'], 'reference' => $o['code'],
        'cust_name' => mb_substr((string)($info['admin_name'] ?? '') ?: (string)($cust['name'] ?? 'Customer'), 0, 100),
        'cust_phone' => $phone, 'cust_email' => (string)($cust['email'] ?? ''),
        'cust_address' => mb_substr((string)($info['address'] ?? '') ?: 'Bangladesh', 0, 200),
        'callback_url' => site_url('api.php?action=paystation_callback'),
        'checkout_items' => mb_substr($o['pack'] . ' প্যাকেজ (' . $o['stack'] . ')', 0, 200), 'opt_a' => (string)$o['id'],
    ];
}

// Asks PayStation (server to server) what happened to a payment and applies it to the order.
// Never trusts the browser: amount, invoice and status all come from PayStation's answer.
// Returns the payment's resulting status.
function paystation_verify(array $pay, string $trxHint = '', array $fbCtx = []): string {
    if ($pay['status'] === 'success') return 'success';
    $p = payment_settings()['paystation'];
    if ($p['merchant_id'] === '') return $pay['status'];
    $r = paystation_post('/transaction-status', ['invoice_number' => $pay['invoice_number']], ['merchantId: ' . $p['merchant_id']]);
    if ((!ps_accepted($r) || empty($r['json']['data'])) && $trxHint !== '') {
        $r = paystation_post('/v2/transaction-status', ['trxId' => $trxHint], ['merchantId: ' . $p['merchant_id']], true);
    }
    if (!ps_accepted($r) || !is_array($r['json']['data'] ?? null)) {
        q('UPDATE payments SET note = ? WHERE id = ?', [mb_substr($r['ok'] ? 'যাচাই: ' . ($r['json']['message'] ?? 'তথ্য পাওয়া যায়নি') : $r['error'], 0, 255), $pay['id']]);
        return $pay['status'];
    }
    $d = $r['json']['data'];
    $status = ps_status((string)($d['trx_status'] ?? ''));
    $trx = mb_substr((string)($d['trx_id'] ?? ''), 0, 60) ?: null;
    $method = mb_substr((string)($d['payment_method'] ?? ''), 0, 40) ?: null;
    $payer = mb_substr((string)($d['payer_mobile_no'] ?? ''), 0, 30) ?: null;
    $amount = null;
    foreach (['payment_amount', 'request_amount', 'trx_amount'] as $k) if (isset($d[$k]) && $d[$k] !== '') { $amount = (float)$d[$k]; break; }

    if ($status === 'success') {
        $note = null;
        if (!empty($d['invoice_number']) && (string)$d['invoice_number'] !== $pay['invoice_number']) $note = 'ইনভয়েস নম্বর মেলেনি: ' . $d['invoice_number'];
        elseif ($amount === null || $amount + 0.01 < (float)$pay['amount']) $note = 'টাকার পরিমাণ মেলেনি: চাওয়া হয়েছিল ' . $pay['amount'] . ', এসেছে ' . ($amount ?? 'অজানা');
        if ($note) { q("UPDATE payments SET status = 'mismatch', trx_id = ?, method = ?, payer = ?, note = ? WHERE id = ?", [$trx, $method, $payer, $note, $pay['id']]); return 'mismatch'; }
        db()->beginTransaction();
        $won = q("UPDATE payments SET status = 'success', trx_id = ?, method = ?, payer = ?, note = NULL WHERE id = ? AND status <> 'success'", [$trx, $method, $payer, $pay['id']])->rowCount() === 1;
        if ($won) {
            $o = q('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$pay['order_id']])->fetch();
            $restart = (int)$o['stage'] <= 1;   // the countdown starts once the money is in
            q("UPDATE orders SET pay_status = 'verified', pay_trx = ?, pay_sender = ?, stage = GREATEST(stage, 1), progress = GREATEST(progress, 15)"
              . ($restart ? ', deadline_at = UTC_TIMESTAMP() + INTERVAL hours HOUR' : '') . ' WHERE id = ?',
              [mb_substr((string)$trx, 0, 20), mb_substr((string)($payer ?? ''), 0, 20), $o['id']]);
            $txt = 'অনলাইন পেমেন্ট সফল: ৳' . bn_digits(number_format((int)$pay['amount'])) . ($method ? " · $method" : '') . ($trx ? " · TrxID $trx" : '');
            q('INSERT INTO order_events (order_id, stage, progress, note) VALUES (?,?,?,?)', [$o['id'], max(1, (int)$o['stage']), max(15, (int)$o['progress']), $txt]);
            q('INSERT INTO messages (order_id, from_admin, body) VALUES (?,1,?)', [$o['id'], $txt . "। ধন্যবাদ! আমরা কাজ শুরু করছি" . ($restart ? ', ডেলিভারির কাউন্টডাউন এখন থেকে শুরু।' : '।')]);
        }
        db()->commit();
        if ($won) {
            fb_capi('Purchase', q('SELECT * FROM orders WHERE id = ?', [$pay['order_id']])->fetch(), $fbCtx);
            after_order_paid((int)$pay['order_id']);
        }
        return 'success';
    }
    q("UPDATE payments SET status = ?, trx_id = COALESCE(?, trx_id), method = COALESCE(?, method), payer = COALESCE(?, payer) WHERE id = ? AND status <> 'success'",
      [$status, $trx, $method, $payer, $pay['id']]);
    return $status;
}

/* ---------- public images (media/) ---------- */

// Validates an uploaded photo and stores it in media/ under a random name. With GD the image is
// re-encoded (dropping metadata and anything hidden in the file) and scaled down; $square crops to a
// centred square for profile photos. Returns the public path, e.g. "media/3f…a9.webp".
function save_image(string $field, int $max, bool $square): string {
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('ছবি আপলোড হয়নি, আবার চেষ্টা করুন।');
    if ($f['size'] > 5 * 1024 * 1024) fail('ছবি 5MB-এর বেশি বড়।');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext || !@getimagesize($f['tmp_name'])) fail('শুধু JPG, PNG বা WebP ছবি দিন।');
    $dir = __DIR__ . '/media';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) fail('media ফোল্ডার তৈরি করা যায়নি।', 500);
    $name = bin2hex(random_bytes(16));
    if (function_exists('imagecreatefromstring') && ($src = @imagecreatefromstring((string)file_get_contents($f['tmp_name'])))) {
        [$w, $h] = [imagesx($src), imagesy($src)];
        $sx = $sy = 0; $sw = $w; $sh = $h;
        if ($square) { $sw = $sh = min($w, $h); $sx = intdiv($w - $sw, 2); $sy = intdiv($h - $sh, 2); }
        $scale = min(1, $max / max($sw, $sh));
        $dw = max(1, (int)round($sw * $scale)); $dh = max(1, (int)round($sh * $scale));
        $dst = imagecreatetruecolor($dw, $dh);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);
        $ok = function_exists('imagewebp') ? imagewebp($dst, "$dir/$name.webp", 82) : imagejpeg($dst, "$dir/$name.jpg", 85);
        $ext = function_exists('imagewebp') ? 'webp' : 'jpg';
        imagedestroy($src); imagedestroy($dst);
        if (!$ok) fail('ছবি সেভ করা যায়নি।', 500);
    } elseif (!move_uploaded_file($f['tmp_name'], "$dir/$name.$ext")) {
        fail('ছবি সেভ করা যায়নি।', 500);
    }
    return "media/$name.$ext";
}

function is_media_path(?string $p): bool { return (bool)preg_match('#^media/[a-f0-9]{32}\.(jpg|png|webp)$#', (string)$p); }

function delete_media(?string $p): void { if (is_media_path($p) && is_file(__DIR__ . '/' . $p)) @unlink(__DIR__ . '/' . $p); }

/* ---------- Facebook Pixel & Conversions API ---------- */

const FB_GRAPH_VERSION = 'v21.0';   // Graph API version for the Conversions API; bump when Meta retires it

function marketing_settings(bool $reload = false): array {
    static $s = null;
    if ($s === null || $reload) {
        $row = q("SELECT v FROM settings WHERE k = 'marketing'")->fetch();
        $s = array_replace(['pixel_enabled' => false, 'pixel_id' => '', 'capi_token' => '', 'test_code' => '', 'capi_last' => null], $row ? (json_decode($row['v'], true) ?: []) : []);
    }
    return $s;
}

// Server-side copy of a browser Pixel event. The same event_id lets Meta drop the duplicate.
// Never throws: tracking must not break an order or a payment.
function fb_capi(string $event, array $o, array $extra = []): void {
    try {
        $m = marketing_settings();
        if (!$m['pixel_enabled'] || $m['pixel_id'] === '' || $m['capi_token'] === '' || !function_exists('curl_init')) return;
        $info = json_decode($o['info_json'] ?? '{}', true) ?: [];
        $cust = q('SELECT email, phone FROM users WHERE id = ?', [$o['user_id']])->fetch() ?: [];
        $h = fn($v) => hash('sha256', mb_strtolower(trim((string)$v)));
        $phone = preg_replace('/\D/', '', (string)($info['phone'] ?? $cust['phone'] ?? ''));
        if ($phone !== '' && str_starts_with($phone, '01')) $phone = '88' . $phone;
        $user = array_filter([
            'em' => !empty($cust['email']) ? [$h($cust['email'])] : null,
            'ph' => $phone !== '' ? [$h($phone)] : null,
            'external_id' => [$h('techill-' . $o['user_id'])],
            'client_ip_address' => $extra['ip'] ?? null, 'client_user_agent' => $extra['ua'] ?? null,
            'fbp' => $extra['fbp'] ?? null, 'fbc' => $extra['fbc'] ?? null,
        ]);
        $body = ['data' => [[
            'event_name' => $event, 'event_time' => time(), 'event_id' => $o['code'], 'action_source' => 'website',
            'event_source_url' => site_url('index.html'), 'user_data' => $user,
            'custom_data' => ['currency' => 'BDT', 'value' => (int)$o['total'], 'content_name' => $o['pack'] . ' · ' . $o['stack'], 'content_type' => 'product', 'content_ids' => [$o['pack']], 'order_id' => $o['code']],
        ]]];
        if ($m['test_code'] !== '') $body['test_event_code'] = $m['test_code'];
        $r = fb_post($m, $body);
        $m['capi_last'] = ['event' => $event, 'order' => $o['code'], 'ok' => $r['ok'], 'message' => $r['message'], 'at' => time()];
        q("INSERT INTO settings (k, v) VALUES ('marketing', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [json_encode($m, JSON_UNESCAPED_UNICODE)]);
        marketing_settings(true);
    } catch (Throwable $e) {
        error_log('techill capi: ' . $e->getMessage());
    }
}

function fb_post(array $m, array $body): array {
    $base = cfg('fb_graph_base_url') ?: 'https://graph.facebook.com';   // override only for local tests
    $ch = curl_init($base . '/' . FB_GRAPH_VERSION . '/' . rawurlencode($m['pixel_id']) . '/events?access_token=' . rawurlencode($m['capi_token']));
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6]);
    $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    $j = json_decode((string)$raw, true);
    if ($raw === false || $err !== '') return ['ok' => false, 'message' => 'Meta-তে সংযোগ হয়নি: ' . $err];
    if (isset($j['events_received'])) return ['ok' => true, 'message' => 'Meta ' . $j['events_received'] . 'টা ইভেন্ট পেয়েছে'];
    return ['ok' => false, 'message' => (string)($j['error']['message'] ?? 'অজানা উত্তর')];
}

// Browser context for fb_capi(), when the request comes from the customer's own browser.
function fb_context(): array {
    return ['ip' => client_ip(), 'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400),
            'fbp' => preg_match('/^fb\.\d\.\d+\.\d+$/', $_COOKIE['_fbp'] ?? '') ? $_COOKIE['_fbp'] : null,
            'fbc' => preg_match('/^fb\.\d\.\d+\..+$/', $_COOKIE['_fbc'] ?? '') ? mb_substr($_COOKIE['_fbc'], 0, 300) : null];
}

/* ---------- settings stored as JSON in the settings table ---------- */

function settings_load(string $k, array $defaults, bool $reload = false): array {
    static $cache = [];
    if ($reload || !isset($cache[$k])) {
        $row = q('SELECT v FROM settings WHERE k = ?', [$k])->fetch();
        $cache[$k] = array_replace_recursive($defaults, $row ? (json_decode($row['v'], true) ?: []) : []);
    }
    return $cache[$k];
}

function settings_store(string $k, array $v): void {
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function taka(int $n): string { return '৳' . bn_digits(number_format($n)); }

/* ---------- email (SMTP or PHP mail()) ---------- */

const EMAIL_DEFAULTS = [
    'enabled' => false, 'transport' => 'smtp', 'host' => '', 'port' => 465, 'secure' => 'ssl',
    'username' => '', 'password' => '', 'from_email' => '', 'from_name' => 'Techill', 'reply_to' => '',
    'admin_emails' => '', 'otp_required' => false,
    'notify' => ['new_order' => true, 'status' => true, 'message' => true, 'assign' => true, 'referral' => true],
];

// The SMTP password lives here and is never sent to a browser.
function email_settings(bool $reload = false): array { return settings_load('email', EMAIL_DEFAULTS, $reload); }

function email_ready(): bool {
    $e = email_settings();
    return $e['enabled'] && filter_var($e['from_email'], FILTER_VALIDATE_EMAIL) && ($e['transport'] === 'mail' || $e['host'] !== '');
}

// Sends one message right away. Returns [ok, error message].
function mail_send_now(string $to, string $subject, string $html, ?array $e = null): array {
    $e = $e ?? email_settings();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) return [false, 'প্রাপকের ইমেইল সঠিক নয়।'];
    $from = $e['from_email'];
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return [false, 'প্রেরকের (From) ইমেইল সঠিক নয়।'];
    $host = preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?: 'localhost';
    $boundary = 'b' . bin2hex(random_bytes(12));
    $text = trim(html_entity_decode(strip_tags(preg_replace(['#<br\s*/?>#i', '#</(p|div|h\d|tr|li)>#i'], ["\n", "\n"], $html)), ENT_QUOTES, 'UTF-8'));
    $text = preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text));
    $enc = fn(string $s) => mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n");
    // ASCII names go in quotes (a ":" or "," would otherwise break the header); others as an encoded word.
    $name = preg_replace('/[\x00-\x1F"\\\\]/', '', $e['from_name'] ?: 'Techill') ?: 'Techill';
    $fromName = preg_match('/^[\x20-\x7E]*$/', $name) ? '"' . $name . '"' : '=?UTF-8?B?' . base64_encode($name) . '?=';
    $headers = [
        'Date: ' . date('r'),
        'From: ' . $fromName . " <$from>",
        "To: <$to>",
        'Subject: ' . $enc($subject),
        'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (explode('@', $from)[1] ?? $host) . '>',
        'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
    ];
    if (filter_var($e['reply_to'], FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: <' . $e['reply_to'] . '>';
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
          . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
          . "--$boundary--\r\n";
    if ($e['transport'] === 'mail') {
        // PHP mail() takes To and Subject separately.
        $extra = array_values(array_filter($headers, fn($l) => !preg_match('/^(To|Subject):/', $l)));
        $ok = @mail($to, $enc($subject), $body, implode("\r\n", $extra), preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+$/', $from) ? "-f$from" : '');
        return $ok ? [true, ''] : [false, 'PHP mail() পাঠাতে পারেনি। SMTP ব্যবহার করুন।'];
    }
    return smtp_send($e, $from, $to, implode("\r\n", $headers) . "\r\n\r\n" . $body, $host);
}

// Minimal SMTP client: SSL (465) or STARTTLS (587), AUTH LOGIN/PLAIN. No external library needed.
function smtp_send(array $e, string $from, string $to, string $message, string $helo): array {
    $self = (bool)cfg('smtp_allow_self_signed');   // only for a mail server on "localhost" with a certificate for another name
    $ctx = stream_context_create(['ssl' => ['verify_peer' => !$self, 'verify_peer_name' => !$self, 'allow_self_signed' => $self, 'peer_name' => $e['host'], 'SNI_enabled' => true]]);
    $port = (int)$e['port'] ?: ($e['secure'] === 'ssl' ? 465 : 587);
    $fp = @stream_socket_client(($e['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $e['host'] . ':' . $port, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return [false, mb_substr('SMTP সার্ভারে সংযোগ হয়নি: ' . ($errstr ?: 'কারণ জানা যায়নি') . " ($errno)", 0, 250)];
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 2048)) !== false) { $data .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; }
        return $data;
    };
    $cmd = function (?string $c, array $ok, string $hide = '') use ($fp, $read): string {
        if ($c !== null) fwrite($fp, $c . "\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException(trim(($hide ?: (string)$c) . ' → ' . ($r !== '' ? trim($r) : 'উত্তর নেই')));
        return $r;
    };
    try {
        $cmd(null, [220]);
        $ehlo = $cmd("EHLO $helo", [250]);
        if ($e['secure'] === 'tls') {
            $cmd('STARTTLS', [220]);
            $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (!@stream_socket_enable_crypto($fp, true, $method)) throw new RuntimeException('STARTTLS চালু হয়নি (সার্টিফিকেট বা পোর্ট দেখুন)।');
            $ehlo = $cmd("EHLO $helo", [250]);
        }
        if ($e['username'] !== '') {
            if (preg_match('/^250[ -]AUTH\b[^\r\n]*\bLOGIN\b/mi', $ehlo)) {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($e['username']), [334], 'username');
                $cmd(base64_encode($e['password']), [235], 'password');
            } else {
                $cmd('AUTH PLAIN ' . base64_encode("\0" . $e['username'] . "\0" . $e['password']), [235], 'AUTH PLAIN');
            }
        }
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        $cmd(preg_replace('/^\./m', '..', $message) . "\r\n.", [250], 'DATA');
        try { $cmd('QUIT', [221]); } catch (RuntimeException $x) {}
        fclose($fp);
        return [true, ''];
    } catch (RuntimeException $x) {
        @fclose($fp);
        $m = $x->getMessage();
        if (preg_match('/password → 5\d\d|AUTH PLAIN → 5\d\d/', $m)) $m = 'ইউজারনেম বা পাসওয়ার্ড ভুল (' . $m . ')';
        return [false, mb_substr($m, 0, 250)];
    }
}

// Queues a notification. It is sent after the response has gone to the browser (PHP-FPM / LiteSpeed),
// so a slow mail server never slows the page. Every message is logged in email_log.
function mail_queue(string $to, string $subject, string $html, string $kind, ?int $orderId = null): void {
    static $registered = false;
    if (!email_ready() || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    try {
        q('INSERT INTO email_log (to_email, subject, kind, order_id) VALUES (?,?,?,?)', [mb_substr($to, 0, 190), mb_substr($subject, 0, 255), $kind, $orderId]);
        $GLOBALS['techill_mail_queue'][] = ['id' => (int)db()->lastInsertId(), 'to' => $to, 'subject' => $subject, 'html' => $html];
        if (random_int(1, 50) === 1) q('DELETE FROM email_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL 90 DAY');
    } catch (Throwable $x) { error_log('techill mail queue: ' . $x->getMessage()); return; }
    if (!$registered) { $registered = true; register_shutdown_function('mail_flush'); }
}

function mail_flush(): void {
    $queue = $GLOBALS['techill_mail_queue'] ?? [];
    $GLOBALS['techill_mail_queue'] = [];
    if (!$queue) return;
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // don't hold the user's session while sending
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    ignore_user_abort(true);
    @set_time_limit(120);
    foreach ($queue as $m) {
        try {
            [$ok, $err] = mail_send_now($m['to'], $m['subject'], $m['html']);
            q("UPDATE email_log SET status = ?, error = ?, sent_at = IF(? = 'sent', UTC_TIMESTAMP(), NULL) WHERE id = ?", [$ok ? 'sent' : 'failed', $ok ? null : mb_substr($err, 0, 255), $ok ? 'sent' : 'failed', $m['id']]);
        } catch (Throwable $x) { error_log('techill mail: ' . $x->getMessage()); }
    }
}

// The one email layout: brand bar, title, body, optional button. $body is already-escaped HTML.
function mail_layout(string $title, string $body, string $btnText = '', string $btnUrl = ''): string {
    $btn = $btnText !== '' ? '<p style="margin:26px 0 6px"><a href="' . h($btnUrl) . '" style="display:inline-block;background:#0a84f0;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 24px;border-radius:10px">' . h($btnText) . '</a></p>' : '';
    return '<!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;background:#f2f5f9;padding:24px 12px;font-family:\'Tiro Bangla\',\'Noto Serif Bengali\',\'Noto Sans Bengali\',Georgia,serif;color:#121822">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e1e7ef">'
        . '<tr><td style="background:#0a84f0;padding:18px 28px;color:#ffffff;font-size:20px;font-weight:bold;letter-spacing:.3px">Techill</td></tr>'
        . '<tr><td style="padding:28px;font-size:16px;line-height:1.75">'
        . '<h1 style="font-size:21px;line-height:1.35;margin:0 0 14px">' . h($title) . '</h1>' . $body . $btn . '</td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f7f9fc;color:#5a6578;font-size:13px;line-height:1.6">এই ইমেইল Techill থেকে স্বয়ংক্রিয়ভাবে পাঠানো। উত্তর দিতে ড্যাশবোর্ডের চ্যাট ব্যবহার করুন।</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function mail_kv(array $rows): string {
    $out = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:14px 0;font-size:15px;border-collapse:collapse">';
    foreach ($rows as $k => $v) $out .= '<tr><td style="padding:7px 0;color:#5a6578;border-bottom:1px solid #eef3f9">' . h($k) . '</td><td style="padding:7px 0;text-align:right;font-weight:bold;border-bottom:1px solid #eef3f9">' . h($v) . '</td></tr>';
    return $out . '</table>';
}

function notify_on(string $kind): bool { return email_ready() && !empty(email_settings()['notify'][$kind]); }

// Admin addresses for new-order mail: the list in email settings, else every active admin.
function admin_emails(): array {
    $list = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)email_settings()['admin_emails'])), fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL));
    if (!$list) $list = array_column(q("SELECT email FROM users WHERE role = 'admin' AND active = 1")->fetchAll(), 'email');
    return array_values(array_unique($list));
}

// Mail to one account, unless they switched notifications off ($always for receipts and money).
function notify_user(int $uid, string $subject, string $html, string $kind, ?int $orderId = null, bool $always = false): void {
    $u = q('SELECT email, email_notify, active FROM users WHERE id = ?', [$uid])->fetch();
    if (!$u || !$u['active'] || (!$always && !$u['email_notify'])) return;
    mail_queue($u['email'], $subject, $html, $kind, $orderId);
}

/* ---------- one-time codes by email ---------- */

const OTP_PURPOSES = ['register', 'verify', 'reset', 'change'];

function otp_hash(string $email, string $purpose, string $code): string {
    return hash_hmac('sha256', "$email|$purpose|$code", (string)(cfg('geo_salt') ?: cfg('db_pass')) . '|techill-otp');
}

// Creates a 6-digit code, emails it and returns it. Stops with an error on rate limits or a failed send.
function otp_issue(string $email, string $purpose): string {
    if (!email_ready()) fail('ইমেইল সার্ভিস এখন বন্ধ আছে।', 409);
    $last = q('SELECT created_at > UTC_TIMESTAMP() - INTERVAL 60 SECOND recent FROM email_otps WHERE email = ? AND purpose = ? ORDER BY id DESC LIMIT 1', [$email, $purpose])->fetch();
    if ($last && $last['recent']) fail('একটা কোড এইমাত্র পাঠানো হয়েছে, ১ মিনিট পর আবার চাইতে পারবেন।', 429);
    if ((int)q('SELECT COUNT(*) FROM email_otps WHERE email = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR', [$email])->fetchColumn() >= 5
        || (int)q('SELECT COUNT(*) FROM email_otps WHERE ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR', [client_ip()])->fetchColumn() >= 15) fail('অনেকবার কোড চাওয়া হয়েছে, এক ঘণ্টা পরে চেষ্টা করুন।', 429);
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    q('INSERT INTO email_otps (email, purpose, code_hash, ip, expires_at) VALUES (?,?,?,?, UTC_TIMESTAMP() + INTERVAL 10 MINUTE)', [$email, $purpose, otp_hash($email, $purpose, $code), client_ip()]);
    $id = (int)db()->lastInsertId();
    $what = ['register' => 'অ্যাকাউন্ট খোলার', 'verify' => 'ইমেইল যাচাইয়ের', 'reset' => 'পাসওয়ার্ড রিসেটের', 'change' => 'নতুন ইমেইল যাচাইয়ের'][$purpose];
    $subject = "Techill $what কোড: $code";
    $html = mail_layout("আপনার $what কোড", '<p>নিচের কোডটা Techill-এ লিখুন। কোডটা ১০ মিনিট কাজ করবে।</p>'
        . '<p style="font-size:34px;font-weight:bold;letter-spacing:8px;background:#e8f3ff;color:#0067c7;border-radius:12px;padding:14px 0;text-align:center;font-family:Consolas,Menlo,monospace">' . $code . '</p>'
        . '<p style="color:#5a6578;font-size:14px">আপনি কোড না চাইলে এই ইমেইল উপেক্ষা করুন। কাউকে এই কোড জানাবেন না, Techill-এর কেউ কখনো কোড চাইবে না।</p>');
    [$ok, $err] = mail_send_now($email, $subject, $html);
    q("INSERT INTO email_log (to_email, subject, kind, status, error, sent_at) VALUES (?,?, 'otp', ?, ?, IF(?, UTC_TIMESTAMP(), NULL))", [$email, "Techill $what কোড", $ok ? 'sent' : 'failed', $ok ? null : mb_substr($err, 0, 255), $ok ? 1 : 0]);
    if (!$ok) {
        q('DELETE FROM email_otps WHERE id = ?', [$id]);
        error_log('techill otp mail: ' . $err);
        fail('কোড পাঠানো যায়নি। একটু পরে আবার চেষ্টা করুন, না হলে আমাদের জানান।', 502);
    }
    return $code;
}

// Checks a code and uses it up. Stops with an error when it is wrong, used or expired.
function otp_check(string $email, string $purpose, string $code): void {
    $code = preg_replace('/\D/', '', strtr($code, ['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']));
    $row = q('SELECT * FROM email_otps WHERE email = ? AND purpose = ? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1', [$email, $purpose])->fetch();
    if (!$row || strtotime($row['expires_at'] . ' UTC') < time()) fail('কোডের মেয়াদ শেষ বা কোড পাঠানো হয়নি। নতুন কোড নিন।', 422);
    if ((int)$row['attempts'] >= 5) fail('অনেকবার ভুল কোড দেওয়া হয়েছে। নতুন কোড নিন।', 429);
    if (strlen($code) !== 6 || !hash_equals($row['code_hash'], otp_hash($email, $purpose, $code))) {
        q('UPDATE email_otps SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
        fail('কোড মেলেনি, আবার দেখে লিখুন।', 422);
    }
    q('UPDATE email_otps SET consumed_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
    if (random_int(1, 20) === 1) q('DELETE FROM email_otps WHERE created_at < UTC_TIMESTAMP() - INTERVAL 2 DAY');
}

function otp_required(): bool { return email_ready() && !empty(email_settings()['otp_required']); }

/* ---------- referrals ---------- */

const REFERRAL_DEFAULTS = ['enabled' => true, 'reward' => 1000, 'discount' => 1000, 'min_order' => 5000, 'trigger' => 'delivered', 'payout_min' => 1000, 'first_order_only' => true];

function referral_settings(bool $reload = false): array { return settings_load('referral', REFERRAL_DEFAULTS, $reload); }

function valid_ref_code(string $c): bool { return (bool)preg_match('/^[A-Z0-9]{5,12}$/', $c); }

// The customer's own referral code, created the first time it is needed.
function ref_code_for(int $uid): string {
    $c = (string)q('SELECT ref_code FROM users WHERE id = ?', [$uid])->fetchColumn();
    if ($c !== '') return $c;
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($i = 0; $i < 20; $i++) {
        $c = '';
        for ($j = 0; $j < 7; $j++) $c .= $abc[random_int(0, strlen($abc) - 1)];
        try { if (q('UPDATE users SET ref_code = ? WHERE id = ? AND ref_code IS NULL', [$c, $uid])->rowCount()) return $c; }
        catch (PDOException $x) { continue; }   // taken, try another
        return (string)q('SELECT ref_code FROM users WHERE id = ?', [$uid])->fetchColumn();
    }
    fail('রেফারেল কোড তৈরি করা যায়নি, আবার চেষ্টা করুন।', 500);
}

function referrer_by_code(string $code): ?array {
    if (!valid_ref_code($code)) return null;
    return q("SELECT id, name, email, phone FROM users WHERE ref_code = ? AND active = 1 AND role = 'customer'", [$code])->fetch() ?: null;
}

function digits_only(string $v): string {
    $d = preg_replace('/\D/', '', strtr($v, ['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']));
    return str_starts_with($d, '880') ? substr($d, 2) : $d;
}

// Validates a referral code for an order and returns [referrer row, discount]. Stops with
// ref_invalid when the code cannot be used, so the checkout can drop it and show the new total.
function referral_for_order(string $code, string $email, ?int $refereeId, array $phones, int $subtotal): array {
    $s = referral_settings();
    $bad = fn(string $m) => out(['error' => $m, 'ref_invalid' => true], 422);
    if (!$s['enabled']) $bad('রেফারেল অফার এখন বন্ধ আছে। কোড সরিয়ে আবার জমা দিন।');
    $r = referrer_by_code($code) ?? $bad('রেফারেল কোডটা সঠিক নয়।');
    if (($refereeId && (int)$r['id'] === $refereeId) || strcasecmp($r['email'], $email) === 0) $bad('নিজের রেফারেল কোড নিজে ব্যবহার করা যায় না।');
    $rp = digits_only((string)$r['phone']);
    foreach ($phones as $p) if ($rp !== '' && digits_only((string)$p) === $rp) $bad('নিজের রেফারেল কোড নিজে ব্যবহার করা যায় না।');
    if ($s['first_order_only'] && $refereeId && q('SELECT 1 FROM orders WHERE user_id = ? AND cancelled = 0 LIMIT 1', [$refereeId])->fetch()) $bad('রেফারেল ছাড় শুধু প্রথম অর্ডারে পাওয়া যায়।');
    if ($subtotal < (int)$s['min_order']) $bad('রেফারেল ছাড় পেতে অর্ডার কমপক্ষে ' . taka((int)$s['min_order']) . ' হতে হবে।');
    return [$r, min((int)$s['discount'], $subtotal)];
}

// Moves a referral between pending / earned / cancelled to match its order. A referral the admin
// rejected stays rejected. The referrer gets an email the first time it is earned.
function referral_sync(int $orderId): void {
    $r = q('SELECT r.*, o.cancelled, o.pay_status, o.stage, o.code ocode FROM referrals r JOIN orders o ON o.id = r.order_id WHERE r.order_id = ?', [$orderId])->fetch();
    if (!$r || $r['status'] === 'rejected') return;
    $s = referral_settings();
    $paid = $r['pay_status'] === 'verified';
    $want = ($r['cancelled'] || $r['pay_status'] === 'rejected') ? 'cancelled'
          : (($s['trigger'] === 'paid' ? $paid : ($paid && (int)$r['stage'] === 4)) ? 'earned' : 'pending');
    if ($want === $r['status']) return;
    q("UPDATE referrals SET status = ?, earned_at = IF(? = 'earned', COALESCE(earned_at, UTC_TIMESTAMP()), earned_at) WHERE id = ?", [$want, $want, $r['id']]);
    if ($want === 'earned' && !$r['earned_at'] && notify_on('referral')) {
        $sum = referral_summary((int)$r['referrer_id']);
        notify_user((int)$r['referrer_id'], 'অভিনন্দন! রেফারেলে ' . taka((int)$r['reward']) . ' আয় করেছেন',
            mail_layout('আপনার রেফারেলে ' . taka((int)$r['reward']) . ' যোগ হয়েছে', '<p>আপনার রেফারেল কোড দিয়ে করা একটা অর্ডার সফল হয়েছে। টাকাটা আপনার রেফারেল ব্যালেন্সে যোগ হয়েছে।</p>'
                . mail_kv(['এখন তোলা যাবে' => taka($sum['balance']), 'মোট আয়' => taka($sum['earned'])]) . '<p>আরও বন্ধুকে লিংক পাঠান, প্রতিটা সফল অর্ডারে আবার আয় করুন।</p>',
                'ড্যাশবোর্ডে রেফারেল দেখুন', site_url('dashboard.html#referral')), 'referral', $orderId, true);
    }
}

function referral_summary(int $uid): array {
    $r = q("SELECT COUNT(*) n, COALESCE(SUM(status = 'earned'), 0) earned_n, COALESCE(SUM(status = 'pending'), 0) pending_n,
              COALESCE(SUM(CASE WHEN status = 'earned' THEN reward ELSE 0 END), 0) earned, COALESCE(SUM(CASE WHEN status = 'pending' THEN reward ELSE 0 END), 0) pending
            FROM referrals WHERE referrer_id = ?", [$uid])->fetch();
    $p = q("SELECT COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) paid, COALESCE(SUM(CASE WHEN status = 'requested' THEN amount ELSE 0 END), 0) requested
            FROM payouts WHERE user_id = ?", [$uid])->fetch();
    $out = array_map('intval', $r + $p);
    $out['balance'] = $out['earned'] - $out['paid'] - $out['requested'];
    return $out;
}

/* ---------- floating support button ---------- */

const SUPPORT_DEFAULTS = ['enabled' => true, 'messenger' => '', 'whatsapp' => '', 'wa_text' => 'আসসালামু আলাইকুম, Techill-এর প্যাকেজ নিয়ে জানতে চাই।',
    'tawk_property' => '', 'tawk_widget' => '', 'on_dashboard' => false, 'greeting' => 'প্যাকেজ নিয়ে প্রশ্ন? সরাসরি কথা বলুন'];

function support_settings(bool $reload = false): array { return settings_load('support', SUPPORT_DEFAULTS, $reload); }

/* ---------- order emails ---------- */

const STAGE_NAMES = ['তথ্য জমা', 'পেমেন্ট যাচাই', 'সেটআপ চলছে', 'রিভিউ', 'ডেলিভারি'];

function mail_recent(string $kind, int $orderId, string $to, int $minutes): bool {
    return (bool)q("SELECT 1 FROM email_log WHERE kind = ? AND order_id = ? AND to_email = ? AND created_at > UTC_TIMESTAMP() - INTERVAL $minutes MINUTE LIMIT 1", [$kind, $orderId, $to])->fetch();
}

// Customer email for a project update: stage change, payment, delivery link, cancel, or a note from the developer.
function notify_order_update(int $orderId, array $notes, bool $stageChanged): void {
    if (!notify_on('status') || (!$notes && !$stageChanged)) return;
    $o = q('SELECT * FROM orders WHERE id = ?', [$orderId])->fetch();
    if (!$o) return;
    $stage = STAGE_NAMES[(int)$o['stage']] ?? '';
    $subject = $o['cancelled'] ? "অর্ডার {$o['code']} বাতিল করা হয়েছে" : ((int)$o['stage'] === 4 ? "অর্ডার {$o['code']}: ডেলিভারি সম্পন্ন" : ($stageChanged ? "অর্ডার {$o['code']}: এখন \"$stage\" ধাপে" : "অর্ডার {$o['code']}-এ নতুন আপডেট"));
    $body = '<p>আপনার প্রোজেক্টে নতুন আপডেট এসেছে।</p>' . mail_kv(['অর্ডার' => $o['code'], 'বর্তমান ধাপ' => $stage, 'অগ্রগতি' => bn_digits($o['progress']) . '%']);
    foreach ($notes as $n) $body .= '<p style="background:#f7f9fc;border-left:3px solid #0a84f0;padding:10px 14px;border-radius:6px;white-space:pre-wrap">' . h($n) . '</p>';
    if ($o['site_url'] && (int)$o['stage'] === 4) $body .= '<p>আপনার ওয়েবসাইট: <a href="' . h($o['site_url']) . '">' . h($o['site_url']) . '</a></p>';
    notify_user((int)$o['user_id'], $subject, mail_layout($subject, $body, 'ড্যাশবোর্ডে দেখুন', site_url('dashboard.html#o=' . $o['id'])), 'status', (int)$o['id']);
}

// After an online payment succeeds (called from paystation_verify).
function after_order_paid(int $orderId): void {
    referral_sync($orderId);
    notify_order_update($orderId, ['অনলাইন পেমেন্ট সফল হয়েছে। ধন্যবাদ! আমরা কাজ শুরু করছি।'], true);
}

/* ---------- payment gateway documents ---------- */

// True when the order includes a payment gateway (in the package or picked as an add-on). The
// gateway provider needs the trade licence and the owner's NID to open the merchant account.
function needs_gateway_docs(string $stackKey, array $pack, array $add): bool {
    foreach (catalog()['addons'] as $a) {
        if (empty($a['gw']) || (!empty($a['only']) && $a['only'] !== $stackKey)) continue;
        if (in_array($a['key'], $pack['incl'] ?? [], true) || !empty($add[$a['key']])) return true;
    }
    return false;
}

function valid_nid(string $v): bool { return (bool)preg_match('/^(\d{10}|\d{13}|\d{17})$/', digits_only($v)); }

// Rejects anything but an image or a PDF for an identity document, before the order is saved.
function check_doc_upload(string $field, string $label): void {
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) fail("$label-এর ছবি বা PDF দিন।");
    if ($f['error'] !== UPLOAD_ERR_OK) fail("$label আপলোড হয়নি, আবার চেষ্টা করুন।");
    if ($f['size'] > cfg('max_upload_mb') * 1024 * 1024) fail("$label-এর ফাইল " . cfg('max_upload_mb') . 'MB-এর বেশি বড়।');
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true) || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) fail("$label-এর জন্য শুধু JPG, PNG, WebP বা PDF দিন।");
}

/* ---------- domains ---------- */

const TLDS_BASIC = ['shop', 'top', 'store', 'online', 'site', 'website'];
const TLDS_PREMIUM = ['com'];

// The extensions a package may pick: the basic list, plus .com and friends on packages marked premium.
// Packages saved before this setting existed count as premium when they include a domain.
function allowed_tlds(array $pack): array {
    $cat = catalog();
    $basic = $cat['tlds_basic'] ?? TLDS_BASIC;
    $premium = ($pack['premium_tlds'] ?? in_array('domain', $pack['incl'] ?? [], true)) ? ($cat['tlds_premium'] ?? TLDS_PREMIUM) : [];
    return array_values(array_unique(array_merge($basic, $premium)));
}

function pack_has_domain(array $pack, array $add): bool { return in_array('domain', $pack['incl'] ?? [], true) || !empty($add['domain']); }

// "My Shop.com", "www.myshop" or "myshop" → "myshop" (ASCII letters, digits, hyphens; no IDN).
function domain_label(string $v): string {
    $v = strtolower(trim($v));
    $v = preg_replace('#^https?://#', '', $v);
    $v = preg_replace('#^www\.#', '', $v);
    $v = explode('/', $v)[0];
    $v = explode('.', $v)[0];
    $v = preg_replace('/[\s_]+/', '-', $v);
    return trim(preg_replace('/[^a-z0-9-]/', '', $v), '-');
}

function valid_label(string $l): bool { return (bool)preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $l) && !str_contains(substr($l, 2, 2), '--'); }

// "https://www.MyShop.com/page" → "myshop.com"
function clean_host(string $v): string {
    $v = preg_replace('#^(https?://)?(www\.)?#', '', strtolower(trim($v)));
    return rtrim(explode('/', explode('?', $v)[0])[0], '.');
}

function valid_hostname(string $h): bool {
    return strlen($h) <= 253 && (bool)preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $h);
}

// RDAP server for a TLD, from IANA's bootstrap file (refreshed weekly). The short list below is
// only a fallback for when IANA cannot be reached.
function rdap_base(string $tld): ?string {
    if (cfg('rdap_base_url')) return rtrim(cfg('rdap_base_url'), '/') . "/$tld/";   // local tests only
    static $map = null;
    if ($map === null) {
        $row = q("SELECT v, updated_at > UTC_TIMESTAMP() - INTERVAL 7 DAY fresh FROM settings WHERE k = 'rdap'")->fetch();
        $map = $row ? (json_decode($row['v'], true) ?: []) : [];
        if ((!$row || !$row['fresh']) && function_exists('curl_init')) {
            $ch = curl_init('https://data.iana.org/rdap/dns.json');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_USERAGENT => 'Techill/1.0']);
            $j = json_decode((string)curl_exec($ch), true);
            curl_close($ch);
            if (!empty($j['services'])) {
                $map = [];
                foreach ($j['services'] as [$tlds, $urls]) {
                    $url = current(array_filter($urls, fn($u) => str_starts_with($u, 'https://'))) ?: $urls[0];
                    foreach ($tlds as $t) $map[strtolower($t)] = rtrim($url, '/') . '/';
                }
                settings_store('rdap', $map);
            }
        }
    }
    $fallback = ['com' => 'https://rdap.verisign.com/com/v1/', 'net' => 'https://rdap.verisign.com/net/v1/', 'top' => 'https://rdap.nic.top/',
        'shop' => 'https://rdap.gmoregistry.net/rdap/', 'store' => 'https://rdap.centralnic.com/store/', 'online' => 'https://rdap.centralnic.com/online/',
        'site' => 'https://rdap.centralnic.com/site/', 'website' => 'https://rdap.centralnic.com/website/'];
    return $map[$tld] ?? $fallback[$tld] ?? null;
}

// Live availability of several full domain names at once. The registry's RDAP server is asked
// first (404 = available, 200 = registered); a name it cannot answer for is checked in DNS over
// HTTPS (NXDOMAIN = most likely available). Answers are kept 3 minutes; $fresh skips the cache.
// Returns [domain => ['status' => available|taken|unknown, 'src' => rdap|dns|null]].
function domain_lookup(array $domains, bool $fresh = false): array {
    $out = [];
    if (!$domains) return $out;
    if (!$fresh) {
        $marks = implode(',', array_fill(0, count($domains), '?'));
        foreach (q("SELECT domain, status, source FROM domain_cache WHERE domain IN ($marks) AND checked_at > UTC_TIMESTAMP() - INTERVAL 3 MINUTE AND status <> 'unknown'", $domains)->fetchAll() as $r)
            $out[$r['domain']] = ['status' => $r['status'], 'src' => $r['source']];
    }
    $todo = array_values(array_diff($domains, array_keys($out)));
    if ($todo && function_exists('curl_multi_init')) {
        $urls = [];
        foreach ($todo as $d) if ($base = rdap_base(substr($d, strrpos($d, '.') + 1))) $urls[$d] = $base . 'domain/' . rawurlencode($d);
        foreach (http_multi($urls, ['Accept: application/rdap+json, application/json']) as $d => [$code, $body]) {
            // A web server's HTML error page is not a registry answer, so only a non-HTML 404 means free.
            if ($code === 404 && !str_starts_with(ltrim($body), '<')) $out[$d] = ['status' => 'available', 'src' => 'rdap'];
            elseif ($code === 200) $out[$d] = ['status' => 'taken', 'src' => 'rdap'];
        }
        $left = array_values(array_diff($todo, array_keys($out)));
        if ($left) foreach (dns_probe($left) as $d => $st) if ($st !== 'unknown') $out[$d] = ['status' => $st, 'src' => 'dns'];
        foreach ($todo as $d) {
            $out[$d] = $out[$d] ?? ['status' => 'unknown', 'src' => null];
            q('REPLACE INTO domain_cache (domain, status, source, checked_at) VALUES (?,?,?, UTC_TIMESTAMP())', [$d, $out[$d]['status'], $out[$d]['src']]);
        }
    }
    foreach ($domains as $d) $out[$d] = $out[$d] ?? ['status' => 'unknown', 'src' => null];
    return $out;
}

// GETs several URLs in parallel. Returns [key => [http code, body]] (code 0 when unreachable).
function http_multi(array $urls, array $headers = []): array {
    $mh = curl_multi_init(); $hs = []; $res = [];
    foreach ($urls as $k => $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'Techill/1.0']);
        curl_multi_add_handle($mh, $ch); $hs[$k] = $ch;
    }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1); } while ($running && $st === CURLM_OK);
    foreach ($hs as $k => $ch) {
        $res[$k] = [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), (string)curl_multi_getcontent($ch)];
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return $res;
}

// Free DNS-over-HTTPS check (Google, then Cloudflare): a name the registry has never delegated
// answers NXDOMAIN. Not as certain as RDAP (a registered name without nameservers also answers
// NXDOMAIN), so it is only used when the registry itself cannot be reached.
function dns_probe(array $domains): array {
    $out = array_fill_keys($domains, 'unknown');
    $servers = cfg('doh_base_url') ? [rtrim(cfg('doh_base_url'), '/') . '/resolve?'] : ['https://dns.google/resolve?', 'https://cloudflare-dns.com/dns-query?ct=application/dns-json&'];
    foreach ($servers as $base) {
        $left = array_keys(array_filter($out, fn($s) => $s === 'unknown'));
        if (!$left) break;
        $urls = [];
        foreach ($left as $d) $urls[$d] = $base . 'name=' . rawurlencode($d) . '&type=NS';
        foreach (http_multi($urls, ['Accept: application/dns-json']) as $d => [$code, $body]) {
            $j = $code === 200 ? json_decode($body, true) : null;
            if (!is_array($j) || !isset($j['Status'])) continue;
            if ((int)$j['Status'] === 3) $out[$d] = 'available';
            elseif ((int)$j['Status'] === 0) $out[$d] = 'taken';
        }
    }
    return $out;
}
