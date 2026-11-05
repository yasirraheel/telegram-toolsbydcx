<?php
// Broadcasts: send a message to all non-banned users of the selected bot
// (chunked, with a pause between chunks), record sent_count.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $message = trim((string)($_POST['message'] ?? ''));
    if ($message === '') {
        flash('Message cannot be empty.', 'err');
    } else {
        set_time_limit(0);
        $token = bot_token($botId);
        $users = $pdo->prepare("SELECT telegram_id FROM users WHERE bot_id=? AND banned=0 ORDER BY id ASC");
        $users->execute([$botId]);
        $users = $users->fetchAll();
        $sent = 0;
        $failed = 0;
        if ($token === '') {
            flash('No bot token stored — cannot send. Add the token in Bots first.', 'err');
        } else {
            $pdo->prepare("INSERT INTO broadcasts (bot_id, message, sent_count) VALUES (?,?,0)")->execute([$botId, $message]);
            $bid = (int)$pdo->lastInsertId();
            $chunk = 0;
            foreach ($users as $u) {
                $res = tg_send($token, (int)$u['telegram_id'], $message);
                if (!empty($res['ok'])) { $sent++; } else { $failed++; }
                // Telegram rate limit: ~30 msg/sec for broadcasts; pause each chunk.
                if (++$chunk % 25 === 0) {
                    $pdo->prepare("UPDATE broadcasts SET sent_count=? WHERE id=?")->execute([$sent, $bid]);
                    sleep(1);
                }
            }
            $pdo->prepare("UPDATE broadcasts SET sent_count=? WHERE id=?")->execute([$sent, $bid]);
            flash("Broadcast sent to $sent user(s)" . ($failed ? " ($failed failed — blocked bot / deleted account)." : '.'));
        }
    }
    redirect('index.php?page=broadcasts');
}

$rows = $pdo->prepare("SELECT * FROM broadcasts WHERE bot_id=? ORDER BY created_at DESC LIMIT 20");
$rows->execute([$botId]);
$rows = $rows->fetchAll();
$recipientCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE bot_id=$botId AND banned=0")->fetchColumn();

admin_head('Broadcasts', 'broadcasts', $botId, $bots);
?>
<div class="card"><h3 style="margin-top:0">📣 New broadcast</h3>
<p class="mut">Will be sent to <b><?= $recipientCount ?></b> non-banned user(s) of this bot, in chunks of 25 with a 1-second pause between chunks. This can take a while for large audiences — do not close the tab until it finishes.</p>
<form method="post"><input type="hidden" name="action" value="send"><?= csrf_field() ?>
<label>Message (HTML formatting allowed: &lt;b&gt;, &lt;i&gt;, &lt;a href=""&gt;)</label>
<textarea name="message" rows="6" required placeholder="Hello! New products just dropped…"></textarea>
<button type="submit" style="margin-top:12px" onclick="return confirm('Send this broadcast to <?= $recipientCount ?> user(s)?')">Send broadcast</button></form></div>

<div class="card"><h3 style="margin-top:0">📜 Past broadcasts</h3>
<table><tr><th>#</th><th>Message</th><th>Sent</th><th>When</th></tr>
<?php foreach ($rows as $b): ?><tr>
<td>#<?= (int)$b['id'] ?></td><td><?= nl2br(esc(mb_substr((string)$b['message'], 0, 300))) ?><?= mb_strlen((string)$b['message']) > 300 ? '…' : '' ?></td>
<td><?= (int)$b['sent_count'] ?></td><td class="mut"><?= esc($b['created_at']) ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="4" class="mut">No broadcasts yet.</td></tr><?php endif; ?></table></div>
<?php admin_foot(); ?>
