<?php
// Router for the PHP built-in server (see klms-entrypoint.sh).
//
// Why this exists in the docker setup and not in a native deploy:
// In a native deploy (Apache + mod_php, or nginx + php-fpm) the WEB SERVER
// serves static files (CSS/JS/images) natively with correct MIME types, and only
// routes dynamic requests to the front controller. The PHP built-in server
// (php -S), which we use here because the php:8.4-cli base image ships no
// php-fpm, does NOT do that split on its own: with a router script it sends
// EVERY request through the front controller, so /build/app.css would come back
// as text/html (the browser then refuses to apply it as CSS, and SRI sha384
// hashes no longer match). Without a router script it serves static files
// natively but 404s on every dynamic route.
//
// This router gives us both: existing static files are served natively by
// php -S (correct MIME types, real bytes — required for CSS to apply and SRI
// integrity to match); everything else is routed through the Symfony front
// controller (index.php).

$base = '/app/public';
$uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));

if ($uri !== '/' && $uri !== '' && $uri[0] === '/') {
    $file = realpath($base . $uri);
    if ($file !== false && str_starts_with($file, $base . '/') && is_file($file)) {
        return false;
    }
}

require $base . '/index.php';
