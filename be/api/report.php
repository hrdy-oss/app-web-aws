<?php

function report_range(): array
{
    $from = valid_date($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d');
    $to = valid_date($_GET['to'] ?? null) ? $_GET['to'] : $from;
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to, "$from 00:00:00", "$to 23:59:59"];
}

function csv_cell($v): string
{
    $s = (string) $v;
    // cegah formula injection saat dibuka di Excel
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@'], true)) {
        $s = "'" . $s;
    }
    return '"' . str_replace('"', '""', $s) . '"';
}

function register_report(Router $r): void
{
    $r->add('GET', '/report/summary', function () {
        Auth::requireRole(['pemilik']);
        [$from, $to, $start, $end] = report_range();
        $db = Database::get();

        $st = $db->prepare("SELECT COUNT(*) AS n,
                COALESCE(SUM(total),0) AS omzet,
                COALESCE(SUM(CASE WHEN is_paid=1 THEN total END),0) AS lunas,
                COALESCE(SUM(CASE WHEN is_paid=0 THEN total END),0) AS belum
            FROM orders WHERE status <> 'batal' AND created_at BETWEEN ? AND ?");
        $st->execute([$start, $end]);
        $t = $st->fetch();

        $st = $db->prepare("SELECT payment_method, COALESCE(SUM(total),0) AS jumlah
            FROM orders WHERE status <> 'batal' AND is_paid = 1 AND created_at BETWEEN ? AND ?
            GROUP BY payment_method");
        $st->execute([$start, $end]);
        $methods = ['tunai' => 0, 'qris' => 0];
        foreach ($st->fetchAll() as $row) {
            $methods[$row['payment_method']] = (int) $row['jumlah'];
        }

        $st = $db->prepare("SELECT oi.name, SUM(oi.qty) AS qty, SUM(oi.qty * oi.price) AS subtotal
            FROM order_items oi JOIN orders o ON o.id = oi.order_id
            WHERE o.status <> 'batal' AND o.created_at BETWEEN ? AND ?
            GROUP BY oi.name ORDER BY qty DESC");
        $st->execute([$start, $end]);
        $top = array_map(fn($x) => ['name' => $x['name'], 'qty' => (int) $x['qty'], 'subtotal' => (int) $x['subtotal']], $st->fetchAll());

        $st = $db->prepare("SELECT COUNT(*) FROM orders WHERE status = 'batal' AND created_at BETWEEN ? AND ?");
        $st->execute([$start, $end]);

        Response::json([
            'from' => $from,
            'to' => $to,
            'orders' => (int) $t['n'],
            'revenue' => (int) $t['omzet'],
            'paid' => (int) $t['lunas'],
            'unpaid' => (int) $t['belum'],
            'cancelled' => (int) $st->fetchColumn(),
            'by_method' => $methods,
            'top_items' => $top,
        ]);
    });

    // CSV berpemisah titik koma + BOM UTF-8 agar langsung rapi di Excel (locale Indonesia).
    $r->add('GET', '/report/export', function () {
        Auth::requireRole(['pemilik']);
        [$from, $to, $start, $end] = report_range();
        $db = Database::get();
        $st = $db->prepare('SELECT * FROM orders WHERE created_at BETWEEN ? AND ? ORDER BY id');
        $st->execute([$start, $end]);
        $orders = orders_attach_items($db, $st->fetchAll());

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="laporan-' . $from . '_' . $to . '.csv"');
        echo "\xEF\xBB\xBF";
        echo implode(';', array_map('csv_cell', ['No Pesanan', 'Waktu', 'Sumber', 'Pelanggan', 'Item', 'Metode', 'Status Bayar', 'Status', 'Total'])) . "\r\n";
        foreach ($orders as $o) {
            $items = implode(', ', array_map(
                fn($i) => $i['qty'] . 'x ' . $i['name'] . ($i['part'] ? ' (' . $i['part'] . ')' : ''),
                $o['items']
            ));
            echo implode(';', array_map('csv_cell', [
                $o['id'], $o['created_at'], $o['source'], $o['customer_name'] ?? '', $items,
                $o['payment_method'], $o['is_paid'] ? 'Lunas' : 'Belum bayar', $o['status'], $o['total'],
            ])) . "\r\n";
        }
        exit;
    });
}
