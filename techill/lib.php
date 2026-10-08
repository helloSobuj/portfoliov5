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
        $u = q('SELECT id, name, email, phone, role FROM users WHERE id = ? AND active = 1', [$_SESSION['uid']])->fetch() ?: null;
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
