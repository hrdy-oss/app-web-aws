<?php
// Hanya untuk tes lokal: php -S localhost:8000 router.php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/be' || strpos($p, '/be/') === 0) {
    $file = __DIR__ . $p;
    if (is_file($file) && substr($file, -4) !== '.php') {
        return false;
    }
    require __DIR__ . '/be/index.php';
    return true;
}
return false;
