<?php
// Reviews: list pending; approve/reject.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    $rid = post_int('id');
    if (in_array($action, ['approve', 'reject'], true)) {
        $st = $pdo->prepare("SELECT id FROM reviews WHERE id=? AND bot_id=? AND status='pending'");
        $st->execute([$rid, $botId]);
        if ($st->fetch()) {
            $pdo->prepare("UPDATE reviews SET status=? WHERE id=?")->execute([$action === 'approve' ? 'approved' : 'rejected', $rid]);
            flash('Review #' . $rid . ' ' . ($action === 'approve' ? 'approved.' : 'rejected.'));
        } else { flash('Review not found or already decided.', 'err'); }
    }
    redirect('index.php?page=reviews');
}

$filter = (string)($_GET['status'] ?? 'pending');
if (!in_array($filter, ['pending', 'approved', 'rejected'], true)) $filter = 'pending';
$st = $pdo->prepare("SELECT r.*, u.username, u.telegram_id, p.name AS product_name FROM reviews r JOIN users u ON u.id=r.user_id JOIN products p ON p.id=r.product_id WHERE r.bot_id=? AND r.status=? ORDER BY r.created_at DESC LIMIT 200");
$st->execute([$botId, $filter]);
$rows = $st->fetchAll();

admin_head('Reviews', 'reviews', $botId, $bots);
?>
<div class="card"><form method="get" action="index.php" class="row"><input type="hidden" name="page" value="reviews">
<label style="margin:0">Status:</label><select name="status" onchange="this.form.submit()">
<?php foreach (['pending', 'approved', 'rejected'] as $s): ?><option value="<?= esc($s) ?>" <?= $filter === $s ? 'selected' : '' ?>><?= esc($s) ?></option><?php endforeach; ?>
</select></form>
<table style="margin-top:12px"><tr><th>#</th><th>Product</th><th>User</th><th>Rating</th><th>Review</th><th>Created</th><th></th></tr>
<?php foreach ($rows as $r): ?><tr>
<td>#<?= (int)$r['id'] ?></td><td><?= esc($r['product_name']) ?></td>
<td>@<?= esc($r['username'] ?: (string)$r['telegram_id']) ?></td>
<td><?= str_repeat('⭐', max(1, min(5, (int)$r['rating']))) ?> (<?= (int)$r['rating'] ?>/5)</td>
<td><?= nl2br(esc($r['text'] ?: '—')) ?></td><td class="mut"><?= esc($r['created_at']) ?></td>
<td><?php if ($r['status'] === 'pending'): ?><div class="row">
<form method="post" class="inline"><input type="hidden" name="action" value="approve"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-sm btn-ok" type="submit">Approve</button></form>
<form method="post" class="inline"><input type="hidden" name="action" value="reject"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-sm btn-danger" type="submit">Reject</button></form>
</div><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="mut">No <?= esc($filter) ?> reviews.</td></tr><?php endif; ?></table></div>
<?php admin_foot(); ?>
