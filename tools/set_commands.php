<?php
/**
 * Register the bot's commands with Telegram (setMyCommands) so they appear
 * in the hamburger (/) commands menu next to the message input — the same
 * menu the original bot has. Run once (CLI only) after any command change:
 *   php tools/set_commands.php
 * The commands are stored by Telegram per-bot; they are NOT sent on every
 * webhook call. Re-running it just re-registers the same list.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Telegram.php';

$bot = bot_by_id(1);
if (!$bot) { echo "bot 1 not found\n"; exit(1); }
$token = dec_token($bot['token_enc']);

$commands = [
    // Descriptions copied exactly from the original bot's commands menu
    // (user screenshot 11:38 PKT): friendly name + emoji on the left,
    // /command on the right.
    ['command' => 'start',    'description' => '🛍️ Open Shop'],
    ['command' => 'balance',  'description' => '💰 My Balance'],
    ['command' => 'deposit',  'description' => '💎 Buy Points'],
    ['command' => 'orders',   'description' => '📜 My Orders'],
    ['command' => 'freebies', 'description' => '🎁 Free Products'],
    ['command' => 'apikey',   'description' => '🔗 Reseller API Key'],
    ['command' => 'language', 'description' => '🌐 Change Language'],
    ['command' => 'support',  'description' => '🎫 Support & Contact'],
    ['command' => 'help',     'description' => '📚 How to Use'],
];

$res = tg_api($token, 'setMyCommands', ['commands' => json_encode($commands)]);
if (!empty($res['ok'])) {
    echo "setMyCommands ok: " . count($commands) . " commands registered\n";
    $check = tg_api($token, 'getMyCommands', []);
    foreach (($check['result'] ?? []) as $c) echo '  /' . $c['command'] . ' — ' . $c['description'] . "\n";
} else {
    echo "setMyCommands FAILED: " . ($res['description'] ?? $res['error'] ?? 'unknown') . "\n";
    exit(1);
}
