<?php
// Telegram webhook entry.
// URL: https://telegram.toolsbydcx.com/bot/webhook.php?b=<bot_id>&s=<webhook_secret>
// The secret is per-bot (bots.webhook_secret), set by the admin panel.
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/Bot.php';

$botId = (int)($_GET['b'] ?? 0);
$secret = $_GET['s'] ?? '';
if ($botId <= 0 || $secret === '') { http_response_code(400); exit('bad request'); }

$bot = bot_by_id($botId);
if (!$bot || !$bot['active'] || !hash_equals((string)$bot['webhook_secret'], $secret)) {
    http_response_code(403); exit('forbidden');
}

$raw = file_get_contents('php://input');
$update = json_decode($raw ?: '{}', true);
if (!is_array($update)) { http_response_code(200); exit('ok'); }

try {
    (new Bot($bot))->handle($update);
} catch (Throwable $e) {
    error_log('webhook error bot ' . $botId . ': ' . $e->getMessage());
}
http_response_code(200);
echo 'ok';
