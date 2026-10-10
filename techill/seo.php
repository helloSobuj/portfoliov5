<?php
// robots.txt, sitemap.xml and the IndexNow key file, built from the SEO settings in the admin panel.
// .htaccess maps /robots.txt and /sitemap.xml here; seo.php?f=robots works without it too.

declare(strict_types=1);
require __DIR__ . '/lib.php';

$f = $_GET['f'] ?? '';
try {
    switch ($f) {
        case 'robots':
            header('Content-Type: text/plain; charset=utf-8');
            echo seo_robots();
            break;
        case 'sitemap':
            header('Content-Type: application/xml; charset=utf-8');
            echo seo_sitemap();
            break;
        case 'indexnow':
            $key = seo_settings()['indexnow_key'];
            if ($key === '') { http_response_code(404); exit; }
            header('Content-Type: text/plain; charset=utf-8');
            echo $key;
            break;
        default:
            http_response_code(404);
    }
} catch (Throwable $e) {
    // Not installed yet or the database is down: still answer robots.txt so crawlers do not stall.
    error_log('techill seo: ' . $e->getMessage());
    if ($f === 'robots') { header('Content-Type: text/plain; charset=utf-8'); echo "User-agent: *\nDisallow:\n"; }
    else http_response_code(503);
}
