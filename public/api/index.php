<?php
// Reseller API — ToolsByDcx Telegram Store.
// Auth: header X-API-Key: <key> (sha256 hash looked up in reseller_api_keys).
// Pretty URLs via public/.htaccess: /api/products -> /api/index.php?route=products
// Direct form also works: /api/index.php?route=products
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/Telegram.php';
require_once __DIR__ . '/../../lib/OrderService.php';

header('Content-Type: application/json; charset=utf-8');

function api_error(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}
function api_ok(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ---- Route: ?route=... wins; otherwise parse the request path after /api/ ----
$route = trim((string)($_GET['route'] ?? ''), '/');
if ($route === '') {
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    if (preg_match('#/api/(.+)$#', $path, $m)) $route = trim($m[1], '/');
    $route = preg_replace('#^index\.php/?#', '', $route);
}
$segments = $route === '' ? [] : explode('/', $route);
$resource = $segments[0] ?? '';
$resourceId = isset($segments[1]) ? (int)$segments[1] : 0;
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// ---- Auth ----
$apiKey = '';
if (!empty($_SERVER['HTTP_X_API_KEY'])) $apiKey = trim((string)$_SERVER['HTTP_X_API_KEY']);
elseif (function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
        if (strtolower($k) === 'x-api-key') { $apiKey = trim((string)$v); break; }
    }
}
if ($apiKey === '') api_error(401, 'Missing X-API-Key header.');
$pdo = db();
$st = $pdo->prepare("SELECT * FROM reseller_api_keys WHERE api_key_hash=? AND active=1");
$st->execute([hash('sha256', $apiKey)]);
$keyRow = $st->fetch();
if (!$keyRow) api_error(401, 'Invalid or deactivated API key.');
$botId = (int)$keyRow['bot_id'];
$userId = (int)$keyRow['user_id'];
$pdo->prepare("UPDATE reseller_api_keys SET last_used=NOW() WHERE id=?")->execute([(int)$keyRow['id']]);

$ust = $pdo->prepare("SELECT * FROM users WHERE id=? AND bot_id=?");
$ust->execute([$userId, $botId]);
$reseller = $ust->fetch();
if (!$reseller) api_error(401, 'API key owner not found.');
if ((int)$reseller['banned'] === 1) api_error(401, 'Reseller account is banned.');

function available_stock(PDO $pdo, int $productId): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM stock_items WHERE product_id=? AND status='available'");
    $st->execute([$productId]);
    return (int)$st->fetchColumn();
}

// ---- GET /api/products ----
if ($method === 'GET' && $resource === 'products') {
    $st = $pdo->prepare(
        "SELECT p.id, p.name, p.price_usd, p.description, p.delivery_format, p.status, p.sold_count,
                c.id AS category_id, c.name AS category
           FROM products p JOIN categories c ON c.id=p.category_id
          WHERE p.bot_id=? AND p.active=1 ORDER BY c.sort ASC, p.sort ASC, p.name ASC"
    );
    $st->execute([$botId]);
    $out = [];
    foreach ($st->fetchAll() as $p) {
        $out[] = [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'price_usd' => (float)$p['price_usd'],
            'description' => $p['description'],
            'delivery_format' => $p['delivery_format'],
            'category' => $p['category'],
            'category_id' => (int)$p['category_id'],
            'stock' => available_stock($pdo, (int)$p['id']),
            'sold_count' => (int)$p['sold_count'],
            'status' => $p['status'],
        ];
    }
    api_ok(['products' => $out]);
}

// ---- GET /api/categories ----
if ($method === 'GET' && $resource === 'categories') {
    $st = $pdo->prepare(
        "SELECT c.id, c.name, c.slug, c.emoji,
                (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id AND p.active=1) AS product_count
           FROM categories c WHERE c.bot_id=? AND c.active=1 ORDER BY c.sort ASC, c.name ASC"
    );
    $st->execute([$botId]);
    $out = [];
    foreach ($st->fetchAll() as $c) {
        $out[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'slug' => $c['slug'],
                  'emoji' => $c['emoji'], 'product_count' => (int)$c['product_count']];
    }
    api_ok(['categories' => $out]);
}

// ---- GET /api/stock?product_id=N ----
if ($method === 'GET' && $resource === 'stock') {
    $pid = (int)($_GET['product_id'] ?? 0);
    if ($pid <= 0) api_error(400, 'product_id is required.');
    $st = $pdo->prepare("SELECT id, name, price_usd, status FROM products WHERE id=? AND bot_id=? AND active=1");
    $st->execute([$pid, $botId]);
    $p = $st->fetch();
    if (!$p) api_error(404, 'Product not found.');
    api_ok(['product_id' => (int)$p['id'], 'name' => $p['name'], 'price_usd' => (float)$p['price_usd'],
            'status' => $p['status'], 'stock' => available_stock($pdo, $pid)]);
}

// ---- POST /api/order {product_id, qty} ----
if ($method === 'POST' && $resource === 'order' && $resourceId === 0) {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) api_error(400, 'Invalid JSON body.');
    $pid = (int)($body['product_id'] ?? 0);
    $qty = (int)($body['qty'] ?? 1);
    if ($pid <= 0) api_error(400, 'product_id is required.');
    if ($qty < 1 || $qty > 100) api_error(400, 'qty must be between 1 and 100.');
    $st = $pdo->prepare("SELECT * FROM products WHERE id=? AND bot_id=? AND active=1");
    $st->execute([$pid, $botId]);
    $product = $st->fetch();
    if (!$product) api_error(404, 'Product not found.');
    if ($product['status'] === 'out_of_stock') api_error(409, 'Product is out of stock.');
    $stock = available_stock($pdo, $pid);
    if ($stock < $qty) api_error(409, 'Out of stock: only ' . $stock . ' item(s) available.');
    $total = round((float)$product['price_usd'] * $qty, 2);
    $points = points_for($botId, $total);
    $balance = (float)$reseller['points_wallet'] + (float)$reseller['points_referral'];
    if ($balance < $points) {
        api_error(402, 'Insufficient points: need ' . fmt_points($points) . ' points, balance is ' . fmt_points($balance) . '. Top up in the bot first.');
    }
    // Charge the wallet (wallet first, then referral balance — the bot shows
    // the combined balance, so both count). If delivery fails, refund the same split.
    $wallet = (float)$reseller['points_wallet'];
    $fromWallet = min($points, $wallet);
    $fromReferral = round($points - $fromWallet, 2);
    if ($fromWallet > 0) add_points($botId, $userId, -$fromWallet, 'api_order', 'order', null);
    if ($fromReferral > 0) add_points($botId, $userId, -$fromReferral, 'referral_api_order', 'order', null);
    try {
        $res = order_create_delivered($pdo, $botId, $userId, $product, $qty, 'points');
    } catch (OrderServiceException $e) {
        if ($fromWallet > 0) add_points($botId, $userId, $fromWallet, 'api_order_refund', 'order', null);
        if ($fromReferral > 0) add_points($botId, $userId, $fromReferral, 'referral_api_order_refund', 'order', null);
        api_error(409, 'Out of stock: not enough items available to fulfil this order. Points were refunded.');
    }
    $order = $res['order'];
    $resp = [
        'order_id' => (int)$order['id'],
        'product_id' => $pid,
        'product' => $product['name'],
        'qty' => $qty,
        'total_usd' => $total,
        'points_charged' => $points,
        'status' => 'delivered',
    ];
    if (count($res['contents']) <= 9) {
        $resp['contents'] = $res['contents'];
    } else {
        // 10+ items: file is sent to the reseller on Telegram, as in the bot.
        $resp['contents'] = null;
        $resp['note'] = '10+ items: the delivery file was sent to you on Telegram.';
        order_notify_delivery(bot_token($botId), (int)$reseller['telegram_id'], $order, $res['contents']);
    }
    api_ok($resp, 201);
}

// ---- GET /api/order/{id} ----
if ($method === 'GET' && $resource === 'order' && $resourceId > 0) {
    $order = order_load($pdo, $botId, $resourceId);
    if (!$order || (int)$order['user_id'] !== $userId) api_error(404, 'Order not found.');
    $items = $pdo->prepare("SELECT content_snapshot FROM order_items WHERE order_id=?");
    $items->execute([$resourceId]);
    $contents = array_column($items->fetchAll(), 'content_snapshot');
    api_ok([
        'order_id' => (int)$order['id'],
        'product_id' => (int)$order['product_id'],
        'product' => $order['product_name'],
        'qty' => (int)$order['qty'],
        'unit_price' => (float)$order['unit_price'],
        'total_usd' => (float)$order['total_usd'],
        'status' => $order['status'],
        'payment_method' => $order['payment_method'],
        'created_at' => $order['created_at'],
        'delivered_at' => $order['delivered_at'],
        'items' => $order['status'] === 'delivered' ? $contents : [],
    ]);
}

api_error(404, 'Unknown endpoint. See /api-docs for the available endpoints.');
