<?php
// Migration runner: applies db/schema.sql + db/seed.sql, then generates sample
// stock items. Idempotent (INSERT IGNORE). Run on the server after deploy:
//   php db/migrate.php
require_once __DIR__ . '/../lib/Database.php';

$pdo = db();
foreach (['schema.sql', 'seed.sql'] as $f) {
    $sql = file_get_contents(__DIR__ . '/' . $f);
    // Strip full-line -- comments first (they share chunks with statements).
    $sql = preg_replace('/^--.*$/m', '', $sql);
    // Split on semicolons at line ends (safe for this file set: no triggers).
    $stmts = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
    $n = 0;
    foreach ($stmts as $stmt) {
        if ($stmt === '') continue;
        $pdo->exec($stmt);
        $n++;
    }
    echo "$f: $n statements applied.\n";
}
// Additive columns for installations created before Binance Pay checkout was
// introduced. MariaDB versions differ on ADD COLUMN IF NOT EXISTS, so the
// duplicate-column error is intentionally ignored.
foreach ([
    "ALTER TABLE orders ADD COLUMN binance_trade_no VARCHAR(32) NOT NULL DEFAULT '' AFTER txid",
    "ALTER TABLE orders ADD COLUMN binance_prepay_id VARCHAR(32) NOT NULL DEFAULT '' AFTER binance_trade_no",
    "ALTER TABLE deposits ADD COLUMN binance_trade_no VARCHAR(32) NOT NULL DEFAULT '' AFTER txid",
    "ALTER TABLE deposits ADD COLUMN binance_prepay_id VARCHAR(32) NOT NULL DEFAULT '' AFTER binance_trade_no",
    "ALTER TABLE deposits MODIFY status ENUM('pending','confirmed','failed','cancelled') NOT NULL DEFAULT 'pending'",
] as $alter) {
    try { $pdo->exec($alter); } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1060) throw $e;
    }
}
require __DIR__ . '/seed_stock.php';
echo "Migration done.\n";
