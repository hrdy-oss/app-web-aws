<?php
// Buat / reset user dari terminal (bukan dari web):
//   php create_user.php <username> <password> <pemilik|dapur> "<Nama Tampil>"
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/config.php';

[$script, $username, $password, $role, $name] = array_pad($argv, 5, null);
if (!$username || !$password || !in_array($role, ['pemilik', 'dapur'], true)) {
    fwrite(STDERR, "Pemakaian: php create_user.php <username> <password> <pemilik|dapur> \"<Nama>\"\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password minimal 8 karakter\n");
    exit(1);
}
$name = $name ?: ucfirst($username);
$hash = password_hash($password, PASSWORD_BCRYPT);
$st = Database::get()->prepare(
    'INSERT INTO users (username, password_hash, name, role) VALUES (?,?,?,?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), name = VALUES(name), role = VALUES(role)'
);
$st->execute([$username, $hash, $name, $role]);
echo "User '$username' ($role) siap dipakai\n";
