<?php
// Mencatat setiap struk yang dicetak (cetak pertama maupun cetak ulang)
require __DIR__ . '/../app/init.php';
api_guard();
$in = api_input();
$id = (int)($in['id'] ?? 0);
$method = ($in['method'] ?? '') === 'thermal' ? 'thermal' : 'browser';
$device = mb_substr(trim((string)($in['device'] ?? '')), 0, 100);

$pdo = db();
$pdo->beginTransaction();
$t = q("SELECT id, print_count FROM transactions WHERE id=? FOR UPDATE", [$id])->fetch();
if (!$t) { $pdo->rollBack(); json_out(['error' => 'Nota tidak ditemukan'], 404); }
$copy = (int)$t['print_count'] + 1;
q("INSERT INTO print_logs (trx_id, copy_no, method, device, user_id, printed_at) VALUES (?,?,?,?,?,?)",
  [$id, $copy, $method, $device ?: null, (int)me()['id'], now()]);
q("UPDATE transactions SET print_count=?, last_printed_at=? WHERE id=?", [$copy, now(), $id]);
$pdo->commit();
json_out(['printCount' => $copy, 'prints' => print_logs_for($id)]);
