<?php
// Stock: per product, add many items at once (one per line), list with status, delete.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'add') {
        $pid = post_int('product_id');
        $isSample = post_int('is_sample') ? 1 : 0;
        $chk = $pdo->prepare("SELECT id FROM products WHERE id=? AND bot_id=?");
        $chk->execute([$pid, $botId]);
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($_POST['items'] ?? ''))));
        if (!$chk->fetch()) { flash('Product not found.', 'err'); }
        elseif (!$lines) { flash('Paste at least one stock item (one per line).', 'err'); }
        else {
            $ins = $pdo->prepare("INSERT INTO stock_items (bot_id, product_id, content, is_sample) VALUES (?,?,?,?)");
            $n = 0;
            foreach ($lines as $line) { $ins->execute([$botId, $pid, $line, $isSample]); $n++; }
            flash("$n stock item(s) added" . ($isSample ? ' (marked as samples).' : '.'));
        }
    } elseif ($action === 'delete') {
        $st = $pdo->prepare("SELECT s.id FROM stock_items s JOIN products p ON p.id=s.product_id WHERE s.id=? AND p.bot_id=?");
        $st->execute([post_int('id'), $botId]);
        if ($st->fetch()) {
            $pdo->prepare("DELETE FROM stock_items WHERE id=?")->execute([post_int('id')]);
            flash('Stock item deleted.');
        } else { flash('Stock item not found.', 'err'); }
    }
    redirect('index.php?page=stock&product_id=' . post_int('product_id'));
}

$prods = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM stock_items s WHERE s.product_id=p.id AND s.status='available') AS stock FROM products p WHERE p.bot_id=? ORDER BY p.name ASC");
$prods->execute([$botId]);
$prods = $prods->fetchAll();

$pid = get_int('product_id');
$product = null;
if ($pid) {
    foreach ($prods as $p) if ((int)$p['id'] === $pid) $product = $p;
}
if (!$product && $prods) { $product = $prods[0]; $pid = (int)$product['id']; }

$items = [];
if ($product) {
    $st = $pdo->prepare("SELECT * FROM stock_items WHERE product_id=? ORDER BY id DESC LIMIT 500");
    $st->execute([$pid]);
    $items = $st->fetchAll();
}

admin_head('Stock', 'stock', $botId, $bots);
?>
<div class="card"><form method="get" action="index.php" class="row"><input type="hidden" name="page" value="stock">
<label style="margin:0">Product:</label><select name="product_id" onchange="this.form.submit()" style="max-width:420px">
<?php foreach ($prods as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $pid ? 'selected' : '' ?>><?= esc($p['name']) ?> (<?= (int)$p['stock'] ?> available)</option><?php endforeach; ?>
</select></form>
<?php if (!$product): ?><p class="mut">No products yet. Add one in <a href="index.php?page=products">Products</a> first.</p><?php endif; ?></div>

<?php if ($product): ?>
<div class="card"><h3 style="margin-top:0">➕ Add stock — <?= esc($product['name']) ?></h3>
<form method="post"><input type="hidden" name="action" value="add"><?= csrf_field() ?>
<input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
<label>Items (one per line — each line is one delivered code/account)</label>
<textarea name="items" rows="8" placeholder="CODE-XXXX-1111&#10;CODE-XXXX-2222"></textarea>
<label><input type="checkbox" name="is_sample" value="1" style="width:auto"> Mark these as <b>sample</b> items (clearly labelled; used for previews, not regular sales)</label>
<button type="submit" style="margin-top:12px">Add stock items</button></form></div>

<div class="card"><h3 style="margin-top:0">📦 Stock items (<?= count($items) ?> shown, newest first)</h3>
<table><tr><th>ID</th><th>Content</th><th>Sample</th><th>Status</th><th>Sold order</th><th>Added</th><th></th></tr>
<?php foreach ($items as $it): ?><tr>
<td><?= (int)$it['id'] ?></td><td class="mono"><?= esc($it['content']) ?></td>
<td><?= $it['is_sample'] ? '<span class="badge warn">SAMPLE</span>' : '—' ?></td>
<td><span class="badge <?= $it['status'] === 'available' ? 'ok' : ($it['status'] === 'sold' ? 'bad' : 'warn') ?>"><?= esc($it['status']) ?></span></td>
<td><?= $it['sold_order_id'] ? '<a href="index.php?page=orders&amp;id=' . (int)$it['sold_order_id'] . '">#' . (int)$it['sold_order_id'] . '</a>' : '—' ?></td>
<td class="mut"><?= esc($it['created_at']) ?></td>
<td><?php if ($it['status'] === 'available'): ?>
<form method="post" class="inline"><input type="hidden" name="action" value="delete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>"><button class="btn-sm btn-danger" type="submit" onclick="return confirm('Delete this stock item?')">Delete</button></form>
<?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="7" class="mut">No stock items for this product yet.</td></tr><?php endif; ?></table></div>
<?php endif; ?>
<?php admin_foot(); ?>
