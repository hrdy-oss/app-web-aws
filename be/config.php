<?php
// Memuat .env dan class-class inti.

function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim(trim($v), "\"'");
        $_ENV[$k] = $v;
        putenv("$k=$v");
    }
}

function env(string $key, $default = null)
{
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : $v;
}

load_env(__DIR__ . '/.env');

date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Jakarta'));
define('DEBUG_MODE', env('DEBUG_MODE', 'false') === 'true');
ini_set('display_errors', DEBUG_MODE ? '1' : '0');
error_reporting(DEBUG_MODE ? E_ALL : E_ALL & ~E_NOTICE & ~E_DEPRECATED);

function json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

function valid_date(?string $d): bool
{
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;
}

require_once __DIR__ . '/classes/Response.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Router.php';
require_once __DIR__ . '/classes/S3Service.php';
