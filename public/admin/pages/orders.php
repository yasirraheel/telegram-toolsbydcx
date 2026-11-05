<?php
// Orders: list with status filter; detail with items; Deliver (manual),
// Refund (points back to wallet), Cancel.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    $oid = post_int('id');
    $order = $oid ? order_load($pdo, $botId, $oid) : null;
    if (!$order) { flash('Order not found.', 'err'); redirect('index.php?page=orders'); }

    if ($action === 'deliver') {
        $confirmed = post_int('confirmed') === 1;
        if ($order['status'] !== 'pending') { flash('Only pending orders can be delivered.', 'err'); }
        elseif ($order['txid'] === '' && !$confirmed) {
            flash('This order has no TXID. Tick "payment confirmed" to deliver it anyway.', 'err');
        } else {
            try {
                $res = order_deliver_existing($pdo, $botId, $oid);
                $ust = $pdo->prepare("SELECT telegram_id FROM users WHERE id=?");
                $ust->execute([(int)$order['user_id']]);
                $u = $ust->fetch();
                if ($u) order_notify_delivery(bot_token($botId), (int)$u['telegram_id'], $res['order'], $res['contents']);
                flash('Order #' . $oid . ' delivered (' . count($res['contents']) . ' item(s) sent to the buyer).');
            } catch (OrderServiceException $e) {
                flash('Delivery failed: ' . ($e->getMessage() === 'out_of_stock' ? 'not enough stock for this product.' : $e->getMessage()), 'err');
            }
        }
    } elseif ($action === 'refund') {
        if ($order['status'] === 'refunded') { flash('Order is already refunded.', 'err'); }
        else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE orders SET status='refunded' WHERE id=?")->execute([$oid]);
                $points = order_refund_points($botId, $order);
                $pdo->commit();
                flash('Order #' . $oid . ' refunded: ' . fmt_points($points) . ' points returned to the buyer wallet.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('Refund failed: ' . $e->getMessage(), 'err');
            }
        }
    } elseif ($action === 'cancel') {
        if ($order['status'] !== 'pending') { flash('Only pending orders can be cancelled.', 'err'); }
        else {
            $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$oid]);
            flash('Order #' . $oid . ' cancelled.');
        }
    }
    redirect('index.php?page=orders&id=' . $oid);
}

$viewId = get_int('id');
if ($viewId) {
    $order = order_load($pdo, $botId, $viewId);
    admin_head('Order #' . $viewId, 'orders', $botId, $bots);
    if (!$order) { echo '<div class="card">Order not found.</div>'; admin_foot(); return; }
    $st = $pdo->prepare("SELECT u.*, o.qty, o.unit_price, o.total_usd, o.payment_method, o.txid, o.created_at, o.delivered_at FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?");
    $st->execute([$viewId]);
    $u = $st->fetch();
    $items = $pdo->prepare("SELECT * FROM order_items WHERE order_id=?");
    $items->execute([$viewId]);
    $items = $items->fetchAll();
    ?>
    <div class="card"><p><a href="index.php?page=orders">← Back to orders</a></p>
    <table>
    <tr><th>Order</th><td>#<?= (int)$order['id'] ?> — <span class="badge <?= $order['status'] === 'delivered' ? 'ok' : ($order['status'] === 'pending' ? 'warn' : 'bad') ?>"><?= esc($order['status']) ?></span></td></tr>
    <tr><th>Product</th><td><?= esc($order['product_name']) ?> × <?= (int)$order['qty'] ?> @ $<?= esc(fmt_usd($order['unit_price'])) ?> = <b>$<?= esc(fmt_usd($order['total_usd'])) ?></b></td></tr>
    <tr><th>Buyer</th><td><a href="index.php?page=users&amp;id=<?= (int)$u['id'] ?>">@<?= esc($u['username'] ?: (string)$u['telegram_id']) ?></a> (TG <?= (int)$u['telegram_id'] ?>)</td></tr>
    <tr><th>Payment</th><td><?= esc($order['payment_method']) ?> — TXID: <span class="mono"><?= esc($order['txid'] ?: '—') ?></span></td></tr>
    <tr><th>Created</th><td><?= esc($order['created_at']) ?><?= $order['delivered_at'] ? ' · Delivered: ' . esc($order['delivered_at']) : '' ?></td></tr>
    </table>
    <h3>Delivered items (<?= count($items) ?>)</h3>
    <?php if ($items): ?><pre><?php foreach ($items as $it) echo esc($it['content_snapshot']) . "\n"; ?></pre>
    <?php else: ?><p class="mut">No items delivered yet.</p><?php endif; ?>
    <div class="row">
    <?php if ($order['status'] === 'pending'): ?>
    <form method="post"><input type="hidden" name="action" value="deliver"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
    <label style="display:inline"><input type="checkbox" name="confirmed" value="1" style="width:auto" <?= $order['txid'] !== '' ? 'checked' : '' ?>> Payment confirmed<?= $order['txid'] !== '' ? ' (TXID on file)' : ' (no TXID — admin confirms payment)' ?></label>
    <button type="submit" class="btn-ok">Deliver manually</button></form>
    <form method="post"><input type="hidden" name="action" value="cancel"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$order['id'] ?>"><button type="submit" class="btn-danger" onclick="return confirm('Cancel this order?')">Cancel</button></form>
    <?php endif; ?>
    <?php if ($order['status'] !== 'refunded'): ?>
    <form method="post"><input type="hidden" name="action" value="refund"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$order['id'] ?>"><button type="submit" class="btn-warn" onclick="return confirm('Refund this order? The full value in points goes back to the buyer wallet.')">Refund (points)</button></form>
    <?php endif; ?>
    </div></div>
    <?php admin_foot(); return;
}

$filter = (string)($_GET['status'] ?? '');
$allowed = ['pending', 'delivered', 'refunded', 'cancelled'];
if (!in_array($filter, $allowed, true)) $filter = '';
$sql = "SELECT o.*, p.name AS product_name, u.username, u.telegram_id FROM orders o JOIN products p ON p.id=o.product_id JOIN users u ON u.id=o.user_id WHERE o.bot_id=?";
$args = [$botId];
if ($filter) { $sql .= " AND o.status=?"; $args[] = $filter; }
$sql .= " ORDER BY o.created_at DESC LIMIT 200";
$st = $pdo->prepare($sql); $st->execute($args);
$rows = $st->fetchAll();

admin_head('Orders', 'orders', $botId, $bots);
?>
<div class="card"><form method="get" action="index.php" class="row"><input type="hidden" name="page" value="orders">
<label style="margin:0">Status:</label><select name="status" onchange="this.form.submit()">
<option value="">All</option>
<?php foreach ($allowed as $s): ?><option value="<?= esc($s) ?>" <?= $filter === $s ? 'selected' : '' ?>><?= esc($s) ?></option><?php endforeach; ?>
</select></form>
<table style="margin-top:12px"><tr><th>#</th><th>Product</th><th>User</th><th>Qty</th><th>Total</th><th>Method</th><th>Status</th><th>When</th></tr>
<?php foreach ($rows as $o): ?><tr>
<td><a href="index.php?page=orders&amp;id=<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?></a></td>
<td><?= esc($o['product_name']) ?></td><td>@<?= esc($o['username'] ?: (string)$o['telegram_id']) ?></td>
<td><?= (int)$o['qty'] ?></td><td>$<?= esc(fmt_usd($o['total_usd'])) ?></td><td><?= esc($o['payment_method']) ?></td>
<td><span class="badge <?= $o['status'] === 'delivered' ? 'ok' : ($o['status'] === 'pending' ? 'warn' : 'bad') ?>"><?= esc($o['status']) ?></span></td>
<td class="mut"><?= esc($o['created_at']) ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="mut">No orders<?= $filter ? ' with status ' . esc($filter) : '' ?> yet.</td></tr><?php endif; ?></table></div>
<?php admin_foot(); ?>
