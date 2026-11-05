<?php
// One-off reconciliation: seed the COMPLETE 179-product catalogue captured
// from @Bite_storee_bot (Classic View All, 6 Nov 2026) into bot_id=1.
// Reads db/catalogue_179.json. Safe to re-run (upsert + stock top-up).
//  - renames 2 categories, adds 'Scribd'
//  - updates the 11 kept products in place, inserts the 168 missing ones
//  - deletes the 27 invented representative products not in the 179 list
//    (their sample stock rows cascade; the 5 live orders all sit on
//    product 1, which is kept, so nothing real is lost)
//  - tops sample stock (is_sample=1) up to min(stock, 200) per product;
//    huge live counts (e.g. 1,000,000) are NOT materialised as rows.
//  - strips supplier links from product 1's description (never clone them).
// Run on the server: php db/apply_catalogue_179.php
require_once __DIR__ . '/../lib/Database.php';

$json = json_decode(file_get_contents(__DIR__ . '/catalogue_179.json'), true);
if (!$json || empty($json['products'])) { fwrite(STDERR, "catalogue_179.json missing/empty\n"); exit(1); }

function stock_prefix(string $name): string {
    $p = strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', $name));
    $p = trim($p, '-');
    return substr($p, 0, 24) ?: 'ITEM';
}

$pdo = db();
$pdo->beginTransaction();
try {
    // Categories: renames + new 'Scribd'
    foreach ($json['categories_rename'] as $c) {
        $pdo->prepare("UPDATE categories SET name=?, slug=? WHERE id=? AND bot_id=1")
            ->execute([$c['name'], $c['slug'], (int)$c['id']]);
    }
    foreach ($json['categories_new'] as $c) {
        $pdo->prepare("INSERT IGNORE INTO categories (id, bot_id, name, slug, sort) VALUES (?,?,?,?,?)")
            ->execute([(int)$c['id'], 1, $c['name'], $c['slug'], (int)$c['sort']]);
    }

    $finalIds = [];
    $inserted = 0; $updated = 0;
    foreach ($json['products'] as $p) {
        $id = (int)$p['id'];
        $finalIds[] = $id;
        $price = round((float)$p['price_usd'], 2); // DECIMAL(10,2): 1.386->1.39, 0.044->0.04
        $st = $pdo->prepare("SELECT id FROM products WHERE id=? AND bot_id=1");
        $st->execute([$id]);
        if ($st->fetch()) {
            $pdo->prepare("UPDATE products SET name=?, price_usd=?, status=?, category_id=?, sort=? WHERE id=? AND bot_id=1")
                ->execute([$p['name'], $price, $p['status'], (int)$p['category_id'], (int)$p['sort'], $id]);
            $updated++;
        } else {
            $pdo->prepare("INSERT INTO products (id, bot_id, category_id, name, price_usd, description, delivery_format, status, sold_count, sort) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$id, 1, (int)$p['category_id'], $p['name'], $price, $p['name'], 'Coupon / Redemption Code', $p['status'], 0, (int)$p['sort']]);
            $inserted++;
        }
    }
    // Product 1: strip supplier links from the description.
    $pdo->prepare("UPDATE products SET description=? WHERE id=1 AND bot_id=1")
        ->execute(['100M Credit API Codex 5 Days - full warranty.']);

    // Delete invented products not in the 179 list (only ids outside the set).
    $placeholders = implode(',', array_fill(0, count($finalIds), '?'));
    $del = $pdo->prepare("SELECT id, name FROM products WHERE bot_id=1 AND id NOT IN ($placeholders)");
    $del->execute($finalIds);
    $doomed = $del->fetchAll();
    if ($doomed) {
        $ids = array_column($doomed, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM products WHERE bot_id=1 AND id IN ($ph)")->execute($ids);
    }

    // Stock: top up SAMPLE items to min(stock, 200); enforce 0 for OOS.
    $stockAdded = 0; $stockRemoved = 0;
    foreach ($json['products'] as $p) {
        $id = (int)$p['id'];
        $target = $p['status'] === 'active' ? min((int)$p['stock'], 200) : 0;
        $have = (int)$pdo->query("SELECT COUNT(*) FROM stock_items WHERE product_id=$id AND status='available'")->fetchColumn();
        if ($have < $target) {
            $prefix = stock_prefix($p['name']);
            $need = $target - $have;
            $ins = $pdo->prepare("INSERT INTO stock_items (bot_id, product_id, content, is_sample) VALUES (1, ?, ?, 1)");
            for ($i = $have + 1; $i <= $target; $i++) {
                $code = $prefix . '-SAMPLE-' . str_pad((string)$i, 4, '0', STR_PAD_LEFT) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                $ins->execute([$id, $code]);
                $stockAdded++;
            }
        } elseif ($have > $target) {
            // Only ever remove SAMPLE rows (never real delivery items).
            $excess = $have - $target;
            $rows = $pdo->prepare("SELECT id FROM stock_items WHERE product_id=? AND status='available' AND is_sample=1 ORDER BY id DESC LIMIT $excess");
            $rows->execute([$id]);
            $rmIds = array_column($rows->fetchAll(), 'id');
            if ($rmIds) {
                $ph = implode(',', array_fill(0, count($rmIds), '?'));
                $pdo->prepare("DELETE FROM stock_items WHERE id IN ($ph)")->execute($rmIds);
                $stockRemoved += count($rmIds);
            }
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

$counts = $pdo->query("SELECT status, COUNT(*) c FROM products WHERE bot_id=1 GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
echo "products updated: $updated, inserted: $inserted, deleted: " . count($doomed ?? []) . "\n";
if (!empty($doomed)) foreach ($doomed as $d) echo "  deleted #{$d['id']}: {$d['name']}\n";
echo "stock sample rows added: $stockAdded, removed: $stockRemoved\n";
echo "categories: " . $pdo->query("SELECT COUNT(*) FROM categories WHERE bot_id=1")->fetchColumn() . "\n";
echo "products total: " . $pdo->query("SELECT COUNT(*) FROM products WHERE bot_id=1")->fetchColumn()
   . " (active " . ($counts['active'] ?? 0) . ", out_of_stock " . ($counts['out_of_stock'] ?? 0) . ")\n";
echo "stock_items total: " . $pdo->query("SELECT COUNT(*) FROM stock_items WHERE bot_id=1")->fetchColumn() . "\n";
echo "Done.\n";
