<?php
// Dashboard: counts, revenue, low stock, recent orders (selected bot only).
admin_head('Dashboard', 'dashboard', $botId, $bots);
if (!$bot) { echo '<div class="card">No bots yet. Go to <a href="index.php?page=bots">Bots</a> and add your first bot token.</div>'; admin_foot(); return; }

$one = function (string $sql, array $args = []) use ($pdo) {
    $st = $pdo->prepare($sql); $st->execute($args);
    return $st->fetchColumn();
};
$users = (int)$one("SELECT COUNT(*) FROM users WHERE bot_id=?", [$botId]);
$orders = (int)$one("SELECT COUNT(*) FROM orders WHERE bot_id=?", [$botId]);
$revenue = (float)$one("SELECT COALESCE(SUM(total_usd),0) FROM orders WHERE bot_id=? AND status='delivered'", [$botId]);
$pendingOrders = (int)$one("SELECT COUNT(*) FROM orders WHERE bot_id=? AND status='pending'", [$botId]);
$pendingTickets = (int)$one("SELECT COUNT(*) FROM support_tickets WHERE bot_id=? AND status='open'", [$botId]);
$pendingWarranty = (int)$one("SELECT COUNT(*) FROM warranty_claims WHERE bot_id=? AND status='pending'", [$botId]);
$pendingReviews = (int)$one("SELECT COUNT(*) FROM reviews WHERE bot_id=? AND status='pending'", [$botId]);

$low = $pdo->prepare(
    "SELECT p.id, p.name, p.price_usd,
            (SELECT COUNT(*) FROM stock_items s WHERE s.product_id=p.id AND s.status='available') AS stock
       FROM products p WHERE p.bot_id=? AND p.active=1
      HAVING stock < 5 ORDER BY stock ASC LIMIT 20"
);
$low->execute([$botId]);
$lowRows = $low->fetchAll();

$recent = $pdo->prepare(
    "SELECT o.*, p.name AS product_name, u.username, u.telegram_id
       FROM orders o JOIN products p ON p.id=o.product_id
       JOIN users u ON u.id=o.user_id
      WHERE o.bot_id=? ORDER BY o.created_at DESC LIMIT 15"
);
$recent->execute([$botId]);
$recentRows = $recent->fetchAll();
?>
<div class="grid">
<div class="stat"><b><?= (int)$users ?></b><span>Users</span></div>
<div class="stat"><b><?= (int)$orders ?></b><span>Orders</span></div>
<div class="stat"><b>$<?= esc(fmt_usd($revenue)) ?></b><span>Revenue (delivered)</span></div>
<div class="stat"><b><?= (int)$pendingOrders ?></b><span>Pending orders</span></div>
<div class="stat"><b><?= (int)$pendingTickets ?></b><span>Open tickets</span></div>
<div class="stat"><b><?= (int)$pendingWarranty ?></b><span>Pending warranty</span></div>
<div class="stat"><b><?= (int)$pendingReviews ?></b><span>Pending reviews</span></div>
</div>

<div class="card"><h3 style="margin-top:0">⚠️ Low stock (under 5 available)</h3>
<?php if (!$lowRows): ?><p class="mut">All active products have 5+ items in stock.</p>
<?php else: ?><table><tr><th>Product</th><th>Price</th><th>Stock</th><th></th></tr>
<?php foreach ($lowRows as $r): ?><tr>
<td><?= esc($r['name']) ?></td><td>$<?= esc(fmt_usd($r['price_usd'])) ?></td>
<td><span class="badge <?= (int)$r['stock'] === 0 ? 'bad' : 'warn' ?>"><?= (int)$r['stock'] ?></span></td>
<td><a href="index.php?page=stock&amp;product_id=<?= (int)$r['id'] ?>">Add stock →</a></td></tr>
<?php endforeach; ?></table><?php endif; ?></div>

<div class="card"><h3 style="margin-top:0">🧾 Recent orders</h3>
<?php if (!$recentRows): ?><p class="mut">No orders yet for this bot.</p>
<?php else: ?><table><tr><th>#</th><th>Product</th><th>User</th><th>Qty</th><th>Total</th><th>Status</th><th>When</th></tr>
<?php foreach ($recentRows as $o): ?><tr>
<td><a href="index.php?page=orders&amp;id=<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?></a></td>
<td><?= esc($o['product_name']) ?></td>
<td>@<?= esc($o['username'] ?: (string)$o['telegram_id']) ?></td>
<td><?= (int)$o['qty'] ?></td><td>$<?= esc(fmt_usd($o['total_usd'])) ?></td>
<td><span class="badge <?= $o['status'] === 'delivered' ? 'ok' : ($o['status'] === 'pending' ? 'warn' : 'bad') ?>"><?= esc($o['status']) ?></span></td>
<td class="mut"><?= esc($o['created_at']) ?></td></tr>
<?php endforeach; ?></table><?php endif; ?></div>
<?php admin_foot(); ?>
