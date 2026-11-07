<?php
// Maintenance: one-click database migrations from the admin UI, so no
// phpMyAdmin / SSH / mysql CLI is needed. Runs only for a logged-in admin
// (the router enforces login) and only when the button is pressed.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'run_images') {
        $done = [];
        $errors = [];
        // 1. Add the image_url column if it is missing (MariaDB 10.5+ /
        // MySQL 8 support ADD COLUMN IF NOT EXISTS).
        foreach (['categories', 'products'] as $t) {
            try {
                $pdo->exec("ALTER TABLE {$t} ADD COLUMN IF NOT EXISTS image_url VARCHAR(500) NOT NULL DEFAULT ''");
                $done[] = "Column image_url ready on {$t}.";
            } catch (PDOException $e) {
                $errors[] = "{$t}: " . $e->getMessage();
            }
        }
        // 2. Set each category's brand logo (same mapping as
        // db/set_category_images.sql).
        $imgs = [
        1 => 'https://www.google.com/s2/favicons?domain=chatgpt.com&sz=128',
        2 => 'https://www.google.com/s2/favicons?domain=grok.com&sz=128',
        3 => 'https://www.google.com/s2/favicons?domain=capcut.com&sz=128',
        4 => 'https://www.google.com/s2/favicons?domain=freepik.com&sz=128',
        5 => 'https://www.google.com/s2/favicons?domain=elevenlabs.io&sz=128',
        6 => 'https://www.google.com/s2/favicons?domain=netflix.com&sz=128',
        7 => 'https://www.google.com/s2/favicons?domain=claude.ai&sz=128',
        8 => 'https://www.google.com/s2/favicons?domain=cursor.com&sz=128',
        9 => 'https://www.google.com/s2/favicons?domain=google.com&sz=128',
        10 => 'https://www.google.com/s2/favicons?domain=gmail.com&sz=128',
        11 => 'https://www.google.com/s2/favicons?domain=microsoft.com&sz=128',
        12 => 'https://www.google.com/s2/favicons?domain=adobe.com&sz=128',
        13 => 'https://www.google.com/s2/favicons?domain=gmail.com&sz=128',
        14 => 'https://www.google.com/s2/favicons?domain=nordvpn.com&sz=128',
        15 => 'https://www.google.com/s2/favicons?domain=autodesk.com&sz=128',
        16 => 'https://www.google.com/s2/favicons?domain=primevideo.com&sz=128',
        17 => 'https://www.google.com/s2/favicons?domain=canva.com&sz=128',
        18 => 'https://www.google.com/s2/favicons?domain=notion.so&sz=128',
        19 => 'https://www.google.com/s2/favicons?domain=ilovepdf.com&sz=128',
        20 => 'https://www.google.com/s2/favicons?domain=grammarly.com&sz=128',
        21 => 'https://www.google.com/s2/favicons?domain=linkedin.com&sz=128',
        22 => 'https://www.google.com/s2/favicons?domain=figma.com&sz=128',
        23 => 'https://www.google.com/s2/favicons?domain=microsoft.com&sz=128',
        24 => 'https://www.google.com/s2/favicons?domain=max.com&sz=128',
        25 => 'https://www.google.com/s2/favicons?domain=peacocktv.com&sz=128',
        26 => 'https://www.google.com/s2/favicons?domain=klingai.com&sz=128',
        27 => 'https://www.google.com/s2/favicons?domain=leonardo.ai&sz=128',
        28 => 'https://www.google.com/s2/favicons?domain=miro.com&sz=128',
        29 => 'https://www.google.com/s2/favicons?domain=jetbrains.com&sz=128',
        30 => 'https://www.google.com/s2/favicons?domain=genspark.ai&sz=128',
        31 => 'https://www.google.com/s2/favicons?domain=scribd.com&sz=128',
        32 => 'https://www.google.com/s2/favicons?domain=spotify.com&sz=128',
        33 => 'https://www.google.com/s2/favicons?domain=bytedance.com&sz=128',
        34 => 'https://www.google.com/s2/favicons?domain=duolingo.com&sz=128',
        35 => 'https://www.google.com/s2/favicons?domain=framer.com&sz=128',
        36 => 'https://www.google.com/s2/favicons?domain=kaspersky.com&sz=128',
        37 => 'https://www.google.com/s2/favicons?domain=railway.app&sz=128',
        38 => 'https://www.google.com/s2/favicons?domain=lovable.dev&sz=128',
        39 => 'https://www.google.com/s2/favicons?domain=replit.com&sz=128',
        40 => 'https://www.google.com/s2/favicons?domain=n8n.io&sz=128',
        41 => 'https://www.google.com/s2/favicons?domain=gamma.app&sz=128',
        42 => 'https://www.google.com/s2/favicons?domain=akool.com&sz=128',
        43 => 'https://www.google.com/s2/favicons?domain=telegram.org&sz=128',
        44 => 'https://www.google.com/s2/favicons?domain=telegram.org&sz=128',
        45 => 'https://www.google.com/s2/favicons?domain=envato.com&sz=128',
        46 => 'https://www.google.com/s2/favicons?domain=coursera.org&sz=128',
        47 => 'https://www.google.com/s2/favicons?domain=meitu.com&sz=128',
        48 => 'https://www.google.com/s2/favicons?domain=minimax.com&sz=128',
        49 => 'https://www.google.com/s2/favicons?domain=quizlet.com&sz=128',
        50 => 'https://www.google.com/s2/favicons?domain=meitu.com&sz=128',
        51 => 'https://www.google.com/s2/favicons?domain=crunchyroll.com&sz=128',
        52 => 'https://www.google.com/s2/favicons?domain=heygen.com&sz=128',
        53 => 'https://www.google.com/s2/favicons?domain=udemy.com&sz=128',
        54 => 'https://www.google.com/s2/favicons?domain=youtube.com&sz=128',
        55 => 'https://www.google.com/s2/favicons?domain=meta.com&sz=128',
        56 => 'https://www.google.com/s2/favicons?domain=suno.com&sz=128',
        57 => 'https://www.google.com/s2/favicons?domain=zoom.us&sz=128',
        58 => 'https://www.google.com/s2/favicons?domain=manus.im&sz=128',
        59 => 'https://www.google.com/s2/favicons?domain=apple.com&sz=128',
        60 => 'https://www.google.com/s2/favicons?domain=discord.com&sz=128',
        61 => 'https://www.google.com/s2/favicons?domain=kahoot.com&sz=128',
        62 => 'https://www.google.com/s2/favicons?domain=meitu.com&sz=128',
        63 => 'https://www.google.com/s2/favicons?domain=deepseek.com&sz=128',
        64 => 'https://www.google.com/s2/favicons?domain=higgsfield.ai&sz=128',
        65 => 'https://www.google.com/s2/favicons?domain=hide.me&sz=128',
        66 => 'https://www.google.com/s2/favicons?domain=deezer.com&sz=128',
        67 => 'https://www.google.com/s2/favicons?domain=jetbrains.com&sz=128',
        68 => 'https://www.google.com/s2/favicons?domain=brain.fm&sz=128',
        69 => 'https://www.google.com/s2/favicons?domain=galaxyplay.vn&sz=128',
        70 => 'https://www.google.com/s2/favicons?domain=telegram.org&sz=128',
        71 => 'https://www.google.com/s2/favicons?domain=scribd.com&sz=128',
        ];
        $st = $pdo->prepare("UPDATE categories SET image_url=? WHERE id=? AND bot_id=?");
        $n = 0;
        foreach ($imgs as $cid => $url) {
            try {
                $st->execute([$url, $cid, $botId]);
                $n += (int)$st->rowCount();
            } catch (PDOException $e) {
                $errors[] = "category {$cid}: " . $e->getMessage();
            }
        }
        $done[] = "Category images set: {$n} row(s) updated.";
        if ($errors) {
            flash(implode(' ', $done) . ' Errors: ' . implode(' | ', $errors), 'err');
        } else {
            flash(implode(' ', $done));
        }
    }
    redirect('index.php?page=maintenance');
}

// Status: does image_url exist, and how many categories/products have one?
function col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch();
}
$catCol = col_exists($pdo, 'categories', 'image_url');
$prodCol = col_exists($pdo, 'products', 'image_url');
$catWithImg = 0; $catTotal = 0; $prodWithImg = 0; $prodTotal = 0;
try {
    $catTotal = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE bot_id=" . (int)$botId)->fetchColumn();
    if ($catCol) $catWithImg = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE bot_id=" . (int)$botId . " AND image_url<>''")->fetchColumn();
    $prodTotal = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE bot_id=" . (int)$botId)->fetchColumn();
    if ($prodCol) $prodWithImg = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE bot_id=" . (int)$botId . " AND image_url<>''")->fetchColumn();
} catch (PDOException $e) { /* status stays 0 */ }

admin_head('Maintenance', 'maintenance', $botId, $bots);
?>
<div class="card"><h3 style="margin-top:0">🛠️ Maintenance — image migration</h3>
<p class="mut">No phpMyAdmin or hosting panel needed. This button adds the image column and sets each category's brand logo, right from here.</p>
<table>
<tr><th>Check</th><th>Status</th></tr>
<tr><td>Categories: image column (image_url)</td><td><?= $catCol ? '<span class="badge ok">exists</span>' : '<span class="badge bad">missing</span>' ?></td></tr>
<tr><td>Products: image column (image_url)</td><td><?= $prodCol ? '<span class="badge ok">exists</span>' : '<span class="badge bad">missing</span>' ?></td></tr>
<tr><td>Categories with an image</td><td><?= (int)$catWithImg ?> / <?= (int)$catTotal ?></td></tr>
<tr><td>Products with an image</td><td><?= (int)$prodWithImg ?> / <?= (int)$prodTotal ?></td></tr>
</table>
<form method="post" style="margin-top:12px"><input type="hidden" name="action" value="run_images"><?= csrf_field() ?>
<button type="submit">▶️ Run image migration (add column + set 71 category logos)</button>
</form>
<p class="mut">Safe to press again — it only adds the column if it is missing and re-sets the same logos. After it runs, open any category in the bot and its own logo will show instead of the welcome banner.</p>
</div>
<?php admin_foot(); ?>
