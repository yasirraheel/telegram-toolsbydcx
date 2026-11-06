<?php
// One-time diagnostic: is Telegram delivering updates to the webhook?
// Run once on the server:  php tools/webhook_status.php
// CLI only. Prints getMe (token works?) and getWebhookInfo
// (pending_update_count, last_error_message, last_error_date).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Telegram.php';

$bot = bot_by_id(1);
if (!$bot || !$bot['active']) { fwrite(STDERR, "No active bot id 1.\n"); exit(1); }
$token = dec_token($bot['token_enc']);

$me = tg_api($token, 'getMe');
echo "getMe ok: " . (!empty($me['ok']) ? 'yes' : 'no') . "\n";
if (!empty($me['ok'])) echo "bot: @" . ($me['result']['username'] ?? '?') . "\n";
else echo "getMe error: " . ($me['description'] ?? 'unknown') . "\n";

$info = tg_api($token, 'getWebhookInfo');
if (empty($info['ok'])) {
    echo "getWebhookInfo error: " . ($info['description'] ?? 'unknown') . "\n";
    exit(1);
}
$r = $info['result'];
echo "webhook url: " . ($r['url'] ?? '(none — webhook not set!)') . "\n";
echo "pending_update_count: " . ($r['pending_update_count'] ?? '?') . "\n";
echo "last_error_date: " . (!empty($r['last_error_date']) ? date('Y-m-d H:i:s', (int)$r['last_error_date']) . ' UTC' : '(none)') . "\n";
echo "last_error_message: " . ($r['last_error_message'] ?? '(none)') . "\n";
echo "max_connections: " . ($r['max_connections'] ?? '?') . "\n";
echo "ip_address: " . ($r['ip_address'] ?? '(none)') . "\n";
