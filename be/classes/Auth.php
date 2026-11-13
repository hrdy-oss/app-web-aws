<?php

class Auth
{
    private static ?array $current = null;

    private static function secret(): string
    {
        $s = env('JWT_SECRET');
        if (!$s || strlen($s) < 16) {
            throw new RuntimeException('JWT_SECRET belum diatur di .env (minimal 16 karakter)');
        }
        return $s;
    }

    private static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64d(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/'));
    }

    public static function issue(array $user): string
    {
        $h = self::b64u(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p = self::b64u(json_encode([
            'sub' => (int) $user['id'],
            'name' => $user['name'],
            'role' => $user['role'],
            'exp' => time() + 12 * 3600,
        ]));
        $s = self::b64u(hash_hmac('sha256', "$h.$p", self::secret(), true));
        return "$h.$p.$s";
    }

    private static function authHeader(): string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
            if (!empty($_SERVER[$k])) {
                return $_SERVER[$k];
            }
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strtolower($k) === 'authorization') {
                    return $v;
                }
            }
        }
        return '';
    }

    public static function user(): ?array
    {
        if (self::$current !== null) {
            return self::$current;
        }
        if (!preg_match('/^Bearer\s+(.+)$/i', self::authHeader(), $m)) {
            return null;
        }
        $parts = explode('.', trim($m[1]));
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $calc = self::b64u(hash_hmac('sha256', "$h.$p", self::secret(), true));
        if (!hash_equals($calc, $s)) {
            return null;
        }
        $data = json_decode(self::b64d($p), true);
        if (!is_array($data) || ($data['exp'] ?? 0) < time()) {
            return null;
        }
        self::$current = ['id' => (int) $data['sub'], 'name' => $data['name'], 'role' => $data['role']];
        return self::$current;
    }

    public static function requireRole(array $roles = []): array
    {
        $u = self::user();
        if (!$u) {
            Response::error('Sesi habis, silakan login lagi', 401);
        }
        if ($roles && !in_array($u['role'], $roles, true)) {
            Response::error('Aksi ini hanya untuk pemilik', 403);
        }
        return $u;
    }
}
