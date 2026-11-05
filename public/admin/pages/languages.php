<?php
// Languages: list per bot, add, toggle active, delete.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    $code = strtolower(trim(post_str('code')));
    if ($action === 'add') {
        $name = post_str('name');
        $native = post_str('native_name') ?: $name;
        $sort = post_int('sort');
        if (!preg_match('/^[a-z]{2}(-[a-z]+)?$/', $code) || $name === '') {
            flash('Enter a valid code (e.g. en, ur, ur-roman) and a name.', 'err');
        } else {
            try {
                $pdo->prepare("INSERT INTO languages (bot_id, code, name, native_name, active, sort) VALUES (?,?,?,?,1,?)")
                    ->execute([$botId, $code, $name, $native, $sort]);
                flash('Language ' . $code . ' added.');
            } catch (PDOException $e) { flash('Could not add language (already exists?): ' . $e->getMessage(), 'err'); }
        }
    } elseif ($action === 'toggle') {
        $pdo->prepare("UPDATE languages SET active = 1 - active WHERE bot_id=? AND code=?")->execute([$botId, $code]);
        flash('Language ' . $code . ' toggled.');
    } elseif ($action === 'delete') {
        if ($code === 'en') { flash('English cannot be deleted (it is the fallback language).', 'err'); }
        else {
            $pdo->prepare("DELETE FROM languages WHERE bot_id=? AND code=?")->execute([$botId, $code]);
            $pdo->prepare("DELETE FROM texts WHERE bot_id=? AND lang=?")->execute([$botId, $code]);
            flash('Language ' . $code . ' and its texts deleted.');
        }
    }
    redirect('index.php?page=languages');
}

$rows = $pdo->prepare("SELECT * FROM languages WHERE bot_id=? ORDER BY sort ASC, code ASC");
$rows->execute([$botId]);
$rows = $rows->fetchAll();

admin_head('Languages', 'languages', $botId, $bots);
?>
<div class="card"><h3 style="margin-top:0">➕ Add language</h3>
<p class="mut">English is the fallback: any key missing in another language falls back to the English text (see <a href="index.php?page=texts">Texts</a>).</p>
<form method="post"><input type="hidden" name="action" value="add"><?= csrf_field() ?>
<div class="row">
<div style="width:130px"><label>Code</label><input type="text" name="code" placeholder="ur" required></div>
<div style="flex:1"><label>Name (English)</label><input type="text" name="name" placeholder="Urdu" required></div>
<div style="flex:1"><label>Native name</label><input type="text" name="native_name" placeholder="اردو"></div>
<div style="width:90px"><label>Sort</label><input type="number" name="sort" value="0"></div>
</div><button type="submit" style="margin-top:12px">Add language</button></form></div>

<div class="card"><table><tr><th>Code</th><th>Name</th><th>Native name</th><th>Active</th><th>Texts</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<?php $n = $pdo->prepare("SELECT COUNT(*) FROM texts WHERE bot_id=? AND lang=?"); $n->execute([$botId, $r['code']]); $n = (int)$n->fetchColumn(); ?>
<tr><td class="mono"><?= esc($r['code']) ?></td><td><?= esc($r['name']) ?></td><td><?= esc($r['native_name']) ?></td>
<td><?= $r['active'] ? '<span class="badge ok">yes</span>' : '<span class="badge bad">no</span>' ?></td>
<td><?= $n ?> keys · <a href="index.php?page=texts&amp;lang=<?= esc($r['code']) ?>">Edit texts →</a></td>
<td><div class="row">
<form method="post" class="inline"><input type="hidden" name="action" value="toggle"><?= csrf_field() ?><input type="hidden" name="code" value="<?= esc($r['code']) ?>"><button class="btn-sm btn2" type="submit"><?= $r['active'] ? 'Disable' : 'Enable' ?></button></form>
<?php if ($r['code'] !== 'en'): ?>
<form method="post" class="inline"><input type="hidden" name="action" value="delete"><?= csrf_field() ?><input type="hidden" name="code" value="<?= esc($r['code']) ?>"><button class="btn-sm btn-danger" type="submit" onclick="return confirm('Delete this language and all its texts?')">Delete</button></form>
<?php endif; ?></div></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="mut">No languages for this bot yet.</td></tr><?php endif; ?></table></div>
<?php admin_foot(); ?>
