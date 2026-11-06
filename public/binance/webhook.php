<?php
// Binance Pay order notification endpoint.
// Configure this exact HTTPS URL in Binance Merchant Portal:
// https://telegram.toolsbydcx.com/binance/webhook.php
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/Telegram.php';
require_once __DIR__ . '/../../lib/BinancePay.php';
require_once __DIR__ . '/../../lib/Bot.php';

$raw = file_get_contents('php://input') ?: '';
$event = json_decode($raw, true);
$tradeNo = is_array($event) ? (string)(json_decode((string)($event['data'] ?? '{}'), true)['merchantTradeNo'] ?? '') : '';
if (!is_array($event) || ($event['bizType'] ?? '') !== 'PAY' || $tradeNo === '') {
    http_response_code(400); exit('bad request');
}

// The trade number embeds the bot id so one merchant webhook can serve all
// bots in this installation: TDCX{botId}O{orderId} / TDCX{botId}D{depositId}.
if (!preg_match('/^TDCX(\d+)[OD]\d+$/', $tradeNo, $m)) {
    http_response_code(400); exit('bad trade number');
}
$botId = (int)$m[1];
$bot = bot_by_id($botId);
if (!$bot || !$bot['active']) { http_response_code(404); exit('unknown merchant'); }

$pm = db()->prepare("SELECT details FROM payment_methods WHERE bot_id=? AND code='binance' AND active=1");
$pm->execute([$botId]);
$details = json_decode((string)($pm->fetch()['details'] ?? '{}'), true) ?: [];
$headers = function_exists('getallheaders') ? (array)getallheaders() : [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $name = str_replace('_', '-', substr($key, 5));
        $headers[$name] = $value;
    }
}
if (!BinancePay::verifyWebhook($details, $raw, $headers)) {
    http_response_code(401); exit('invalid signature');
}

$payload = json_decode((string)($event['data'] ?? '{}'), true);
if (!is_array($payload)) { http_response_code(400); exit('bad payload'); }
// Only final successful payments can fulfill a product or credit points.
if (($event['bizStatus'] ?? '') === 'PAY_SUCCESS') {
    $ok = (new Bot($bot))->handleBinanceWebhook($payload);
    if (!$ok) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['returnCode' => 'FAIL', 'returnMessage' => 'Payment could not be processed yet']);
        exit;
    }
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['returnCode' => 'SUCCESS', 'returnMessage' => null]);
