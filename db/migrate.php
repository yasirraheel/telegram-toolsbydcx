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
require __DIR__ . '/seed_stock.php';
echo "Migration done.\n";
