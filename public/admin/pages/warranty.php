<?php
// Warranty claims: list pending; approve (refund type refunds points) / reject
// with an admin note; the user is notified on Telegram when possible.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    $wid = post_int('id');
    $note = post_str('admin_note');
    $st = $pdo->prepare("SELECT w.*, u.telegram_id, u.username FROM warranty_claims w JOIN users u ON u.id=w.user_id WHERE w.id=? AND w.bot_id=?");
    $st->execute([$wid, $botId]);
    $claim = $st->fetch();
    if (!$claim) { flash('Claim not found.', 'err'); redirect('index.php?page=warranty'); }
    if ($claim['status'] !== 'pending') { flash('Claim is already ' . $claim['status'] . '.', 'err'); redirect('index.php?page=warranty'); }

    if ($action === 'approve') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE warranty_claims SET status='approved', admin_note=? WHERE id=?")->execute([$note, $wid]);
            $refunded = 0.0;
            if ($claim['type'] === 'refund') {
                $ost = $pdo->prepare("SELECT * FROM orders WHERE id=? AND bot_id=?");
                $ost->execute([(int)$claim['order_id'], $botId]);
                $order = $ost->fetch();
                if ($order && $order['status'] !== 'refunded') {
                    $pdo->prepare("UPDATE orders SET status='refunded' WHERE id=?")->execute([(int)$order['id']]);
                    $refunded = order_refund_points($botId, $order);
                }
            }
            $pdo->commit();
            notify_user($botId, (int)$claim['telegram_id'],
                "🛡️ Your warranty claim #{$wid} was approved." . ($refunded > 0 ? " " . fmt_points($refunded) . " points were refunded to your wallet." : '') . ($note !== '' ? "\nNote: " . $note : ''));
            flash('Claim #' . $wid . ' approved.' . ($refunded > 0 ? ' ' . fmt_points($refunded) . ' points refunded.' : ''));
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('Approve failed: ' . $e->getMessage(), 'err');
        }
    } elseif ($action === 'reject') {
        $pdo->prepare("UPDATE warranty_claims SET status='rejected', admin_note=? WHERE id=?")->execute([$note, $wid]);
        notify_user($botId, (int)$claim['telegram_id'],
            "🛡️ Your warranty claim #{$wid} was rejected." . ($note !== '' ? "\nReason: " . $note : ''));
        flash('Claim #' . $wid . ' rejected.');
    }
    redirect('index.php?page=warranty');
}

$filter = (string)($_GET['status'] ?? 'pending');
if (!in_array($filter, ['pending', 'approved', 'rejected'], true)) $filter = 'pending';
$st = $pdo->prepare("SELECT w.*, u.username, u.telegram_id, p.name AS product_name FROM warranty_claims w JOIN users u ON u.id=w.user_id JOIN orders o ON o.id=w.order_id JOIN products p ON p.id=o.product_id WHERE w.bot_id=? AND w.status=? ORDER BY w.created_at DESC LIMIT 200");
$st->execute([$botId, $filter]);
$rows = $st->fetchAll();

admin_head('Warranty Claims', 'warranty', $botId, $bots);
?>
<div class="card"><form method="get" action="index.php" class="row"><input type="hidden" name="page" value="warranty">
<label style="margin:0">Status:</label><select name="status" onchange="this.form.submit()">
<?php foreach (['pending', 'approved', 'rejected'] as $s): ?><option value="<?= esc($s) ?>" <?= $filter === $s ? 'selected' : '' ?>><?= esc($s) ?></option><?php endforeach; ?>
</select></form>
<table style="margin-top:12px"><tr><th>#</th><th>Order</th><th>User</th><th>Type</th><th>Reason</th><th>Admin note</th><th>Created</th><th></th></tr>
<?php foreach ($rows as $w): ?><tr>
<td>#<?= (int)$w['id'] ?></td>
<td><a href="index.php?page=orders&amp;id=<?= (int)$w['order_id'] ?>">#<?= (int)$w['order_id'] ?></a><br><span class="mut"><?= esc($w['product_name']) ?></span></td>
<td>@<?= esc($w['username'] ?: (string)$w['telegram_id']) ?></td>
<td><?= esc($w['type']) ?></td><td><?= nl2br(esc($w['reason'])) ?></td>
<td class="mut"><?= esc($w['admin_note'] ?: '—') ?></td><td class="mut"><?= esc($w['created_at']) ?></td>
<td><?php if ($w['status'] === 'pending'): ?>
<form method="post"><input type="hidden" name="action" value="approve"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
<input type="text" name="admin_note" placeholder="Admin note (optional)" style="margin-bottom:6px">
<button class="btn-sm btn-ok" type="submit">Approve<?= $w['type'] === 'refund' ? ' + refund' : '' ?></button></form>
<form method="post" style="margin-top:6px"><input type="hidden" name="action" value="reject"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
<input type="text" name="admin_note" placeholder="Rejection reason" style="margin-bottom:6px">
<button class="btn-sm btn-danger" type="submit">Reject</button></form>
<?php else: ?><span class="badge <?= $w['status'] === 'approved' ? 'ok' : 'bad' ?>"><?= esc($w['status']) ?></span><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="mut">No <?= esc($filter) ?> warranty claims.</td></tr><?php endif; ?></table></div>
<?php admin_foot(); ?>
