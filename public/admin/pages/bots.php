<?php
// Bots: list, add by token (getMe + setWebhook), test/set/delete webhook,
// toggle active, delete, copy seed catalogue from bot 1.
$baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');

function webhook_url_for(int $id, string $secret): string {
    global $baseUrl;
    return $baseUrl . '/bot/webhook.php?b=' . $id . '&s=' . urlencode($secret);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'add') {
        $name = post_str('name');
        $token = trim((string)($_POST['token'] ?? ''));
        if ($name === '' || $token === '' || !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token)) {
            flash('Enter a bot name and a valid Bot API token (looks like 123456:ABC...).', 'err');
        } else {
            $me = tg_api($token, 'getMe');
            if (empty($me['ok'])) {
                flash('Telegram rejected this token: ' . ($me['description'] ?? 'getMe failed') . '. Bot not added.', 'err');
            } else {
                $username = (string)($me['result']['username'] ?? '');
                $secret = bin2hex(random_bytes(32));
                $pdo->prepare("INSERT INTO bots (name, username, token_enc, webhook_secret, active) VALUES (?,?,?,?,0)")
                    ->execute([$name, $username, enc_token($token), $secret]);
                $newId = (int)$pdo->lastInsertId();
                $hook = tg_api($token, 'setWebhook', ['url' => webhook_url_for($newId, $secret)]);
                if (!empty($hook['ok'])) {
                    $pdo->prepare("UPDATE bots SET active=1 WHERE id=?")->execute([$newId]);
                    flash('Bot @' . $username . ' added and webhook set. It is now active.');
                } else {
                    flash('Bot @' . $username . ' added, but setWebhook failed: ' . ($hook['description'] ?? 'unknown error') . '. Use "Set Webhook" to retry; bot left inactive.', 'err');
                }
                $_SESSION['admin_bot_id'] = $newId;
                redirect('index.php?page=bots');
            }
        }
    }

    $id = post_int('id');
    $row = $id ? bot_by_id($id) : null;
    if (!$row && in_array($action, ['test', 'setwebhook', 'delwebhook', 'toggle', 'delete', 'copy_seed'], true)) {
        flash('Bot not found.', 'err');
    } elseif ($row) {
        $token = bot_token($id);
        if ($action === 'test') {
            $me = $token === '' ? ['ok' => false, 'description' => 'no token stored'] : tg_api($token, 'getMe');
            if (!empty($me['ok'])) {
                flash('Connection OK: @' . ($me['result']['username'] ?? '?') . ' — ' . ($me['result']['first_name'] ?? ''));
                if (!empty($me['result']['username']) && $me['result']['username'] !== $row['username']) {
                    $pdo->prepare("UPDATE bots SET username=? WHERE id=?")->execute([$me['result']['username'], $id]);
                }
            } else {
                flash('Connection failed: ' . ($me['description'] ?? 'getMe failed'), 'err');
            }
        } elseif ($action === 'setwebhook') {
            $res = $token === '' ? ['ok' => false, 'description' => 'no token stored']
                : tg_api($token, 'setWebhook', ['url' => webhook_url_for($id, $row['webhook_secret'])]);
            flash(!empty($res['ok']) ? 'Webhook set for @' . $row['username'] . '.'
                : 'setWebhook failed: ' . ($res['description'] ?? 'unknown error'), empty($res['ok']) ? 'err' : 'ok');
        } elseif ($action === 'delwebhook') {
            $res = $token === '' ? ['ok' => false, 'description' => 'no token stored']
                : tg_api($token, 'deleteWebhook', ['drop_pending_updates' => true]);
            flash(!empty($res['ok']) ? 'Webhook deleted for @' . $row['username'] . '.'
                : 'deleteWebhook failed: ' . ($res['description'] ?? 'unknown error'), empty($res['ok']) ? 'err' : 'ok');
        } elseif ($action === 'toggle') {
            $pdo->prepare("UPDATE bots SET active = 1 - active WHERE id=?")->execute([$id]);
            flash('Bot @' . $row['username'] . ' toggled.');
        } elseif ($action === 'delete') {
            if ($id === 1) {
                flash('Bot 1 (seed bot) cannot be deleted from here.', 'err');
            } else {
                if ($token !== '') tg_api($token, 'deleteWebhook', ['drop_pending_updates' => true]);
                $pdo->prepare("DELETE FROM bots WHERE id=?")->execute([$id]);
                if ((int)($_SESSION['admin_bot_id'] ?? 0) === $id) unset($_SESSION['admin_bot_id']);
                flash('Bot @' . $row['username'] . ' deleted (all its shop data was removed with it).');
            }
        } elseif ($action === 'copy_seed') {
            flash(copy_seed_catalogue($pdo, $id), 'ok');
        }
        redirect('index.php?page=bots');
    }
}

/** Copy categories/products/freebies/tiers/languages/texts/payment_methods from bot 1. */
function copy_seed_catalogue(PDO $pdo, int $targetId): string {
    if ($targetId === 1) return 'Bot 1 is already the seed bot.';
    $has = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE bot_id=$targetId")->fetchColumn()
         + (int)$pdo->query("SELECT COUNT(*) FROM products WHERE bot_id=$targetId")->fetchColumn();
    if ($has > 0) return 'Copy skipped: this bot already has categories/products. Copy only works on an empty bot.';
    $pdo->beginTransaction();
    try {
        // Categories (map old id -> new id), then products.
        $cats = $pdo->query("SELECT * FROM categories WHERE bot_id=1 ORDER BY sort ASC")->fetchAll();
        $map = [];
        foreach ($cats as $c) {
            $pdo->prepare("INSERT INTO categories (bot_id, name, slug, emoji, sort, active) VALUES (?,?,?,?,?,?)")
                ->execute([$targetId, $c['name'], $c['slug'], $c['emoji'], $c['sort'], $c['active']]);
            $map[(int)$c['id']] = (int)$pdo->lastInsertId();
        }
        $nProd = 0;
        foreach ($pdo->query("SELECT * FROM products WHERE bot_id=1 ORDER BY sort ASC")->fetchAll() as $p) {
            if (!isset($map[(int)$p['category_id']])) continue;
            $pdo->prepare("INSERT INTO products (bot_id, category_id, name, price_usd, description, delivery_format, status, sort, active) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$targetId, $map[(int)$p['category_id']], $p['name'], $p['price_usd'], $p['description'], $p['delivery_format'], $p['status'], $p['sort'], $p['active']]);
            $nProd++;
        }
        // Straight copies (no id remapping needed).
        foreach (['freebies' => ['title','description','delivery_text','claim_limit','claims_count','active','sort'],
                  'loyalty_tiers' => ['name','min_spent','min_orders','discount_pct','sort']] as $table => $cols) {
            foreach ($pdo->query("SELECT * FROM $table WHERE bot_id=1")->fetchAll() as $r) {
                $vals = array_map(fn($c) => $r[$c], $cols);
                $pdo->prepare("INSERT INTO $table (bot_id, " . implode(',', $cols) . ") VALUES (?, " . rtrim(str_repeat('?,', count($cols)), ',') . ")")
                    ->execute(array_merge([$targetId], $vals));
            }
        }
        foreach ($pdo->query("SELECT * FROM languages WHERE bot_id=1")->fetchAll() as $r) {
            $pdo->prepare("INSERT INTO languages (bot_id, code, name, native_name, active, sort) VALUES (?,?,?,?,?,?)")
                ->execute([$targetId, $r['code'], $r['name'], $r['native_name'], $r['active'], $r['sort']]);
        }
        foreach ($pdo->query("SELECT * FROM texts WHERE bot_id=1")->fetchAll() as $r) {
            $pdo->prepare("INSERT IGNORE INTO texts (bot_id, lang, `key`, `value`) VALUES (?,?,?,?)")
                ->execute([$targetId, $r['lang'], $r['key'], $r['value']]);
        }
        foreach ($pdo->query("SELECT * FROM payment_methods WHERE bot_id=1")->fetchAll() as $r) {
            $pdo->prepare("INSERT INTO payment_methods (bot_id, code, name, details, active, sort) VALUES (?,?,?,?,?,?)")
                ->execute([$targetId, $r['code'], $r['name'], $r['details'], $r['active'], $r['sort']]);
        }
        foreach ($pdo->query("SELECT * FROM bot_settings WHERE bot_id=1")->fetchAll() as $r) {
            $pdo->prepare("INSERT IGNORE INTO bot_settings (bot_id, `key`, `value`) VALUES (?,?,?)")
                ->execute([$targetId, $r['key'], $r['value']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return 'Copy failed: ' . $e->getMessage();
    }
    return "Seed catalogue copied from bot 1: " . count($cats) . " categories, $nProd products, plus freebies, tiers, languages, texts, payment methods and settings.";
}

admin_head('Bots', 'bots', $botId, $bots);
?>
<div class="card"><h3 style="margin-top:0">➕ Add a bot</h3>
<p class="mut">Paste the token from @BotFather. The panel encrypts it, calls getMe for the username, and sets the webhook to <span class="mono"><?= esc($baseUrl) ?>/bot/webhook.php?b={id}&amp;s={secret}</span>.</p>
<form method="post"><input type="hidden" name="action" value="add"><?= csrf_field() ?>
<label>Bot name</label><input type="text" name="name" placeholder="ToolsByDcx" required>
<label>Bot API token</label><input type="text" name="token" placeholder="123456789:AAEhBOweik6ad9r_QXMENQjcrGbqCr6bA" required autocomplete="off">
<button type="submit" style="margin-top:12px">Add bot &amp; set webhook</button></form></div>

<div class="card"><h3 style="margin-top:0">🤖 All bots</h3>
<table><tr><th>ID</th><th>Name</th><th>Username</th><th>Webhook URL</th><th>Active</th><th>Actions</th></tr>
<?php foreach ($bots as $b): ?>
<tr><td><?= (int)$b['id'] ?></td>
<td><?= esc($b['name']) ?></td>
<td>@<?= esc($b['username'] ?: '—') ?></td>
<td class="mono"><?= esc(webhook_url_for((int)$b['id'], (string)$b['webhook_secret'])) ?></td>
<td><?= $b['active'] ? '<span class="badge ok">active</span>' : '<span class="badge bad">off</span>' ?></td>
<td><div class="row">
<form method="post" class="inline"><input type="hidden" name="action" value="test"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn-sm btn2" type="submit">Test</button></form>
<form method="post" class="inline"><input type="hidden" name="action" value="setwebhook"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn-sm btn2" type="submit">Set Webhook</button></form>
<form method="post" class="inline"><input type="hidden" name="action" value="delwebhook"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn-sm btn2" type="submit">Delete Webhook</button></form>
<form method="post" class="inline"><input type="hidden" name="action" value="toggle"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn-sm btn2" type="submit"><?= $b['active'] ? 'Disable' : 'Enable' ?></button></form>
<?php if ((int)$b['id'] !== 1): ?>
<form method="post" class="inline"><input type="hidden" name="action" value="copy_seed"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn-sm btn-ok" type="submit" onclick="return confirm('Copy the full seed catalogue from bot 1 into this bot? Only works while this bot is empty.')">Copy seed catalogue</button></form>
<form method="post" class="inline"><input type="hidden" name="action" value="delete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn-sm btn-danger" type="submit" onclick="return confirm('Delete this bot and ALL its shop data? This cannot be undone.')">Delete</button></form>
<?php endif; ?>
</div></td></tr>
<?php endforeach; ?></table></div>
<?php admin_foot(); ?>
