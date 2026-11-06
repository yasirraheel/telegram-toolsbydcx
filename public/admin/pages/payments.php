<?php
// Payment methods CRUD (selected bot only). Details is a JSON textarea.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'binance_credentials') {
        $apiKey = trim((string)($_POST['binance_api_key'] ?? ''));
        $apiSecret = trim((string)($_POST['binance_api_secret'] ?? ''));
        $certPublic = trim((string)($_POST['binance_cert_public'] ?? ''));
        $st = $pdo->prepare("SELECT * FROM payment_methods WHERE bot_id=? AND code='binance'");
        $st->execute([$botId]);
        $row = $st->fetch();
        $details = $row ? (json_decode((string)$row['details'], true) ?: []) : [];
        $keyChanged = (string)($details['api_key'] ?? '') !== $apiKey;
        if ($apiKey === '') { flash('Binance API identity key is required.', 'err'); }
        else {
            $secretChanged = $apiSecret !== '';
            $details['api_key'] = $apiKey;
            if ($secretChanged) {
                $details['api_secret_enc'] = enc_token($apiSecret);
            }
            if ($keyChanged || $secretChanged) {
                // A rotated API key/secret must not reuse an old webhook cert.
                unset($details['cert_sn'], $details['cert_public']);
            }
            if ($certPublic !== '') $details['cert_public'] = $certPublic;
            $json = json_encode($details);
            if ($row) {
                $pdo->prepare("UPDATE payment_methods SET details=?, active=1 WHERE id=? AND bot_id=?")
                    ->execute([$json, (int)$row['id'], $botId]);
            } else {
                $pdo->prepare("INSERT INTO payment_methods (bot_id, code, name, details, sort, active) VALUES (?,?,?,?,2,1)")
                    ->execute([$botId, 'binance', 'Binance Auto Pay', $json]);
            }
            flash('Binance Pay credentials saved. Fetch the webhook certificate before going live.');
        }
    } elseif ($action === 'binance_cert') {
        $st = $pdo->prepare("SELECT * FROM payment_methods WHERE bot_id=? AND code='binance'");
        $st->execute([$botId]);
        $row = $st->fetch();
        $details = $row ? (json_decode((string)$row['details'], true) ?: []) : [];
        if (!$row || !BinancePay::configured($details)) {
            flash('Save the Binance API identity key and secret first.', 'err');
        } else {
            $res = BinancePay::certificates($details);
            $certs = $res['data'] ?? [];
            if (isset($certs['certificates']) && is_array($certs['certificates'])) $certs = $certs['certificates'];
            if (is_array($certs) && isset($certs['certSerial'])) $certs = [$certs];
            $cert = is_array($certs) ? ($certs[0] ?? []) : [];
            if (($res['status'] ?? '') === 'SUCCESS' && !empty($cert['certSerial']) && !empty($cert['certPublic'])) {
                $details['cert_sn'] = (string)$cert['certSerial'];
                $details['cert_public'] = (string)$cert['certPublic'];
                $pdo->prepare("UPDATE payment_methods SET details=?, active=1 WHERE id=? AND bot_id=?")
                    ->execute([json_encode($details), (int)$row['id'], $botId]);
                flash('Binance webhook certificate fetched and saved.');
            } else {
                flash('Could not fetch Binance certificate: ' . ($res['errorMessage'] ?? $res['error'] ?? 'check credentials/IP whitelist'), 'err');
            }
        }
    } elseif ($action === 'save') {
        $id = post_int('id');
        $code = strtolower(trim(post_str('code')));
        $name = post_str('name');
        $details = trim((string)($_POST['details'] ?? '{}'));
        $sort = post_int('sort');
        $active = post_int('active', 1) ? 1 : 0;
        $decoded = json_decode($details, true);
        if (!preg_match('/^[a-z0-9_]+$/', $code) || $name === '') { flash('A valid code (letters/numbers/underscore) and a name are required.', 'err'); }
        elseif ($details !== '' && $decoded === null) { flash('Details must be valid JSON, e.g. {"address":"0x..."} or {"uid":"12345"}.', 'err'); }
        else {
            $details = $details === '' ? '{}' : json_encode($decoded);
            if ($id > 0) {
                $pdo->prepare("UPDATE payment_methods SET code=?, name=?, details=?, sort=?, active=? WHERE id=? AND bot_id=?")
                    ->execute([$code, $name, $details, $sort, $active, $id, $botId]);
                flash('Payment method updated.');
            } else {
                try {
                    $pdo->prepare("INSERT INTO payment_methods (bot_id, code, name, details, sort, active) VALUES (?,?,?,?,?,?)")
                        ->execute([$botId, $code, $name, $details, $sort, $active]);
                    flash('Payment method added.');
                } catch (PDOException $e) { flash('Could not add method (code already exists?): ' . $e->getMessage(), 'err'); }
            }
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM payment_methods WHERE id=? AND bot_id=?")->execute([post_int('id'), $botId]);
        flash('Payment method deleted.');
    } elseif ($action === 'wallets') {
        // Plain wallet fields (no JSON needed): saved into each method's details.
        $fields = [
            'binance' => ['key' => 'uid',     'name' => 'Binance Auto Pay'],
            'bep20'   => ['key' => 'address', 'name' => 'USDT (BEP20)'],
            'sol'     => ['key' => 'address', 'name' => 'USDT (Solana)'],
            'ton'     => ['key' => 'address', 'name' => 'USDT (TON)'],
        ];
        $saved = [];
        foreach ($fields as $code => $f) {
            $val = trim((string)($_POST[$code] ?? ''));
            $st = $pdo->prepare("SELECT * FROM payment_methods WHERE bot_id=? AND code=?");
            $st->execute([$botId, $code]);
            $row = $st->fetch();
            $details = $row ? (json_decode((string)$row['details'], true) ?: []) : [];
            if ($val === '') { unset($details[$f['key']]); } else { $details[$f['key']] = $val; }
            $json = json_encode($details);
            if ($row) {
                $pdo->prepare("UPDATE payment_methods SET details=? WHERE id=? AND bot_id=?")->execute([$json, (int)$row['id'], $botId]);
            } elseif ($val !== '') {
                $pdo->prepare("INSERT INTO payment_methods (bot_id, code, name, details, sort, active) VALUES (?,?,?,?,?,1)")
                    ->execute([$botId, $code, $f['name'], $json, 0]);
            }
            if ($val !== '') $saved[] = $f['name'];
        }
        flash($saved ? 'Wallets saved: ' . implode(', ', $saved) . '. The bot shows these addresses to buyers at checkout.' : 'Wallets cleared.');
    }
    redirect('index.php?page=payments');
}

$editId = get_int('edit');
$edit = null;
if ($editId) {
    $st = $pdo->prepare("SELECT * FROM payment_methods WHERE id=? AND bot_id=?");
    $st->execute([$editId, $botId]);
    $edit = $st->fetch();
}
$rows = $pdo->prepare("SELECT * FROM payment_methods WHERE bot_id=? ORDER BY sort ASC, name ASC");
$rows->execute([$botId]);
$rows = $rows->fetchAll();

$binanceDetails = [];
foreach ($rows as $r) {
    if ($r['code'] === 'binance') { $binanceDetails = json_decode((string)$r['details'], true) ?: []; break; }
}

admin_head('Payment Methods', 'payments', $botId, $bots);

$walletOf = function (string $code, string $key) use ($rows): string {
    foreach ($rows as $r) {
        if ($r['code'] === $code) {
            $d = json_decode((string)$r['details'], true) ?: [];
            return (string)($d[$key] ?? '');
        }
    }
    return '';
};
?>
<div class="card"><h3 style="margin-top:0">💰 Crypto wallets</h3>
<p class="mut" style="margin-top:0">Put your wallet addresses here. The bot shows them to buyers at checkout — nothing is hardcoded. Leave a field empty to hide that method's address.</p>
<form method="post"><input type="hidden" name="action" value="wallets"><?= csrf_field() ?>
<label>Binance Pay — Pay ID / UID</label>
<input type="text" name="binance" value="<?= esc($walletOf('binance', 'uid')) ?>" placeholder="e.g. 123456789">
<label>USDT (BEP20) — wallet address</label>
<input type="text" name="bep20" value="<?= esc($walletOf('bep20', 'address')) ?>" placeholder="0x...">
<label>USDT (Solana) — wallet address</label>
<input type="text" name="sol" value="<?= esc($walletOf('sol', 'address')) ?>" placeholder="e.g. 7x...">
<label>USDT (TON) — wallet address</label>
<input type="text" name="ton" value="<?= esc($walletOf('ton', 'address')) ?>" placeholder="e.g. UQ...">
<button type="submit" style="margin-top:12px">Save wallets</button></form></div>

<div class="card"><h3 style="margin-top:0">🟡 Binance Pay — automatic checkout</h3>
<p class="mut" style="margin-top:0">This is the live API integration. Buyers get a Binance checkout link; successful payments are verified by signed webhook and the Query Order API, then delivery/points are processed once only.</p>
<p class="mut">Create the credentials in Binance Merchant Portal, paste them here, then use “Fetch webhook certificate”. Secret is encrypted before storage and is never displayed.</p>
<form method="post"><input type="hidden" name="action" value="binance_credentials"><?= csrf_field() ?>
<label>API identity key / API key</label>
<input type="text" name="binance_api_key" value="<?= esc($binanceDetails['api_key'] ?? '') ?>" autocomplete="off" placeholder="Binance Pay API identity key" required>
<label>API secret key <?= !empty($binanceDetails['api_secret_enc']) ? '(already saved — leave blank to keep)' : '' ?></label>
<input type="password" name="binance_api_secret" value="" autocomplete="new-password" placeholder="Paste only when setting or rotating it">
<label>Webhook public certificate (optional — fetch automatically below)</label>
<textarea name="binance_cert_public" rows="3" class="mono" placeholder="-----BEGIN PUBLIC KEY----- ..."><?= esc($binanceDetails['cert_public'] ?? '') ?></textarea>
<button type="submit" style="margin-top:12px">Save Binance credentials</button></form>
<form method="post" style="margin-top:10px"><input type="hidden" name="action" value="binance_cert"><?= csrf_field() ?>
<button type="submit" class="btn btn2">Fetch webhook certificate from Binance</button>
<span class="mut" style="margin-left:8px"><?= !empty($binanceDetails['cert_sn']) ? 'Certificate loaded.' : 'Certificate not loaded yet.' ?></span></form>
<p class="mut" style="margin-bottom:0">Webhook URL to register in Binance Merchant Portal → Developer → Webhooks:<br><code><?= esc(rtrim($config['base_url'], '/') . '/binance/webhook.php') ?></code></p></div>

<div class="card"><h3 style="margin-top:0"><?= $edit ? '✏️ Edit payment method' : '➕ Add payment method' ?></h3>
<form method="post"><input type="hidden" name="action" value="save"><?= csrf_field() ?>
<input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<div class="row">
<div style="width:150px"><label>Code</label><input type="text" name="code" value="<?= esc($edit['code'] ?? '') ?>" placeholder="bep20" required></div>
<div style="flex:1"><label>Name</label><input type="text" name="name" value="<?= esc($edit['name'] ?? '') ?>" placeholder="USDT (BEP20)" required></div>
<div style="width:90px"><label>Sort</label><input type="number" name="sort" value="<?= (int)($edit['sort'] ?? 0) ?>"></div>
<div><label>Active</label><select name="active"><option value="1" <?= ($edit['active'] ?? 1) ? 'selected' : '' ?>>Yes</option><option value="0" <?= isset($edit) && !$edit['active'] ? 'selected' : '' ?>>No</option></select></div>
</div>
<label>Details (JSON) — e.g. {"address":"0x..."} or {"uid":"12345"}</label>
<textarea name="details" rows="3" class="mono"><?= esc($edit['details'] ?? '{}') ?></textarea>
<button type="submit" style="margin-top:12px"><?= $edit ? 'Save changes' : 'Add method' ?></button>
<?php if ($edit): ?><a class="btn btn2" href="index.php?page=payments">Cancel</a><?php endif; ?></form></div>

<div class="card"><table><tr><th>Code</th><th>Name</th><th>Details</th><th>Active</th><th></th></tr>
<?php foreach ($rows as $r): ?><tr>
<td class="mono"><?= esc($r['code']) ?></td><td><?= esc($r['name']) ?></td>
<td class="mono"><?= esc($r['details'] ?: '{}') ?></td>
<td><?= $r['active'] ? '<span class="badge ok">yes</span>' : '<span class="badge bad">no</span>' ?></td>
<td><div class="row"><a class="btn btn-sm btn2" href="index.php?page=payments&amp;edit=<?= (int)$r['id'] ?>">Edit</a>
<form method="post" class="inline"><input type="hidden" name="action" value="delete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-sm btn-danger" type="submit" onclick="return confirm('Delete this payment method?')">Delete</button></form></div></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="mut">No payment methods yet for this bot.</td></tr><?php endif; ?></table></div>
<?php admin_foot(); ?>
