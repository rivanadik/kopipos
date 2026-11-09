<?php
/*
 | CRUD generik. Dipanggil dari halaman yang mengisi $cfg:
 |   title, table, intro?, where?, order?, fields, cols,
 |   before_save?(array $data, int $id): array|string  (string = pesan error)
 |   after_save?(array $data, int $id, ?array $old): void  (dipanggil setelah berhasil simpan; $old = baris lama / null jika baru)
 |   before_delete?(int $id): ?string  (string = alasan tidak boleh dihapus)
 |   delete_confirm?(array $row): string  (teks konfirmasi hapus kustom)
 |   confirm_edit?: bool  (true = saat Simpan Ubah, tampilkan konfirmasi nilai lama -> baru)
 |   row_actions?(array $row): string   top_html?: string   no_delete?: bool
 | Format field: [nama, label, tipe, opsi?, default?, wajib?]
 |   tipe: text | int | dec | check | password | date | time | select | fk (select, '' -> NULL) | hidden (input tersembunyi berisi nilai baris sekarang)
 */
$tbl = $cfg['table'];
$where = $cfg['where'] ?? '1=1';
$order = $cfg['order'] ?? 'id';
$pageTitle = $pageTitle ?? $cfg['title'];
$base = 'index.php?page=' . ($_GET['page'] ?? '');
$editId = (int)($_GET['edit'] ?? 0);
$isNew = isset($_GET['new']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['save', 'delete'], true)) {
    check_csrf();
    $act = $_POST['action'];

    if ($act === 'delete' && empty($cfg['no_delete'])) {
        $id = (int)$_POST['id'];
        $block = isset($cfg['before_delete']) ? $cfg['before_delete']($id) : null;
        if ($tbl === 'users' && $id === (int)me()['id']) {
            flash_set('Akun yang sedang dipakai tidak dapat dihapus', 'err');
        } elseif (is_string($block) && $block !== '') {
            flash_set($block, 'err');
        } else {
            try {
                db()->prepare("DELETE FROM `$tbl` WHERE id=?")->execute([$id]);
                flash_set('Data dihapus');
            } catch (PDOException $ex) {
                flash_set('Data tidak bisa dihapus karena masih dipakai data lain. Nonaktifkan saja.', 'err');
            }
        }
        redirect($base);
    }

    if ($act === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $back = $base . ($id ? '&edit=' . $id : '&new=1');
        $data = [];
        foreach ($cfg['fields'] as $f) {
            [$name, $label, $type] = $f;
            $raw = trim((string)($_POST['f_' . $name] ?? ''));
            switch ($type) {
                case 'check': $v = isset($_POST['f_' . $name]) ? 1 : 0; break;
                case 'int': $v = (int)round((float)str_replace(',', '.', $raw)); break;
                case 'dec': $v = (float)str_replace(',', '.', $raw); break;
                case 'password': $v = $raw === '' ? null : password_hash($raw, PASSWORD_DEFAULT); break;
                case 'date': $v = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : null; break;
                case 'time': $v = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $raw) ? $raw : ''; break;
                case 'fk': $v = $raw === '' ? null : (int)$raw; break;
                default: $v = $name === 'code' ? strtoupper($raw) : $raw;
            }
            if (($type === 'int' || $type === 'dec') && num_too_big((float)$v)) {
                flash_set(num_limit_msg($label), 'err');
                redirect($back);
            }
            if (!empty($f[5]) && ($v === '' || $v === null)) {
                flash_set('Kolom "' . $label . '" wajib diisi', 'err');
                redirect($back);
            }
            $data[$name] = $v;
        }
        if ($tbl === 'users') {
            if (empty($data['password'])) unset($data['password']);
        }
        if (isset($cfg['before_save'])) {
            $res = $cfg['before_save']($data, $id);
            if (is_string($res)) { flash_set($res, 'err'); redirect($back); }
            $data = $res;
        }
        $oldRow = $id ? (q("SELECT * FROM `$tbl` WHERE id=?", [$id])->fetch() ?: null) : null;
        try {
            if ($id) {
                $sets = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
                $vals = array_values($data);
                $vals[] = $id;
                db()->prepare("UPDATE `$tbl` SET $sets WHERE id=?")->execute($vals);
            } else {
                $cols = implode(',', array_map(fn($k) => "`$k`", array_keys($data)));
                $qs = implode(',', array_fill(0, count($data), '?'));
                db()->prepare("INSERT INTO `$tbl` ($cols) VALUES ($qs)")->execute(array_values($data));
                $id = (int)db()->lastInsertId();
            }
        } catch (PDOException $ex) {
            error_log('crud ' . $tbl . ': ' . $ex->getMessage());
            flash_set($ex->getCode() === '23000' ? 'Data sudah ada (nama/kode tidak boleh kembar)' : 'Gagal menyimpan data. Cek angka tidak terlalu besar.', 'err');
            redirect($back);
        }
        if (isset($cfg['after_save'])) $cfg['after_save']($data, $id, $oldRow);
        flash_set('Data tersimpan');
        redirect($base);
    }
}

$rows = db()->query("SELECT * FROM `$tbl` WHERE $where ORDER BY $order")->fetchAll();
$editRow = $editId ? (q("SELECT * FROM `$tbl` WHERE id=?", [$editId])->fetch() ?: null) : null;
?>
<div class="toolbar">
  <div>
    <h2><?= e($cfg['title']) ?></h2>
    <p class="sub" style="margin:0"><?= e($cfg['intro'] ?? (count($rows) . ' data')) ?></p>
  </div>
  <?php if (!$isNew && !$editId): ?>
    <a class="btn btn-primary" href="<?= $base ?>&new=1">+ Tambah</a>
  <?php endif; ?>
</div>

<?= $cfg['top_html'] ?? '' ?>

<?php if ($isNew || $editRow): ?>
<div class="card pad form-card">
  <h3 class="cardh2"><?= $editRow ? 'Ubah' : 'Tambah' ?> <?= e($cfg['title']) ?></h3>
  <form method="post" action="<?= $base ?>" id="crud-form"<?= ($editRow && !empty($cfg['confirm_edit'])) ? ' data-confirm-edit="1"' : '' ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : 0 ?>">
    <div class="form-grid">
    <?php foreach ($cfg['fields'] as $f):
      [$name, $label, $type] = $f;
      $opts = $f[3] ?? null;
      $def = $f[4] ?? ($type === 'check' ? 1 : '');
      $v = $editRow ? ($editRow[$name] ?? '') : $def;
      $id = 'f_' . $name;
    ?>
      <?php if ($type === 'hidden'): ?>
        <input type="hidden" name="<?= $id ?>" value="<?= e($editRow ? ($editRow[$name] ?? '') : $def) ?>">
      <?php elseif ($type === 'check'): ?>
        <div class="fg full"><label class="check"><input type="checkbox" name="<?= $id ?>" value="1" <?= $v ? 'checked' : '' ?>> <?= e($label) ?></label></div>
      <?php else: ?>
        <div class="fg"><label class="lbl" for="<?= $id ?>"><?= e($label) ?><?= !empty($f[5]) ? ' *' : '' ?></label>
        <?php if ($opts !== null): ?>
          <select id="<?= $id ?>" name="<?= $id ?>">
            <?php foreach ($opts as $ov => $ol): ?>
              <option value="<?= e($ov) ?>" <?= (string)$v === (string)$ov ? 'selected' : '' ?>><?= e($ol) ?></option>
            <?php endforeach; ?>
          </select>
        <?php elseif ($type === 'password'): ?>
          <input id="<?= $id ?>" type="password" name="<?= $id ?>" autocomplete="new-password" placeholder="<?= $editRow ? 'Kosongkan jika tidak diubah' : '' ?>">
        <?php elseif ($type === 'int' || $type === 'dec'): ?>
          <input id="<?= $id ?>" type="number" step="<?= $type === 'dec' ? 'any' : '1' ?>" name="<?= $id ?>" value="<?= e($type === 'dec' && $v !== '' ? (float)$v : $v) ?>">
        <?php elseif ($type === 'date'): ?>
          <input id="<?= $id ?>" type="date" name="<?= $id ?>" value="<?= e($v) ?>">
        <?php elseif ($type === 'time'): ?>
          <input id="<?= $id ?>" type="time" name="<?= $id ?>" value="<?= e(hm($v)) ?>">
        <?php else: ?>
          <input id="<?= $id ?>" type="text" name="<?= $id ?>" value="<?= e($v) ?>">
        <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary">Simpan</button>
      <a class="btn" href="<?= $base ?>">Batal</a>
    </div>
  </form>
</div>
<?php endif; ?>
<?php if ($editRow && !empty($cfg['confirm_edit'])): ?>
<script>
(function () {
  const f = document.getElementById('crud-form');
  if (!f) return;
  const fields = [...f.querySelectorAll('[name^="f_"]')].filter(el => el.type !== 'hidden');
  const val = el => el.type === 'checkbox' ? (el.checked ? 'Aktif' : 'Nonaktif')
    : el.tagName === 'SELECT' ? el.selectedOptions[0].text : el.value.trim();
  const orig = new Map(fields.map(el => [el, val(el)]));
  const label = el => { const l = f.querySelector('label[for="' + el.id + '"]'); return (l ? l.textContent : el.closest('label').textContent).replace('*', '').trim(); };
  f.addEventListener('submit', e => {
    const ch = fields.filter(el => val(el) !== orig.get(el));
    if (!ch.length) { e.preventDefault(); alert('Tidak ada yang diubah.'); return; }
    const msg = 'Yakin mengubah data ini?\n\n' + ch.map(el => '• ' + label(el) + ': ' + (orig.get(el) || '(kosong)') + '  →  ' + (val(el) || '(kosong)')).join('\n');
    if (!confirm(msg)) e.preventDefault();
  });
})();
</script>
<?php endif; ?>

<div class="card">
<div class="tscroll">
<table>
  <thead><tr>
    <?php foreach ($cfg['cols'] as $c): ?><th class="<?= !empty($c[2]) ? 'right' : '' ?>"><?= e($c[0]) ?></th><?php endforeach; ?>
    <th></th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="<?= count($cfg['cols']) + 1 ?>" class="empty">Belum ada data</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr>
      <?php foreach ($cfg['cols'] as $c): ?>
        <td class="<?= !empty($c[2]) ? 'right' : '' ?>"><?= is_callable($c[1]) ? $c[1]($r) : e($r[$c[1]] ?? '') ?></td>
      <?php endforeach; ?>
      <td class="right nowrap">
        <?= isset($cfg['row_actions']) ? $cfg['row_actions']($r) : '' ?>
        <a class="btn btn-sm" href="<?= $base ?>&edit=<?= (int)$r['id'] ?>">Ubah</a>
        <?php if (empty($cfg['no_delete'])): ?>
        <form method="post" action="<?= $base ?>" class="inline" onsubmit="return confirm(<?= e(json_encode(isset($cfg['delete_confirm']) ? $cfg['delete_confirm']($r) : 'Hapus data ini?', JSON_UNESCAPED_UNICODE)) ?>)">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-sm btn-danger">Hapus</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>
