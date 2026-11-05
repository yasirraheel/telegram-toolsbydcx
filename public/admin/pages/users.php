<?php
// Users: list/search, detail with points adjust (ledger entry) and ban/unban.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    $uid = post_int('id');
    $st = $pdo->prepare("SELECT * FROM users WHERE id=? AND bot_id=?");
    $st->execute([$uid, $botId]);
    $user = $st->fetch();
    if (!$user) { flash('User not found.', 'err'); redirect('index.php?page=users'); }
    if ($action === 'points') {
        $delta = post_float('delta');
        $reason = post_str('reason') ?: 'admin_adjust';
        if ($delta == 0) { flash('Enter a non-zero amount.', 'err'); }
        else {
            add_points($botId, $uid, $delta, $reason);
            flash(($delta > 0 ? '+' : '') . fmt_points($delta) . ' points ' . ($delta > 0 ? 'added to' : 'removed from') . ' @' . ($user['username'] ?: (string)$user['telegram_id']) . ' wallet.');
        }
    } elseif ($action === 'ban') {
        $pdo->prepare("UPDATE users SET banned = 1 - banned WHERE id=?")->execute([$uid]);
        flash('User @' . ($user['username'] ?: (string)$user['telegram_id']) . ' ' . ($user['banned'] ? 'unbanned.' : 'banned.'));
    }
    redirect('index.php?page=users&id=' . $uid);
}

$viewId = get_int('id');
if ($viewId) {
    $st = $pdo->prepare("SELECT * FROM users WHERE id=? AND bot_id=?");
    $st->execute([$viewId, $botId]);
    $user = $st->fetch();
    admin_head($user ? ('User @' . ($user['username'] ?: (string)$user['telegram_id'])) : 'User', 'users', $botId, $bots);
    if (!$user) { echo '<div class="card">User not found.</div>'; admin_foot(); return; }
    $tier = loyalty_tier_for($botId, (float)$user['total_spent'], (int)$user['total_orders']);
    $ledger = $pdo->prepare("SELECT * FROM points_ledger WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
    $ledger->execute([$viewId]);
    $ledger = $ledger->fetchAll();
    $uorders = $pdo->prepare("SELECT o.*, p.name AS product_name FROM orders o JOIN products p ON p.id=o.product_id WHERE o.user_id=? ORDER BY o.created_at DESC LIMIT 10");
    $uorders->execute([$viewId]);
    $uorders = $uorders->fetchAll();
    ?>
    <div class="card"><p><a href="index.php?page=users">← Back to users</a></p>
    <table>
    <tr><th>Telegram ID</th><td><?= (int)$user['telegram_id'] ?></td></tr>
    <tr><th>Name</th><td><?= esc($user['first_name']) ?> (@<?= esc($user['username'] ?: '—') ?>)</td></tr>
    <tr><th>Language</th><td><?= esc($user['language']) ?></td></tr>
    <tr><th>Wallet points</th><td><?= esc(fmt_points($user['points_wallet'])) ?> (referral: <?= esc(fmt_points($user['points_referral'])) ?>)</td></tr>
    <tr><th>Total spent</th><td>$<?= esc(fmt_usd($user['total_spent'])) ?> · <?= (int)$user['total_orders'] ?> orders · Tier: <?= esc((string)($tier['current']['name'] ?? '')) ?></td></tr>
    <tr><th>Referred by</th><td><?= $user['referred_by'] ? '#' . (int)$user['referred_by'] : '—' ?></td></tr>
    <tr><th>Status</th><td><?= $user['banned'] ? '<span class="badge bad">BANNED</span>' : '<span class="badge ok">active</span>' ?></td></tr>
    <tr><th>Joined</th><td><?= esc($user['joined_at']) ?></td></tr>
    </table></div>

    <div class="card"><h3 style="margin-top:0">💎 Adjust points</h3>
    <form method="post"><input type="hidden" name="action" value="points"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
    <div class="row"><div style="width:160px"><label>Amount (+/-)</label><input type="number" step="0.1" name="delta" required placeholder="+10 or -5"></div>
    <div style="flex:1"><label>Reason</label><input type="text" name="reason" value="admin_adjust" required></div></div>
    <button type="submit" style="margin-top:12px">Apply (ledger entry recorded)</button></form>
    <form method="post" style="margin-top:10px"><input type="hidden" name="action" value="ban"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
    <button type="submit" class="<?= $user['banned'] ? 'btn-ok' : 'btn-danger' ?>"><?= $user['banned'] ? 'Unban user' : 'Ban user' ?></button></form></div>

    <div class="card"><h3 style="margin-top:0">🧾 Recent orders</h3>
    <?php if (!$uorders): ?><p class="mut">No orders.</p><?php else: ?><table><tr><th>#</th><th>Product</th><th>Total</th><th>Status</th><th>When</th></tr>
    <?php foreach ($uorders as $o): ?><tr><td><a href="index.php?page=orders&amp;id=<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?></a></td><td><?= esc($o['product_name']) ?></td><td>$<?= esc(fmt_usd($o['total_usd'])) ?></td><td><?= esc($o['status']) ?></td><td class="mut"><?= esc($o['created_at']) ?></td></tr><?php endforeach; ?></table><?php endif; ?></div>

    <div class="card"><h3 style="margin-top:0">🔄 Points ledger (latest 20)</h3>
    <?php if (!$ledger): ?><p class="mut">No ledger entries.</p><?php else: ?><table><tr><th>When</th><th>Delta</th><th>Reason</th><th>Ref</th></tr>
    <?php foreach ($ledger as $l): ?><tr><td class="mut"><?= esc($l['created_at']) ?></td><td><?= esc(fmt_points($l['delta'])) ?></td><td><?= esc($l['reason']) ?></td><td><?= esc($l['ref_type']) ?> <?= $l['ref_id'] ? '#' . (int)$l['ref_id'] : '' ?></td></tr><?php endforeach; ?></table><?php endif; ?></div>
    <?php admin_foot(); return;
}

$q = trim((string)($_GET['q'] ?? ''));
$sql = "SELECT * FROM users WHERE bot_id=?";
$args = [$botId];
if ($q !== '') {
    $sql .= " AND (CAST(telegram_id AS CHAR) LIKE ? OR username LIKE ? OR first_name LIKE ?)";
    $like = '%' . $q . '%';
    $args[] = $like; $args[] = $like; $args[] = $like;
}
$sql .= " ORDER BY joined_at DESC LIMIT 200";
$st = $pdo->prepare($sql); $st->execute($args);
$rows = $st->fetchAll();

admin_head('Users', 'users', $botId, $bots);
?>
<div class="card"><form method="get" action="index.php" class="row"><input type="hidden" name="page" value="users">
<input type="text" name="q" value="<?= esc($q) ?>" placeholder="Search by Telegram ID, @username or name" style="flex:1">
<button type="submit">Search</button><?php if ($q !== ''): ?><a class="btn btn2" href="index.php?page=users">Clear</a><?php endif; ?></form>
<table style="margin-top:12px"><tr><th>ID</th><th>Telegram ID</th><th>Name</th><th>Points</th><th>Spent</th><th>Orders</th><th>Banned</th></tr>
<?php foreach ($rows as $r): ?><tr>
<td><a href="index.php?page=users&amp;id=<?= (int)$r['id'] ?>">#<?= (int)$r['id'] ?></a></td>
<td><?= (int)$r['telegram_id'] ?></td>
<td><?= esc($r['first_name']) ?> (@<?= esc($r['username'] ?: '—') ?>)</td>
<td><?= esc(fmt_points($r['points_wallet'])) ?></td>
<td>$<?= esc(fmt_usd($r['total_spent'])) ?></td><td><?= (int)$r['total_orders'] ?></td>
<td><?= $r['banned'] ? '<span class="badge bad">banned</span>' : '—' ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="mut">No users found.</td></tr><?php endif; ?></table></div>
<?php admin_foot(); ?>
