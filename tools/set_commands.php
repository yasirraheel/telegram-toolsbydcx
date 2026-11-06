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
    ['command' => 'start',    'description' => 'Start the bot and show the main menu'],
    ['command' => 'balance',  'description' => 'Check your balance and points'],
    ['command' => 'deposit',  'description' => 'Buy points / deposit funds'],
    ['command' => 'orders',   'description' => 'View your orders'],
    ['command' => 'freebies', 'description' => 'Claim free products and methods'],
    ['command' => 'apikey',   'description' => 'Reseller API key and documentation'],
    ['command' => 'language', 'description' => 'Change the bot language'],
    ['command' => 'support',  'description' => 'Support tickets'],
    ['command' => 'help',     'description' => 'How to use the bot'],
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
