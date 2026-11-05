<?php
// Referrals: list with status (filterable).
$filter = (string)($_GET['status'] ?? '');
if (!in_array($filter, ['pending', 'approved', 'rejected'], true)) $filter = '';
$sql = "SELECT r.*, a.username AS referrer_name, a.telegram_id AS referrer_tg, b.username AS referred_name, b.telegram_id AS referred_tg
        FROM referrals r
        JOIN users a ON a.id=r.referrer_id
        JOIN users b ON b.id=r.referred_id
        WHERE r.bot_id=?";
$args = [$botId];
if ($filter) { $sql .= " AND r.status=?"; $args[] = $filter; }
$sql .= " ORDER BY r.created_at DESC LIMIT 200";
$st = $pdo->prepare($sql); $st->execute($args);
$rows = $st->fetchAll();

admin_head('Referrals', 'referrals', $botId, $bots);
?>
<div class="card"><form method="get" action="index.php" class="row"><input type="hidden" name="page" value="referrals">
<label style="margin:0">Status:</label><select name="status" onchange="this.form.submit()">
<option value="">All</option>
<?php foreach (['pending', 'approved', 'rejected'] as $s): ?><option value="<?= esc($s) ?>" <?= $filter === $s ? 'selected' : '' ?>><?= esc($s) ?></option><?php endforeach; ?>
</select>
<span class="mut">Reward: <?= esc((string)setting($botId, 'referral_reward', '0.1')) ?> pt to both sides on approval · Milestone <?= esc((string)setting($botId, 'referral_milestone', '20')) ?> refs → +<?= esc((string)setting($botId, 'referral_milestone_reward', '10')) ?> pts (see Settings).</span></form>
<table style="margin-top:12px"><tr><th>#</th><th>Referrer</th><th>Referred</th><th>Status</th><th>Points awarded</th><th>When</th></tr>
<?php foreach ($rows as $r): ?><tr>
<td>#<?= (int)$r['id'] ?></td>
<td><a href="index.php?page=users&amp;id=<?= (int)$r['referrer_id'] ?>">@<?= esc($r['referrer_name'] ?: (string)$r['referrer_tg']) ?></a></td>
<td><a href="index.php?page=users&amp;id=<?= (int)$r['referred_id'] ?>">@<?= esc($r['referred_name'] ?: (string)$r['referred_tg']) ?></a></td>
<td><span class="badge <?= $r['status'] === 'approved' ? 'ok' : ($r['status'] === 'pending' ? 'warn' : 'bad') ?>"><?= esc($r['status']) ?></span></td>
<td><?= esc(fmt_points($r['points_awarded'])) ?></td><td class="mut"><?= esc($r['created_at']) ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="mut">No referrals yet.</td></tr><?php endif; ?></table></div>
<?php admin_foot(); ?>
