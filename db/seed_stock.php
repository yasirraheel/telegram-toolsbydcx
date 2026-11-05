<?php
// Generates SAMPLE stock items (is_sample=1) matching the seed product counts
// from the live analysis. Content is clearly fake test data — replace in the
// admin panel (Stock) with real delivery items before going live.
require_once __DIR__ . '/../lib/Database.php';

// product_id => [count, content prefix]
$plan = [
    1  => [68, 'CODEX-100M-5D'],
    2  => [12, 'CODEX-1B-7D'],
    3  => [12, 'CODEX-1B-2D'],
    4  => [52, 'CODEX-200M-5D'],
    5  => [37, 'CODEX-500M-1D'],
    6  => [15, 'CODEX-500M-1M'],
    8  => [5,  'CODEX-100M-3D'],
    9  => [10, 'CODEX-50M-2D'],
    10 => [12, 'CODEX-10M-1D'],
    13 => [20, 'SPOTIFY-1M'],
    14 => [10, 'CLAUDE-PRO-1M'],
    16 => [10, 'GEMINI-PRO-1M'],
    18 => [10, 'CURSOR-PRO-1M'],
    19 => [10, 'CAPCUT-PRO-1M'],
    20 => [10, 'CANVA-PRO-1M'],
    29 => [10, 'YOUTUBE-PREM-1M'],
];
$pdo = db();
$total = 0;
foreach ($plan as $pid => [$count, $prefix]) {
    $have = $pdo->prepare("SELECT COUNT(*) c FROM stock_items WHERE product_id=? AND is_sample=1");
    $have->execute([$pid]);
    $have = (int)$have->fetch()['c'];
    for ($i = $have + 1; $i <= $count; $i++) {
        $code = $prefix . '-SAMPLE-' . str_pad((string)$i, 4, '0', STR_PAD_LEFT) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $pdo->prepare("INSERT INTO stock_items (bot_id, product_id, content, is_sample) VALUES (1, ?, ?, 1)")
            ->execute([$pid, $code]);
        $total++;
    }
}
echo "Sample stock items added: $total\n";
