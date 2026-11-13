<?php

function menu_photo_url(S3Service $s3, ?string $key): ?string
{
    if (!$key || !$s3->enabled()) {
        return null;
    }
    return $s3->presign($key, 3600);
}

function menu_row_out(S3Service $s3, array $m): array
{
    return [
        'id' => (int) $m['id'],
        'name' => $m['name'],
        'category' => $m['category'],
        'price' => (int) $m['price'],
        'has_part' => (bool) $m['has_part'],
        'is_available' => (bool) $m['is_available'],
        'photo_url' => menu_photo_url($s3, $m['photo_key']),
    ];
}

/** Validasi dan simpan foto ke S3. Mengembalikan key objek, atau null jika tidak ada file. */
function menu_handle_upload(S3Service $s3): ?string
{
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES['photo'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        Response::error('Upload foto gagal, coba lagi', 422);
    }
    if ($f['size'] > 2 * 1024 * 1024) {
        Response::error('Ukuran foto maksimal 2 MB', 422);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) {
        Response::error('Foto harus berformat JPG, PNG, atau WebP', 422);
    }
    if (!$s3->enabled()) {
        Response::error('S3 belum dikonfigurasi, foto belum bisa diunggah', 503);
    }
    $key = 'menu/' . bin2hex(random_bytes(12)) . '.' . $ext;
    $s3->put($key, file_get_contents($f['tmp_name']), $mime);
    return $key;
}

function menu_input(): array
{
    $name = trim((string) ($_POST['name'] ?? ''));
    $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_INT);
    $category = (string) ($_POST['category'] ?? 'lainnya');
    if ($name === '' || mb_strlen($name) > 80) {
        Response::error('Nama menu wajib diisi (maksimal 80 karakter)', 422);
    }
    if ($price === false || $price < 0 || $price > 10000000) {
        Response::error('Harga tidak valid', 422);
    }
    if (!in_array($category, ['ayam', 'nasi', 'lainnya'], true)) {
        Response::error('Kategori tidak valid', 422);
    }
    return [
        'name' => $name,
        'price' => $price,
        'category' => $category,
        'has_part' => !empty($_POST['has_part']) && $_POST['has_part'] !== '0' ? 1 : 0,
        'is_available' => isset($_POST['is_available']) && $_POST['is_available'] === '0' ? 0 : 1,
    ];
}

function register_menu(Router $r): void
{
    $r->add('GET', '/menu', function () {
        Auth::requireRole();
        $s3 = new S3Service();
        $rows = Database::get()->query('SELECT * FROM menu_items WHERE is_active = 1 ORDER BY category, id')->fetchAll();
        Response::json(['items' => array_map(fn($m) => menu_row_out($s3, $m), $rows)]);
    });

    $r->add('POST', '/menu', function () {
        Auth::requireRole(['pemilik']);
        $in = menu_input();
        $s3 = new S3Service();
        $key = menu_handle_upload($s3);
        $db = Database::get();
        $st = $db->prepare('INSERT INTO menu_items (name, category, price, has_part, is_available, photo_key) VALUES (?,?,?,?,?,?)');
        $st->execute([$in['name'], $in['category'], $in['price'], $in['has_part'], $in['is_available'], $key]);
        Response::json(['id' => (int) $db->lastInsertId()], 201);
    });

    // Ubah menu (POST agar bisa menerima multipart/form-data di PHP)
    $r->add('POST', '/menu/{id}', function ($p) {
        Auth::requireRole(['pemilik']);
        $id = (int) $p['id'];
        $db = Database::get();
        $st = $db->prepare('SELECT * FROM menu_items WHERE id = ? AND is_active = 1');
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) {
            Response::error('Menu tidak ditemukan', 404);
        }
        $in = menu_input();
        $s3 = new S3Service();
        $newKey = menu_handle_upload($s3);
        $key = $old['photo_key'];
        $dropOld = false;
        if ($newKey) {
            $key = $newKey;
            $dropOld = (bool) $old['photo_key'];
        } elseif (!empty($_POST['remove_photo']) && $_POST['remove_photo'] === '1') {
            $key = null;
            $dropOld = (bool) $old['photo_key'];
        }
        $st = $db->prepare('UPDATE menu_items SET name=?, category=?, price=?, has_part=?, is_available=?, photo_key=? WHERE id=?');
        $st->execute([$in['name'], $in['category'], $in['price'], $in['has_part'], $in['is_available'], $key, $id]);
        if ($dropOld) {
            try {
                $s3->delete($old['photo_key']);
            } catch (Throwable $e) {
                // foto lama gagal dihapus tidak menggagalkan perubahan menu
            }
        }
        Response::json(['ok' => true]);
    });

    // Menu disembunyikan (bukan dihapus) agar riwayat pesanan tetap utuh.
    $r->add('DELETE', '/menu/{id}', function ($p) {
        Auth::requireRole(['pemilik']);
        $st = Database::get()->prepare('UPDATE menu_items SET is_active = 0 WHERE id = ?');
        $st->execute([(int) $p['id']]);
        Response::json(['ok' => true]);
    });
}
