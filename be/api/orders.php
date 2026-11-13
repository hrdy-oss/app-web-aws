<?php

const ORDER_STATUSES = ['baru', 'diproses', 'siap', 'selesai', 'batal'];

/** Validasi isi pesanan lalu hitung harga dari database (harga dari klien tidak dipercaya). */
function orders_prepare(array $b): array
{
    $source = (string) ($b['source'] ?? 'langsung');
    $method = (string) ($b['payment_method'] ?? 'tunai');
    $customer = trim((string) ($b['customer_name'] ?? ''));
    if (!in_array($source, ['langsung', 'whatsapp'], true)) {
        Response::error('Sumber pesanan tidak valid', 422);
    }
    if (!in_array($method, ['tunai', 'qris'], true)) {
        Response::error('Metode pembayaran tidak valid', 422);
    }
    if (mb_strlen($customer) > 80) {
        Response::error('Nama pelanggan maksimal 80 karakter', 422);
    }
    $items = $b['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        Response::error('Pilih minimal satu menu', 422);
    }
    $db = Database::get();
    $lines = [];
    $total = 0;
    foreach ($items as $it) {
        $menuId = (int) ($it['menu_id'] ?? 0);
        $qty = (int) ($it['qty'] ?? 0);
        $part = trim((string) ($it['part'] ?? ''));
        if ($qty < 1 || $qty > 99) {
            Response::error('Jumlah harus 1 sampai 99', 422);
        }
        $st = $db->prepare('SELECT id, name, price, has_part, is_available FROM menu_items WHERE id = ? AND is_active = 1');
        $st->execute([$menuId]);
        $m = $st->fetch();
        if (!$m) {
            Response::error('Ada menu yang sudah tidak tersedia', 422);
        }
        if (!$m['is_available']) {
            Response::error($m['name'] . ' sedang habis', 422);
        }
        $part = $m['has_part'] ? mb_substr($part, 0, 30) : '';
        $lines[] = ['menu_id' => (int) $m['id'], 'name' => $m['name'], 'part' => $part ?: null, 'price' => (int) $m['price'], 'qty' => $qty];
        $total += (int) $m['price'] * $qty;
    }
    return [
        'source' => $source,
        'method' => $method,
        'customer' => $customer !== '' ? $customer : null,
        'lines' => $lines,
        'total' => $total,
    ];
}

function orders_insert_lines(PDO $db, int $orderId, array $lines): void
{
    $st = $db->prepare('INSERT INTO order_items (order_id, menu_id, name, part, price, qty) VALUES (?,?,?,?,?,?)');
    foreach ($lines as $l) {
        $st->execute([$orderId, $l['menu_id'], $l['name'], $l['part'], $l['price'], $l['qty']]);
    }
}

function orders_attach_items(PDO $db, array $orders): array
{
    if (!$orders) {
        return [];
    }
    $ids = array_map(fn($o) => (int) $o['id'], $orders);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT order_id, menu_id, name, part, price, qty FROM order_items WHERE order_id IN ($in) ORDER BY id");
    $st->execute($ids);
    $byOrder = [];
    foreach ($st->fetchAll() as $it) {
        $byOrder[(int) $it['order_id']][] = [
            'menu_id' => $it['menu_id'] !== null ? (int) $it['menu_id'] : null,
            'name' => $it['name'],
            'part' => $it['part'],
            'price' => (int) $it['price'],
            'qty' => (int) $it['qty'],
        ];
    }
    return array_map(fn($o) => [
        'id' => (int) $o['id'],
        'source' => $o['source'],
        'customer_name' => $o['customer_name'],
        'payment_method' => $o['payment_method'],
        'is_paid' => (bool) $o['is_paid'],
        'paid_at' => $o['paid_at'],
        'status' => $o['status'],
        'total' => (int) $o['total'],
        'created_at' => $o['created_at'],
        'items' => $byOrder[(int) $o['id']] ?? [],
    ], $orders);
}

function orders_find(PDO $db, int $id): array
{
    $st = $db->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) {
        Response::error('Pesanan tidak ditemukan', 404);
    }
    return $o;
}

function register_orders(Router $r): void
{
    $r->add('GET', '/orders', function () {
        Auth::requireRole();
        $db = Database::get();
        $status = (string) ($_GET['status'] ?? '');
        $sql = 'SELECT * FROM orders WHERE 1=1';
        $args = [];

        if ($status === 'aktif') {
            $sql .= " AND status IN ('baru','diproses','siap')";
        } elseif (($_GET['unpaid'] ?? '') === '1') {
            $sql .= " AND is_paid = 0 AND status <> 'batal'";
        } else {
            $from = valid_date($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d');
            $to = valid_date($_GET['to'] ?? null) ? $_GET['to'] : $from;
            $sql .= ' AND created_at BETWEEN ? AND ?';
            $args[] = "$from 00:00:00";
            $args[] = "$to 23:59:59";
            if (in_array($status, ORDER_STATUSES, true)) {
                $sql .= ' AND status = ?';
                $args[] = $status;
            }
        }
        $sql .= ' ORDER BY id DESC LIMIT 500';
        $st = $db->prepare($sql);
        $st->execute($args);
        Response::json(['orders' => orders_attach_items($db, $st->fetchAll())]);
    });

    $r->add('POST', '/orders', function () {
        $user = Auth::requireRole(['pemilik']);
        $b = json_body();
        $o = orders_prepare($b);
        $paid = !empty($b['is_paid']) ? 1 : 0;
        $now = date('Y-m-d H:i:s');
        $db = Database::get();
        $db->beginTransaction();
        try {
            $st = $db->prepare('INSERT INTO orders (source, customer_name, payment_method, is_paid, paid_at, status, total, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$o['source'], $o['customer'], $o['method'], $paid, $paid ? $now : null, 'baru', $o['total'], $user['id'], $now, $now]);
            $id = (int) $db->lastInsertId();
            orders_insert_lines($db, $id, $o['lines']);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        Response::json(['id' => $id, 'total' => $o['total']], 201);
    });

    // Ubah isi pesanan: hanya pemilik.
    $r->add('PUT', '/orders/{id}', function ($p) {
        Auth::requireRole(['pemilik']);
        $db = Database::get();
        $id = (int) $p['id'];
        $old = orders_find($db, $id);
        if ($old['status'] === 'batal') {
            Response::error('Pesanan yang dibatalkan tidak bisa diubah', 409);
        }
        $o = orders_prepare(json_body());
        $db->beginTransaction();
        try {
            $st = $db->prepare('UPDATE orders SET source=?, customer_name=?, payment_method=?, total=?, updated_at=? WHERE id=?');
            $st->execute([$o['source'], $o['customer'], $o['method'], $o['total'], date('Y-m-d H:i:s'), $id]);
            $db->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$id]);
            orders_insert_lines($db, $id, $o['lines']);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        Response::json(['ok' => true, 'total' => $o['total']]);
    });

    // Dapur boleh: diproses, siap. Pemilik boleh semua status (termasuk batal).
    $r->add('PATCH', '/orders/{id}/status', function ($p) {
        $user = Auth::requireRole();
        $db = Database::get();
        $id = (int) $p['id'];
        $new = (string) (json_body()['status'] ?? '');
        $allowed = $user['role'] === 'pemilik' ? ORDER_STATUSES : ['diproses', 'siap'];
        if (!in_array($new, $allowed, true)) {
            Response::error('Anda tidak bisa mengubah ke status ini', 403);
        }
        $old = orders_find($db, $id);
        if ($old['status'] === 'batal') {
            Response::error('Pesanan yang dibatalkan tidak bisa diubah lagi', 409);
        }
        if ($user['role'] !== 'pemilik' && $old['status'] === 'selesai') {
            Response::error('Pesanan sudah selesai', 409);
        }
        $db->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?')->execute([$new, date('Y-m-d H:i:s'), $id]);
        Response::json(['ok' => true]);
    });

    // Tandai lunas (untuk pesanan yang dibayar nanti).
    $r->add('POST', '/orders/{id}/pay', function ($p) {
        Auth::requireRole(['pemilik']);
        $db = Database::get();
        $id = (int) $p['id'];
        $old = orders_find($db, $id);
        if ($old['status'] === 'batal') {
            Response::error('Pesanan yang dibatalkan tidak bisa dibayar', 409);
        }
        $method = (string) (json_body()['payment_method'] ?? $old['payment_method']);
        if (!in_array($method, ['tunai', 'qris'], true)) {
            Response::error('Metode pembayaran tidak valid', 422);
        }
        $now = date('Y-m-d H:i:s');
        $db->prepare('UPDATE orders SET is_paid = 1, paid_at = ?, payment_method = ?, updated_at = ? WHERE id = ?')->execute([$now, $method, $now, $id]);
        Response::json(['ok' => true]);
    });
}
