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
<style>
:root{--bg:#f4f5f7;--card:#ffffff;--line:#e5e7eb;--line2:#eef0f3;--txt:#1f2937;--mut:#6b7280;--acc:#ff6622;--acc-d:#e6551a;--acc-soft:#fff1e8;--ok:#16a34a;--ok-soft:#dcfce7;--warn:#b45309;--warn-soft:#fef3c7;--bad:#dc2626;--bad-soft:#fee2e2;--info:#2563eb;--info-soft:#dbeafe}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--txt);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
a{color:var(--acc);text-decoration:none}a:hover{text-decoration:underline}
.wrap{display:flex;min-height:100vh}
.side{width:230px;background:#fff;border-right:1px solid var(--line);padding:14px 10px;position:sticky;top:0;height:100vh;overflow:auto;flex-shrink:0}
.side h1{font-size:17px;margin:6px 10px 18px;color:var(--txt)}.side h1 span{color:var(--acc)}
.side h4{margin:16px 12px 6px;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--mut);font-weight:600}
.side a{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;color:#374151;margin:1px 0}
.side a:hover{background:#f3f4f6;text-decoration:none}
.side a.on{background:var(--acc-soft);color:var(--acc);font-weight:600}
.side .logout{color:var(--mut)}
.main{flex:1;padding:0 24px 40px;min-width:0}
.top{display:flex;gap:16px;align-items:center;justify-content:space-between;padding:16px 0;margin-bottom:18px;flex-wrap:wrap}
.top h2{margin:0;font-size:20px;font-weight:700}
.top .tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.search{position:relative}.search input{width:220px;padding:8px 12px 8px 34px;border:1px solid var(--line);border-radius:8px;background:#fff;font:inherit}
.search span{position:absolute;left:10px;top:8px;color:var(--mut)}
.top form{display:flex;gap:8px;align-items:center}
.userchip{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--line);border-radius:20px;padding:5px 12px 5px 6px;font-weight:600}
.userchip .av{width:26px;height:26px;border-radius:50%;background:var(--acc);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px}
select,input[type=text],input[type=number],input[type=password],input[type=url],textarea{width:100%;background:#fff;border:1px solid #d1d5db;color:var(--txt);border-radius:8px;padding:8px 10px;font:inherit}
select{width:auto}textarea{min-height:90px;resize:vertical}
input:focus,select:focus,textarea:focus{outline:2px solid var(--acc-soft);border-color:var(--acc)}
label{display:block;font-size:12px;color:var(--mut);margin:10px 0 4px;font-weight:600}
button,.btn{display:inline-block;background:var(--acc);color:#fff;border:0;border-radius:8px;padding:8px 14px;font:inherit;cursor:pointer;font-weight:600}
button:hover,.btn:hover{background:var(--acc-d);text-decoration:none}
.btn2{background:#fff;border:1px solid #d1d5db;color:var(--txt)}
.btn2:hover{background:#f9fafb}
.btn-danger{background:var(--bad)}.btn-danger:hover{background:#b91c1c}
.btn-ok{background:var(--ok)}.btn-ok:hover{background:#15803d}
.btn-warn{background:#d97706}.btn-warn:hover{background:#b45309}
.btn-sm{padding:4px 10px;font-size:13px}
table{width:100%;border-collapse:collapse;background:var(--card);border-radius:10px;overflow:hidden}
th,td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--line2);vertical-align:top}
th{background:#f9fafb;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--mut)}
tr:last-child td{border-bottom:0}
tbody tr:hover{background:#fafafa}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:18px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.card h3{margin-top:0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:16px}
.stat{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.stat b{font-size:26px;display:block;color:var(--txt)}.stat span{color:var(--mut);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.stat b.acc{color:var(--acc)}
.flash{padding:11px 14px;border-radius:8px;margin-bottom:14px;font-weight:600}
.flash.ok{background:var(--ok-soft);color:#166534;border:1px solid #bbf7d0}
.flash.err{background:var(--bad-soft);color:#991b1b;border:1px solid #fecaca}
.badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#f3f4f6;color:#374151}
.badge.ok{background:var(--ok-soft);color:#166534}.badge.warn{background:var(--warn-soft);color:#92400e}.badge.bad{background:var(--bad-soft);color:#991b1b}
.mut{color:var(--mut);font-size:13px}.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;word-break:break-all}
.inline{display:inline}.inline form{display:inline}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
pre{background:#f9fafb;border:1px solid var(--line);border-radius:8px;padding:12px;overflow:auto;white-space:pre-wrap;word-break:break-word}
</style></head>
<body><div class="wrap">
<nav class="side" id="sidebar"><h1>🛒 Tools<span>ByDcx</span></h1>
<?php foreach ($nav as $group => $links): ?>
<h4><?= esc($group) ?></h4>
<?php foreach ($links as $k => $label): ?>
<a href="index.php?page=<?= esc($k) ?>" class="<?= $page === $k ? 'on' : '' ?>" data-nav="<?= esc($label) ?>"><?= esc($label) ?></a>
<?php endforeach; ?>
<?php endforeach; ?>
<h4>Account</h4>
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
