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

// Answer Telegram immediately, BEFORE doing the work. Telegram waits for
// the 200 before delivering the next update, so when a reply was slow,
// every later tap queued up behind it — and Telegram retried the slow
// one, doubling the load. The bot's replies are still sent by handle()
// below; only the acknowledgement is early.
// Hostinger runs LiteSpeed/LSAPI, not PHP-FPM: fastcgi_finish_request()
// does not exist there, and flush() alone may be buffered, so the 200
// must be forced out with an exact Content-Length. And the script must
// keep running after the client disconnects (ignore_user_abort defaults
// to 0, which can kill handle() mid-reply).
ignore_user_abort(true);
http_response_code(200);
header('Content-Length: 2');   // 'ok' is 2 bytes — forces LiteSpeed to flush now
header('Connection: close');
echo 'ok';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    if (ob_get_level() > 0) { @ob_flush(); }
    @flush();
}

try {
    (new Bot($bot))->handle($update);
} catch (Throwable $e) {
    error_log('webhook error bot ' . $botId . ': ' . $e->getMessage());
}
