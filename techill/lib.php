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

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    static $u = false;
    if ($u === false) {
        $u = q('SELECT id, name, email, phone, role, avatar FROM users WHERE id = ? AND active = 1', [$_SESSION['uid']])->fetch() ?: null;
        if ($u) $u['id'] = (int)$u['id'];
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
        if ($won) fb_capi('Purchase', q('SELECT * FROM orders WHERE id = ?', [$pay['order_id']])->fetch(), $fbCtx);
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
