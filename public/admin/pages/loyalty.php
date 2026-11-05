<?php
// Loyalty tiers CRUD (selected bot only).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'save') {
        $id = post_int('id');
        $name = post_str('name');
        $minSpent = post_float('min_spent');
        $minOrders = post_int('min_orders');
        $discount = post_float('discount_pct');
        $sort = post_int('sort');
        if ($name === '') { flash('Name is required.', 'err'); }
        elseif ($id > 0) {
            $pdo->prepare("UPDATE loyalty_tiers SET name=?, min_spent=?, min_orders=?, discount_pct=?, sort=? WHERE id=? AND bot_id=?")
                ->execute([$name, $minSpent, $minOrders, $discount, $sort, $id, $botId]);
            flash('Tier updated.');
        } else {
            try {
                $pdo->prepare("INSERT INTO loyalty_tiers (bot_id, name, min_spent, min_orders, discount_pct, sort) VALUES (?,?,?,?,?,?)")
                    ->execute([$botId, $name, $minSpent, $minOrders, $discount, $sort]);
                flash('Tier added.');
            } catch (PDOException $e) { flash('Could not add tier (name already exists?): ' . $e->getMessage(), 'err'); }
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM loyalty_tiers WHERE id=? AND bot_id=?")->execute([post_int('id'), $botId]);
        flash('Tier deleted.');
    }
    redirect('index.php?page=loyalty');
}

$editId = get_int('edit');
$edit = null;
if ($editId) {
    $st = $pdo->prepare("SELECT * FROM loyalty_tiers WHERE id=? AND bot_id=?");
    $st->execute([$editId, $botId]);
    $edit = $st->fetch();
}
$rows = $pdo->prepare("SELECT * FROM loyalty_tiers WHERE bot_id=? ORDER BY sort ASC");
$rows->execute([$botId]);
$rows = $rows->fetchAll();

admin_head('Loyalty Tiers', 'loyalty', $botId, $bots);
?>
<div class="card"><h3 style="margin-top:0"><?= $edit ? '✏️ Edit tier' : '➕ Add tier' ?></h3>
<form method="post"><input type="hidden" name="action" value="save"><?= csrf_field() ?>
<input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<div class="row">
<div style="flex:1"><label>Name</label><input type="text" name="name" value="<?= esc($edit['name'] ?? '') ?>" required></div>
<div style="width:130px"><label>Min spent ($)</label><input type="number" step="0.01" min="0" name="min_spent" value="<?= esc(fmt_usd($edit['min_spent'] ?? 0)) ?>"></div>
<div style="width:110px"><label>Min orders</label><input type="number" min="0" name="min_orders" value="<?= (int)($edit['min_orders'] ?? 0) ?>"></div>
<div style="width:110px"><label>Discount %</label><input type="number" step="0.01" min="0" max="100" name="discount_pct" value="<?= esc(fmt_usd($edit['discount_pct'] ?? 0)) ?>"></div>
<div style="width:90px"><label>Sort</label><input type="number" name="sort" value="<?= (int)($edit['sort'] ?? 0) ?>"></div>
</div>
<button type="submit" style="margin-top:12px"><?= $edit ? 'Save changes' : 'Add tier' ?></button>
<?php if ($edit): ?><a class="btn btn2" href="index.php?page=loyalty">Cancel</a><?php endif; ?></form></div>

<div class="card"><table><tr><th>Tier</th><th>Min spent</th><th>Min orders</th><th>Discount</th><th>Sort</th><th></th></tr>
<?php foreach ($rows as $r): ?><tr>
<td><?= esc($r['name']) ?></td><td>$<?= esc(fmt_usd($r['min_spent'])) ?></td><td><?= (int)$r['min_orders'] ?></td>
<td><?= esc(fmt_usd($r['discount_pct'])) ?>%</td><td><?= (int)$r['sort'] ?></td>
<td><div class="row"><a class="btn btn-sm btn2" href="index.php?page=loyalty&amp;edit=<?= (int)$r['id'] ?>">Edit</a>
<form method="post" class="inline"><input type="hidden" name="action" value="delete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-sm btn-danger" type="submit" onclick="return confirm('Delete this tier?')">Delete</button></form></div></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="mut">No tiers yet for this bot.</td></tr><?php endif; ?></table></div>
<?php admin_foot(); ?>
