<?php
// Support tickets: list open/closed; detail with messages; admin reply
// (stored, and sent to the user on Telegram when possible).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    $tid = post_int('id');
    $st = $pdo->prepare("SELECT t.*, u.telegram_id, u.username FROM support_tickets t JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.bot_id=?");
    $st->execute([$tid, $botId]);
    $ticket = $st->fetch();
    if (!$ticket) { flash('Ticket not found.', 'err'); redirect('index.php?page=tickets'); }
    if ($action === 'reply') {
        $msg = post_str('message');
        if ($msg === '') { flash('Reply cannot be empty.', 'err'); }
        else {
            $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender, message) VALUES (?, 'admin', ?)")->execute([$tid, $msg]);
            $sent = notify_user($botId, (int)$ticket['telegram_id'], "🎫 Support reply (ticket #{$tid}):\n\n" . $msg);
            flash('Reply saved' . ($sent ? ' and sent to the user on Telegram.' : ' (could not send on Telegram — check the bot token).'));
        }
    } elseif ($action === 'close') {
        $pdo->prepare("UPDATE support_tickets SET status='closed' WHERE id=?")->execute([$tid]);
        flash('Ticket #' . $tid . ' closed.');
    } elseif ($action === 'reopen') {
        $pdo->prepare("UPDATE support_tickets SET status='open' WHERE id=?")->execute([$tid]);
        flash('Ticket #' . $tid . ' reopened.');
    }
    redirect('index.php?page=tickets&id=' . $tid);
}

$viewId = get_int('id');
if ($viewId) {
    $st = $pdo->prepare("SELECT t.*, u.username, u.telegram_id, u.first_name FROM support_tickets t JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.bot_id=?");
    $st->execute([$viewId, $botId]);
    $ticket = $st->fetch();
    admin_head($ticket ? ('Ticket #' . $viewId) : 'Ticket', 'tickets', $botId, $bots);
    if (!$ticket) { echo '<div class="card">Ticket not found.</div>'; admin_foot(); return; }
    $msgs = $pdo->prepare("SELECT * FROM ticket_messages WHERE ticket_id=? ORDER BY created_at ASC");
    $msgs->execute([$viewId]);
    $msgs = $msgs->fetchAll();
    ?>
    <div class="card"><p><a href="index.php?page=tickets">← Back to tickets</a></p>
    <p><b><?= esc($ticket['subject'] ?: '(no subject)') ?></b> — <span class="badge <?= $ticket['status'] === 'open' ? 'warn' : 'ok' ?>"><?= esc($ticket['status']) ?></span><br>
    <span class="mut">From <a href="index.php?page=users&amp;id=<?= (int)$ticket['user_id'] ?>">@<?= esc($ticket['username'] ?: (string)$ticket['telegram_id']) ?></a> · <?= esc($ticket['created_at']) ?></span></p>
    <?php foreach ($msgs as $m): ?>
    <div style="border-left:3px solid <?= $m['sender'] === 'admin' ? '#7c5cff' : '#00bfff' ?>;padding:8px 12px;margin:8px 0;background:<?= $m['sender'] === 'admin' ? '#f0e8ff' : '#e8f4fd' ?>;border-radius:0 8px 8px 0;color:#1a1a2e">
    <span class="mut"><?= $m['sender'] === 'admin' ? '👤 Admin' : '🙋 User' ?> · <?= esc($m['created_at']) ?></span><br><?= nl2br(esc($m['message'])) ?></div>
    <?php endforeach; ?>
    <?php if (!$msgs): ?><p class="mut">No messages yet.</p><?php endif; ?>
    <form method="post" id="reply"><input type="hidden" name="action" value="reply"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
    <label>💬 Reply — sends a direct Telegram message to the user</label><textarea name="message" required placeholder="Type your reply — it will also be sent to the user on Telegram."></textarea>
    <button type="submit" style="margin-top:10px">Send reply</button></form>
    <form method="post" style="margin-top:10px"><input type="hidden" name="action" value="<?= $ticket['status'] === 'open' ? 'close' : 'reopen' ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
    <button type="submit" class="btn2"><?= $ticket['status'] === 'open' ? 'Close ticket' : 'Reopen ticket' ?></button></form></div>
    <?php admin_foot(); return;
}

$filter = (string)($_GET['status'] ?? 'open');
if (!in_array($filter, ['open', 'closed'], true)) $filter = 'open';
$st = $pdo->prepare("SELECT t.*, u.username, u.telegram_id FROM support_tickets t JOIN users u ON u.id=t.user_id WHERE t.bot_id=? AND t.status=? ORDER BY t.created_at DESC LIMIT 200");
$st->execute([$botId, $filter]);
$rows = $st->fetchAll();

admin_head('Support Tickets', 'tickets', $botId, $bots);
?>
<div class="card"><form method="get" action="index.php" class="row"><input type="hidden" name="page" value="tickets">
<label style="margin:0">Status:</label><select name="status" onchange="this.form.submit()">
<option value="open" <?= $filter === 'open' ? 'selected' : '' ?>>Open</option>
<option value="closed" <?= $filter === 'closed' ? 'selected' : '' ?>>Closed</option>
</select></form>
<table style="margin-top:12px"><tr><th>#</th><th>Subject</th><th>User</th><th>Created</th><th></th></tr>
<?php foreach ($rows as $t): ?><tr>
<td><a href="index.php?page=tickets&amp;id=<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
<td><?= esc($t['subject'] ?: '(no subject)') ?></td>
<td>@<?= esc($t['username'] ?: (string)$t['telegram_id']) ?></td>
<td class="mut"><?= esc($t['created_at']) ?></td>
<td><?php if ($t['status'] === 'open'): ?><a class="btn btn-sm" href="index.php?page=tickets&amp;id=<?= (int)$t['id'] ?>#reply">💬 Reply</a><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="mut">No <?= esc($filter) ?> tickets.</td></tr><?php endif; ?></table></div>
<?php admin_foot(); ?>
