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

$totalUsers = 0;
$avgRating = 0;
$reviewCount = 0;
$reviews = [];
if ($botId) {
    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE bot_id=$botId")->fetchColumn();
    $avgRating = (float)$pdo->query("SELECT COALESCE(AVG(rating),0) FROM reviews WHERE bot_id=$botId AND status='approved'")->fetchColumn();
    $reviewCount = (int)$pdo->query("SELECT COUNT(*) FROM reviews WHERE bot_id=$botId AND status='approved'")->fetchColumn();
    $st = $pdo->prepare(
        "SELECT r.rating, r.text AS comment, p.name AS product_name, u.username
           FROM reviews r JOIN products p ON p.id=r.product_id JOIN users u ON u.id=r.user_id
          WHERE r.bot_id=? AND r.status='approved' ORDER BY r.created_at DESC LIMIT 6"
    );
    $st->execute([$botId]);
    $reviews = $st->fetchAll();
}
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

<div class="hero-split">
<div class="hero-text">
<h1>AI tools, accounts &amp; credits.<br><span class="grad-text">Instantly delivered.</span></h1>
<p class="lead">ChatGPT, Claude, Netflix, Spotify aur 170+ premium products, aik hi jagah. Telegram bot kholo, buy karo, seconds me delivery pao.</p>
<div class="hero-cta">
<?php if ($tgLink): ?><a class="btn btn-green" href="<?= esc($tgLink) ?>">Open in Telegram ↗</a><?php else: ?><span class="badge warn">Bot is being connected…</span><?php endif; ?>
<a class="btn btn2" href="#all">Browse products ↓</a>
</div>
<div class="trust-row">
<span>⚡ Instant auto delivery</span>
<span>🔒 Secure crypto &amp; points payments</span>
<span>🛡️ Warranty on every order</span>
</div>
<div class="row" style="margin:20px 0 6px">
<div class="stat"><b><?= (int)$totalProducts ?></b><span>Products</span></div>
<div class="stat"><b><?= count($cats) ?></b><span>Categories</span></div>
<?php if ($totalUsers > 0): ?><div class="stat"><b><?= (int)$totalUsers ?></b><span>Users</span></div><?php endif; ?>
<?php if ($reviewCount > 0): ?><div class="stat"><b><?= esc(number_format($avgRating, 1)) ?> ★</b><span><?= (int)$reviewCount ?> reviews</span></div><?php endif; ?>
<div class="stat"><b>$1 = 10 pts</b><span>Points rate</span></div>
</div>
</div>
<div class="hero-art">
<img src="assets/banner.gif" alt="ToolsByDcx" onerror="this.style.display='none'">
<div class="float-card c1">⚡ Instant delivery<small>Average under 1 minute</small></div>
<div class="float-card c2">💎 <?= (int)$totalProducts ?> products<small>Across <?= count($cats) ?> categories</small></div>
</div>
</div>

<h2 class="cat" style="margin-top:34px">How it works</h2>
<div class="steps">
<div class="step"><div class="num">1</div><h3>Open the bot</h3><p>Telegram par <?= esc($botName) ?> bot kholo aur Shop Now par tap karo.</p></div>
<div class="step"><div class="num">2</div><h3>Buy with points or crypto</h3><p>Points, Binance Pay, USDT ya Telegram Stars se pay karo, 15 minute payment window.</p></div>
<div class="step"><div class="num">3</div><h3>Get it instantly</h3><p>Payment confirm hote hi account ya code chat me auto deliver ho jata hai.</p></div>
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

<?php if ($reviews): ?>
<h2 class="cat">What buyers say</h2>
<div class="steps">
<?php foreach ($reviews as $r): ?>
<div class="review-card">
<div class="stars"><?= str_repeat('★', max(1, min(5, (int)$r['rating']))) ?><?= str_repeat('☆', 5 - max(1, min(5, (int)$r['rating']))) ?></div>
<p><?= esc($r['comment'] !== '' ? $r['comment'] : 'Great product, instant delivery.') ?></p>
<div class="who">@<?= esc($r['username'] ?: 'buyer') ?> · <?= esc($r['product_name']) ?></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($tgLink): ?>
<div class="cta-banner">
<h2>Ready to shop?</h2>
<p>Open the bot and grab your favourite tool in under a minute.</p>
<a class="btn" href="<?= esc($tgLink) ?>">Open <?= esc($botName) ?> in Telegram ↗</a>
</div>
<?php endif; ?>

<footer>
<a href="admin/">Admin Panel</a> · <a href="api-docs/">Reseller API Docs</a><?php if ($tgLink): ?> · <a href="<?= esc($tgLink) ?>">Telegram Bot</a><?php endif; ?><br><br>
<?= esc($botName) ?> · Pay via Binance Pay — Auto Delivery
</footer>
<button class="back-to-top" id="toTop" title="Back to top" aria-label="Back to top">↑</button>
<script>
(function(){var b=document.getElementById('toTop');if(!b)return;window.addEventListener('scroll',function(){b.classList.toggle('show',window.scrollY>300);},{passive:true});b.addEventListener('click',function(){window.scrollTo({top:0,behavior:'smooth'});});})();
</script>
</div></body></html>
