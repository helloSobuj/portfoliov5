<?php
// Techill installer: checks the server, connects the database, creates the first admin and
// writes config.php. When it finishes it deletes itself. If it cannot delete itself it stays
// locked: on an installed site it only offers a database update, and only to an admin.

declare(strict_types=1);
require __DIR__ . '/lib.php';

const MIN_PHP = '8.1.0';
const CONFIG = __DIR__ . '/config.php';

start_session();
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$cur = is_file(CONFIG) ? (require CONFIG) : [];
if (!is_array($cur)) $cur = [];
$installed = ($cur['db_pass'] ?? 'CHANGE_ME') !== 'CHANGE_ME' && installer_db_ok($cur);
$W = &$_SESSION['installer'];
if (!is_array($W)) $W = [];
$step = $_GET['step'] ?? 'check';
$err = ''; $note = '';

function installer_pdo(array $c): PDO {
    $dsn = 'mysql:host=' . $c['db_host'] . (!empty($c['db_port']) ? ';port=' . (int)$c['db_port'] : '') . ';dbname=' . $c['db_name'] . ';charset=utf8mb4';
    return new PDO($dsn, $c['db_user'], $c['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]);
}

// An existing site counts as installed when its database answers and already has an admin.
function installer_db_ok(array $c): bool {
    try { return (bool)installer_pdo($c)->query("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1")->fetch(); }
    catch (Throwable $e) { return false; }
}

function bytes(string $v): int {
    $n = (int)$v; $u = strtolower(substr(trim($v), -1));
    return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
}

// Everything the site needs from the server. 'need' => must pass to install; otherwise a warning.
function requirements(): array {
    $r = [];
    $add = function (string $name, bool $ok, string $detail, bool $need = true, string $fix = '') use (&$r) { $r[] = compact('name', 'ok', 'detail', 'need', 'fix'); };
    $add('PHP ভার্সন ' . MIN_PHP . ' বা নতুন', version_compare(PHP_VERSION, MIN_PHP, '>='), 'আছে ' . PHP_VERSION, true, 'cPanel → MultiPHP Manager / Select PHP Version থেকে PHP 8.1 বা নতুন দিন।');
    foreach (['pdo_mysql' => 'ডেটাবেস', 'mbstring' => 'বাংলা লেখা', 'json' => 'API', 'openssl' => 'নিরাপদ সংযোগ ও ইমেইল', 'curl' => 'PayStation, ডোমেইন চেক, Pixel', 'fileinfo' => 'ফাইল আপলোড যাচাই'] as $ext => $why)
        $add("PHP extension: $ext", extension_loaded($ext), $why, true, 'cPanel → Select PHP Version → Extensions থেকে ' . $ext . ' চালু করুন।');
    $add('PHP extension: gd', extension_loaded('gd'), 'প্রোফাইল ও টেমপ্লেট ছবি ছোট করা', false, 'Select PHP Version → Extensions থেকে gd চালু করুন, না হলে ছবি আপলোড কাজ করবে না।');
    $writable = is_file(CONFIG) ? is_writable(CONFIG) : is_writable(__DIR__);
    $add('config.php লেখা যায়', $writable, $writable ? 'ইনস্টলার নিজেই সেটিংস লিখে দেবে' : 'লেখা যাচ্ছে না', false, 'File Manager-এ config.php-এর permission 644 দিন। না পারলে শেষ ধাপে কোড কপি করে নিজে বসাতে হবে।');
    $add('ফোল্ডারে লেখা যায়', is_writable(__DIR__), 'আপলোড ফোল্ডার তৈরি', true, 'ফোল্ডারের permission 755 দিন।');
    $up = min(bytes((string)ini_get('upload_max_filesize')), bytes((string)ini_get('post_max_size')));
    $add('ফাইল আপলোডের সীমা ১০MB+', $up >= 10 << 20, 'এখন ' . bn_digits(round($up / 1048576)) . 'MB', false, 'Select PHP Version → Options থেকে upload_max_filesize আর post_max_size 16M বা বেশি দিন।');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443 || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $add('HTTPS (SSL)', $https, $https ? 'চালু' : 'এই পেজ http দিয়ে খোলা', false, 'cPanel → SSL/TLS Status → Run AutoSSL, তারপর https:// দিয়ে খুলুন। PayStation আর লগইনের জন্য দরকার।');
    $ht = is_file(__DIR__ . '/.htaccess') && is_file(__DIR__ . '/uploads/.htaccess') && is_file(__DIR__ . '/media/.htaccess');
    $add('.htaccess ফাইলগুলো আছে', $ht, $ht ? 'config ও আপলোড সুরক্ষিত' : 'এক বা একাধিক .htaccess নেই', false, 'File Manager → Settings → Show Hidden Files চালু করে zip থেকে .htaccess ফাইলগুলো আবার আপলোড করুন।');
    $net = false; $netd = 'cURL নেই';
    if (function_exists('curl_init')) {
        $ch = curl_init('https://data.iana.org/rdap/dns.json');
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_RETURNTRANSFER => true]);
        curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $net = $code > 0 && $code < 500; $netd = $net ? 'বাইরের সার্ভারে যাওয়া যায়' : 'বাইরের HTTPS কল আটকানো';
    }
    $add('বাইরের ইন্টারনেট (HTTPS)', $net, $netd, false, 'হোস্টিংকে বলুন সার্ভার থেকে বাইরের HTTPS (port 443) চালু করতে। না হলে PayStation আর লাইভ ডোমেইন চেক কাজ করবে না।');
    return $r;
}

function config_php(array $c): string {
    $v = fn($x) => var_export($x, true);
    $extra = '';
    foreach ($c as $k => $x) if (!in_array($k, ['db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'upload_dir', 'max_upload_mb', 'allowed_ext', 'geo_lookup', 'geo_salt', 'installed', 'installed_at'], true))
        $extra .= "    " . $v($k) . " => " . $v($x) . ",\n";
    return "<?php\n// Written by install.php on " . gmdate('Y-m-d H:i') . " UTC. Keep this file private; do not overwrite it when updating.\nreturn [\n"
        . "    // Database (cPanel > MySQL Databases)\n"
        . "    'db_host' => {$v($c['db_host'])},\n" . (!empty($c['db_port']) ? "    'db_port' => {$v((int)$c['db_port'])},\n" : '')
        . "    'db_name' => {$v($c['db_name'])},\n    'db_user' => {$v($c['db_user'])},\n    'db_pass' => {$v($c['db_pass'])},\n\n"
        . "    // Uploaded files (outside public_html when possible)\n"
        . "    'upload_dir' => {$v($c['upload_dir'])},\n    'max_upload_mb' => {$v((int)($c['max_upload_mb'] ?? 10))},\n"
        . "    'allowed_ext' => {$v($c['allowed_ext'] ?? ['jpg','jpeg','png','webp','gif','svg','pdf','csv','txt','doc','docx','xls','xlsx','zip'])},\n\n"
        . "    // Visitor location: 'ipapi' or 'off'. The salt hashes visitor IPs; never change it.\n"
        . "    'geo_lookup' => {$v($c['geo_lookup'] ?? 'ipapi')},\n    'geo_salt' => {$v($c['geo_salt'])},\n\n"
        . ($extra ? "    // Other settings\n$extra\n" : '')
        . "    'installed' => {$v(!empty($c['installed']))},\n    'installed_at' => {$v($c['installed_at'] ?? null)},\n];\n";
}

function suggest_upload_dir(): string {
    $home = getenv('HOME') ?: (preg_match('#^(/home\d*/[^/]+)/#', __DIR__, $m) ? $m[1] : '');
    return $home && is_dir($home) && is_writable($home) ? rtrim($home, '/') . '/techill_uploads' : __DIR__ . '/uploads';
}

function make_upload_dir(string $d): void {
    if (!is_dir($d) && !@mkdir($d, 0755, true)) throw new RuntimeException("আপলোড ফোল্ডার তৈরি করা যায়নি: $d");
    if (!is_writable($d)) throw new RuntimeException("আপলোড ফোল্ডারে লেখা যাচ্ছে না: $d (permission 755 দিন)");
    if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Require all denied\n");
    if (!is_file("$d/index.html")) @file_put_contents("$d/index.html", '');
}

function check_token(): void { if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('পেজের মেয়াদ শেষ, রিফ্রেশ করে আবার চেষ্টা করুন।'); }

/* ---------------- actions ---------------- */
try {
    if ($installed) {
        // Locked: an existing site may only update its tables, after an admin signs in here.
        $step = 'update';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token();
            $u = q("SELECT id, password_hash FROM users WHERE email = ? AND role = 'admin' AND active = 1", [mb_strtolower(trim((string)($_POST['email'] ?? '')))])->fetch();
            if (!$u || !password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) { usleep(600000); throw new RuntimeException('অ্যাডমিনের ইমেইল বা পাসওয়ার্ড ভুল।'); }
            db()->exec((string)file_get_contents(__DIR__ . '/schema.sql'));
            migrate();
            if (empty($cur['installed']) && is_writable(CONFIG)) @file_put_contents(CONFIG, config_php(['installed' => true, 'installed_at' => gmdate('c')] + $cur), LOCK_EX);
            $step = 'updated';
            $W['deleted'] = @unlink(__FILE__);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_token();
        if ($step === 'db') {
            $c = ['db_host' => trim((string)($_POST['db_host'] ?? 'localhost')) ?: 'localhost', 'db_port' => (int)($_POST['db_port'] ?? 0) ?: null,
                  'db_name' => trim((string)($_POST['db_name'] ?? '')), 'db_user' => trim((string)($_POST['db_user'] ?? '')), 'db_pass' => (string)($_POST['db_pass'] ?? '')];
            $W['db'] = $c;
            if ($c['db_name'] === '' || $c['db_user'] === '') throw new RuntimeException('ডেটাবেসের নাম আর ইউজার দিন।');
            try { $pdo = installer_pdo($c); }
            catch (PDOException $e) {
                $m = $e->getMessage();
                throw new RuntimeException(preg_match("/Access denied for user .* to database/i", $m) ? 'এই ডেটাবেসে ঢোকার অনুমতি নেই: নামটা ভুল (cPanel-এর prefix সহ পুরো নাম দিন), অথবা ইউজারকে এই ডেটাবেসে যোগ করা হয়নি (Add User To Database → ALL PRIVILEGES)।'
                    : (str_contains($m, 'Access denied') ? 'ইউজার বা পাসওয়ার্ড ভুল, অথবা ইউজারকে এই ডেটাবেসে যোগ করা হয়নি (MySQL Databases → Add User To Database → ALL PRIVILEGES)।'
                    : (str_contains($m, 'Unknown database') ? 'এই নামে ডেটাবেস নেই। cPanel-এ নামের আগে যে prefix থাকে (যেমন user_techill), সেটা সহ পুরো নাম দিন।'
                    : 'ডেটাবেসে সংযোগ হয়নি: ' . $m)));
            }
            // The user must be able to create and alter tables, not just read.
            try { $pdo->exec('CREATE TABLE IF NOT EXISTS techill_install_probe (id INT) ENGINE=InnoDB'); $pdo->exec('DROP TABLE techill_install_probe'); }
            catch (PDOException $e) { throw new RuntimeException('ডেটাবেসে টেবিল বানানোর অনুমতি নেই। MySQL Databases থেকে ইউজারকে ALL PRIVILEGES দিন।'); }
            $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            $W['db_ver'] = $ver;
            $W['db_ok'] = true;
            if ($pdo->query("SHOW TABLES LIKE 'users'")->fetch()) $W['db_note'] = 'এই ডেটাবেসে আগে থেকেই Techill-এর টেবিল আছে। পুরনো ডেটা থাকবে, নতুন টেবিল/কলাম যোগ হবে।';
            header('Location: install.php?step=site'); exit;
        }
        if ($step === 'site') {
            if (empty($W['db_ok'])) { header('Location: install.php?step=db'); exit; }
            $s = ['admin_name' => trim((string)($_POST['admin_name'] ?? '')), 'admin_email' => mb_strtolower(trim((string)($_POST['admin_email'] ?? ''))),
                  'upload_dir' => rtrim(trim((string)($_POST['upload_dir'] ?? '')), '/') ?: suggest_upload_dir()];
            $W['site'] = $s;
            $pass = (string)($_POST['admin_pass'] ?? '');
            if ($s['admin_name'] === '' || !filter_var($s['admin_email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('অ্যাডমিনের নাম আর সঠিক ইমেইল দিন।');
            if (mb_strlen($pass) < 10) throw new RuntimeException('অ্যাডমিন পাসওয়ার্ড কমপক্ষে ১০ অক্ষরের দিন।');
            if ($pass !== (string)($_POST['admin_pass2'] ?? '')) throw new RuntimeException('দুইবার লেখা পাসওয়ার্ড মেলেনি।');
            if (array_filter(requirements(), fn($r) => $r['need'] && !$r['ok'])) { header('Location: install.php?step=check'); exit; }

            make_upload_dir($s['upload_dir']);
            $W['salt'] ??= bin2hex(random_bytes(24));   // kept across retries so a hand-pasted config still matches
            $c = $W['db'] + ['upload_dir' => $s['upload_dir'], 'geo_salt' => $W['salt']] + $cur;
            unset($c['installed'], $c['installed_at']);
            $written = (bool)@file_put_contents(CONFIG, config_php($c + ['installed' => false]), LOCK_EX);
            if (function_exists('opcache_invalidate')) @opcache_invalidate(CONFIG, true);
            if (!$written) {
                // config.php is read-only: accept it if the admin already pasted the settings into it.
                $now = (static fn() => require CONFIG)();
                if (!is_array($now) || ($now['db_name'] ?? '') !== $c['db_name'] || ($now['db_user'] ?? '') !== $c['db_user'] || ($now['db_pass'] ?? '') !== $c['db_pass']) {
                    $W['manual_config'] = config_php($c + ['installed' => true, 'installed_at' => gmdate('c')]);
                    throw new RuntimeException('config.php-তে লেখা যাচ্ছে না। নিচের কোডটা কপি করে File Manager-এ config.php খুলে পুরোটা বদলে সেভ করুন, তারপর একই পাসওয়ার্ড দিয়ে আবার "ইনস্টল করুন" চাপুন।');
                }
            }

            db()->exec((string)file_get_contents(__DIR__ . '/schema.sql'));
            migrate();
            q("INSERT INTO users (name, email, password_hash, role, email_verified_at) VALUES (?,?,?,'admin', UTC_TIMESTAMP())
               ON DUPLICATE KEY UPDATE name = VALUES(name), role = 'admin', active = 1, password_hash = VALUES(password_hash)",
              [$s['admin_name'], $s['admin_email'], password_hash($pass, PASSWORD_DEFAULT)]);

            if ($written) @file_put_contents(CONFIG, config_php($c + ['installed' => true, 'installed_at' => gmdate('c')]), LOCK_EX);
            if (function_exists('opcache_invalidate')) @opcache_invalidate(CONFIG, true);
            if (is_file(__DIR__ . '/setup.php')) @unlink(__DIR__ . '/setup.php');   // the older one-step installer
            // Delete the installer, then show the finish page from this same request (the file is gone).
            $W = ['done' => true, 'email' => $s['admin_email'], 'deleted' => @unlink(__FILE__)];
            $step = 'done';
        }
    }
} catch (RuntimeException $e) {
    $err = $e->getMessage();
} catch (PDOException $e) {
    $err = 'ডেটাবেসে সমস্যা: ' . $e->getMessage();
}
if ($step === 'done' && empty($W['done'])) $step = 'check';
if (in_array($step, ['site'], true) && empty($W['db_ok'])) $step = 'db';

$reqs = in_array($step, ['check', 'site'], true) ? requirements() : [];
$blocking = array_filter($reqs, fn($r) => $r['need'] && !$r['ok']);
$steps = ['check' => 'সার্ভার চেক', 'db' => 'ডেটাবেস', 'site' => 'অ্যাডমিন', 'done' => 'সম্পন্ন'];
$idx = array_search($step, array_keys($steps), true);
$base = (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/'));
$db = $W['db'] ?? ['db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_port' => null];
$site = $W['site'] ?? ['admin_name' => '', 'admin_email' => '', 'upload_dir' => suggest_upload_dir()];
$prefix = preg_match('#^/home\d*/([^/]+)/#', __DIR__, $m) ? $m[1] . '_' : '';
$I = fn(string $p) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
$OK = $I('<path d="M5 12.5l4.5 4.5L19 7"/>'); $NO = $I('<path d="M6 6l12 12M18 6L6 18"/>'); $WARN = $I('<path d="M12 8v5M12 16.5v.01"/><path d="M10.3 3.9L2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>');
?><!doctype html>
<html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Techill ইনস্টলার</title>
<link rel="icon" href="assets/favicon-32.png?v=2" sizes="32x32" type="image/png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tiro+Bangla&display=swap">
<style>
:root{--bg:#f4f7fc;--surface:#fff;--ink:#121822;--muted:#5a6578;--line:#e1e7ef;--blue:#0a84f0;--blue-ink:#0759b8;--blue-soft:#e8f3ff;--ok:#12a150;--ok-soft:#e4f6eb;--warn:#b26a00;--warn-soft:#fff3e0;--bad:#d93025;--bad-soft:#fde8e7;font-family:"Tiro Bangla","Noto Serif Bengali",system-ui,sans-serif}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(60% 50% at 10% 0,#dcecff,transparent 70%),radial-gradient(50% 40% at 100% 10%,#ddf5e7,transparent 70%),var(--bg);color:var(--ink);min-height:100vh;padding:32px 16px 64px;line-height:1.6}
.wrap{max-width:760px;margin:auto}.top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:22px}.top img{height:30px}.top span{font-size:.85rem;color:var(--muted)}
.card{background:var(--surface);border:1px solid var(--line);border-radius:20px;box-shadow:0 1px 2px rgb(16 24 40/.04),0 24px 48px -28px rgb(16 24 40/.3);overflow:hidden}
.steps{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid var(--line);background:#fafcff}
.steps div{display:flex;align-items:center;gap:8px;justify-content:center;padding:14px 6px;font-size:.88rem;color:var(--muted);position:relative}
.steps i{width:26px;height:26px;border-radius:50%;display:grid;place-items:center;font-style:normal;font-weight:700;border:2px solid var(--line);background:#fff;font-size:.8rem}
.steps .on{color:var(--blue-ink);font-weight:700}.steps .on i{border-color:var(--blue);background:var(--blue);color:#fff}
.steps .done i{border-color:var(--ok);background:var(--ok-soft);color:var(--ok)}
.steps .on::after{content:"";position:absolute;left:20%;right:20%;bottom:-1px;height:3px;border-radius:3px;background:var(--blue)}
.body{padding:28px}h1{font-size:1.55rem;margin:0 0 6px}.sub{color:var(--muted);margin:0 0 22px}
.req{list-style:none;padding:0;margin:0;display:grid;gap:8px}
.req li{display:grid;grid-template-columns:30px 1fr auto;gap:12px;align-items:start;padding:12px 14px;border:1px solid var(--line);border-radius:14px}
.req .ic{width:30px;height:30px;border-radius:9px;display:grid;place-items:center}.req .ic svg{width:17px;height:17px}
.req .ok .ic{background:var(--ok-soft);color:var(--ok)}.req .bad .ic{background:var(--bad-soft);color:var(--bad)}.req .warn .ic{background:var(--warn-soft);color:var(--warn)}
.req b{display:block;font-size:.98rem}.req small{color:var(--muted);font-size:.85rem}.req .fix{display:block;margin-top:4px;color:var(--ink);font-size:.86rem}
.req .tag{font-size:.75rem;font-weight:700;padding:2px 9px;border-radius:999px;white-space:nowrap}.ok .tag{background:var(--ok-soft);color:var(--ok)}.bad .tag{background:var(--bad-soft);color:var(--bad)}.warn .tag{background:var(--warn-soft);color:var(--warn)}
.sum{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 18px}.sum span{padding:6px 12px;border-radius:999px;font-weight:700;font-size:.86rem}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.full{grid-column:1/-1}
label{display:grid;gap:6px;font-weight:600;font-size:.94rem}label small{font-weight:400;color:var(--muted)}
input{font:inherit;padding:11px 13px;border:1.5px solid var(--line);border-radius:11px;background:#fff;color:var(--ink);width:100%}input:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 4px var(--blue-soft)}
.pw{position:relative}.pw button{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:none;color:var(--blue-ink);font:inherit;font-size:.82rem;font-weight:700;cursor:pointer;padding:6px}
.acts{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:24px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:8px;font:inherit;font-weight:700;border-radius:12px;padding:12px 22px;border:1.5px solid transparent;cursor:pointer;text-decoration:none}
.btn-p{background:var(--blue);color:#fff;box-shadow:0 8px 20px -10px var(--blue)}.btn-p:hover{background:var(--blue-ink)}.btn-p[disabled]{opacity:.5;cursor:not-allowed}
.btn-g{background:#fff;border-color:var(--line);color:var(--ink)}
.msg{padding:12px 14px;border-radius:12px;margin-bottom:18px;font-size:.94rem}.msg.err{background:var(--bad-soft);color:#8f1d16}.msg.info{background:var(--blue-soft);color:var(--blue-ink)}.msg.ok{background:var(--ok-soft);color:#0b6b35}
pre{background:#0f172a;color:#e2e8f0;padding:14px;border-radius:12px;overflow:auto;font-size:.78rem;max-height:280px}
.hint{font-size:.85rem;color:var(--muted);margin-top:4px}
.done{text-align:center;padding:12px 0}.done .big{width:76px;height:76px;margin:0 auto 14px;border-radius:50%;background:var(--ok-soft);color:var(--ok);display:grid;place-items:center;animation:pop .5s cubic-bezier(.3,1.6,.5,1)}.done .big svg{width:38px;height:38px}
@keyframes pop{from{transform:scale(.3);opacity:0}}
.next{display:grid;gap:8px;text-align:left;margin:22px 0 0;padding:0;list-style:none;counter-reset:n}.next li{counter-increment:n;display:grid;grid-template-columns:28px 1fr;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:12px;font-size:.94rem}
.next li::before{content:counter(n, bengali);width:28px;height:28px;border-radius:50%;background:var(--blue-soft);color:var(--blue-ink);display:grid;place-items:center;font-weight:700}
@media (max-width:600px){.grid{grid-template-columns:1fr}.steps span{display:none}.body{padding:20px}.req li{grid-template-columns:30px 1fr}.req .tag{grid-column:2;justify-self:start}}
</style></head><body><div class="wrap">
<div class="top"><img src="assets/logo-light.png?v=2" alt="Techill"><span>ইনস্টলার · PHP <?= $h(PHP_VERSION) ?></span></div>
<div class="card">
<?php if ($step !== 'update' && $step !== 'updated'): ?>
  <div class="steps"><?php $n = 0; foreach ($steps as $k => $l): $n++; $cls = $n - 1 < $idx ? 'done' : ($n - 1 === $idx ? 'on' : ''); ?><div class="<?= $cls ?>"><i><?= $cls === 'done' ? '✓' : ['১','২','৩','৪'][$n - 1] ?></i><span><?= $h($l) ?></span></div><?php endforeach; ?></div>
<?php endif; ?>
  <div class="body">
<?php if ($err): ?><div class="msg err"><?= $h($err) ?></div><?php endif; ?>

<?php if ($step === 'check'): $warns = count(array_filter($reqs, fn($r) => !$r['need'] && !$r['ok'])); ?>
    <h1>সার্ভার চেক</h1>
    <p class="sub">Techill চালাতে যা যা লাগে, সব এই সার্ভারে আছে কিনা দেখে নিচ্ছি।</p>
    <div class="sum"><span style="background:var(--ok-soft);color:var(--ok)"><?= bn_digits(count(array_filter($reqs, fn($r) => $r['ok']))) ?>টা ঠিক আছে</span>
      <?php if ($blocking): ?><span style="background:var(--bad-soft);color:var(--bad)"><?= bn_digits(count($blocking)) ?>টা ঠিক করতে হবে</span><?php endif; ?>
      <?php if ($warns): ?><span style="background:var(--warn-soft);color:var(--warn)"><?= bn_digits($warns) ?>টা সতর্কতা</span><?php endif; ?></div>
    <ul class="req">
<?php foreach ($reqs as $r): $c = $r['ok'] ? 'ok' : ($r['need'] ? 'bad' : 'warn'); ?>
      <li class="<?= $c ?>"><span class="ic"><?= $r['ok'] ? $OK : ($r['need'] ? $NO : $WARN) ?></span><span><b><?= $h($r['name']) ?></b><small><?= $h($r['detail']) ?></small><?php if (!$r['ok'] && $r['fix']): ?><span class="fix"><?= $h($r['fix']) ?></span><?php endif; ?></span><span class="tag"><?= $r['ok'] ? 'ঠিক আছে' : ($r['need'] ? 'দরকার' : 'সতর্কতা') ?></span></li>
<?php endforeach; ?>
    </ul>
    <div class="acts"><a class="btn btn-g" href="install.php?step=check">আবার চেক করুন</a>
      <?php if ($blocking): ?><button class="btn btn-p" disabled>আগে লাল চিহ্নগুলো ঠিক করুন</button><?php else: ?><a class="btn btn-p" href="install.php?step=db">পরের ধাপ: ডেটাবেস →</a><?php endif; ?></div>

<?php elseif ($step === 'db'): ?>
    <h1>ডেটাবেস যুক্ত করুন</h1>
    <p class="sub">cPanel → <b>MySQL® Databases</b> থেকে একটা ডেটাবেস আর ইউজার বানান, ইউজারকে ডেটাবেসে <b>ALL PRIVILEGES</b> দিয়ে যোগ করুন। তারপর তথ্যগুলো এখানে দিন।</p>
    <form method="post" action="install.php?step=db" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
      <div class="grid">
        <label>ডেটাবেসের নাম<input name="db_name" required value="<?= $h($db['db_name']) ?>" placeholder="<?= $h($prefix) ?>techill"><?php if ($prefix): ?><small>cPanel নামের আগে <b><?= $h($prefix) ?></b> বসায়, সেটা সহ লিখুন</small><?php endif; ?></label>
        <label>ডেটাবেস ইউজার<input name="db_user" required value="<?= $h($db['db_user']) ?>" placeholder="<?= $h($prefix) ?>techill"></label>
        <label class="full">ইউজারের পাসওয়ার্ড<span class="pw"><input type="password" name="db_pass" id="dbp"><button type="button" data-show="dbp">দেখুন</button></span></label>
        <label>হোস্ট <small>(সাধারণত localhost)</small><input name="db_host" value="<?= $h($db['db_host']) ?>"></label>
        <label>পোর্ট <small>(ফাঁকা রাখুন)</small><input name="db_port" inputmode="numeric" value="<?= $h($db['db_port'] ?? '') ?>" placeholder="3306"></label>
      </div>
      <div class="acts"><a class="btn btn-g" href="install.php?step=check">← পেছনে</a><button class="btn btn-p">সংযোগ পরীক্ষা করে এগিয়ে যান →</button></div>
    </form>

<?php elseif ($step === 'site'): ?>
    <div class="msg ok">ডেটাবেস সংযোগ সফল<?= !empty($W['db_ver']) ? ' · ' . $h($W['db_ver']) : '' ?></div>
    <?php if (!empty($W['db_note'])): ?><div class="msg info"><?= $h($W['db_note']) ?></div><?php endif; ?>
    <?php if (!empty($W['manual_config'])): ?><pre><?= $h($W['manual_config']) ?></pre><?php endif; ?>
    <h1>অ্যাডমিন অ্যাকাউন্ট</h1>
    <p class="sub">এই অ্যাকাউন্ট দিয়ে অ্যাডমিন প্যানেলে ঢুকে সব অর্ডার, টিম, পেমেন্ট আর সেটিংস চালাবেন।</p>
    <form method="post" action="install.php?step=site" autocomplete="off" id="siteForm">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
      <div class="grid">
        <label>আপনার নাম<input name="admin_name" required value="<?= $h($site['admin_name']) ?>"></label>
        <label>ইমেইল <small>(লগইন হবে এটা দিয়ে)</small><input type="email" name="admin_email" required value="<?= $h($site['admin_email']) ?>"></label>
        <label>পাসওয়ার্ড <small>(১০+ অক্ষর)</small><span class="pw"><input type="password" name="admin_pass" id="ap1" minlength="10" required><button type="button" data-show="ap1">দেখুন</button></span></label>
        <label>আবার পাসওয়ার্ড<input type="password" name="admin_pass2" id="ap2" minlength="10" required></label>
        <label class="full">আপলোড ফোল্ডার <small>(কাস্টমারের NID, লাইসেন্স, ফাইল থাকবে। public_html-এর বাইরে থাকলে সবচেয়ে নিরাপদ)</small><input name="upload_dir" value="<?= $h($site['upload_dir']) ?>"><span class="hint">ফোল্ডার না থাকলে ইনস্টলার নিজেই বানিয়ে নেবে।</span></label>
      </div>
      <div class="acts"><a class="btn btn-g" href="install.php?step=db">← পেছনে</a><button class="btn btn-p" id="goBtn">ইনস্টল করুন</button></div>
    </form>

<?php elseif ($step === 'done'): ?>
    <div class="done"><div class="big"><?= $OK ?></div>
      <h1>Techill ইনস্টল হয়ে গেছে!</h1>
      <p class="sub">ডেটাবেস, অ্যাডমিন অ্যাকাউন্ট আর config.php সব তৈরি।</p>
      <?php if (!empty($W['deleted'])): ?><div class="msg ok">নিরাপত্তার জন্য install.php নিজে থেকে মুছে ফেলা হয়েছে।</div>
      <?php else: ?><div class="msg err">install.php নিজে মুছতে পারেনি। এটা এখন লক করা আছে, তবু File Manager থেকে <b>install.php</b> ডিলিট করে দিন।</div><?php endif; ?>
      <ul class="next">
        <li><span><a href="admin.html"><b>অ্যাডমিন প্যানেলে</b></a> লগইন করুন (<?= $h($W['email'] ?? '') ?>)।</span></li>
        <li><span>প্যাকেজ ও দাম → বিকাশ/নগদ/রকেট আর WhatsApp নম্বর বসান, "লাইভ ডোমেইন চেক টেস্ট" চালান।</span></li>
        <li><span>পেমেন্ট গেটওয়ে → PayStation-এর Merchant ID ও Password দিন (আগে Sandbox-এ টেস্ট)।</span></li>
        <li><span>ইমেইল → cPanel-এ noreply@ ইমেইল বানিয়ে SMTP বসান, টেস্ট ইমেইল পাঠান।</span></li>
        <li><span>টিম → ডেভেলপারদের অ্যাকাউন্ট যোগ করুন।</span></li>
      </ul>
      <div class="acts" style="justify-content:center"><a class="btn btn-p" href="admin.html">অ্যাডমিন প্যানেলে যান →</a><a class="btn btn-g" href="./">ওয়েবসাইট দেখুন</a></div>
    </div>

<?php elseif ($step === 'update'): ?>
    <h1>Techill আগেই ইনস্টল করা আছে</h1>
    <p class="sub">ইনস্টলার লক করা। নতুন ভার্সন আপলোড করে থাকলে অ্যাডমিন হিসেবে লগইন করে ডেটাবেস আপডেট করুন। পুরনো সব ডেটা থাকবে।</p>
    <form method="post" action="install.php">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
      <div class="grid"><label>অ্যাডমিন ইমেইল<input type="email" name="email" required></label><label>পাসওয়ার্ড<input type="password" name="password" required></label></div>
      <div class="acts"><a class="btn btn-g" href="./">ওয়েবসাইটে ফিরুন</a><button class="btn btn-p">ডেটাবেস আপডেট করুন</button></div>
    </form>

<?php elseif ($step === 'updated'): ?>
    <div class="done"><div class="big"><?= $OK ?></div><h1>ডেটাবেস আপডেট হয়েছে</h1>
      <?php if (!empty($W['deleted'])): ?><div class="msg ok">install.php নিজে থেকে মুছে ফেলা হয়েছে।</div><?php else: ?><div class="msg err">install.php মুছতে পারেনি, File Manager থেকে ডিলিট করে দিন।</div><?php endif; ?>
      <div class="acts" style="justify-content:center"><a class="btn btn-p" href="admin.html">অ্যাডমিন প্যানেলে যান →</a></div></div>
<?php endif; ?>
  </div>
</div>
<p class="hint" style="text-align:center;margin-top:16px">Techill · by RedBolt IT</p>
</div>
<script>
document.querySelectorAll("[data-show]").forEach(b => b.addEventListener("click", () => { const i = document.getElementById(b.dataset.show); const t = i.type === "password"; i.type = t ? "text" : "password"; b.textContent = t ? "লুকান" : "দেখুন"; }));
const f = document.getElementById("siteForm");
if (f) f.addEventListener("submit", e => {
  if (f.admin_pass.value !== f.admin_pass2.value) { e.preventDefault(); alert("দুইবার লেখা পাসওয়ার্ড মেলেনি।"); return; }
  const b = document.getElementById("goBtn"); b.disabled = true; b.textContent = "ইনস্টল হচ্ছে...";
});
</script>
</body></html>
