<?php
// Products CRUD (selected bot only). List shows available stock count.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'save') {
        $id = post_int('id');
        $name = post_str('name');
        $cat = post_int('category_id');
        $price = post_float('price_usd');
        $desc = post_str('description');
        $format = post_str('delivery_format') ?: 'Coupon / Redemption Code';
        $status = post_str('status') === 'out_of_stock' ? 'out_of_stock' : 'active';
        $sort = post_int('sort');
        $active = post_int('active', 1) ? 1 : 0;
        $chk = $pdo->prepare("SELECT id FROM categories WHERE id=? AND bot_id=?");
        $chk->execute([$cat, $botId]);
        if ($name === '' || !$chk->fetch()) { flash('Name and a valid category are required.', 'err'); }
        elseif ($id > 0) {
            $pdo->prepare("UPDATE products SET category_id=?, name=?, price_usd=?, description=?, delivery_format=?, status=?, sort=?, active=? WHERE id=? AND bot_id=?")
                ->execute([$cat, $name, $price, $desc, $format, $status, $sort, $active, $id, $botId]);
            flash('Product updated.');
        } else {
            $pdo->prepare("INSERT INTO products (bot_id, category_id, name, price_usd, description, delivery_format, status, sort, active) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$botId, $cat, $name, $price, $desc, $format, $status, $sort, $active]);
            flash('Product added.');
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM products WHERE id=? AND bot_id=?")->execute([post_int('id'), $botId]);
        flash('Product deleted (its stock was removed with it).');
    }
    redirect('index.php?page=products');
}

$cats = $pdo->prepare("SELECT * FROM categories WHERE bot_id=? ORDER BY sort ASC, name ASC");
$cats->execute([$botId]);
$cats = $cats->fetchAll();

$editId = get_int('edit');
$edit = null;
if ($editId) {
    $st = $pdo->prepare("SELECT * FROM products WHERE id=? AND bot_id=?");
    $st->execute([$editId, $botId]);
    $edit = $st->fetch();
}
$filterCat = get_int('category_id');
$sql = "SELECT p.*, c.name AS cat_name,
        (SELECT COUNT(*) FROM stock_items s WHERE s.product_id=p.id AND s.status='available') AS stock
        FROM products p JOIN categories c ON c.id=p.category_id WHERE p.bot_id=?";
$args = [$botId];
if ($filterCat) { $sql .= " AND p.category_id=?"; $args[] = $filterCat; }
$sql .= " ORDER BY c.sort ASC, p.sort ASC, p.name ASC";
$st = $pdo->prepare($sql); $st->execute($args);
$rows = $st->fetchAll();

admin_head('Products', 'products', $botId, $bots);
?>
<div class="card"><h3 style="margin-top:0"><?= $edit ? '✏️ Edit product' : '➕ Add product' ?></h3>
<?php if (!$cats): ?><p class="mut">Add a category first.</p><?php else: ?>
<form method="post"><input type="hidden" name="action" value="save"><?= csrf_field() ?>
<input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<div class="row">
<div style="flex:2"><label>Name</label><input type="text" name="name" value="<?= esc($edit['name'] ?? '') ?>" required></div>
<div style="flex:1"><label>Category</label><select name="category_id" style="width:100%">
<?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($edit['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= esc($c['name']) ?></option><?php endforeach; ?>
</select></div>
<div style="width:120px"><label>Price (USD)</label><input type="number" step="0.01" min="0" name="price_usd" value="<?= esc(fmt_usd($edit['price_usd'] ?? 0)) ?>" required></div>
<div style="width:110px"><label>Sort</label><input type="number" name="sort" value="<?= (int)($edit['sort'] ?? 0) ?>"></div>
</div>
<label>Description</label><textarea name="description"><?= esc($edit['description'] ?? '') ?></textarea>
<div class="row">
<div style="flex:1"><label>Delivery format</label><input type="text" name="delivery_format" value="<?= esc($edit['delivery_format'] ?? 'Coupon / Redemption Code') ?>"></div>
<div><label>Status</label><select name="status"><option value="active" <?= ($edit['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>active</option><option value="out_of_stock" <?= ($edit['status'] ?? '') === 'out_of_stock' ? 'selected' : '' ?>>out_of_stock</option></select></div>
<div><label>Active</label><select name="active"><option value="1" <?= ($edit['active'] ?? 1) ? 'selected' : '' ?>>Yes</option><option value="0" <?= isset($edit) && !$edit['active'] ? 'selected' : '' ?>>No</option></select></div>
</div>
<button type="submit" style="margin-top:12px"><?= $edit ? 'Save changes' : 'Add product' ?></button>
<?php if ($edit): ?><a class="btn btn2" href="index.php?page=products">Cancel</a><?php endif; ?></form><?php endif; ?></div>

<div class="card"><form method="get" action="index.php" class="row"><input type="hidden" name="page" value="products">
<label style="margin:0">Category:</label><select name="category_id" onchange="this.form.submit()">
<option value="0">All categories</option>
<?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $filterCat === (int)$c['id'] ? 'selected' : '' ?>><?= esc($c['name']) ?></option><?php endforeach; ?>
</select></form>
<table style="margin-top:12px"><tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Sold</th><th>Status</th><th></th></tr>
<?php foreach ($rows as $r): ?><tr>
<td><?= esc($r['name']) ?><br><span class="mut"><?= esc($r['delivery_format']) ?></span></td>
<td><?= esc($r['cat_name']) ?></td><td>$<?= esc(fmt_usd($r['price_usd'])) ?> <span class="mut">≈ Rs. <?= esc(pkr_of($botId, $r['price_usd'])) ?></span></td>
<td><span class="badge <?= (int)$r['stock'] === 0 ? 'bad' : ((int)$r['stock'] < 5 ? 'warn' : 'ok') ?>"><?= (int)$r['stock'] ?></span></td>
<td><?= (int)$r['sold_count'] ?></td>
<td><?= $r['active'] ? esc($r['status']) : '<span class="badge bad">inactive</span>' ?></td>
<td><div class="row">
<a class="btn btn-sm btn2" href="index.php?page=stock&amp;product_id=<?= (int)$r['id'] ?>">Stock</a>
<a class="btn btn-sm btn2" href="index.php?page=products&amp;edit=<?= (int)$r['id'] ?>">Edit</a>
<form method="post" class="inline"><input type="hidden" name="action" value="delete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-sm btn-danger" type="submit" onclick="return confirm('Delete this product and all its stock?')">Delete</button></form>
</div></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="mut">No products yet for this bot.</td></tr><?php endif; ?></table></div>
<?php admin_foot(); ?>
