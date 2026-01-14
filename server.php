<?php

/*
 * PHP Development Router for FileGator
 *
 * This handles static file serving for PHP built-in server
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

$staticPath = __DIR__ . '/dist' . $uri;
if ($uri !== '/' && file_exists($staticPath) && is_file($staticPath)) {
    $ext = pathinfo($staticPath, PATHINFO_EXTENSION);
    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
    ];
    
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }
    
    readfile($staticPath);
    return;
}

define('APP_ENV', 'production');
define('APP_PUBLIC_PATH', '/');

require __DIR__ . '/dist/index.php';
