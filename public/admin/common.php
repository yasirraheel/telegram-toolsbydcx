<?php
// Admin panel bootstrap: session, auth helpers, CSRF, bot selection, flash.
// Included by public/admin/index.php (and login.php) before any output.
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/Telegram.php';
require_once __DIR__ . '/../../lib/BinancePay.php';
require_once __DIR__ . '/../../lib/OrderService.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$pdo = db();
$config = load_config();

// ---- CSRF ----
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . esc(csrf_token()) . '">';
}
function verify_csrf(): void {
    $sent = $_POST['csrf'] ?? '';
    if (!hash_equals(csrf_token(), (string)$sent)) {
        http_response_code(403);
        exit('Invalid CSRF token. Go back and try again.');
    }
}

// ---- Flash messages ----
function flash(string $msg, string $type = 'ok'): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
function take_flash(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---- Auth ----
function admin_logged_in(): bool { return !empty($_SESSION['admin_id']); }
function require_admin(): void {
    if (!admin_logged_in()) {
        header('Location: index.php?page=login');
        exit;
    }
}
function redirect(string $url): void { header('Location: ' . $url); exit; }

// ---- Request helpers ----
function post_str(string $k, string $def = ''): string { return trim((string)($_POST[$k] ?? $def)); }
function post_int(string $k, int $def = 0): int { return (int)($_POST[$k] ?? $def); }
function get_int(string $k, int $def = 0): int { return (int)($_GET[$k] ?? $def); }
function post_float(string $k, float $def = 0.0): float { return (float)($_POST[$k] ?? $def); }

function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string)$s, '-') ?: 'item';
}

// ---- Bot selection (session, switched via ?bot=<id>) ----
function admin_bots(PDO $pdo): array {
    return $pdo->query("SELECT * FROM bots ORDER BY id ASC")->fetchAll();
}
function current_bot_id(PDO $pdo): int {
    if (isset($_GET['bot'])) {
        $id = (int)$_GET['bot'];
        $st = $pdo->prepare("SELECT id FROM bots WHERE id=?");
        $st->execute([$id]);
        if ($st->fetch()) $_SESSION['admin_bot_id'] = $id;
    }
    if (!empty($_SESSION['admin_bot_id'])) {
        $st = $pdo->prepare("SELECT id FROM bots WHERE id=?");
        $st->execute([(int)$_SESSION['admin_bot_id']]);
        if ($st->fetch()) return (int)$_SESSION['admin_bot_id'];
    }
    $row = $pdo->query("SELECT id FROM bots ORDER BY id ASC LIMIT 1")->fetch();
    if ($row) { $_SESSION['admin_bot_id'] = (int)$row['id']; return (int)$row['id']; }
    return 0;
}

// ---- Telegram notify helper (best effort, never fatal) ----
function notify_user(int $botId, int $telegramId, string $text): bool {
    try {
        $token = bot_token($botId);
        if ($token === '' || $telegramId <= 0) return false;
        $res = tg_send($token, $telegramId, $text);
        return !empty($res['ok']);
    } catch (Throwable $e) {
        error_log('admin notify failed: ' . $e->getMessage());
        return false;
    }
}

// ---- Admin pending-review counts (bell icon + sidebar badges) ----
// Items the admin must review: open tickets, pending orders, pending
// warranty claims, pending deposits. Used by the layout bell and the
// notifications page.
function admin_pending_counts(PDO $pdo, int $botId): array {
    $c = ['tickets' => 0, 'orders' => 0, 'warranty' => 0, 'deposits' => 0, 'total' => 0];
    if ($botId <= 0) return $c;
    try {
        $q = function (string $sql) use ($pdo, $botId): int {
            $st = $pdo->prepare($sql);
            $st->execute([$botId]);
            return (int)$st->fetchColumn();
        };
        $c['tickets'] = $q("SELECT COUNT(*) FROM support_tickets WHERE bot_id=? AND status='open'");
        $c['orders'] = $q("SELECT COUNT(*) FROM orders WHERE bot_id=? AND status='pending'");
        $c['warranty'] = $q("SELECT COUNT(*) FROM warranty_claims WHERE bot_id=? AND status='pending'");
        $c['deposits'] = $q("SELECT COUNT(*) FROM deposits WHERE bot_id=? AND status='pending'");
    } catch (Throwable $e) {
        error_log('pending counts failed: ' . $e->getMessage());
    }
    $c['total'] = $c['tickets'] + $c['orders'] + $c['warranty'] + $c['deposits'];
    return $c;
}
