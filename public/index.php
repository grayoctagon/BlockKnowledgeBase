<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$preConfig = require $root . '/config/app.php';

date_default_timezone_set((string) ($preConfig['timezone'] ?? 'Europe/Vienna'));
session_name((string) ($preConfig['session_name'] ?? 'BKBSESSID'));
$dataDirectory = rtrim((string) ($preConfig['data_dir'] ?? $root . '/data'), DIRECTORY_SEPARATOR);
$sessionDirectory = getenv('BKB_SESSION_DIR') ?: $dataDirectory . '/auth/sessions';
if (
    !is_dir($sessionDirectory)
    && !mkdir($sessionDirectory, 0770, true)
    && !is_dir($sessionDirectory)
) {
    throw new RuntimeException('Das Session-Verzeichnis konnte nicht angelegt werden.');
}

// Shared hosters may configure memcached or Redis as the global session
// handler. BlockKnowledgeBase intentionally stores sessions in its protected
// data directory, so the handler must be switched before setting the path.
ini_set('session.save_handler', 'files');
if (ini_get('session.save_handler') !== 'files') {
    throw new RuntimeException(
        'Dateibasierte PHP-Sessions konnten nicht aktiviert werden. '
        . 'Bitte session.save_handler bzw. session_cache im Hosting-Controlpanel auf "filesystem" setzen.'
    );
}
session_save_path($sessionDirectory);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$container = require $root . '/src/bootstrap.php';
$path = \BKB\Request::path();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cross-Origin-Opener-Policy: same-origin');
header(
    "Content-Security-Policy: default-src 'self'; "
    . "base-uri 'none'; "
    . "connect-src 'self'; "
    . "font-src 'self'; "
    . "form-action 'self'; "
    . "frame-ancestors 'self'; "
    . "img-src 'self' data: blob:; "
    . "object-src 'none'; "
    . "script-src 'self'; "
    . "style-src 'self'"
);

if (str_starts_with($path, '/api/')) {
    $container['api']->handle();
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#15211f">
    <title>BlockKnowledgeBase</title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
    <noscript>
        <div class="noscript">
            BlockKnowledgeBase benötigt JavaScript für den blockbasierten Editor.
        </div>
    </noscript>
    <div id="app" aria-live="polite">
        <div class="boot-screen">
            <div class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></div>
            <p>BlockKnowledgeBase wird geladen …</p>
        </div>
    </div>
</body>
</html>
