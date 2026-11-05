<?php
// Texts editor: pick a language, list its keys with values, edit/add key-values.
// English is the fallback language (lib/I18n.php); missing keys in other
// languages are shown with the English value for reference.
$lang = strtolower(trim((string)($_GET['lang'] ?? 'en')));
if (!preg_match('/^[a-z]{2}(-[a-z]+)?$/', $lang)) $lang = 'en';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    $lang = strtolower(trim(post_str('lang')));
    if (!preg_match('/^[a-z]{2}(-[a-z]+)?$/', $lang)) $lang = 'en';
    if ($action === 'save_all') {
        $keys = $_POST['key'] ?? [];
        $vals = $_POST['value'] ?? [];
        $n = 0;
        $up = $pdo->prepare("INSERT INTO texts (bot_id, lang, `key`, `value`) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
        foreach ((array)$keys as $i => $k) {
            $k = trim((string)$k);
            if ($k === '') continue;
            $up->execute([$botId, $lang, $k, (string)($vals[$i] ?? '')]);
            $n++;
        }
        flash("$n text key(s) saved for $lang.");
    } elseif ($action === 'add') {
        $k = trim(post_str('new_key'));
        $v = post_str('new_value');
        if ($k === '') { flash('Key is required.', 'err'); }
        else {
            $pdo->prepare("INSERT INTO texts (bot_id, lang, `key`, `value`) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
                ->execute([$botId, $lang, $k, $v]);
            flash("Key $k saved for $lang.");
        }
    } elseif ($action === 'delete') {
        $k = trim(post_str('key'));
        $pdo->prepare("DELETE FROM texts WHERE bot_id=? AND lang=? AND `key`=?")->execute([$botId, $lang, $k]);
        flash("Key $k deleted for $lang.");
    }
    redirect('index.php?page=texts&lang=' . urlencode($lang));
}

$langs = $pdo->prepare("SELECT * FROM languages WHERE bot_id=? ORDER BY sort ASC");
$langs->execute([$botId]);
$langs = $langs->fetchAll();
if (!$langs) $langs = [['code' => 'en', 'name' => 'English']];

// All keys = union of this language's keys and the English keys.
$en = $pdo->prepare("SELECT `key`, `value` FROM texts WHERE bot_id=? AND lang='en' ORDER BY `key` ASC");
$en->execute([$botId]);
$enRows = $en->fetchAll();
$enMap = [];
foreach ($enRows as $r) $enMap[$r['key']] = $r['value'];
$mine = $pdo->prepare("SELECT `key`, `value` FROM texts WHERE bot_id=? AND lang=?");
$mine->execute([$botId, $lang]);
$myMap = [];
foreach ($mine->fetchAll() as $r) $myMap[$r['key']] = $r['value'];
$allKeys = array_unique(array_merge(array_keys($enMap), array_keys($myMap)));
sort($allKeys);

admin_head('Texts', 'texts', $botId, $bots);
?>
<div class="card"><form method="get" action="index.php" class="row"><input type="hidden" name="page" value="texts">
<label style="margin:0">Language:</label><select name="lang" onchange="this.form.submit()">
<?php foreach ($langs as $l): ?><option value="<?= esc($l['code']) ?>" <?= $lang === $l['code'] ? 'selected' : '' ?>><?= esc($l['name']) ?> (<?= esc($l['code']) ?>)</option><?php endforeach; ?>
</select><span class="mut">Missing keys fall back to English. <?= count($allKeys) ?> keys total.</span></form></div>

<div class="card"><h3 style="margin-top:0">✏️ Texts — <?= esc($lang) ?></h3>
<form method="post"><input type="hidden" name="action" value="save_all"><?= csrf_field() ?><input type="hidden" name="lang" value="<?= esc($lang) ?>">
<table><tr><th style="width:220px">Key</th><th>Value (<?= esc($lang) ?>)</th></tr>
<?php foreach ($allKeys as $k): ?>
<tr><td class="mono"><?= esc($k) ?><input type="hidden" name="key[]" value="<?= esc($k) ?>"></td>
<td><textarea name="value[]" rows="2" style="min-height:44px" placeholder="<?= esc($lang !== 'en' && isset($enMap[$k]) ? 'EN: ' . $enMap[$k] : '') ?>"><?= esc($myMap[$k] ?? '') ?></textarea>
<?php if ($lang !== 'en' && !isset($myMap[$k]) && isset($enMap[$k])): ?><div class="mut">Not translated — English fallback is used.</div><?php endif; ?></td></tr>
<?php endforeach; ?>
</table>
<button type="submit">Save all</button></form></div>

<div class="card"><h3 style="margin-top:0">🗑️ Delete a key</h3>
<form method="post"><input type="hidden" name="action" value="delete"><?= csrf_field() ?><input type="hidden" name="lang" value="<?= esc($lang) ?>">
<div class="row"><div style="flex:1"><label>Key</label><select name="key" style="width:100%">
<?php foreach ($allKeys as $k): ?><option value="<?= esc($k) ?>"><?= esc($k) ?></option><?php endforeach; ?>
</select></div></div>
<button type="submit" class="btn-danger" style="margin-top:12px" onclick="return confirm('Delete this key for <?= esc($lang) ?>?')">Delete key</button></form></div>

<div class="card"><h3 style="margin-top:0">➕ Add a key</h3>
<form method="post"><input type="hidden" name="action" value="add"><?= csrf_field() ?><input type="hidden" name="lang" value="<?= esc($lang) ?>">
<div class="row"><div style="width:240px"><label>Key</label><input type="text" name="new_key" required placeholder="btn_shop"></div>
<div style="flex:1"><label>Value</label><input type="text" name="new_value" placeholder="🛍️ Shop Now"></div></div>
<button type="submit" style="margin-top:12px">Add key</button></form></div>
<?php admin_foot(); ?>
