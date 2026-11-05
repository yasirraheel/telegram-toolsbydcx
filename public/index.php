<?php
// ToolsByDcx — public store landing page. Lists every product added in the
// admin panel, grouped by category, for the active bot. Orders happen in Telegram.
require_once __DIR__ . '/../lib/helpers.php';

$pdo = db();
$bot = $pdo->query("SELECT * FROM bots WHERE active=1 ORDER BY id ASC LIMIT 1")->fetch()
    ?: $pdo->query("SELECT * FROM bots ORDER BY id ASC LIMIT 1")->fetch();

$botId = $bot ? (int)$bot['id'] : 0;
$botName = $bot ? $bot['name'] : 'ToolsByDcx';
$botUser = $bot ? trim((string)$bot['username']) : '';
$tgLink = $botUser !== '' ? 'https://t.me/' . $botUser : '';

$cats = [];
$products = [];
$totalProducts = 0;
if ($botId) {
    $st = $pdo->prepare("SELECT * FROM categories WHERE bot_id=? AND active=1 ORDER BY sort ASC, name ASC");
    $st->execute([$botId]);
    $cats = $st->fetchAll();
    $st = $pdo->prepare(
        "SELECT p.*, (SELECT COUNT(*) FROM stock_items s WHERE s.product_id=p.id AND s.status='available') AS stock
           FROM products p WHERE p.bot_id=? AND p.active=1 ORDER BY p.category_id, p.sort ASC, p.name ASC"
    );
    $st->execute([$botId]);
    $products = $st->fetchAll();
    $totalProducts = count($products);
}
$byCat = [];
foreach ($products as $p) $byCat[(int)$p['category_id']][] = $p;
$catName = [];
foreach ($cats as $c) $catName[(int)$c['id']] = $c;
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($botName) ?> — Telegram Store</title>
<link rel="stylesheet" href="assets/site.css"></head>
<body><div class="store">
<header>
<a class="logo" href="/" title="Home">🛒 Tools<span>ByDcx</span></a>
<nav class="nav"><a href="admin/">Admin Panel</a><a href="api-docs/">Reseller API</a><?php if ($tgLink): ?><a href="<?= esc($tgLink) ?>">Telegram Bot ↗</a><?php endif; ?></nav>
</header>

<div class="hero">
<img src="assets/banner.gif" alt="ToolsByDcx" onerror="this.style.display='none'">
<h1>Automated digital marketplace</h1>
<p>AI productivity tools, creative studio software, and online learning. Shop inside Telegram, instant auto delivery.</p>
<?php if ($tgLink): ?><a class="btn btn-green" href="<?= esc($tgLink) ?>">Open in Telegram ↗</a><?php else: ?><p class="empty">The Telegram bot is being connected. Check back soon.</p><?php endif; ?>
<div class="row" style="justify-content:center;margin:20px 0 6px">
<div class="stat"><b><?= (int)$totalProducts ?></b><span>Products</span></div>
<div class="stat"><b><?= count($cats) ?></b><span>Categories</span></div>
<div class="stat"><b>$1 = 10 pts</b><span>Points rate</span></div>
</div>
</div>

<?php if ($totalProducts): ?>
<div class="search" style="margin:22px 0 8px"><input type="text" id="q" style="width:100%" placeholder="🔍 Search products…" oninput="filterProducts(this.value)"></div>
<div class="chips"><a href="#all">All</a>
<?php foreach ($cats as $c): if (empty($byCat[(int)$c['id']])) continue; ?>
<a href="#cat-<?= (int)$c['id'] ?>"><?= esc($c['emoji']) ?> <?= esc($c['name']) ?></a>
<?php endforeach; ?></div>

<div id="all">
<?php foreach ($cats as $c): $list = $byCat[(int)$c['id']] ?? []; if (!$list) continue; ?>
<h2 class="cat" id="cat-<?= (int)$c['id'] ?>"><?= esc($c['emoji']) ?> <?= esc($c['name']) ?></h2>
<div class="shop-grid">
<?php foreach ($list as $p): $inStock = $p['status'] === 'active' && (int)$p['stock'] > 0; ?>
<div class="shop-card" data-name="<?= esc(strtolower($p['name'])) ?>">
<h3><?= esc($p['name']) ?></h3>
<div class="price">$<?= esc(fmt_usd($p['price_usd'])) ?> <small>· Rs. <?= esc(pkr_of($botId, $p['price_usd'])) ?></small></div>
<div><span class="badge <?= $inStock ? 'ok' : 'bad' ?>"><?= $inStock ? 'In stock (' . (int)$p['stock'] . ')' : 'Out of stock' ?></span></div>
<?php if ($tgLink): ?><a class="buy <?= $inStock ? '' : 'off' ?>" href="<?= esc($tgLink) ?>"><?= $inStock ? 'Buy in Telegram →' : 'Notify me in Telegram' ?></a><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
</div>
<script>
function filterProducts(q){q=q.toLowerCase();document.querySelectorAll('.shop-card[data-name]').forEach(function(c){c.style.display=c.dataset.name.includes(q)?'':'none';});document.querySelectorAll('h2.cat').forEach(function(h){var g=h.nextElementSibling,any=false;if(g){g.querySelectorAll('.shop-card').forEach(function(c){if(c.style.display!=='none')any=true;});}h.style.display=any?'':'none';if(g)g.style.display=any?'':'none';});}
</script>
<?php else: ?>
<p class="empty">No products listed yet. Products added in the admin panel will appear here automatically.</p>
<?php endif; ?>

<footer>
<a href="admin/">Admin Panel</a> · <a href="api-docs/">Reseller API Docs</a><?php if ($tgLink): ?> · <a href="<?= esc($tgLink) ?>">Telegram Bot</a><?php endif; ?><br><br>
<?= esc($botName) ?> · Pay via Binance Pay — Auto Delivery
</footer>
</div></body></html>
