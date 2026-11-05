<?php
// Helpers: settings, encryption, money, formatting.
require_once __DIR__ . '/Database.php';

function setting(int $botId, string $key, $default = null) {
    static $cache = [];
    $ck = $botId . ':' . $key;
    if (array_key_exists($ck, $cache)) return $cache[$ck];
    $st = db()->prepare("SELECT `value` FROM bot_settings WHERE bot_id=? AND `key`=?");
    $st->execute([$botId, $key]);
    $row = $st->fetch();
    $cache[$ck] = $row ? $row['value'] : $default;
    return $cache[$ck];
}

function set_setting(int $botId, string $key, $value): void {
    $st = db()->prepare("INSERT INTO bot_settings (bot_id, `key`, `value`) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    $st->execute([$botId, $key, (string)$value]);
}

function enc_token(string $plain): string {
    $c = load_config();
    $key = hash('sha256', $c['app_key'], true);
    $iv = random_bytes(16);
    return base64_encode($iv . openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
}

function dec_token(string $enc): string {
    if ($enc === '') return '';
    $c = load_config();
    $key = hash('sha256', $c['app_key'], true);
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 17) return '';
    $iv = substr($raw, 0, 16);
    return openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv) ?: '';
}

function fmt_usd($n): string { return number_format((float)$n, 2, '.', ''); }
function fmt_points($n): string { return number_format((float)$n, 1, '.', ''); }
function pkr_of(int $botId, $usd): string {
    return number_format((float)$usd * (float)setting($botId, 'pkr_rate', 300), 0, '.', ',');
}
function points_for(int $botId, $usd): float {
    return round((float)$usd * (float)setting($botId, 'points_rate', 10), 2);
}
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function bot_by_id(int $id): ?array {
    $st = db()->prepare("SELECT * FROM bots WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}
function bot_token(int $botId): string {
    $bot = bot_by_id($botId);
    return $bot ? dec_token($bot['token_enc']) : '';
}
function loyalty_tier_for(int $botId, float $spent, int $orders): array {
    $st = db()->prepare("SELECT * FROM loyalty_tiers WHERE bot_id=? ORDER BY sort ASC");
    $st->execute([$botId]);
    $tiers = $st->fetchAll();
    $current = $tiers[0] ?? ['name' => 'Bronze', 'discount_pct' => 0];
    $next = null;
    foreach ($tiers as $t) {
        if ($spent >= (float)$t['min_spent'] && $orders >= (int)$t['min_orders']) $current = $t;
        elseif ($next === null) $next = $t;
    }
    return ['current' => $current, 'next' => $next];
}
function add_points(int $botId, int $userId, float $delta, string $reason, string $refType = '', ?int $refId = null): void {
    $pdo = db();
    $field = str_starts_with($reason, 'referral') ? 'points_referral' : 'points_wallet';
    $pdo->prepare("UPDATE users SET $field = $field + ? WHERE id=?")->execute([$delta, $userId]);
    $pdo->prepare("INSERT INTO points_ledger (bot_id, user_id, delta, reason, ref_type, ref_id) VALUES (?,?,?,?,?,?)")
        ->execute([$botId, $userId, $delta, $reason, $refType, $refId]);
}
