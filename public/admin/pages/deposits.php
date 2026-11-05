<?php
// Deposits: list; Confirm (credit points) / Reject.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    $did = post_int('id');
    $st = $pdo->prepare("SELECT * FROM deposits WHERE id=? AND bot_id=?");
    $st->execute([$did, $botId]);
    $dep = $st->fetch();
    if (!$dep) { flash('Deposit not found.', 'err'); }
    elseif ($dep['status'] !== 'pending') { flash('Deposit is already ' . $dep['status'] . '.', 'err'); }
    elseif ($action === 'confirm') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE deposits SET status='confirmed' WHERE id=?")->execute([$did]);
            add_points($botId, (int)$dep['user_id'], (float)$dep['points'], 'deposit', 'deposit', $did);
            $pdo->commit();
            $ust = $pdo->prepare("SELECT telegram_id FROM users WHERE id=?");
            $ust->execute([(int)$dep['user_id']]);
            $u = $ust->fetch();
            if ($u) notify_user($botId, (int)$u['telegram_id'], "✅ Your deposit of $" . fmt_usd($dep['amount_usd']) . " is confirmed. " . fmt_points($dep['points']) . " points were added to your wallet.");
            flash('Deposit #' . $did . ' confirmed: ' . fmt_points($dep['points']) . ' points credited.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('Confirm failed: ' . $e->getMessage(), 'err');
        }
    } elseif ($action === 'reject') {
        $pdo->prepare("UPDATE deposits SET status='failed' WHERE id=?")->execute([$did]);
        flash('Deposit #' . $did . ' rejected.');
    }
    redirect('index.php?page=deposits');
}

$filter = (string)($_GET['status'] ?? '');
if (!in_array($filter, ['pending', 'confirmed', 'failed'], true)) $filter = '';
$sql = "SELECT d.*, u.username, u.telegram_id FROM deposits d JOIN users u ON u.id=d.user_id WHERE d.bot_id=?";
$args = [$botId];
if ($filter) { $sql .= " AND d.status=?"; $args[] = $filter; }
$sql .= " ORDER BY d.created_at DESC LIMIT 200";
$st = $pdo->prepare($sql); $st->execute($args);
$rows = $st->fetchAll();

admin_head('Deposits', 'deposits', $botId, $bots);
?>
<div class="card"><form method="get" action="index.php" class="row"><input type="hidden" name="page" value="deposits">
<label style="margin:0">Status:</label><select name="status" onchange="this.form.submit()">
<option value="">All</option>
<?php foreach (['pending', 'confirmed', 'failed'] as $s): ?><option value="<?= esc($s) ?>" <?= $filter === $s ? 'selected' : '' ?>><?= esc($s) ?></option><?php endforeach; ?>
</select></form>
<table style="margin-top:12px"><tr><th>#</th><th>User</th><th>Amount</th><th>Points</th><th>Method</th><th>TXID</th><th>Status</th><th>When</th><th></th></tr>
<?php foreach ($rows as $d): ?><tr>
<td>#<?= (int)$d['id'] ?></td><td><a href="index.php?page=users&amp;id=<?= (int)$d['user_id'] ?>">@<?= esc($d['username'] ?: (string)$d['telegram_id']) ?></a></td>
<td>$<?= esc(fmt_usd($d['amount_usd'])) ?></td><td><?= esc(fmt_points($d['points'])) ?></td>
<td><?= esc($d['method']) ?></td><td class="mono"><?= esc($d['txid'] ?: '—') ?></td>
<td><span class="badge <?= $d['status'] === 'confirmed' ? 'ok' : ($d['status'] === 'pending' ? 'warn' : 'bad') ?>"><?= esc($d['status']) ?></span></td>
<td class="mut"><?= esc($d['created_at']) ?></td>
<td><?php if ($d['status'] === 'pending'): ?><div class="row">
<form method="post" class="inline"><input type="hidden" name="action" value="confirm"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn-sm btn-ok" type="submit">Confirm</button></form>
<form method="post" class="inline"><input type="hidden" name="action" value="reject"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn-sm btn-danger" type="submit" onclick="return confirm('Reject this deposit?')">Reject</button></form>
</div><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="9" class="mut">No deposits yet.</td></tr><?php endif; ?></table></div>
<?php admin_foot(); ?>
