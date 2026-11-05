<?php
// Freebies CRUD (selected bot only).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'save') {
        $id = post_int('id');
        $title = post_str('title');
        $desc = post_str('description');
        $delivery = post_str('delivery_text');
        $limit = post_int('claim_limit');
        $sort = post_int('sort');
        $active = post_int('active', 1) ? 1 : 0;
        if ($title === '') { flash('Title is required.', 'err'); }
        elseif ($id > 0) {
            $pdo->prepare("UPDATE freebies SET title=?, description=?, delivery_text=?, claim_limit=?, sort=?, active=? WHERE id=? AND bot_id=?")
                ->execute([$title, $desc, $delivery, $limit, $sort, $active, $id, $botId]);
            flash('Freebie updated.');
        } else {
            $pdo->prepare("INSERT INTO freebies (bot_id, title, description, delivery_text, claim_limit, sort, active) VALUES (?,?,?,?,?,?,?)")
                ->execute([$botId, $title, $desc, $delivery, $limit, $sort, $active]);
            flash('Freebie added.');
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM freebies WHERE id=? AND bot_id=?")->execute([post_int('id'), $botId]);
        flash('Freebie deleted.');
    }
    redirect('index.php?page=freebies');
}

$editId = get_int('edit');
$edit = null;
if ($editId) {
    $st = $pdo->prepare("SELECT * FROM freebies WHERE id=? AND bot_id=?");
    $st->execute([$editId, $botId]);
    $edit = $st->fetch();
}
$rows = $pdo->prepare("SELECT * FROM freebies WHERE bot_id=? ORDER BY sort ASC, title ASC");
$rows->execute([$botId]);
$rows = $rows->fetchAll();

admin_head('Freebies', 'freebies', $botId, $bots);
?>
<div class="card"><h3 style="margin-top:0"><?= $edit ? '✏️ Edit freebie' : '➕ Add freebie' ?></h3>
<form method="post"><input type="hidden" name="action" value="save"><?= csrf_field() ?>
<input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<div class="row">
<div style="flex:2"><label>Title</label><input type="text" name="title" value="<?= esc($edit['title'] ?? '') ?>" required></div>
<div style="width:130px"><label>Claim limit (0 = unlimited)</label><input type="number" min="0" name="claim_limit" value="<?= (int)($edit['claim_limit'] ?? 0) ?>"></div>
<div style="width:90px"><label>Sort</label><input type="number" name="sort" value="<?= (int)($edit['sort'] ?? 0) ?>"></div>
<div><label>Active</label><select name="active"><option value="1" <?= ($edit['active'] ?? 1) ? 'selected' : '' ?>>Yes</option><option value="0" <?= isset($edit) && !$edit['active'] ? 'selected' : '' ?>>No</option></select></div>
</div>
<label>Description</label><textarea name="description"><?= esc($edit['description'] ?? '') ?></textarea>
<label>Delivery text (sent to the user when they claim it)</label><textarea name="delivery_text"><?= esc($edit['delivery_text'] ?? '') ?></textarea>
<button type="submit" style="margin-top:12px"><?= $edit ? 'Save changes' : 'Add freebie' ?></button>
<?php if ($edit): ?><a class="btn btn2" href="index.php?page=freebies">Cancel</a><?php endif; ?></form></div>

<div class="card"><table><tr><th>Title</th><th>Claims</th><th>Limit</th><th>Active</th><th></th></tr>
<?php foreach ($rows as $r): ?><tr>
<td><?= esc($r['title']) ?><br><span class="mut"><?= esc(mb_substr((string)$r['description'], 0, 120)) ?></span></td>
<td><?= (int)$r['claims_count'] ?></td><td><?= (int)$r['claim_limit'] === 0 ? '∞' : (int)$r['claim_limit'] ?></td>
<td><?= $r['active'] ? '<span class="badge ok">yes</span>' : '<span class="badge bad">no</span>' ?></td>
<td><div class="row"><a class="btn btn-sm btn2" href="index.php?page=freebies&amp;edit=<?= (int)$r['id'] ?>">Edit</a>
<form method="post" class="inline"><input type="hidden" name="action" value="delete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-sm btn-danger" type="submit" onclick="return confirm('Delete this freebie?')">Delete</button></form></div></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="mut">No freebies yet for this bot.</td></tr><?php endif; ?></table></div>
<?php admin_foot(); ?>
