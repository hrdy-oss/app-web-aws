# Kasir Ayam

Aplikasi kasir/pemesanan makanan: PHP 8 + Apache + MySQL (RDS) + S3 (foto menu).
Frontend memakai HTML/CSS/JS biasa (tanpa build), backend REST API di folder `be/`.

## Struktur
- `index.html`, `assets/` : tampilan (kasir, dapur, pesanan, menu, laporan)
- `be/` : API (`index.php` sebagai router, `api/` endpoint, `classes/` Auth JWT, S3, Database)
- `be/database.sql` : skema + 3 menu awal
- `healthz` : file health check untuk ALB (`/healthz`); `/be/health` ikut mengecek database
- `router.php` : hanya untuk tes lokal

## Kebutuhan server
Apache dengan `mod_rewrite` dan `AllowOverride All` untuk document root, serta PHP 8.x
dengan ekstensi: `mysql` (pdo_mysql), `curl`, `mbstring`, `fileinfo`.
Naikkan `upload_max_filesize` dan `post_max_size` ke 3M di `php.ini` (batas foto di aplikasi 2 MB).

## Menjalankan
1. `cp be/env.sample be/.env` lalu isi nilainya (DB, JWT_SECRET, S3).
2. Impor database: `mysql -h <host> -u <user> -p < be/database.sql`
3. Buat user:
   - `php be/create_user.php admin 'PasswordKuat123' pemilik "Nama Kamu"`
   - `php be/create_user.php dapur 'PasswordDapur123' dapur "Dapur"`
4. Tes lokal: `php -S localhost:8000 router.php` lalu buka http://localhost:8000

## Peran
- `pemilik`: semua fitur (buat/ubah/batalkan pesanan, tandai lunas, menu, laporan)
- `dapur`: hanya melihat pesanan aktif dan mengubah status menjadi diproses atau siap

## Catatan Learner Lab
Kredensial AWS Learner Lab berganti setiap sesi. Perbarui `AWS_ACCESS_KEY_ID`,
`AWS_SECRET_ACCESS_KEY`, dan `AWS_SESSION_TOKEN` di `be/.env` pada setiap EC2 saat sesi baru dimulai.
