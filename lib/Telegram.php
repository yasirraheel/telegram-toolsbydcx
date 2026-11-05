<?php
// Minimal Telegram Bot API client (curl). In TEST_MODE (tools/test_harness.php)
// calls are recorded instead of sent.
function tg_api(string $token, string $method, array $params = []): array {
    if (!empty($GLOBALS['TG_TEST_MODE'])) {
        $GLOBALS['TG_CALLS'][] = ['method' => $method, 'params' => $params];
        return ['ok' => true, 'result' => ['message_id' => count($GLOBALS['TG_CALLS'])]];
    }
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 30,
    ]);
    $out = curl_exec($ch);
    curl_close($ch);
    $dec = json_decode((string)$out, true);
    return is_array($dec) ? $dec : ['ok' => false, 'error' => 'bad response'];
}

function tg_send(string $token, int $chatId, string $text, $markup = null, string $parse = 'HTML'): array {
    $p = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => $parse,
          'disable_web_page_preview' => true];
    if ($markup) $p['reply_markup'] = json_encode($markup);
    return tg_api($token, 'sendMessage', $p);
}
function tg_edit(string $token, int $chatId, int $msgId, string $text, $markup = null): array {
    $p = ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => $text,
          'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($markup) $p['reply_markup'] = json_encode($markup);
    return tg_api($token, 'editMessageText', $p);
}
function tg_answer(string $token, string $cbId, ?string $text = null): array {
    $p = ['callback_query_id' => $cbId];
    if ($text) $p['text'] = $text;
    return tg_api($token, 'answerCallbackQuery', $p);
}
function tg_photo(string $token, int $chatId, string $photo, string $caption, $markup = null): array {
    $p = ['chat_id' => $chatId, 'photo' => $photo, 'caption' => $caption, 'parse_mode' => 'HTML'];
    if ($markup) $p['reply_markup'] = json_encode($markup);
    return tg_api($token, 'sendPhoto', $p);
}
function tg_animation(string $token, int $chatId, string $animation, string $caption, $markup = null): array {
    $p = ['chat_id' => $chatId, 'animation' => $animation, 'caption' => $caption, 'parse_mode' => 'HTML'];
    if ($markup) $p['reply_markup'] = json_encode($markup);
    return tg_api($token, 'sendAnimation', $p);
}
function tg_document(string $token, int $chatId, string $path, string $caption = ''): array {
    if (!empty($GLOBALS['TG_TEST_MODE'])) {
        $GLOBALS['TG_CALLS'][] = ['method' => 'sendDocument', 'params' => ['chat_id' => $chatId, 'file' => $path, 'caption' => $caption]];
        return ['ok' => true, 'result' => ['message_id' => count($GLOBALS['TG_CALLS'])]];
    }
    $p = ['chat_id' => $chatId, 'document' => new CURLFile($path), 'caption' => $caption, 'parse_mode' => 'HTML'];
    return tg_api($token, 'sendDocument', $p);
}
function inline_kb(array $rows): array { return ['inline_keyboard' => $rows]; }
function reply_kb(array $rows, bool $resize = true): array {
    return ['keyboard' => $rows, 'resize_keyboard' => $resize, 'is_persistent' => true];
}
function btn(string $text, string $cb, ?string $style = null): array {
    $b = ['text' => $text, 'callback_data' => $cb];
    // Bot API 9.4 button styles: 'primary' (blue), 'success' (green), 'danger' (red).
    // Only sent when given; older clients (pre Feb 2026) ignore it and show default.
    if ($style !== null) $b['style'] = $style;
    return $b;
}
function btn_kb(string $text, ?string $style = null): array {
    $b = ['text' => $text];
    if ($style !== null) $b['style'] = $style;
    return $b;
}
function btn_url(string $text, string $url): array { return ['text' => $text, 'url' => $url]; }
