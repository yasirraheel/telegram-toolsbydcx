<?php
// Payment methods CRUD (selected bot only). Details is a JSON textarea.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'binance_credentials') {
        $apiKey = trim((string)($_POST['binance_api_key'] ?? ''));
        $apiSecret = trim((string)($_POST['binance_api_secret'] ?? ''));
        $uid = trim((string)($_POST['binance_uid'] ?? ''));
        $currency = strtoupper(post_str('binance_currency', 'USDT'));
        if (!preg_match('/^[A-Z0-9]{2,12}$/', $currency)) $currency = 'USDT';
        $st = $pdo->prepare("SELECT * FROM payment_methods WHERE bot_id=? AND code='binance'");
        $st->execute([$botId]);
        $row = $st->fetch();
        $details = $row ? (json_decode((string)$row['details'], true) ?: []) : [];
        if ($uid === '') $uid = trim((string)($details['uid'] ?? ''));
        if ($apiKey === '' || $uid === '') { flash('Binance API Key aur Binance Pay UID dono required hain.', 'err'); }
        else {
            $secretChanged = $apiSecret !== '';
            $details['api_key'] = $apiKey;
            if ($secretChanged) {
                $details['api_secret_enc'] = enc_token($apiSecret);
            }
            $details['mode'] = 'personal';
            $details['uid'] = $uid;
            $details['currency'] = $currency;
            unset($details['cert_sn'], $details['cert_public']);
            $json = json_encode($details);
            if ($row) {
                $pdo->prepare("UPDATE payment_methods SET details=?, active=1 WHERE id=? AND bot_id=?")
                    ->execute([$json, (int)$row['id'], $botId]);
            } else {
                $pdo->prepare("INSERT INTO payment_methods (bot_id, code, name, details, sort, active) VALUES (?,?,?,?,2,1)")
                    ->execute([$botId, 'binance', 'Binance Auto Pay', $json]);
            }
            flash('Personal Binance Pay API credentials saved. Matching payments will be verified automatically from Binance Pay history.');
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
$binanceSecretHint = '';
if (!empty($binanceDetails['api_secret_enc'])) {
    $savedSecret = dec_token((string)$binanceDetails['api_secret_enc']);
    $savedLength = strlen($savedSecret);
    if ($savedLength >= 9) {
        $binanceSecretHint = substr($savedSecret, 0, 4) . '••••••••' . substr($savedSecret, -4);
    } elseif ($savedLength > 0) {
        $binanceSecretHint = substr($savedSecret, 0, 2) . '••••' . substr($savedSecret, -2);
    }
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
<p class="mut" style="margin-top:0">These fields are for other manual wallet payments. Binance Personal Pay uses the API section below; its UID is only used there for automatic transaction matching.</p>
<form method="post"><input type="hidden" name="action" value="wallets"><?= csrf_field() ?>
<label>Binance Pay UID — API section below</label>
<input type="text" name="binance" value="<?= esc($walletOf('binance', 'uid')) ?>" placeholder="e.g. 123456789">
<label>USDT (BEP20) — wallet address</label>
<input type="text" name="bep20" value="<?= esc($walletOf('bep20', 'address')) ?>" placeholder="0x...">
<label>USDT (Solana) — wallet address</label>
<input type="text" name="sol" value="<?= esc($walletOf('sol', 'address')) ?>" placeholder="e.g. 7x...">
<label>USDT (TON) — wallet address</label>
<input type="text" name="ton" value="<?= esc($walletOf('ton', 'address')) ?>" placeholder="e.g. UQ...">
<button type="submit" style="margin-top:12px">Save wallets</button></form></div>

<div class="card"><h3 style="margin-top:0">🟡 Binance Pay API</h3>
<p class="mut" style="margin-top:0">Personal Binance Pay API fixed-amount receive link banati hai. Payment ke baad bot Binance Pay transaction history se receiver UID, amount, currency aur time match karke order ya points automatically confirm karta hai. Secret encrypted save hota hai.</p>
<form method="post"><input type="hidden" name="action" value="binance_credentials"><?= csrf_field() ?>
<label>API Key</label>
<input type="text" name="binance_api_key" value="<?= esc($binanceDetails['api_key'] ?? '') ?>" autocomplete="off" placeholder="Binance API Key" required>
<label>API secret key</label>
<input type="password" name="binance_api_secret" value="" autocomplete="new-password" placeholder="<?= !empty($binanceDetails['api_secret_enc']) ? '••••••••••••••••  Saved securely — blank chhorain' : 'Paste API secret key' ?>">
<?php if (!empty($binanceDetails['api_secret_enc'])): ?><small class="mut">✅ API secret save hai: <code><?= esc($binanceSecretHint ?: '••••••••') ?></code><br>Field blank chhorain; sirf nayi secret key set ya rotate karni ho to paste karein.</small><?php endif; ?>
<label>Binance Pay UID — auto verification ke liye required</label>
<input type="text" name="binance_uid" value="<?= esc($binanceDetails['uid'] ?? '') ?>" inputmode="numeric" placeholder="e.g. 123456789" required>
<label>Receive currency</label>
<input type="text" name="binance_currency" value="<?= esc($binanceDetails['currency'] ?? 'USDT') ?>" maxlength="12" placeholder="USDT">
<button type="submit" style="margin-top:12px">Save Personal Binance credentials</button></form></div>

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
