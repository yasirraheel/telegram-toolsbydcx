<?php
// Notifications: everything the admin must review — open tickets, pending
// orders, pending warranty claims, pending deposits — grouped with links.
$pc = admin_pending_counts($pdo, $botId);
admin_head('Notifications' . ($pc['total'] > 0 ? ' (' . $pc['total'] . ')' : ''), 'notifications', $botId, $bots);
?>
<div class="card">
<?php if ($pc['total'] === 0): ?>
<p>✅ Nothing needs review right now. New tickets, orders, warranty claims and deposits will appear here.</p>
<?php else: ?>
<p><b><?= (int)$pc['total'] ?> item(s) need review.</b></p>
<?php endif; ?>

<?php
$groups = [];
if ($pc['tickets'] > 0) {
    $rows = $pdo->prepare("SELECT t.id, t.subject, u.username, u.telegram_id, t.created_at FROM support_tickets t JOIN users u ON u.id=t.user_id WHERE t.bot_id=? AND t.status='open' ORDER BY t.created_at DESC LIMIT 10");
    $rows->execute([$botId]);
    $groups[] = ['label' => '🎫 Open Tickets', 'page' => 'tickets', 'rows' => $rows->fetchAll(), 'fmt' => function ($r) {
        return '#' . (int)$r['id'] . ' — ' . ($r['subject'] ?: '(no subject)') . ' · @' . ($r['username'] ?: (string)$r['telegram_id']) . ' · ' . $r['created_at'];
    }, 'link' => function ($r) { return 'index.php?page=tickets&id=' . (int)$r['id'] . '#reply'; }];
}
if ($pc['orders'] > 0) {
    $rows = $pdo->prepare("SELECT o.id, o.total_usd, u.username, u.telegram_id, o.created_at FROM orders o JOIN users u ON u.id=o.user_id WHERE o.bot_id=? AND o.status='pending' ORDER BY o.created_at DESC LIMIT 10");
    $rows->execute([$botId]);
    $groups[] = ['label' => '🧾 Pending Orders', 'page' => 'orders', 'rows' => $rows->fetchAll(), 'fmt' => function ($r) {
        return '#' . (int)$r['id'] . ' — $' . number_format((float)$r['total_usd'], 2) . ' · @' . ($r['username'] ?: (string)$r['telegram_id']) . ' · ' . $r['created_at'];
    }, 'link' => function ($r) { return 'index.php?page=orders&id=' . (int)$r['id']; }];
}
if ($pc['warranty'] > 0) {
    $rows = $pdo->prepare("SELECT w.id, w.type, u.username, u.telegram_id, w.created_at FROM warranty_claims w JOIN users u ON u.id=w.user_id WHERE w.bot_id=? AND w.status='pending' ORDER BY w.created_at DESC LIMIT 10");
    $rows->execute([$botId]);
    $groups[] = ['label' => '🛡️ Pending Warranty Claims', 'page' => 'warranty', 'rows' => $rows->fetchAll(), 'fmt' => function ($r) {
        return '#' . (int)$r['id'] . ' — ' . $r['type'] . ' · @' . ($r['username'] ?: (string)$r['telegram_id']) . ' · ' . $r['created_at'];
    }, 'link' => function ($r) { return 'index.php?page=warranty'; }];
}
if ($pc['deposits'] > 0) {
    $rows = $pdo->prepare("SELECT d.id, d.amount_usd, u.username, u.telegram_id, d.created_at FROM deposits d JOIN users u ON u.id=d.user_id WHERE d.bot_id=? AND d.status='pending' ORDER BY d.created_at DESC LIMIT 10");
    $rows->execute([$botId]);
    $groups[] = ['label' => '💳 Pending Deposits', 'page' => 'deposits', 'rows' => $rows->fetchAll(), 'fmt' => function ($r) {
        return '#' . (int)$r['id'] . ' — $' . number_format((float)$r['amount_usd'], 2) . ' · @' . ($r['username'] ?: (string)$r['telegram_id']) . ' · ' . $r['created_at'];
    }, 'link' => function ($r) { return 'index.php?page=deposits'; }];
}
foreach ($groups as $g):
?>
<h3 style="margin-top:18px"><?= esc($g['label']) ?> <span class="badge warn"><?= count($g['rows']) ?><?= count($g['rows']) >= 10 ? '+' : '' ?></span></h3>
<ul>
<?php foreach ($g['rows'] as $r): ?>
<li><a href="<?= esc($g['link']($r)) ?>"><?= esc($g['fmt']($r)) ?></a></li>
<?php endforeach; ?>
</ul>
<p><a href="index.php?page=<?= esc($g['page']) ?>">View all →</a></p>
<?php endforeach; ?>
</div>
<?php admin_foot(); ?>
