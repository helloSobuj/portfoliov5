<?php
// The landing page with the SEO tags from the admin panel written into the HTML, so Google,
// Facebook and WhatsApp see the title, description and share image without running JavaScript.
// Falls back to index.html as it is when the site is not installed yet or the database is down.

declare(strict_types=1);

$html = (string)file_get_contents(__DIR__ . '/index.html');
try {
    require __DIR__ . '/lib.php';
    if (cfg('db_pass') !== 'CHANGE_ME') {
        $head = seo_head();
        $html = preg_replace_callback('/<!--seo-->.*?<!--\/seo-->/s', fn() => $head, $html, 1);
    }
} catch (Throwable $e) {
    error_log('techill index: ' . $e->getMessage());
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
