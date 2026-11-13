<?php
// Front controller API: semua request /be/... masuk ke sini.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/auth.php';
require_once __DIR__ . '/api/menu.php';
require_once __DIR__ . '/api/orders.php';
require_once __DIR__ . '/api/report.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#^/be(/.*)?$#', $path, $m)) {
    $path = $m[1] ?? '/';
}
$path = '/' . trim($path, '/');
if ($path === '/index.php') {
    $path = '/';
}

try {
    $router = new Router();
    register_auth($router);
    register_menu($router);
    register_orders($router);
    register_report($router);
    $router->dispatch($_SERVER['REQUEST_METHOD'], $path);
} catch (Throwable $e) {
    error_log('[kasir] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $msg = DEBUG_MODE ? $e->getMessage() : 'Terjadi kesalahan di server';
    Response::error($msg, 500);
}
