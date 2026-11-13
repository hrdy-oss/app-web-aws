<?php

function register_auth(Router $r): void
{
    $r->add('POST', '/auth/login', function () {
        $b = json_body();
        $username = trim((string) ($b['username'] ?? ''));
        $password = (string) ($b['password'] ?? '');
        if ($username === '' || $password === '') {
            Response::error('Username dan password wajib diisi', 422);
        }
        $st = Database::get()->prepare('SELECT id, username, name, role, password_hash FROM users WHERE username = ?');
        $st->execute([$username]);
        $u = $st->fetch();
        if (!$u || !password_verify($password, $u['password_hash'])) {
            Response::error('Username atau password salah', 401);
        }
        Response::json([
            'token' => Auth::issue($u),
            'user' => ['id' => (int) $u['id'], 'name' => $u['name'], 'role' => $u['role']],
        ]);
    });

    $r->add('GET', '/me', function () {
        Response::json(['user' => Auth::requireRole()]);
    });

    // Health check untuk ALB: memastikan aplikasi dan database hidup.
    $r->add('GET', '/health', function () {
        try {
            Database::get()->query('SELECT 1');
            Response::json(['status' => 'ok']);
        } catch (Throwable $e) {
            Response::json(['status' => 'db_error'], 503);
        }
    });
}
