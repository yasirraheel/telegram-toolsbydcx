<?php
// CLI test harness: feeds simulated Telegram updates to the Bot handler and
// prints every Telegram API call it would make. No real Telegram calls.
// Usage (on server, from repo root): php tools/test_harness.php
$GLOBALS['TG_TEST_MODE'] = true;
$GLOBALS['TG_CALLS'] = [];

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Bot.php';

$bot = bot_by_id(1);
if (!$bot) { fwrite(STDERR, "No bot id 1 — run db/migrate.php first.\n"); exit(1); }
$bot['token_enc'] = enc_token('TEST_TOKEN');
$handler = new Bot($bot);

function run_case(string $name, array $update, Bot $handler): void {
    $GLOBALS['TG_CALLS'] = [];
    $handler->handle($update);
    echo "=== $name ===\n";
    foreach ($GLOBALS['TG_CALLS'] as $c) {
        $p = $c['params'];
        $text = $p['text'] ?? $p['caption'] ?? '';
        $kb = isset($p['reply_markup']) ? ' [keyboard: ' . substr($p['reply_markup'], 0, 200) . ']' : '';
        echo "  -> {$c['method']}: " . substr(str_replace("\n", ' | ', (string)$text), 0, 220) . $kb . "\n";
    }
    echo "\n";
}

$msg = fn(int $id, string $text, array $extra = []) => array_merge([
    'update_id' => $id,
    'message' => [
        'message_id' => $id, 'date' => time(),
        'chat' => ['id' => 111222333, 'type' => 'private'],
        'from' => ['id' => 111222333, 'first_name' => 'Test', 'username' => 'tester'],
        'text' => $text,
    ],
], $extra);

$cb = fn(int $id, string $data) => [
    'update_id' => $id,
    'callback_query' => [
        'id' => 'cb' . $id, 'data' => $data,
        'from' => ['id' => 111222333, 'first_name' => 'Test', 'username' => 'tester'],
        // Real menu buttons live on the animated welcome banner: a media
        // message with a caption, so callbacks must use editMessageCaption.
        'message' => ['message_id' => 50, 'chat' => ['id' => 111222333, 'type' => 'private'],
            'animation' => ['file_id' => 'banner'], 'caption' => 'welcome'],
    ],
];

run_case('/start welcome', $msg(1, '/start'), $handler);
run_case('Shop Now screen', $cb(2, 'm:shop'), $handler);
run_case('Chatgpt category page 1', $cb(3, 'cat:1:1'), $handler);
run_case('Product detail (100M Codex)', $cb(4, 'p:1'), $handler);
run_case('Buy Now -> confirm screen', $cb(5, 'buy:1'), $handler);
// Give the test user points so the points purchase succeeds
db()->prepare("UPDATE users SET points_wallet=100 WHERE bot_id=1 AND telegram_id=111222333")->execute();
run_case('Confirm purchase with Points', $cb(6, 'cf:1:1:points'), $handler);
run_case('Buy Points screen', $cb(7, 'm:points'), $handler);
run_case('Account screen', $cb(8, 'm:acct'), $handler);
run_case('Orders screen', $cb(9, 'm:orders'), $handler);
run_case('Price list', $cb(10, 'm:price'), $handler);
run_case('Loyalty screen', $cb(11, 'm:loyal'), $handler);
run_case('Freebies', $msg(12, '/freebies'), $handler);
run_case('How to Use', $msg(13, '/help'), $handler);

$pdo = db();
$u = $pdo->query("SELECT points_wallet, total_orders, total_spent FROM users WHERE bot_id=1 AND telegram_id=111222333")->fetch();
$o = $pdo->query("SELECT id, status, total_usd FROM orders WHERE bot_id=1 ORDER BY id DESC LIMIT 1")->fetch();
echo "DB check — user points_wallet={$u['points_wallet']} orders={$u['total_orders']} spent={$u['total_spent']}\n";
echo "DB check — last order: #" . ($o['id'] ?? '-') . " status=" . ($o['status'] ?? '-') . " total=\$" . ($o['total_usd'] ?? '-') . "\n";
echo "Harness done.\n";
