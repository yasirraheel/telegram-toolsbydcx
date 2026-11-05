<?php
// Payment methods CRUD (selected bot only). Details is a JSON textarea.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'save') {
        $id = post_int('id');
        $code = strtolower(trim(post_str('code')));
        $name = post_str('name');
        $details = trim((string)($_POST['details'] ?? '{}'));
        $sort = post_int('sort');
        $active = post_int('active', 1) ? 1 : 0;
        $decoded = json_decode($details, true);
        if (!preg_match('/^[a-z0-9_]+$/', $code) || $name === '') { flash('A valid code (letters/numbers/underscore) and a name are required.', 'err'); }
        elseif ($details !== '' && $decoded === null) { flash('Details must be valid JSON, e.g. {"address":"0x..."} or {"uid":"12345"}.', 'err'); }
        else {
            $details = $details === '' ? '{}' : json_encode($decoded);
            if ($id > 0) {
                $pdo->prepare("UPDATE payment_methods SET code=?, name=?, details=?, sort=?, active=? WHERE id=? AND bot_id=?")
                    ->execute([$code, $name, $details, $sort, $active, $id, $botId]);
                flash('Payment method updated.');
            } else {
                try {
                    $pdo->prepare("INSERT INTO payment_methods (bot_id, code, name, details, sort, active) VALUES (?,?,?,?,?,?)")
                        ->execute([$botId, $code, $name, $details, $sort, $active]);
                    flash('Payment method added.');
                } catch (PDOException $e) { flash('Could not add method (code already exists?): ' . $e->getMessage(), 'err'); }
            }
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM payment_methods WHERE id=? AND bot_id=?")->execute([post_int('id'), $botId]);
        flash('Payment method deleted.');
    }
    redirect('index.php?page=payments');
}

$editId = get_int('edit');
$edit = null;
if ($editId) {
    $st = $pdo->prepare("SELECT * FROM payment_methods WHERE id=? AND bot_id=?");
    $st->execute([$editId, $botId]);
    $edit = $st->fetch();
}
$rows = $pdo->prepare("SELECT * FROM payment_methods WHERE bot_id=? ORDER BY sort ASC, name ASC");
$rows->execute([$botId]);
$rows = $rows->fetchAll();

admin_head('Payment Methods', 'payments', $botId, $bots);
?>
<div class="card"><h3 style="margin-top:0"><?= $edit ? '✏️ Edit payment method' : '➕ Add payment method' ?></h3>
<form method="post"><input type="hidden" name="action" value="save"><?= csrf_field() ?>
<input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<div class="row">
<div style="width:150px"><label>Code</label><input type="text" name="code" value="<?= esc($edit['code'] ?? '') ?>" placeholder="bep20" required></div>
<div style="flex:1"><label>Name</label><input type="text" name="name" value="<?= esc($edit['name'] ?? '') ?>" placeholder="USDT (BEP20)" required></div>
<div style="width:90px"><label>Sort</label><input type="number" name="sort" value="<?= (int)($edit['sort'] ?? 0) ?>"></div>
<div><label>Active</label><select name="active"><option value="1" <?= ($edit['active'] ?? 1) ? 'selected' : '' ?>>Yes</option><option value="0" <?= isset($edit) && !$edit['active'] ? 'selected' : '' ?>>No</option></select></div>
</div>
<label>Details (JSON) — e.g. {"address":"0x..."} or {"uid":"12345"}</label>
<textarea name="details" rows="3" class="mono"><?= esc($edit['details'] ?? '{}') ?></textarea>
<button type="submit" style="margin-top:12px"><?= $edit ? 'Save changes' : 'Add method' ?></button>
<?php if ($edit): ?><a class="btn btn2" href="index.php?page=payments">Cancel</a><?php endif; ?></form></div>

<div class="card"><table><tr><th>Code</th><th>Name</th><th>Details</th><th>Active</th><th></th></tr>
<?php foreach ($rows as $r): ?><tr>
<td class="mono"><?= esc($r['code']) ?></td><td><?= esc($r['name']) ?></td>
<td class="mono"><?= esc($r['details'] ?: '{}') ?></td>
<td><?= $r['active'] ? '<span class="badge ok">yes</span>' : '<span class="badge bad">no</span>' ?></td>
<td><div class="row"><a class="btn btn-sm btn2" href="index.php?page=payments&amp;edit=<?= (int)$r['id'] ?>">Edit</a>
<form method="post" class="inline"><input type="hidden" name="action" value="delete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-sm btn-danger" type="submit" onclick="return confirm('Delete this payment method?')">Delete</button></form></div></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="mut">No payment methods yet for this bot.</td></tr><?php endif; ?></table></div>
<?php admin_foot(); ?>
