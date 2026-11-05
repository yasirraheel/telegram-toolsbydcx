<?php
// Dashboard: every stat that matters for a store platform — sales, users,
// catalogue, money & points, engagement, 7-day trend, top products/customers.
admin_head('Dashboard', 'dashboard', $botId, $bots);
if (!$bot) { echo '<div class="card">No bots yet. Go to <a href="index.php?page=bots">Bots</a> and add your first bot token.</div>'; admin_foot(); return; }

$one = function (string $sql, array $args = []) use ($pdo) {
    $st = $pdo->prepare($sql); $st->execute($args);
    return $st->fetchColumn();
};
$all = function (string $sql, array $args = []) use ($pdo) {
    $st = $pdo->prepare($sql); $st->execute($args);
    return $st->fetchAll();
};

// ---- Sales ----
$revTotal  = (float)$one("SELECT COALESCE(SUM(total_usd),0) FROM orders WHERE bot_id=? AND status='delivered'", [$botId]);
$revToday  = (float)$one("SELECT COALESCE(SUM(total_usd),0) FROM orders WHERE bot_id=? AND status='delivered' AND DATE(created_at)=CURDATE()", [$botId]);
$rev7      = (float)$one("SELECT COALESCE(SUM(total_usd),0) FROM orders WHERE bot_id=? AND status='delivered' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)", [$botId]);
$rev30     = (float)$one("SELECT COALESCE(SUM(total_usd),0) FROM orders WHERE bot_id=? AND status='delivered' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)", [$botId]);
$refunded  = (float)$one("SELECT COALESCE(SUM(total_usd),0) FROM orders WHERE bot_id=? AND status='refunded'", [$botId]);
$ordersAll = (int)$one("SELECT COUNT(*) FROM orders WHERE bot_id=?", [$botId]);
$ordersToday = (int)$one("SELECT COUNT(*) FROM orders WHERE bot_id=? AND DATE(created_at)=CURDATE()", [$botId]);
$orders7   = (int)$one("SELECT COUNT(*) FROM orders WHERE bot_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)", [$botId]);
$orders30  = (int)$one("SELECT COUNT(*) FROM orders WHERE bot_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)", [$botId]);
$ordDeliv  = (int)$one("SELECT COUNT(*) FROM orders WHERE bot_id=? AND status='delivered'", [$botId]);
$ordPend   = (int)$one("SELECT COUNT(*) FROM orders WHERE bot_id=? AND status='pending'", [$botId]);
$ordRef    = (int)$one("SELECT COUNT(*) FROM orders WHERE bot_id=? AND status='refunded'", [$botId]);
$ordCanc   = (int)$one("SELECT COUNT(*) FROM orders WHERE bot_id=? AND status='cancelled'", [$botId]);
$aov       = $ordDeliv > 0 ? $revTotal / $ordDeliv : 0;

// ---- Users ----
$usersAll  = (int)$one("SELECT COUNT(*) FROM users WHERE bot_id=?", [$botId]);
$usersToday = (int)$one("SELECT COUNT(*) FROM users WHERE bot_id=? AND DATE(joined_at)=CURDATE()", [$botId]);
$users7    = (int)$one("SELECT COUNT(*) FROM users WHERE bot_id=? AND joined_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)", [$botId]);
$users30   = (int)$one("SELECT COUNT(*) FROM users WHERE bot_id=? AND joined_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)", [$botId]);
$usersActive7 = (int)$one("SELECT COUNT(*) FROM users WHERE bot_id=? AND last_active>=DATE_SUB(NOW(),INTERVAL 7 DAY)", [$botId]);
$usersBanned  = (int)$one("SELECT COUNT(*) FROM users WHERE bot_id=? AND banned=1", [$botId]);
$buyers    = (int)$one("SELECT COUNT(DISTINCT user_id) FROM orders WHERE bot_id=?", [$botId]);
$conv      = $usersAll > 0 ? round(100 * $buyers / $usersAll, 1) : 0;

// ---- Catalogue ----
$prodActive = (int)$one("SELECT COUNT(*) FROM products WHERE bot_id=? AND active=1", [$botId]);
$catCount   = (int)$one("SELECT COUNT(*) FROM categories WHERE bot_id=? AND active=1", [$botId]);
$prodOOS    = (int)$one("SELECT COUNT(*) FROM products WHERE bot_id=? AND active=1 AND status='out_of_stock'", [$botId]);
$stockAvail = (int)$one("SELECT COUNT(*) FROM stock_items WHERE bot_id=? AND status='available'", [$botId]);
$stockSold  = (int)$one("SELECT COUNT(*) FROM stock_items WHERE bot_id=? AND status='sold'", [$botId]);

// ---- Money & points ----
$depConfirmed = (float)$one("SELECT COALESCE(SUM(amount_usd),0) FROM deposits WHERE bot_id=? AND status='confirmed'", [$botId]);
$depPending   = (int)$one("SELECT COUNT(*) FROM deposits WHERE bot_id=? AND status='pending'", [$botId]);
$depFailed    = (int)$one("SELECT COUNT(*) FROM deposits WHERE bot_id=? AND status='failed'", [$botId]);
$ptsOut       = (float)$one("SELECT COALESCE(SUM(points_wallet),0) FROM users WHERE bot_id=?", [$botId]);
$ptsIssued    = (float)$one("SELECT COALESCE(SUM(delta),0) FROM points_ledger WHERE bot_id=? AND delta>0", [$botId]);
$refPts       = (float)$one("SELECT COALESCE(SUM(points_awarded),0) FROM referrals WHERE bot_id=? AND status='approved'", [$botId]);

// ---- Engagement / support ----
$refsAll   = (int)$one("SELECT COUNT(*) FROM referrals WHERE bot_id=?", [$botId]);
$refsOk    = (int)$one("SELECT COUNT(*) FROM referrals WHERE bot_id=? AND status='approved'", [$botId]);
$freeClaims = (int)$one("SELECT COUNT(*) FROM freebie_claims WHERE bot_id=?", [$botId]);
$revCount  = (int)$one("SELECT COUNT(*) FROM reviews WHERE bot_id=? AND status='approved'", [$botId]);
$revAvg    = (float)$one("SELECT COALESCE(AVG(rating),0) FROM reviews WHERE bot_id=? AND status='approved'", [$botId]);
$apiKeys   = (int)$one("SELECT COUNT(*) FROM reseller_api_keys WHERE bot_id=?", [$botId]);
$tickOpen  = (int)$one("SELECT COUNT(*) FROM support_tickets WHERE bot_id=? AND status='open'", [$botId]);
$tickClosed = (int)$one("SELECT COUNT(*) FROM support_tickets WHERE bot_id=? AND status='closed'", [$botId]);
$warPend   = (int)$one("SELECT COUNT(*) FROM warranty_claims WHERE bot_id=? AND status='pending'", [$botId]);
$revPend   = (int)$one("SELECT COUNT(*) FROM reviews WHERE bot_id=? AND status='pending'", [$botId]);

// ---- 7-day trend ----
$trend = $all(
    "SELECT DATE(created_at) d, COUNT(*) orders, COALESCE(SUM(CASE WHEN status='delivered' THEN total_usd ELSE 0 END),0) revenue
       FROM orders WHERE bot_id=? AND created_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY)
      GROUP BY DATE(created_at) ORDER BY d ASC", [$botId]);
$trendByDay = [];
foreach ($trend as $t) $trendByDay[$t['d']] = $t;
$days = [];
for ($i = 6; $i >= 0; $i--) $days[] = date('Y-m-d', strtotime("-$i days"));
$maxRev = 1;
foreach ($days as $d) $maxRev = max($maxRev, (float)($trendByDay[$d]['revenue'] ?? 0));

$low = $all(
    "SELECT p.id, p.name, p.price_usd,
            (SELECT COUNT(*) FROM stock_items s WHERE s.product_id=p.id AND s.status='available') AS stock
       FROM products p WHERE p.bot_id=? AND p.active=1
      HAVING stock < 5 ORDER BY stock ASC LIMIT 20", [$botId]);

$recent = $all(
    "SELECT o.*, p.name AS product_name, u.username, u.telegram_id
       FROM orders o JOIN products p ON p.id=o.product_id
       JOIN users u ON u.id=o.user_id
      WHERE o.bot_id=? ORDER BY o.created_at DESC LIMIT 15", [$botId]);

$topProducts = $all(
    "SELECT p.name, COUNT(o.id) orders, COALESCE(SUM(o.total_usd),0) revenue
       FROM orders o JOIN products p ON p.id=o.product_id
      WHERE o.bot_id=? AND o.status='delivered' GROUP BY p.id ORDER BY revenue DESC LIMIT 5", [$botId]);

$topCustomers = $all(
    "SELECT username, telegram_id, total_spent, total_orders FROM users
      WHERE bot_id=? ORDER BY total_spent DESC LIMIT 5", [$botId]);

// ---- Charts data ----
$revByCat = $all(
    "SELECT c.name, COALESCE(SUM(o.total_usd),0) revenue, COUNT(o.id) orders
       FROM orders o JOIN products p ON p.id=o.product_id JOIN categories c ON c.id=p.category_id
      WHERE o.bot_id=? AND o.status='delivered' GROUP BY c.id ORDER BY revenue DESC LIMIT 6", [$botId]);
$maxCatRev = 1;
foreach ($revByCat as $r) $maxCatRev = max($maxCatRev, (float)$r['revenue']);
$prodByCat = $all(
    "SELECT c.name, COUNT(p.id) n FROM categories c LEFT JOIN products p ON p.id IS NOT NULL AND p.bot_id=c.bot_id AND p.category_id=c.id AND p.active=1
      WHERE c.bot_id=? AND c.active=1 GROUP BY c.id ORDER BY n DESC LIMIT 6", [$botId]);
// Donut: orders by status
$donut = [
    ['label' => 'Delivered', 'n' => $ordDeliv, 'color' => '#16a34a'],
    ['label' => 'Pending',   'n' => $ordPend,  'color' => '#d97706'],
    ['label' => 'Refunded',  'n' => $ordRef,   'color' => '#2563eb'],
    ['label' => 'Cancelled', 'n' => $ordCanc,  'color' => '#dc2626'],
];
$donutTotal = max(1, $ordDeliv + $ordPend + $ordRef + $ordCanc);
$C = 2 * M_PI * 60; // r=60
$donutOff = 0;
?>
<h3 style="margin:4px 0 10px">💰 Sales</h3>
<div class="grid">
<div class="stat"><b class="acc">$<?= esc(fmt_usd($revToday)) ?></b><span>Revenue today</span></div>
<div class="stat"><b>$<?= esc(fmt_usd($rev7)) ?></b><span>Revenue · last 7 days</span></div>
<div class="stat"><b>$<?= esc(fmt_usd($rev30)) ?></b><span>Revenue · last 30 days</span></div>
<div class="stat"><b>$<?= esc(fmt_usd($revTotal)) ?></b><span>Total revenue (delivered)</span></div>
<div class="stat"><b>$<?= esc(fmt_usd($refunded)) ?></b><span>Refunded</span></div>
<div class="stat"><b>$<?= esc(fmt_usd($aov)) ?></b><span>Avg order value</span></div>
<div class="stat"><b><?= (int)$ordersToday ?></b><span>Orders today</span></div>
<div class="stat"><b><?= (int)$orders7 ?></b><span>Orders · 7 days</span></div>
<div class="stat"><b><?= (int)$orders30 ?></b><span>Orders · 30 days</span></div>
<div class="stat"><b><?= (int)$ordersAll ?></b><span>Total orders</span></div>
<div class="stat"><b><?= (int)$ordDeliv ?></b><span>Delivered</span></div>
<div class="stat"><b><?= (int)$ordPend ?></b><span>Pending orders</span></div>
<div class="stat"><b><?= (int)$ordRef ?></b><span>Refunded orders</span></div>
<div class="stat"><b><?= (int)$ordCanc ?></b><span>Cancelled orders</span></div>
</div>

<h3 style="margin:4px 0 10px">👥 Users</h3>
<div class="grid">
<div class="stat"><b><?= (int)$usersAll ?></b><span>Total users</span></div>
<div class="stat"><b><?= (int)$usersToday ?></b><span>New users today</span></div>
<div class="stat"><b><?= (int)$users7 ?></b><span>New users · 7 days</span></div>
<div class="stat"><b><?= (int)$users30 ?></b><span>New users · 30 days</span></div>
<div class="stat"><b><?= (int)$usersActive7 ?></b><span>Active users · 7 days</span></div>
<div class="stat"><b><?= (int)$buyers ?></b><span>Buyers</span></div>
<div class="stat"><b><?= esc((string)$conv) ?>%</b><span>Conversion rate</span></div>
<div class="stat"><b><?= (int)$usersBanned ?></b><span>Banned users</span></div>
</div>

<h3 style="margin:4px 0 10px">🛍️ Catalogue &amp; stock</h3>
<div class="grid">
<div class="stat"><b><?= (int)$prodActive ?></b><span>Active products</span></div>
<div class="stat"><b><?= (int)$catCount ?></b><span>Categories</span></div>
<div class="stat"><b><?= (int)$prodOOS ?></b><span>Out-of-stock products</span></div>
<div class="stat"><b><?= (int)$stockAvail ?></b><span>Stock items available</span></div>
<div class="stat"><b><?= (int)$stockSold ?></b><span>Stock items sold</span></div>
</div>

<h3 style="margin:4px 0 10px">💳 Money &amp; points</h3>
<div class="grid">
<div class="stat"><b>$<?= esc(fmt_usd($depConfirmed)) ?></b><span>Deposits confirmed</span></div>
<div class="stat"><b><?= (int)$depPending ?></b><span>Pending deposits</span></div>
<div class="stat"><b><?= (int)$depFailed ?></b><span>Failed deposits</span></div>
<div class="stat"><b><?= esc(fmt_points($ptsOut)) ?></b><span>Points outstanding (wallets)</span></div>
<div class="stat"><b><?= esc(fmt_points($ptsIssued)) ?></b><span>Points issued</span></div>
<div class="stat"><b><?= esc(fmt_points($refPts)) ?></b><span>Referral points given</span></div>
</div>

<h3 style="margin:4px 0 10px">⭐ Engagement &amp; support</h3>
<div class="grid">
<div class="stat"><b><?= (int)$refsAll ?></b><span>Total referrals</span></div>
<div class="stat"><b><?= (int)$refsOk ?></b><span>Approved referrals</span></div>
<div class="stat"><b><?= (int)$freeClaims ?></b><span>Freebie claims</span></div>
<div class="stat"><b><?= esc(number_format($revAvg, 1)) ?> ★</b><span>Avg rating (<?= (int)$revCount ?> reviews)</span></div>
<div class="stat"><b><?= (int)$apiKeys ?></b><span>Reseller API keys</span></div>
<div class="stat"><b><?= (int)$tickOpen ?></b><span>Open tickets</span></div>
<div class="stat"><b><?= (int)$tickClosed ?></b><span>Closed tickets</span></div>
<div class="stat"><b><?= (int)$warPend ?></b><span>Pending warranty</span></div>
<div class="stat"><b><?= (int)$revPend ?></b><span>Pending reviews</span></div>
</div>

<div class="chart-row">
<div class="chart-card"><h3>🍩 Orders by status</h3>
<div class="donut-wrap">
<svg class="donut" viewBox="0 0 150 150">
<circle cx="75" cy="75" r="60" stroke="#eef0f3"></circle>
<?php foreach ($donut as $seg): $len = $seg['n'] > 0 ? $C * $seg['n'] / $donutTotal : 0; if ($len <= 0) continue; ?>
<circle class="seg" cx="75" cy="75" r="60" stroke="<?= esc($seg['color']) ?>" stroke-dashoffset="<?= esc(number_format(-$donutOff, 1)) ?>" style="--seg: <?= esc(number_format($len, 1)) ?> <?= esc(number_format($C, 1)) ?>" transform="rotate(-90 75 75)"></circle>
<?php $donutOff += $len; endforeach; ?>
<circle class="hole" cx="75" cy="75" r="42"></circle>
<text x="75" y="72" text-anchor="middle"><?= (int)$ordersAll ?></text>
<text x="75" y="92" text-anchor="middle" style="font-size:11px;fill:var(--mut);font-weight:600">ORDERS</text>
</svg>
<ul class="legend">
<?php foreach ($donut as $seg): ?>
<li><span class="dot" style="background:<?= esc($seg['color']) ?>"></span><?= esc($seg['label']) ?><b><?= (int)$seg['n'] ?> (<?= esc((string)round(100 * $seg['n'] / $donutTotal)) ?>%)</b></li>
<?php endforeach; ?>
</ul>
</div></div>

<div class="chart-card"><h3>📊 Revenue by category</h3>
<?php if (!$revByCat): ?><p class="mut">No delivered orders yet.</p>
<?php else: foreach ($revByCat as $r): $w = round(100 * (float)$r['revenue'] / $maxCatRev); ?>
<div class="bar-row"><div class="lbl"><span><?= esc($r['name']) ?></span><b>$<?= esc(fmt_usd($r['revenue'])) ?> · <?= (int)$r['orders'] ?> orders</b></div>
<div class="bar-track"><div class="bar-fill" data-w="<?= (int)$w ?>"></div></div></div>
<?php endforeach; endif; ?>
<h3 style="margin-top:20px">🗂️ Products per category</h3>
<?php foreach ($prodByCat as $r): $w = $prodByCat ? round(100 * (int)$r['n'] / max(1, (int)$prodByCat[0]['n'])) : 0; ?>
<div class="bar-row"><div class="lbl"><span><?= esc($r['name']) ?></span><b><?= (int)$r['n'] ?> products</b></div>
<div class="bar-track"><div class="bar-fill blue" data-w="<?= (int)$w ?>"></div></div></div>
<?php endforeach; ?>
</div>
</div>

<div class="card"><h3 style="margin-top:0">📈 Last 7 days — revenue</h3>
<div class="bars7">
<?php foreach ($days as $d): $r = (float)($trendByDay[$d]['revenue'] ?? 0); $o = (int)($trendByDay[$d]['orders'] ?? 0); $h = max(4, round(100 * $r / $maxRev)); ?>
<div class="col"><div class="bar" data-h="<?= (int)$h ?>" title="$<?= esc(fmt_usd($r)) ?> · <?= (int)$o ?> orders"></div><div class="cap"><?= esc(date('D', strtotime($d))) ?><br>$<?= esc(fmt_usd($r)) ?></div></div>
<?php endforeach; ?>
</div></div>

<script>
document.querySelectorAll('.bar-fill').forEach(function(b){requestAnimationFrame(function(){b.style.width=b.dataset.w+'%';});});
document.querySelectorAll('.bars7 .bar').forEach(function(b){requestAnimationFrame(function(){b.style.height=b.dataset.h+'px';});});
</script>

<div class="card"><h3 style="margin-top:0">🏆 Top products (by revenue)</h3>
<?php if (!$topProducts): ?><p class="mut">No delivered orders yet.</p>
<?php else: ?><table><tr><th>Product</th><th>Orders</th><th>Revenue</th></tr>
<?php foreach ($topProducts as $t): ?><tr><td><?= esc($t['name']) ?></td><td><?= (int)$t['orders'] ?></td><td>$<?= esc(fmt_usd($t['revenue'])) ?></td></tr><?php endforeach; ?></table><?php endif; ?></div>

<div class="card"><h3 style="margin-top:0">💎 Top customers (by spend)</h3>
<?php if (!$topCustomers): ?><p class="mut">No customers yet.</p>
<?php else: ?><table><tr><th>Customer</th><th>Orders</th><th>Total spent</th></tr>
<?php foreach ($topCustomers as $u): ?><tr><td>@<?= esc($u['username'] ?: (string)$u['telegram_id']) ?></td><td><?= (int)$u['total_orders'] ?></td><td>$<?= esc(fmt_usd($u['total_spent'])) ?></td></tr><?php endforeach; ?></table><?php endif; ?></div>

<div class="card"><h3 style="margin-top:0">⚠️ Low stock (under 5 available)</h3>
<?php if (!$low): ?><p class="mut">All active products have 5+ items in stock.</p>
<?php else: ?><table><tr><th>Product</th><th>Price</th><th>Stock</th><th></th></tr>
<?php foreach ($low as $r): ?><tr>
<td><?= esc($r['name']) ?></td><td>$<?= esc(fmt_usd($r['price_usd'])) ?></td>
<td><span class="badge <?= (int)$r['stock'] === 0 ? 'bad' : 'warn' ?>"><?= (int)$r['stock'] ?></span></td>
<td><a href="index.php?page=stock&amp;product_id=<?= (int)$r['id'] ?>">Add stock →</a></td></tr>
<?php endforeach; ?></table><?php endif; ?></div>

<div class="card"><h3 style="margin-top:0">🧾 Recent orders</h3>
<?php if (!$recent): ?><p class="mut">No orders yet for this bot.</p>
<?php else: ?><table><tr><th>#</th><th>Product</th><th>User</th><th>Qty</th><th>Total</th><th>Status</th><th>When</th></tr>
<?php foreach ($recent as $o): ?><tr>
<td><a href="index.php?page=orders&amp;id=<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?></a></td>
<td><?= esc($o['product_name']) ?></td>
<td>@<?= esc($o['username'] ?: (string)$o['telegram_id']) ?></td>
<td><?= (int)$o['qty'] ?></td><td>$<?= esc(fmt_usd($o['total_usd'])) ?></td>
<td><span class="badge <?= $o['status'] === 'delivered' ? 'ok' : ($o['status'] === 'pending' ? 'warn' : 'bad') ?>"><?= esc($o['status']) ?></span></td>
<td class="mut"><?= esc($o['created_at']) ?></td></tr>
<?php endforeach; ?></table><?php endif; ?></div>
<?php admin_foot(); ?>
