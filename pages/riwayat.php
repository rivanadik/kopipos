<?php
$pageTitle = 'Riwayat Saya';
$st = db()->prepare("SELECT tr.*, u.name user_name FROM transactions tr
    JOIN users u ON u.id = tr.user_id
    WHERE tr.user_id=? ORDER BY tr.id DESC LIMIT 200");
$st->execute([(int)me()['id']]);
?>
<h2>Riwayat Saya</h2>
<p class="sub">Klik transaksi untuk melihat dan mencetak ulang struk.</p>
<div class="card"><?= render_trx_table($st->fetchAll()) ?></div>
