<?php
// OrderService: shared delivery logic for the admin panel and the reseller API.
// Mirrors Bot::createAndDeliverOrder (lib/Bot.php) but works on existing orders
// too and never touches Bot.php. All stock allocation happens inside a
// transaction with FOR UPDATE row locks so two deliveries cannot sell the
// same stock item twice.
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Telegram.php';

class OrderServiceException extends Exception {}

/**
 * Take $qty available stock items for a product and attach them to $orderId.
 * Must be called inside a transaction. Returns the delivered contents.
 * @throws OrderServiceException when there is not enough stock.
 */
function order_allocate_stock(PDO $pdo, int $botId, int $productId, int $qty, int $orderId): array {
    $qty = max(1, $qty);
    $st = $pdo->prepare(
        "SELECT id, content FROM stock_items
          WHERE bot_id=? AND product_id=? AND status='available'
          ORDER BY id ASC LIMIT $qty FOR UPDATE"
    );
    $st->execute([$botId, $productId]);
    $items = $st->fetchAll();
    if (count($items) < $qty) throw new OrderServiceException('out_of_stock');
    $contents = [];
    foreach ($items as $it) {
        $pdo->prepare("UPDATE stock_items SET status='sold', sold_order_id=? WHERE id=?")
            ->execute([$orderId, $it['id']]);
        $pdo->prepare("INSERT INTO order_items (order_id, stock_item_id, content_snapshot) VALUES (?,?,?)")
            ->execute([$orderId, $it['id'], $it['content']]);
        $contents[] = $it['content'];
    }
    return $contents;
}

/** Load an order (scoped to bot) with its product row, or null. */
function order_load(PDO $pdo, int $botId, int $orderId): ?array {
    $st = $pdo->prepare(
        "SELECT o.*, p.name AS product_name, p.price_usd AS product_price
           FROM orders o JOIN products p ON p.id = o.product_id
          WHERE o.id=? AND o.bot_id=?"
    );
    $st->execute([$orderId, $botId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Deliver an existing pending order: allocate stock, mark delivered,
 * bump product sold_count and the buyer's totals.
 * Returns ['order'=>..., 'contents'=>[...]].
 * @throws OrderServiceException on wrong status or insufficient stock.
 */
function order_deliver_existing(PDO $pdo, int $botId, int $orderId): array {
    $order = order_load($pdo, $botId, $orderId);
    if (!$order) throw new OrderServiceException('not_found');
    if ($order['status'] !== 'pending') throw new OrderServiceException('not_pending');
    $qty = (int)$order['qty'];
    $pdo->beginTransaction();
    try {
        $contents = order_allocate_stock($pdo, $botId, (int)$order['product_id'], $qty, $orderId);
        $pdo->prepare("UPDATE orders SET status='delivered', delivered_at=NOW() WHERE id=?")
            ->execute([$orderId]);
        $pdo->prepare("UPDATE products SET sold_count = sold_count + ? WHERE id=?")
            ->execute([$qty, $order['product_id']]);
        $pdo->prepare("UPDATE users SET total_spent = total_spent + ?, total_orders = total_orders + 1 WHERE id=?")
            ->execute([(float)$order['total_usd'], (int)$order['user_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $order['status'] = 'delivered';
    return ['order' => $order, 'contents' => $contents];
}

/**
 * Create a new order already delivered (reseller API / instant delivery):
 * insert the order, allocate stock, bump product sold_count and buyer totals.
 * The caller is responsible for charging the buyer BEFORE calling this
 * (and refunding if it throws). Returns ['order'=>..., 'contents'=>[...]].
 * @throws OrderServiceException on insufficient stock.
 */
function order_create_delivered(PDO $pdo, int $botId, int $userId, array $product, int $qty, string $method, string $txid = ''): array {
    $qty = max(1, $qty);
    $total = round((float)$product['price_usd'] * $qty, 2);
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO orders (bot_id, user_id, product_id, qty, unit_price, total_usd, status, payment_method, txid, delivered_at)
             VALUES (?,?,?,?,?,?,'delivered',?,?,NOW())"
        )->execute([$botId, $userId, $product['id'], $qty, $product['price_usd'], $total, $method, $txid]);
        $orderId = (int)$pdo->lastInsertId();
        $contents = order_allocate_stock($pdo, $botId, (int)$product['id'], $qty, $orderId);
        $pdo->prepare("UPDATE products SET sold_count = sold_count + ? WHERE id=?")
            ->execute([$qty, $product['id']]);
        $pdo->prepare("UPDATE users SET total_spent = total_spent + ?, total_orders = total_orders + 1 WHERE id=?")
            ->execute([$total, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $order = order_load($pdo, $botId, $orderId);
    return ['order' => $order, 'contents' => $contents];
}

/** Refund an order's value in wallet points (points = total_usd * points_rate). */
function order_refund_points(int $botId, array $order): float {
    $points = points_for($botId, (float)$order['total_usd']);
    add_points($botId, (int)$order['user_id'], $points, 'order_refund', 'order', (int)$order['id']);
    return $points;
}

/** Send the delivered contents to a Telegram user (inline, or as a file for 10+). */
function order_notify_delivery(string $token, int $telegramId, array $order, array $contents): void {
    $title = "✅ Order #{$order['id']} Delivered!\n\nProduct: {$order['product_name']}\nQty: {$order['qty']}";
    try {
        if (count($contents) <= 9) {
            tg_send($token, $telegramId, $title . "\n\n" . implode("\n", $contents));
        } else {
            tg_send($token, $telegramId, $title . "\n\n📄 Your delivery file is attached (10+ items).");
            $tmp = sys_get_temp_dir() . "/order_{$order['id']}.txt";
            file_put_contents($tmp, "Order #{$order['id']} — {$order['product_name']} x{$order['qty']}\n\n" . implode("\n", $contents));
            tg_document($token, $telegramId, $tmp);
            @unlink($tmp);
        }
    } catch (Throwable $e) {
        error_log('delivery notify failed order ' . $order['id'] . ': ' . $e->getMessage());
    }
}
