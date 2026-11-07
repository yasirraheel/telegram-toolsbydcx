<?php
// Categories CRUD (selected bot only).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'save') {
        $id = post_int('id');
        $name = post_str('name');
        $slug = slugify(post_str('slug') ?: $name);
        $emoji = post_str('emoji') ?: '📁';
        $img = post_str('image_url');
        $up = handle_image_upload('image_file', 'cat');
        if ($up === '') { flash('Image upload failed: use a JPG, PNG, GIF or WebP under 5 MB.', 'err'); }
        elseif ($up !== null) { $img = $up; }
        $sort = post_int('sort');
        $active = post_int('active', 1) ? 1 : 0;
        if ($name === '') { flash('Name is required.', 'err'); }
        elseif ($id > 0) {
            $st = $pdo->prepare("UPDATE categories SET name=?, slug=?, emoji=?, image_url=?, sort=?, active=? WHERE id=? AND bot_id=?");
            $st->execute([$name, $slug, $emoji, $img, $sort, $active, $id, $botId]);
            flash('Category updated.');
        } else {
            try {
                $pdo->prepare("INSERT INTO categories (bot_id, name, slug, emoji, image_url, sort, active) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$botId, $name, $slug, $emoji, $img, $sort, $active]);
                flash('Category added.');
            } catch (PDOException $e) { flash('Could not add category (slug already exists?): ' . $e->getMessage(), 'err'); }
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM categories WHERE id=? AND bot_id=?")->execute([post_int('id'), $botId]);
        flash('Category deleted (its products and stock were removed with it).');
    }
    redirect('index.php?page=categories');
}

$editId = get_int('edit');
$edit = null;
if ($editId) {
    $st = $pdo->prepare("SELECT * FROM categories WHERE id=? AND bot_id=?");
    $st->execute([$editId, $botId]);
    $edit = $st->fetch();
}
$rows = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id) AS products FROM categories c WHERE c.bot_id=? ORDER BY c.sort ASC, c.name ASC");
$rows->execute([$botId]);
$rows = $rows->fetchAll();

admin_head('Categories', 'categories', $botId, $bots);
?>
<div class="card"><h3 style="margin-top:0"><?= $edit ? '✏️ Edit category' : '➕ Add category' ?></h3>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="save"><?= csrf_field() ?>
<input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<div class="row">
<div style="flex:2"><label>Name</label><input type="text" name="name" value="<?= esc($edit['name'] ?? '') ?>" required></div>
<div style="flex:1"><label>Slug (auto from name if blank)</label><input type="text" name="slug" value="<?= esc($edit['slug'] ?? '') ?>"></div>
<div style="width:90px"><label>Emoji</label><input type="text" name="emoji" value="<?= esc($edit['emoji'] ?? '📁') ?>"></div>
<div style="width:90px"><label>Sort</label><input type="number" name="sort" value="<?= (int)($edit['sort'] ?? 0) ?>"></div>
<div><label>Active</label><select name="active"><option value="1" <?= ($edit['active'] ?? 1) ? 'selected' : '' ?>>Yes</option><option value="0" <?= isset($edit) && !$edit['active'] ? 'selected' : '' ?>>No</option></select></div>
</div>
<label>Image — upload a file (JPG, PNG, GIF or WebP, max 5 MB)</label><input type="file" name="image_file" accept="image/*">
<?php if (!empty($edit['image_url'])): ?><p class="mut" style="margin:4px 0">Current: <a href="<?= esc($edit['image_url']) ?>" target="_blank" rel="noopener"><?= esc($edit['image_url']) ?></a></p><?php endif; ?>
<label>…or paste an Image URL (used only when no file is uploaded)</label><input type="url" name="image_url" value="<?= esc($edit['image_url'] ?? '') ?>" placeholder="https://...">
<button type="submit" style="margin-top:12px"><?= $edit ? 'Save changes' : 'Add category' ?></button>
<?php if ($edit): ?><a class="btn btn2" href="index.php?page=categories">Cancel</a><?php endif; ?></form></div>

<div class="card"><table><tr><th></th><th>Name</th><th>Slug</th><th>Sort</th><th>Products</th><th>Active</th><th></th></tr>
<?php foreach ($rows as $r): ?><tr>
<td><?= esc($r['emoji']) ?></td><td><?= esc($r['name']) ?></td><td class="mono"><?= esc($r['slug']) ?></td>
<td><?= (int)$r['sort'] ?></td><td><?= (int)$r['products'] ?></td>
<td><?= $r['active'] ? '<span class="badge ok">yes</span>' : '<span class="badge bad">no</span>' ?></td>
<td><div class="row"><a class="btn btn-sm btn2" href="index.php?page=categories&amp;edit=<?= (int)$r['id'] ?>">Edit</a>
<form method="post" class="inline"><input type="hidden" name="action" value="delete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-sm btn-danger" type="submit" onclick="return confirm('Delete this category and all its products/stock?')">Delete</button></form></div></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="mut">No categories yet for this bot.</td></tr><?php endif; ?></table></div>
<?php admin_foot(); ?>
