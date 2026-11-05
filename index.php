<?php
require __DIR__ . '/app/init.php';

$page = $_GET['page'] ?? '';

if ($page === 'logout') {
    $_SESSION = [];
    session_destroy();
    redirect('index.php?page=login');
}

if (!me()) {
    if ($page !== 'login') redirect('index.php?page=login');
    $allowed = ['login'];
} else {
    if ($page === 'login') redirect('index.php');
    $allowed = is_admin()
        ? ['dashboard', 'laporan', 'labarugi', 'cetak', 'shift', 'produk', 'kategori', 'bahan', 'resep', 'stok',
           'diskon', 'kupon', 'beban', 'jadwal', 'struk', 'printer', 'akun']
        : ['pos', 'shift', 'riwayat', 'stok', 'printer'];
    if ($page === '') redirect('index.php?page=' . (is_admin() ? 'dashboard' : 'pos'));
}

if (!in_array($page, $allowed, true)) {
    redirect(me() ? 'index.php' : 'index.php?page=login');
}

// Halaman dijalankan ke buffer agar redirect/header tetap bisa dipakai
ob_start();
require __DIR__ . '/pages/' . $page . '.php';
$content = ob_get_clean();

if ($page === 'login') {
    echo $content;
} else {
    require __DIR__ . '/app/layout.php';
}
