<?php
// Shared admin layout — bunny.net USER dashboard style: clean light theme,
// white cards, grouped left sidebar, top bar with search + user, orange accent.
function admin_nav(): array {
    return [
        'Store' => [
            'dashboard'  => '📊 Dashboard',
            'bots'       => '🤖 Bots',
        ],
        'Catalogue' => [
            'categories' => '📁 Categories',
            'products'   => '🛍️ Products',
            'stock'      => '📦 Stock',
            'freebies'   => '🎁 Freebies',
        ],
        'Sales' => [
            'orders'     => '🧾 Orders',
            'users'      => '👥 Users',
            'deposits'   => '💳 Deposits',
            'referrals'  => '🎁 Referrals',
        ],
        'Support' => [
            'tickets'    => '🎫 Tickets',
            'warranty'   => '🛡️ Warranty',
            'reviews'    => '⭐ Reviews',
        ],
        'Configure' => [
            'loyalty'    => '🏆 Loyalty',
            'languages'  => '🌐 Languages',
            'texts'      => '✏️ Texts',
            'payments'   => '💰 Payments',
            'apikeys'    => '🔑 API Keys',
            'broadcasts' => '📣 Broadcasts',
            'settings'   => '⚙️ Settings',
        ],
    ];
}

function admin_head(string $title, string $page, int $botId, array $bots): void {
    $nav = admin_nav();
    $bot = null;
    foreach ($bots as $b) if ((int)$b['id'] === $botId) $bot = $b;
    $botLabel = $bot ? $bot['name'] . (!empty($bot['username']) ? ' (@' . $bot['username'] . ')' : '') : 'No bot yet';
    ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title) ?> — ToolsByDcx Admin</title>
<link rel="stylesheet" href="../assets/site.css?v=3"></head>
<body><div class="wrap">
<nav class="side" id="sidebar"><h1><a href="index.php?page=dashboard" title="Dashboard">🛒 Tools<span>ByDcx</span></a></h1>
<?php foreach ($nav as $group => $links): ?>
<h4><?= esc($group) ?></h4>
<?php foreach ($links as $k => $label): ?>
<a href="index.php?page=<?= esc($k) ?>" class="<?= $page === $k ? 'on' : '' ?>" data-nav="<?= esc($label) ?>"><?= esc($label) ?></a>
<?php endforeach; ?>
<?php endforeach; ?>
<h4>Account</h4>
<a href="../" target="_blank" rel="noopener" title="Open the public store landing page">🌐 View Store ↗</a>
<a href="index.php?page=logout" class="logout">🚪 Logout (<?= esc($_SESSION['admin_username'] ?? 'admin') ?>)</a>
</nav>
<main class="main">
<div class="top"><h2><?= esc($title) ?></h2>
<div class="tools">
<div class="search"><span>🔍</span><input type="text" id="navsearch" placeholder="Search menu…" oninput="filterNav(this.value)"></div>
<form method="get" action="index.php">
<input type="hidden" name="page" value="<?= esc($page) ?>">
<select name="bot" onchange="this.form.submit()" title="Active bot">
<?php foreach ($bots as $b): ?>
<option value="<?= (int)$b['id'] ?>" <?= (int)$b['id'] === $botId ? 'selected' : '' ?>><?= esc($b['name']) ?> (@<?= esc($b['username'] ?: '—') ?>)<?= $b['active'] ? '' : ' [off]' ?></option>
<?php endforeach; ?>
</select>
<noscript><button type="submit">Switch</button></noscript>
</form>
<div class="userchip"><span class="av"><?= esc(strtoupper(substr((string)($_SESSION['admin_username'] ?? 'A'), 0, 1))) ?></span><?= esc($_SESSION['admin_username'] ?? 'admin') ?></div>
<a class="btn btn2 btn-sm" href="../" target="_blank" rel="noopener" title="Open the public store landing page">🌐 View Store</a>
</div></div>
<script>
function filterNav(q){q=q.toLowerCase();document.querySelectorAll('.side a[data-nav]').forEach(function(a){a.style.display=a.dataset.nav.toLowerCase().includes(q)?'':'none';});document.querySelectorAll('.side h4').forEach(function(h){var nxt=h.nextElementSibling,any=false;while(nxt&&nxt.tagName!=='H4'){if(nxt.style.display!=='none')any=true;nxt=nxt.nextElementSibling;}h.style.display=any?'':'none';});}
</script>
<?php foreach (take_flash() as $f): ?>
<div class="flash <?= esc($f['type']) ?>"><?= esc($f['msg']) ?></div>
<?php endforeach; ?>
<?php
}

function admin_foot(): void {
    echo '<script>(function(){var s=document.getElementById("sidebar");if(!s)return;var k="tdcx-side-scroll";try{var y=sessionStorage.getItem(k);if(y!==null)s.scrollTop=parseInt(y,10)||0;}catch(e){}s.addEventListener("scroll",function(){try{sessionStorage.setItem(k,String(s.scrollTop));}catch(e){}},{passive:true});var on=s.querySelector("a.on");if(on){var r=on.getBoundingClientRect(),sr=s.getBoundingClientRect();if(r.top<sr.top||r.bottom>sr.bottom){on.scrollIntoView({block:"nearest"});}}})();</script></main></div></body></html>';
}
